<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The server clock is authoritative. Clients compute their offset from
 * server_time and use it for countdowns; they never decide timing themselves.
 */
final class ServerTime
{
    /** Current server time, UTC, millisecond precision: 2026-10-16T10:00:00.123Z */
    public static function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Formats a stored timestamp as ISO-8601 UTC, or null. */
    public static function format(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{meta: array{server_time: string}}
     */
    public static function meta(): array
    {
        return ['meta' => ['server_time' => self::now()]];
    }
}
