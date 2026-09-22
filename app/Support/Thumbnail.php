<?php

namespace App\Support;

use GdImage;

class Thumbnail
{
    /**
     * Write a WebP thumbnail of an image file using GD.
     *
     * Returns false when the source cannot (or need not) be thumbnailed —
     * callers then fall back to serving the original file.
     */
    public static function make(string $src, string $dest, int $max = 480, bool $force = false): bool
    {
        $info = @getimagesize($src);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return false;
        }
        [$w, $h] = $info;

        // Decoding needs roughly 5 bytes per pixel; skip instead of hitting
        // a fatal OOM that would fail the whole upload request.
        $limit = self::memoryLimitBytes();
        if ($limit > 0 && $w * $h * 5 > $limit - memory_get_usage(true) - 16_000_000) {
            return false;
        }

        // Already small: the original is its own thumbnail.
        if (! $force && max($w, $h) <= $max && (int) @filesize($src) < 150_000) {
            return false;
        }

        $create = match ($info[2]) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_AVIF => 'imagecreatefromavif',
            default => null,
        };
        if (! $create || ! function_exists($create)) {
            return false;
        }
        $img = @$create($src);
        if (! $img instanceof GdImage) {
            return false;
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $img = self::applyExifOrientation($img, $src);
        }
        $w = imagesx($img);
        $h = imagesy($img);

        $scale = min(1, $max / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $thumb = imagecreatetruecolor($tw, $th);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);

        if (! is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0755, true);
        }
        $ok = @imagewebp($thumb, $dest, 78);
        imagedestroy($thumb);

        return (bool) $ok;
    }

    /** Rotate JPEGs shot on phones according to their EXIF orientation. */
    private static function applyExifOrientation(GdImage $img, string $src): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $orientation = (int) (@exif_read_data($src)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof GdImage) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    private static function memoryLimitBytes(): int
    {
        $ini = ini_get('memory_limit') ?: '128M';
        if (function_exists('ini_parse_quantity')) {
            return (int) ini_parse_quantity($ini);
        }

        return (int) $ini * match (strtoupper(substr($ini, -1))) {
            'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1,
        };
    }
}
