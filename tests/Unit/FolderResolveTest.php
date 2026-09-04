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

use PHPUnit\Framework\TestCase;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Folder;

/**
 * Folder::resolve() — the path-safety gate. It runs before anything touches the disk
 * for a gallery, so its rejections are what keep a shortcode from reading outside the
 * configured base directory. Exercised against a real temp tree with JPATH_ROOT
 * pointed at the system temp dir.
 */
final class FolderResolveTest extends TestCase
{
    private string $base;
    private string $baseAbs;

    public static function setUpBeforeClass(): void
    {
        if (!\defined('JPATH_ROOT')) {
            \define('JPATH_ROOT', realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        }
    }

    protected function setUp(): void
    {
        $this->base    = 'dg-folder-' . bin2hex(random_bytes(6));
        $this->baseAbs = JPATH_ROOT . '/' . $this->base;

        mkdir($this->baseAbs . '/sub/nested', 0777, true);
        file_put_contents($this->baseAbs . '/sub/photo.jpg', 'x');
        file_put_contents($this->baseAbs . '/sub/notes.txt', 'x');
        mkdir(JPATH_ROOT . '/' . $this->base . '-sibling', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseAbs);
        $this->rrmdir(JPATH_ROOT . '/' . $this->base . '-sibling');
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

    private function folder(): Folder
    {
        return new Folder($this->base, ['jpg', 'jpeg', 'png']);
    }

    public function testResolvesADirectory(): void
    {
        $f = $this->folder();

        $this->assertSame(realpath($this->baseAbs . '/sub'), $f->resolve('sub'));
        $this->assertSame('', $f->getError());
    }

    public function testResolvesANestedDirectory(): void
    {
        $this->assertSame(
            realpath($this->baseAbs . '/sub/nested'),
            $this->folder()->resolve('sub/nested')
        );
    }

    public function testResolvesASingleImageFile(): void
    {
        $this->assertSame(
            realpath($this->baseAbs . '/sub/photo.jpg'),
            $this->folder()->resolve('sub/photo.jpg')
        );
    }

    public function testLeadingSlashIsTolerated(): void
    {
        // sigplus writes "/path"; "/x" and "x" must resolve the same.
        $this->assertSame(
            $this->folder()->resolve('sub'),
            $this->folder()->resolve('/sub')
        );
    }

    public function testBackslashesAreNormalised(): void
    {
        $this->assertSame(
            realpath($this->baseAbs . '/sub/nested'),
            $this->folder()->resolve('sub\\nested')
        );
    }

    public function testRejectsEmptyName(): void
    {
        $f = $this->folder();

        $this->assertNull($f->resolve(''));
        $this->assertSame('invalid folder name', $f->getError());
        $this->assertNull($f->resolve('   '));
    }

    public function testRejectsParentTraversal(): void
    {
        $f = $this->folder();

        $this->assertNull($f->resolve('..'));
        $this->assertSame('invalid folder name', $f->getError());
        $this->assertNull($f->resolve('../' . $this->base . '-sibling'));
        $this->assertNull($f->resolve('sub/../sub'), 'rejected on the literal "..", even though it would resolve inside');
        $this->assertNull($f->resolve('..\\' . $this->base . '-sibling'), 'backslash traversal too');
    }

    public function testRejectsANonImageFile(): void
    {
        $f = $this->folder();

        $this->assertNull($f->resolve('sub/notes.txt'));
        $this->assertSame('not a directory or image file', $f->getError());
    }

    public function testRejectsAMissingTarget(): void
    {
        $f = $this->folder();

        $this->assertNull($f->resolve('does/not/exist'));
        $this->assertSame('folder not found', $f->getError());
    }

    public function testRejectsWhenTheBaseItselfIsMissing(): void
    {
        $f = new Folder('dg-does-not-exist-' . bin2hex(random_bytes(4)), ['jpg']);

        $this->assertNull($f->resolve('sub'));
        $this->assertStringContainsString('does not exist', $f->getError());
    }
}
