<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

use Veyra\Media\Domain\Attachment;

final class UploadOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?Attachment $attachment,
        public readonly bool $retrySafe
    ) {
        if (!in_array($status, ['succeeded', 'failed', 'blocked', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Upload outcome status is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Upload outcome code is invalid.');
        }
    }
}
