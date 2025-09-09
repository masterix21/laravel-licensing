<?php

namespace LucaLongo\Licensing\Enums;

enum KeyType: string
{
    case Root = 'root';
    case Signing = 'signing';
}