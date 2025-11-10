<?php

declare(strict_types=1);

namespace MsgViewer\Rtf\Decompressor;

enum CompType: int
{
    case Compressed = 0;
    case Uncompressed = 1;
}
