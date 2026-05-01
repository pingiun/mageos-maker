<?php

use App\Livewire\Configurator;
use Illuminate\Support\Facades\Route;

Route::get('/', Configurator::class)->name('configurator.index');
Route::get('/c/{id}', Configurator::class)->name('configurator.show');
