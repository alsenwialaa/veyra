<?php

declare(strict_types=1);

namespace Veyra\Media\Presentation;

use Closure;
use Veyra\AI\Tool\ToolContextFactory;
use Veyra\Features\Application\FeatureGate;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Http\Correlation;
use Veyra\Http\RateLimiter;
use Veyra\Http\RestEnvelope;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorType;
use Veyra\Media\Application\ProtectedAttachmentAccessService;
use Veyra\Media\Application\ProtectedUploadService;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\Uuid;

/** Actor-owned, CSRF-protected upload and controlled download adapter. */
final class MediaRestController
{
    private readonly Closure $uploadedFileCheck;

    /** @param callable(string):bool|null $uploadedFileCheck */
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly GuestSessionManager $guestSessions,
        private readonly FeatureGate $features,
        private readonly ToolContextFactory $contexts,
        private readonly ProtectedUploadService $uploads,
        private readonly ProtectedAttachmentAccessService $access,
        private readonly RateLimiter $rateLimiter,
        private readonly Clock $clock,
        ?callable $uploadedFileCheck = null
    ) {
        $this->uploadedFileCheck = Closure::fromCallable(
            $uploadedFileCheck ?? static fn (string $path): bool => is_uploaded_file($path)
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_filter('rest_pre_serve_request', [$this, 'serveProtectedStream'], 10, 4);
    }

    public function registerRoutes(): void
    {
        register_rest_route('veyra/v1', '/attachments', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload'],
            'permission_callback' => [$this, 'canUpload'],
        ]);
        register_rest_route('veyra/v1', '/attachments/(?P<attachment_id>[a-f0-9-]{36})/content', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'download'],
            'permission_callback' => [$this, 'canRead'],
            'args' => [
                'attachment_id' => ['type' => 'string', 'required' => true],
                'conversation_id' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** @return bool|\WP_Error */
    public function canUpload(\WP_REST_Request $request): bool|\WP_Error
    {
        $actor = $this->authorizedCustomerActor($request, true);
        if ($actor instanceof \WP_Error) {
            return $actor;
        }
        $purpose = $request->get_param('purpose');
        $feature = $purpose === 'payment_evidence'
            ? 'payment_offline_review'
            : ($purpose === 'crm_evidence' ? 'service_crm' : null);
        if ($feature === null) {
            return $this->permissionError('upload_purpose_not_allowed', 400);
        }
        $state = $this->features->inspect(new FeatureKey($feature));

        return $state->usable()
            ? true
            : $this->permissionError($state->reasonCode, 503);
    }

    /** @return bool|\WP_Error */
    public function canRead(\WP_REST_Request $request): bool|\WP_Error
    {
        $actor = $this->authorizedCustomerActor($request, false);

        return $actor instanceof Actor ? true : $actor;
    }

    public function upload(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(true);
        $body = $request->get_body_params();
        $files = $request->get_file_params();
        if (!$actor instanceof Actor || !$this->validUploadCommand($body) || !$this->validUploadFile($files['file'] ?? null)) {
            return $this->json(RestEnvelope::blocked('upload_contract_invalid', 'The upload did not match the bounded public contract.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        if (!$this->rateLimiter->consume($actor, 'media.upload', 10)) {
            return $this->json(RestEnvelope::blocked('upload_rate_limited', 'Too many upload attempts were submitted.', $correlation->value(), 'safe_no_side_effect'), 429);
        }
        $key = $request->get_header('Idempotency-Key');
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 191) {
            return $this->json(RestEnvelope::blocked('idempotency_key_invalid', 'A bounded idempotency key is required.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        $file = $files['file'];
        try {
            $context = $this->contexts->create($actor, (string) $body['conversation_id'], $correlation->value());
            $outcome = $this->uploads->accept(
                $context,
                (string) $file['tmp_name'],
                (string) $file['type'],
                (string) $body['purpose'],
                $key,
                is_string($body['message_id'] ?? null) ? $body['message_id'] : null
            );
            $value = $outcome->attachment === null
                ? ['attachment' => null]
                : ['attachment' => $outcome->attachment->safeMetadata($this->clock->now())];
            $envelope = RestEnvelope::make(
                $outcome->status,
                $outcome->code,
                $value,
                $correlation->value(),
                $outcome->retrySafe ? 'safe_no_side_effect' : 'never_retry'
            );
            $status = match ($outcome->status) {
                'succeeded' => $outcome->code === 'upload_idempotent_replay' ? 200 : 201,
                'blocked' => 422,
                'uncertain' => 503,
                default => 503,
            };
            return $this->json($envelope, $status);
        } catch (\Throwable) {
            return $this->json(RestEnvelope::failed('upload_service_unavailable', 'The protected upload service is unavailable.', $correlation->value()), 503);
        }
    }

    public function download(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(true);
        $attachmentId = $request->get_param('attachment_id');
        $conversationId = $request->get_param('conversation_id');
        if (!$actor instanceof Actor || !is_string($attachmentId) || !Uuid::isValid($attachmentId)
            || !is_string($conversationId) || !Uuid::isValid($conversationId)
        ) {
            return $this->json(RestEnvelope::blocked('attachment_target_invalid', 'The protected attachment target is invalid.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        if (!$this->rateLimiter->consume($actor, 'media.download', 120)) {
            return $this->json(RestEnvelope::blocked('attachment_rate_limited', 'Protected attachment access is temporarily rate limited.', $correlation->value(), 'safe_no_side_effect'), 429);
        }
        try {
            $opened = $this->access->open($this->contexts->create($actor, $conversationId, $correlation->value()), $attachmentId);
            $metadata = $opened['metadata'];
            $response = new \WP_REST_Response(new ProtectedStreamPayload($opened['stream']), 200);
            $response->header('Content-Type', (string) $metadata['mime_type']);
            $response->header('Content-Length', (string) ((int) $metadata['byte_size']));
            $response->header('Content-Disposition', 'attachment; filename="veyra-attachment-' . $attachmentId . $this->extension((string) $metadata['mime_type']) . '"');
            $response->header('Cache-Control', 'no-store, private');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
            return $response;
        } catch (\Throwable) {
            return $this->json(RestEnvelope::blocked('attachment_not_owned_or_unavailable', 'The protected attachment is unavailable.', $correlation->value(), 'never_retry'), 404);
        }
    }

    public function serveProtectedStream(bool $served, mixed $result, \WP_REST_Request $request, mixed $server): bool
    {
        unset($request, $server);
        if ($served || !$result instanceof \WP_REST_Response || !$result->get_data() instanceof ProtectedStreamPayload) {
            return $served;
        }
        $payload = $result->get_data();
        try {
            while (!feof($payload->stream)) {
                $chunk = fread($payload->stream, 8192);
                if (!is_string($chunk)) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authorized binary stream.
            }
        } finally {
            $payload->close();
        }

        return true;
    }

    /** @return Actor|\WP_Error */
    private function authorizedCustomerActor(\WP_REST_Request $request, bool $mutation): Actor|\WP_Error
    {
        $actor = $this->actors->resolve(true);
        if (!$actor instanceof Actor) {
            return $this->permissionError('veyra_authentication_required', 401);
        }
        if (!in_array($actor->type, [ActorType::Guest, ActorType::Customer], true)) {
            return $this->permissionError('veyra_actor_type_denied', 403);
        }
        if (!$mutation) {
            return $actor;
        }
        if ($actor->type !== ActorType::Guest) {
            $nonce = $request->get_header('X-WP-Nonce');
            return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') === 1
                ? $actor
                : $this->permissionError('veyra_csrf_check_failed', 403);
        }
        $raw = isset($_COOKIE[GuestSessionManager::COOKIE_NAME]) && is_string($_COOKIE[GuestSessionManager::COOKIE_NAME])
            ? $_COOKIE[GuestSessionManager::COOKIE_NAME]
            : null;
        $context = $this->guestSessions->inspectFromRawToken($raw);
        $csrf = $request->get_header('X-Veyra-CSRF');
        return $context !== null && is_string($csrf) && $this->guestSessions->verifyCsrf($context->session, $csrf)
            ? $actor
            : $this->permissionError('veyra_csrf_check_failed', 403);
    }

    /** @param mixed $body */
    private function validUploadCommand(mixed $body): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $allowed = ['schema_version', 'conversation_id', 'purpose', 'message_id'];
        if (array_diff(array_keys($body), $allowed) !== []
            || ($body['schema_version'] ?? null) !== 'veyra.protected_upload_command.v1'
            || !is_string($body['conversation_id'] ?? null)
            || !Uuid::isValid($body['conversation_id'])
            || !in_array($body['purpose'] ?? null, ['payment_evidence', 'crm_evidence'], true)
        ) {
            return false;
        }
        $messageId = $body['message_id'] ?? null;
        return $messageId === null || (is_string($messageId) && (Uuid::isValid($messageId) || preg_match('/^msg_[a-f0-9]{32}$/D', $messageId) === 1));
    }

    /** @param mixed $file */
    private function validUploadFile(mixed $file): bool
    {
        if (!is_array($file)
            || !is_string($file['tmp_name'] ?? null)
            || !is_string($file['type'] ?? null)
            || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        ) {
            return false;
        }
        return ($this->uploadedFileCheck)($file['tmp_name']);
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            default => '.bin',
        };
    }

    private function permissionError(string $code, int $status): \WP_Error
    {
        return new \WP_Error($code, __('The protected media request is not authorized.', 'veyra-ai-commerce-agent'), ['status' => $status]);
    }

    /** @param array<string,mixed> $envelope */
    private function json(array $envelope, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($envelope, $status);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
