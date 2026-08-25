<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Tool\ToolContext;
use Veyra\Experience\Contract\ProductReferenceIdentity;

final class AgentTurnInput
{
    /** @param list<array<string, mixed>> $productReferences @param array<int, string> $attachmentIds @param array<string, mixed>|null $answerBinding */
    public function __construct(
        public readonly ToolContext $context,
        public readonly string $text,
        public readonly ?string $replyToMessageId,
        public readonly array $productReferences,
        public readonly array $attachmentIds,
        public readonly ?array $location,
        public readonly ?array $answerBinding = null
    ) {
        if (trim($text) === '' && $attachmentIds === [] && $location === null) {
            throw new \InvalidArgumentException('A turn must contain an enabled modality.');
        }
        if (strlen($text) > 12000 || count($productReferences) > 3 || count($attachmentIds) > 5) {
            throw new \InvalidArgumentException('Turn payload exceeds bounds.');
        }
        if (!array_is_list($productReferences)) {
            throw new \InvalidArgumentException('Product-reference bindings are invalid.');
        }
        $seenReferences = [];
        foreach ($productReferences as $binding) {
            $binding = ProductReferenceIdentity::commandBinding($binding);
            if ($binding === null || isset($seenReferences[$binding['reference_id']])) {
                throw new \InvalidArgumentException('Product-reference bindings are invalid.');
            }
            $seenReferences[$binding['reference_id']] = true;
        }
        if ($answerBinding !== null
            && (array_diff(array_keys($answerBinding), ['schema_version', 'choice_id', 'pending_question_id']) !== []
                || array_diff(['schema_version', 'choice_id', 'pending_question_id'], array_keys($answerBinding)) !== []
                || ($answerBinding['schema_version'] ?? null) !== 'veyra.answer_binding.v1'
                || !is_string($answerBinding['choice_id'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $answerBinding['choice_id']) !== 1
                || !is_string($answerBinding['pending_question_id'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $answerBinding['pending_question_id']) !== 1)
        ) {
            throw new \InvalidArgumentException('Answer binding is invalid.');
        }
    }
}
