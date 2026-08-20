<?php

namespace App\Importacion;

/**
 * Saca un título presentable del nombre del archivo.
 *
 * Los nombres reales de la biblioteca son un mosaico: numerados con título
 * ("01 Oración del Guru Fundador.mp3"), fecha y hora de la grabadora
 * ("2020.01.10_19.27_01.MP3"), y códigos de grabadora sueltos
 * ("DVT_B001_130530_1516.MP3").
 *
 * Es deliberadamente conservador: ante la duda deja el nombre como está. Un
 * título feo se corrige a mano; uno recortado de más pierde información y nadie
 * se entera.
 */
class TituloDeArchivo
{
    public static function desde(string $archivo): string
    {
        $base = pathinfo($archivo, PATHINFO_FILENAME);

        // Fecha y hora de grabadora: no hay título que rescatar, se muestra la
        // fecha, que es lo único que significa algo.
        if (preg_match('/^(\d{4})\.(\d{2})\.(\d{2})[_ ](\d{2})\.(\d{2})/', $base, $m)) {
            return "Grabación del {$m[3]}/{$m[2]}/{$m[1]}, {$m[4]}:{$m[5]}";
        }

        // Número de pista al principio: "01 ", "14g ", "20c - ".
        $limpio = preg_replace('/^\s*\d{1,3}[a-z]?\s*[-–.)]?\s+/ui', '', $base);

        // Guiones bajos como separador, que vienen de nombres sin espacios.
        $limpio = str_replace('_', ' ', (string) $limpio);
        $limpio = trim(preg_replace('/\s{2,}/u', ' ', $limpio));

        return $limpio !== '' ? $limpio : $base;
    }

    /**
     * La fecha de grabación, cuando el nombre del archivo la trae.
     *
     * Da orden cronológico dentro de un retiro largo, que es justo donde el
     * número de pista no alcanza porque las carpetas mezclan sesiones de días
     * distintos.
     */
    public static function fechaDe(string $archivo): ?string
    {
        $base = pathinfo($archivo, PATHINFO_FILENAME);

        if (preg_match('/(20\d{2})\.(\d{2})\.(\d{2})/', $base, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }
}
