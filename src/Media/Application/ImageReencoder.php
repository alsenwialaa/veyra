<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

interface ImageReencoder
{
    /** Returns a new temporary path whose caller owns deletion. */
    public function reencode(string $sourcePath, string $mimeType): string;
}
