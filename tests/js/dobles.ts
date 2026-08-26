/**
 * Los dobles de las APIs del navegador que estas pruebas necesitan.
 *
 * Cache Storage no existe en Node, y el <audio> de jsdom no reproduce nada: su
 * play() ni siquiera devuelve una promesa. Se imitan acá, lo más chico posible
 * — sólo lo que el código realmente usa.
 */

type Entrada = {
    cuerpo: Uint8Array;
    status: number;
    cabeceras: Record<string, string>;
};

/**
 * Una caché de verdad devuelve una Response NUEVA en cada match().
 *
 * No es un detalle de pureza: el cuerpo de una Response se lee una sola vez, y
 * el código lo lee más de una —mira el primer byte para saber si es audio y
 * después arma el blob—. Un doble que devolviera siempre el mismo objeto
 * fallaría por un motivo que no tiene nada que ver con lo que se prueba.
 */
export class CacheFalsa {
    private entradas = new Map<string, Entrada>();

    /*
     * La app pide con rutas relativas ("/pistas/7/audio") porque en el
     * navegador se resuelven contra la página. Node no tiene página, así que
     * acá se les pone una base: sin esto, `new Request('/pistas/7/audio')`
     * revienta con "Invalid URL".
     */
    private static url(pedido: RequestInfo | URL): string {
        const crudo =
            typeof pedido === 'string'
                ? pedido
                : pedido instanceof URL
                  ? pedido.href
                  : pedido.url;

        return new URL(crudo, 'http://localhost').href;
    }

    async put(pedido: RequestInfo | URL, respuesta: Response): Promise<void> {
        this.entradas.set(CacheFalsa.url(pedido), {
            cuerpo: new Uint8Array(await respuesta.arrayBuffer()),
            status: respuesta.status,
            cabeceras: Object.fromEntries(respuesta.headers.entries()),
        });
    }

    async match(pedido: RequestInfo | URL): Promise<Response | undefined> {
        const entrada = this.entradas.get(CacheFalsa.url(pedido));

        if (!entrada) {
            return undefined;
        }

        // slice() copia: si se le pasara el mismo buffer a dos Response, la
        // segunda podría encontrárselo ya consumido.
        return new Response(entrada.cuerpo.slice(), {
            status: entrada.status,
            headers: entrada.cabeceras,
        });
    }

    async delete(pedido: RequestInfo | URL): Promise<boolean> {
        return this.entradas.delete(CacheFalsa.url(pedido));
    }

    async keys(): Promise<Request[]> {
        return [...this.entradas.keys()].map((url) => new Request(url));
    }
}

export class CacheStorageFalso {
    private caches = new Map<string, CacheFalsa>();

    async open(nombre: string): Promise<CacheFalsa> {
        const existente = this.caches.get(nombre);

        if (existente) {
            return existente;
        }

        const nueva = new CacheFalsa();
        this.caches.set(nombre, nueva);

        return nueva;
    }
}

/** El último <audio> que se construyó, para poder mirarle el src. */
export let ultimoAudio: AudioFalso | null = null;

// Vía una función y no `ultimoAudio = this` en el constructor: eslint prohíbe
// guardarse `this` en una variable, y pasarlo como argumento hace lo mismo.
const anotarElUltimo = (audio: AudioFalso) => {
    ultimoAudio = audio;
};

export class AudioFalso {
    src = '';
    currentTime = 0;
    duration = 0;
    playbackRate = 1;
    paused = true;

    private oyentes = new Map<string, (() => void)[]>();

    constructor() {
        anotarElUltimo(this);
    }

    addEventListener(tipo: string, fn: () => void): void {
        this.oyentes.set(tipo, [...(this.oyentes.get(tipo) ?? []), fn]);
    }

    /** Para que una prueba pueda disparar 'error' o 'ended' a mano. */
    emitir(tipo: string): void {
        (this.oyentes.get(tipo) ?? []).forEach((fn) => fn());
    }

    async play(): Promise<void> {
        this.paused = false;
    }

    pause(): void {
        this.paused = true;
    }
}

/** Los primeros bytes de un mp3 con etiqueta ID3: "ID3" y su versión. */
export const bytesDeMp3 = () =>
    new Uint8Array([0x49, 0x44, 0x33, 0x03, 0x00, 0x00, 0x00, 0x00]);

export const respuestaDeAudio = (tipo = 'audio/mpeg') => {
    const cuerpo = bytesDeMp3();

    return new Response(cuerpo, {
        status: 200,
        headers: {
            'Content-Type': tipo,
            'Content-Length': String(cuerpo.length),
            'Accept-Ranges': 'bytes',
        },
    });
};

/**
 * La respuesta que empezó todo: 202 con un JSON, que para fetch es "ok".
 */
export const respuestaEnLaNube = () =>
    Response.json(
        { estado: 'en_nube', mensaje: 'Trayendo de la nube…' },
        { status: 202 },
    );
