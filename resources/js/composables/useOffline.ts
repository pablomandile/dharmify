import { reactive, ref } from 'vue';

/**
 * Lo que está guardado en ESTE dispositivo.
 *
 * No se guarda en el servidor a propósito: la misma persona entra desde el
 * teléfono y desde la computadora, y lo que bajó en uno no está en el otro. La
 * única fuente de verdad son las claves de la caché del navegador.
 */
const CACHE_AUDIO = 'dharmify-audio-v1';

const guardadas = ref<Set<number>>(new Set());
const progreso = reactive<Record<number, string>>({});

const urlAudio = (id: number) => `/pistas/${id}/audio`;

const idDeUrl = (url: string) => {
    const m = url.match(/\/pistas\/(\d+)\/audio/);

    return m ? Number(m[1]) : null;
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
const guardar = async (id: number) => {
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

const borrar = async (id: number) => {
    const cache = await caches.open(CACHE_AUDIO);
    await cache.delete(urlAudio(id));

    const copia = new Set(guardadas.value);
    copia.delete(id);
    guardadas.value = copia;
};

export function useOffline() {
    return { guardadas, progreso, revisar, guardar, borrar };
}
