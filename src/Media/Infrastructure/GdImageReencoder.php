<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

// This private temporary-file adapter enforces exact path permissions and cleanup.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod

use Veyra\Media\Application\ImageReencoder;

final class GdImageReencoder implements ImageReencoder
{
    public function __construct(private readonly string $temporaryDirectory)
    {
    }

    public function reencode(string $sourcePath, string $mimeType): string
    {
        if (!is_dir($this->temporaryDirectory)
            || !is_writable($this->temporaryDirectory)
            || !function_exists('imagecreatetruecolor')
        ) {
            throw new \RuntimeException('image_reencode_unavailable');
        }
        $decoder = match ($mimeType) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        $encoderAvailable = match ($mimeType) {
            'image/jpeg' => function_exists('imagejpeg'),
            'image/png' => function_exists('imagepng'),
            'image/webp' => function_exists('imagewebp'),
            default => false,
        };
        if ($decoder === null || !function_exists($decoder) || !$encoderAvailable) {
            throw new \RuntimeException('image_reencode_unavailable');
        }
        $source = @$decoder($sourcePath);
        if (!$source instanceof \GdImage) {
            throw new \RuntimeException('image_decode_failed');
        }

        $targetPath = tempnam($this->temporaryDirectory, 'veyra-img-');
        if (!is_string($targetPath)) {
            imagedestroy($source);
            throw new \RuntimeException('image_reencode_temporary_file_failed');
        }
        $ok = false;
        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $canvas = imagecreatetruecolor($width, $height);
            if (!$canvas instanceof \GdImage) {
                throw new \RuntimeException('image_reencode_failed');
            }
            try {
                if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
                }
                if (!imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height)) {
                    throw new \RuntimeException('image_reencode_failed');
                }
                $ok = match ($mimeType) {
                    'image/jpeg' => imagejpeg($canvas, $targetPath, 90),
                    'image/png' => imagepng($canvas, $targetPath, 6),
                    'image/webp' => imagewebp($canvas, $targetPath, 90),
                    default => false,
                };
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }
        if (!$ok) {
            @unlink($targetPath);
            throw new \RuntimeException('image_reencode_failed');
        }
        @chmod($targetPath, 0600);

        return $targetPath;
    }
}
