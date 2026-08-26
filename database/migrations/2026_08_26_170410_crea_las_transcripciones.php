<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * El texto de cada enseñanza, para leerlo mientras suena.
         *
         * Tabla aparte y no columnas en `pistas` por una razón concreta: el
         * texto de una charla de dos horas son decenas de KB, y `pistas` se
         * consulta en la grilla, los favoritos, las listas y las descargas.
         * Metido ahí, cada listado arrastraría megabytes que nadie va a leer.
         */
        Schema::create('transcripciones', function (Blueprint $table) {
            $table->id();

            // Una por pista. Si se borra la pista, se va con ella.
            $table->foreignId('pista_id')->unique()->constrained('pistas')->cascadeOnDelete();

            // El nombre original, que es lo que se ofrece al descargar.
            $table->string('archivo');
            $table->string('formato', 10);

            /*
             * De dónde salió. La biblioteca ya traía 709 documentos al lado de
             * los audios, así que casi todas nacen `nube`; `manual` es lo que
             * alguien subió a propósito.
             */
            $table->string('origen', 10);

            /*
             * Nullable porque de los `.doc` viejos —12 en la biblioteca— no se
             * puede sacar el texto sin una librería que no está. Esos quedan
             * con el original descargable y nada más.
             */
            $table->longText('texto')->nullable();

            /*
             * Las marcas de tiempo de un `.srt`, una por línea con su inicio y
             * su fin.
             *
             * Se guardan AHORA aunque el resaltado del texto al ritmo del audio
             * se haga después: si se guardara sólo el texto aplanado, más
             * adelante habría que volver a importar los archivos para
             * recuperarlas.
             */
            $table->json('marcas')->nullable();

            // Contadas una vez acá y no en cada carga de la pantalla.
            $table->unsignedInteger('palabras')->default(0);
            $table->unsignedInteger('bytes')->default(0);

            $table->timestamps();
        });

        Schema::table('pistas', function (Blueprint $table) {
            /*
             * Calcada de `duracion_revisada_en`, y por el mismo motivo: es lo
             * que hace el barrido reanudable. Marca también las pistas que se
             * miraron y NO tenían documento al lado, para no volver a buscarlo
             * en cada corrida — de otro modo, las que no tienen se revisarían
             * para siempre.
             */
            $table->timestamp('transcripcion_revisada_en')->nullable()->after('duracion_revisada_en');
        });
    }

    public function down(): void
    {
        Schema::table('pistas', function (Blueprint $table) {
            $table->dropColumn('transcripcion_revisada_en');
        });

        Schema::dropIfExists('transcripciones');
    }
};
