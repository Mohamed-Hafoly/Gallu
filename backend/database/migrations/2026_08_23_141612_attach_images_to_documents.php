<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every image now belongs to exactly one document, so document_id is NOT
     * NULL rather than a pivot.
     *
     * There is deliberately no data migration for images that predate the rule.
     * On a fresh database this runs against an empty table, and on one that
     * still holds pre-document images the NOT NULL foreign key fails loudly —
     * which is the right outcome. An earlier revision silently deleted those
     * rows and their files; do not reintroduce that.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // Deleting a document takes its images with it: an image cannot
            // outlive the only thing that gives it a place in the hierarchy.
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
        });

        Schema::dropIfExists('document_image');
    }

    public function down(): void
    {
        Schema::create('document_image', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_id', 'image_id']);
        });

        Schema::table('images', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn('document_id');
        });
    }
};
