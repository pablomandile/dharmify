<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BibliotecaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\PistaController;
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
    /*
     * La pantalla principal. Se llama `biblioteca` y no `dashboard`: no es un
     * tablero de métricas, es el catálogo, y el nombre de la ruta debería decir
     * lo mismo que la URL.
     */
    Route::get('biblioteca', [BibliotecaController::class, 'index'])->name('biblioteca');

    Route::get('series/{serie}', [BibliotecaController::class, 'serie'])->name('series.show');
    Route::get('series/{serie}/portada', [PistaController::class, 'portada'])->name('series.portada');

    /*
     * El audio. Va fuera del docroot y pasa por acá, que valida la sesión antes
     * de mandar un solo byte.
     */
    Route::get('pistas/{pista}/audio', [PistaController::class, 'audio'])->name('pistas.audio');
    Route::get('pistas/{pista}/bajar', [PistaController::class, 'descargar'])->name('pistas.bajar');
    Route::post('pistas/{pista}/restaurar', [PistaController::class, 'restaurar'])->name('pistas.restaurar');

    /*
     * El estado va bajo /api porque el service worker tiene prohibido cachear
     * esa rama: una copia vieja diciendo "en la nube" cuando el archivo ya está
     * es peor que un error de red.
     */
    Route::get('api/pistas/{pista}/estado', [PistaController::class, 'estado'])->name('pistas.estado');
});

require __DIR__.'/settings.php';
