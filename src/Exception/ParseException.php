<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Exception;

use RuntimeException;

/**
 * Thrown when a .msg / Compound File structure cannot be interpreted.
 */
class ParseException extends RuntimeException {}
