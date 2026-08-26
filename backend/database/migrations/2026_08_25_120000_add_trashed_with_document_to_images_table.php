<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an image that was trashed only because its document was.
     *
     * Restoring a document has to put back exactly the images its own delete
     * took down, and leave alone any image that was already in the bin on its
     * own. Matching on deleted_at looks like it would do that, but Laravel
     * stores timestamps to the second: an image binned in the same second as its
     * document is indistinguishable from one the cascade took, and would be
     * wrongly revived. This records the fact instead of inferring it.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->boolean('trashed_with_document')
                ->default(false)
                ->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('trashed_with_document');
        });
    }
};
