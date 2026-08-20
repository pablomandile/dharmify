<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BibliotecaController;
use App\Http\Controllers\FavoritoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\ListaController;
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
     * Cambiar la carátula a mano. Sólo el administrador: es el catálogo, no una
     * preferencia de cada persona.
     */
    Route::post('series/{serie}/portada', [PistaController::class, 'subirPortada'])
        ->middleware('admin')
        ->name('series.portada.subir');

    /*
     * El audio. Va fuera del docroot y pasa por acá, que valida la sesión antes
     * de mandar un solo byte.
     */
    Route::get('pistas/{pista}/audio', [PistaController::class, 'audio'])->name('pistas.audio');
    Route::get('pistas/{pista}/bajar', [PistaController::class, 'descargar'])->name('pistas.bajar');
    Route::post('pistas/{pista}/restaurar', [PistaController::class, 'restaurar'])->name('pistas.restaurar');

    /*
     * El reproductor devuelve la duración exacta apenas carga el audio. Es lo
     * que completa las pocas pistas cuyo encabezado no alcanzó para calcularla.
     */
    Route::post('pistas/{pista}/duracion', [PistaController::class, 'duracion'])->name('pistas.duracion');

    /*
     * El estado va bajo /api porque el service worker tiene prohibido cachear
     * esa rama: una copia vieja diciendo "en la nube" cuando el archivo ya está
     * es peor que un error de red.
     */
    Route::get('api/pistas/{pista}/estado', [PistaController::class, 'estado'])->name('pistas.estado');
    Route::get('api/pistas/metadatos', [PistaController::class, 'metadatos'])->name('pistas.metadatos');

    /*
     * Lo que está guardado en ESTE dispositivo. La pantalla no recibe datos del
     * servidor: los saca de la caché del navegador, porque lo que bajaste en el
     * teléfono no está en la computadora y el servidor no tiene forma de saberlo.
     */
    Route::inertia('descargas', 'Descargas')->name('descargas');

    /*
     * Va dentro del grupo con sesión, como todo lo demás.
     *
     * En movieboxd la misma pantalla es pública porque ahí hay algo que mostrarle
     * a quien llega de afuera. Dharmify no: la raíz manda al login y no existe
     * ninguna pantalla anónima, así que abrirle una excepción a ésta significaría
     * dibujarle la barra lateral entera —con su menú de cuenta— a alguien que no
     * tiene cuenta.
     */
    Route::inertia('acerca-de', 'AcercaDe')->name('acerca-de');

    /*
     * Favoritos y listas son de cada persona, no del catálogo: por eso no piden
     * ser administrador, y por eso cada consulta arranca desde el usuario.
     */
    Route::get('favoritos', [FavoritoController::class, 'index'])->name('favoritos');
    Route::post('favoritos/{pista}', [FavoritoController::class, 'alternar'])->name('favoritos.alternar');

    Route::get('listas', [ListaController::class, 'index'])->name('listas');
    Route::post('listas', [ListaController::class, 'store'])->name('listas.store');
    Route::get('listas/{lista}', [ListaController::class, 'show'])->name('listas.show');
    Route::patch('listas/{lista}', [ListaController::class, 'update'])->name('listas.update');
    Route::delete('listas/{lista}', [ListaController::class, 'destroy'])->name('listas.destroy');
    Route::post('listas/{lista}/pistas', [ListaController::class, 'agregar'])->name('listas.agregar');
    Route::delete('listas/{lista}/pistas/{pista}', [ListaController::class, 'quitar'])->name('listas.quitar');
    Route::patch('listas/{lista}/pistas/{pista}', [ListaController::class, 'mover'])->name('listas.mover');
});

require __DIR__.'/settings.php';
