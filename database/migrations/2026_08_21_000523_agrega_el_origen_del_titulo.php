<?php

use App\Models\Serie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            /*
             * De dónde salió el título que se muestra.
             *
             * Hace falta porque cada escaneo recalcula el título a partir del
             * nombre de la carpeta: sin esta columna, un título tomado de la
             * etiqueta del mp3 duraría hasta el próximo «Refrescar biblioteca».
             *
             * No alcanzaba con `editada_a_mano`, que ya existe: esa marca congela
             * TODO —tipo, año, idioma y maestros—, y esos datos están en el
             * nombre de la carpeta y no en las etiquetas. Acá sólo se quiere
             * congelar el título.
             */
            $table->string('titulo_origen', 20)->default(Serie::TITULO_CARPETA)->after('titulo');
        });

        // Todo lo que hay salió del nombre de la carpeta.
        DB::table('series')->update(['titulo_origen' => Serie::TITULO_CARPETA]);
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('titulo_origen');
        });
    }
};
