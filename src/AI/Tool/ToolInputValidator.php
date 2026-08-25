<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

final class ToolInputValidator
{
    /** @param array<string, mixed> $value @param array<string, mixed> $schema */
    public function validate(array $value, array $schema): bool
    {
        return $this->validateNode($value, $schema + ['type' => 'object'], 0);
    }

    /** Validate an arbitrary schema node without coercing it to an object. */
    public function validateValue(mixed $value, array $schema): bool
    {
        return $this->validateNode($value, $schema, 0);
    }

    /** @param array<string, mixed> $schema */
    private function validateNode(mixed $value, array $schema, int $depth): bool
    {
        if ($depth > 12) {
            return false;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }
        if (isset($schema['oneOf'])) {
            if (!is_array($schema['oneOf']) || !array_is_list($schema['oneOf'])) {
                return false;
            }
            $matches = 0;
            foreach ($schema['oneOf'] as $candidate) {
                if (is_array($candidate) && $this->validateNode($value, $candidate, $depth + 1)) {
                    ++$matches;
                }
            }
            if ($matches !== 1) {
                return false;
            }
        }
        $type = $schema['type'] ?? null;
        $types = is_array($type) && array_is_list($type) ? $type : [$type];
        $valid = false;
        foreach ($types as $candidateType) {
            $valid = $valid || match ($candidateType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'null' => $value === null,
            null => true,
            default => false,
            };
        }
        if (!$valid) {
            return false;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (is_string($value) && isset($schema['maxLength']) && strlen($value) > (int) $schema['maxLength']) {
            return false;
        }
        if (is_string($value) && isset($schema['minLength']) && strlen($value) < (int) $schema['minLength']) {
            return false;
        }
        if (is_string($value) && isset($schema['pattern']) && is_string($schema['pattern'])) {
            $pattern = '~' . str_replace('~', '\\~', $schema['pattern']) . '~D';
            if (@preg_match($pattern, $value) !== 1) {
                return false;
            }
        }
        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            return false;
        }
        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            return false;
        }
        if (is_array($value) && array_is_list($value) && isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            return false;
        }
        if (is_array($value) && array_is_list($value) && isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            return false;
        }
        if (is_array($value) && array_is_list($value) && ($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $encoded = json_encode($item, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded) || isset($seen[$encoded])) {
                    return false;
                }
                $seen[$encoded] = true;
            }
        }
        if (is_array($value) && array_is_list($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $item) {
                if (!$this->validateNode($item, $schema['items'], $depth + 1)) {
                    return false;
                }
            }
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))
            && (in_array('object', $types, true) || isset($schema['properties']) || isset($schema['additionalProperties']))
        ) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                return false;
            }
            if (isset($schema['maxProperties']) && count($value) > (int) $schema['maxProperties']) {
                return false;
            }
            if (isset($schema['minProperties']) && count($value) < (int) $schema['minProperties']) {
                return false;
            }
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $key) {
                if (!is_string($key) || !array_key_exists($key, $value)) {
                    return false;
                }
            }
            $additional = $schema['additionalProperties'] ?? true;
            $unknown = array_diff(array_keys($value), array_keys($properties));
            if ($additional === false && $unknown !== []) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key])
                    && !$this->validateNode($item, $properties[$key], $depth + 1)
                ) {
                    return false;
                }
                if (!isset($properties[$key]) && is_array($additional)
                    && !$this->validateNode($item, $additional, $depth + 1)
                ) {
                    return false;
                }
            }
        }
        return true;
    }
}
