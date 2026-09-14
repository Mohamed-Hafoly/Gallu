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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_en', 40);
            $table->string('name_ar', 40);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name_en');
            $table->unique('name_ar');

            // Serves the retention sweep, which scans `deleted_at <= ?` on this
            // table every five minutes — see routes/console.php and
            // Category::prunable(). A plain single-column index rather than the
            // ['deleted_at', 'created_at'] composites on `documents` and
            // `images`: those exist to carry an ORDER BY through, and this
            // listing is not server-sorted — index() returns every row and the
            // SPA sorts and filters client-side.
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
