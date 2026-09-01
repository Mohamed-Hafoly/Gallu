<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The index behind Document::toSearchableArray()'s SearchUsingFullText
     * attribute, which makes Scout's database engine search `description` with
     * MATCH ... AGAINST rather than a LIKE.
     *
     * Not optional: without it MySQL raises "Can't find FULLTEXT index matching
     * the column list" rather than falling back to a scan, so the listing would
     * 500 on any search. It is also why the suite runs on MySQL - SQLite has no
     * compileFullText() at all, so this migration cannot run there.
     *
     * The column is a varchar(400), which InnoDB indexes fine. Note the
     * consequences for callers: matching is by whole word, terms shorter than
     * innodb_ft_min_token_size (3 by default) are ignored, and a mid-word
     * fragment finds nothing - `title` keeps its wildcarded LIKE for that.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->fullText('description');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText(['description']);
        });
    }
};
