<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Received = 'received';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';
}
