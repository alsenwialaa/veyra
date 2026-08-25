<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

// This bounded signature validator needs random-access stream reads; WP_Filesystem has no equivalent API.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

use Veyra\Media\Application\FileValidator;
use Veyra\Media\Domain\ValidatedFile;

/**
 * Bounded structural validator. It intentionally accepts only the evidence
 * formats this slice can inspect safely; additional modalities remain blocked.
 */
final class MagicByteFileValidator implements FileValidator
{
    /** @param array<string, int> $maximumBytesByMime */
    public function __construct(
        private readonly array $maximumBytesByMime = [
            'image/jpeg' => 8388608,
            'image/png' => 8388608,
            'image/webp' => 8388608,
            'application/pdf' => 10485760,
        ],
        private readonly int $maximumImageWidth = 10000,
        private readonly int $maximumImageHeight = 10000,
        private readonly int $maximumImagePixels = 25000000
    ) {
    }

    public function validate(string $path, string $claimedMimeType): ValidatedFile
    {
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            throw new \RuntimeException('upload_file_unreadable');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1) {
            throw new \RuntimeException('upload_file_empty');
        }
        if (!class_exists(\finfo::class)) {
            throw new \RuntimeException('upload_mime_inspector_unavailable');
        }
        $inspector = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $inspector->file($path);
        if (!is_string($detected) || !isset($this->maximumBytesByMime[$detected])) {
            throw new \RuntimeException('upload_mime_not_allowed');
        }
        $claim = strtolower(trim(explode(';', $claimedMimeType, 2)[0]));
        if ($claim === '' || !hash_equals($detected, $claim)) {
            throw new \RuntimeException('upload_mime_mismatch');
        }
        if ($size > $this->maximumBytesByMime[$detected]) {
            throw new \RuntimeException('upload_file_too_large');
        }

        $dimensions = [];
        if (str_starts_with($detected, 'image/')) {
            $dimensions = $this->validateImage($path, $detected);
        } else {
            $this->validatePdf($path);
        }

        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum)) {
            throw new \RuntimeException('upload_checksum_failed');
        }

        return new ValidatedFile($path, $detected, $size, $checksum, $dimensions);
    }

    /** @return array{width:int,height:int} */
    private function validateImage(string $path, string $detectedMime): array
    {
        $image = @getimagesize($path);
        if (!is_array($image) || !isset($image[0], $image[1], $image['mime'])) {
            throw new \RuntimeException('upload_image_structure_invalid');
        }
        $width = (int) $image[0];
        $height = (int) $image[1];
        if (!is_string($image['mime']) || !hash_equals($detectedMime, $image['mime'])) {
            throw new \RuntimeException('upload_image_mime_mismatch');
        }
        if ($width < 1 || $height < 1
            || $width > $this->maximumImageWidth
            || $height > $this->maximumImageHeight
            || $width * $height > $this->maximumImagePixels
        ) {
            throw new \RuntimeException('upload_image_dimensions_exceeded');
        }

        return ['width' => $width, 'height' => $height];
    }

    private function validatePdf(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('upload_pdf_unreadable');
        }
        try {
            $head = fread($handle, 8);
            if (!is_string($head) || !str_starts_with($head, '%PDF-')) {
                throw new \RuntimeException('upload_pdf_signature_invalid');
            }
            $size = filesize($path);
            if (!is_int($size) || fseek($handle, max(0, $size - 8192)) !== 0) {
                throw new \RuntimeException('upload_pdf_structure_invalid');
            }
            $tail = stream_get_contents($handle);
            if (!is_string($tail) || !str_contains($tail, '%%EOF')) {
                throw new \RuntimeException('upload_pdf_structure_invalid');
            }
        } finally {
            fclose($handle);
        }

        // A complete parser and malware scanner remain authoritative. These
        // constructs are rejected early because this slice never needs them.
        $sample = file_get_contents($path, false, null, 0, min((int) filesize($path), 10485760));
        if (!is_string($sample)) {
            throw new \RuntimeException('upload_pdf_unreadable');
        }
        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/RichMedia', '/XFA'] as $token) {
            if (stripos($sample, $token) !== false) {
                throw new \RuntimeException('upload_pdf_active_content_rejected');
            }
        }
    }
}
