<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-to-end encryption for group channels.
 *
 * A channel key is a random 32-byte secret a member's device creates and
 * wraps for every device of every approved member (the same ephemeral-box
 * wrap private conversations use). Each new key starts an "epoch". Whenever
 * the set of member devices changes — someone joins, leaves, is removed,
 * pairs or removes a device — the next sender has to create a new epoch
 * first, so a newcomer can read only what is written after they joined and
 * somebody who left cannot read what comes after. The server stores wraps it
 * cannot open and checks only the bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('e2ee')->default(false);
            // The current epoch, and a fingerprint of the device set its key
            // was wrapped for. A different fingerprint now = a new epoch is due.
            $table->unsignedInteger('key_epoch')->nullable();
            $table->string('key_devices_hash', 64)->nullable();
        });

        Schema::create('channel_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch');
            $table->foreignId('chat_device_id')->constrained()->cascadeOnDelete();
            $table->text('wrap');
            $table->timestamps();
            $table->unique(['channel_id', 'epoch', 'chat_device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_keys');
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['e2ee', 'key_epoch', 'key_devices_hash']);
        });
    }
};
