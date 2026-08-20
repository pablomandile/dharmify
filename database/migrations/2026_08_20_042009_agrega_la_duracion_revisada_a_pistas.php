<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pistas', function (Blueprint $table) {
            /*
             * Cuándo se leyó el encabezado buscando la duración, se haya podido
             * o no.
             *
             * Misma lección que con las carátulas: sin esta marca, una pista
             * cuyo encabezado no alcanza para calcular la duración queda con
             * `duracion_seg = null` y la consulta la vuelve a elegir en cada
             * tanda, así que el barrido gira sobre las primeras y nunca llega al
             * resto.
             *
             * Acá pesa más todavía: cada lectura cuesta unos 9 segundos de ida y
             * vuelta contra OneDrive, y son 928 pistas. Repetir es caro de verdad.
             */
            $table->timestamp('duracion_revisada_en')->nullable()->after('duracion_seg');
        });
    }

    public function down(): void
    {
        Schema::table('pistas', function (Blueprint $table) {
            $table->dropColumn('duracion_revisada_en');
        });
    }
};
