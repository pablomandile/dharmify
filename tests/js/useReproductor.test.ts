import { describe, expect, it, vi } from 'vitest';
import type { PistaReproducible } from '@/composables/useReproductor';
import { respuestaDeAudio, respuestaEnLaNube, ultimoAudio } from './dobles';

/**
 * A qué copia le da play el reproductor.
 *
 * El orden correcto es dispositivo → server → nube, y lo que se prueba acá es
 * el primer eslabón: durante un tiempo la decisión salía de `en_server` —el
 * estado del SERVER— así que darle play a algo ya bajado salía a pedirle
 * permiso a la red para reproducir un archivo que estaba en el teléfono. Sin
 * señal, se quedaba colgado en "Trayendo de la nube…" para siempre.
 */
const CACHE = 'dharmify-audio-v1';

const pista = (extra: Partial<PistaReproducible> = {}): PistaReproducible => ({
    id: 1,
    titulo: 'Primera charla',
    serie: 'Retiro de Vacuidad',
    serieId: 3,
    en_server: false,
    en_nube: true,
    ...extra,
});

const cargar = async () => {
    /*
     * Los dos módulos se importan DESPUÉS del reset y en la misma vuelta: así
     * el reproductor y la prueba miran el mismo useOffline. Importarlo antes
     * dejaría a cada uno con su copia del estado.
     */
    vi.resetModules();

    const { useReproductor } = await import('@/composables/useReproductor');
    const { useOffline } = await import('@/composables/useOffline');

    return { ...useReproductor(), ...useOffline() };
};

const guardarEnLaCache = async (id: number, respuesta: Response) => {
    await (await caches.open(CACHE)).put(`/pistas/${id}/audio`, respuesta);
};

describe('reproducir', () => {
    it('usa la copia del dispositivo sin tocar la red', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());

        const { reproducir, actual, avisos } = await cargar();
        await reproducir(pista());

        expect(fetch).not.toHaveBeenCalled();
        expect(actual.value?.id).toBe(1);
        expect(ultimoAudio?.src).toBe('/pistas/1/audio');
        expect(avisos[1]).toBeUndefined();
    });

    /**
     * Que salga dentro del gesto del usuario no es un lujo: Safari bloquea el
     * play() que llega después de un await. Por eso el camino normal mira el
     * listado ya cargado y deja la consulta asíncrona de repuesto.
     *
     * Se comprueba sin esperar la promesa: si al volver de la llamada la pista
     * ya está cargada, es que no hubo await en el medio.
     */
    it('arranca en el mismo tick cuando el listado ya está cargado', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());

        const { reproducir, revisar, actual } = await cargar();
        await revisar();

        const trabajo = reproducir(pista());

        expect(actual.value?.id).toBe(1);

        await trabajo;
    });

    it('va a buscarla a la nube cuando no hay copia en el dispositivo', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Response.json({ estado: 'listo' })),
        );

        const { reproducir, actual } = await cargar();
        await reproducir(pista());

        expect(fetch).toHaveBeenCalledWith(
            '/pistas/1/restaurar',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(actual.value?.id).toBe(1);
    });

    /**
     * Los dos arreglos juntos. Una entrada guardada que no es audio —el JSON
     * del 202— no puede contar como copia local: si contara, el reproductor le
     * daría play a un archivo de 55 bytes y fallaría en silencio, que es
     * exactamente lo que pasaba.
     */
    it('no toma por copia local una entrada que no es audio', async () => {
        await guardarEnLaCache(1, respuestaEnLaNube());

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Response.json({ estado: 'listo' })),
        );

        const { reproducir } = await cargar();
        await reproducir(pista());

        expect(fetch).toHaveBeenCalledWith(
            '/pistas/1/restaurar',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('no promete nada de lo que no está ni acá ni en la nube', async () => {
        const { reproducir, avisos } = await cargar();
        await reproducir(pista({ en_nube: false }));

        expect(fetch).not.toHaveBeenCalled();
        expect(avisos[1]).toBe('Este audio no está disponible.');
    });

    /**
     * Un <audio> que no puede con su fuente se queda quieto y no avisa nada.
     * Ese silencio fue buena parte de por qué el bug se veía como "se corta".
     */
    it('avisa cuando el audio no se puede reproducir', async () => {
        await guardarEnLaCache(1, respuestaDeAudio());

        const { reproducir, avisos } = await cargar();
        await reproducir(pista());

        ultimoAudio?.emitir('error');

        expect(avisos[1]).toBe('No se pudo reproducir este audio.');
    });
});
