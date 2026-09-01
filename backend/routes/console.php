<?php

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * req.txt's retention rule: every entry is soft deleted, and then destroyed for
 * good once its window runs out. The windows themselves live on the models, as
 * RETENTION_DAYS / RETENTION_HOURS beside the prunable() scope that reads them.
 *
 * A scheduled sweep rather than a queued job dispatched at delete time: this is
 * a periodic pass over a time predicate, not event-driven work. A delayed job
 * would still fire after the row was restored, could not be re-aimed when a
 * window changed, and would multiply per row.
 *
 * The --model list is not decoration - it fixes the order, children before
 * parents. Each child is collected on its own clock while its parent is still
 * live, and whatever is left inside a parent's bin is taken by that parent's
 * pruning() hook when its own window runs out. See Image::prunable().
 *
 * withoutOverlapping() earns its keep at this cadence: a sweep that force
 * deletes a large team's documents walks their media files one at a time and
 * can outrun five minutes.
 */
Schedule::command('model:prune', [
    '--model' => [
        Image::class,
        Document::class,
        Team::class,
        User::class,
        Category::class,
    ],
])->everyFiveMinutes()->withoutOverlapping();
