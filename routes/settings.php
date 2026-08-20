<?php

use App\Http\Controllers\FuenteController;
use App\Http\Controllers\RefrescarBibliotecaController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

/*
 * De dónde sale la biblioteca. Sólo el administrador: a quien invitás le toca
 * escuchar, no configurar el origen del contenido.
 */
Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('settings/fuentes', [FuenteController::class, 'index'])->name('fuentes.index');
    Route::post('settings/fuentes', [FuenteController::class, 'store'])->name('fuentes.store');
    Route::put('settings/fuentes/{fuente}', [FuenteController::class, 'update'])->name('fuentes.update');
    Route::delete('settings/fuentes/{fuente}', [FuenteController::class, 'destroy'])->name('fuentes.destroy');
    Route::post('settings/fuentes/{fuente}/probar', [FuenteController::class, 'probar'])->name('fuentes.probar');

    // Throttle: el escaneo recorre la nube entera, no es algo para repetir a
    // los golpes de clic mientras el anterior sigue corriendo.
    Route::post('biblioteca/refrescar', RefrescarBibliotecaController::class)
        ->middleware('throttle:6,5')
        ->name('biblioteca.refrescar');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
