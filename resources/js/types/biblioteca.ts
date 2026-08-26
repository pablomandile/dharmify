/**
 * Lo que el servidor manda de cada enseñanza.
 *
 * Es una sola forma para las cuatro pantallas que muestran pistas —la serie, los
 * favoritos, una lista y las descargas— y coincide con `Pista::ficha()` del
 * backend. Cuando se agregó la duración hubo que tocar cuatro lugares; con una
 * sola forma, agregar un campo es un lugar de cada lado.
 */
export type FichaDePista = {
    id: number;
    titulo: string;
    serie: string;
    serieId: number;
    portada: string | null;
    duracion_seg: number | null;
    bytes: number;
    grabada_el: string | null;
    en_server: boolean;
    en_nube: boolean;
    /** Si hay texto para leer. El texto en sí se pide aparte, al abrirlo. */
    transcripcion: boolean;
    favorita: boolean;
};

export type ListaBreve = {
    id: number;
    nombre: string;
    pistas?: number;
};
