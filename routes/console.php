<?php

use App\Support\Scheduler;
use Illuminate\Support\Facades\Schedule;

// Hourly, not daily: the lazy fallback this replaces ran every hour, and
// file-deletion tombstones are only meant to live for one. Everything it does
// is a no-op when there is nothing to remove.
Schedule::command('channels:cleanup')->hourly()->withoutOverlapping();

// Proof that cron is actually driving the scheduler. Without it there is no way
// to tell a working cron from a silent one — the only other task runs at
// midnight and reports nothing either way. The admin dashboard reads the stamp.
// Keeps the live-stream relay running on hosts with nothing better than cron
// to supervise it: started once a minute, and a start that finds the port
// taken exits at once. Under systemd or supervisor this is a harmless no-op.
if (config('live.url')) {
    Schedule::command('live:serve')->everyMinute()->runInBackground();
}

Schedule::call([Scheduler::class, 'beat'])
    ->everyMinute()
    ->name('scheduler-heartbeat');
