<?php

declare(strict_types=1);

namespace Veyra\Http;

use Veyra\Identity\Domain\Actor;
use Veyra\Infrastructure\Database\TableNames;

/** Fixed-window actor/action limiter. It stores only keyed hashes, never IPs. */
final class RateLimiter
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->rateLimits();
    }

    public function consume(Actor $actor, string $action, int $limit, int $windowSeconds = 60): bool
    {
        return $this->consumeBucket($actor->key(), $action, $limit, $windowSeconds);
    }

    /**
     * Bound cookie-less guest bootstrap before any persistent actor exists.
     * The network address is normalized then HMACed; no address is stored.
     */
    public function consumePreSession(string $networkAddress, string $action, int $limit, int $windowSeconds = 60): bool
    {
        $packed = filter_var($networkAddress, FILTER_VALIDATE_IP) !== false
            ? inet_pton($networkAddress)
            : false;
        $material = is_string($packed) ? bin2hex($packed) : 'network-unavailable';

        return $this->consumeBucket('pre-session:' . $material, $action, $limit, $windowSeconds);
    }

    private function consumeBucket(string $actorKey, string $action, int $limit, int $windowSeconds): bool
    {
        if ($limit < 1 || $limit > 1000 || $windowSeconds < 10 || $windowSeconds > 3600) {
            throw new \InvalidArgumentException('Rate limit is outside the safe bound.');
        }

        $window = intdiv(time(), $windowSeconds);
        $bucket = hash_hmac('sha256', $actorKey . '|' . $action, $this->rateKey());
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', ($window + 2) * $windowSeconds);
        $sql = $this->database->prepare(
            "INSERT INTO {$this->table} (bucket_hash,window_key,counter,expires_at,updated_at)
             VALUES (%s,%d,1,%s,%s)
             ON DUPLICATE KEY UPDATE counter = counter + 1, updated_at = VALUES(updated_at)",
            $bucket,
            $window,
            $expires,
            $now
        );
        if ($this->database->query($sql) === false) {
            return false;
        }

        $count = $this->database->get_var($this->database->prepare(
            "SELECT counter FROM {$this->table} WHERE bucket_hash = %s AND window_key = %d LIMIT 1",
            $bucket,
            $window
        ));

        return is_numeric($count) && (int) $count <= $limit;
    }

    public function purgeExpired(int $maximumRows = 500): int
    {
        $maximumRows = max(1, min(5000, $maximumRows));
        $query = $this->database->prepare(
            "DELETE FROM {$this->table} WHERE expires_at < %s LIMIT %d",
            gmdate('Y-m-d H:i:s'),
            $maximumRows
        );
        $deleted = $this->database->query($query);
        return is_int($deleted) && $deleted > 0 ? $deleted : 0;
    }

    private function rateKey(): string
    {
        $material = function_exists('wp_salt') ? wp_salt('nonce') : '';
        if (!is_string($material) || strlen($material) < 16) {
            throw new \RuntimeException('Rate-limit key material is unavailable.');
        }
        return $material;
    }
}
