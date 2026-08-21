<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;

final class Decoder
{
    /** @var array<int, int> */
    private array $dictionary;

    private int $dictionaryWrite;

    private int $dictionaryEnd;

    private string $output;

    private int $outputSize = 0;

    private int $iterations = 0;

    public function __construct(
        private readonly string $binary,
        private readonly int $rawSize,
        private int $offset,
    ) {
        $this->dictionary = Dictionary::seed();
        $this->dictionaryWrite = Dictionary::seedLength();
        $this->dictionaryEnd = $this->dictionaryWrite;
        $this->output = str_repeat("\0", $rawSize);
    }

    public function decode(): string
    {
        while ($this->shouldContinue()) {
            $this->guardIterations();
            $this->readGroup();
        }

        return substr($this->output, 0, $this->outputSize);
    }

    private function hasInput(): bool
    {
        return $this->offset < strlen($this->binary);
    }

    private function hasOutputCapacity(): bool
    {
        return $this->outputSize < $this->rawSize;
    }

    private function shouldContinue(): bool
    {
        return $this->hasInput() && $this->hasOutputCapacity();
    }

    private function guardIterations(): void
    {
        $this->iterations++;

        throw_if(
            $this->iterations > 10_000_000,
            CorruptedFileException::class,
            'RTF decompression exceeded maximum iteration count.',
        );
    }

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

        foreach (range(1, $length) as $_) {
            $hasCapacity = $this->hasOutputCapacity();

            if (! $hasCapacity) {
                return;
            }

            $this->writeByte($this->dictionary[$readOffset]);
            $readOffset = ($readOffset + 1) % count($this->dictionary);
        }
    }

    private function readByte(): int
    {
        return ord($this->binary[$this->offset++]);
    }

    private function writeByte(int $byte): void
    {
        $this->dictionary[$this->dictionaryWrite] = $byte;
        $this->dictionaryWrite++;
        $this->dictionaryEnd = max($this->dictionaryEnd, min($this->dictionaryWrite, count($this->dictionary)));
        $this->dictionaryWrite %= count($this->dictionary);
        $this->output[$this->outputSize++] = chr($byte & 0xFF);
    }
}
