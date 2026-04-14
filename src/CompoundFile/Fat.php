<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Cosmira\OutlookMessage\Support\BinaryBuffer;

/**
 * FAT (File Allocation Table) — это таблица, описывающая связи между секторами в Compound File Binary Format (CFBF).
 * Каждый элемент FAT указывает, какой сектор идёт следующим в цепочке или содержит специальное значение:
 * - FREE_SECTOR (0xFFFFFFFF): свободный сектор;
 * - END_OF_CHAIN (0xFFFFFFFE): конец цепочки;
 * - DIFAT_SECTOR (0xFFFFFFFC): сектор, содержащий расширенную таблицу FAT (DIFAT);
 * - FAT_SECTOR (0xFFFFFFFD): сектор FAT.
 *
 * Этот класс отвечает за сбор и объединение всех FAT-записей из указанных DIFAT-секторов.
 */
final class Fat
{
    /** Размер одной FAT-записи в байтах (32-битное целое). */
    private const FAT_ENTRY_SIZE = 4;

    /**
     * Собирает все FAT-записи из указанных DIFAT-секторов.
     *
     * @param BinaryBuffer    $buffer Буфер бинарных данных.
     * @param Header          $header Заголовок Compound File.
     * @param array<int, int> $difat  Список секторов, содержащих FAT-таблицы.
     *
     * @return array<int, int> Полная таблица FAT, где каждый элемент указывает на следующий сектор или служебное значение.
     */
    public static function collect(BinaryBuffer $buffer, Header $header, array $difat): array
    {
        $entriesPerSector = Util::fatSectorSize($header);
        $fatEntries = [];

        foreach ($difat as $sector) {
            $sectorOffset = Util::sectorOffset($sector, $header->sectorSize);

            // Считываем все записи FAT из одного сектора
            $sectorEntries = self::readFatEntriesFromSector($buffer, $sectorOffset, $entriesPerSector);
            array_push($fatEntries, ...$sectorEntries);
        }

        return $fatEntries;
    }

    /**
     * Считывает все FAT-записи из одного сектора.
     *
     * @param BinaryBuffer $buffer       Буфер с бинарными данными.
     * @param int          $offset       Смещение начала сектора.
     * @param int          $entriesCount Количество FAT-записей в секторе.
     *
     * @return array<int, int> FAT-записи, считанные из сектора.
     */
    private static function readFatEntriesFromSector(BinaryBuffer $buffer, int $offset, int $entriesCount): array
    {
        $entries = [];

        for ($i = 0; $i < $entriesCount; $i++) {
            $entries[] = $buffer->getUint32($offset);
            $offset += self::FAT_ENTRY_SIZE;
        }

        return $entries;
    }
}
