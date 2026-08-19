<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
 * El respaldo que muestra el service worker cuando no hay conexión. Es una vista
 * suelta y no Inertia: tiene que poder mostrarse sin los assets compilados, que
 * es exactamente el caso en el que aparece.
 */
Route::view('sin-conexion', 'offline')->name('offline');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
