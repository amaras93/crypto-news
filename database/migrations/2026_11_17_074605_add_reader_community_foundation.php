<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
            $table->string('preferred_locale', 5)->default('en');
            $table->boolean('reply_notifications')->default(false);
            $table->timestamp('community_suspended_at')->nullable()->index();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->string('locale', 5);
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('reply_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['article_id', 'locale', 'parent_id', 'status', 'created_at'], 'comments_discussion_index');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('comment_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['comment_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('comment_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 32);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['comment_id', 'user_id']);
            $table->index(['resolved_at', 'created_at']);
        });

        Schema::create('comment_moderation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->timestamps();
            $table->index(['comment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_moderation_events');
        Schema::dropIfExists('comment_reports');
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comments');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'preferred_locale', 'reply_notifications', 'community_suspended_at']);
        });
    }
};
