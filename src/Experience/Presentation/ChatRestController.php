<?php

declare(strict_types=1);

namespace Veyra\Experience\Presentation;

use Veyra\AI\Orchestration\AgentTurnInput;
use Veyra\AI\Orchestration\CommerceAgent;
use Veyra\AI\Tool\ToolContextFactory;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Http\Correlation;
use Veyra\Http\CustomerMessagePresenter;
use Veyra\Http\RateLimiter;
use Veyra\Http\RestEnvelope;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Presentation\RestPermissionGate;

final class ChatRestController
{
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly RestPermissionGate $readPermission,
        private readonly RestPermissionGate $writePermission,
        private readonly ConversationStore $conversations,
        private readonly CommerceAgent $agent,
        private readonly ToolContextFactory $contexts,
        private readonly CustomerMessagePresenter $messages,
        private readonly IdempotencyService $idempotency,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('veyra/v1', '/conversations/current/messages', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'history'],
                'permission_callback' => [$this, 'canRead'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'send'],
                'permission_callback' => [$this, 'canWrite'],
            ],
        ]);
        register_rest_route('veyra/v1', '/conversations', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'newConversation'],
            'permission_callback' => [$this, 'canWrite'],
        ]);
        register_rest_route('veyra/v1', '/conversations/current/turns/cancel', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancelTurn'],
            'permission_callback' => [$this, 'canWrite'],
        ]);
        register_rest_route('veyra/v1', '/conversations/current/interactions', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'interaction'],
            'permission_callback' => [$this, 'canWrite'],
        ]);
    }

    /** @return bool|\WP_Error */
    public function canRead(\WP_REST_Request $request): bool|\WP_Error
    {
        return ($this->readPermission)($request);
    }

    /** @return bool|\WP_Error */
    public function canWrite(\WP_REST_Request $request): bool|\WP_Error
    {
        return ($this->writePermission)($request);
    }

    public function history(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(true);
        if ($actor === null) {
            if (!$this->rateLimiter->consumePreSession($this->clientNetworkAddress(), 'chat.guest_bootstrap', 10)) {
                return $this->response(RestEnvelope::blocked('guest_session_rate_limited', 'A guest session cannot be started right now.', $correlation->value(), 'safe_no_side_effect'), 429);
            }

            try {
                $actor = $this->actors->resolveOrCreateGuest();
            } catch (\Throwable) {
                return $this->response(RestEnvelope::failed('guest_session_unavailable', 'A secure guest session could not be started.', $correlation->value()), 503);
            }
        }
        if (!$this->rateLimiter->consume($actor, 'chat.history', 120)) {
            return $this->response(RestEnvelope::blocked('chat_history_rate_limited', 'Conversation history is temporarily unavailable.', $correlation->value(), 'safe_no_side_effect'), 429);
        }
        $actorType = $this->contexts->actorType($actor);
        $requestedConversationId = $request->get_param('conversation_id');
        if ($requestedConversationId !== null && !$this->validOpaqueId($requestedConversationId)) {
            return $this->response(RestEnvelope::blocked('conversation_target_invalid', 'The requested conversation target is invalid.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        $conversation = is_string($requestedConversationId)
            ? $this->conversations->getOwnedConversation($requestedConversationId, $actorType, $actor->id->value())
            : $this->conversations->currentOwnedConversation($actorType, $actor->id->value());
        if (is_string($requestedConversationId) && $conversation === null) {
            return $this->response(RestEnvelope::blocked('conversation_not_owned', 'The requested conversation is unavailable.', $correlation->value(), 'never_retry'), 404);
        }
        if ($conversation === null) {
            return $this->response(RestEnvelope::succeeded('chat_history_empty', [
                'conversation_id' => null,
                'messages' => [],
                'next_cursor' => null,
                'quick_replies' => [],
                'reconciliation_complete' => false,
                'reconciliation_code' => 'reconciliation_not_requested',
            ], $correlation->value()));
        }
        $conversationId = (string) $conversation['public_id'];
        $raw = $this->conversations->recentVisibleMessages($conversationId, $actorType, $actor->id->value(), 50);
        $messages = [];
        $warnings = [];
        foreach ($raw as $row) {
            try {
                $messages[] = $this->messages->present($conversationId, $row);
            } catch (\Throwable) {
                $warnings[] = 'message_render_contract_rejected';
            }
        }
        $reconciliation = ['complete' => false, 'code' => 'reconciliation_not_requested'];
        $reconciliationHandle = $request->get_param('reconciliation_handle');
        if ($reconciliationHandle !== null) {
            if (!$this->validOpaqueId($reconciliationHandle) || strlen((string) $reconciliationHandle) < 8) {
                return $this->response(RestEnvelope::blocked('reconciliation_handle_invalid', 'The reconciliation handle is invalid.', $correlation->value(), 'never_retry'), 400);
            }
            try {
                $reconciliation = $this->idempotency->reconciliationStatus(
                    $actor,
                    'chat.message',
                    (string) $reconciliationHandle,
                    'conversation:' . $conversationId
                );
            } catch (\Throwable) {
                $reconciliation = ['complete' => false, 'code' => 'reconciliation_unavailable'];
            }
        }
        $value = [
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'next_cursor' => null,
            'quick_replies' => $this->quickReplies($conversationId, $actorType, $actor->id->value(), $raw),
            'reconciliation_complete' => $reconciliation['complete'] === true,
            'reconciliation_code' => (string) $reconciliation['code'],
        ];
        $envelope = RestEnvelope::make(
            'succeeded',
            $warnings === [] ? 'chat_history_loaded' : 'chat_history_loaded_with_rejections',
            $value,
            $correlation->value(),
            'safe_no_side_effect',
            array_values(array_unique($warnings))
        );
        return $this->response($envelope);
    }

    public function send(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(true);
        $body = $request->get_json_params();
        if ($actor === null || !$this->validMessageCommand($body)) {
            return $this->response(RestEnvelope::blocked('chat_message_invalid', 'The message did not match the bounded public contract.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        if (!$this->rateLimiter->consume($actor, 'chat.send', 20)) {
            return $this->response(RestEnvelope::blocked('chat_message_rate_limited', 'Too many messages were submitted. Wait before trying again.', $correlation->value(), 'safe_no_side_effect'), 429);
        }
        $actorType = $this->contexts->actorType($actor);
        $conversation = $this->resolveConversation($body['conversation_id'], $actorType, $actor->id->value());
        if ($conversation === null) {
            return $this->response(RestEnvelope::blocked('conversation_not_owned', 'The exact actor-owned conversation is unavailable.', $correlation->value(), 'never_retry'), 404);
        }
        $conversationId = (string) $conversation['public_id'];
        $idempotencyKey = $request->get_header('Idempotency-Key');
        if (!is_string($idempotencyKey) || !hash_equals((string) $body['client_message_id'], $idempotencyKey)) {
            return $this->response(RestEnvelope::blocked('idempotency_key_invalid', 'The message idempotency key is missing or mismatched.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        try {
            $decision = $this->idempotency->begin(
                $actor,
                'chat.message',
                $idempotencyKey,
                $body,
                'conversation:' . $conversationId,
                $correlation
            );
        } catch (\Throwable) {
            return $this->response($this->uncertainMessageEnvelope(
                'idempotency_unavailable',
                'The message could not be safely claimed for processing.',
                $conversationId,
                $idempotencyKey,
                $correlation->value()
            ), 503);
        }
        if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
            return $this->idempotencyResponse($decision, $correlation->value(), $idempotencyKey);
        }

        try {
            $context = $this->contexts->create($actor, $conversationId, $correlation->value());
            $turn = $this->agent->handle(new AgentTurnInput(
                $context,
                (string) $body['text'],
                is_string($body['reply_to_message_id'] ?? null) ? $body['reply_to_message_id'] : null,
                is_array($body['product_references'] ?? null) ? $body['product_references'] : [],
                [],
                null,
                is_array($body['answer_binding'] ?? null) ? $body['answer_binding'] : null
            ));
            if (!in_array($turn->status, ['succeeded', 'partial', 'uncertain'], true)
                || !is_array($turn->focusUpdate)
                || !is_string($turn->focusUpdate['message_id'] ?? null)
            ) {
                if (!$this->failIdempotency(
                    $decision->record,
                    $turn->code,
                    ['conversation_id' => $conversationId],
                    false
                )) {
                    return $this->response($this->uncertainMessageEnvelope(
                        'chat_message_idempotency_transition_uncertain',
                        'The message outcome could not be finalized. Refresh the conversation before retrying.',
                        $conversationId,
                        $idempotencyKey,
                        $correlation->value()
                    ), 503);
                }
                return $this->response(RestEnvelope::make(
                    'blocked',
                    $turn->code,
                    ['message' => $turn->visibleText, 'conversation_id' => $conversationId],
                    $correlation->value(),
                    'never_retry'
                ), 503);
            }
            $row = $this->conversations->visibleMessage($conversationId, $actorType, $actor->id->value(), $turn->focusUpdate['message_id']);
            if ($row === null) {
                $this->markIdempotencyUncertain(
                    $decision->record,
                    'message_persistence_reconciliation_required',
                    ['conversation_id' => $conversationId]
                );
                return $this->response($this->uncertainMessageEnvelope(
                    'message_persistence_reconciliation_required',
                    'The response outcome requires history reconciliation.',
                    $conversationId,
                    $idempotencyKey,
                    $correlation->value()
                ), 503);
            }
            $message = $this->messages->present($conversationId, $row);
            $recent = $this->conversations->recentVisibleMessages($conversationId, $actorType, $actor->id->value(), 4);
            $value = [
                'conversation_id' => $conversationId,
                'message' => $message,
                'quick_replies' => $this->quickReplies($conversationId, $actorType, $actor->id->value(), $recent),
                'turn_id' => null,
            ];
            if ($turn->status === 'uncertain') {
                $this->markIdempotencyUncertain(
                    $decision->record,
                    $turn->code,
                    ['conversation_id' => $conversationId, 'message_id' => $turn->focusUpdate['message_id']]
                );
                return $this->response($this->uncertainMessageEnvelope(
                    $turn->code,
                    $turn->visibleText,
                    $conversationId,
                    $idempotencyKey,
                    $correlation->value(),
                    $message
                ), 503);
            }
            if ($turn->status === 'partial') {
                $warnings = ['one_or_more_mutations_completed_before_response_failure'];
                $stored = [
                    '__veyra_envelope_status' => 'partial',
                    'code' => $turn->code,
                    'value' => $value,
                    'warnings' => $warnings,
                ];
                if (!$this->completeIdempotency($decision->record, $turn->code, $stored, false)) {
                    return $this->response($this->uncertainMessageEnvelope(
                        'chat_message_completion_uncertain',
                        'The response was stored, but idempotency completion requires reconciliation.',
                        $conversationId,
                        $idempotencyKey,
                        $correlation->value(),
                        $message
                    ), 503);
                }
                return $this->response(RestEnvelope::partial($turn->code, $value, $correlation->value(), $warnings));
            }
            if (!$this->completeIdempotency($decision->record, 'chat_message_completed', $value, true)) {
                return $this->response($this->uncertainMessageEnvelope(
                    'chat_message_completion_uncertain',
                    'The response was stored, but idempotency completion requires reconciliation.',
                    $conversationId,
                    $idempotencyKey,
                    $correlation->value(),
                    $message
                ), 503);
            }
            return $this->response(RestEnvelope::succeeded('chat_message_completed', $value, $correlation->value()));
        } catch (\Throwable) {
            $this->markIdempotencyUncertain(
                $decision->record,
                'chat_message_outcome_uncertain',
                ['conversation_id' => $conversationId]
            );
            return $this->response($this->uncertainMessageEnvelope(
                'chat_message_outcome_uncertain',
                'The message outcome is uncertain. Refresh the conversation before retrying.',
                $conversationId,
                $idempotencyKey,
                $correlation->value()
            ), 503);
        }
    }

    public function newConversation(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(true);
        $body = $request->get_json_params();
        if ($actor === null || !is_array($body) || array_keys($body) !== ['schema_version'] || ($body['schema_version'] ?? null) !== 'veyra.new_conversation_command.v1') {
            return $this->response(RestEnvelope::blocked('new_conversation_invalid', 'The new-conversation command is invalid.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        if (!$this->rateLimiter->consume($actor, 'chat.new_conversation', 5)) {
            return $this->response(RestEnvelope::blocked('new_conversation_rate_limited', 'Too many conversations were opened. Wait before trying again.', $correlation->value(), 'safe_no_side_effect'), 429);
        }
        $key = $request->get_header('Idempotency-Key');
        if (!is_string($key)) {
            return $this->response(RestEnvelope::blocked('idempotency_key_invalid', 'An idempotency key is required.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        try {
            $decision = $this->idempotency->begin($actor, 'chat.new_conversation', $key, $body, 'actor:' . $actor->key(), $correlation);
            if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
                return $this->idempotencyResponse($decision, $correlation->value(), $key);
            }
            $actorType = $this->contexts->actorType($actor);
            $conversationId = $this->conversations->createConversation($actorType, $actor->id->value(), $actor->wordpressUserId, $actor->guestSessionId?->value());
            $value = ['conversation_id' => $conversationId];
            if (!$this->completeIdempotency($decision->record, 'new_conversation_created', $value, true)) {
                return $this->response(RestEnvelope::uncertain('new_conversation_completion_uncertain', 'Reload before opening another conversation.', $correlation->value()), 503);
            }
            return $this->response(RestEnvelope::succeeded('new_conversation_created', $value, $correlation->value()));
        } catch (\Throwable) {
            if (isset($decision) && $decision->status === IdempotencyDecisionStatus::Claimed) {
                $this->markIdempotencyUncertain(
                    $decision->record,
                    'new_conversation_outcome_uncertain',
                    ['actor_key' => $actor->key()]
                );
            }
            return $this->response(RestEnvelope::uncertain('new_conversation_outcome_uncertain', 'Reload before opening another conversation.', $correlation->value()), 503);
        }
    }

    public function cancelTurn(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        return $this->response(RestEnvelope::blocked('turn_not_active', 'No asynchronous provider turn is active. No state changed.', $correlation->value(), 'safe_no_side_effect'), 409);
    }

    public function interaction(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        return $this->response(RestEnvelope::blocked('interaction_not_published', 'This candidate exposes no server-issued interaction action for execution.', $correlation->value(), 'safe_no_side_effect'), 409);
    }

    /** @param mixed $raw */
    private function resolveConversation(mixed $raw, string $actorType, string $actorId): ?array
    {
        return $this->validOpaqueId($raw)
            ? $this->conversations->getOwnedConversation((string) $raw, $actorType, $actorId)
            : null;
    }

    private function clientNetworkAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($address) ? $address : '';
    }

    /** @param mixed $body */
    private function validMessageCommand(mixed $body): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $allowed = ['schema_version', 'client_message_id', 'conversation_id', 'text', 'language', 'direction', 'reply_to_message_id', 'product_references', 'answer_binding'];
        if (array_diff(array_keys($body), $allowed)
            || ($body['schema_version'] ?? null) !== 'veyra.customer_message_command.v1'
            || !is_string($body['client_message_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $body['client_message_id']) !== 1
            || !$this->validOpaqueId($body['conversation_id'] ?? null)
            || !is_string($body['text'] ?? null)
            || trim($body['text']) === ''
            || strlen($body['text']) > 4000
            || !is_string($body['language'] ?? null)
            || strlen($body['language']) > 35
            || !in_array($body['direction'] ?? null, ['auto', 'ltr', 'rtl'], true)
        ) {
            return false;
        }
        foreach (['reply_to_message_id'] as $field) {
            if (isset($body[$field]) && (!is_string($body[$field]) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $body[$field]) !== 1)) {
                return false;
            }
        }
        $references = $body['product_references'] ?? [];
        if (!is_array($references) || !array_is_list($references) || count($references) > 3) {
            return false;
        }
        $seenReferences = [];
        foreach ($references as $reference) {
            $binding = ProductReferenceIdentity::commandBinding($reference);
            if ($binding === null || isset($seenReferences[$binding['reference_id']])) {
                return false;
            }
            $seenReferences[$binding['reference_id']] = true;
        }
        return !isset($body['answer_binding']) || $this->validAnswerBinding($body['answer_binding']);
    }

    private function validAnswerBinding(mixed $binding): bool
    {
        return is_array($binding)
            && array_diff(array_keys($binding), ['schema_version', 'choice_id', 'pending_question_id']) === []
            && array_diff(['schema_version', 'choice_id', 'pending_question_id'], array_keys($binding)) === []
            && ($binding['schema_version'] ?? null) === 'veyra.answer_binding.v1'
            && $this->validOpaqueId($binding['choice_id'] ?? null)
            && $this->validOpaqueId($binding['pending_question_id'] ?? null);
    }

    /** @param list<array<string, mixed>> $messages @return list<array<string, string>> */
    private function quickReplies(string $conversationId, string $actorType, string $actorId, array $messages): array
    {
        $focus = $this->conversations->focus($conversationId, $actorType, $actorId);
        $question = $focus?->pendingQuestion;
        if ($question === null || !$question->isActive(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) || $question->allowedChoiceIds === []) {
            return [];
        }
        $choices = [];
        foreach (array_reverse($messages) as $message) {
            if (($message['sender_type'] ?? null) !== 'ai' || !is_array($message['render']['components'] ?? null)) {
                continue;
            }
            foreach ($message['render']['components'] as $component) {
                if (is_array($component) && ($component['type'] ?? null) === 'choices' && is_array($component['choices'] ?? null)) {
                    $choices = $component['choices'];
                    break 2;
                }
            }
        }
        $result = [];
        foreach (array_slice($choices, 0, 8) as $choice) {
            $choiceId = is_array($choice) ? ($choice['choice_id'] ?? $choice['id'] ?? null) : $choice;
            $label = is_array($choice) ? ($choice['label'] ?? null) : $choice;
            $label = is_string($label) ? trim($label) : '';
            if (!$this->validOpaqueId($choiceId)
                || !in_array($choiceId, $question->allowedChoiceIds, true)
                || $label === ''
                || strlen($label) > 160
                || !$this->validOpaqueId($question->id)
            ) {
                continue;
            }
            $result[] = ['choice_id' => $choiceId, 'label' => $label, 'pending_question_id' => $question->id];
        }
        return $result;
    }

    private function idempotencyResponse(object $decision, string $correlationId, ?string $reconciliationHandle = null): \WP_REST_Response
    {
        if ($decision->status === IdempotencyDecisionStatus::Replay && is_array($decision->record->result)) {
            if ($decision->record->status === 'succeeded') {
                if (($decision->record->result['__veyra_envelope_status'] ?? null) === 'partial'
                    && is_array($decision->record->result['value'] ?? null)
                ) {
                    return $this->response(RestEnvelope::partial(
                        is_string($decision->record->result['code'] ?? null) ? $decision->record->result['code'] : 'idempotency_partial_replay',
                        $decision->record->result['value'],
                        $correlationId,
                        is_array($decision->record->result['warnings'] ?? null) ? $decision->record->result['warnings'] : []
                    ));
                }
                return $this->response(RestEnvelope::succeeded('idempotency_replay', $decision->record->result, $correlationId));
            }
            return $this->response(RestEnvelope::blocked((string) ($decision->record->resultCode ?? 'previous_attempt_failed'), 'The previous attempt did not succeed.', $correlationId, 'never_retry'), 409);
        }
        if ($decision->status === IdempotencyDecisionStatus::Conflict) {
            return $this->response(RestEnvelope::blocked('idempotency_payload_conflict', 'This idempotency key was already used for different input.', $correlationId, 'never_retry'), 409);
        }
        $conversationId = is_array($decision->record->result ?? null) && is_string($decision->record->result['conversation_id'] ?? null)
            ? $decision->record->result['conversation_id']
            : null;
        return $this->response(RestEnvelope::make(
            'uncertain',
            $decision->code,
            [
                'message' => 'Processing state must be reconciled before retrying.',
                'conversation_id' => $conversationId,
                'reconciliation_handle' => $reconciliationHandle,
            ],
            $correlationId,
            'reconcile_before_retry'
        ), 409);
    }

    /** @param array<string, mixed> $result */
    private function completeIdempotency(
        IdempotencyRecord $record,
        string $code,
        array $result,
        bool $retrySafe
    ): bool {
        try {
            return $this->idempotency->complete($record, $code, $result, $retrySafe);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $result */
    private function failIdempotency(
        IdempotencyRecord $record,
        string $code,
        array $result,
        bool $retrySafe
    ): bool {
        try {
            return $this->idempotency->fail($record, $code, $result, $retrySafe);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $known */
    private function markIdempotencyUncertain(
        IdempotencyRecord $record,
        string $code,
        array $known
    ): bool {
        try {
            return $this->idempotency->markUncertain($record, $code, $known);
        } catch (\Throwable) {
            return false;
        }
    }

    private function validOpaqueId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1;
    }

    /** @param array<string, mixed>|null $message */
    private function uncertainMessageEnvelope(
        string $code,
        string $visibleText,
        string $conversationId,
        string $reconciliationHandle,
        string $correlationId,
        ?array $message = null
    ): array {
        $value = [
            'message_text' => $visibleText,
            'conversation_id' => $conversationId,
            'reconciliation_handle' => $reconciliationHandle,
        ];
        if ($message !== null) {
            $value['message'] = $message;
        }
        return RestEnvelope::make('uncertain', $code, $value, $correlationId, 'reconcile_before_retry');
    }

    /** @param array<string, mixed> $envelope */
    private function response(array $envelope, int $status = 200): \WP_REST_Response
    {
        $response = new \WP_REST_Response($envelope, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
