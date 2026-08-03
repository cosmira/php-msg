<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

/**
 * MiniFAT (Mini File Allocation Table) — таблица, описывающая распределение мини-секторов,
 * которые используются для хранения небольших потоков (до 4096 байт).
 *
 * MiniFAT строится аналогично обычной FAT, но работает поверх MiniStream.
 */
final class MiniFat
{
    /**
     * Указывает на конец цепочки (End Of Chain).
     */
    private const END_OF_CHAIN = 0xFFFFFFFE;

    /**
     * Размер одной FAT-записи (в байтах).
     */
    private const FAT_ENTRY_SIZE = 4;

    /**
     * Собирает MiniFAT из цепочки секторов, используя FAT для навигации.
     *
     * @param BinaryBuffer    $buffer Буфер, предоставляющий доступ к бинарным данным.
     * @param Header          $header Заголовок Compound File.
     * @param array<int, int> $fat    Основная FAT-таблица, используемая для определения следующего сектора.
     *
     * @throws \Throwable
     *
     * @return array<int, int> Таблица MiniFAT, где каждая запись — 32-битное значение,
     *                         указывающее на следующий мини-сектор или служебное значение.
     */
    public static function collect(BinaryBuffer $buffer, Header $header, array $fat): array
    {
        $entriesPerSector = Util::fatSectorSize($header);
        $miniFatEntries = [];
        $currentSector = $header->firstMiniFatSectorLocation;
        $visited = [];

        // Читаем все цепочки MiniFAT-секторов, пока не достигнут конец
        while ($currentSector < self::END_OF_CHAIN) {
            throw_if(isset($visited[$currentSector]), CorruptedFileException::class, 'Circular reference detected in MiniFAT chain.');

            $visited[$currentSector] = true;
            $sectorOffset = Util::sectorOffset($currentSector, $header->sectorSize);

            // Считываем записи MiniFAT из одного сектора
            $sectorEntries = self::readMiniFatEntriesFromSector($buffer, $sectorOffset, $entriesPerSector);
            array_push($miniFatEntries, ...$sectorEntries);

            // Определяем следующий сектор по основной FAT
            $currentSector = $fat[$currentSector] ?? self::END_OF_CHAIN;
        }

        return $miniFatEntries;
    }

    /**
     * Считывает все MiniFAT-записи из одного сектора.
     *
     * @param BinaryBuffer $buffer       Буфер бинарных данных.
     * @param int          $offset       Смещение начала сектора.
     * @param int          $entriesCount Количество FAT-записей в одном секторе.
     *
     * @return array<int, int> Список 32-битных MiniFAT-записей.
     */
    private static function readMiniFatEntriesFromSector(BinaryBuffer $buffer, int $offset, int $entriesCount): array
    {
        $entries = [];

        for ($i = 0; $i < $entriesCount; $i++) {
            $entries[] = $buffer->getUint32($offset);
            $offset += self::FAT_ENTRY_SIZE;
        }

        return $entries;
    }
}
