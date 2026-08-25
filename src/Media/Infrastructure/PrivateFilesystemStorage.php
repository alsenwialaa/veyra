<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

// This protected-storage adapter requires exclusive creation, streaming, containment checks,
// exact private permissions, and unfiltered deletion that WP_Filesystem cannot guarantee.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

use Veyra\Media\Application\ProtectedStorage;
use Veyra\Media\Domain\StoredObject;

final class PrivateFilesystemStorage implements ProtectedStorage
{
    /** @param list<string> $publicRoots */
    public function __construct(
        private readonly string $basePath,
        array $publicRoots = []
    ) {
        if (!$this->ensureBasePath()) {
            throw new \RuntimeException('Protected storage directory is unavailable.');
        }
        $resolvedBase = realpath($this->basePath);
        if (!is_string($resolvedBase)) {
            throw new \RuntimeException('Protected storage path cannot be resolved.');
        }
        foreach ($publicRoots as $root) {
            $resolvedRoot = realpath($root);
            if (is_string($resolvedRoot) && $this->within($resolvedBase, $resolvedRoot)) {
                throw new \InvalidArgumentException('Protected storage must not be inside a public root.');
            }
        }
    }

    public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Protected storage source is invalid.');
        }
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => throw new \RuntimeException('Protected storage MIME type is unsupported.'),
        };
        $key = gmdate('Y/m') . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $this->pathForKey($key);
        $directory = dirname($destination);
        if (!$this->makePrivateDirectory($directory)) {
            throw new \RuntimeException('Protected storage subdirectory is unavailable.');
        }
        $resolvedBase = realpath($this->basePath);
        $resolvedDirectory = realpath($directory);
        if (!is_string($resolvedBase)
            || !is_string($resolvedDirectory)
            || !$this->within($resolvedDirectory, $resolvedBase)
            || is_link(dirname($directory))
            || is_link($directory)
        ) {
            throw new \RuntimeException('Protected storage subdirectory escaped its root.');
        }
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($destination, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($destination);
            throw new \RuntimeException('Protected storage write could not start.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                throw new \RuntimeException('Protected storage write failed.');
            }
        } catch (\Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }
        @chmod($destination, 0600);
        $actualChecksum = hash_file('sha256', $destination);
        $size = filesize($destination);
        if (!is_string($actualChecksum) || !hash_equals($checksumSha256, $actualChecksum) || !is_int($size) || $size < 1) {
            @unlink($destination);
            throw new \RuntimeException('Protected storage integrity verification failed.');
        }

        return new StoredObject('private_fs', $key, $size, $actualChecksum);
    }

    public function open(string $key)
    {
        $path = $this->resolvedObjectPath($key);
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Protected object could not be opened.');
        }

        return $stream;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathForKey($key);
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        try {
            $resolved = $this->resolvedObjectPath($key);
        } catch (\Throwable) {
            return false;
        }

        return @unlink($resolved);
    }

    private function ensureBasePath(): bool
    {
        return is_dir($this->basePath) ? is_writable($this->basePath) : $this->makePrivateDirectory($this->basePath);
    }

    private function makePrivateDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        @chmod($directory, 0700);
        return is_writable($directory);
    }

    private function pathForKey(string $key): string
    {
        if (preg_match('#^[0-9]{4}/[0-9]{2}/[a-f0-9]{48}\.(jpg|png|webp|pdf)$#D', $key) !== 1) {
            throw new \InvalidArgumentException('Protected storage key is invalid.');
        }
        $resolvedBase = realpath($this->basePath);
        if (!is_string($resolvedBase)) {
            throw new \RuntimeException('Protected storage path is unavailable.');
        }

        return $resolvedBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private function resolvedObjectPath(string $key): string
    {
        $path = $this->pathForKey($key);
        $base = realpath($this->basePath);
        $resolved = realpath($path);
        $monthDirectory = dirname($path);
        if (!is_string($base)
            || !is_string($resolved)
            || !is_file($resolved)
            || is_link($path)
            || is_link($monthDirectory)
            || is_link(dirname($monthDirectory))
            || !$this->within($resolved, $base)
        ) {
            throw new \RuntimeException('Protected object is unavailable.');
        }

        return $resolved;
    }

    private function within(string $path, string $root): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath . '/', $normalizedRoot . '/');
    }
}
