<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

use Veyra\Media\Application\MalwareScanner;
use Veyra\Media\Application\ProtectedStorage;

/** Builds only explicitly configured, non-public protected media adapters. */
final class ProtectedStorageFactory
{
    public const PATH_CONSTANT = 'VEYRA_PROTECTED_STORAGE_PATH';
    public const RETENTION_CONSTANT = 'VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS';

    public static function storage(): ?ProtectedStorage
    {
        if (!defined(self::PATH_CONSTANT)) {
            return null;
        }
        $path = constant(self::PATH_CONSTANT);
        if (!is_string($path) || trim($path) === '' || !self::absolutePath($path)) {
            return null;
        }

        $publicRoots = [];
        foreach (['ABSPATH', 'WP_CONTENT_DIR'] as $constant) {
            if (defined($constant) && is_string(constant($constant)) && constant($constant) !== '') {
                $publicRoots[] = constant($constant);
            }
        }
        $rawDocumentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The unslashed raw boundary value is sanitized below and rejected unless byte-identical before path validation.
            ? wp_unslash($_SERVER['DOCUMENT_ROOT'])
            : '';
        if (!is_string($rawDocumentRoot)) {
            return null;
        }
        $documentRoot = sanitize_text_field($rawDocumentRoot);
        if (!hash_equals($rawDocumentRoot, $documentRoot)) {
            return null;
        }
        $documentRoot = trim($documentRoot);
        if ($documentRoot !== '' && self::absolutePath($documentRoot)) {
            $publicRoots[] = $documentRoot;
        }
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir(null, false, false);
            if (is_array($uploads) && is_string($uploads['basedir'] ?? null) && $uploads['basedir'] !== '') {
                $publicRoots[] = $uploads['basedir'];
            }
        }

        try {
            return new PrivateFilesystemStorage($path, array_values(array_unique($publicRoots)));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function scanner(): ?MalwareScanner
    {
        if (!function_exists('apply_filters')) {
            return null;
        }
        $callback = apply_filters('veyra_malware_scanner_callback', null);
        if (!is_callable($callback)) {
            return null;
        }

        try {
            return new CallableMalwareScanner($callback);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * DEC-023 leaves default file-retention periods open. Protected uploads
     * therefore require an explicit operator-approved value and never inherit
     * a guessed production default.
     */
    public static function retentionSeconds(): ?int
    {
        if (!defined(self::RETENTION_CONSTANT)) {
            return null;
        }
        $value = constant(self::RETENTION_CONSTANT);
        if (is_string($value) && preg_match('/^[1-9][0-9]{3,7}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 3600 && $value <= 31536000
            ? $value
            : null;
    }

    /** @return array{storage:string,scanner:string,retention:string,routes:string} */
    public static function health(): array
    {
        $storage = self::storage();
        $scanner = self::scanner();
        $retention = self::retentionSeconds();
        return [
            'storage' => $storage === null ? 'Blocked' : 'Available',
            'scanner' => $scanner === null ? 'Blocked' : 'Available',
            'retention' => $retention === null ? 'Blocked' : 'Available',
            'routes' => $storage !== null && $scanner !== null && $retention !== null ? 'Eligible' : 'Blocked',
        ];
    }

    private static function absolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
