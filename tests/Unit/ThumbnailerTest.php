<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\Content\DinkyGallery\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Thumbnailer;

/**
 * The GD thumbnail cache. Runs against real files in a temp directory — the whole
 * point of the class is filesystem behaviour (naming, hash invalidation, pruning) that
 * a mock could not exercise.
 */
#[RequiresPhpExtension('gd')]
final class ThumbnailerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dg-thumb-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rrmdir($path . '/' . $e);
            }
        }

        @rmdir($path);
    }

    private function jpeg(string $name, int $w, int $h): string
    {
        $im   = imagecreatetruecolor($w, $h);
        $path = $this->dir . '/' . $name;
        imagejpeg($im, $path, 90);
        imagedestroy($im);

        return $path;
    }

    private function thumbDir(): string
    {
        return $this->dir . '/.thumbs';
    }

    public function testGeneratesRequestedWidthsAndSkipsUpscales(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);
        $t   = new Thumbnailer('.thumbs', 82);

        $result = $t->ensure($src, [480, 768, 1024, 1600, 2000], 0);

        $this->assertCount(3, $result['variants'], 'only 480 / 768 / 1024 are below the source width');
        $this->assertNull($result['large']);
        $this->assertSame([480, 768, 1024], array_column($result['variants'], 'w'));

        foreach ($result['variants'] as $v) {
            $file = $this->thumbDir() . '/' . $v['name'];
            $this->assertFileExists($file);
            $this->assertMatchesRegularExpression('/^src\.[0-9a-f]{8}\.\d+x\d+\.jpg$/', $v['name']);
            [$fw, $fh] = getimagesize($file);
            $this->assertSame($v['w'], $fw);
            $this->assertSame($v['h'], $fh);
        }
    }

    public function testAspectRatioIsKept(): void
    {
        $src    = $this->jpeg('src.jpg', 1600, 1000);
        $result = (new Thumbnailer('.thumbs', 82))->ensure($src, [800], 0);

        $this->assertSame(800, $result['variants'][0]['w']);
        $this->assertSame(500, $result['variants'][0]['h']);
    }

    public function testLargeDerivativeOnlyWhenOverTheCap(): void
    {
        $big   = $this->jpeg('big.jpg', 3000, 2000);
        $small = $this->jpeg('small.jpg', 1600, 1000);
        $t     = new Thumbnailer('.thumbs', 82);

        $over = $t->ensure($big, [], 1920);
        $this->assertNotNull($over['large']);
        $this->assertSame(1920, $over['large']['w']);
        $this->assertSame(1280, $over['large']['h']);
        $this->assertFileExists($this->thumbDir() . '/' . $over['large']['name']);
        $this->assertStringContainsString('.large-1920x1280.jpg', $over['large']['name']);

        $under = $t->ensure($small, [], 1920);
        $this->assertNull($under['large'], 'a source within the cap is served as-is');
    }

    public function testNoCacheFolderWhenNothingToGenerate(): void
    {
        $src = $this->jpeg('src.jpg', 1200, 800);
        (new Thumbnailer('.thumbs', 82))->ensure($src, [1600, 2000], 0);

        $this->assertDirectoryDoesNotExist($this->thumbDir());
    }

    public function testSecondCallReusesInsteadOfRegenerating(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);
        $t   = new Thumbnailer('.thumbs', 82);

        $t->ensure($src, [480, 768], 0);
        $afterFirst = $t->stats();
        $this->assertSame(2, $afterFirst['made']);

        $t->ensure($src, [480, 768], 0);
        $afterSecond = $t->stats();
        $this->assertSame(2, $afterSecond['made'], 'nothing new was written');
        $this->assertSame(2, $afterSecond['reused'] - $afterFirst['reused']);
    }

    public function testReplacingTheSourceInvalidatesAndPrunes(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);
        $t   = new Thumbnailer('.thumbs', 82);

        $first = $t->ensure($src, [480, 768], 0);
        $oldNames = array_column($first['variants'], 'name');

        // Simulate a re-upload under the same name: same dimensions, different bytes + mtime.
        $this->jpeg('src.jpg', 1600, 1000);
        touch($src, time() + 120);
        clearstatcache();

        $second = $t->ensure($src, [480, 768], 0);
        $newNames = array_column($second['variants'], 'name');

        $this->assertNotSame($oldNames, $newNames, 'the hash segment changed');

        $t->prune($this->thumbDir(), $newNames);

        foreach ($oldNames as $n) {
            $this->assertFileDoesNotExist($this->thumbDir() . '/' . $n);
        }
        foreach ($newNames as $n) {
            $this->assertFileExists($this->thumbDir() . '/' . $n);
        }
        $this->assertGreaterThan(0, $t->stats()['pruned']);
    }

    public function testPruneLeavesForeignFilesAlone(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);
        $t   = new Thumbnailer('.thumbs', 82);
        $t->ensure($src, [480], 0);

        file_put_contents($this->thumbDir() . '/notes.txt', 'keep me');
        file_put_contents($this->thumbDir() . '/src.deadbeef.999x999.jpg', 'stale, matches the pattern');

        $t->prune($this->thumbDir(), []); // keep nothing that we generated

        $this->assertFileExists($this->thumbDir() . '/notes.txt');
        $this->assertFileDoesNotExist($this->thumbDir() . '/src.deadbeef.999x999.jpg');
    }

    public function testPruneRemovesAnEmptiedCacheFolder(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);
        $t   = new Thumbnailer('.thumbs', 82);
        $t->ensure($src, [480, 768], 0);

        $this->assertDirectoryExists($this->thumbDir());

        $t->prune($this->thumbDir(), []); // nothing kept -> all generated files go -> folder goes

        $this->assertDirectoryDoesNotExist($this->thumbDir());
    }

    public function testUnsupportedFormatFallsBackToEmpty(): void
    {
        $txt = $this->dir . '/notanimage.jpg';
        file_put_contents($txt, 'this is not a JPEG');

        $result = (new Thumbnailer('.thumbs', 82))->ensure($txt, [480], 1920);

        $this->assertSame(['variants' => [], 'large' => null], $result);
        $this->assertDirectoryDoesNotExist($this->thumbDir());
    }

    public function testQualityIsPartOfTheCacheKey(): void
    {
        $src = $this->jpeg('src.jpg', 1600, 1000);

        $a = (new Thumbnailer('.thumbs', 60))->ensure($src, [480], 0)['variants'][0]['name'];
        $b = (new Thumbnailer('.thumbs', 90))->ensure($src, [480], 0)['variants'][0]['name'];

        $this->assertNotSame($a, $b);
    }

    public function testWebPKeepsTheSourceFormatWhenGdSupportsIt(): void
    {
        if (!(gd_info()['WebP Support'] ?? false)) {
            $this->markTestSkipped('this GD build has no WebP support');
        }

        $im   = imagecreatetruecolor(1600, 1000);
        $path = $this->dir . '/src.webp';
        imagewebp($im, $path, 80);
        imagedestroy($im);

        $result = (new Thumbnailer('.thumbs', 82))->ensure($path, [480], 0);

        $this->assertCount(1, $result['variants']);
        $this->assertStringEndsWith('.webp', $result['variants'][0]['name']);
    }
}
