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
const CACHE_APP = 'dharmify-app-v1';

/*
 * La caché del audio NO lleva número de versión, a propósito, y el `activate` de
 * más abajo filtra por prefijo en vez de "todo lo que no sea la actual".
 *
 * Si se versionara, cada deploy le borraría al usuario todo lo que se bajó para
 * escuchar sin conexión — que puede ser un gigabyte que tardó una hora en juntar
 * por wifi. La llena la página (no el service worker) y se usa en la fase de
 * offline; se declara desde ahora para que el filtro del activate nazca bien.
 */
const CACHE_AUDIO = 'dharmify-audio-v1'; // eslint-disable-line no-unused-vars

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
