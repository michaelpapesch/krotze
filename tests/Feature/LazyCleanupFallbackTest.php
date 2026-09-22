<?php

namespace Tests\Feature;

use App\Models\ChatUser;
use App\Support\Scheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Retention runs from two places. The scheduler is the real one; the sweep
 * carried by API traffic exists for hosts where nothing runs `schedule:run`.
 * These pin down which takes over when.
 */
class LazyCleanupFallbackTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** An identity old enough for the retention rules to remove. */
    private function abandoned(): ChatUser
    {
        $user = ChatUser::create(['username' => null, 'status' => ChatUser::APPROVED]);
        $user->forceFill(['created_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)])->save();

        return $user;
    }

    public function test_api_traffic_sweeps_when_nothing_drives_the_scheduler(): void
    {
        $abandoned = $this->abandoned();
        $cred = $this->register();

        $this->signed($cred, 'GET', '/api/state')->assertOk();

        $this->assertNull(ChatUser::find($abandoned->id), 'The fallback has to work where cron does not exist.');
    }

    public function test_api_traffic_stands_aside_when_the_scheduler_is_alive(): void
    {
        $abandoned = $this->abandoned();
        Scheduler::beat();
        $cred = $this->register();

        $this->signed($cred, 'GET', '/api/state')->assertOk();

        // Not a leak — the scheduled run covers it. Doing the same work twice
        // only makes one unlucky poller wait for it.
        $this->assertNotNull(ChatUser::find($abandoned->id));

        // …and the scheduled path really does remove it.
        $this->artisan('channels:cleanup')->assertSuccessful();
        $this->assertNull(ChatUser::find($abandoned->id));
    }

    public function test_the_sweep_runs_at_most_once_an_hour(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'GET', '/api/state')->assertOk();
        $this->assertNotNull(Cache::get('channels-cleanup-ran'), 'The first poll takes the hourly lock.');

        // A second identity going stale between polls survives until the lock
        // expires — the point being that every poll does not pay for a sweep.
        $late = $this->abandoned();
        $this->signed($cred, 'GET', '/api/state')->assertOk();

        $this->assertNotNull(ChatUser::find($late->id));
    }

    public function test_the_schedule_runs_cleanup_hourly(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $cleanup = $events->first(fn ($e) => str_contains($e->command ?? '', 'channels:cleanup'));

        $this->assertNotNull($cleanup);
        // Hourly, because the fallback it replaces ran hourly and tombstones
        // are only meant to survive an hour.
        $this->assertSame('0 * * * *', $cleanup->expression);
    }

    private function register(): array
    {
        return $this->postJson('/api/session', ['device_name' => 'Test'])->json();
    }
}
