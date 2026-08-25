<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\StateHash;

final class ConfirmationRequest
{
    /**
     * @param array<string, mixed> $resourceScope
     * @param array<string, mixed> $materialPayload
     * @param list<string>         $acknowledgements
     */
    public function __construct(
        public readonly string $action,
        public readonly array $resourceScope,
        public readonly array $materialPayload,
        public readonly StateHash $stateHash,
        public readonly string $summaryMessageId,
        public readonly int $summaryVersion,
        public readonly array $acknowledgements,
        public readonly string $idempotencyScope,
        public readonly CorrelationId $correlationId,
        public readonly ?string $sessionId = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $journeyId = null,
        public readonly int $ttlSeconds = 300
    ) {
        try {
            $resourceJson = CanonicalJson::encode($resourceScope);
            $materialJson = CanonicalJson::encode($materialPayload);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Confirmation payload is not canonical JSON data.', 0, $error);
        }
        $references = [$sessionId, $conversationId, $journeyId];
        $referencesValid = array_reduce(
            $references,
            static fn (bool $valid, ?string $reference): bool => $valid
                && ($reference === null || self::validOpaqueReference($reference)),
            true
        );
        $acknowledgementsValid = count($acknowledgements) <= 32
            && count($acknowledgements) === count(array_unique($acknowledgements));
        foreach ($acknowledgements as $acknowledgement) {
            if (!is_string($acknowledgement)
                || preg_match('/^[a-z][a-z0-9_.:-]{2,95}$/D', $acknowledgement) !== 1
            ) {
                $acknowledgementsValid = false;
                break;
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,190}$/D', $action) !== 1
            || $resourceScope === []
            || array_is_list($resourceScope)
            || count($resourceScope) > 32
            || strlen($resourceJson) > 8192
            || $materialPayload === []
            || strlen($materialJson) > 65536
            || !self::validOpaqueReference($summaryMessageId)
            || $summaryVersion < 1
            || $summaryVersion > 2147483647
            || strlen($idempotencyScope) < 3
            || strlen($idempotencyScope) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $idempotencyScope) === 1
            || !$acknowledgementsValid
            || !$referencesValid
            || $ttlSeconds < 30
            || $ttlSeconds > 900
        ) {
            throw new \InvalidArgumentException('Confirmation request is incomplete or outside safe bounds.');
        }
    }

    private static function validOpaqueReference(string $reference): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $reference) === 1;
    }
}
