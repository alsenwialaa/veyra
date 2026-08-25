<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

use Veyra\Media\Domain\ValidatedFile;

interface FileValidator
{
    public function validate(string $path, string $claimedMimeType): ValidatedFile;
}
