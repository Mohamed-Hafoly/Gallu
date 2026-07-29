<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn(Request $request) => $request->user());
    Route::get('/projects',fn() => 'hellp');
    Route::get('/profile', fn() => 'hellp');
    Route::get('/settings', fn() => 'hellp');
    Route::get('/', fn() => 'hellp');
});
