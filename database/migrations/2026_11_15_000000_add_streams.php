<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->string('role', 10)->default('member'); // member | streamer (the owner is implicit)
        });

        // A live stream being relayed by the live server (`php artisan
        // live:serve`). The row is only the announcement that lets
        // /api/state tell clients about it — media is never stored.
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('chat_users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('chat_devices')->cascadeOnDelete();
            $table->boolean('audio')->default(false);
            $table->string('video', 10)->nullable(); // camera | screen
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->index(['channel_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streams');
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
