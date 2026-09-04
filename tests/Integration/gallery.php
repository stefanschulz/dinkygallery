<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 *
 * Checks the folder listing, the thumbnail cache and a full article render against
 * the fixtures in .docker/fixtures.sql.
 *
 * These are not unit tests and deliberately use no mocks: what has to be right here is
 * the real filesystem (natural sort, the .thumbs cache, GD output) and the real
 * onContentPrepare pipeline. So it runs against the Joomla install of the test stack:
 *
 *   .docker/gallery.sh
 *
 * Part A drives Folder + Thumbnailer directly against images/gallery-*. Part B fetches
 * the test article over HTTP and asserts on the HTML. Exit code is 0 only if every
 * check passed.
 */

use TheLoom\Plugin\Content\DinkyGallery\Helper\Folder;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Thumbnailer;

// phpcs:disable PSR1.Files.SideEffects
\define('_JEXEC', 1);
\define('JPATH_BASE', getenv('JOOMLA_ROOT') ?: '/var/www/html');

// Give Uri something to work with under CLI so Uri::root(true) is well defined.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'dinkygallery.localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
// phpcs:enable PSR1.Files.SideEffects

// The plugin's classes: the extension autoloader only knows them while the extension
// renders, so register the one prefix this script needs.
spl_autoload_register(static function (string $class): void {
    $prefix = 'TheLoom\\Plugin\\Content\\DinkyGallery\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

$failed = 0;
$run    = 0;

$check = static function (string $what, bool $ok, string $detail = '') use (&$failed, &$run): void {
    $run++;

    if ($ok) {
        printf("  ok    %s\n", $what);

        return;
    }

    $failed++;
    printf("  FAIL  %s\n%s", $what, $detail === '' ? '' : "        $detail\n");
};

$root  = rtrim(JPATH_BASE, '/');
$exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
$thumb = \function_exists('imagecreatetruecolor') ? new Thumbnailer('.thumbs', 82) : null;

if ($thumb === null) {
    fwrite(STDERR, "GD is not available in this PHP — thumbnail checks cannot run.\n");

    exit(2);
}

echo "Part A — Folder + Thumbnailer against images/gallery-*\n\n";

$folder = new Folder('images', $exts, $thumb, [480, 768, 1024, 1600], 1920, true);

// --- resolve() -----------------------------------------------------------------
$g12 = $folder->resolve('gallery-12');
$check('resolve("gallery-12") -> a directory', $g12 !== null && is_dir($g12), $folder->getError());
$check('resolve("../escape") -> null', $folder->resolve('../escape') === null);
$check('resolve("missing-folder") -> null', $folder->resolve('missing-folder') === null);

$single = $folder->resolve('gallery-12/05-huge.jpg');
$check('resolve("gallery-12/05-huge.jpg") -> the file', $single !== null && is_file($single), $folder->getError());

// --- images() listing --------------------------------------------------------
$asc = $folder->images($g12, 'images/gallery-12', 'asc');

$check('gallery-12 lists 12 images (notes.txt skipped)', \count($asc) === 12, 'got ' . \count($asc));
$check(
    'natural sort ascending',
    ($asc[0]['alt'] ?? '') === '01-landscape' && ($asc[11]['alt'] ?? '') === '12-mid',
    'first=' . ($asc[0]['alt'] ?? '?') . ' last=' . ($asc[11]['alt'] ?? '?')
);

$desc = $folder->images($g12, 'images/gallery-12', 'desc');
$check('sort=desc reverses', ($desc[0]['alt'] ?? '') === '12-mid' && ($desc[11]['alt'] ?? '') === '01-landscape');

$byName = [];

foreach ($asc as $img) {
    $byName[$img['alt']] = $img;
}

$check(
    'every entry has url / src / srcset / full / w / h keys',
    !array_filter(
        $asc,
        static fn ($i) => !isset($i['url'], $i['src'], $i['srcset'], $i['full'], $i['alt'])
            || !array_key_exists('w', $i) || !array_key_exists('h', $i)
    )
);

$check(
    'urls sit under the site root + /images/gallery-12/',
    str_contains($byName['01-landscape']['url'], '/images/gallery-12/01-landscape.jpg')
);

// alt is the file name without its extension (Folder uses PATHINFO_FILENAME).
$tiny = $byName['04-tiny'];
$mid  = $byName['09-mid'];
$huge = $byName['05-huge'];

// 04-tiny.png is 120x90: every target width is >= its own, so no srcset, src == url.
$check(
    '04-tiny.png (120x90): no derivatives, src == url',
    $tiny['srcset'] === '' && $tiny['src'] === $tiny['url']
);

// 09-mid.jpg is 1500x1000: 480/768/1024 generated + original; within the 1920 cap.
$check(
    '09-mid.jpg (1500x1000): srcset has 3 derivatives + the original',
    substr_count($mid['srcset'], '.thumbs/') === 3
        && str_contains($mid['srcset'], '/images/gallery-12/09-mid.jpg 1500w')
);
$check(
    '09-mid.jpg: full == the original (source within thumb_large)',
    $mid['full'] === $mid['url']
);

// 05-huge.jpg is 6000x4000: gets a size-capped "large" derivative for the lightbox.
$check(
    '05-huge.jpg (6000x4000): full is a large-1920x1280 derivative',
    str_contains($huge['full'], '/.thumbs/05-huge.')
        && str_contains($huge['full'], '.large-1920x1280.jpg')
);

// --- the .thumbs cache on disk ---------------------------------------------
$cacheDir = $g12 . '/.thumbs';
$check('.thumbs cache folder was created', is_dir($cacheDir));

$cacheFiles = array_values(array_filter(scandir($cacheDir) ?: [], static fn ($e) => $e[0] !== '.'));
$check(
    'every cache file matches the derivative naming pattern',
    $cacheFiles !== [] && !array_filter(
        $cacheFiles,
        static fn ($n) => !preg_match('/\.[0-9a-f]{8}\.(?:large-)?\d+x\d+\.[a-z0-9]+$/i', $n)
    )
);

$madeFirst = $thumb->stats()['made'];
$folder->images($g12, 'images/gallery-12', 'asc'); // second pass
$check(
    'a second listing reuses the cache (0 further made)',
    $thumb->stats()['made'] === $madeFirst,
    'made went from ' . $madeFirst . ' to ' . $thumb->stats()['made']
);
$check('nothing was pruned on a clean re-list', $thumb->stats()['pruned'] === 0);

// --- single() --------------------------------------------------------------
$one = $folder->single($single, 'images/gallery-12/05-huge.jpg');
$check('single() returns exactly one entry', \count($one) === 1);
$check(
    'single() entry: alt "05-huge", full is the large derivative',
    ($one[0]['alt'] ?? '') === '05-huge'
        && str_contains($one[0]['full'], '.large-1920x1280.jpg')
);

// --- other fixture folders -----------------------------------------------
$p = $folder->resolve('gallery-portrait');
$check(
    'gallery-portrait lists 6 portraits, natural order',
    $p !== null
        && \count($folder->images($p, 'images/gallery-portrait', 'asc')) === 6
);

echo "\nPart B — full render of the test article over HTTP\n\n";

// curl, not the HTTP stream wrapper: SEF is on, so index.php?...id=110 answers 301
// and the wrapper does not follow it. Hit the alias directly.
$html = shell_exec(
    'curl -s -H "Host: dinkygallery.localhost" http://localhost/dinkygallery-test 2>/dev/null'
);

if ($html === null || $html === '' || $html === false) {
    $check('fetched the article', false, 'no response from the local web server');
} else {
    $check('fetched the article', true);
    $check('no PHP warning / notice / fatal in the output', !preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated):/', $html));

    $dgCount = substr_count($html, 'class="dg"');
    $check('renders at least 10 galleries', $dgCount >= 10, 'found ' . $dgCount);

    // Look at the body only: the <head> JSON-LD echoes the article's metadesc, which
    // legitimately contains the literal "{gallery}".
    $body = (($h = strpos($html, '</head>')) !== false) ? substr($html, $h) : $html;

    $check('cards carry a srcset', str_contains($body, 'srcset="'));
    $check('the debug comment is present', str_contains($body, '<!-- DinkyGallery:'));
    $check('the debug comment reports thumbnail work', (bool) preg_match('/thumbs: \d+ made, \d+ reused/', $body));
    $check('no data-w / data-h leaked onto the card links', !str_contains($body, 'data-w='));
    $check('no {gallery} left unrendered in the body', !preg_match('/\{gallery[\s}]/', $body));
}

printf("\n%d/%d checks passed\n", $run - $failed, $run);

exit($failed === 0 ? 0 : 1);
