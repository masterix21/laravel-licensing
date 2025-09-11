<?php

namespace LucaLongo\Licensing\Enums;

enum OverLimitPolicy: string
{
    case Reject = 'reject';
    case AutoReplaceOldest = 'auto_replace_oldest';
}
