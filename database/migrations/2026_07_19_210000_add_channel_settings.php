<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('allow_images')->default(true);
            $table->boolean('allow_videos')->default(true);
            $table->boolean('allow_audio')->default(true);
            $table->boolean('allow_zip')->default(true);
            $table->boolean('restrict_delete')->default(false);
        });
        Schema::table('channel_members', function (Blueprint $table) {
            $table->boolean('hidden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['allow_images', 'allow_videos', 'allow_audio', 'allow_zip', 'restrict_delete']);
        });
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
