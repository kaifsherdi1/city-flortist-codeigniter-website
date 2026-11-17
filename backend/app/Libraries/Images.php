<?php

namespace App\Libraries;

use GdImage;
use RuntimeException;

/** Turns uploaded photos into a large WebP and a thumbnail under public/uploads. */
class Images
{
    public const IMAGE_MAX = 1600;

    public const THUMB_MAX = 640;

    /** Largest photo accepted, in pixels (a 48 MP phone photo fits). Bigger ones would exhaust memory. */
    public const MAX_PIXELS = 50_000_000;

    public static function load(string $file): GdImage
    {
        $info = @getimagesize($file);
        if (! $info) {
            throw new RuntimeException('Not a valid image file.');
        }
        // Checked from the file header, before decoding: a small file can still claim a huge size.
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException(sprintf('This photo is too big (%d × %d pixels). Please use one under 50 megapixels.', $info[0], $info[1]));
        }
        // Decoding needs about 5 bytes per pixel; make room for large phone photos where the host allows it.
        $needed = (int) ($info[0] * $info[1] * 5 + 64 * 1024 * 1024);
        $limit  = (string) ini_get('memory_limit');
        if ($limit !== '-1' && Text::iniBytes($limit) < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }
        // No "@" here, as in the Laravel version: a photo that is damaged inside reports GD's own message
        // (the error handler turns the warning into an exception), shown to the owner as the upload warning.
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file),
            IMAGETYPE_PNG  => imagecreatefrompng($file),
            IMAGETYPE_WEBP => imagecreatefromwebp($file),
            IMAGETYPE_GIF  => imagecreatefromgif($file),
            default        => throw new RuntimeException('Please upload a JPG, PNG or WebP image.'),
        };
        if (! $img) {
            throw new RuntimeException('This image could not be read.');
        }
        // Phone photos are often stored sideways with an EXIF rotation flag.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif   = @exif_read_data($file);
            $rotate = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rotate) {
                $img = imagerotate($img, $rotate, 0);
            }
        }

        return $img;
    }

    public static function resize(GdImage $src, int $max): GdImage
    {
        $w     = imagesx($src);
        $h     = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $dst   = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        return $dst;
    }

    /**
     * Saves a source image as a large WebP plus a thumbnail. Every photo is decoded and re-saved under a random
     * name, so a disguised file can't run.
     * Returns paths relative to public/, e.g. ['uploads/products/ab12.webp', 'uploads/products/ab12-thumb.webp'].
     */
    public static function store(string $sourceFile, string $subdir = 'products', ?string $baseName = null): array
    {
        $dirRel = 'uploads/' . trim($subdir, '/');
        $dirAbs = Text::publicFile($dirRel);
        if (! is_dir($dirAbs) && ! mkdir($dirAbs, 0755, true) && ! is_dir($dirAbs)) {
            throw new RuntimeException('Cannot create the upload folder.');
        }
        $name = $baseName ?: date('Ymd') . '-' . bin2hex(random_bytes(5));

        $src       = self::load($sourceFile);
        $path      = "{$dirRel}/{$name}.webp";
        $thumbPath = "{$dirRel}/{$name}-thumb.webp";
        imagewebp(self::resize($src, self::IMAGE_MAX), Text::publicFile($path), 82);
        imagewebp(self::resize($src, self::THUMB_MAX), Text::publicFile($thumbPath), 78);

        return [$path, $thumbPath];
    }

    /** Deletes files under public/uploads. Anything else is ignored. */
    public static function delete(?string ...$paths): void
    {
        foreach ($paths as $p) {
            if ($p && str_starts_with($p, 'uploads/') && ! str_contains($p, '..') && is_file(Text::publicFile($p))) {
                @unlink(Text::publicFile($p));
            }
        }
    }
}
