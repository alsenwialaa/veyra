<?php

declare(strict_types=1);

namespace Veyra\Shared\Application;

use Veyra\Shared\Domain\CorrelationId;

/** @template T */
final class OperationResult implements \JsonSerializable
{
    /**
     * @param T|null                    $value
     * @param list<string>             $changedResources
     * @param list<string>             $unchangedResources
     * @param list<string>             $warnings
     * @param array<string, mixed>      $safeCustomerMessageData
     */
    private function __construct(
        public readonly string $schemaVersion,
        public readonly OperationStatus $status,
        public readonly string $code,
        public readonly mixed $value,
        public readonly ?string $authoritativeVersion,
        public readonly array $changedResources,
        public readonly array $unchangedResources,
        public readonly array $warnings,
        public readonly array $safeCustomerMessageData,
        public readonly RetrySafety $retrySafety,
        public readonly CorrelationId $correlationId,
        public readonly ?string $auditReference,
        public readonly ?OperationError $error
    ) {
    }

    /**
     * @template S
     * @param S                     $value
     * @param list<string>          $changedResources
     * @param array<string, mixed>  $safeCustomerMessageData
     * @return self<S>
     */
    public static function succeeded(
        string $code,
        mixed $value,
        CorrelationId $correlationId,
        array $changedResources = [],
        array $safeCustomerMessageData = [],
        ?string $authoritativeVersion = null,
        ?string $auditReference = null
    ): self {
        return new self(
            '1.0.0',
            OperationStatus::Succeeded,
            self::validateCode($code),
            $value,
            $authoritativeVersion,
            $changedResources,
            [],
            [],
            $safeCustomerMessageData,
            RetrySafety::NotApplicable,
            $correlationId,
            $auditReference,
            null
        );
    }

    /**
     * @template S
     * @param S|null                $value
     * @param list<string>          $warnings
     * @param array<string, mixed>  $safeCustomerMessageData
     * @return self<S>
     */
    public static function failed(
        OperationStatus $status,
        OperationError $error,
        RetrySafety $retrySafety,
        CorrelationId $correlationId,
        mixed $value = null,
        array $warnings = [],
        array $safeCustomerMessageData = []
    ): self {
        if ($status === OperationStatus::Succeeded) {
            throw new \InvalidArgumentException('A failed result cannot use succeeded status.');
        }

        return new self(
            '1.0.0',
            $status,
            $error->code,
            $value,
            null,
            [],
            [],
            $warnings,
            $safeCustomerMessageData,
            $retrySafety,
            $correlationId,
            null,
            $error
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'status' => $this->status->value,
            'code' => $this->code,
            'value' => $this->value,
            'authoritative_version' => $this->authoritativeVersion,
            'changed_resources' => $this->changedResources,
            'unchanged_resources' => $this->unchangedResources,
            'warnings' => $this->warnings,
            'safe_customer_message_data' => $this->safeCustomerMessageData,
            'retry_safety' => $this->retrySafety->value,
            'correlation_id' => $this->correlationId->value(),
            'audit_reference' => $this->auditReference,
            'error' => $this->error,
        ];
    }

    private static function validateCode(string $code): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Operation result code is invalid.');
        }

        return $code;
    }
}

