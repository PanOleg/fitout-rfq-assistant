<?php

declare(strict_types=1);

use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => config('app.name'), 'api' => url('/api/tenders')]));

Route::middleware('token')->group(function (): void {
    Route::get('/tenders/{tender}/review', [ReviewController::class, 'show'])->name('tenders.review');
    Route::post('/tenders/{tender}/review', [ReviewController::class, 'update'])->name('tenders.review.update');
});
