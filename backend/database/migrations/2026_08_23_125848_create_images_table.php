<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Created after `documents` rather than before it: every image belongs to
     * exactly one document, so document_id is a NOT NULL foreign key here rather
     * than a pivot table.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete, not a cascade: a force delete of a user must
            // cost *authorship*, not content. Images belong to the team —
            // scopeVisibleTo() reaches the team through documents — so a gallery
            // must not empty because the photographer left.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Deleting a document takes its images with it: an image cannot outlive
            // the only thing that gives it a place in the hierarchy.
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('title', 140);
            $table->string('description', 400)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Backs Image::toSearchableArray()'s SearchUsingFullText attribute,
            // which makes Scout's database engine search `description` with
            // MATCH ... AGAINST rather than a LIKE. The documents table carries the
            // same pair for the same reason.
            //
            // Not optional: without it MySQL raises "Can't find FULLTEXT index
            // matching the column list" rather than falling back to a scan, so the
            // gallery's search would 500. It is also part of why the suite runs on
            // MySQL — SQLite has no compileFullText() at all.
            //
            // Consequences for callers: matching is by whole word, terms shorter
            // than innodb_ft_min_token_size (3 by default) are ignored, stopwords
            // are never indexed, and a mid-word fragment finds nothing — `title`
            // keeps its wildcarded LIKE for that.
            $table->fullText('description');

            // Sorting indexes, one per timestamp column. An index can only serve
            // an ORDER BY when the columns the query filters by *equality* come
            // first, so each is prefixed by document_id and the soft delete.
            //
            // document_id leads because the gallery is the only screen that lists
            // images and is always pinned to one document — a bare (created_at)
            // index would be unusable here. ImageGallery.vue defaults to
            // created_at DESC, so the first of these runs on every load; it also
            // serves the cover-image latest()->limit(4) load on document cards.
            //
            // The bin is deliberately not covered: `deleted_at IS NOT NULL` is a
            // range rather than a single value, which breaks the ordering behind
            // it, so a trashed listing still filesorts. It is rare and small.
            $table->index(['document_id', 'deleted_at', 'created_at']);
            $table->index(['document_id', 'deleted_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
