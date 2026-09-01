<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Global, above teams, per req.txt — deliberately not a spatie role,
            // whose assignments are always scoped to one team. Never fillable:
            // registration and the profile update both mass-assign.
            $table->boolean('is_super_admin')->default(false);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            // Every entry is soft deleted, model_has_roles row survives untouched,
            // so a restore returns them to
            // the same team with the same role.
            $table->softDeletes();

            // Sorting indexes, one per timestamp column. An index can only serve
            // an ORDER BY when the columns the query filters by *equality* come
            // first, so each is prefixed by the soft delete, which is single-valued under IS NULL
            // and so leaves the suffix already in timestamp order.
            //
            // The admin users table arrives unsorted (id, free via the primary key);
            // these serve a click on the Created / Updated headers.
            //
            // The bin is deliberately not covered: `deleted_at IS NOT NULL` is a
            // range rather than a single value, which breaks the ordering behind
            // it, so a trashed listing still filesorts. It is rare and small.
            $table->index(['deleted_at', 'created_at']);
            $table->index(['deleted_at', 'updated_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
