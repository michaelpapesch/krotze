<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A heartbeat for Laravel's scheduler.
 *
 * Whether cron is driving `schedule:run` is otherwise unknowable from inside
 * the app: a scheduler that never runs looks exactly like one whose tasks are
 * not due yet, and on a jailed host the crontab lives somewhere you cannot
 * read. So the scheduler stamps the time every minute, and the admin panel
 * reports whether that stamp is fresh.
 *
 * It matters because retention has two drivers: the scheduler, and a lazy
 * hourly sweep paid for by whoever polls the API first. The lazy path works,
 * but only while somebody is using the app — a quiet server stops cleaning up.
 */
class Scheduler
{
    private const KEY = 'scheduler.heartbeat';

    /**
     * How long a stamp stays convincing. A minute-by-minute cron gives plenty
     * of margin; this is loose enough to survive one slow tick or a deploy that
     * cleared the cache moments ago.
     */
    public const STALE_AFTER_MINUTES = 5;

    /** Called by the scheduler itself, every minute. */
    public static function beat(): void
    {
        // Kept well beyond the staleness window so "last seen three days ago"
        // stays reportable instead of silently becoming "never".
        Cache::put(self::KEY, now()->toIso8601String(), now()->addWeek());
    }

    public static function lastRun(): ?Carbon
    {
        $at = Cache::get(self::KEY);

        try {
            return $at ? Carbon::parse($at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isAlive(): bool
    {
        return self::lastRun()?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES)) ?? false;
    }
}
