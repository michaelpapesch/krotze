<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_users', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('type', 10)->default('group'); // group | private
            $table->foreignId('owner_id')->nullable()->constrained('chat_users')->nullOnDelete();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->unsignedSmallInteger('retention_days')->default(7);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('chat_users')->cascadeOnDelete();
            $table->string('alias', 40);
            $table->string('status', 10)->default('pending'); // pending | approved | denied
            $table->boolean('pinned')->default(false);
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();
            $table->unique(['channel_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('chat_users')->nullOnDelete();
            $table->string('author_alias', 40);
            $table->text('body')->nullable();
            $table->string('kind', 10)->default('text'); // text | image | audio | video | zip
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_deleted_by', 40)->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'id']);
        });

        Schema::create('chat_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('chat_users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('channel_members');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('chat_users');
    }
};
