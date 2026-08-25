import { reactive, ref } from 'vue';

/**
 * Lo que está guardado en ESTE dispositivo.
 *
 * No se guarda en el servidor a propósito: la misma persona entra desde el
 * teléfono y desde la computadora, y lo que bajó en uno no está en el otro. La
 * única fuente de verdad son las claves de la caché del navegador.
 */
const CACHE_AUDIO = 'dharmify-audio-v1';

/** La ficha de cada pista bajada. */
export type FichaGuardada = {
    id: number;
    titulo: string;
    serie: string;
    serieId: number;
    portada: string | null;
    duracion_seg: number | null;
    bytes: number;
};

const guardadas = ref<Set<number>>(new Set());
const progreso = reactive<Record<number, string>>({});

const urlAudio = (id: number) => `/pistas/${id}/audio`;

/*
 * La ficha vive en la MISMA caché que el audio, con una URL inventada que nunca
 * se pide por red.
 *
 * Es lo que hace que la pantalla de Descargas abra sin conexión: si el título y
 * la carátula hubiera que pedírselos al servidor, la lista de lo que bajaste
 * para escuchar en el colectivo estaría vacía justo en el colectivo.
 */
const urlFicha = (id: number) => `/descargas/ficha/${id}.json`;

const idDeUrl = (url: string) => {
    const m = url.match(/\/pistas\/(\d+)\/audio/);

    return m ? Number(m[1]) : null;
};

/**
 * Si el audio de esta pista está guardado en ESTE dispositivo.
 *
 * Le pregunta a Cache Storage en vez de mirar `guardadas`, que se llena recién
 * cuando alguna pantalla llama a revisar(): darle play a algo que ya se bajó no
 * puede depender de que la pantalla de turno se haya acordado de refrescar el
 * listado. Es la pregunta que decide si hace falta la red o no.
 */
const estaGuardada = async (id: number): Promise<boolean> => {
    if (!('caches' in window)) {
        return false;
    }

    try {
        const cache = await caches.open(CACHE_AUDIO);

        return (await cache.match(urlAudio(id))) !== undefined;
    } catch {
        return false;
    }
};

/** Al abrir la app, se pinta lo que ya está guardado. */
const revisar = async () => {
    if (!('caches' in window)) {
        return;
    }

    const cache = await caches.open(CACHE_AUDIO);
    const claves = await cache.keys();
    const ids = new Set<number>();

    claves.forEach((req) => {
        const id = idDeUrl(req.url);

        if (id) {
            ids.add(id);
        }
    });

    guardadas.value = ids;
};

/**
 * Todo lo bajado, con su ficha y su peso real.
 *
 * El peso sale del `Content-Length` de la respuesta guardada y no de lo que diga
 * el catálogo: acá interesa cuánto ocupa en ESTE teléfono.
 */
const listar = async (): Promise<FichaGuardada[]> => {
    if (!('caches' in window)) {
        return [];
    }

    const cache = await caches.open(CACHE_AUDIO);
    const claves = await cache.keys();

    const ids = claves
        .map((req) => idDeUrl(req.url))
        .filter((id): id is number => id !== null);

    const fichas = await Promise.all(
        ids.map(async (id) => {
            const [guardada, audio] = await Promise.all([
                cache.match(urlFicha(id)),
                cache.match(urlAudio(id)),
            ]);

            const ficha: FichaGuardada | null = guardada
                ? await guardada.json().catch(() => null)
                : null;

            const bytes = Number(audio?.headers.get('Content-Length') ?? 0);

            if (ficha) {
                return { ...ficha, id, bytes: bytes || ficha.bytes };
            }

            // Bajada antes de que guardáramos la ficha. Se completa más abajo
            // pidiéndosela al servidor, si hay red.
            return {
                id,
                titulo: '',
                serie: '',
                serieId: 0,
                portada: null,
                duracion_seg: null,
                bytes,
            };
        }),
    );

    return await completarLasViejas(fichas);
};

/**
 * Rellena las fichas de lo que se bajó antes de esta versión.
 *
 * Sin red no se puede: quedan con el título vacío y la pantalla las muestra
 * igual, porque el audio está y se puede escuchar. Cuando haya conexión, la
 * ficha se guarda y el problema no vuelve.
 */
const completarLasViejas = async (
    fichas: FichaGuardada[],
): Promise<FichaGuardada[]> => {
    const faltan = fichas.filter((f) => !f.titulo).map((f) => f.id);

    if (!faltan.length || !navigator.onLine) {
        return fichas;
    }

    try {
        const res = await fetch(
            `/api/pistas/metadatos?ids=${faltan.join(',')}`,
            {
                headers: { Accept: 'application/json' },
            },
        );

        if (!res.ok) {
            return fichas;
        }

        const { pistas } = (await res.json()) as { pistas: FichaGuardada[] };
        const porId = new Map(pistas.map((p) => [p.id, p]));

        const cache = await caches.open(CACHE_AUDIO);

        await Promise.all(
            pistas.map((p) => cache.put(urlFicha(p.id), Response.json(p))),
        );

        return fichas.map((f) => {
            const traida = porId.get(f.id);

            return traida ? { ...traida, bytes: f.bytes || traida.bytes } : f;
        });
    } catch {
        return fichas;
    }
};

/**
 * Sin esto el navegador descarta lo guardado cuando necesita espacio, y la
 * persona se queda sin sus audios sin enterarse.
 */
const pedirPersistencia = async () => {
    if (navigator.storage?.persist && !(await navigator.storage.persisted())) {
        await navigator.storage.persist().catch(() => {});
    }
};

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

/**
 * Baja el audio y lo guarda en el dispositivo, mostrando el porcentaje.
 *
 * Contempla el 202: si el archivo está sólo en la nube hay que traerlo al
 * servidor primero, o se guardaría un JSON de 40 bytes en vez del audio.
 */
const guardar = async (id: number, ficha?: Omit<FichaGuardada, 'id'>) => {
    if (!('caches' in window)) {
        progreso[id] = 'Este navegador no puede guardar audio.';

        return;
    }

    await pedirPersistencia();
    progreso[id] = 'Preparando…';

    try {
        let res = await fetch(urlAudio(id));

        if (res.status === 202) {
            progreso[id] = 'Trayendo de la nube…';
            await fetch(`/pistas/${id}/restaurar`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            });
            res = await fetch(urlAudio(id));
        }

        if (!res.ok || !res.body) {
            throw new Error('no se pudo descargar');
        }

        const total = Number(res.headers.get('Content-Length') || 0);
        const lector = res.body.getReader();
        const partes: BlobPart[] = [];
        let recibido = 0;

        for (;;) {
            const { done, value } = await lector.read();

            if (done) {
                break;
            }

            partes.push(value);
            recibido += value.length;

            if (total) {
                progreso[id] = `${Math.round((recibido / total) * 100)}%`;
            }
        }

        const blob = new Blob(partes, { type: 'audio/mpeg' });
        const cache = await caches.open(CACHE_AUDIO);

        // Los headers explícitos no son decorativos: son los que después usa el
        // reproductor para poder adelantar sobre la copia guardada.
        await cache.put(
            urlAudio(id),
            new Response(blob, {
                status: 200,
                headers: {
                    'Content-Type': 'audio/mpeg',
                    'Content-Length': String(blob.size),
                    'Accept-Ranges': 'bytes',
                },
            }),
        );

        if (ficha) {
            await cache.put(
                urlFicha(id),
                Response.json({ ...ficha, id, bytes: blob.size }),
            );

            await guardarLaPortada(cache, ficha.portada);
        }

        guardadas.value = new Set([...guardadas.value, id]);
        delete progreso[id];
    } catch (e) {
        const lleno =
            e instanceof Error &&
            (e.name === 'QuotaExceededError' || /quota/i.test(e.message));

        progreso[id] = lleno
            ? 'No hay espacio en el dispositivo.'
            : 'No se pudo guardar.';
    }
};

/**
 * La carátula de la serie, junto al audio.
 *
 * Sin esto, la pantalla de Descargas sin conexión muestra la lista con todos los
 * huecos grises: lo que se bajó para el colectivo se ve peor justo en el
 * colectivo. Pesa unos 100 KB contra los 30 MB del audio.
 *
 * No se borra al quitar una pista: la comparten todas las de la serie y sale más
 * barato dejarla que llevar la cuenta de quién la usa.
 */
const guardarLaPortada = async (cache: Cache, portada: string | null) => {
    if (!portada || (await cache.match(portada))) {
        return;
    }

    try {
        const res = await fetch(portada);

        if (res.ok) {
            await cache.put(portada, res);
        }
    } catch {
        // Que no haya carátula no es motivo para dar por fallada la descarga.
    }
};

const borrar = async (id: number) => {
    const cache = await caches.open(CACHE_AUDIO);
    await Promise.all([cache.delete(urlAudio(id)), cache.delete(urlFicha(id))]);

    const copia = new Set(guardadas.value);
    copia.delete(id);
    guardadas.value = copia;
};

/** Cuánto ocupa la app en el dispositivo y cuánto lugar queda. */
const espacio = async (): Promise<{ usado: number; total: number } | null> => {
    if (!navigator.storage?.estimate) {
        return null;
    }

    const { usage, quota } = await navigator.storage.estimate();

    return { usado: usage ?? 0, total: quota ?? 0 };
};

export function useOffline() {
    return {
        guardadas,
        progreso,
        revisar,
        estaGuardada,
        listar,
        guardar,
        borrar,
        espacio,
    };
}
