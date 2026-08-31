<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the flag that told a cascade-trashed image from one binned on its
     * own.
     *
     * It existed to keep a document's restore from reviving an image that had
     * been deleted deliberately. That is no longer the rule: restoring a
     * document now empties its whole bin, and restoring a team does the same
     * one level up — so nothing reads the column any more, and a column nobody
     * reads is a second source of truth waiting to drift.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('trashed_with_document');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->boolean('trashed_with_document')
                ->default(false)
                ->after('document_id');
        });
    }
};
