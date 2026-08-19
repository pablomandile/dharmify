<?php

namespace App\Importacion;

/**
 * Convierte el nombre de una carpeta de OneDrive en datos.
 *
 * Los nombres siguen casi siempre "<tipo> <título> <año> <maestro> [<idioma>]",
 * pero con mucha variación: abreviaturas ("G.Dekyong"), apellidos sueltos
 * ("Prajnaparamita Kunsang Dekyong Jampa"), y varios maestros en un festival.
 *
 * Es deliberadamente conservador: prefiere devolver `null` a inventar. Lo que no
 * resuelve queda para que el administrador lo corrija a mano, que sale mucho más
 * barato que descubrir dentro de un año que media biblioteca quedó mal clasificada.
 */
class TaxonomiaDeCarpeta
{
    /**
     * Variante que aparece en las carpetas => nombre canónico del maestro.
     *
     * El orden importa dos veces:
     *
     * 1. Del más específico al más general, o "Rinchung" le gana a
     *    "Guen Kelsang Rinchung" y el nombre largo queda partido.
     * 2. La misma persona aparece escrita de varias formas —"Dekyong",
     *    "Guenla Dekyong", "G.Dekyong"— y sin unificarlas la biblioteca termina
     *    con el mismo maestro repetido tres veces. En la tradición, "Guen",
     *    "Guenla" y "Gueshe" son títulos y "Kelsang" es el nombre de ordenación:
     *    por eso "Guen Rinchung" y "Guen Kelsang Rinchung" son uno solo.
     *
     * NO agregar acá nombres de deidades ni de temas (Tara, Avalokiteshvara,
     * Vajrayoguini, Je Tsongkhapa, Prajnaparamita...): aparecen en los mismos
     * nombres de carpeta y confundirlos convierte el tema en el maestro.
     */
    private const MAESTROS = [
        'Gueshe Kelsang Gyatso' => 'Gueshe Kelsang Gyatso',
        'Guen Kelsang Rinchung' => 'Guen Kelsang Rinchung',
        'Guenla Kelsang Jampa' => 'Guenla Kelsang Jampa',
        'Kelsang Sangton' => 'Kelsang Sangton',
        'Kelsang Panchen' => 'Kelsang Panchen',
        'Kelsang Loten' => 'Kelsang Loten',
        'Guenla Khyenrab' => 'Guenla Khyenrab',
        'Guenla Kyenrab' => 'Guenla Khyenrab',
        'Guenla Dekyong' => 'Guenla Dekyong',
        'Guenla Kunsang' => 'Guenla Kunsang',
        'Guenla Jampa' => 'Guenla Kelsang Jampa',
        'Guen Rinchung' => 'Guen Kelsang Rinchung',
        'GuenTogden' => 'Guen Togden',
        'Guen Togden' => 'Guen Togden',
        'Guen Namdrol' => 'Guen Namdrol',
        'Guen Lochani' => 'Guen Lochani',
        'Guen Sangden' => 'Guen Sangden',
        'Guen Losang' => 'Guen Losang',
        'Guen Atisha' => 'Guen Atisha',
        'Guen Rabten' => 'Guen Rabten',
        'Guen Tsultrim' => 'Guen Tsultrim',
        'Guen Sangton' => 'Kelsang Sangton',
        'Carola Troncoso' => 'Carola Troncoso',
        'Nicolás Caputo' => 'Nicolás Caputo',
        'Adrian Izbizqui' => 'Adrian Izbizqui',
        'Christian Lange' => 'Christian Lange',
        'Juan Sebastián' => 'Juan Sebastián',
        'Mauro Arroyo' => 'Mauro Arroyo',
        'Sebas Arias' => 'Sebas Arias',
        'Vicky Gille' => 'Vicky Gille',
        'Maru Chaves' => 'Maru Chaves',
        'Ana Paula' => 'Ana Paula',
        'Clau Moli' => 'Clau Moli',
        'Chodzom' => 'Chodzom',
        // Apellidos sueltos, al final para no ganarle a los nombres completos.
        'Khyenrab' => 'Guenla Khyenrab',
        'Kyenrab' => 'Guenla Khyenrab',
        'Dekyong' => 'Guenla Dekyong',
        'Kunsang' => 'Guenla Kunsang',
        'Togden' => 'Guen Togden',
        'Lochani' => 'Guen Lochani',
        'Rinchung' => 'Guen Kelsang Rinchung',
        'Namdrol' => 'Guen Namdrol',
        'Sangden' => 'Guen Sangden',
        'Losang' => 'Guen Losang',
        'Rabten' => 'Guen Rabten',
        'Tsultrim' => 'Guen Tsultrim',
        'Sangton' => 'Kelsang Sangton',
        'Panchen' => 'Kelsang Panchen',
        'Loten' => 'Kelsang Loten',
        'Jampa' => 'Guenla Kelsang Jampa',
        'Atisha' => 'Guen Atisha',
        'Edgardo' => 'Edgardo',
        'Silvina' => 'Silvina',
        'Cecilia' => 'Cecilia',
        'Carola' => 'Carola Troncoso',
        'Maflo' => 'Maflo',
        'Mauro' => 'Mauro Arroyo',
        'Norberto' => 'Norberto',
        'Christian' => 'Christian Lange',
        'Juanse' => 'Juan Sebastián',
        'Sebas' => 'Sebas Arias',
        'Vicky' => 'Vicky Gille',
        'Erica' => 'Erica',
        'Fabio' => 'Fabio',
        'Flor' => 'Flor',
        'Maru' => 'Maru Chaves',
    ];

    /** El prefijo del nombre dice qué clase de evento fue. */
    private const TIPOS = [
        'Summer Festival' => 'festival',
        'Fall Festival' => 'festival',
        'Spring Festival' => 'festival',
        'Festival Argentino' => 'festival',
        'Festival Brasilero' => 'festival',
        'Festival Nacional' => 'festival',
        'Celebración Argentina' => 'festival',
        'California Dharma Celebration' => 'festival',
        'Aniversario' => 'festival',
        'Retiro' => 'retiro',
        'Curso' => 'curso',
        'Course' => 'curso',
        'Clases de' => 'curso',
        'Clase' => 'curso',
        'Iniciación' => 'iniciacion',
        'Iniciacion' => 'iniciacion',
        'PGA' => 'programa',
        'PG' => 'programa',
        'Charla pública' => 'charla',
        'Charla' => 'charla',
        'Entrevista' => 'entrevista',
        'Temple Tours' => 'visita',
        'Visita guiada' => 'visita',
        'Special Prayers' => 'oraciones',
        'Post Tsog' => 'practica',
    ];

    /**
     * @param  list<string>  $maestros
     */
    public function __construct(
        public readonly ?string $tipo,
        public readonly ?int $anio,
        public readonly string $idioma,
        public readonly array $maestros,
        public readonly string $titulo,
    ) {}

    public static function desde(string $carpeta): self
    {
        // "G.Dekyong" y "Guen.Lochani" son las mismas personas que "Guen Dekyong":
        // se normaliza la abreviatura antes de buscar nada.
        $resto = preg_replace('/\bG(uen)?\.\s*/u', 'Guen ', $carpeta);

        [$idioma, $resto] = self::sacarIdioma($resto);
        [$anio, $resto] = self::sacarAnio($resto);
        [$tipo, $resto] = self::sacarTipo($resto);
        [$maestros, $resto] = self::sacarMaestros($resto);

        return new self(
            tipo: $tipo,
            anio: $anio,
            idioma: $idioma,
            maestros: $maestros,
            titulo: trim(preg_replace('/\s{2,}/u', ' ', $resto), " \t-–—.,()"),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function sacarIdioma(string $resto): array
    {
        // Las palabras enteras se pueden buscar en cualquier lugar del nombre
        // ("Retiro Post-Summer Festival 2016 English-Menorca").
        if (preg_match('/\b(ingl[ée]s|english)\b/iu', $resto, $m)) {
            return ['en', str_ireplace($m[0], ' ', $resto)];
        }

        if (preg_match('/\b(espa[nñ]ol|spanish)\b/iu', $resto, $m)) {
            return ['es', str_ireplace($m[0], ' ', $resto)];
        }

        /*
         * Las siglas EN y ES, en cambio, SOLO al final y SOLO en mayúsculas.
         *
         * Sin esas dos restricciones, "en" y "es" —dos de las palabras más
         * comunes del castellano— convierten en inglesa cualquier serie cuyo
         * título lleve una preposición: "Clase Confianza EN el guía espiritual"
         * quedaba marcada como inglesa y encima perdía la palabra.
         */
        if (preg_match('/[\s(]+(EN|ES)\)?\s*$/u', $resto, $m)) {
            return [strtolower($m[1]), substr($resto, 0, -strlen($m[0]))];
        }

        // Todo lo que no diga lo contrario es castellano.
        return ['es', $resto];
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private static function sacarAnio(string $resto): array
    {
        if (! preg_match('/\b(19|20)\d{2}\b/', $resto, $m, PREG_OFFSET_CAPTURE)) {
            return [null, $resto];
        }

        return [
            (int) $m[0][0],
            substr_replace($resto, ' ', $m[0][1], strlen($m[0][0])),
        ];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function sacarTipo(string $resto): array
    {
        $limpio = ltrim($resto);

        foreach (self::TIPOS as $prefijo => $tipo) {
            if (mb_stripos($limpio, $prefijo) === 0) {
                return [$tipo, mb_substr($limpio, mb_strlen($prefijo))];
            }
        }

        return [null, $resto];
    }

    /**
     * Puede haber varios: un festival con tres maestros es lo normal.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function sacarMaestros(string $resto): array
    {
        $encontrados = [];

        foreach (self::MAESTROS as $variante => $canonico) {
            $patron = '/(?<!\p{L})'.preg_quote($variante, '/').'(?!\p{L})/iu';

            if (! preg_match($patron, $resto)) {
                continue;
            }

            // Una carpeta puede nombrar dos veces a la misma persona ("Guenla
            // Dekyong ... Dekyong"): se guarda el canónico una sola vez, pero el
            // texto se consume igual para que no ensucie el título.
            if (! in_array($canonico, $encontrados, strict: true)) {
                $encontrados[] = $canonico;
            }

            $resto = preg_replace($patron, ' ', $resto, 1);
        }

        return [$encontrados, $resto];
    }
}
