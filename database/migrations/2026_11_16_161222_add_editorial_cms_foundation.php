<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_editor')->default(false)->index();
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('image_credit', 500)->nullable();
        });
        Schema::table('article_translations', function (Blueprint $table): void {
            $table->string('image_alt')->nullable();
        });
        Schema::create('editorial_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });
        Schema::create('editorial_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('default_author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_settings');
        Schema::dropIfExists('editorial_events');
        Schema::table('article_translations', fn (Blueprint $table) => $table->dropColumn('image_alt'));
        Schema::table('articles', fn (Blueprint $table) => $table->dropColumn('image_credit'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_editor'));
    }
};
