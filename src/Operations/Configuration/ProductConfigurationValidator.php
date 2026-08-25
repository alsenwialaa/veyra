<?php

declare(strict_types=1);

namespace Veyra\Operations\Configuration;

use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\ReleaseUnit;

final class ProductConfigurationValidator
{
    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly ExperienceConfigurationValidator $experience
    ) {
    }

    /** @param array<string, mixed> $configuration @return list<array{code:string,path:string,message:string}> */
    public function validate(string $product, array $configuration): array
    {
        return match ($product) {
            'agent' => $this->agent($configuration),
            'knowledge' => $this->knowledge($configuration),
            'experience' => $this->experience->validate($configuration),
            'commerce' => $this->commerce($configuration),
            default => [$this->issue('product_unknown', '$.product', 'Unknown administration product.')],
        };
    }

    /** @return array<string, array<string, mixed>> */
    public function defaults(): array
    {
        return [
            'agent' => [
                'public_name' => 'Veyra',
                'default_language' => 'auto',
                'disclosure_text' => 'AI shopping assistant. Store staff may review retained conversations.',
                'formality' => 'balanced',
                'response_length' => 'concise',
                'recommendation_limit' => 4,
                'handoff_threshold' => 'balanced',
                'respect_refusal' => true,
            ],
            'knowledge' => [
                'store_name' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
                'timezone' => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC',
                'market' => '',
                'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
                'durable_memory_enabled' => false,
            ],
            'experience' => [
                'schema_version' => ExperienceConfigurationValidator::SCHEMA_VERSION,
                'hidden_parts' => [],
                'components' => [],
                'tokens' => [
                    'colors' => ['brand' => '#0C4A46', 'accent' => '#B84720'],
                    'body_font_size_px' => 16,
                    'minimum_touch_target_px' => 44,
                    'focus_width_px' => 3,
                    'motion' => ['honor_reduced_motion' => true, 'maximum_duration_ms' => 240],
                ],
            ],
            'commerce' => ['features' => []],
        ];
    }

    /** @param array<string, mixed> $value @return list<array{code:string,path:string,message:string}> */
    private function agent(array $value): array
    {
        $issues = $this->unexpectedKeys($value, [
            'public_name', 'default_language', 'disclosure_text', 'formality', 'response_length',
            'recommendation_limit', 'handoff_threshold', 'respect_refusal',
        ]);
        $name = is_string($value['public_name'] ?? null) ? trim($value['public_name']) : '';
        $disclosure = is_string($value['disclosure_text'] ?? null) ? trim($value['disclosure_text']) : '';
        if ($name === '' || strlen($name) > 80) {
            $issues[] = $this->issue('public_name_invalid', '$.public_name', 'Public AI name is required and limited to 80 bytes.');
        }
        if ($disclosure === '' || strlen($disclosure) > 240) {
            $issues[] = $this->issue('disclosure_invalid', '$.disclosure_text', 'A bounded, visible AI disclosure is required.');
        }
        if (!in_array($value['default_language'] ?? null, ['auto', 'ar', 'en'], true)) {
            $issues[] = $this->issue('language_invalid', '$.default_language', 'Default language is unsupported.');
        }
        if (!in_array($value['formality'] ?? null, ['casual', 'balanced', 'formal'], true)) {
            $issues[] = $this->issue('formality_invalid', '$.formality', 'Formality is unsupported.');
        }
        if (!in_array($value['response_length'] ?? null, ['concise', 'balanced', 'detailed'], true)) {
            $issues[] = $this->issue('response_length_invalid', '$.response_length', 'Response length is unsupported.');
        }
        if (!in_array($value['handoff_threshold'] ?? null, ['early', 'balanced', 'only_when_needed'], true)) {
            $issues[] = $this->issue('handoff_threshold_invalid', '$.handoff_threshold', 'Handoff threshold is unsupported.');
        }
        if (($value['respect_refusal'] ?? null) !== true) {
            $issues[] = $this->issue('refusal_policy_required', '$.respect_refusal', 'Respecting shopper refusals cannot be disabled.');
        }
        $limit = $value['recommendation_limit'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 8) {
            $issues[] = $this->issue('recommendation_limit_invalid', '$.recommendation_limit', 'Recommendation limit must be between one and eight.');
        }
        return $issues;
    }

    /** @param array<string, mixed> $value @return list<array{code:string,path:string,message:string}> */
    private function knowledge(array $value): array
    {
        $issues = $this->unexpectedKeys($value, [
            'store_name', 'timezone', 'market', 'currency', 'durable_memory_enabled',
        ]);
        if (!is_string($value['store_name'] ?? null) || strlen(trim($value['store_name'])) > 120) {
            $issues[] = $this->issue('store_name_invalid', '$.store_name', 'Store display name must be a string limited to 120 bytes.');
        }
        if (!is_string($value['market'] ?? null) || strlen(trim($value['market'])) > 80) {
            $issues[] = $this->issue('market_invalid', '$.market', 'Market must be a string limited to 80 bytes.');
        }
        if (!is_bool($value['durable_memory_enabled'] ?? null)) {
            $issues[] = $this->issue('durable_memory_state_invalid', '$.durable_memory_enabled', 'Durable memory state must be a boolean.');
        }
        if (($value['durable_memory_enabled'] ?? false) === true) {
            $issues[] = $this->issue(
                'optional_memory_uncertified',
                '$.durable_memory_enabled',
                'Durable preference memory is an uncertified optional module and cannot be published.'
            );
        }
        $currency = is_string($value['currency'] ?? null) ? $value['currency'] : '';
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            $issues[] = $this->issue('currency_invalid', '$.currency', 'Currency must be an ISO-style three-letter uppercase code.');
        }
        $timezone = is_string($value['timezone'] ?? null) ? $value['timezone'] : '';
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $issues[] = $this->issue('timezone_invalid', '$.timezone', 'Time zone must be a recognized IANA identifier.');
        }
        return $issues;
    }

    /** @param array<string, mixed> $value @return list<array{code:string,path:string,message:string}> */
    private function commerce(array $value): array
    {
        $issues = $this->unexpectedKeys($value, ['features']);
        $configured = $value['features'] ?? [];
        if (!is_array($configured)) {
            $issues[] = $this->issue('features_invalid', '$.features', 'Features must be a keyed object.');
            return $issues;
        }
        $known = [];
        foreach ($this->features->all() as $definition) {
            $known[$definition->key->value()] = $definition;
        }
        foreach (array_diff(array_keys($known), array_keys($configured)) as $missing) {
            $issues[] = $this->issue(
                'feature_missing',
                '$.features.' . $missing,
                'A complete feature map is required so publication cannot reset omitted state.'
            );
        }
        foreach ($configured as $key => $feature) {
            if (!is_string($key) || !isset($known[$key]) || !is_array($feature)) {
                $issues[] = $this->issue('feature_unknown', '$.features.' . (string) $key, 'Unknown feature configuration.');
                continue;
            }
            if (array_diff(array_keys($feature), ['configured_state']) !== []) {
                $issues[] = $this->issue('feature_fields_unknown', '$.features.' . $key, 'Unknown feature configuration field.');
            }
            $state = $feature['configured_state'] ?? null;
            if (!in_array($state, ['On', 'Off'], true)) {
                $issues[] = $this->issue('feature_state_invalid', '$.features.' . $key, 'Configured state must be On or Off.');
            }
            if ($state === 'On' && $known[$key]->releaseUnit === ReleaseUnit::OptionalModule) {
                $issues[] = $this->issue('optional_module_uncertified', '$.features.' . $key, 'Optional modules cannot be enabled without separate certification.');
            }
            if ($state === 'Off' && $known[$key]->foundational) {
                $issues[] = $this->issue('foundational_feature_required', '$.features.' . $key, 'Foundational features cannot be configured Off.');
            }
        }
        return $issues;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed @return list<array{code:string,path:string,message:string}> */
    private function unexpectedKeys(array $value, array $allowed): array
    {
        $issues = [];
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $issues[] = $this->issue(
                'configuration_field_unknown',
                '$.' . (string) $key,
                'Unknown configuration field.'
            );
        }

        return $issues;
    }

    /** @return array{code:string,path:string,message:string} */
    private function issue(string $code, string $path, string $message): array
    {
        return ['code' => $code, 'path' => $path, 'message' => $message];
    }
}
