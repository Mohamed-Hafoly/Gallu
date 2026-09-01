<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn a hard delete of a user into lost *authorship* rather than lost
     * content.
     *
     * documents.user_id and images.user_id were NOT NULL + ON DELETE CASCADE, so
     * force deleting a user destroyed every document they had filed and every
     * image they had uploaded - and, through images.document_id's own cascade,
     * everything under those documents. That content belongs to a team, not to
     * its author: both scopeVisibleTo() methods scope by team, so a gallery must
     * not empty because the photographer left. teams.user_id and
     * categories.user_id already said nullOnDelete for exactly this reason;
     * these two were the outliers.
     *
     * Nothing exercises this today - users are soft deleted and there is no
     * pruner anywhere in the app - but the rule belongs in the schema now rather
     * than being remembered later, when the first force delete would be
     * irreversible.
     *
     * dropForeign then foreign(), not change(): altering a column's nullability
     * does not touch the constraint's referential action, so the CASCADE would
     * survive a plain change().
     */
    public function up(): void
    {
        foreach (['documents', 'images'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['user_id']);
                $blueprint->unsignedBigInteger('user_id')->nullable()->change();
                $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['documents', 'images'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['user_id']);
                $blueprint->unsignedBigInteger('user_id')->nullable(false)->change();
                $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
