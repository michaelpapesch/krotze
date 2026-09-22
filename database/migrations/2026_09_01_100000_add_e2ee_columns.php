<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_devices', function (Blueprint $table) {
            // X25519 public key (base64), set by the device itself. The private
            // half never leaves the device — that is the whole point.
            $table->string('public_key', 64)->nullable();
        });
        Schema::table('messages', function (Blueprint $table) {
            // body holds an E2EE envelope (ciphertext + per-device wrapped
            // keys) instead of text; the server cannot read it.
            $table->boolean('encrypted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chat_devices', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};
