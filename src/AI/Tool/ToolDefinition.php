<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

final class ToolDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<int, string>   $actors
     * @param array<int, string>   $capabilities
     * @param array<int, string>   $features
     * @param array<string, mixed> $outputSchema Closed successful-result data schema for a certified tool.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $classification,
        public readonly array $inputSchema,
        public readonly array $actors,
        public readonly array $capabilities,
        public readonly array $features,
        public readonly bool $modelVisible,
        public readonly array $outputSchema = []
    ) {
        if (!in_array($classification, ['read', 'write', 'sensitive_write', 'advisory'], true)) {
            throw new \InvalidArgumentException('Invalid tool classification.');
        }
    }

    /** @return array<string, mixed> */
    public function forModel(): array
    {
        $schema = $this->inputSchema;
        // Idempotency is a server-owned replay boundary. Provider-visible
        // schemas must not ask the model to invent the key that authorizes it.
        if (in_array($this->classification, ['write', 'sensitive_write'], true)
            && is_array($schema['properties'] ?? null)
            && array_key_exists('idempotency_key', $schema['properties'])
        ) {
            unset($schema['properties']['idempotency_key']);
            if (is_array($schema['required'] ?? null)) {
                $schema['required'] = array_values(array_filter(
                    $schema['required'],
                    static fn (mixed $field): bool => $field !== 'idempotency_key'
                ));
            }
        }

        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'input_schema' => $schema,
        ];
    }
}
