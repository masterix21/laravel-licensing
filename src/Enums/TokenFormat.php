<?php

namespace LucaLongo\Licensing\Enums;

enum TokenFormat: string
{
    case Paseto = 'paseto';
    case Jws = 'jws';
}
