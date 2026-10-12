<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;

final class Decoder
{
    /**
     * The decompressor's sliding byte dictionary.
     *
     * @var array<int, int>
     */
    private array $dictionary;

    /**
     * The next dictionary position that receives an output byte.
     */
    private int $dictionaryWrite;

    /**
     * The highest initialized dictionary position.
     */
    private int $dictionaryEnd;

    /**
     * The preallocated decompressed RTF buffer.
     */
    private string $output;

    /**
     * The number of output bytes already produced.
     */
    private int $outputSize = 0;

    /**
     * The decompression steps counted against the work limit.
     */
    private int $iterations = 0;

    /**
     * Prepare the seeded dictionary and bounded output for an RTF payload.
     */
    public function __construct(
        /**
         * The source bytes retained for decoding or round-trip comparison.
         */
        private readonly string $binary,
        /**
         * The declared size of the decompressed output.
         */
        private readonly int $rawSize,
        /**
         * The current byte offset in the compressed payload.
         */
        private int $offset,
    ) {
        $this->dictionary = Dictionary::seed();
        $this->dictionaryWrite = Dictionary::seedLength();
        $this->dictionaryEnd = $this->dictionaryWrite;
        $this->output = str_repeat("\0", $rawSize);
    }

    /**
     * Expand the compressed RTF payload to its declared output size.
     */
    public function decode(): string
    {
        while ($this->shouldContinue()) {
            $this->guardIterations();
            $this->readGroup();
        }

        throw_unless(
            $this->outputSize === $this->rawSize,
            CorruptedFileException::class,
            'Compressed RTF ended before the declared output size was produced.',
        );

        return $this->output;
    }

    /**
     * Determine whether unread compressed bytes remain.
     */
    private function hasInput(): bool
    {
        return $this->offset < strlen($this->binary);
    }

    /**
     * Determine whether the declared output still has available capacity.
     */
    private function hasOutputCapacity(): bool
    {
        return $this->outputSize < $this->rawSize;
    }

    /**
     * Continue decoding only while both input and output capacity remain.
     */
    private function shouldContinue(): bool
    {
        return $this->hasInput() && $this->hasOutputCapacity();
    }

    /**
     * Bound decompression work to reject malformed or nonterminating input.
     */
    private function guardIterations(): void
    {
        $this->iterations++;

        throw_if(
            $this->iterations > 10_000_000,
            CorruptedFileException::class,
            'RTF decompression exceeded maximum iteration count.',
        );
    }

    /**
     * Decode the literal or dictionary-reference tokens selected by one control byte.
     */
    private function readGroup(): void
    {
        $control = $this->readByte();

        foreach (range(0, 7) as $bit) {
            $hasCapacity = $this->hasOutputCapacity();

            if (! $hasCapacity) {
                return;
            }

            $hasInput = $this->hasInput();

            if (! $hasInput) {
                return;
            }

            $isReference = (($control >> $bit) & 1) === 1;

            if ($isReference) {
                $this->copyReference();

                continue;
            }

            $this->writeByte($this->readByte());
        }
    }

    /**
     * Copy a dictionary reference while honoring the bounded output size.
     */
    private function copyReference(): void
    {
        $nextOffset = $this->offset + 1;
        $inputSize = strlen($this->binary);

        if ($nextOffset >= $inputSize) {
            $this->offset = strlen($this->binary);

            return;
        }

        $reference = ($this->readByte() << 8) | $this->readByte();
        $readOffset = ($reference & 0xFFF0) >> 4;

        throw_if(
            $readOffset > $this->dictionaryEnd,
            CorruptedFileException::class,
            'RTF decompression encountered an invalid dictionary reference.',
        );

        if ($readOffset === $this->dictionaryWrite) {
            $this->offset = strlen($this->binary);

            return;
        }

        $length = 2 + ($reference & 0x0F);

        foreach (range(1, $length) as $ignored) {
            $hasCapacity = $this->hasOutputCapacity();

            if (! $hasCapacity) {
                return;
            }

            $this->writeByte($this->dictionary[$readOffset]);
            $readOffset = ($readOffset + 1) % count($this->dictionary);
        }
    }

    /**
     * Consume the next compressed input byte.
     */
    private function readByte(): int
    {
        return ord($this->binary[$this->offset++]);
    }

    /**
     * Append one output byte and update the sliding dictionary.
     */
    private function writeByte(int $byte): void
    {
        $this->dictionary[$this->dictionaryWrite] = $byte;
        $this->dictionaryWrite++;
        $this->dictionaryEnd = max(
            $this->dictionaryEnd,
            min($this->dictionaryWrite, count($this->dictionary)),
        );
        $this->dictionaryWrite %= count($this->dictionary);
        $this->output[$this->outputSize++] = chr($byte & 0xFF);
    }
}
