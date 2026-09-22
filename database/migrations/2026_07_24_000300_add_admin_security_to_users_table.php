<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin account security: a record of when the password was last set, and the
 * TOTP secret plus recovery codes behind two-factor login.
 *
 * `password_changed_at` starts null for everybody, including accounts that
 * already exist — a seeded password nobody has touched is exactly the case the
 * forced first-run change is there to catch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
            // Encrypted at rest: a database dump should not hand somebody the
            // ability to generate valid codes.
            $table->text('two_factor_secret')->nullable()->after('password_changed_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            // Only set once a code has been entered successfully, so an
            // abandoned setup never locks anybody out.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'password_changed_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
