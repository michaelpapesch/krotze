<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Scheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'password_changed_at' => now()],
        );
    }

    public function test_a_fresh_stamp_counts_as_alive(): void
    {
        Scheduler::beat();

        $this->assertTrue(Scheduler::isAlive());
        $this->assertNotNull(Scheduler::lastRun());
        $this->assertTrue(Scheduler::lastRun()->diffInSeconds(now()) < 5);
    }

    public function test_no_stamp_at_all_counts_as_dead(): void
    {
        $this->assertFalse(Scheduler::isAlive());
        $this->assertNull(Scheduler::lastRun());
    }

    public function test_a_stale_stamp_counts_as_dead(): void
    {
        $this->travelTo(now()->subMinutes(Scheduler::STALE_AFTER_MINUTES + 1));
        Scheduler::beat();
        $this->travelBack();

        // Still readable, so the dashboard can say when it was last seen …
        $this->assertNotNull(Scheduler::lastRun());
        // … but not fresh enough to claim cron is driving anything.
        $this->assertFalse(Scheduler::isAlive());
    }

    public function test_a_garbled_stamp_does_not_break_the_dashboard(): void
    {
        Cache::put('scheduler.heartbeat', 'not-a-date', now()->addHour());

        $this->assertNull(Scheduler::lastRun());
        $this->assertFalse(Scheduler::isAlive());
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_the_dashboard_reports_a_running_scheduler(): void
    {
        Scheduler::beat();

        $this->actingAs($this->admin())->get('/admin')
            ->assertOk()
            ->assertSee('Scheduler is running')
            ->assertDontSee('schedule:run');
    }

    public function test_the_dashboard_reports_a_silent_scheduler_and_how_to_fix_it(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertSee('Scheduler is not running')
            ->assertSee('never reported in')
            // The fix, spelled out rather than left as an exercise.
            ->assertSee('schedule:run', false);
    }

    public function test_the_heartbeat_is_registered_on_the_schedule(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $descriptions = collect($schedule->events())->map(fn ($e) => $e->description ?? '')->all();

        $this->assertContains('scheduler-heartbeat', $descriptions,
            'The heartbeat must be on the schedule, or it can never report in.');
    }

    public function test_running_the_schedule_writes_a_stamp(): void
    {
        $this->assertFalse(Scheduler::isAlive());

        // What cron does every minute.
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertTrue(Scheduler::isAlive(), 'schedule:run must drive the heartbeat.');
    }
}
