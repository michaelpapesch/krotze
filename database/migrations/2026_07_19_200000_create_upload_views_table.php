<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            // The member's personal capability URL for this upload. Nulled
            // ("route destroyed") when the 5-minute view window has passed.
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_views');
    }
};
