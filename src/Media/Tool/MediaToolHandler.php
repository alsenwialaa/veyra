<?php

declare(strict_types=1);

namespace Veyra\Media\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Clock\SystemClock;
use Veyra\Media\Application\AttachmentRepository;
use Veyra\Shared\Domain\Clock;

/**
 * Model-visible media access is limited to safe actor-owned metadata. Raw
 * bytes require the separate protected-access service and an authenticated
 * controller; no URL or filesystem key is ever returned here.
 */
final class MediaToolHandler implements ToolHandler
{
    private readonly Clock $clock;

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly FoundationActorMapper $actors,
        ?Clock $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function definitions(): array
    {
        $input = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['attachment_id'],
            'properties' => [
                'attachment_id' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36],
            ],
        ];
        $actors = ['guest', 'customer'];
        $features = ['ai_multimodal_understanding'];

        return [
            new ToolDefinition(
                'media.validate_upload',
                '1.0.0',
                'Read validation and malware-scan state for one exact actor-owned protected attachment.',
                'read',
                $input,
                $actors,
                [],
                $features,
                true
            ),
            new ToolDefinition(
                'media.get_protected_attachment',
                '1.0.0',
                'Resolve safe metadata for one exact actor-owned protected attachment; raw access is never model-visible.',
                'read',
                $input,
                $actors,
                [],
                $features,
                false
            ),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if (!in_array($call->name, ['media.validate_upload', 'media.get_protected_attachment'], true)) {
            return ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false);
        }
        $attachment = $this->attachments->find(
            ActorScope::fromActor($this->actors->map($context)),
            (string) $call->arguments['attachment_id']
        );
        if ($attachment === null || !hash_equals($context->conversationId, $attachment->conversationId)) {
            return ToolResult::failed($call, 'attachment_not_owned_or_unavailable', $context->correlationId, false);
        }

        return ToolResult::success(
            $call,
            ['attachment' => $attachment->safeMetadata($this->clock->now())],
            $context->correlationId
        );
    }
}
