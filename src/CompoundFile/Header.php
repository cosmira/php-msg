<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

/**
 * Представляет заголовок Compound File Binary Format (CFBF),
 * используемого в Microsoft Office (форматы .doc, .xls, .msg и др.).
 *
 * Заголовок содержит метаданные, описывающие
 * структуру файла:
 * размеры секторов, количество FAT/DIFAT-секторов,
 * расположение каталогов и пр.
 */
final readonly class Header
{
    /**
     * Ожидаемая сигнатура Compound File.
     */
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * Указывает на пустую/свободную запись DIFAT.
     */
    private const FREE_SECTOR = 0xFFFFFFFF;

    /**
     * Byte length of the inline DIFAT array in the compound-file header.
     *
     * Размер блока DIFAT в заголовке (в байтах).
     */
    private const DIFAT_BLOCK_SIZE = 436;

    /**
     * Retain the validated fields decoded from a compound-file header.
     *
     * @param int[] $signature
     * @param int[] $difat
     */
    private function __construct(
        /**
         * The eight-byte compound-file signature.
         */
        public array $signature,
        /**
         * The compound-file minor version.
         */
        public int $minorVersion,
        /**
         * The compound-file major version.
         */
        public int $majorVersion,
        /**
         * The byte-order marker from the header.
         */
        public int $byteOrder,
        /**
         * The regular sector size in bytes.
         */
        public int $sectorSize,
        /**
         * The mini-sector size in bytes.
         */
        public int $miniSectorSize,
        /**
         * The number of directory sectors.
         */
        public int $numberOfDirectorySectors,
        /**
         * The number of FAT sectors.
         */
        public int $numberOfFatSectors,
        /**
         * The first directory-sector index.
         */
        public int $firstDirSectorLocation,
        /**
         * The transaction signature number.
         */
        public int $transactionSignatureNumber,
        /**
         * The stream-size threshold for MiniFAT storage.
         */
        public int $miniStreamCutOffSize,
        /**
         * The first MiniFAT-sector index.
         */
        public int $firstMiniFatSectorLocation,
        /**
         * The number of MiniFAT sectors.
         */
        public int $numberOfMiniFatSectors,
        /**
         * The first extended-DIFAT-sector index.
         */
        public int $firstDifatSectorLocation,
        /**
         * The number of extended DIFAT sectors.
         */
        public int $numberOfDifatSectors,
        /**
         * The DIFAT entries stored directly in the header.
         */
        public array $difat
    ) {}

    /**
     * Parse and validate a compound-file header.
     */
    public static function parse(BinaryBuffer $buffer): self
    {
        self::validateSignature($buffer);
        self::validateFormat($buffer);
        $majorVersion = $buffer->getUint16(26);
        $directorySectors = $buffer->getUint32(40);
        throw_if(
            $majorVersion === 3 && $directorySectors !== 0,
            CorruptedFileException::class,
            'Version 3 compound files must declare zero directory sectors.',
        );
        $miniCutoff = $buffer->getUint32(56);
        throw_if(
            $miniCutoff !== 4096,
            CorruptedFileException::class,
            sprintf(
                'Invalid mini stream cutoff size: %s. Must be 4096 per MS-CFB spec.',
                $miniCutoff,
            ),
        );

        // MS-CFB assigns fixed byte offsets to all fields in its 512-byte header.
        return new self(
            array_map(ord(...), str_split(self::SIGNATURE)),
            $buffer->getUint16(24),
            $majorVersion,
            $buffer->getUint16(28),
            2 ** $buffer->getUint16(30),
            2 ** $buffer->getUint16(32),
            $directorySectors,
            $buffer->getUint32(44),
            $buffer->getUint32(48),
            $buffer->getUint32(52),
            $miniCutoff,
            $buffer->getUint32(60),
            $buffer->getUint32(64),
            $buffer->getUint32(68),
            $buffer->getUint32(72),
            self::readDifatEntries($buffer, 76),
        );
    }

    /**
     * Validate the byte order, format version and sector widths before reading records.
     */
    private static function validateFormat(BinaryBuffer $buffer): void
    {
        $majorVersion = $buffer->getUint16(26);
        if (! in_array($majorVersion, [3, 4], true)) {
            throw new CorruptedFileException(sprintf(
                'Unsupported compound-file major version: %d.',
                $majorVersion,
            ));
        }

        $byteOrder = $buffer->getUint16(28);
        if ($byteOrder !== 0xFFFE) {
            throw new CorruptedFileException(sprintf(
                'Invalid byte order: 0x%04X. Expected 0xFFFE (little-endian).',
                $byteOrder,
            ));
        }

        $sectorShift = $buffer->getUint16(30);
        $invalidShift = ($majorVersion === 3 && $sectorShift !== 9)
            || ($majorVersion === 4 && $sectorShift !== 12);

        if ($invalidShift) {
            throw new CorruptedFileException(sprintf(
                'Invalid sector size shift (%d) for version %s.',
                $sectorShift,
                $majorVersion,
            ));
        }

        $miniSectorShift = $buffer->getUint16(32);
        if ($miniSectorShift !== 6) {
            throw new CorruptedFileException(sprintf(
                'Invalid mini sector size shift (%s), expected 6.',
                $miniSectorShift,
            ));
        }

    }

    /**
     * Проверяет сигнатуру Compound File.
     *
     *
     * @throws \Throwable
     */
    private static function validateSignature(BinaryBuffer $buffer): void
    {
        $signature = $buffer->slice(0, 8);

        throw_if(
            $signature !== self::SIGNATURE,
            CorruptedFileException::class,
            'Invalid compound file signature.',
        );
    }

    /**
     * Считывает DIFAT-записи из заголовка.
     *
     * В заголовке хранится максимум 109 записей (109 * 4 = 436
     * байт).
     * Каждая запись — это 32-битное значение, указывающее
     * на FAT-сектор.
     *
     * @return int[]
     */
    private static function readDifatEntries(BinaryBuffer $buffer, int $offset): array
    {
        $entries = [];
        /** @var array<int, int> $values */
        $values = unpack('V*', $buffer->slice($offset, self::DIFAT_BLOCK_SIZE));

        foreach ($values as $value) {

            if ($value === self::FREE_SECTOR) {
                break;
            }

            $entries[] = $value;
        }

        return $entries;
    }
}
