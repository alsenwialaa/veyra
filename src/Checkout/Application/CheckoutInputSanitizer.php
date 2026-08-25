<?php

declare(strict_types=1);

namespace Veyra\Checkout\Application;

final class CheckoutInputSanitizer
{
    private const CONTACT_FIELDS = ['first_name', 'last_name', 'company', 'email', 'phone'];
    private const ADDRESS_FIELDS = ['address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    /** @param array<mixed> $input @return array<string, string> */
    public function contact(array $input): array
    {
        return $this->sanitizeMap($input, self::CONTACT_FIELDS, false);
    }

    /** @param array<mixed> $input @return array<string, string> */
    public function address(array $input): array
    {
        $result = $this->sanitizeMap($input, self::ADDRESS_FIELDS, true);
        if (isset($result['country'])) {
            $result['country'] = strtoupper($result['country']);
            if (preg_match('/^[A-Z]{2}$/D', $result['country']) !== 1) {
                throw new \InvalidArgumentException('Address country must be an ISO alpha-2 code.');
            }
            if (function_exists('WC') && WC() !== null && WC()->countries !== null) {
                $countries = WC()->countries->get_countries();
                if (!isset($countries[$result['country']])) {
                    throw new \InvalidArgumentException('Address country is not currently supported.');
                }
            }
        }
        if (isset($result['state']) && $result['state'] !== '' && isset($result['country'])
            && function_exists('WC') && WC() !== null && WC()->countries !== null
        ) {
            $states = WC()->countries->get_states($result['country']);
            if (is_array($states) && $states !== []) {
                $candidate = strtoupper($result['state']);
                if (!isset($states[$candidate])) {
                    throw new \InvalidArgumentException('Address state must be an exact current state code.');
                }
                $result['state'] = $candidate;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $input
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function sanitizeMap(array $input, array $allowed, bool $address): array
    {
        if ($input === [] || array_is_list($input)) {
            throw new \InvalidArgumentException('Checkout contact/address update must be a non-empty object.');
        }
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new \InvalidArgumentException('Checkout contact/address update contains an unsupported field.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Checkout contact/address value is invalid.');
            }
            $clean = function_exists('sanitize_text_field')
                ? sanitize_text_field($value)
                : trim((string) preg_replace('/<[^>]*>/', '', $value));
            if ($key === 'email') {
                $clean = function_exists('sanitize_email') ? sanitize_email($clean) : filter_var($clean, FILTER_SANITIZE_EMAIL);
                if ($clean !== '' && ((function_exists('is_email') && is_email($clean) === false) || (!function_exists('is_email') && filter_var($clean, FILTER_VALIDATE_EMAIL) === false))) {
                    throw new \InvalidArgumentException('Checkout email address is invalid.');
                }
            }
            if ($key === 'phone' && function_exists('wc_sanitize_phone_number')) {
                $clean = wc_sanitize_phone_number($clean);
            }
            if ($address && $key === 'postcode' && function_exists('wc_format_postcode')) {
                $country = isset($input['country']) && is_string($input['country']) ? strtoupper($input['country']) : '';
                $clean = wc_format_postcode($clean, $country);
            }
            $result[$key] = $clean;
        }

        return $result;
    }
}
