<?php
declare(strict_types=1);

namespace Veyra\Experience\Contract;

/**
 * Presentation-schema validation hooks. Server publication must call this in
 * addition to capability, feature, dependency, and audit gates.
 */
final class ExperienceConfigurationValidator
{
    public const SCHEMA_VERSION = 'veyra.experience.v1';

    /** @var list<string> */
    private const NON_HIDEABLE_TRUTHS = [
        'product_identity',
        'variation',
        'current_price',
        'current_total',
        'shipping',
        'tax',
        'fees',
        'required_terms',
        'ai_identity',
        'error_state',
        'permission_state',
        'confirmation_scope',
        'payment_implications',
        'accessibility_controls',
    ];

    /**
     * @param array<string, mixed> $configuration
     * @return list<array{code:string,path:string,message:string}>
     */
    public function validate(array $configuration): array
    {
        $issues = [];

        if (($configuration['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $issues[] = $this->issue('schema_version_invalid', '$.schema_version', 'Unsupported Experience schema version.');
        }

        $hidden = $configuration['hidden_parts'] ?? [];
        if (!is_array($hidden)) {
            $issues[] = $this->issue('hidden_parts_invalid', '$.hidden_parts', 'Hidden parts must be a list.');
        } else {
            foreach (self::NON_HIDEABLE_TRUTHS as $truth) {
                if (in_array($truth, $hidden, true)) {
                    $issues[] = $this->issue(
                        'mandatory_truth_hidden',
                        '$.hidden_parts',
                        sprintf('The mandatory %s truth cannot be hidden.', str_replace('_', ' ', $truth))
                    );
                }
            }
        }

        $components = $configuration['components'] ?? [];
        if (!is_array($components)) {
            $issues[] = $this->issue('components_invalid', '$.components', 'Components must be an object.');
        } else {
            foreach (self::NON_HIDEABLE_TRUTHS as $truth) {
                if (
                    isset($components[$truth])
                    && is_array($components[$truth])
                    && array_key_exists('visible', $components[$truth])
                    && $components[$truth]['visible'] !== true
                ) {
                    $issues[] = $this->issue(
                        'mandatory_truth_hidden',
                        '$.components.' . $truth . '.visible',
                        sprintf('The mandatory %s truth must remain visible.', str_replace('_', ' ', $truth))
                    );
                }
            }
        }

        $tokens = $configuration['tokens'] ?? [];
        if (!is_array($tokens)) {
            $issues[] = $this->issue('tokens_invalid', '$.tokens', 'Design tokens must be an object.');
            return $issues;
        }

        $colors = $tokens['colors'] ?? [];
        if (!is_array($colors)) {
            $issues[] = $this->issue('colors_invalid', '$.tokens.colors', 'Semantic colors must be an object.');
        } else {
            foreach ($colors as $name => $color) {
                if (!is_string($name) || !is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/D', $color) !== 1) {
                    $issues[] = $this->issue(
                        'color_invalid',
                        '$.tokens.colors.' . (is_string($name) ? $name : 'unknown'),
                        'Colors must use six-digit hexadecimal values before publication.'
                    );
                }
            }
        }

        $minimumTouchTarget = $tokens['minimum_touch_target_px'] ?? 44;
        if (!is_int($minimumTouchTarget) || $minimumTouchTarget < 44 || $minimumTouchTarget > 80) {
            $issues[] = $this->issue(
                'touch_target_invalid',
                '$.tokens.minimum_touch_target_px',
                'Primary touch targets must be between 44 and 80 CSS pixels.'
            );
        }

        $bodySize = $tokens['body_font_size_px'] ?? 16;
        if (!is_int($bodySize) || $bodySize < 14 || $bodySize > 24) {
            $issues[] = $this->issue(
                'body_font_size_invalid',
                '$.tokens.body_font_size_px',
                'Body type must remain readable at the supported zoom levels.'
            );
        }

        $focusWidth = $tokens['focus_width_px'] ?? 3;
        if (!is_int($focusWidth) || $focusWidth < 2 || $focusWidth > 8) {
            $issues[] = $this->issue(
                'focus_indicator_invalid',
                '$.tokens.focus_width_px',
                'The visible focus indicator cannot be removed or made too thin.'
            );
        }

        $motion = $tokens['motion'] ?? [];
        if (!is_array($motion)) {
            $issues[] = $this->issue('motion_invalid', '$.tokens.motion', 'Motion settings must be an object.');
        } else {
            if (($motion['honor_reduced_motion'] ?? true) !== true) {
                $issues[] = $this->issue(
                    'reduced_motion_required',
                    '$.tokens.motion.honor_reduced_motion',
                    'Reduced-motion preferences must be honored.'
                );
            }
            $duration = $motion['maximum_duration_ms'] ?? 240;
            if (!is_int($duration) || $duration < 0 || $duration > 500) {
                $issues[] = $this->issue(
                    'motion_duration_invalid',
                    '$.tokens.motion.maximum_duration_ms',
                    'Motion duration must remain bounded.'
                );
            }
        }

        return $issues;
    }

    /** @return list<string> */
    public static function nonHideableTruths(): array
    {
        return self::NON_HIDEABLE_TRUTHS;
    }

    /** @return array{code:string,path:string,message:string} */
    private function issue(string $code, string $path, string $message): array
    {
        return ['code' => $code, 'path' => $path, 'message' => $message];
    }
}
