<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prekeys for the Double Ratchet in private conversations: a second X25519
 * public key per device, replaced by the device every week, that a sender
 * combines with the identity key (`public_key`) to start a session while the
 * device is offline (X3DH). The private half never leaves the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_devices', function (Blueprint $table) {
            $table->string('prekey', 64)->nullable();
            $table->unsignedInteger('prekey_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_devices', function (Blueprint $table) {
            $table->dropColumn(['prekey', 'prekey_id']);
        });
    }
};
