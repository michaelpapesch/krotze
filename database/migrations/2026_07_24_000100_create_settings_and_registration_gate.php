<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Server-wide switches the admin can flip (open registrations, manual
 * approval), the registration status every identity now carries, and the
 * per-membership mute flag behind the client's notification settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            ['key' => 'open_registrations', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'manual_approval', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'registration_invite_token', 'value' => Str::random(40), 'created_at' => now(), 'updated_at' => now()],
            // Uploads used to expire after a hard-coded 24 hours; that is now
            // the default of an admin-controlled setting.
            ['key' => 'upload_autodelete', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'upload_retention_days', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            // Likewise, the 5-minute window that starts when a member first
            // opens an upload used to be a constant.
            ['key' => 'upload_view_limit', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'upload_view_minutes', 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('chat_users', function (Blueprint $table) {
            // approved | pending | denied — pending identities may pick a
            // username and poll their state, nothing else.
            $table->string('status', 10)->default('approved')->after('username');
            $table->timestamp('decided_at')->nullable()->after('last_seen_at');
            $table->index('status');
        });

        Schema::table('channel_members', function (Blueprint $table) {
            $table->boolean('muted')->default(false)->after('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn('muted');
        });

        Schema::table('chat_users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'decided_at']);
        });

        Schema::dropIfExists('settings');
    }
};
