<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use Brick\Math\BigInteger;
use MsgViewer\Exception\CorruptedFileException;

/**
 * Class Util
 *
 * Вспомогательные методы для работы с Compound File Binary Format (CFBF).
 * Содержит функции для вычисления смещений секторов и определения размеров таблиц.
 */
final class Util
{
    /** Количество FAT-записей на сектор в версии 3. */
    private const FAT_ENTRIES_V3 = 128;

    /** Количество FAT-записей на сектор в версии 4. */
    private const FAT_ENTRIES_V4 = 1024;

    /**
     * Возвращает смещение сектора в основном потоке файла.
     *
     * Каждый сектор (кроме заголовка) начинается после 512-байтного блока заголовка,
     * поэтому смещение вычисляется как `(sector + 1) * sectorSize`.
     */
    public static function sectorOffset(int $sector, int $sectorSize): int
    {
        if ($sectorSize > 0 && $sector >= PHP_INT_MAX / $sectorSize) {
            throw new CorruptedFileException(sprintf('Sector offset would overflow: sector=%d, sectorSize=%d.', $sector, $sectorSize));
        }

        return ($sector + 1) * $sectorSize;
    }

    /**
     * Вычисляет смещение сектора потока данных.
     *
     * Если размер потока меньше порогового значения `miniStreamCutOffSize`,
     * то данные хранятся в MiniStream (мини-потоке), и смещение вычисляется по-другому.
     *
     * @param int        $sector              Индекс сектора в потоке.
     * @param Header     $header              Заголовок Compound File.
     * @param BigInteger $streamSize          Размер потока.
     * @param int[]      $miniStreamLocations Номера секторов, где хранится MiniStream.
     *
     * @return int Смещение в байтах относительно начала файла.
     */
    public static function streamSectorOffset(
        int $sector,
        Header $header,
        BigInteger $streamSize,
        array $miniStreamLocations
    ): int {
        // По умолчанию — смещение сектора в основном потоке
        $offset = self::sectorOffset($sector, $header->sectorSize);

        // Для мини-потоков (меньше 4096 байт) используется MiniStream
        if ($streamSize->isLessThan($header->miniStreamCutOffSize)) {
            // Смещение относительно начала MiniStream
            $offset = $sector * $header->miniSectorSize;

            // Определяем, в каком секторе MiniStream находится нужный мини-сектор
            $miniStreamSector = intdiv($offset, $header->sectorSize);
            $miniStreamOffset = $offset % $header->sectorSize;

            // Находим физический сектор MiniStream по FAT-цепочке
            $miniSector = $miniStreamLocations[$miniStreamSector] ?? null;
            if ($miniSector === null) {
                // Если MiniStream не найден, возвращаем локальное смещение
                return $offset;
            }

            // Итоговое смещение — смещение MiniStream + внутренний сдвиг
            $offset = self::sectorOffset($miniSector, $header->sectorSize) + $miniStreamOffset;
        }

        return $offset;
    }

    /**
     * Возвращает количество FAT-записей, которые помещаются в один сектор,
     * в зависимости от версии формата Compound File.
     *
     * - Версия 3: сектор = 512 байт, 128 FAT-записей.
     * - Версия 4: сектор = 4096 байт, 1024 FAT-записей.
     */
    public static function fatSectorSize(Header $header): int
    {
        return $header->majorVersion === 3
            ? self::FAT_ENTRIES_V3
            : self::FAT_ENTRIES_V4;
    }
}
