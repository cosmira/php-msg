<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Entry;

enum PropertyKind: int
{
    case Numerical = 0;
    case String = 1;
}

