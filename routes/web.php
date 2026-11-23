<?php

use App\Http\Controllers\BitqueryController;
use App\Http\Controllers\Web3AuthController;
use App\Http\Middleware\RequireWallet;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/trades');

// Batas per pengunjung (per IP). Awalan (pages, wallet, ...) memisahkan hitungan tiap kelompok.
Route::middleware('throttle:60,1,pages')->group(function () {
    Route::get('/trades', [BitqueryController::class, 'trades'])->name('trades');
    Route::get('/tokens', [BitqueryController::class, 'tokens'])->name('tokens');
});

Route::get('/wallet', [BitqueryController::class, 'wallet'])
    ->middleware('throttle:20,1,wallet')
    ->name('wallet');

// Live Feed: hanya untuk pengunjung yang sudah masuk dengan wallet.
Route::get('/trades/feed', [BitqueryController::class, 'feed'])
    ->middleware(['throttle:30,1,feed', RequireWallet::class])
    ->name('trades.feed');

// Masuk dengan wallet (tanda tangan pesan, gratis dan tanpa transaksi).
Route::prefix('auth')->middleware('throttle:10,1,auth')->group(function () {
    Route::post('/nonce', [Web3AuthController::class, 'nonce'])->name('auth.nonce');
    Route::post('/verify', [Web3AuthController::class, 'verify'])->name('auth.verify');
    Route::post('/logout', [Web3AuthController::class, 'logout'])->name('auth.logout');
});
