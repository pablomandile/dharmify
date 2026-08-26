<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            /*
             * Lo que hay en el documento ANTES de la primera marca de tiempo:
             * casi siempre el título repetido arriba de todo.
             *
             * Hasta ahora se guardaba mezclado dentro de `texto` y sin frontera,
             * porque sólo se leía. Al poder editar hay que volver a escribir el
             * documento, y sin saber dónde termina el encabezado cada guardado
             * le comería esa línea — en silencio y sobre 644 archivos.
             *
             * Queda duplicado dentro de `texto` a propósito: `texto` es lo que
             * se lee y lo que algún día se va a poder buscar, y sacarle el
             * encabezado lo dejaría incompleto.
             */
            $table->text('encabezado')->nullable()->after('formato');
        });
    }

    public function down(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            $table->dropColumn('encabezado');
        });
    }
};
