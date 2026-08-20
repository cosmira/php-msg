<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

enum MessageImportance: int
{
    case Low = 0;
    case Normal = 1;
    case High = 2;
}
