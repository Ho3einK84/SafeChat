<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::get('/chat', [HomeController::class, 'chat'])->middleware('device.auth');
Route::get('/install', [HomeController::class, 'install']);
Route::post('/install', [HomeController::class, 'runInstall']);

Route::get('/sw.js', fn () => response()->file(public_path('sw.js'), [
    'Content-Type' => 'application/javascript',
]));

Route::get('/manifest.json', fn () => response()->file(public_path('manifest.json'), [
    'Content-Type' => 'application/manifest+json',
]));
