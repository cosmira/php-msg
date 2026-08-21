<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

/**
 * DIFAT (Double Indirect File Allocation Table) — это структура,
 * используемая в Compound File Binary Format (CFBF), применяемом в Microsoft Office.
 * Она хранит ссылки на сектора FAT (File Allocation Table),
 * а также ссылки на дополнительные DIFAT-сектора.
 *
 * Задача класса — собрать полный список FAT-секторов, включая те,
 * что указаны в дополнительных DIFAT-секторах.
 */
final class Difat
{
    /**
     * Специальное значение, указывающее на конец цепочки (End Of Chain).
     */
    private const END_OF_CHAIN = 0xFFFFFFFE;

    /**
     * Специальное значение, указывающее на свободный сектор (Free Sector).
     */
    private const FREE_SECTOR = 0xFFFFFFFF;

    /**
     * Максимальное количество FAT-ссылок, хранимых в одном DIFAT-секторе
     * (зависит от версии формата).
     */
    private const MAX_FAT_ENTRIES_V3 = 127;

    private const MAX_FAT_ENTRIES_V4 = 1023;

    /**
     * Собирает все сектора FAT, включая те, что определены в DIFAT-секторах.
     *
     * @param BinaryBuffer $buffer Буфер, предоставляющий доступ к бинарным данным.
     * @param Header       $header Заголовок файла, содержащий основные метаданные.
     *
     * @return array<int, int> Список всех номеров FAT-секторов.
     */
    public static function collect(BinaryBuffer $buffer, Header $header): array
    {
        $fatSectors = $header->difat;

        // Количество FAT-записей в одном DIFAT-секторе зависит от версии формата
        $capacity = $header->majorVersion === 3
            ? self::MAX_FAT_ENTRIES_V3
            : self::MAX_FAT_ENTRIES_V4;

        // Указатель на первый DIFAT-сектор (если есть)
        $currentSector = $header->firstDifatSectorLocation;
        $visited = [];
        $sectorsRead = 0;

        // Пока не достигнут конец цепочки DIFAT
        while ($currentSector < self::END_OF_CHAIN) {
            throw_if(isset($visited[$currentSector]), CorruptedFileException::class, 'Circular reference detected in DIFAT chain.');
            throw_if(
                $sectorsRead >= $header->numberOfDifatSectors,
                CorruptedFileException::class,
                'DIFAT chain exceeds the sector count declared in the header.',
            );

            $visited[$currentSector] = true;
            $sectorsRead++;
            $offset = Util::sectorOffset($currentSector, $header->sectorSize);

            // Читаем FAT-записи из текущего DIFAT-сектора
            [$entries, $nextSector] = self::readFatEntriesFromSector(
                $buffer,
                $offset,
                $capacity
            );

            array_push($fatSectors, ...$entries);

            $currentSector = $nextSector;
        }

        throw_if(
            $sectorsRead !== $header->numberOfDifatSectors,
            CorruptedFileException::class,
            'DIFAT chain length does not match the sector count declared in the header.',
        );

        $fatSectors = array_values(array_filter(
            $fatSectors,
            static fn (int $sector): bool => $sector !== self::FREE_SECTOR,
        ));
        throw_if(
            count($fatSectors) !== $header->numberOfFatSectors,
            CorruptedFileException::class,
            'FAT sector count does not match the value declared in the header.',
        );
        throw_if(
            count(array_unique($fatSectors)) !== count($fatSectors),
            CorruptedFileException::class,
            'Duplicate FAT sector references were found in the DIFAT.',
        );

        return $fatSectors;
    }

    /**
     * Считывает все FAT-записи из одного DIFAT-сектора.
     *
     * @param BinaryBuffer $buffer       Буфер с бинарными данными.
     * @param int          $offset       Смещение текущего сектора в файле.
     * @param int          $entriesCount Количество записей FAT в одном DIFAT-секторе.
     *
     * @return array{0: array<int, int>, 1: int} Возвращает массив с двумя элементами:
     *                                           [список FAT-записей, номер следующего DIFAT-сектора].
     */
    private static function readFatEntriesFromSector(
        BinaryBuffer $buffer,
        int $offset,
        int $entriesCount
    ): array {

        /** @var array<int, int> $values */
        $values = unpack('V*', $buffer->slice($offset, ($entriesCount + 1) * 4));
        $nextSector = array_pop($values) ?? self::END_OF_CHAIN;
        $entries = [];
        foreach ($values as $entry) {
            if ($entry === self::FREE_SECTOR) {
                break;
            }

            $entries[] = $entry;
        }

        return [$entries, $nextSector];
    }
}
