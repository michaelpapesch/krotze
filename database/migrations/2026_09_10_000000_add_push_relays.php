<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push relays let a device that holds identities on several Krotze servers
 * receive notifications from all of them. A browser's push subscription is
 * bound to the VAPID key of the server that created it — the home server —
 * so every other server sends through it instead.
 *
 * `push_relays` is the home side: one token per (device, foreign origin) that
 * the foreign server presents when it has something to deliver. The columns
 * added to `push_subscriptions` are the foreign side: a subscription that is
 * not a browser endpoint but a relay URL plus that token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_relays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('chat_devices')->cascadeOnDelete();
            $table->string('origin');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'origin']);
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('p256dh', 128)->nullable()->change();
            $table->string('auth', 32)->nullable()->change();
            $table->boolean('relay')->default(false);
            $table->string('relay_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['relay', 'relay_token']);
        });
        Schema::dropIfExists('push_relays');
    }
};
