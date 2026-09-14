<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SetPermissionsTeam;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', SetPermissionsTeam::class])->group(function () {
    Route::get('/user', fn (Request $request) => new UserResource($request->user()));

    Route::get('/images', [ImageController::class, 'index']);
    Route::post('/images', [ImageController::class, 'store']);
    Route::patch('/images/{image}', [ImageController::class, 'update']);
    Route::delete('/images/{image}', [ImageController::class, 'destroy']);
    // withTrashed(), like the category and team restore routes: without it the
    // soft-deleted image the admin screen is trying to restore 404s at binding.
    Route::post('/images/{image}/restore', [ImageController::class, 'restore'])->withTrashed();

    // A document groups existing images rather than owning uploads of its own,
    // so these are plain JSON — no multipart POST spoofing PATCH like /images.
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::patch('/documents/{document}', [DocumentController::class, 'update']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    // withTrashed(), like the image, category and team restore routes: without
    // it the soft-deleted document being restored 404s at binding.
    Route::post('/documents/{document}/restore', [DocumentController::class, 'restore'])->withTrashed();

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/picker', [CategoryController::class, 'picker']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::patch('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])->withTrashed();

    // Super-admin only, enforced by TeamPolicy. Mirrors the category routes:
    // one unpaginated index plus a picker for the user dialog's select.
    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/picker', [TeamController::class, 'picker']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::patch('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
    Route::post('/teams/{team}/restore', [TeamController::class, 'restore'])->withTrashed();

    // A team's membership, from the manage-members dialog on the teams screen.
    // Deliberately not withTrashed(): a soft-deleted team has no membership to
    // manage, since User::teamAssignment() already reads one as team-less.
    // One whole-state write rather than a route per add, removal and role
    // change: the dialog edits locally and saves once.
    Route::get('/teams/{team}/members', [TeamMemberController::class, 'index']);
    Route::put('/teams/{team}/members', [TeamMemberController::class, 'sync']);

    // Super-admin only, enforced by UserPolicy. The SPA sends the update as a
    // multipart POST spoofing PATCH so the avatar can ride along.
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    // withTrashed(), like the image, category, document and team restore routes:
    // without it the soft-deleted user the admin screen is trying to restore
    // 404s at binding.
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed();

    Route::get('/gallery', fn () => 'hellp');
    Route::get('/profile', fn () => 'hellp');
    Route::get('/settings', fn () => 'hellp');
    Route::get('/', fn () => 'hellp');
});
