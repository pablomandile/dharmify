/*
 * Service worker de Dharmify.
 *
 * Regla: cache-first SOLO para URLs con hash de contenido en el nombre
 * (/build/app-A1b2C3.js). Cualquier URL fija —íconos, manifest, carátulas— va
 * por network-first, o queda congelada para siempre.
 *
 * Al cambiar un ícono hay que tocar TRES lugares a la vez:
 *   1. el número de CACHE_APP de acá abajo
 *   2. el ?v= de los <link rel="icon"> del blade
 *   3. el ?v= de los "src" del manifest
 */
const CACHE_APP = 'dharmify-app-v5';

/*
 * La caché del audio NO lleva número de versión, a propósito, y el `activate` de
 * más abajo filtra por prefijo en vez de "todo lo que no sea la actual".
 *
 * Si se versionara, cada deploy le borraría al usuario todo lo que se bajó para
 * escuchar sin conexión — que puede ser un gigabyte que tardó una hora en juntar
 * por wifi. La llena la página (no el service worker) y se usa en la fase de
 * offline; se declara desde ahora para que el filtro del activate nazca bien.
 */
const CACHE_AUDIO = 'dharmify-audio-v1';

/**
 * Sirve un audio guardado en el dispositivo, armando el 206 a mano.
 *
 * Acá está la trampa que más tiempo come. Guardar el archivo en Cache Storage
 * es fácil; el problema es que una entrada de caché devuelve SIEMPRE el archivo
 * entero con 200, y el <audio> pide rangos de bytes para arrancar y para cada
 * salto de la barra. Resultado: suena, pero no se puede adelantar.
 */
async function servirAudio(request) {
    const cache = await caches.open(CACHE_AUDIO);
    const guardado = await cache.match(request.url, { ignoreSearch: true });

    // No está guardado en este dispositivo: que vaya al servidor como siempre.
    if (!guardado) {
        return fetch(request);
    }

    const rango = request.headers.get('range');
    const blob = await guardado.blob();

    /*
     * El tipo con el que se guardó, no uno fijo: 27 de las 928 son m4a. Servir
     * AAC diciendo "audio/mpeg" hace que algunos navegadores se nieguen a
     * reproducirlo, y encima el server manda `nosniff`, que les prohíbe
     * corregirnos el error mirando el archivo.
     */
    const tipo = guardado.headers.get('Content-Type') || 'audio/mpeg';

    if (!rango) {
        return new Response(blob, {
            status: 200,
            headers: {
                'Content-Type': tipo,
                'Content-Length': String(blob.size),
                'Accept-Ranges': 'bytes',
            },
        });
    }

    const m = /bytes=(\d*)-(\d*)/.exec(rango);
    let inicio = m && m[1] !== '' ? parseInt(m[1], 10) : 0;
    let fin = m && m[2] !== '' ? parseInt(m[2], 10) : blob.size - 1;

    if (m && m[1] === '' && m[2] !== '') {
        // "bytes=-500": los últimos N bytes.
        inicio = Math.max(0, blob.size - parseInt(m[2], 10));
        fin = blob.size - 1;
    }

    if (inicio >= blob.size || inicio > fin) {
        return new Response(null, {
            status: 416,
            headers: { 'Content-Range': 'bytes */' + blob.size },
        });
    }

    // slice() no carga los 25 MB en memoria: devuelve una vista del blob.
    const trozo = blob.slice(inicio, fin + 1);

    return new Response(trozo, {
        status: 206,
        headers: {
            'Content-Type': tipo,
            'Content-Length': String(trozo.size),
            'Content-Range': 'bytes ' + inicio + '-' + fin + '/' + blob.size,
            'Accept-Ranges': 'bytes',
        },
    });
}

const OFFLINE_URL = '/sin-conexion';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_APP)
            .then((cache) => cache.addAll([OFFLINE_URL, '/manifest.webmanifest']))
            .catch(() => {}), // que un 404 no impida instalar el service worker
    );

    // Por ahora se activa solo. Cuando exista la pantalla de descargas conviene
    // pasar a dejarlo en espera y avisarle al usuario, para no cambiar el service
    // worker en medio de una reproducción.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((claves) =>
                Promise.all(
                    claves
                        .filter((clave) => clave.startsWith('dharmify-app-') && clave !== CACHE_APP)
                        .map((clave) => caches.delete(clave)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

const tieneHashDeContenido = (url) => url.pathname.startsWith('/build/');

const esNavegacion = (request) =>
    request.mode === 'navigate' ||
    (request.method === 'GET' &&
        (request.headers.get('accept') || '').includes('text/html'));

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    /*
     * El estado del server NUNCA se cachea. Una copia vieja diciendo
     * "descargando 40%" es peor que un error de red: la UI sabe manejar el error,
     * no sabe manejar una mentira.
     */
    if (url.pathname.startsWith('/api/')) return;

    // El audio guardado se sirve desde el dispositivo, con su 206 sintetizado.
    if (/^\/pistas\/\d+\/audio$/.test(url.pathname)) {
        event.respondWith(servirAudio(request));

        return;
    }

    // La descarga del mp3 va siempre al servidor, nunca a la caché.
    if (/^\/pistas\/\d+\/bajar$/.test(url.pathname)) return;

    /*
     * La transcripción de lo bajado, para poder leerla en el colectivo.
     *
     * Primero la caché: es texto y no cambia salvo que alguien suba otra
     * versión, y no vale la pena esperar a la red para mostrar algo que ya está.
     * Fijate que NO va bajo /api/, que unas líneas más arriba tiene prohibido
     * cachearse: por eso esta URL termina en .json y no cuelga de ahí.
     */
    if (/^\/pistas\/\d+\/transcripcion\.json$/.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cacheada) => cacheada || fetch(request)),
        );

        return;
    }

    /*
     * Las carátulas de lo bajado se guardan junto al audio, así la pantalla de
     * Descargas se ve igual sin conexión.
     *
     * Primero la caché sin dudar: la URL lleva ?v= con la fecha de la carátula,
     * o sea que si la imagen cambia, cambia la URL. Una copia guardada no puede
     * quedar vieja.
     */
    if (/^\/series\/\d+\/portada$/.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cacheada) => cacheada || fetch(request)),
        );

        return;
    }

    // Los assets de Vite llevan el hash en el nombre: si la URL existe, el
    // contenido no cambió nunca. Servirlos de caché es seguro y es lo único que
    // hace que la app abra rápido.
    if (tieneHashDeContenido(url)) {
        event.respondWith(
            caches.match(request).then(
                (cacheada) =>
                    cacheada ||
                    fetch(request).then((respuesta) => {
                        if (respuesta.ok) {
                            const copia = respuesta.clone();
                            caches
                                .open(CACHE_APP)
                                .then((cache) => cache.put(request, copia))
                                .catch(() => {});
                        }
                        return respuesta;
                    }),
            ),
        );
        return;
    }

    // Navegaciones: cartel propio en vez del dinosaurio si no hay conexión, y
    // rescate si la caché del navegador contestó con la variante JSON.
    if (esNavegacion(request)) {
        event.respondWith(
            fetch(request)
                .then((respuesta) => {
                    /*
                     * Una navegación no puede recibir la variante JSON de una
                     * página: la misma URL devuelve HTML o JSON según el header
                     * `X-Inertia`, y el CDN de Hostinger borra el `Vary` que las
                     * distingue. El servidor ya manda `no-store` para que no
                     * vuelva a pasar, pero las entradas ya guardadas siguen ahí, y
                     * con el JSON en pantalla la app no arranca: ningún script de
                     * la página puede repararlo, solo esto.
                     *
                     * Las dos condiciones hacen falta:
                     *
                     * `request.mode === 'navigate'` porque esNavegacion() también
                     * da true para los XHR de Inertia —el router manda
                     * `Accept: text/html`—, y "arreglarles" la respuesta les
                     * devolvería el HTML de arranque en vez del JSON de la página:
                     * la app dejaría de navegar por completo.
                     *
                     * Y el header `X-Inertia` de la RESPUESTA en vez del
                     * content-type, porque puede haber navegaciones que contesten
                     * JSON de verdad (una exportación) y se pedirían dos veces.
                     */
                    if (request.mode !== 'navigate' || !respuesta.headers.get('x-inertia')) {
                        return respuesta;
                    }

                    return fetch(request.url, {
                        cache: 'reload',
                        headers: { Accept: 'text/html' },
                    }).then((recuperada) =>
                        /*
                         * Si la sesión venció, esa URL redirige al login. Una
                         * respuesta ya redirigida no se le puede entregar a una
                         * navegación —el Service Worker API lo prohíbe—, así que se
                         * le pasa el redirect y lo sigue el navegador.
                         */
                        recuperada.redirected
                            ? Response.redirect(recuperada.url, 302)
                            : recuperada,
                    );
                })
                .catch(() =>
                    caches.match(OFFLINE_URL).then(
                        (cacheada) =>
                            cacheada ||
                            new Response('Sin conexión', {
                                status: 503,
                                headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                            }),
                    ),
                ),
        );
        return;
    }

    // Todo lo demás (íconos, manifest, XHR de Inertia): a la red, sin guardar.
    event.respondWith(fetch(request));
});
