import { computed, ref } from 'vue';

export type Marca = {
    inicio: number;
    fin: number;
    texto: string;
};

export type FichaDeTranscripcion = {
    pista: number;
    titulo: string;
    serie: string;
    serieId: number;
    formato: string;
    palabras: number;
    texto: string | null;
    /** Sólo en .srt y .vtt. Hoy no se usan: son para el resaltado que viene. */
    marcas: Marca[] | null;
    seEmbebe: boolean;
    urlVer: string | null;
    urlBajar: string;
    urlCompleta: string;
    archivo: string;
};

/**
 * El panel de lectura.
 *
 * Vive en el módulo y no en cada fila por lo mismo que el reproductor: es UNO
 * solo para toda la app. Con un panel por fila, un retiro de cincuenta clases
 * montaría cincuenta diálogos que nadie va a abrir.
 */
const abierto = ref(false);
const cargando = ref(false);
const error = ref<string | null>(null);
const ficha = ref<FichaDeTranscripcion | null>(null);

/**
 * Que no haya transcripción NO es un error.
 *
 * Es un estado con su propia pantalla —para el administrador, la de subir una—
 * y confundirlo con un fallo le diría a la persona que algo se rompió cuando en
 * realidad no hay nada que mostrar todavía.
 */
const sinTranscripcion = ref(false);

/** Lo que se muestra mientras llega: el título ya lo sabe quien abre. */
const titulo = ref('');

const urlFicha = (id: number) => `/pistas/${id}/transcripcion.json`;

/** Cuál está abierta, para poder recargarla después de subir una nueva. */
const pistaAbierta = ref<number | null>(null);

const abrir = async (id: number, tituloDeLaPista: string) => {
    titulo.value = tituloDeLaPista;
    abierto.value = true;
    error.value = null;
    pistaAbierta.value = id;

    // Si ya está la de esta pista, no se vuelve a pedir: pasa todo el tiempo al
    // cerrar y volver a abrir la misma mientras se escucha.
    if (ficha.value?.pista === id) {
        return;
    }

    await traer(id);
};

/** Vuelve a pedirla sin importar qué haya cargado: se usa al subir una nueva. */
const recargar = async () => {
    if (pistaAbierta.value !== null) {
        await traer(pistaAbierta.value);
    }
};

const traer = async (id: number) => {
    ficha.value = null;
    sinTranscripcion.value = false;
    cargando.value = true;

    try {
        const res = await fetch(urlFicha(id), {
            headers: { Accept: 'application/json' },
        });

        // 404 acá significa "todavía no hay", no "se rompió".
        if (res.status === 404) {
            sinTranscripcion.value = true;

            return;
        }

        if (!res.ok) {
            throw new Error('no se pudo');
        }

        ficha.value = (await res.json()) as FichaDeTranscripcion;
    } catch {
        /*
         * Sin conexión, esto sólo llega si la pista se bajó al dispositivo: la
         * transcripción viaja con el audio. El mensaje lo dice, porque
         * "falló" no le explica a nadie por qué unas sí y otras no.
         */
        error.value = navigator.onLine
            ? 'No se pudo abrir la transcripción.'
            : 'Sin conexión: sólo están las de lo que bajaste al dispositivo.';
    } finally {
        cargando.value = false;
    }
};

const cerrar = () => {
    abierto.value = false;
};

export function useTranscripcion() {
    return {
        abierto,
        cargando,
        error,
        ficha,
        sinTranscripcion,
        pistaAbierta,
        titulo,
        abrir,
        recargar,
        cerrar,
        hayTexto: computed(() => Boolean(ficha.value?.texto)),
    };
}
