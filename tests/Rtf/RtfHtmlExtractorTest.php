<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Rtf;

use Cosmira\OutlookMessage\Rtf\RtfHtmlExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RtfHtmlExtractorTest extends TestCase
{
    public function testReturnsNullForRtfWithoutEncapsulatedHtml(): void
    {
        $this->assertNull(RtfHtmlExtractor::extract('{\rtf1\ansi\deff0 Plain {\b RTF} text.\par }'));
        $this->assertNull(RtfHtmlExtractor::extract('{\rtf1\ansi\fromhtml0 {\*\htmltag0 <p>}}'));
        $this->assertNull(RtfHtmlExtractor::extract(''));
    }

    public function testKeepsHtmlTagsAndTextWhileDroppingRtfOnlyFormatting(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg1252\fromhtml1 \deff0{\fonttbl{\f0\fswiss\fcharset0 Arial;}}'
            .'{\colortbl\red0\green0\blue0;}{\*\generator Microsoft Exchange Server;}'
            .'\uc1\pard\plain\deftab360 \f0\fs24 '
            .'{\*\htmltag19 <html>}{\*\htmltag50 <body>}'
            .'\htmlrtf {\b\htmlrtf0 Hello\htmlrtf }\htmlrtf0 '
            .'{\*\htmltag84 &amp;}\htmlrtf &\htmlrtf0  world'
            .'{\*\htmltag116 <br>}\htmlrtf \line'."\r\n".'\htmlrtf0 '
            .'{\*\htmltag58 </body>}{\*\htmltag27 </html>}}';

        $this->assertSame('<html><body>Hello&amp; world<br></body></html>', RtfHtmlExtractor::extract($rtf));
    }

    public function testHtmlRtfSuppressionIsScopedToGroups(): void
    {
        $rtf = $this->document('\htmlrtf {\htmlrtf0 kept}dropped\htmlrtf0  shown');

        $this->assertSame('kept shown', RtfHtmlExtractor::extract($rtf));
    }

    public function testTranslatesParagraphsTabsAndEscapedCharacters(): void
    {
        $rtf = $this->document(
            '{\*\htmltag0 <style>p \{color:red\}</style>}'
            .'{\*\htmltag0 <pre>}a\tab b\par c\\\\d\~e\_f\-g'."\\\r\n".'h{\*\htmltag0 </pre>}'
        );

        $this->assertSame("<style>p {color:red}</style><pre>a\tb\r\nc\\d\u{00A0}e\u{2011}f\u{00AD}g\r\nh</pre>", RtfHtmlExtractor::extract($rtf));
    }

    public function testIgnoresRawLineBreaksAsRequiredByRtf(): void
    {
        $rtf = $this->document("{\\*\\htmltag0 <a href=\"https://example.com/very/\r\nlong/path\">}link{\\*\\htmltag0 </a>}");

        $this->assertSame('<a href="https://example.com/very/long/path">link</a>', RtfHtmlExtractor::extract($rtf));
    }

    public function testSkipsDestinationsThatAreNotPartOfTheHtml(): void
    {
        $rtf = $this->document(
            '{\*\bkmkstart BM_BEGIN}{\*\unknown ignored}{\pntext\f1 1.\tab}'
            .'{\pict\wmetafile8\bin4 }{\\}}'
            .'{\stylesheet{\s0 Normal;}}{\info{\title Title}}'
            .'{\*\htmltag0 <p>}visible{\*\htmltag0 </p>}'
        );

        $this->assertSame('<p>visible</p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testPrefersHtmlTagOverMhtmlTagToKeepContentIdReferences(): void
    {
        $rtf = $this->document(
            '{\*\mhtmltag84 <img src="{\htmlbase ATT-0-image001.png}">}'
            .'{\*\htmltag84 <img src="cid:image001.png@01D0A524.96D40F30">}\htmlrtf \objattph  \htmlrtf0 '
        );

        $this->assertSame('<img src="cid:image001.png@01D0A524.96D40F30">', RtfHtmlExtractor::extract($rtf));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function codepageProvider(): iterable
    {
        yield 'windows-1252'                    => ['1252', 'f\\\'fcr', 'für'];
        yield 'windows-1251'                    => ['1251', '\\\'ea\\\'e0\\\'ea', 'как'];
        yield 'windows-1250'                    => ['1250', 'organiza\\\'e8n\\\'e1', 'organizačná'];
        yield 'windows-1255'                    => ['1255', '\\\'f9\\\'ec\\\'e5\\\'ed', 'שלום'];
        yield 'windows-1253'                    => ['1253', '\\\'e1\\\'e2', 'αβ'];
        yield 'shift-jis double byte'           => ['932', '\\\'82\\\'b1\\\'82\\\'f1', 'こん'];
        yield 'utf-8 bytes despite declaration' => ['1252', 'gr\\\'c3\\\'bc\\\'c3\\\'9f', 'grüß'];
    }

    #[DataProvider('codepageProvider')]
    public function testDecodesHexEscapesWithTheDocumentCodepage(string $codepage, string $text, string $expected): void
    {
        $rtf = '{\rtf1\ansi\ansicpg'.$codepage.'\fromhtml1 \deff0{\fonttbl{\f0\fswiss Arial;}}'
            .'{\*\htmltag0 <p>}'.$text.'{\*\htmltag0 </p>}}';

        $this->assertSame('<p>'.$expected.'</p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testDecodesHexEscapesWithTheCodepageOfTheActiveFont(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg932\fromhtml1 \deff0{\fonttbl'
            .'{\f0\fswiss\fcharset128 MS PGothic;}'
            .'{\f1\fswiss\fcharset134 SimSun;}'
            .'{\f2\fswiss\fcharset177 Arial;}}'
            .'\uc1\pard\plain\f0 '
            .'{\*\htmltag0 <p>}\\\'82\\\'b1{\f1 \\\'c4\\\'e3\\\'ba\\\'c3}{\f2 \\\'f9}\plain \\\'82\\\'f1{\*\htmltag0 </p>}}';

        $this->assertSame('<p>こ你好שん</p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testDecodesMacRomanFonts(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg1252\fromhtml1 \deff0{\fonttbl{\f0\fswiss Arial;}{\f1\fnil\fcharset77 Monaco;}}'
            .'{\*\htmltag0 <p>}{\f1 \\\'8a}{\*\htmltag0 </p>}}';

        $this->assertSame('<p>ä</p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testEscapesMarkupCharactersInTextOutsideHtmlTags(): void
    {
        // Exchange decodes entities into the text runs; markup only lives in \htmltag.
        $rtf = $this->document(
            '{\*\htmltag0 <p>}\htmlrtf {\htmlrtf0 Jana <jana@kunde.example> & Co &amp; R\\\'26D \\\'3cb\\\'3e'
            .'{\*\htmltag0 <br>}\htmlrtf }\htmlrtf0 {\*\htmltag0 </p>}'
        );

        $this->assertSame('<p>Jana &lt;jana@kunde.example&gt; &amp; Co &amp; R&amp;D &lt;b&gt;<br></p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testEscapingKeepsDoubleByteCharactersWithMarkupTrailBytes(): void
    {
        // Johab: D9 3C is a single character whose trail byte is '<'.
        $rtf = '{\rtf1\ansi\ansicpg1252\fromhtml1 \deff0{\fonttbl{\f0\fswiss Arial;}{\f1\fnil\fcharset130 Gulim;}}'
            .'{\*\htmltag0 <p>}{\f1 \\\'d9<}a<b{\*\htmltag0 </p>}}';

        $this->assertSame('<p>＼a&lt;b</p>', RtfHtmlExtractor::extract($rtf));
    }

    public function testDanglingLeadByteNeverSwallowsTheFollowingMarkup(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg932\fromhtml1 {\*\htmltag0 <p>}{\htmlrtf0 \\\'82}{\*\htmltag0 </p>}}';

        $html = RtfHtmlExtractor::extract($rtf);

        $this->assertIsString($html);
        $this->assertStringStartsWith('<p>', $html);
        $this->assertStringEndsWith('</p>', $html);
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
    }

    public function testDecodesUnicodeEscapesAndSkipsTheirFallbackCharacters(): void
    {
        $rtf = $this->document(
            '\uc1'.$this->u(20320).'?'.$this->u(22909).'?|\u-3913?|{\uc2'.$this->u(8364).'\\\'80\\\'80}|{\uc0'.$this->u(8211).'}|'.$this->u(8212).'\\\'97|'
        );

        $this->assertSame("你好|\u{F0B7}|€|–|—|", RtfHtmlExtractor::extract($rtf));
    }

    public function testCombinesSurrogatePairsIntoSupplementaryCharacters(): void
    {
        $high = $this->u(55357).'?';
        $low = $this->u(56842).'?';

        $this->assertSame('😊', RtfHtmlExtractor::extract($this->document($high.$low)));
        $this->assertSame('😊', RtfHtmlExtractor::extract($this->document('\u-10179?\u-8694?')));
        $this->assertSame("\u{FFFD}x", RtfHtmlExtractor::extract($this->document($high.'x')));
        $this->assertSame("\u{FFFD}x", RtfHtmlExtractor::extract($this->document($low.'x')));
        $this->assertSame("\u{FFFD}", RtfHtmlExtractor::extract($this->document($high)));
    }

    public function testGroupDelimitersTerminateUnicodeFallbackSkipping(): void
    {
        $this->assertSame('你?', RtfHtmlExtractor::extract($this->document('\uc1\u20320{?}')));
        $this->assertSame('你?', RtfHtmlExtractor::extract($this->document('{\uc1\u20320}?')));
        $this->assertSame('你x', RtfHtmlExtractor::extract($this->document('\uc2\u20320\{\}x')));
    }

    public function testBinaryPayloadCountsAsOneUnicodeFallbackCharacter(): void
    {
        $this->assertSame('你x', RtfHtmlExtractor::extract($this->document('\uc1\u20320\bin4 abcdx')));
        $this->assertSame('你x', RtfHtmlExtractor::extract($this->document('\uc2\u20320\bin4 abcd?x')));
    }

    public function testMalformedUnicodeValuesYieldReplacementCharacters(): void
    {
        $this->assertSame("\u{FFFD}x\u{FFFD}y", RtfHtmlExtractor::extract($this->document('\u9999999999?x\u-9999999999?y')));
    }

    public function testFontCodepageWinsWhenBytesAreAlsoValidUtf8(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg1252\fromhtml1 \deff0{\fonttbl{\f0\fcharset0 Arial;}{\f1\fcharset134 SimSun;}}'
            .'{\*\htmltag0 <p>}gr\\\'c3\\\'bc\\\'c3\\\'9f{\f1 \\\'c2\\\'bf}{\*\htmltag0 </p>}}';

        $this->assertSame('<p>grüß驴</p>', RtfHtmlExtractor::extract($rtf));
        $this->assertSame('驴', RtfHtmlExtractor::extract('{\rtf1\ansi\ansicpg936\fromhtml1 \\\'c2\\\'bf}'));
    }

    public function testTranslatesSymbolControlWords(): void
    {
        $rtf = $this->document('\lquote a\rquote  \ldblquote b\rdblquote  \bullet  \endash  \emdash ');

        $this->assertSame("\u{2018}a\u{2019} \u{201C}b\u{201D} \u{2022} \u{2013} \u{2014}", RtfHtmlExtractor::extract($rtf));
    }

    public function testToleratesTruncatedInput(): void
    {
        $this->assertSame('<p>abc4', RtfHtmlExtractor::extract('{\rtf1\ansi\fromhtml1 {\*\htmltag0 <p>}abc\\\'4'));
        $this->assertSame('<p>', RtfHtmlExtractor::extract('{\rtf1\ansi\fromhtml1 {\*\htmltag0 <p>}}}}\\'));
    }

    /**
     * Build an RTF \u control word for a signed 16-bit value.
     */
    private function u(int $value): string
    {
        return '\\'.'u'.$value;
    }

    private function document(string $body): string
    {
        return '{\rtf1\ansi\ansicpg1252\fromhtml1 \deff0{\fonttbl{\f0\fswiss\fcharset0 Arial;}}'
            .'\uc1\pard\plain\f0 '.$body.'}';
    }
}
