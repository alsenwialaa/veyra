<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;

interface AttachmentRepository
{
    public function insert(Attachment $attachment): bool;

    public function save(Attachment $attachment, int $expectedVersion): bool;

    public function find(ActorScope $actor, string $attachmentId): ?Attachment;

    /** @param list<string> $attachmentIds @return list<Attachment> */
    public function findMany(ActorScope $actor, array $attachmentIds): array;

    /** @return array{count:int,bytes:int}|null Null means quota state is unavailable. */
    public function activeUsage(ActorScope $actor): ?array;
}
