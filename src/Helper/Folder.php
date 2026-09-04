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

use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resolves a gallery folder name to a validated absolute path and lists the images
 * inside it.
 *
 * The resolved real path must stay inside JPATH_ROOT/<base_directory>; anything with
 * "..", a leading slash, a non-existent target or a path that escapes the base is
 * rejected (resolve() returns null and getError() explains why). getimagesize() is
 * cached per request across every gallery on the page.
 *
 * When a Thumbnailer is supplied, each listed image also carries a "src" (a mid-size
 * copy for the no-srcset fallback), a "srcset" string and a "full" URL (a size-capped
 * copy for the lightbox); the cache subfolder is pruned of stale derivatives after a
 * full-folder listing.
 *
 * @since  1.0.0
 */
final class Folder
{
    /**
     * The site-root-relative base directory, normalised (forward slashes, no edges).
     *
     * @var    string
     * @since  1.0.0
     */
    private string $base;

    /**
     * Lower-case image extensions without the leading dot.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private array $extensions;

    /**
     * Thumbnail generator, or null when thumbnailing is off / GD is unavailable.
     *
     * @var    Thumbnailer|null
     * @since  1.5.0
     */
    private ?Thumbnailer $thumbs;

    /**
     * Target widths for the card srcset, ascending.
     *
     * @var    list<int>
     * @since  1.5.0
     */
    private array $thumbWidths;

    /**
     * Long-edge cap for the lightbox "large" derivative; 0 = use the original.
     *
     * @var    integer
     * @since  1.5.0
     */
    private int $thumbLarge;

    /**
     * Whether to delete stale derivatives from a folder's cache after listing it.
     *
     * @var    boolean
     * @since  1.5.0
     */
    private bool $thumbPrune;

    /**
     * Why the last resolve() call returned null.
     *
     * @var    string
     * @since  1.0.0
     */
    private string $error = '';

    /**
     * Per-request getimagesize() cache, keyed by absolute path.
     *
     * @var    array<string, array{0:int,1:int}|null>
     * @since  1.0.0
     */
    private array $sizeCache = [];

    /**
     * @param   string            $baseDirectory  The base_directory parameter value.
     * @param   string[]          $extensions     Allowed image extensions (lower-case, no dot).
     * @param   Thumbnailer|null  $thumbs         Derivative generator, or null to serve originals.
     * @param   list<int>         $thumbWidths    Card srcset widths in pixels.
     * @param   integer           $thumbLarge     Lightbox long-edge cap; 0 = original.
     * @param   boolean           $thumbPrune     Delete stale derivatives after a folder listing.
     *
     * @since   1.0.0
     */
    public function __construct(
        string $baseDirectory,
        array $extensions,
        ?Thumbnailer $thumbs = null,
        array $thumbWidths = [],
        int $thumbLarge = 0,
        bool $thumbPrune = true
    ) {
        $this->base        = trim(str_replace('\\', '/', $baseDirectory), '/');
        $this->extensions  = $extensions;
        $this->thumbs      = $thumbs;
        $this->thumbWidths = $thumbWidths;
        $this->thumbLarge  = max(0, $thumbLarge);
        $this->thumbPrune  = $thumbPrune;
    }

    /**
     * Validates a folder (or single image file) name and returns its absolute real
     * path, or null.
     *
     * @param   string  $folderName  The folder / file name from the shortcode.
     *
     * @return  string|null  Absolute path on success; null on any rejection.
     *
     * @since   1.0.0
     */
    public function resolve(string $folderName): ?string
    {
        $this->error = '';

        // A leading slash is tolerated (sigplus treats "/x" and "x" the same, both
        // under the base); ".." and backslashes are not.
        $name = ltrim(trim(str_replace('\\', '/', $folderName)), '/');

        if ($name === '' || str_contains($name, '..')) {
            $this->error = 'invalid folder name';

            return null;
        }

        $baseAbs = realpath(JPATH_ROOT . '/' . $this->base);

        if ($baseAbs === false || !is_dir($baseAbs)) {
            $this->error = 'base_directory "' . $this->base . '" does not exist';

            return null;
        }

        $targetAbs = realpath($baseAbs . '/' . $name);

        if ($targetAbs === false) {
            $this->error = 'folder not found';

            return null;
        }

        $isFile = is_file($targetAbs)
            && \in_array(strtolower(pathinfo($targetAbs, \PATHINFO_EXTENSION)), $this->extensions, true);

        if (!is_dir($targetAbs) && !$isFile) {
            $this->error = 'not a directory or image file';

            return null;
        }

        if ($targetAbs !== $baseAbs && !str_starts_with($targetAbs . \DIRECTORY_SEPARATOR, $baseAbs . \DIRECTORY_SEPARATOR)) {
            $this->error = 'resolves outside base_directory';

            return null;
        }

        return $targetAbs;
    }

    /**
     * The reason the last resolve() failed.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Lists the images in a resolved gallery folder.
     *
     * @param   string  $absPath   Absolute path from resolve().
     * @param   string  $relBase   Site-root-relative path of the folder (for URLs).
     * @param   string  $sort      "asc" or "desc" (natural, by filename).
     *
     * @return  list<array{url:string, src:string, srcset:string, full:string, alt:string, w:?int, h:?int}>
     *
     * @since   1.0.0
     */
    public function images(string $absPath, string $relBase, string $sort): array
    {
        $names = @scandir($absPath) ?: [];
        $files = [];

        foreach ($names as $name) {
            if ($name === '' || $name[0] === '.') {
                continue;
            }

            if (!\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), $this->extensions, true)) {
                continue;
            }

            if (!is_file($absPath . \DIRECTORY_SEPARATOR . $name)) {
                continue;
            }

            $files[] = $name;
        }

        usort($files, 'strnatcasecmp');

        if (strtolower($sort) === 'desc') {
            $files = array_reverse($files);
        }

        $urlBase = rtrim(Uri::root(true), '/') . '/' . $this->encodePath(trim(str_replace('\\', '/', $relBase), '/'));

        $out  = [];
        $keep = [];

        foreach ($files as $name) {
            $absFile = $absPath . \DIRECTORY_SEPARATOR . $name;
            $size    = $this->size($absFile);
            $url     = $urlBase . '/' . rawurlencode($name);

            $out[] = $this->withThumbs(
                [
                    'url'    => $url,
                    'src'    => $url,
                    'srcset' => '',
                    'full'   => $url,
                    'alt'    => pathinfo($name, \PATHINFO_FILENAME),
                    'w'      => $size[0] ?? null,
                    'h'      => $size[1] ?? null,
                ],
                $absFile,
                $urlBase,
                $keep
            );
        }

        if ($this->thumbs !== null && $this->thumbPrune) {
            $this->thumbs->prune($absPath . \DIRECTORY_SEPARATOR . $this->thumbs->dirName(), $keep);
        }

        return $out;
    }

    /**
     * A one-image "gallery" for a shortcode that points straight at a file
     * (sigplus form: {gallery}path/to/photo.jpg{/gallery}).
     *
     * @param   string  $absFile  Absolute path from resolve().
     * @param   string  $relFile  Site-root-relative path of the file (for the URL).
     *
     * @return  list<array{url:string, src:string, srcset:string, full:string, alt:string, w:?int, h:?int}>
     *
     * @since   1.2.0
     */
    public function single(string $absFile, string $relFile): array
    {
        $rel   = trim(str_replace('\\', '/', $relFile), '/');
        $slash = strrpos($rel, '/');
        $dir   = $slash === false ? '' : substr($rel, 0, $slash);
        $file  = $slash === false ? $rel : substr($rel, $slash + 1);
        $size  = $this->size($absFile);

        $root   = rtrim(Uri::root(true), '/');
        $dirUrl = $dir === '' ? $root : $root . '/' . $this->encodePath($dir);
        $url    = $dirUrl . '/' . rawurlencode($file);
        $keep   = [];

        // A single-file gallery shares its .thumbs folder with sibling images, so it
        // never prunes.
        return [$this->withThumbs(
            [
                'url'    => $url,
                'src'    => $url,
                'srcset' => '',
                'full'   => $url,
                'alt'    => pathinfo($file, \PATHINFO_FILENAME),
                'w'      => $size[0] ?? null,
                'h'      => $size[1] ?? null,
            ],
            $absFile,
            $dirUrl,
            $keep
        )];
    }

    /**
     * Adds "src" / "srcset" / "full" to an image entry from generated derivatives, and
     * records the derivative file names in $keep for the prune pass. A no-op (entry
     * returned unchanged) when there is no Thumbnailer or the source cannot be read.
     *
     * @param   array<string,mixed>  $entry    The base image entry (url/src/srcset/full/alt/w/h).
     * @param   string               $absFile  Absolute path of the source image.
     * @param   string               $dirUrl   URL of the folder holding the source (no trailing slash).
     * @param   array<string>        $keep     Collected derivative file names (by reference).
     *
     * @return  array<string,mixed>
     *
     * @since   1.5.0
     */
    private function withThumbs(array $entry, string $absFile, string $dirUrl, array &$keep): array
    {
        if ($this->thumbs === null || !$entry['w'] || !$entry['h']) {
            return $entry;
        }

        $result  = $this->thumbs->ensure($absFile, $this->thumbWidths, $this->thumbLarge);
        $cacheUrl = $dirUrl . '/' . rawurlencode($this->thumbs->dirName());

        if ($result['variants'] !== []) {
            $set = [];

            foreach ($result['variants'] as $v) {
                $keep[] = $v['name'];
                $set[]  = $cacheUrl . '/' . rawurlencode($v['name']) . ' ' . $v['w'] . 'w';
            }

            // Complete the set with the original at its native width.
            $set[] = $entry['url'] . ' ' . (int) $entry['w'] . 'w';

            $entry['srcset'] = implode(', ', $set);
            $entry['src']    = $cacheUrl . '/' . rawurlencode($this->fallbackName($result['variants']));
        }

        if ($result['large'] !== null) {
            $keep[]        = $result['large']['name'];
            $entry['full'] = $cacheUrl . '/' . rawurlencode($result['large']['name']);
        }

        return $entry;
    }

    /**
     * Picks the derivative to use as the plain "src": the widest that is still no wider
     * than 1280 px, else the narrowest generated.
     *
     * @param   list<array{w:int, h:int, name:string}>  $variants  Ascending by width.
     *
     * @return  string
     *
     * @since   1.5.0
     */
    private function fallbackName(array $variants): string
    {
        $pick = $variants[0];

        foreach ($variants as $v) {
            if ($v['w'] <= 1280) {
                $pick = $v;
            }
        }

        return $pick['name'];
    }

    /**
     * URL-encodes each segment of a relative path, keeping the slashes.
     *
     * @param   string  $path  A forward-slash path with no leading or trailing slash.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', $path === '' ? [] : explode('/', $path)));
    }

    /**
     * Cached getimagesize(); returns [width, height] or null on any failure.
     *
     * @param   string  $file  Absolute file path.
     *
     * @return  array{0:int,1:int}|null
     *
     * @since   1.0.0
     */
    private function size(string $file): ?array
    {
        if (\array_key_exists($file, $this->sizeCache)) {
            return $this->sizeCache[$file];
        }

        $result = null;
        $info   = @getimagesize($file);

        if ($info !== false && isset($info[0], $info[1])) {
            $result = [(int) $info[0], (int) $info[1]];
        }

        return $this->sizeCache[$file] = $result;
    }
}
