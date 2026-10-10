<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

/**
 * Recovers the original HTML from an RTF body that encapsulates it ([MS-OXRTFEX] section 2.4).
 *
 * Outlook frequently stores HTML mail only as compressed RTF marked with \fromhtml1.
 * The HTML markup lives in \*\htmltag destinations, while the RTF-only formatting
 * that must be dropped is wrapped in \htmlrtf ... \htmlrtf0 blocks. A \*\mhtmltag
 * duplicates the following \htmltag with MHTML-rewritten URLs and is skipped, so
 * inline images keep their original cid: references.
 */
final class RtfHtmlExtractor
{
    /**
     * Destinations whose content never belongs to the encapsulated HTML.
     */
    private const SKIPPED_DESTINATIONS = [
        'colortbl'   => true,
        'fonttbl'    => true,
        'info'       => true,
        'listtable'  => true,
        'object'     => true,
        'pict'       => true,
        'pntext'     => true,
        'pntxta'     => true,
        'pntxtb'     => true,
        'stylesheet' => true,
    ];

    /**
     * Control words that stand for a single Unicode character.
     */
    private const SYMBOLS = [
        'bullet'    => "\u{2022}",
        'emdash'    => "\u{2014}",
        'endash'    => "\u{2013}",
        'ldblquote' => "\u{201C}",
        'lquote'    => "\u{2018}",
        'rdblquote' => "\u{201D}",
        'rquote'    => "\u{2019}",
        'tab'       => "\t",
    ];

    /**
     * Output pieces: raw bytes with their codepage, or UTF-8 text with a null codepage,
     * plus whether the piece is text (outside \htmltag) that must be escaped once decoded.
     *
     * @var list<array{string, int|null, bool}>
     */
    private array $segments = [];

    private string $pendingBytes = '';

    private int $pendingCodepage = 1252;

    private bool $pendingEscape = false;

    private int $codepage = 1252;

    private int $defaultFont = 0;

    /**
     * @var array<int, int>
     */
    private array $fontCodepages = [];

    private ?int $fontDefinition = null;

    private bool $fromHtml = false;

    private ?int $highSurrogate = null;

    private int $pendingSkip = 0;

    private RtfHtmlExtractorState $state;

    /**
     * @var RtfHtmlExtractorState[]
     */
    private array $stack = [];

    private function __construct(private readonly string $rtf)
    {
        $this->state = new RtfHtmlExtractorState;
    }

    /**
     * Extract the encapsulated HTML from a decompressed RTF body as UTF-8.
     *
     * Returns null when the RTF does not encapsulate HTML.
     */
    public static function extract(string $rtf): ?string
    {
        if (! str_contains($rtf, '\fromhtml')) {
            return null;
        }

        return (new self($rtf))->run();
    }

    private function run(): ?string
    {
        $length = strlen($this->rtf);
        $offset = 0;

        while ($offset < $length) {
            $char = $this->rtf[$offset];

            if ($char === '{' || $char === '}') {
                $this->group($char === '{');
                $offset++;

                continue;
            }

            if ($char === '\\') {
                $offset = $this->readControl($offset + 1);

                continue;
            }

            if ($char === "\r" || $char === "\n") {
                $offset++;

                continue;
            }

            $run = strcspn($this->rtf, "\\{}\r\n", $offset);
            $this->text(substr($this->rtf, $offset, $run));
            $offset += $run;
        }

        return $this->fromHtml ? $this->assemble() : null;
    }

    /**
     * Open or close a group. Pending bytes are flushed so that a dangling
     * double-byte lead byte can never swallow the markup that follows.
     */
    private function group(bool $open): void
    {
        $this->flushBytes();
        $this->pendingSkip = 0;

        if (! $open) {
            $this->state = array_pop($this->stack) ?? new RtfHtmlExtractorState;

            return;
        }

        $this->stack[] = $this->state;
        $this->state = clone $this->state;
        $this->state->ignorable = false;
        $this->state->destinationPending = true;
    }

    /**
     * Read the control word or control symbol starting after a backslash.
     */
    private function readControl(int $offset): int
    {
        if (! isset($this->rtf[$offset])) {
            return $offset;
        }

        $char = $this->rtf[$offset];

        if (! ctype_alpha($char)) {
            return $this->controlSymbol($char, $offset + 1);
        }

        preg_match('/\G([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $this->rtf, $matches, 0, $offset);
        $word = $matches[1] ?? $char;
        $parameter = isset($matches[2]) ? (int) $matches[2] : null;
        $next = $offset + strlen($matches[0] ?? $char);

        if ($word === 'bin') {
            return $next + max(0, $parameter ?? 0);
        }

        $this->controlWord($word, $parameter);

        return $next;
    }

    private function controlSymbol(string $symbol, int $next): int
    {
        if ($symbol === '*') {
            $this->state->ignorable = true;

            return $next;
        }

        if ($symbol === "'") {
            $hex = substr($this->rtf, $next, 2);

            if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                $this->bytes(chr((int) hexdec($hex) & 0xFF));

                return $next + 2;
            }

            return $next;
        }

        if ($this->consumeSkip()) {
            return $next;
        }

        match ($symbol) {
            '{', '}', '\\' => $this->bytes($symbol),
            '~'            => $this->unicode("\u{00A0}"),
            '_'            => $this->bytes('-'),
            "\r", "\n"     => $this->bytes("\r\n"),
            default        => null,
        };

        return $next;
    }

    private function controlWord(string $word, ?int $parameter): void
    {
        $isDestination = $this->state->destinationPending;
        $this->state->destinationPending = false;

        if ($isDestination && $this->enterDestination($word)) {
            return;
        }

        if ($this->consumeSkip()) {
            return;
        }

        match ($word) {
            'fromhtml'      => $this->fromHtml = $parameter !== 0,
            'ansicpg'       => $this->codepage = $parameter ?? 1252,
            'deff'          => $this->defaultFont = $parameter ?? 0,
            'f'             => $this->font($parameter),
            'fcharset'      => $this->fontCharset($parameter),
            'plain'         => $this->state->font = null,
            'htmlrtf'       => $this->state->suppressed = $parameter !== 0,
            'uc'            => $this->state->unicodeSkip = max(0, $parameter ?? 1),
            'u'             => $this->unicodeCodeUnit($parameter ?? 0),
            'par', 'line'   => $this->bytes("\r\n"),
            default         => isset(self::SYMBOLS[$word]) ? $this->unicode(self::SYMBOLS[$word]) : null,
        };
    }

    /**
     * Handle the first control word of a group. Returns true when it was consumed.
     */
    private function enterDestination(string $word): bool
    {
        if ($word === 'htmltag') {
            $this->state->suppressed = false;
            $this->state->ignorable = false;
            $this->state->htmlTag = true;

            return true;
        }

        if ($word === 'fonttbl') {
            $this->state->fontTable = true;
        }

        if ($this->state->ignorable || isset(self::SKIPPED_DESTINATIONS[$word])) {
            $this->state->skipped = true;

            return true;
        }

        return false;
    }

    /**
     * Select a font, or start a font definition inside the font table.
     */
    private function font(?int $font): void
    {
        if ($this->state->fontTable) {
            $this->fontDefinition = $font;

            return;
        }

        $this->state->font = $font;
    }

    private function fontCharset(?int $charset): void
    {
        $codepage = $charset !== null ? RtfCodepage::forCharset($charset) : null;

        if ($this->state->fontTable && $this->fontDefinition !== null && $codepage !== null) {
            $this->fontCodepages[$this->fontDefinition] = $codepage;
        }
    }

    private function text(string $text): void
    {
        if ($this->pendingSkip > 0) {
            $skipped = min($this->pendingSkip, strlen($text));
            $this->pendingSkip -= $skipped;
            $text = substr($text, $skipped);
        }

        $this->state->destinationPending = false;

        if ($text !== '') {
            $this->bytes($text);
        }
    }

    /**
     * Swallow one fallback character after a \u control word when required.
     */
    private function consumeSkip(): bool
    {
        if ($this->pendingSkip === 0) {
            return false;
        }

        $this->pendingSkip--;

        return true;
    }

    /**
     * Emit a \u value, which is a signed UTF-16 code unit; characters outside
     * the BMP (such as emoji) arrive as a high and a low surrogate.
     */
    private function unicodeCodeUnit(int $value): void
    {
        $codeUnit = $value < 0 ? $value + 0x10000 : $value;
        $this->pendingSkip = $this->state->unicodeSkip;

        if (! $this->emits()) {
            return;
        }

        if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF) {
            $this->flushSurrogate();
            $this->highSurrogate = $codeUnit;

            return;
        }

        if ($codeUnit >= 0xDC00 && $codeUnit <= 0xDFFF) {
            $high = $this->highSurrogate;
            $this->highSurrogate = null;

            $this->unicode($high === null
                ? "\u{FFFD}"
                : mb_chr(0x10000 + (($high - 0xD800) << 10) + ($codeUnit - 0xDC00), 'UTF-8'));

            return;
        }

        $this->unicode(mb_chr($codeUnit, 'UTF-8'));
    }

    private function bytes(string $bytes): void
    {
        if ($this->pendingSkip > 0) {
            $this->pendingSkip--;

            return;
        }

        if (! $this->emits()) {
            return;
        }

        $this->flushSurrogate();

        $codepage = $this->fontCodepages[$this->state->font ?? $this->defaultFont] ?? $this->codepage;
        $escape = ! $this->state->htmlTag;
        if ($codepage !== $this->pendingCodepage || $escape !== $this->pendingEscape) {
            $this->flushBytes();
            $this->pendingCodepage = $codepage;
            $this->pendingEscape = $escape;
        }

        $this->pendingBytes .= $bytes;
    }

    private function unicode(string $utf8): void
    {
        if (! $this->emits()) {
            return;
        }

        $this->flushSurrogate();
        $this->flushBytes();
        $this->segments[] = [$utf8, null, ! $this->state->htmlTag];
    }

    /**
     * Escape markup characters in decoded text from outside \htmltag destinations.
     *
     * Outlook keeps entities such as &lt; in \htmltag groups, but Exchange
     * decodes them into the text, so "Name <user@example.com>" would otherwise
     * become an unknown element and vanish. Existing entities stay untouched.
     * Escaping runs after decoding, so double-byte characters whose trail byte
     * is '<' or '>' (Johab) stay intact.
     */
    private function escapeText(string $text): string
    {
        if (strpbrk($text, '<>&') === false) {
            return $text;
        }

        $text = (string) preg_replace('/&(?!#[0-9]+;|#[xX][0-9a-fA-F]+;|[a-zA-Z][a-zA-Z0-9]*;)/', '&amp;', $text);

        return str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
    }

    /**
     * Replace a high surrogate that was not followed by its low surrogate.
     */
    private function flushSurrogate(): void
    {
        if ($this->highSurrogate === null) {
            return;
        }

        $this->highSurrogate = null;
        $this->flushBytes();
        $this->segments[] = ["\u{FFFD}", null, false];
    }

    private function flushBytes(): void
    {
        if ($this->pendingBytes === '') {
            return;
        }

        $this->segments[] = [$this->pendingBytes, $this->pendingCodepage, $this->pendingEscape];
        $this->pendingBytes = '';
    }

    private function emits(): bool
    {
        return ! $this->state->skipped && ! $this->state->suppressed;
    }

    /**
     * Decode the collected segments into the final UTF-8 HTML.
     *
     * Some non-Outlook writers store UTF-8 bytes while declaring a Windows codepage;
     * like the HTML body decoding, valid multi-byte UTF-8 therefore wins.
     */
    private function assemble(): string
    {
        $this->flushSurrogate();
        $this->flushBytes();

        $bytes = '';
        foreach ($this->segments as [$segment, $codepage]) {
            $bytes .= $codepage === null ? '' : $segment;
        }

        $isUtf8 = preg_match('/[\x80-\xFF]/', $bytes) === 1 && mb_check_encoding($bytes, 'UTF-8');

        $html = '';
        foreach ($this->segments as [$segment, $codepage, $escape]) {
            $text = $codepage === null || $isUtf8 ? $segment : RtfCodepage::decode($segment, $codepage);
            $html .= $escape ? $this->escapeText($text) : $text;
        }

        return mb_scrub($html, 'UTF-8');
    }
}
