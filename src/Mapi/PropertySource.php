<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

enum PropertySource: string
{
    case Stream = 'stream';
    case Property = 'property';
}
