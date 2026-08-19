<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\InicioController;
use Illuminate\Support\Facades\Route;

/*
 * La raíz no muestra landing: Dharmify es una biblioteca privada, así que quien
 * llega sin sesión va derecho al login y quien ya entró, a la app. Conserva el
 * nombre `home` porque es a donde vuelven el logout y los layouts de auth.
 */
Route::get('/', InicioController::class)->name('home');

/*
 * El respaldo que muestra el service worker cuando no hay conexión. Es una vista
 * suelta y no Inertia: tiene que poder mostrarse sin los assets compilados, que
 * es exactamente el caso en el que aparece.
 */
Route::view('sin-conexion', 'offline')->name('offline');

/*
 * Ingreso con Google. Van en inglés y bajo /auth como el resto de las rutas de
 * autenticación, que las publica Fortify; el español es para el dominio.
 *
 * `guest`: alguien con la sesión abierta que llega acá ya está adentro, y
 * rehacer el flujo solo puede terminar cambiándole la cuenta sin querer.
 */
Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirigir'])
        ->name('google.redirect');

    Route::get('auth/google/callback', [GoogleController::class, 'volver'])
        ->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
