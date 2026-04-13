<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use MsgViewer\Support\BinaryBuffer;
use RuntimeException;

/**
 * Представляет заголовок Compound File Binary Format (CFBF),
 * используемого в Microsoft Office (форматы .doc, .xls, .msg и др.).
 *
 * Заголовок содержит метаданные, описывающие структуру файла:
 * размеры секторов, количество FAT/DIFAT-секторов, расположение каталогов и пр.
 */
final class Header
{
    /** Ожидаемая сигнатура Compound File. */
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Указывает на пустую/свободную запись DIFAT. */
    private const FREE_SECTOR = 0xFFFFFFFF;

    /** Размер блока DIFAT в заголовке (в байтах). */
    private const DIFAT_BLOCK_SIZE = 436;

    /** Количество байт, используемых для одного uint32. */
    private const UINT32_SIZE = 4;

    /**
     * @param int[] $signature
     * @param int[] $difat
     */
    private function __construct(
        public readonly array $signature,
        public readonly int $minorVersion,
        public readonly int $majorVersion,
        public readonly int $byteOrder,
        public readonly int $sectorSize,
        public readonly int $miniSectorSize,
        public readonly int $numberOfDirectorySectors,
        public readonly int $numberOfFatSectors,
        public readonly int $firstDirSectorLocation,
        public readonly int $transactionSignatureNumber,
        public readonly int $miniStreamCutOffSize,
        public readonly int $firstMiniFatSectorLocation,
        public readonly int $numberOfMiniFatSectors,
        public readonly int $firstDifatSectorLocation,
        public readonly int $numberOfDifatSectors,
        public readonly array $difat
    ) {}

    public static function parse(BinaryBuffer $buffer): self
    {
        self::validateSignature($buffer);

        $offset = 8;

        // Header CLSID (16 bytes) ignored
        $offset += 16;

        $minorVersion = $buffer->getUint16($offset);
        $offset += 2;

        $majorVersion = $buffer->getUint16($offset);
        $offset += 2;

        $byteOrder = $buffer->getUint16($offset);
        $offset += 2;

        $sectorSize = 2 ** $buffer->getUint16($offset);
        $offset += 2;

        $miniSectorSize = 2 ** $buffer->getUint16($offset);
        $offset += 2;

        // Reserved (6 bytes)
        $offset += 6;

        $numberOfDirectorySectors = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfFatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $firstDirSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $transactionSignatureNumber = $buffer->getUint32($offset);
        $offset += 4;

        $miniStreamCutOffSize = $buffer->getUint32($offset);
        $offset += 4;

        $firstMiniFatSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfMiniFatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $firstDifatSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfDifatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $difat = self::readDifatEntries($buffer, $offset);

        return new self(
            array_map(static fn (string $byte): int => ord($byte), str_split(self::SIGNATURE)),
            $minorVersion,
            $majorVersion,
            $byteOrder,
            $sectorSize,
            $miniSectorSize,
            $numberOfDirectorySectors,
            $numberOfFatSectors,
            $firstDirSectorLocation,
            $transactionSignatureNumber,
            $miniStreamCutOffSize,
            $firstMiniFatSectorLocation,
            $numberOfMiniFatSectors,
            $firstDifatSectorLocation,
            $numberOfDifatSectors,
            $difat
        );
    }

    /**
     * Проверяет сигнатуру Compound File.
     *
     * @throws RuntimeException Если сигнатура не совпадает с ожидаемой.
     */
    private static function validateSignature(BinaryBuffer $buffer): void
    {
        $signature = $buffer->slice(0, 8);

        if ($signature !== self::SIGNATURE) {
            throw new RuntimeException('Invalid compound file signature.');
        }
    }

    /**
     * Считывает DIFAT-записи из заголовка.
     *
     * В заголовке хранится максимум 109 записей (109 * 4 = 436 байт).
     * Каждая запись — это 32-битное значение, указывающее на FAT-сектор.
     */
    private static function readDifatEntries(BinaryBuffer $buffer, int $offset): array
    {
        $entries = [];

        for ($i = 0; $i < self::DIFAT_BLOCK_SIZE; $i += self::UINT32_SIZE) {
            $value = $buffer->getUint32($offset);

            if ($value === self::FREE_SECTOR) {
                // Пропускаем оставшиеся байты блока DIFAT
                $offset += (self::DIFAT_BLOCK_SIZE - $i);
                break;
            }

            $entries[] = $value;
            $offset += self::UINT32_SIZE;
        }

        return $entries;
    }
}
