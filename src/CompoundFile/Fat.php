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
    /**
     * Размер одной FAT-записи в байтах (32-битное целое).
     */
    private const FAT_ENTRY_SIZE = 4;

    /**
     * Собирает все FAT-записи из указанных DIFAT-секторов.
     *
     * @param BinaryBuffer    $buffer Буфер бинарных данных.
     * @param Header          $header Заголовок Compound File.
     * @param array<int, int> $difat  Список секторов, содержащих FAT-таблицы.
     *
     * @return PackedFatTable Полная таблица FAT, где каждый элемент указывает на следующий сектор или служебное значение.
     */
    public static function collect(BinaryBuffer $buffer, Header $header, array $difat): PackedFatTable
    {
        $entriesPerSector = Util::fatSectorSize($header);
        $fatEntries = '';

        foreach ($difat as $sector) {
            $sectorOffset = Util::sectorOffset($sector, $header->sectorSize);
            $fatEntries .= $buffer->slice($sectorOffset, $entriesPerSector * self::FAT_ENTRY_SIZE);
        }

        return new PackedFatTable($fatEntries);
    }
}
