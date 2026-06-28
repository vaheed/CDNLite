<?php

namespace App\Services\ControlPlane;

final class UnixTime
{
    public static function now(): int
    {
        return time();
    }
}
