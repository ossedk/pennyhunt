<?php

use App\Http\Controllers\BacktestsController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\RadarController;
use App\Http\Controllers\SignalsController;
use App\Http\Controllers\SourcesController;
use App\Http\Controllers\TickerController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/radar')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('radar', [RadarController::class, 'index'])->name('radar');
    Route::redirect('dashboard', '/radar')->name('dashboard');

    Route::get('feed', [FeedController::class, 'index'])->name('feed');
    Route::get('signals', [SignalsController::class, 'index'])->name('signals');
    Route::get('signals/{signal}/bars', [SignalsController::class, 'bars'])->name('signals.bars');
    Route::get('signals/{signal}', [SignalsController::class, 'show'])->name('signals.show');
    Route::get('tickers/{symbol}', [TickerController::class, 'show'])->name('tickers.show');

    Route::get('backtests', [BacktestsController::class, 'index'])->name('backtests');
    Route::post('backtests', [BacktestsController::class, 'store'])->name('backtests.store');

    Route::get('watchlists', [WatchlistController::class, 'index'])->name('watchlists');
    Route::post('watchlists/items', [WatchlistController::class, 'store'])->name('watchlists.store');
    Route::delete('watchlists/items/{ticker}', [WatchlistController::class, 'destroy'])->name('watchlists.destroy');

    Route::get('sources', [SourcesController::class, 'index'])->name('sources');
});

require __DIR__.'/settings.php';
