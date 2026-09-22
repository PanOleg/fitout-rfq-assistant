<?php

declare(strict_types=1);

use App\Http\Controllers\TenderController;
use Illuminate\Support\Facades\Route;

Route::post('/tenders', [TenderController::class, 'store'])->name('tenders.store');
Route::get('/tenders/{tender}', [TenderController::class, 'show'])->name('tenders.show');
Route::get('/tenders/{tender}/rfqs', [TenderController::class, 'rfqs'])->name('tenders.rfqs');
