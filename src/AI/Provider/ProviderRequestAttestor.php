<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ProviderRequest;
use Veyra\Shared\Domain\CanonicalJson;

/** Per-runtime signature over the complete provider-independent request. */
final class ProviderRequestAttestor
{
    private readonly string $key;

    public function __construct(?string $key = null)
    {
        $key ??= random_bytes(32);
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Provider request attestation key is invalid.');
        }
        $this->key = $key;
    }

    public function seal(ProviderRequest $request): ProviderRequest
    {
        if ($request->attestation !== '') {
            throw new \InvalidArgumentException('Provider request is already attested.');
        }
        return $request->withAttestation($this->signature($request));
    }

    public function verify(ProviderRequest $request): bool
    {
        if ($request->attestation === '') {
            return false;
        }
        try {
            return hash_equals($this->signature($request), $request->attestation);
        } catch (\Throwable) {
            return false;
        }
    }

    private function signature(ProviderRequest $request): string
    {
        return hash_hmac('sha256', CanonicalJson::encode($request->attestationMaterial()), $this->key);
    }
}
