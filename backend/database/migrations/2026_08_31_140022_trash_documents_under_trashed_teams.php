<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bring existing rows in line with the invariant Team::booted() introduces:
     * a live document always has a live team.
     *
     * Before it, a team's delete touched nothing else, so its documents stayed
     * live with a dangling team_id — reported as team-less, and visible to
     * nobody but a super-admin, since User::teamAssignment() reads a trashed
     * team as no membership at all. They are trashed here as though the cascade
     * had run, stamped with their team's own deleted_at, and so are the images
     * under them, which Document's cascade would have taken.
     *
     * Written with the query builder rather than the models: a migration has to
     * keep working when the cascade is changed again later.
     */
    public function up(): void
    {
        $trashedTeams = DB::table('teams')->whereNotNull('deleted_at')->get(['id', 'deleted_at']);

        foreach ($trashedTeams as $team) {
            $documentIds = DB::table('documents')
                ->where('team_id', $team->id)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($documentIds->isEmpty()) {
                continue;
            }

            DB::table('images')
                ->whereIn('document_id', $documentIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $team->deleted_at]);

            DB::table('documents')
                ->whereIn('id', $documentIds)
                ->update(['deleted_at' => $team->deleted_at]);
        }
    }

    /**
     * Irreversible by nature: which documents were live before this ran is
     * exactly the information it consumed. Restoring the team puts them all
     * back, which is the supported way out.
     */
    public function down(): void
    {
        //
    }
};
