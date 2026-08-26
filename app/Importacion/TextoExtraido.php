<?php

namespace App\Importacion;

/**
 * Lo que se pudo sacar de un documento de transcripción.
 *
 * Son dos cosas y no una porque un `.srt` trae texto Y tiempos, y los tiempos
 * hay que guardarlos aunque el resaltado del texto al ritmo del audio se haga
 * más adelante: si se guardara sólo el texto aplanado, después habría que
 * volver a importar los 709 archivos para recuperarlos.
 */
class TextoExtraido
{
    /**
     * @param  list<array{inicio: float, fin: float, texto: string}>|null  $marcas
     * @param  string|null  $encabezado  lo que va antes de la primera marca
     */
    public function __construct(
        public readonly string $texto,
        public readonly ?array $marcas = null,
        public readonly ?string $encabezado = null,
    ) {}

    /**
     * Cuántas palabras, para poder decir "unas 4.200" sin recontar en cada
     * carga de la pantalla.
     *
     * `str_word_count` no sirve acá: se come los acentos y parte "meditación"
     * en dos. La clase \p{L} de una expresión regular Unicode sí los cuenta.
     */
    public function palabras(): int
    {
        return preg_match_all("/\p{L}[\p{L}\p{M}'’-]*/u", $this->texto) ?: 0;
    }
}
