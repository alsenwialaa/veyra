<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

/** Immutable, server-owned policy for one provider Context Bundle route. */
final class ContextBundlePolicy
{
    private const CLASSIFICATIONS = [
        'public',
        'internal',
        'personal',
        'sensitive_personal',
        'commerce_confidential',
        'protected_file',
        'credential_reference',
    ];

    /** @param list<string> $allowedDataClasses */
    public function __construct(
        public readonly string $providerRouteId,
        public readonly string $routeManifestVersion,
        public readonly string $purpose,
        public readonly bool $transmissionAuthorized,
        public readonly string $transmissionDecisionCode,
        public readonly array $allowedDataClasses,
        public readonly int $maximumBytes,
        public readonly int $maximumItems,
        public readonly int $ttlSeconds
    ) {
        foreach ([$providerRouteId, $routeManifestVersion, $transmissionDecisionCode] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 128
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $identifier) !== 1
            ) {
                throw new \InvalidArgumentException('Context Bundle policy identifier is invalid.');
            }
        }
        if ($purpose === '' || strlen($purpose) > 160 || preg_match('//u', $purpose) !== 1) {
            throw new \InvalidArgumentException('Context Bundle purpose is invalid.');
        }
        if ($transmissionAuthorized && $transmissionDecisionCode !== 'runtime_ready') {
            throw new \InvalidArgumentException('Authorized Context Bundle policy requires the exact ready decision.');
        }
        if (!array_is_list($allowedDataClasses)
            || $allowedDataClasses !== array_values(array_unique($allowedDataClasses))
            || array_diff($allowedDataClasses, self::CLASSIFICATIONS) !== []
        ) {
            throw new \InvalidArgumentException('Context Bundle data classifications are invalid.');
        }
        if ($maximumBytes < 4096 || $maximumBytes > 1048576
            || $maximumItems < 8 || $maximumItems > 1000
            || $ttlSeconds < 60 || $ttlSeconds > 3600
        ) {
            throw new \InvalidArgumentException('Context Bundle policy bounds are invalid.');
        }
    }
}
