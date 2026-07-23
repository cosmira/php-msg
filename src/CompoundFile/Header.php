<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use RuntimeException;

/**
 * Представляет заголовок Compound File Binary Format (CFBF),
 * используемого в Microsoft Office (форматы .doc, .xls, .msg и др.).
 *
 * Заголовок содержит метаданные, описывающие структуру файла:
 * размеры секторов, количество FAT/DIFAT-секторов, расположение каталогов и пр.
 */
final readonly class Header
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
        public array $signature,
        public int $minorVersion,
        public int $majorVersion,
        public int $byteOrder,
        public int $sectorSize,
        public int $miniSectorSize,
        public int $numberOfDirectorySectors,
        public int $numberOfFatSectors,
        public int $firstDirSectorLocation,
        public int $transactionSignatureNumber,
        public int $miniStreamCutOffSize,
        public int $firstMiniFatSectorLocation,
        public int $numberOfMiniFatSectors,
        public int $firstDifatSectorLocation,
        public int $numberOfDifatSectors,
        public array $difat
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
        if ($byteOrder !== 0xFFFE) {
            throw new CorruptedFileException(sprintf('Invalid byte order: 0x%04X. Expected 0xFFFE (little-endian).', $byteOrder));
        }

        $offset += 2;

        $sectorShift = $buffer->getUint16($offset);
        if (($majorVersion === 3 && $sectorShift !== 9) || ($majorVersion === 4 && $sectorShift !== 12)) {
            throw new CorruptedFileException(sprintf('Invalid sector size shift (%d) for version %s.', $sectorShift, $majorVersion));
        }

        $sectorSize = 2 ** $sectorShift;
        $offset += 2;

        $miniSectorShift = $buffer->getUint16($offset);
        if ($miniSectorShift !== 6) {
            throw new CorruptedFileException(sprintf('Invalid mini sector size shift (%s), expected 6.', $miniSectorShift));
        }

        $miniSectorSize = 2 ** $miniSectorShift;
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
        if ($miniStreamCutOffSize !== 4096) {
            throw new CorruptedFileException(sprintf('Invalid mini stream cutoff size: %s. Must be 4096 per MS-CFB spec.', $miniStreamCutOffSize));
        }

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
            array_map(ord(...), str_split(self::SIGNATURE)),
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
     * @param BinaryBuffer $buffer
     * @throws \Throwable
     */
    private static function validateSignature(BinaryBuffer $buffer): void
    {
        $signature = $buffer->slice(0, 8);

        throw_if($signature !== self::SIGNATURE, CorruptedFileException::class, 'Invalid compound file signature.');
    }

    /**
     * Считывает DIFAT-записи из заголовка.
     *
     * В заголовке хранится максимум 109 записей (109 * 4 = 436 байт).
     * Каждая запись — это 32-битное значение, указывающее на FAT-сектор.
     *
     * @return int[]
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
