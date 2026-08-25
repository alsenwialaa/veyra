<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\Privacy\ProviderOutboundSanitizer;

/**
 * Deterministic technical classifier/redactor for data leaving the store.
 *
 * This is deliberately not an intent classifier. It detects only bounded,
 * high-confidence credential and identifier syntax. Ordinary names, email,
 * phone, address, order references, quantities, and commerce text remain
 * available to an explicitly authorized `personal` provider route.
 */
final class ProviderProhibitedDataRedactor implements ProviderOutboundSanitizer
{
    private const MAX_DEPTH = 24;
    private const MAX_NODES = 20000;
    private const MAX_STRING_BYTES = 524288;

    /** @var array<string, string> */
    private const PROHIBITED_KEYS = [
        'password' => 'authentication_secret',
        'passwd' => 'authentication_secret',
        'pwd' => 'authentication_secret',
        'passcode' => 'authentication_secret',
        'secret' => 'authentication_secret',
        'secretkey' => 'authentication_secret',
        'clientsecret' => 'authentication_secret',
        'consumersecret' => 'authentication_secret',
        'signingsecret' => 'authentication_secret',
        'webhooksecret' => 'authentication_secret',
        'awssecretaccesskey' => 'authentication_secret',
        'apikey' => 'authentication_secret',
        'accesstoken' => 'authentication_secret',
        'refreshtoken' => 'authentication_secret',
        'sessiontoken' => 'authentication_secret',
        'idtoken' => 'authentication_secret',
        'bearertoken' => 'authentication_secret',
        'authorization' => 'authentication_secret',
        'authcookie' => 'authentication_secret',
        'privatekey' => 'authentication_secret',
        'cardnumber' => 'payment_credential',
        'paymentcardnumber' => 'payment_credential',
        'pan' => 'payment_credential',
        'cvv' => 'payment_credential',
        'cvc' => 'payment_credential',
        'cvn' => 'payment_credential',
        'securitycode' => 'payment_credential',
        'cardpin' => 'payment_credential',
        'cardexpiry' => 'payment_credential',
        'cardexpiration' => 'payment_credential',
        'paymenttoken' => 'payment_credential',
        'otp' => 'one_time_code',
        'onetimecode' => 'one_time_code',
        'verificationcode' => 'one_time_code',
        'bankaccount' => 'banking_credential',
        'accountnumber' => 'banking_credential',
        'routingnumber' => 'banking_credential',
        'iban' => 'banking_credential',
        'swift' => 'banking_credential',
        'bic' => 'banking_credential',
        'ssn' => 'government_identifier',
        'socialsecuritynumber' => 'government_identifier',
        'nationalid' => 'government_identifier',
        'passportnumber' => 'government_identifier',
        'driverlicensenumber' => 'government_identifier',
        'biometrictemplate' => 'sensitive_personal',
        'medicalrecordnumber' => 'sensitive_personal',
        'taxid' => 'government_identifier',
    ];

    /**
     * @return array{value:mixed,classifications:list<string>,redactions:list<string>}
     */
    public function redact(mixed $value): array
    {
        $classifications = [];
        $redactions = [];
        $nodes = 0;
        $safe = $this->redactNode($value, null, 0, $nodes, $classifications, $redactions);
        $classifications = array_values(array_unique($classifications));
        $redactions = array_values(array_unique($redactions));
        sort($classifications, SORT_STRING);
        sort($redactions, SORT_STRING);

        return [
            'value' => $safe,
            'classifications' => $classifications,
            'redactions' => $redactions,
        ];
    }

    /** True only when applying the redactor would make no material change. */
    public function isAlreadySafe(mixed $value): bool
    {
        $result = $this->redact($value);
        return $result['redactions'] === [] && $result['value'] === $value;
    }

    /**
     * @param list<string> $classifications
     * @param list<string> $redactions
     */
    private function redactNode(
        mixed $value,
        ?string $field,
        int $depth,
        int &$nodes,
        array &$classifications,
        array &$redactions
    ): mixed {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new ProviderDataPolicyException('provider_outbound_data_limit_exceeded');
        }

        $keyClass = $field === null ? null : $this->keyClassification($field);
        if ($keyClass !== null) {
            $classifications[] = $keyClass;
            if (is_string($value) && hash_equals($this->placeholder($keyClass), $value)) {
                return $value;
            }
            $redactions[] = $keyClass;
            return $this->placeholder($keyClass);
        }

        if (is_string($value)) {
            return $this->redactText($value, $classifications, $redactions);
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new ProviderDataPolicyException('provider_outbound_non_finite_number');
            }
            return $value;
        }
        if (!is_array($value)) {
            throw new ProviderDataPolicyException('provider_outbound_value_type_invalid');
        }

        $safe = [];
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new ProviderDataPolicyException('provider_outbound_object_key_invalid');
            }
            if (is_string($key) && (strlen($key) > 191 || preg_match('//u', $key) !== 1)) {
                throw new ProviderDataPolicyException('provider_outbound_object_key_invalid');
            }
            $safe[$key] = $this->redactNode(
                $item,
                is_string($key) ? $key : null,
                $depth + 1,
                $nodes,
                $classifications,
                $redactions
            );
        }

        return $safe;
    }

    /**
     * @param list<string> $classifications
     * @param list<string> $redactions
     */
    private function redactText(string $text, array &$classifications, array &$redactions): string
    {
        if (strlen($text) > self::MAX_STRING_BYTES || preg_match('//u', $text) !== 1) {
            throw new ProviderDataPolicyException('provider_outbound_text_invalid');
        }

        $safe = $text;
        $safe = $this->replace(
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/su',
            $safe,
            'authentication_secret',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{12,}\b/u',
            $safe,
            'authentication_secret',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/\beyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{8,}\b/u',
            $safe,
            'authentication_secret',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/\b(?:AIza[0-9A-Za-z_-]{20,}|sk-[0-9A-Za-z_-]{16,}|(?:sk|rk)_(?:live|test)_[0-9A-Za-z]{16,}|gh[pousr]_[0-9A-Za-z]{20,}|glpat-[0-9A-Za-z_-]{20,}|xox[baprs]-[0-9A-Za-z-]{20,}|whsec_[0-9A-Za-z]{20,}|AKIA[0-9A-Z]{16}|(?:ck|cs)_[a-f0-9]{40})\b/u',
            $safe,
            'authentication_secret',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:password|passwd|pwd|passcode|api[ _-]?key|client[ _-]?secret|consumer[ _-]?secret|signing[ _-]?secret|webhook[ _-]?secret|secret[ _-]?access[ _-]?key|access[ _-]?token|refresh[ _-]?token|session[ _-]?token|id[ _-]?token|كلمة[ _-]?المرور|رمز[ _-]?المرور|مفتاح[ _-]?(?:واجهة|api))["\']?\s*[:=]\s*(?:"[^"\r\n]{1,256}"|\'[^\'\r\n]{1,256}\'|[^\s,;}{]{4,256})/iu',
            $safe,
            'authentication_secret',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:\botp\b|one[ _-]?time(?:[ _-]?(?:code|password))?|verification[ _-]?code|رمز[ _-]?التحقق|رمز[ _-]?لمرة[ _-]?واحدة)["\']?\s*[:#=\-]?\s*["\']?[0-9٠-٩۰-۹]{3,8}["\']?/iu',
            $safe,
            'one_time_code',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:\bcvv\b|\bcvc\b|\bcvn\b|card[ _-]?pin|card[ _-]?security[ _-]?code)["\']?\s*[:#=\-]?\s*["\']?[0-9٠-٩۰-۹]{3,8}["\']?/iu',
            $safe,
            'payment_credential',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:bank[ _-]?account|account[ _-]?number|routing[ _-]?number|swift|bic)["\']?\s*[:=]\s*["\']?[A-Z0-9٠-٩۰-۹][A-Z0-9٠-٩۰-۹ ._-]{3,63}["\']?/iu',
            $safe,
            'banking_credential',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:passport[ _-]?number|national[ _-]?id|driver[ _-]?(?:license|licence)[ _-]?number|tax[ _-]?id)["\']?\s*[:=]\s*["\']?[A-Z0-9٠-٩۰-۹][A-Z0-9٠-٩۰-۹ ._-]{3,63}["\']?/iu',
            $safe,
            'government_identifier',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/["\']?(?:medical[ _-]?record[ _-]?number|biometric[ _-]?template)["\']?\s*[:=]\s*["\']?[A-Z0-9٠-٩۰-۹][A-Z0-9٠-٩۰-۹ ._-]{3,63}["\']?/iu',
            $safe,
            'sensitive_personal',
            $classifications,
            $redactions
        );
        $safe = $this->replace(
            '/\b(?:\d{3}-\d{2}-\d{4})\b/u',
            $safe,
            'government_identifier',
            $classifications,
            $redactions
        );
        $safe = $this->replaceIban($safe, $classifications, $redactions);
        $safe = $this->replacePaymentCards($safe, $classifications, $redactions);

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}/iu', $safe) === 1) {
            $classifications[] = 'personal_contact';
        }

        return $safe;
    }

    /**
     * @param list<string> $classifications
     * @param list<string> $redactions
     */
    private function replace(
        string $pattern,
        string $value,
        string $classification,
        array &$classifications,
        array &$redactions
    ): string {
        $count = 0;
        $replaced = preg_replace($pattern, $this->placeholder($classification), $value, -1, $count);
        if (!is_string($replaced)) {
            throw new ProviderDataPolicyException('provider_outbound_classifier_failed');
        }
        if ($count > 0) {
            $classifications[] = $classification;
            $redactions[] = $classification;
        }
        return $replaced;
    }

    /**
     * @param list<string> $classifications
     * @param list<string> $redactions
     */
    private function replacePaymentCards(string $value, array &$classifications, array &$redactions): string
    {
        $replaced = preg_replace_callback(
            '/(?<![\p{L}\p{N}])(?:[0-9٠-٩۰-۹][ -]?){12,18}[0-9٠-٩۰-۹](?![\p{L}\p{N}])/u',
            function (array $match) use (&$classifications, &$redactions): string {
                $digits = $this->asciiDigits((string) $match[0]);
                if (strlen($digits) < 13 || strlen($digits) > 19 || !$this->luhn($digits)) {
                    return (string) $match[0];
                }
                $classifications[] = 'payment_credential';
                $redactions[] = 'payment_credential';
                return $this->placeholder('payment_credential');
            },
            $value
        );
        if (!is_string($replaced)) {
            throw new ProviderDataPolicyException('provider_outbound_classifier_failed');
        }
        return $replaced;
    }

    /**
     * @param list<string> $classifications
     * @param list<string> $redactions
     */
    private function replaceIban(string $value, array &$classifications, array &$redactions): string
    {
        $replaced = preg_replace_callback(
            '/(?<![A-Z0-9])[A-Z]{2}[0-9]{2}(?:[ ]?[A-Z0-9]){11,30}(?![A-Z0-9])/iu',
            function (array $match) use (&$classifications, &$redactions): string {
                $candidate = strtoupper(str_replace(' ', '', (string) $match[0]));
                if (strlen($candidate) < 15 || strlen($candidate) > 34 || !$this->validIban($candidate)) {
                    return (string) $match[0];
                }
                $classifications[] = 'banking_credential';
                $redactions[] = 'banking_credential';
                return $this->placeholder('banking_credential');
            },
            $value
        );
        if (!is_string($replaced)) {
            throw new ProviderDataPolicyException('provider_outbound_classifier_failed');
        }
        return $replaced;
    }

    private function keyClassification(string $key): ?string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
        return self::PROHIBITED_KEYS[$normalized] ?? null;
    }

    private function placeholder(string $classification): string
    {
        return '[REDACTED:' . $classification . ']';
    }

    private function asciiDigits(string $value): string
    {
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $parity = strlen($digits) % 2;
        for ($index = 0, $length = strlen($digits); $index < $length; ++$index) {
            $digit = ord($digits[$index]) - 48;
            if (($index % 2) === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return $sum > 0 && $sum % 10 === 0;
    }

    private function validIban(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        for ($index = 0, $length = strlen($rearranged); $index < $length; ++$index) {
            $character = $rearranged[$index];
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            for ($digitIndex = 0, $digitLength = strlen($digits); $digitIndex < $digitLength; ++$digitIndex) {
                $remainder = ($remainder * 10 + (ord($digits[$digitIndex]) - 48)) % 97;
            }
        }
        return $remainder === 1;
    }
}
