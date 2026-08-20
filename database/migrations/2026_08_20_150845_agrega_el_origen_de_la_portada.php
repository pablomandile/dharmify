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
             * De dónde salió la carátula que se está mostrando.
             *
             * No es un dato decorativo: decide qué puede pisar qué. Una imagen
             * subida a mano no la reemplaza ningún barrido, y una generada sí
             * —al contrario, hay que poder reemplazarla el día que aparezca el
             * flyer de verdad—. Sin esta columna, "no tiene carátula" y "tiene
             * una que dibujamos nosotros" serían el mismo estado.
             */
            $table->string('portada_origen', 20)->nullable()->after('portada_revisada_en');
        });

        // Todo lo que ya hay salió de los archivos: del flyer de la carpeta o
        // del encabezado de un audio.
        DB::table('series')->whereNotNull('portada')->update(['portada_origen' => Serie::PORTADA_ARCHIVO]);
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('portada_origen');
        });
    }
};
