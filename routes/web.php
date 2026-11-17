<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? to_route('studio') : to_route('login'))->name('home');
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth', ['mode' => 'login']))->name('login');
    Route::get('/register', fn () => Inertia::render('Auth', ['mode' => 'register']))->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:sign-in');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/studio', [StudioController::class, 'index'])->name('studio');
    Route::post('/quotes', [StudioController::class, 'quote'])->middleware('throttle:60,1')->name('quotes.store');
    Route::post('/generations', [StudioController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');
    Route::get('/history', [StudioController::class, 'history'])->name('history');
    Route::get('/credits', [StudioController::class, 'credits'])->name('credits');
    Route::post('/credits', [StudioController::class, 'purchase'])->middleware('throttle:10,1')->name('credits.purchase');
    Route::get('/admin/reports', ReportController::class)->name('admin.reports');
});
