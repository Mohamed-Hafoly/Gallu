<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users were the last entity still hard deleted, against req.txt's "Every
     * entry should be soft deleted".
     *
     * Note what this alone changes, before any application code: every
     * belongsTo(User) starts carrying the global scope, so $document->user and
     * $image->user resolve to null for a deleted author - which is what the
     * resources render as "[deleted]" - and Team::members(), a morphedByMany
     * onto User, drops them from member lists and members_count on its own. The
     * model_has_roles row survives untouched, so a restore returns them to the
     * same team with the same role.
     *
     * `users_email_unique` is deliberately left alone. A binned user keeps their
     * address reserved, so restoring is always lossless; freeing it would mean
     * mangling the stored email and returning a different user than was deleted.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
