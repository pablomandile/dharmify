import { describe, expect, it, vi } from 'vitest';

/**
 * El panel de lectura.
 *
 * Lo que se cuida acá es la diferencia entre "todavía no hay transcripción" y
 * "algo se rompió". Son dos pantallas distintas —una ofrece subir una, la otra
 * pide disculpas— y confundirlas le diría a la persona que la app falló cuando
 * en realidad no hay nada que mostrar.
 */
const cargar = async () => {
    // Módulo nuevo en cada prueba: el estado del panel vive en el módulo, así
    // que sin esto una prueba arranca con la transcripción de la anterior.
    vi.resetModules();

    const { useTranscripcion } = await import('@/composables/useTranscripcion');

    return useTranscripcion();
};

const fichaFalsa = (pista: number) => ({
    pista,
    titulo: 'Primera charla',
    serie: 'Retiro de Vacuidad',
    serieId: 3,
    formato: 'docx',
    palabras: 4200,
    texto: 'Buenas tardes a todos.',
    marcas: null,
    seEmbebe: false,
    urlVer: null,
    urlBajar: `/pistas/${pista}/transcripcion/bajar`,
    urlCompleta: `/pistas/${pista}/transcripcion`,
    archivo: 'clase.docx',
});

describe('abrir', () => {
    it('trae el texto y lo deja listo para mostrar', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Response.json(fichaFalsa(7))),
        );

        const { abrir, ficha, abierto, error } = await cargar();
        await abrir(7, 'Primera charla');

        expect(abierto.value).toBe(true);
        expect(ficha.value?.texto).toBe('Buenas tardes a todos.');
        expect(error.value).toBeNull();
    });

    /** El caso que motiva todo: un 404 acá no es un fallo. */
    it('un 404 significa que todavía no hay, no que se rompió', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 404 })),
        );

        const { abrir, ficha, error, sinTranscripcion } = await cargar();
        await abrir(7, 'Primera charla');

        expect(sinTranscripcion.value).toBe(true);
        expect(ficha.value).toBeNull();
        expect(error.value).toBeNull();
    });

    /**
     * Sin conexión sólo están las de lo que se bajó al dispositivo, porque la
     * transcripción viaja con el audio. Decir sólo "falló" no le explicaría a
     * nadie por qué unas abren y otras no.
     */
    it('sin conexión explica por qué no está', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new Error('sin red');
            }),
        );
        vi.stubGlobal('navigator', { ...navigator, onLine: false });

        const { abrir, error } = await cargar();
        await abrir(7, 'Primera charla');

        expect(error.value).toContain('bajaste al dispositivo');
    });

    it('con conexión, un fallo es un fallo', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 500 })),
        );
        vi.stubGlobal('navigator', { ...navigator, onLine: true });

        const { abrir, error } = await cargar();
        await abrir(7, 'Primera charla');

        expect(error.value).toBe('No se pudo abrir la transcripción.');
    });

    /**
     * Abrir y cerrar la misma mientras se escucha pasa todo el tiempo: volver a
     * pedirla cada vez sería tráfico al pedo y un parpadeo del panel.
     */
    it('no vuelve a pedir la que ya tiene abierta', async () => {
        const pedidos = vi.fn(async () => Response.json(fichaFalsa(7)));
        vi.stubGlobal('fetch', pedidos);

        const { abrir, cerrar } = await cargar();

        await abrir(7, 'Primera charla');
        cerrar();
        await abrir(7, 'Primera charla');

        expect(pedidos).toHaveBeenCalledTimes(1);
    });

    it('pero sí la vuelve a pedir al cambiar de pista', async () => {
        const pedidos = vi.fn(async (url: RequestInfo | URL) =>
            Response.json(fichaFalsa(String(url).includes('/7/') ? 7 : 8)),
        );
        vi.stubGlobal('fetch', pedidos);

        const { abrir, ficha } = await cargar();

        await abrir(7, 'Primera');
        await abrir(8, 'Segunda');

        expect(pedidos).toHaveBeenCalledTimes(2);
        expect(ficha.value?.pista).toBe(8);
    });

    /** Después de subir una nueva hay que volver a pedirla aunque sea la misma. */
    it('recargar ignora lo que ya tenía cargado', async () => {
        const pedidos = vi.fn(async () => Response.json(fichaFalsa(7)));
        vi.stubGlobal('fetch', pedidos);

        const { abrir, recargar } = await cargar();

        await abrir(7, 'Primera charla');
        await recargar();

        expect(pedidos).toHaveBeenCalledTimes(2);
    });
});
