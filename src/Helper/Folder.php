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

\defined('_JEXEC') or die;

/**
 * Resolves a gallery folder name to a validated absolute path and lists the images
 * inside it.
 *
 * The resolved real path must stay inside JPATH_ROOT/<base_directory>; anything with
 * "..", a leading slash, a non-existent target or a path that escapes the base is
 * rejected (resolve() returns null and getError() explains why). getimagesize() is
 * cached per request across every gallery on the page.
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
     * @param   string    $baseDirectory  The base_directory parameter value.
     * @param   string[]  $extensions     Allowed image extensions (lower-case, no dot).
     *
     * @since   1.0.0
     */
    public function __construct(string $baseDirectory, array $extensions)
    {
        $this->base       = trim(str_replace('\\', '/', $baseDirectory), '/');
        $this->extensions = $extensions;
    }

    /**
     * Validates a folder name and returns its absolute real path, or null.
     *
     * @param   string  $folderName  The folder name from the shortcode.
     *
     * @return  string|null  Absolute path on success; null on any rejection.
     *
     * @since   1.0.0
     */
    public function resolve(string $folderName): ?string
    {
        $this->error = '';

        $name = trim(str_replace('\\', '/', $folderName));

        if ($name === '' || $name[0] === '/' || str_contains($name, '..')) {
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

        if (!is_dir($targetAbs)) {
            $this->error = 'not a directory';

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
     * @return  list<array{url:string, alt:string, w:?int, h:?int}>
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

        $out = [];

        foreach ($files as $name) {
            $size = $this->size($absPath . \DIRECTORY_SEPARATOR . $name);

            $out[] = [
                'url' => $urlBase . '/' . rawurlencode($name),
                'alt' => pathinfo($name, \PATHINFO_FILENAME),
                'w'   => $size[0] ?? null,
                'h'   => $size[1] ?? null,
            ];
        }

        return $out;
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
