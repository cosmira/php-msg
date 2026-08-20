<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

enum MessagePriority: int
{
    case NonUrgent = 0;
    case Normal = 1;
    case Urgent = 2;
}
