<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile\Directory;

enum ObjectType: int
{
    case Unknown = 0x00;
    case Storage = 0x01;
    case Stream = 0x02;
    case RootStorage = 0x05;
}
