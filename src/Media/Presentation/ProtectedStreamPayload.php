<?php

declare(strict_types=1);

namespace Veyra\Media\Presentation;

/** Internal REST transport payload. It is intercepted before JSON serialization. */
final class ProtectedStreamPayload
{
    /** @param resource $stream */
    public function __construct(public readonly mixed $stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Protected response stream is invalid.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            // WP_Filesystem cannot close an already-authorized response stream.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->stream);
        }
    }
}
