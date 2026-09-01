<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The index behind Image::toSearchableArray()'s SearchUsingFullText
     * attribute, which makes Scout's database engine search `description` with
     * MATCH ... AGAINST rather than a LIKE. The documents table carries the
     * same pair for the same reason.
     *
     * Not optional: without it MySQL raises "Can't find FULLTEXT index matching
     * the column list" rather than falling back to a scan, so the gallery's
     * search would 500. It is also part of why the suite runs on MySQL - SQLite
     * has no compileFullText() at all, so this migration cannot run there.
     *
     * The column is a varchar(400), which InnoDB indexes fine. Note the
     * consequences for callers: matching is by whole word, terms shorter than
     * innodb_ft_min_token_size (3 by default) are ignored, stopwords are never
     * indexed, and a mid-word fragment finds nothing - `title` keeps its
     * wildcarded LIKE for that.
     *
     * Before this, `images` carried only its primary key and the two foreign
     * key indexes Laravel creates for user_id and document_id.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->fullText('description');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropFullText(['description']);
        });
    }
};
