<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\Content\DinkyGallery\Helper;

\defined('_JEXEC') or die;

/**
 * Generates and caches width-scaled copies of an image next to the original, in a
 * subfolder of the gallery folder (default ".thumbs"), using GD only.
 *
 * A derivative's file name carries an 8-char hash of the source's mtime + size + the
 * quality setting, so replacing an image under the same name yields a new hash and the
 * copy is regenerated on the next render. prune() removes derivatives whose name is no
 * longer current (stale hash, dropped width, deleted source).
 *
 * No cropping: every copy keeps the source aspect ratio, so the intrinsic width/height
 * stays valid. A format GD cannot read or write (typically AVIF) is skipped and the
 * caller falls back to the original.
 *
 * @since  1.5.0
 */
final class Thumbnailer
{
    /**
     * Cache subfolder name, a single path segment (e.g. ".thumbs").
     *
     * @var    string
     * @since  1.5.0
     */
    private string $dirName;

    /**
     * Encoder quality for JPEG / WebP (1-100); mapped to 0-9 for PNG.
     *
     * @var    integer
     * @since  1.5.0
     */
    private int $quality;

    /**
     * Cumulative counters for the debug log.
     *
     * @var    array{made:int, reused:int, pruned:int, skipped:int}
     * @since  1.5.0
     */
    private array $stats = ['made' => 0, 'reused' => 0, 'pruned' => 0, 'skipped' => 0];

    /**
     * Matches a derivative file name this class produces:
     * "<base>.<8-hex>.<w>x<h>.<ext>" or "<base>.<8-hex>.large-<w>x<h>.<ext>".
     *
     * @var    string
     * @since  1.5.0
     */
    private const NAME_RE = '/\.[0-9a-f]{8}\.(?:large-)?\d+x\d+\.[a-z0-9]+$/i';

    /**
     * @param   string   $dirName  Cache subfolder name (already sanitised to one segment).
     * @param   integer  $quality  Encoder quality, 1-100.
     *
     * @since   1.5.0
     */
    public function __construct(string $dirName, int $quality)
    {
        $this->dirName = $dirName;
        $this->quality = min(100, max(1, $quality));
    }

    /**
     * The cache subfolder name.
     *
     * @return  string
     *
     * @since   1.5.0
     */
    public function dirName(): string
    {
        return $this->dirName;
    }

    /**
     * Cumulative made / reused / pruned / skipped counters.
     *
     * @return  array{made:int, reused:int, pruned:int, skipped:int}
     *
     * @since   1.5.0
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Ensures the requested derivatives of $srcAbs exist, creating any that are missing.
     *
     * @param   string      $srcAbs    Absolute path of the source image.
     * @param   list<int>   $widths    Target widths in pixels (ascending, de-duplicated).
     * @param   integer     $largeMax  Long-edge cap for the extra "large" derivative; 0 = none.
     *
     * @return  array{variants: list<array{w:int, h:int, name:string}>, large: array{w:int, h:int, name:string}|null}
     *
     * @since   1.5.0
     */
    public function ensure(string $srcAbs, array $widths, int $largeMax): array
    {
        $empty = ['variants' => [], 'large' => null];

        $info = @getimagesize($srcAbs);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            return $empty;
        }

        [$sw, $sh] = [(int) $info[0], (int) $info[1]];
        $type      = $info[2] ?? 0;
        $ext       = self::extForType($type);

        if ($ext === null || !$this->canProcess($type)) {
            return $empty;
        }

        $mtime = @filemtime($srcAbs) ?: 0;
        $bytes = @filesize($srcAbs) ?: 0;
        $key   = substr(sha1($mtime . '|' . $bytes . '|' . $this->quality), 0, 8);

        $base    = pathinfo($srcAbs, \PATHINFO_FILENAME);
        $dirAbs  = \dirname($srcAbs) . \DIRECTORY_SEPARATOR . $this->dirName;

        $variants = [];

        foreach ($widths as $tw) {
            $tw = (int) $tw;

            // Never upscale: a width at or above the source is served by the original.
            if ($tw < 1 || $tw >= $sw) {
                continue;
            }

            $th   = max(1, (int) round($sh * $tw / $sw));
            $name = $base . '.' . $key . '.' . $tw . 'x' . $th . '.' . $ext;

            if ($this->realise($srcAbs, $type, $dirAbs . \DIRECTORY_SEPARATOR . $name, $tw, $th)) {
                $variants[] = ['w' => $tw, 'h' => $th, 'name' => $name];
            }
        }

        $large = null;

        if ($largeMax > 0 && max($sw, $sh) > $largeMax) {
            $ratio = $largeMax / max($sw, $sh);
            $lw    = max(1, (int) round($sw * $ratio));
            $lh    = max(1, (int) round($sh * $ratio));
            $name  = $base . '.' . $key . '.large-' . $lw . 'x' . $lh . '.' . $ext;

            if ($this->realise($srcAbs, $type, $dirAbs . \DIRECTORY_SEPARATOR . $name, $lw, $lh)) {
                $large = ['w' => $lw, 'h' => $lh, 'name' => $name];
            }
        }

        return ['variants' => $variants, 'large' => $large];
    }

    /**
     * Deletes derivative files in a cache folder whose name is not in $keep. Only files
     * that match this class's naming pattern are ever touched; anything else in the
     * folder is left alone. An empty folder afterwards is removed.
     *
     * @param   string         $dirAbs  Absolute path of the cache subfolder.
     * @param   array<string>   $keep    Current derivative file names to preserve.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    public function prune(string $dirAbs, array $keep): void
    {
        if (!is_dir($dirAbs)) {
            return;
        }

        $keep    = array_flip($keep);
        $entries = @scandir($dirAbs) ?: [];
        $left    = 0;

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (!preg_match(self::NAME_RE, $name)) {
                $left++;

                continue;
            }

            if (isset($keep[$name])) {
                $left++;

                continue;
            }

            if (@unlink($dirAbs . \DIRECTORY_SEPARATOR . $name)) {
                $this->stats['pruned']++;
            } else {
                $left++;
            }
        }

        if ($left === 0) {
            @rmdir($dirAbs);
        }
    }

    /**
     * Creates one derivative if it is not already on disk. Returns true when the file
     * exists afterwards (freshly written or reused), false on any failure.
     *
     * @param   string   $srcAbs  Source image path.
     * @param   integer  $type    IMAGETYPE_* of the source.
     * @param   string   $target  Absolute path of the derivative to write.
     * @param   integer  $w       Target width.
     * @param   integer  $h       Target height.
     *
     * @return  boolean
     *
     * @since   1.5.0
     */
    private function realise(string $srcAbs, int $type, string $target, int $w, int $h): bool
    {
        if (is_file($target)) {
            $this->stats['reused']++;

            return true;
        }

        $dir = \dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->stats['skipped']++;

            return false;
        }

        $src = $this->readImage($srcAbs, $type);

        if (!$src) {
            $this->stats['skipped']++;

            return false;
        }

        $dst = imagecreatetruecolor($w, $h);

        if (!$dst) {
            imagedestroy($src);
            $this->stats['skipped']++;

            return false;
        }

        // Preserve transparency for the formats that carry it.
        if (\in_array($type, [\IMAGETYPE_PNG, \IMAGETYPE_GIF, \IMAGETYPE_WEBP], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);

        $tmp = $target . '.' . getmypid() . '.tmp';
        $ok  = $this->writeImage($dst, $type, $tmp);
        imagedestroy($dst);

        if (!$ok) {
            @unlink($tmp);
            $this->stats['skipped']++;

            return false;
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $this->stats['skipped']++;

            return false;
        }

        $this->stats['made']++;

        return true;
    }

    /**
     * GD reader for a source type. Returns a GdImage or false.
     *
     * @param   string   $path  Source path.
     * @param   integer  $type  IMAGETYPE_* constant.
     *
     * @return  \GdImage|false
     *
     * @since   1.5.0
     */
    private function readImage(string $path, int $type)
    {
        return match ($type) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            \IMAGETYPE_PNG  => @imagecreatefrompng($path),
            \IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            \IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default         => false,
        };
    }

    /**
     * GD writer matching the source type. Returns true on success.
     *
     * @param   \GdImage  $img   The resized image.
     * @param   integer   $type  IMAGETYPE_* of the source.
     * @param   string    $path  Destination path.
     *
     * @return  boolean
     *
     * @since   1.5.0
     */
    private function writeImage(\GdImage $img, int $type, string $path): bool
    {
        return match ($type) {
            \IMAGETYPE_JPEG => @imagejpeg($img, $path, $this->quality),
            \IMAGETYPE_WEBP => @imagewebp($img, $path, $this->quality),
            \IMAGETYPE_PNG  => @imagepng($img, $path, (int) round(9 - ($this->quality / 100) * 9)),
            \IMAGETYPE_GIF  => @imagegif($img, $path),
            default         => false,
        };
    }

    /**
     * Whether GD on this server can both read and write the given type.
     *
     * @param   integer  $type  IMAGETYPE_* constant.
     *
     * @return  boolean
     *
     * @since   1.5.0
     */
    private function canProcess(int $type): bool
    {
        return match ($type) {
            \IMAGETYPE_JPEG => \function_exists('imagecreatefromjpeg') && \function_exists('imagejpeg'),
            \IMAGETYPE_PNG  => \function_exists('imagecreatefrompng') && \function_exists('imagepng'),
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') && \function_exists('imagewebp'),
            \IMAGETYPE_GIF  => \function_exists('imagecreatefromgif') && \function_exists('imagegif'),
            default         => false,
        };
    }

    /**
     * File extension to use for a derivative of the given source type.
     *
     * @param   integer  $type  IMAGETYPE_* constant.
     *
     * @return  string|null
     *
     * @since   1.5.0
     */
    private static function extForType(int $type): ?string
    {
        return match ($type) {
            \IMAGETYPE_JPEG => 'jpg',
            \IMAGETYPE_PNG  => 'png',
            \IMAGETYPE_WEBP => 'webp',
            \IMAGETYPE_GIF  => 'gif',
            default         => null,
        };
    }
}
