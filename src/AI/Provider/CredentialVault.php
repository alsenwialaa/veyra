<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

final class CredentialVault
{
    private const OPTION = 'veyra_provider_credentials_v1';

    public function hasGeminiCredential(): bool
    {
        $stored = get_option(self::OPTION, []);
        $ciphertext = is_array($stored) ? ($stored['google_gemini'] ?? null) : null;
        return is_string($ciphertext) && $ciphertext !== '' && $this->decrypt($ciphertext) !== null;
    }

    public function storeGeminiCredential(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || strlen($apiKey) > 512) {
            throw new \InvalidArgumentException('Invalid provider credential.');
        }

        $ciphertext = $this->encrypt($apiKey);
        update_option(self::OPTION, ['google_gemini' => $ciphertext], false);
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)
            || !is_string($stored['google_gemini'] ?? null)
            || !hash_equals($ciphertext, $stored['google_gemini'])
            || $this->decrypt($stored['google_gemini']) !== $apiKey
        ) {
            throw new \RuntimeException('Provider credential persistence could not be verified.');
        }
    }

    public function clearGeminiCredential(): void
    {
        delete_option(self::OPTION);
        if (get_option(self::OPTION, null) !== null) {
            throw new \RuntimeException('Provider credential removal could not be verified.');
        }
    }

    public function geminiCredential(): ?string
    {
        $stored = get_option(self::OPTION, []);
        $ciphertext = is_array($stored) ? ($stored['google_gemini'] ?? null) : null;
        if (!is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        return $this->decrypt($ciphertext);
    }

    private function encryptionKey(): string
    {
        $authKey = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        $secureSalt = defined('SECURE_AUTH_SALT') ? (string) SECURE_AUTH_SALT : '';
        if (strlen($authKey) < 16 || strlen($secureSalt) < 16) {
            throw new \RuntimeException('WordPress authentication key material is unavailable.');
        }
        $material = $authKey . '|' . $secureSalt . '|veyra-provider-v1';
        return hash('sha256', $material, true);
    }

    private function encrypt(string $plaintext): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey());
            return 's1:' . base64_encode($nonce . $cipher);
        }

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('No supported credential encryption extension is available.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher)) {
            throw new \RuntimeException('Credential encryption failed.');
        }
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $stored): ?string
    {
        [$scheme, $encoded] = array_pad(explode(':', $stored, 2), 2, '');
        $raw = base64_decode($encoded, true);
        if (!is_string($raw)) {
            return null;
        }

        if ($scheme === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) <= $nonceSize) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open(substr($raw, $nonceSize), substr($raw, 0, $nonceSize), $this->encryptionKey());
            return is_string($plain) ? $plain : null;
        }

        if ($scheme === 'o1' && function_exists('openssl_decrypt') && strlen($raw) > 28) {
            $plain = openssl_decrypt(
                substr($raw, 28),
                'aes-256-gcm',
                $this->encryptionKey(),
                OPENSSL_RAW_DATA,
                substr($raw, 0, 12),
                substr($raw, 12, 16)
            );
            return is_string($plain) ? $plain : null;
        }
        return null;
    }
}
