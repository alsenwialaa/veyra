<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

/** Purpose-limited adapter resolver for retention, privacy and uninstall deletion. */
final class ProtectedObjectEraser
{
    public function delete(string $driver, string $key): bool
    {
        if ($driver === '' || $key === '' || strlen($driver) > 32 || strlen($key) > 255) {
            return false;
        }

        $filtered = function_exists('apply_filters')
            ? apply_filters('veyra_protected_storage_delete', null, $driver, $key)
            : null;
        if (is_bool($filtered)) {
            return $filtered;
        }
        if ($driver !== 'private_fs') {
            return false;
        }
        $storage = ProtectedStorageFactory::storage();

        return $storage !== null && $storage->delete($key);
    }
}

