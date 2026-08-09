<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn(Request $request) => new UserResource($request->user()));

    Route::get('/projects',fn() => 'hellp');
    Route::get('/profile', fn() => 'hellp');
    Route::get('/settings', fn() => 'hellp');
    Route::get('/', fn() => 'hellp');
});
