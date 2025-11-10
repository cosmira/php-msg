<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

enum PropertySource: string
{
    case Stream = 'stream';
    case Property = 'property';
}
