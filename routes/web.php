<?php

use App\Http\Controllers\ConfiguratorController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ConfiguratorController::class, 'index'])->name('configurator.index');
Route::post('/preview', [ConfiguratorController::class, 'preview'])->name('configurator.preview');
Route::post('/save', [ConfiguratorController::class, 'save'])->name('configurator.save');
Route::get('/c/{id}', [ConfiguratorController::class, 'show'])->name('configurator.show');
