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
     * Encoding used for decoded message text.
     */
    private const UTF8 = 'UTF-8';

    /**
     * Unicode substitution for malformed UTF-16 code units.
     */
    private const REPLACEMENT_CHARACTER = "\u{FFFD}";

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

    /**
     * Encoded bytes awaiting a codepage or escaping-context boundary.
     */
    private string $pendingBytes = '';

    /**
     * The Windows codepage associated with the pending encoded bytes.
     */
    private int $pendingCodepage = 1252;

    /**
     * Whether pending bytes represent text that requires HTML escaping.
     */
    private bool $pendingEscape = false;

    /**
     * The default Windows codepage for encoded text.
     */
    private int $codepage = 1252;

    /**
     * The document's default RTF font identifier.
     */
    private int $defaultFont = 0;

    /**
     * Windows codepages indexed by font-table identifier.
     *
     * @var array<int, int>
     */
    private array $fontCodepages = [];

    /**
     * The font identifier currently being defined in the font table.
     */
    private ?int $fontDefinition = null;

    /**
     * Whether the RTF declares encapsulated HTML content.
     */
    private bool $fromHtml = false;

    /**
     * An unmatched high UTF-16 surrogate awaiting its low surrogate.
     */
    private ?int $highSurrogate = null;

    /**
     * The remaining ANSI fallback characters to skip after a Unicode escape.
     */
    private int $pendingSkip = 0;

    /**
     * The group-scoped RTF parsing and emission context.
     */
    private RtfHtmlExtractorState $state;

    /**
     * Saved parser contexts for open RTF groups.
     *
     * @var RtfHtmlExtractorState[]
     */
    private array $stack = [];

    /**
     * Initialize parser state for a decompressed RTF document.
     */
    private function __construct(/**
         * The decompressed RTF document being scanned.
         */
        private readonly string $rtf)
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

    /**
     * Scan RTF tokens and assemble the recovered encapsulated HTML.
     */
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
        // RTF 1.9.1, Unicode RTF: an unescaped group delimiter ends fallback skipping.
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
            // The control word and its entire binary payload count as one fallback character.
            $this->consumeSkip();

            return $next + max(0, $parameter ?? 0);
        }

        $this->controlWord($word, $parameter);

        return $next;
    }

    /**
     * Interpret an escaped character or an RTF control symbol.
     */
    private function controlSymbol(string $symbol, int $next): int
    {
        if ($symbol === '*') {
            $this->state->ignorable = true;

            return $next;
        }

        if ($symbol === "'") {
            return $this->hexSymbol($next);
        }

        if ($this->consumeSkip()) {
            return $next;
        }

        match ($symbol) {
            '{', '}', '\\' => $this->bytes($symbol),
            '~'            => $this->unicode("\u{00A0}"),
            '_'            => $this->unicode("\u{2011}"),
            '-'            => $this->unicode("\u{00AD}"),
            "\r", "\n"     => $this->bytes("\r\n"),
            default        => null,
        };

        return $next;
    }

    /**
     * Decode a complete hexadecimal escape without consuming malformed escape tails.
     */
    private function hexSymbol(int $next): int
    {
        $hex = substr($this->rtf, $next, 2);

        if (strlen($hex) === 2 && ctype_xdigit($hex)) {
            $this->bytes(chr((int) hexdec($hex) & 0xFF));

            return $next + 2;
        }

        return $next;
    }

    /**
     * Update parser state or emit text for an RTF control word.
     */
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
            default         => isset(self::SYMBOLS[$word])
                ? $this->unicode(self::SYMBOLS[$word]) : null,
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

    /**
     * Associate the active font definition with its Windows codepage.
     */
    private function fontCharset(?int $charset): void
    {
        $codepage = $charset !== null ? RtfCodepage::forCharset($charset) : null;

        if ($this->state->fontTable && $this->fontDefinition !== null && $codepage !== null) {
            $this->fontCodepages[$this->fontDefinition] = $codepage;
        }
    }

    /**
     * Consume Unicode fallback bytes before appending an ordinary text run.
     */
    private function text(string $text): void
    {
        $remaining = $text;
        if ($this->pendingSkip > 0) {
            $skipped = min($this->pendingSkip, strlen($remaining));
            $this->pendingSkip -= $skipped;
            $remaining = substr($remaining, $skipped);
        }

        $this->state->destinationPending = false;

        if ($remaining !== '') {
            $this->bytes($remaining);
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

        if ($value < -32768 || $value > 0xFFFF) {
            $this->unicode(self::REPLACEMENT_CHARACTER);

            return;
        }

        // UTF-16 reserves distinct ten-bit payload ranges for high and low surrogates.
        $surrogateType = $codeUnit & 0xFC00;
        if ($surrogateType === 0xD800) {
            $this->flushSurrogate();
            $this->highSurrogate = $codeUnit;

            return;
        }

        if ($surrogateType === 0xDC00) {
            $this->lowSurrogate($codeUnit);

            return;
        }

        $this->unicode(mb_chr($codeUnit, self::UTF8));
    }

    /**
     * Complete a surrogate pair, substituting a low surrogate without a preceding high one.
     */
    private function lowSurrogate(int $codeUnit): void
    {
        $high = $this->highSurrogate;
        $this->highSurrogate = null;

        $this->unicode($high === null
            ? self::REPLACEMENT_CHARACTER
            : mb_chr(0x10000 + (($high - 0xD800) << 10) + ($codeUnit - 0xDC00), self::UTF8));

    }

    /**
     * Append encoded text using the active font and escaping context.
     */
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

        $font = $this->state->font ?? $this->defaultFont;
        $codepage = $this->fontCodepages[$font] ?? $this->codepage;
        $escape = ! $this->state->htmlTag;
        if ($codepage !== $this->pendingCodepage || $escape !== $this->pendingEscape) {
            $this->flushBytes();
            $this->pendingCodepage = $codepage;
            $this->pendingEscape = $escape;
        }

        $this->pendingBytes .= $bytes;
    }

    /**
     * Append already decoded UTF-8 text in the active escaping context.
     */
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

        $escaped = (string) preg_replace(
            '/&(?!#\d+;|#[xX][0-9a-fA-F]+;|[a-zA-Z][a-zA-Z0-9]*;)/',
            '&amp;',
            $text,
        );

        return str_replace(['<', '>'], ['&lt;', '&gt;'], $escaped);
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
        $this->segments[] = [self::REPLACEMENT_CHARACTER, null, false];
    }

    /**
     * Retain the pending encoded run as a segment before its context changes.
     */
    private function flushBytes(): void
    {
        if ($this->pendingBytes === '') {
            return;
        }

        $this->segments[] = [$this->pendingBytes, $this->pendingCodepage, $this->pendingEscape];
        $this->pendingBytes = '';
    }

    /**
     * Determine whether the active destination contributes to recovered HTML.
     */
    private function emits(): bool
    {
        return ! $this->state->skipped && ! $this->state->suppressed;
    }

    /**
     * Decode the collected segments into the final UTF-8 HTML.
     *
     * Some non-Outlook writers store UTF-8 bytes while declaring a Windows codepage;
     * retain that compatibility heuristic only for Windows-1252 runs. Other
     * codepages, including those selected by Asian fonts, must be decoded as declared.
     */
    private function assemble(): string
    {
        $this->flushSurrogate();
        $this->flushBytes();

        $bytes = '';
        foreach ($this->segments as [$segment, $codepage]) {
            $bytes .= $codepage === 1252 ? $segment : '';
        }

        $hasHighBytes = preg_match('/[\x80-\xFF]/', $bytes) === 1;
        $isUtf8 = $hasHighBytes && mb_check_encoding($bytes, self::UTF8);

        $html = '';
        foreach ($this->segments as [$segment, $codepage, $escape]) {
            $text = $this->decodeSegment($segment, $codepage, $isUtf8);
            $html .= $escape ? $this->escapeText($text) : $text;
        }

        return mb_scrub($html, self::UTF8);
    }

    /**
     * Decode a segment while preserving the Windows-1252 UTF-8 compatibility heuristic.
     */
    private function decodeSegment(string $segment, ?int $codepage, bool $isUtf8): string
    {
        $isWindowsUtf8 = $codepage === 1252 && $isUtf8;
        $retainUtf8 = $codepage === null || $isWindowsUtf8;

        return $retainUtf8 ? $segment : RtfCodepage::decode($segment, $codepage);
    }
}
