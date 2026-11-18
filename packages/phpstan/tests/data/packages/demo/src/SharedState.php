<?php

declare(strict_types=1);

namespace Demo;

final class SharedState
{
    public static int $counter = 0;

    public const int LIMIT = 10;

    private int $instanceCounter = 0;

    public function increment(): int
    {
        global $config;
        static $calls = 0;
        $calls++;
        $this->instanceCounter++;

        return $calls + self::LIMIT + (is_int($config) ? $config : 0);
    }
}
