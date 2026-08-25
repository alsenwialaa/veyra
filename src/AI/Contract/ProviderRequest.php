<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

use Veyra\Conversation\Domain\ContextBundle;

final class ProviderRequest
{
    public const TRAFFIC_SHOPPER = 'shopper_context';
    public const TRAFFIC_READINESS = 'provider_readiness';
    public const TRAFFIC_INTERNAL = 'internal_non_transmitting';
    public const PURPOSE_SHOPPER = 'shopper_commerce_assistance';
    public const PURPOSE_READINESS = 'explicit_provider_readiness';
    public const PHASE_INTERNAL = 'internal';
    public const PHASE_DECISION = 'shopper_decision';
    public const PHASE_RESPONSE = 'shopper_response';
    public const PHASE_SEMANTIC_VERIFICATION = 'shopper_semantic_verification';
    public const PHASE_READINESS = 'provider_readiness_probe';

    /**
     * @param array<int, array<string, mixed>> $input
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $responseSchema
     * @param array<string, scalar|null>        $metadata
     * @param array<int, ProviderFunctionResult> $functionResults
     */
    public function __construct(
        public readonly string $routeId,
        public readonly string $systemInstruction,
        public readonly array $input,
        public readonly array $tools,
        public readonly array $responseSchema,
        public readonly int $timeoutSeconds,
        public readonly array $metadata = [],
        public readonly ?ProviderContinuation $continuation = null,
        public readonly array $functionResults = [],
        public readonly string $trafficClass = self::TRAFFIC_INTERNAL,
        public readonly string $purpose = self::TRAFFIC_INTERNAL,
        public readonly ?ContextBundle $contextBundle = null,
        public readonly string $phase = self::PHASE_INTERNAL,
        public readonly string $attestation = ''
    ) {
        if ($routeId === '' || $timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new \InvalidArgumentException('Invalid provider request envelope.');
        }
        if (($continuation === null) !== ($functionResults === [])) {
            throw new \InvalidArgumentException('Provider continuation and function results must be supplied together.');
        }
        foreach ($functionResults as $result) {
            if (!$result instanceof ProviderFunctionResult) {
                throw new \InvalidArgumentException('Invalid provider function result.');
            }
        }
        if (!in_array($trafficClass, [self::TRAFFIC_SHOPPER, self::TRAFFIC_READINESS, self::TRAFFIC_INTERNAL], true)
            || $purpose === '' || strlen($purpose) > 160 || preg_match('//u', $purpose) !== 1
        ) {
            throw new \InvalidArgumentException('Provider traffic classification is invalid.');
        }
        if ($trafficClass === self::TRAFFIC_SHOPPER && !($contextBundle instanceof ContextBundle)) {
            throw new \InvalidArgumentException('Shopper provider traffic requires a validated Context Bundle.');
        }
        if ($trafficClass !== self::TRAFFIC_SHOPPER && $contextBundle !== null) {
            throw new \InvalidArgumentException('Only shopper traffic may carry a Context Bundle.');
        }
        $allowedPhases = match ($trafficClass) {
            self::TRAFFIC_SHOPPER => [self::PHASE_DECISION, self::PHASE_RESPONSE, self::PHASE_SEMANTIC_VERIFICATION],
            self::TRAFFIC_READINESS => [self::PHASE_READINESS],
            default => [self::PHASE_INTERNAL],
        };
        if (!in_array($phase, $allowedPhases, true)
            || ($attestation !== '' && preg_match('/^[a-f0-9]{64}$/D', $attestation) !== 1)
        ) {
            throw new \InvalidArgumentException('Provider request phase or attestation is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function attestationMaterial(): array
    {
        if ($this->continuation !== null || $this->functionResults !== []) {
            throw new \LogicException('Transmitting attestations do not support provider continuation state.');
        }
        return [
            'route_id' => $this->routeId,
            'system_instruction' => $this->systemInstruction,
            'input' => $this->input,
            'tools' => $this->tools,
            'response_schema' => $this->responseSchema,
            'timeout_seconds' => $this->timeoutSeconds,
            'metadata' => $this->metadata,
            'traffic_class' => $this->trafficClass,
            'purpose' => $this->purpose,
            'phase' => $this->phase,
            'context_bundle_hash' => $this->contextBundle?->hash,
        ];
    }

    public function withAttestation(string $attestation): self
    {
        return new self(
            $this->routeId,
            $this->systemInstruction,
            $this->input,
            $this->tools,
            $this->responseSchema,
            $this->timeoutSeconds,
            $this->metadata,
            $this->continuation,
            $this->functionResults,
            $this->trafficClass,
            $this->purpose,
            $this->contextBundle,
            $this->phase,
            $attestation
        );
    }
}
