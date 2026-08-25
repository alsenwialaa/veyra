<?php
declare(strict_types=1);

namespace Veyra\Context\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Conversation\Application\ConversationStore;

final class ContextToolHandler implements ToolHandler
{
    public function __construct(private readonly ConversationStore $conversations)
    {
    }

    public function definitions(): array
    {
        $actors = ['guest', 'customer', 'support', 'reviewer', 'manager', 'administrator'];
        $empty = ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
        return [
            $this->read('identity.get_current_actor', 'Read the already server-resolved current actor without accepting an actor ID.', $empty, $actors, []),
            $this->read(
                'identity.get_customer_profile',
                'Server-only authenticated customer profile projection; never lookup another customer.',
                $empty,
                ['customer'],
                [],
                false
            ),
            $this->read('identity.get_capabilities', 'Read the current actor effective Veyra capabilities.', $empty, $actors, []),
            $this->read('context.get_store_profile', 'Read bounded public store identity, locale and currency.', $empty, $actors, []),
            $this->read(
                'context.get_runtime_clock',
                'Read authoritative UTC and configured store time.',
                $empty,
                ['guest', 'customer'],
                ['ai_time_awareness'],
                true,
                $this->runtimeClockOutputSchema()
            ),
            $this->read('context.get_page_context', 'Read the server-approved current surface context.', $empty, $actors, []),
            $this->read('context.get_conversation_focus', 'Read the actor-owned foreground journey and pending question.', $empty, $actors, ['ai_conversation_focus']),
            $this->read('memory.get_conversation_state', 'Read validated actor-owned conversation memory and summary.', $empty, $actors, ['ai_conversation_memory']),
            $this->read('memory.list_active_journeys', 'List actor-owned active and paused journeys.', $empty, $actors, ['ai_conversation_memory']),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        $data = match ($call->name) {
            'identity.get_current_actor' => [
                'actor_type' => $context->actorType,
                'authenticated' => $context->userId !== null,
                'actor_ref' => $context->actorId,
            ],
            'identity.get_customer_profile' => $this->customerProfile($context),
            'identity.get_capabilities' => ['capabilities' => $context->capabilities],
            'context.get_store_profile' => $this->storeProfile(),
            'context.get_runtime_clock' => $this->runtimeClock(),
            'context.get_page_context' => ['surface' => 'chat', 'conversation_id' => $context->conversationId],
            'context.get_conversation_focus' => ['focus' => $this->conversations->focus($context->conversationId, $context->actorType, $context->actorId)?->toArray()],
            'memory.get_conversation_state' => [
                'memory' => $this->conversations->memory($context->conversationId, $context->actorType, $context->actorId),
                'summary' => $this->conversations->summary($context->conversationId, $context->actorType, $context->actorId),
            ],
            'memory.list_active_journeys' => ['journeys' => array_map(
                static fn ($journey): array => $journey->toArray(),
                $this->conversations->journeys($context->conversationId, $context->actorType, $context->actorId)
            )],
            default => null,
        };
        if (!is_array($data)) {
            return ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false);
        }
        return ToolResult::success($call, $data, $context->correlationId);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, string> $actors
     * @param array<int, string> $features
     * @param array<string, mixed> $outputSchema
     */
    private function read(
        string $name,
        string $description,
        array $schema,
        array $actors,
        array $features,
        bool $modelVisible = true,
        array $outputSchema = []
    ): ToolDefinition
    {
        return new ToolDefinition(
            $name,
            '1.0.0',
            $description,
            'read',
            $schema,
            $actors,
            [],
            $features,
            $modelVisible,
            $outputSchema
        );
    }

    /** @return array<string, mixed> */
    private function runtimeClockOutputSchema(): array
    {
        $dateTime = [
            'type' => 'string',
            'minLength' => 25,
            'maxLength' => 25,
            'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}[+-]\\d{2}:\\d{2}$',
        ];
        $utcDateTime = $dateTime;
        $utcDateTime['pattern'] = '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$';

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['utc', 'timezone', 'local', 'authoritative'],
            'properties' => [
                'utc' => $utcDateTime,
                'timezone' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'local' => $dateTime,
                'authoritative' => ['type' => 'boolean', 'const' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function customerProfile(ToolContext $context): array
    {
        if ($context->userId === null || !function_exists('get_userdata')) {
            return ['available' => false];
        }
        $user = get_userdata($context->userId);
        if (!$user instanceof \WP_User) {
            return ['available' => false];
        }
        return [
            'available' => true,
            'display_name' => (string) $user->display_name,
            'locale' => function_exists('get_user_locale') ? get_user_locale($context->userId) : $context->locale,
            'billing' => [
                'first_name' => (string) get_user_meta($context->userId, 'billing_first_name', true),
                'last_name' => (string) get_user_meta($context->userId, 'billing_last_name', true),
                'phone' => (string) get_user_meta($context->userId, 'billing_phone', true),
                'email' => (string) get_user_meta($context->userId, 'billing_email', true),
            ],
            'shipping' => [
                'first_name' => (string) get_user_meta($context->userId, 'shipping_first_name', true),
                'last_name' => (string) get_user_meta($context->userId, 'shipping_last_name', true),
                'address_1' => (string) get_user_meta($context->userId, 'shipping_address_1', true),
                'address_2' => (string) get_user_meta($context->userId, 'shipping_address_2', true),
                'city' => (string) get_user_meta($context->userId, 'shipping_city', true),
                'state' => (string) get_user_meta($context->userId, 'shipping_state', true),
                'postcode' => (string) get_user_meta($context->userId, 'shipping_postcode', true),
                'country' => (string) get_user_meta($context->userId, 'shipping_country', true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function storeProfile(): array
    {
        return [
            'name' => function_exists('get_bloginfo') ? get_bloginfo('name') : '',
            'description' => function_exists('get_bloginfo') ? get_bloginfo('description') : '',
            'locale' => function_exists('get_locale') ? get_locale() : 'en_US',
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function runtimeClock(): array
    {
        $utc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        return [
            'utc' => $utc->format(DATE_ATOM),
            'timezone' => $timezone->getName(),
            'local' => $utc->setTimezone($timezone)->format(DATE_ATOM),
            'authoritative' => true,
        ];
    }
}
