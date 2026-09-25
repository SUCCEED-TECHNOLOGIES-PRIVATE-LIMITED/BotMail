<?php

namespace App\Enums;

enum InboxStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
}
