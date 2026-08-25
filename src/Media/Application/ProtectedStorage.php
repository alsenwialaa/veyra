<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

use Veyra\Media\Domain\StoredObject;

interface ProtectedStorage
{
    public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject;

    /** @return resource */
    public function open(string $key);

    public function delete(string $key): bool;
}
