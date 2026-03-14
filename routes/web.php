<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('categories', App\Http\Controllers\CategoryController::class);
    Route::resource('products', App\Http\Controllers\ProductController::class);
});

Route::get('/metrics', function () {
    return \Spatie\Prometheus\Facades\Prometheus::render();
});

Route::get('/metrics-test', function () {
    return "Prometheus Test";
});

// Anomaly Injection Routes
Route::prefix('anomaly')->group(function () {
    Route::get('/delay', [\App\Http\Controllers\AnomalyController::class, 'delay']);
    Route::get('/db-bottleneck', [\App\Http\Controllers\AnomalyController::class, 'dbBottleneck']);
    Route::get('/error', [\App\Http\Controllers\AnomalyController::class, 'error']);
    Route::get('/login-failure', [\App\Http\Controllers\AnomalyController::class, 'loginFailure']);
    Route::get('/heavy-payload', [\App\Http\Controllers\AnomalyController::class, 'heavyPayload']);
});

require __DIR__.'/auth.php';
