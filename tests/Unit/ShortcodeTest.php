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
use TheLoom\Plugin\Content\DinkyGallery\Helper\Shortcode;

/**
 * The shortcode scanner. It has to recognise three syntaxes ({gallery folder},
 * {gallery attr="..."} and the sigplus {gallery}path{/gallery} form), report byte
 * offsets the caller can splice at, and leave a tag inside <code>/<pre> alone.
 */
final class ShortcodeTest extends TestCase
{
    private function find(string $text): array
    {
        return (new Shortcode())->find($text);
    }

    public function testNothingToFind(): void
    {
        $this->assertSame([], $this->find(''));
        $this->assertSame([], $this->find('An article with no gallery in it.'));
    }

    public function testWordBoundary(): void
    {
        // "{galleryfoo}" is not "{gallery ...}".
        $this->assertSame([], $this->find('{galleryfoo}'));
    }

    public function testBareForm(): void
    {
        $matches = $this->find('{gallery my-folder}');

        $this->assertCount(1, $matches);
        $this->assertSame(0, $matches[0]['start']);
        $this->assertSame('{gallery my-folder}', $matches[0]['raw']);
        $this->assertSame(19, $matches[0]['length']);
        $this->assertSame(['folder' => 'my-folder'], $matches[0]['options']);
        $this->assertNull($matches[0]['skip']);
    }

    public function testBareEmptyTag(): void
    {
        $matches = $this->find('{gallery}');

        $this->assertCount(1, $matches);
        $this->assertSame([], $matches[0]['options']);
    }

    public function testNestedFolderPath(): void
    {
        $this->assertSame(
            ['folder' => 'events/2026/summer'],
            $this->find('{gallery events/2026/summer}')[0]['options']
        );
    }

    public function testAttributeForm(): void
    {
        $options = $this->find(
            '{gallery folder="x" cards="4" size="80" loop="0" sort="desc" middle="close" gap="12px" aspect="16:9"}'
        )[0]['options'];

        $this->assertSame(
            [
                'folder' => 'x',
                'cards'  => '4',
                'size'   => '80',
                'loop'   => '0',
                'sort'   => 'desc',
                'middle' => 'close',
                'gap'    => '12px',
                'aspect' => '16:9',
            ],
            $options
        );
    }

    public function testBareTokensAndLeadingFolder(): void
    {
        // A leading word with no name= is still the folder; unquoted values are fine.
        $options = $this->find('{gallery my-pics cards=4 size=80}')[0]['options'];

        $this->assertSame(['folder' => 'my-pics', 'cards' => '4', 'size' => '80'], $options);
    }

    public function testUnknownAttributesAreDropped(): void
    {
        $options = $this->find('{gallery folder="x" bogus="y" width="500"}')[0]['options'];

        $this->assertSame(['folder' => 'x'], $options);
    }

    public function testDeftitleIsKept(): void
    {
        // Not an override, but carried through to become the images' alt text.
        $options = $this->find('{gallery folder="x" deftitle="Our Trip"}')[0]['options'];

        $this->assertSame(['folder' => 'x', 'deftitle' => 'Our Trip'], $options);
    }

    public function testClosingTagForm(): void
    {
        $matches = $this->find('{gallery}my-folder{/gallery}');

        $this->assertCount(1, $matches);
        $this->assertSame('my-folder', $matches[0]['options']['folder']);
        $this->assertSame('{gallery}my-folder{/gallery}', $matches[0]['raw']);
    }

    public function testClosingTagWithOpeningAttributes(): void
    {
        $options = $this->find('{gallery cards="4" size="90"}path/to/pics{/gallery}')[0]['options'];

        $this->assertSame(['cards' => '4', 'size' => '90', 'folder' => 'path/to/pics'], $options);
    }

    public function testInnerTextWinsOverBodyFolder(): void
    {
        $options = $this->find('{gallery folder="from-body"}from/inner{/gallery}')[0]['options'];

        $this->assertSame('from/inner', $options['folder']);
    }

    public function testInnerTextIsTrimmed(): void
    {
        $options = $this->find("{gallery}  spaced/path  {/gallery}")[0]['options'];

        $this->assertSame('spaced/path', $options['folder']);
    }

    public function testMultipleGalleriesInDocumentOrder(): void
    {
        $text    = 'intro {gallery a} middle {gallery folder="b" cards="2"} end';
        $matches = $this->find($text);

        $this->assertCount(2, $matches);
        $this->assertSame('a', $matches[0]['options']['folder']);
        $this->assertSame('b', $matches[1]['options']['folder']);
        $this->assertLessThan($matches[1]['start'], $matches[0]['start']);
    }

    public function testOffsetsAndLengthSpliceExactly(): void
    {
        // What the Extension relies on: substr($text, start, length) is the raw tag.
        $text = "before {gallery über/pfad} after";

        foreach ($this->find($text) as $m) {
            $this->assertSame($m['raw'], substr($text, $m['start'], $m['length']));
        }
    }

    public function testSkippedInsidePre(): void
    {
        $this->assertSame('code-block', $this->find('<pre>{gallery x}</pre>')[0]['skip']);
        $this->assertSame('code-block', $this->find('<code>{gallery x}</code>')[0]['skip']);
    }

    public function testNotSkippedWhenBlockClosedBefore(): void
    {
        $this->assertNull($this->find('<pre>sample</pre> then {gallery x}')[0]['skip']);
    }

    public function testSkipIsPerTag(): void
    {
        // First tag inside an open <code>, second after it closes.
        $matches = $this->find('<code>{gallery a}</code> {gallery b}');

        $this->assertSame('code-block', $matches[0]['skip']);
        $this->assertNull($matches[1]['skip']);
    }
}
