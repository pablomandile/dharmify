<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            /*
             * Cuándo se buscó la carátula, exista o no.
             *
             * Sin esto, una serie cuyo audio no trae imagen queda con
             * `portada = null` para siempre y la consulta la vuelve a elegir en
             * cada tanda: el proceso se queda girando sobre las primeras y
             * nunca llega al resto. Pasó de verdad — cinco tandas de 30 sobre la
             * misma cuarentena de series.
             *
             * Distinguir "sin revisar" de "revisada y no tenía" es lo que hace
             * que el trabajo avance.
             */
            $table->timestamp('portada_revisada_en')->nullable()->after('portada');
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('portada_revisada_en');
        });
    }
};
