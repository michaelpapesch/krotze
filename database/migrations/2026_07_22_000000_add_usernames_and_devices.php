<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->after('id');
            // sha256 of the identity token that can register a new device
            $table->string('transfer_hash', 64)->nullable()->after('token');
            // The raw token is no longer an authenticator — devices sign requests
            // instead. It stays only so existing clients can upgrade once.
            $table->string('token', 64)->nullable()->change();
        });

        $this->backfillUsernames();

        Schema::table('chat_users', function (Blueprint $table) {
            $table->unique('username');
            $table->index('transfer_hash');
        });

        // One row per device that may act as the user. `secret` is the HMAC key
        // the device signs its requests with; it never travels on the wire after
        // the device is registered, and is encrypted at rest.
        Schema::create('chat_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('chat_users')->cascadeOnDelete();
            $table->string('public_id', 32)->unique();
            $table->text('secret');
            $table->string('name', 60);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Identity is global now — the per-membership alias is gone.
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn('alias');
        });
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->string('alias', 40)->default('');
        });

        DB::table('channel_members')->update([
            'alias' => DB::raw('(select coalesce(username, \'\') from chat_users where chat_users.id = channel_members.user_id)'),
        ]);

        Schema::dropIfExists('chat_devices');

        Schema::table('chat_users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['transfer_hash']);
            $table->dropColumn(['username', 'transfer_hash']);
        });
    }

    /** Give every existing user a global name, seeded from their newest alias. */
    private function backfillUsernames(): void
    {
        $taken = [];

        foreach (DB::table('chat_users')->orderBy('id')->pluck('id') as $id) {
            $alias = DB::table('channel_members')
                ->where('user_id', $id)
                ->orderByDesc('id')
                ->value('alias');

            $base = trim((string) $alias) !== ''
                ? mb_substr(trim((string) $alias), 0, 36)
                : 'anon-'.$id;

            $name = $base;
            $n = 1;
            while (isset($taken[mb_strtolower($name)])) {
                $name = mb_substr($base, 0, 34).'-'.(++$n);
            }
            $taken[mb_strtolower($name)] = true;

            DB::table('chat_users')->where('id', $id)->update(['username' => $name]);
        }
    }
};
