import { beforeEach, describe, expect, it, vi } from 'vitest';
import { bytesDeMp3, respuestaDeAudio, respuestaEnLaNube } from './dobles';

/**
 * Guardar audio en el dispositivo.
 *
 * Todo esto existe por un bug que estuvo en producción: `Response.ok` de fetch
 * da true para cualquier 2xx, y el servidor contesta 202 con un JSON cuando el
 * archivo todavía está sólo en la nube. La descarga tomaba ese JSON por el
 * audio, aparecía el tilde de "bajado" y el play fallaba.
 */
const CACHE = 'dharmify-audio-v1';

const cargar = async () => {
    // Módulo nuevo en cada prueba: `guardadas` vive en el módulo, no en un
    // componente, así que sin esto una prueba arranca con lo de la anterior.
    vi.resetModules();

    const { useOffline } = await import('@/composables/useOffline');

    return useOffline();
};

const enLaCache = async (id: number) =>
    (await caches.open(CACHE)).match(`/pistas/${id}/audio`);

const guardarEnLaCache = async (id: number, respuesta: Response) => {
    await (await caches.open(CACHE)).put(`/pistas/${id}/audio`, respuesta);
};

describe('guardar', () => {
    it('guarda el audio cuando lo que llega es audio', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => respuestaDeAudio()),
        );

        const { guardar, guardadas } = await cargar();
        await guardar(7);

        expect(await enLaCache(7)).toBeDefined();
        expect(guardadas.value.has(7)).toBe(true);
    });

    /** El bug, exactamente. */
    it('no guarda el aviso de la nube como si fuera el audio', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: RequestInfo | URL) =>
                String(url).includes('restaurar')
                    ? Response.json({ estado: 'error' }, { status: 500 })
                    : respuestaEnLaNube(),
            ),
        );

        const { guardar, guardadas, progreso } = await cargar();
        await guardar(7);

        expect(await enLaCache(7)).toBeUndefined();
        expect(guardadas.value.has(7)).toBe(false);
        expect(progreso[7]).toBe('No se pudo descargar.');
    });

    /**
     * `ok` es true para CUALQUIER 2xx, y ahí entra el 206.
     *
     * Un proxy que conteste con un pedazo del archivo pasa todos los controles
     * —empieza con la firma de un mp3, así que parece audio— y deja guardado un
     * archivo truncado que suena unos segundos y se corta. Por eso la condición
     * es `status !== 200` y no `!ok`: de una descarga sólo sirve el 200.
     */
    it('no guarda una respuesta parcial como si fuera el archivo entero', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(bytesDeMp3(), {
                        status: 206,
                        headers: {
                            'Content-Type': 'audio/mpeg',
                            'Content-Range': 'bytes 0-7/900000',
                        },
                    }),
            ),
        );

        const { guardar, guardadas, progreso } = await cargar();
        await guardar(7);

        expect(await enLaCache(7)).toBeUndefined();
        expect(guardadas.value.has(7)).toBe(false);
        expect(progreso[7]).toBe('No se pudo descargar.');
    });

    /**
     * Un 200 tampoco garantiza audio: si venció la sesión, lo que llega es el
     * HTML del login, y con status 200.
     */
    it('no guarda la página de login', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response('<!doctype html><html>Ingresá</html>', {
                        status: 200,
                        headers: { 'Content-Type': 'text/html' },
                    }),
            ),
        );

        const { guardar, progreso } = await cargar();
        await guardar(7);

        expect(await enLaCache(7)).toBeUndefined();
        expect(progreso[7]).toBe(
            'Lo que llegó no era el audio. Probá de nuevo.',
        );
    });

    /**
     * 27 de las 928 pistas son m4a. Si se guardan diciendo "audio/mpeg", el
     * service worker las sirve así y algunos navegadores se niegan a
     * reproducirlas.
     */
    it('guarda un m4a con su tipo y no con el de mp3', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => respuestaDeAudio('audio/mp4')),
        );

        const { guardar } = await cargar();
        await guardar(7);

        expect((await enLaCache(7))?.headers.get('Content-Type')).toBe(
            'audio/mp4',
        );
    });

    /**
     * Traer el archivo corre dentro de la petición y una clase de 60 MB tarda
     * más de lo que aguanta el proxy. Cuando lo corta, el archivo del otro lado
     * sigue viniendo: darlo por perdido ahí es lo que rompía las descargas
     * grandes, que acá son casi todas.
     */
    it('insiste mientras el archivo se está trayendo de la nube', async () => {
        vi.useFakeTimers();

        let pedidos = 0;

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: RequestInfo | URL) => {
                if (String(url).includes('restaurar')) {
                    return Response.json({ estado: 'listo' });
                }

                pedidos++;

                return pedidos <= 2 ? respuestaEnLaNube() : respuestaDeAudio();
            }),
        );

        const { guardar, guardadas } = await cargar();
        const trabajo = guardar(7);

        await vi.advanceTimersByTimeAsync(5000);
        await trabajo;

        expect(guardadas.value.has(7)).toBe(true);

        vi.useRealTimers();
    });

    it('se rinde con un mensaje honesto si la nube nunca llega', async () => {
        vi.useFakeTimers();

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: RequestInfo | URL) =>
                String(url).includes('restaurar')
                    ? Response.json({ estado: 'listo' })
                    : respuestaEnLaNube(),
            ),
        );

        const { guardar, progreso } = await cargar();
        const trabajo = guardar(7);

        await vi.advanceTimersByTimeAsync(200000);
        await trabajo;

        expect(await enLaCache(7)).toBeUndefined();
        expect(progreso[7]).toBe('La nube tardó demasiado. Probá de nuevo.');

        vi.useRealTimers();
    });
});

describe('la limpieza de lo que quedó mal guardado', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 500 })),
        );
    });

    it('revisar borra las entradas que no son audio y deja las buenas', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());
        await guardarEnLaCache(2, respuestaEnLaNube());

        const { revisar, guardadas } = await cargar();
        await revisar();

        expect(guardadas.value.has(1)).toBe(true);
        expect(guardadas.value.has(2)).toBe(false);

        // No alcanza con no listarla: mientras siga ahí, el tilde verde miente.
        expect(await enLaCache(2)).toBeUndefined();
    });

    it('estaGuardada no da por buena una entrada rota', async () => {
        await guardarEnLaCache(2, respuestaEnLaNube());

        const { estaGuardada } = await cargar();

        expect(await estaGuardada(2)).toBe(false);
        expect(await enLaCache(2)).toBeUndefined();
    });

    it('estaGuardada reconoce el audio de verdad', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());

        const { estaGuardada } = await cargar();

        expect(await estaGuardada(1)).toBe(true);
    });

    /**
     * El descarte mira el primer byte y NO el tamaño: la pista más corta de la
     * biblioteca pesa 17 KB y hay seis abajo del mega, así que cualquier umbral
     * por peso daría por rotos archivos buenos.
     */
    it('no descarta un audio corto por ser corto', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());

        const { estaGuardada } = await cargar();
        const guardado = await enLaCache(1);

        expect((await guardado?.arrayBuffer())?.byteLength).toBeLessThan(100);
        expect(await estaGuardada(1)).toBe(true);
    });
});
