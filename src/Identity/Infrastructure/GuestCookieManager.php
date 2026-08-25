<?php

declare(strict_types=1);

namespace Veyra\Identity\Infrastructure;

use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Shared\Domain\UtcInstant;

final class GuestCookieManager
{
    public const CSRF_COOKIE_NAME = 'veyra_guest_csrf';

    /** Read and strictly bound the opaque credential at the request boundary. */
    public static function readSessionToken(): ?string
    {
        if (!isset($_COOKIE[GuestSessionManager::COOKIE_NAME])
            || !is_string($_COOKIE[GuestSessionManager::COOKIE_NAME])
        ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The credential is sanitized below but accepted only when the raw and sanitized bytes match and the strict opaque-token grammar passes.
        $rawToken = wp_unslash($_COOKIE[GuestSessionManager::COOKIE_NAME]);
        if (!is_string($rawToken)) {
            return null;
        }
        $token = sanitize_text_field($rawToken);

        // Sanitization is a validation boundary, not a normalization step for
        // credentials. Never let hostile text sanitize into another token.
        if (!hash_equals($rawToken, $token)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{32,192}$/D', $token) === 1 ? $token : null;
    }

    public function issue(string $rawSessionToken, string $rawCsrfToken, UtcInstant $expiresAt): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Guest-session cookies cannot be issued after output.');
        }

        $expires = strtotime($expiresAt->toDatabase() . ' UTC');
        $path = defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
        $secure = function_exists('is_ssl') && is_ssl();
        $base = [
            'expires' => $expires === false ? time() + 3600 : $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'samesite' => 'Lax',
        ];

        setcookie(
            GuestSessionManager::COOKIE_NAME,
            $rawSessionToken,
            $base + ['httponly' => true]
        );
        setcookie(
            self::CSRF_COOKIE_NAME,
            $rawCsrfToken,
            $base + ['httponly' => false]
        );
    }

    public function clear(): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Guest-session cookies cannot be cleared after output.');
        }
        $path = defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
        $secure = function_exists('is_ssl') && is_ssl();
        $options = [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'samesite' => 'Lax',
        ];
        setcookie(GuestSessionManager::COOKIE_NAME, '', $options + ['httponly' => true]);
        setcookie(self::CSRF_COOKIE_NAME, '', $options + ['httponly' => false]);
        unset($_COOKIE[GuestSessionManager::COOKIE_NAME], $_COOKIE[self::CSRF_COOKIE_NAME]);
    }
}
