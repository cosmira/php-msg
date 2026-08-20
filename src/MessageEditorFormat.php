<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

enum MessageEditorFormat: int
{
    case Unknown = 0;
    case PlainText = 1;
    case Html = 2;
    case Rtf = 3;
}
