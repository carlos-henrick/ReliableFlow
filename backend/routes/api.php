<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;

Route::post('/tasks', [TaskController::class, 'store']);
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
