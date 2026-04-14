<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Exception;

/**
 * Thrown when a Compound File contains structurally invalid or corrupt data
 * (bad signature, invalid FAT, circular chains, etc.).
 */
class CorruptedFileException extends ParseException {}
