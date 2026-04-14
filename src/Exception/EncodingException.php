<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Exception;

/**
 * Thrown when a string property cannot be decoded with the advertised or
 * fallback character encoding.
 */
class EncodingException extends ParseException {}
