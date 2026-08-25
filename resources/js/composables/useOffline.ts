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
 * Si lo guardado es audio de verdad y no otra cosa disfrazada.
 *
 * Hace falta porque `Response.ok` da true para un 202, y el 202 es justo lo que
 * contesta el servidor —con un JSON de "Trayendo de la nube…"— cuando el
 * archivo todavía está sólo en la nube. La versión anterior guardaba ESE JSON
 * como si fuera el audio: aparecía el tilde verde y el play fallaba.
 *
 * Mira el primer byte y NO el tamaño, aunque el JSON pese 55 bytes: la pista
 * más corta de esta biblioteca pesa 17 KB y hay seis abajo del mega, así que
 * cualquier umbral por peso daría por rotos archivos buenos.
 *
 * Y descarta por lo que NO puede ser en vez de exigir una firma de mp3, porque
 * 27 de las 928 son m4a y su cabecera es otra. Un audio jamás empieza con `{`
 * (el JSON del 202) ni con `<` (la página de login, si venció la sesión).
 */
const esAudio = async (res: Response | undefined): Promise<boolean> => {
    if (!res) {
        return false;
    }

    try {
        const blob = await res.clone().blob();

        if (blob.size === 0) {
            return false;
        }

        const primero = new Uint8Array(await blob.slice(0, 1).arrayBuffer())[0];

        return primero !== 0x7b && primero !== 0x3c;
    } catch {
        return false;
    }
};

/**
 * Si el audio de esta pista está guardado en ESTE dispositivo.
 *
 * Le pregunta a Cache Storage en vez de mirar `guardadas`, que se llena recién
 * cuando alguna pantalla llama a revisar(): darle play a algo que ya se bajó no
 * puede depender de que la pantalla de turno se haya acordado de refrescar el
 * listado. Es la pregunta que decide si hace falta la red o no.
 *
 * Lo que no pase el control se borra en el momento. Dejarlo sería peor que no
 * tenerlo: el tilde verde seguiría prometiendo un audio que no existe.
 */
const estaGuardada = async (id: number): Promise<boolean> => {
    if (!('caches' in window)) {
        return false;
    }

    try {
        const cache = await caches.open(CACHE_AUDIO);

        if (await esAudio(await cache.match(urlAudio(id)))) {
            return true;
        }

        await cache.delete(urlAudio(id));

        return false;
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
    const ids = new Set<number>();

    /*
     * De paso barre lo que haya quedado mal guardado por el bug del 202. Es el
     * único momento en que se puede: nadie más recorre la caché entera, y
     * mientras la entrada rota siga ahí el tilde verde miente.
     */
    for (const req of await cache.keys()) {
        const id = idDeUrl(req.url);

        if (id === null) {
            continue;
        }

        if (await esAudio(await cache.match(req))) {
            ids.add(id);
        } else {
            await cache.delete(req);
        }
    }

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

    const ids: number[] = [];

    for (const req of claves) {
        const id = idDeUrl(req.url);

        if (id === null) {
            continue;
        }

        // Lo mismo que hace revisar(): una entrada que no es audio no se lista,
        // se borra. Ver esAudio().
        if (await esAudio(await cache.match(req))) {
            ids.push(id);
        } else {
            await cache.delete(req);
        }
    }

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

/*
 * Cuánto se insiste con un archivo que se está trayendo de la nube: 45 vueltas
 * de 4 segundos, tres minutos.
 *
 * Traer el archivo corre DENTRO de la petición —no hay cola de trabajos, haría
 * falta un cron— y una clase de 60 MB tarda entre 45 s y 2 min. El proxy corta
 * bastante antes, y ahí está la trampa: la petición muere pero el archivo del
 * otro lado sigue viniendo. Darlo por perdido en ese momento es lo que hacía
 * fallar las descargas grandes, que en esta biblioteca son casi todas: 922 de
 * las 928 pistas están sólo en la nube.
 */
const ESPERA_MS = 4000;
const INTENTOS = 45;

/**
 * Pide traer el archivo al server y espera hasta que esté, sin creerle a la
 * primera respuesta que se corte.
 */
const traerDeLaNube = async (id: number): Promise<Response> => {
    let estado: string | null = null;

    progreso[id] = 'Trayendo de la nube…';

    try {
        const res = await fetch(`/pistas/${id}/restaurar`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });

        estado = ((await res.json()) as { estado?: string }).estado ?? null;
    } catch {
        // Se cortó la petición, pero el archivo puede seguir bajando del otro
        // lado: eso lo resuelve el bucle de abajo.
    }

    /*
     * Un "no" explícito del servidor es definitivo. Insistir tres minutos con
     * algo que ya contestó que no se puede es sólo hacer esperar al pedo.
     */
    if (estado === 'error' || estado === 'no_disponible') {
        return new Response(null, { status: 502 });
    }

    const desde = Date.now();

    for (let i = 0; i < INTENTOS; i++) {
        const res = await fetch(urlAudio(id));

        if (res.status !== 202) {
            return res;
        }

        await res.body?.cancel();

        const segundos = Math.round((Date.now() - desde) / 1000);
        progreso[id] = `Trayendo de la nube… ${segundos}s`;

        await new Promise((listo) => setTimeout(listo, ESPERA_MS));
    }

    return new Response(null, { status: 202 });
};

/**
 * Baja el audio y lo guarda en el dispositivo, mostrando el porcentaje.
 *
 * Contempla el 202: si el archivo está sólo en la nube hay que traerlo al
 * servidor primero, o se guardaría el JSON del aviso en vez del audio.
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
            res = await traerDeLaNube(id);
        }

        /*
         * `res.ok` no alcanza, y ése era el bug: para fetch cualquier 2xx es
         * "ok", y el 202 de "todavía está en la nube" entra ahí. Con `ok` se
         * guardaba el JSON del aviso como si fuera el audio, aparecía el tilde
         * verde y el play fallaba.
         */
        if (res.status !== 200 || !res.body) {
            progreso[id] =
                res.status === 202
                    ? 'La nube tardó demasiado. Probá de nuevo.'
                    : 'No se pudo descargar.';

            return;
        }

        // El tipo real, no uno inventado: 27 de las 928 son m4a, y declararlas
        // mp3 hace que algunos navegadores se nieguen a reproducirlas.
        const tipo = res.headers.get('Content-Type') || 'audio/mpeg';
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

        const blob = new Blob(partes, { type: tipo });

        /*
         * Último control antes de escribir. Un 200 tampoco garantiza audio: si
         * venció la sesión, lo que llega es el HTML del login.
         */
        if (!(await esAudio(new Response(blob)))) {
            progreso[id] = 'Lo que llegó no era el audio. Probá de nuevo.';

            return;
        }

        const cache = await caches.open(CACHE_AUDIO);

        // Los headers explícitos no son decorativos: son los que después usa el
        // reproductor para poder adelantar sobre la copia guardada.
        await cache.put(
            urlAudio(id),
            new Response(blob, {
                status: 200,
                headers: {
                    'Content-Type': tipo,
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
