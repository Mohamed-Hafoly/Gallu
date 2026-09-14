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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete, not a cascade: a force delete of a user must
            // cost *authorship*, not content. Documents belong to the team so a gallery must not empty because
            // the author left. The resources serve creator: null, which the SPA
            // renders as "[deleted]".
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Stamped at creation rather than derived from the creator's current
            // team, so an entry stays with the team it was made for after its
            // author moves or leaves. NOT NULL: req.txt makes the team the unit
            // of visibility, and Document::scopeVisibleTo(), DocumentPolicy and
            // ImagePolicy all read it, so a team-less document would be a row
            // visible to nobody but a super-admin.
            //
            // cascadeOnDelete, unlike user_id above, and that contrast is the
            // whole design: the team is the container, the author is not.
            // Destroying a team destroys its documents and - through
            // images.document_id, also a cascade - their images. Because that
            // happens in SQL and fires no model events, the force-delete hooks
            // in Team::booted() and Document::booted() walk the children through
            // the models first so their media files are not orphaned on disk.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title', 140);
            $table->string('description', 400)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Backs Document::toSearchableArray()'s SearchUsingFullText attribute,
            // which makes Scout's database engine search `description` with
            // MATCH ... AGAINST rather than a LIKE.
            //
            // Not optional: without it MySQL raises "Can't find FULLTEXT index
            // matching the column list" rather than falling back to a scan, so the
            // listing would 500 on any search. It is also why the suite runs on
            // MySQL — SQLite has no compileFullText() at all.
            //
            // Consequences for callers: matching is by whole word, terms shorter
            // than innodb_ft_min_token_size (3 by default) are ignored, stopwords
            // are never indexed, and a mid-word fragment finds nothing — `title`
            // keeps its wildcarded LIKE for that.
            $table->fullText('description');

            // Sorting indexes, one per timestamp column. An index can only serve
            // an ORDER BY when the columns the query filters by *equality* come
            // first, so each is prefixed by the soft delete, which is single-valued under IS NULL
            // and so leaves the suffix already in timestamp order.
            //
            // documents/index.vue's card feed defaults to created_at DESC, so the
            // first of these is on the hot path rather than an opt-in sort.
            //
            // The bin is deliberately not covered: `deleted_at IS NOT NULL` is a
            // range rather than a single value, which breaks the ordering behind
            // it, so a trashed listing still filesorts. It is rare and small.
            $table->index(['deleted_at', 'created_at']);
            $table->index(['deleted_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
