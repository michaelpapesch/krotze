<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Message text and file names are stored encrypted from here on (AES-256-CBC
 * with the app key, via the model's `encrypted` casts). A stolen database dump
 * or a backup that ends up somewhere it should not be no longer reads as chat.
 *
 * Ciphertext is base64 JSON and grows to roughly 4/3 of the plaintext plus a
 * fixed envelope, so the columns are widened first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->mediumText('body')->nullable()->change();
            $table->text('file_name')->nullable()->change();
        });

        $this->convert(fn (string $v) => Crypt::encryptString($v));
    }

    public function down(): void
    {
        $this->convert(function (string $v) {
            try {
                return Crypt::decryptString($v);
            } catch (\Throwable) {
                return $v; // already plaintext
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
            $table->string('file_name')->nullable()->change();
        });
    }

    /** Rewrite body/file_name of every existing row through $transform. */
    private function convert(callable $transform): void
    {
        DB::table('messages')
            ->select('id', 'body', 'file_name')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($transform) {
                foreach ($rows as $row) {
                    $update = [];
                    foreach (['body', 'file_name'] as $column) {
                        if ($row->$column !== null && $row->$column !== '') {
                            $update[$column] = $transform($row->$column);
                        }
                    }
                    if ($update) {
                        DB::table('messages')->where('id', $row->id)->update($update);
                    }
                }
            });
    }
};
