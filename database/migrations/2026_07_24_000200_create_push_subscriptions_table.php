<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device that asked to be pushed to. Bound to the device rather
 * than the identity, so revoking a device in the profile also stops its
 * notifications, and so each device keeps its own preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('chat_users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('chat_devices')->cascadeOnDelete();
            // Push services hand out long URLs; index a prefix rather than the
            // whole column, which MySQL cannot key in full.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh', 128);
            $table->string('auth', 32);
            $table->boolean('notify_messages')->default(true);
            $table->boolean('notify_chat_requests')->default(true);
            $table->boolean('notify_join_requests')->default(true);
            // Some people would rather a lock screen did not spell out who said
            // what; those devices get "New message in <channel>" instead.
            $table->boolean('hide_message_text')->default(false);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
