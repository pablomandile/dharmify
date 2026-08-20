<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * De dónde sale la biblioteca. Son varias a propósito: la privada y la
         * pública viven en carpetas distintas de OneDrive, y puede haber más.
         */
        Schema::create('fuentes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');

            /*
             * `rclone` es el modo de producción: el server no tiene la carpeta
             * sincronizada y habla con OneDrive por la API.
             *
             * `local` existe para desarrollo, contra la carpeta que sincroniza
             * OneDrive en Windows. Leer nombres, tamaños y rutas de ahí NO baja
             * los archivos, así que sirve para armar el catálogo sin descargar
             * decenas de gigas.
             */
            $table->string('tipo')->default('rclone');

            // "onedrive:Budismo Kadampa/Dharma/Enseñanzas" o una ruta del disco.
            $table->string('ruta', 1024);

            // Quién puede escuchar lo que entra por acá.
            $table->string('visibilidad')->default('privada');

            $table->boolean('activa')->default(true);
            $table->timestamp('revisada_en')->nullable();
            $table->timestamps();
        });

        Schema::create('maestros', function (Blueprint $table) {
            $table->id();

            /*
             * El nombre canónico que devuelve TaxonomiaDeCarpeta. Único: la misma
             * persona aparece en las carpetas como "Dekyong", "Guenla Dekyong" y
             * "G.Dekyong", y el parser ya las unifica antes de llegar acá.
             */
            $table->string('nombre')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        /*
         * Una serie es una carpeta de primer nivel dentro de la fuente: un
         * retiro, un curso, un festival. Es la separación que ya existe en
         * OneDrive y que la app respeta tal cual.
         */
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();

            // La ruta relativa a la fuente. Es lo que identifica a la serie entre
            // revisiones, y por eso es única dentro de cada fuente.
            $table->string('carpeta', 1024);
            $table->string('carpeta_hash', 64);

            $table->string('titulo');
            $table->string('slug')->index();
            $table->string('tipo')->nullable();      // retiro, curso, festival…
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('idioma', 5)->default('es');
            $table->string('portada')->nullable();

            /*
             * Lo que el administrador corrigió a mano no se vuelve a pisar en la
             * próxima revisión: el parser resuelve bien el 89%, y el resto se
             * arregla una vez y queda.
             */
            $table->boolean('editada_a_mano')->default(false);

            $table->timestamps();

            $table->unique(['fuente_id', 'carpeta_hash']);
        });

        Schema::create('maestro_serie', function (Blueprint $table) {
            $table->foreignId('serie_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('maestro_id')->constrained('maestros')->cascadeOnDelete();
            $table->primary(['serie_id', 'maestro_id']);
        });

        Schema::create('pistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serie_id')->constrained('series')->cascadeOnDelete();

            /*
             * La clave estable. Sale del hash de la ruta completa dentro de la
             * fuente: medido sobre la biblioteca real, 157 nombres de archivo se
             * repiten (el mismo canto en 30 álbumes) pero NINGUNA ruta completa
             * se repite.
             */
            $table->string('clave', 64)->unique();

            $table->string('titulo');
            $table->string('archivo');
            $table->string('ruta', 1024);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('duracion_seg')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->date('grabada_el')->nullable();

            /*
             * Dónde vive el archivo. El catálogo es la fuente de verdad, no la
             * carpeta del server: una pista sigue existiendo en la biblioteca
             * aunque la purga se haya llevado su archivo.
             */
            $table->boolean('en_server')->default(false);
            $table->boolean('en_nube')->default(true);

            $table->timestamp('ultimo_acceso_en')->nullable();
            $table->unsignedInteger('reproducciones')->default(0);

            $table->timestamps();

            $table->index(['serie_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pistas');
        Schema::dropIfExists('maestro_serie');
        Schema::dropIfExists('series');
        Schema::dropIfExists('maestros');
        Schema::dropIfExists('fuentes');
    }
};
