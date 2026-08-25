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
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stamped at creation rather than derived from the creator's current
            // team, so an entry stays with the team it was made for after its
            // author moves or leaves. Nullable because super-admins belong to no
            // team and DatabaseSeeder seeds none at all.
            //
            // Nothing reads this yet: req.txt's "admin sees only his own team's
            // entries" rule is unbuilt for images too, and belongs in one pass
            // over both models. The column is here so that pass needs no backfill.
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 140);
            $table->string('description', 400)->nullable();
            $table->timestamps();
            $table->softDeletes();
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
