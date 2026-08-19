<?php

namespace Tests\Unit;

use App\Importacion\TaxonomiaDeCarpeta;
use PHPUnit\Framework\TestCase;

class TaxonomiaDeCarpetaTest extends TestCase
{
    public function test_saca_tipo_titulo_anio_y_maestro_de_un_curso(): void
    {
        $t = TaxonomiaDeCarpeta::desde('Curso Cómo morir bien 2014 Guen Rinchung');

        $this->assertSame('curso', $t->tipo);
        $this->assertSame('Cómo morir bien', $t->titulo);
        $this->assertSame(2014, $t->anio);
        $this->assertSame(['Guen Kelsang Rinchung'], $t->maestros);
        $this->assertSame('es', $t->idioma);
    }

    public function test_reconoce_varios_maestros_en_un_festival(): void
    {
        $t = TaxonomiaDeCarpeta::desde('Summer FestIval UK 2024 Prajnaparamita Kunsang Dekyong Jampa EN');

        $this->assertSame('festival', $t->tipo);
        $this->assertSame(2024, $t->anio);
        $this->assertSame('en', $t->idioma);
        $this->assertEqualsCanonicalizing(
            ['Guenla Kunsang', 'Guenla Dekyong', 'Guenla Kelsang Jampa'],
            $t->maestros,
        );

        // El tema (una deidad o un texto) NO es el maestro: tiene que quedar
        // en el título, no en la lista de maestros.
        $this->assertStringContainsString('Prajnaparamita', $t->titulo);
    }

    /**
     * La trampa que costó una iteración entera.
     *
     * "en" y "es" son dos de las palabras más comunes del castellano. Buscar las
     * siglas de idioma en cualquier parte del nombre marcaba como inglesa
     * cualquier serie cuyo título llevara una preposición, y encima le comía la
     * palabra al título.
     */
    public function test_no_confunde_la_preposicion_en_con_el_idioma_ingles(): void
    {
        $t = TaxonomiaDeCarpeta::desde('Clase Confianza en el guía Espiritual 2011 Guen Togden');

        $this->assertSame('es', $t->idioma);
        $this->assertSame('Confianza en el guía Espiritual', $t->titulo);
        $this->assertSame(['Guen Togden'], $t->maestros);
    }

    public function test_la_sigla_de_idioma_vale_solo_al_final_y_en_mayusculas(): void
    {
        $this->assertSame('en', TaxonomiaDeCarpeta::desde('Fall Festival Brasil 2024 EN')->idioma);
        $this->assertSame('es', TaxonomiaDeCarpeta::desde('Festival Nacional 2025 (Español)')->idioma);
        $this->assertSame('en', TaxonomiaDeCarpeta::desde('Retiro Post-Summer Festival 2016 English-Menorca IRC')->idioma);
    }

    public function test_normaliza_las_abreviaturas_de_guen(): void
    {
        $t = TaxonomiaDeCarpeta::desde('Fall Festival Brasil G.Dekyong G.Jampa 2024 EN');

        $this->assertEqualsCanonicalizing(['Guenla Dekyong', 'Guenla Kelsang Jampa'], $t->maestros);
    }

    public function test_el_nombre_largo_le_gana_al_apellido_suelto(): void
    {
        $t = TaxonomiaDeCarpeta::desde('PG Guen Kelsang Rinchung');

        $this->assertSame(['Guen Kelsang Rinchung'], $t->maestros);
        $this->assertSame('programa', $t->tipo);
    }

    /**
     * El Programa General corre todo el año y no tiene edición ni fecha: que no
     * haya año es un dato correcto, no un fallo del parser.
     */
    public function test_acepta_que_no_haya_anio(): void
    {
        $t = TaxonomiaDeCarpeta::desde('PG GuenTogden');

        $this->assertNull($t->anio);
        $this->assertSame('programa', $t->tipo);
        $this->assertSame(['Guen Togden'], $t->maestros);
    }

    /**
     * La misma persona aparece escrita de varias formas según quién nombró la
     * carpeta. Sin unificarlas, la biblioteca muestra tres maestros distintos
     * donde hay uno solo, y el filtro "todo lo de Guen Togden" trae un tercio.
     */
    public function test_unifica_las_variantes_del_nombre_de_un_maestro(): void
    {
        $variantes = ['PG GuenTogden', 'Curso Tomar y dar 2012 Guen Togden', 'Retiro 2011 Togden'];

        foreach ($variantes as $carpeta) {
            $this->assertSame(
                ['Guen Togden'],
                TaxonomiaDeCarpeta::desde($carpeta)->maestros,
                "no unificó: $carpeta",
            );
        }

        // "Guen" y "Guenla" son títulos y "Kelsang" el nombre de ordenación:
        // las tres formas nombran a la misma persona.
        $this->assertSame(
            ['Guen Kelsang Rinchung'],
            TaxonomiaDeCarpeta::desde('Curso Cómo morir bien 2014 Guen Rinchung')->maestros,
        );
    }

    public function test_prefiere_no_adivinar_cuando_no_hay_maestro(): void
    {
        $t = TaxonomiaDeCarpeta::desde('Celebración Argentina del Dharma 4 B.Medicina 2017');

        $this->assertSame([], $t->maestros);
        $this->assertSame('festival', $t->tipo);
        $this->assertSame(2017, $t->anio);
    }
}
