<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

enum PropertySource: string
{
    case Stream = 'stream';
    case Property = 'property';
}
