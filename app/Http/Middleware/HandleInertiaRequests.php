<?php

namespace App\Http\Middleware;

use App\Models\Fuente;
use App\Models\Serie;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Una URL de Inertia devuelve dos cuerpos distintos según el header
     * `X-Inertia`: el HTML de arranque para una navegación, el JSON de la página
     * para un XHR. Lo único que se lo dice a una caché es el `Vary`, y el CDN de
     * Hostinger lo **borra** cuando comprime con brotli — que es lo que pide
     * cualquier navegador real. Sin esto, el navegador guarda el JSON bajo la URL
     * pelada y, al restaurar una pestaña descartada, muestra el JSON crudo en
     * pantalla en vez de la app.
     *
     * Va acá y no en un middleware aparte: el de Inertia setea el `Vary` y puede
     * reemplazar la respuesta entera en `onVersionChange()`. Cualquier middleware
     * agregado después del suyo corre su parte de salida antes, y quedaría pisado.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        // El CDN lo borra igual, pero se declara: es lo correcto y sirve en
        // cualquier intermediario que sí lo respete.
        $response->headers->set('Vary', Header::INERTIA.', Accept-Encoding');

        /*
         * `no-store` y no `no-cache`: `no-cache` permite guardar y sólo obliga a
         * revalidar, y una navegación de historial —que es exactamente este bug—
         * saltea la revalidación.
         *
         * Y sólo sobre la respuesta XHR, **nunca** sobre el HTML: `no-store` en el
         * documento principal desactiva el back/forward cache de Chrome y convierte
         * cada "atrás" en una ida completa a la red, sin ningún síntoma que lo
         * delate. Por eso hay un test que lo fija.
         */
        if ($request->header(Header::INERTIA)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Para que la UI no ofrezca lo que el servidor va a rechazar.
                // No reemplaza al middleware: sólo evita mostrar puertas cerradas.
                'esAdmin' => (bool) $request->user()?->esAdmin(),
            ],

            // Mensajes de una sola vez que las pantallas muestran en línea.
            'estado' => $request->session()->get('estado'),
            'error' => $request->session()->get('error'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            /*
             * Las carpetas para el desplegable del menú.
             *
             * Van compartidas y no en un endpoint aparte por dos razones: el
             * menú está en todas las pantallas, y así el desplegable también
             * funciona sin conexión, porque viaja dentro del HTML que el service
             * worker ya guarda. Son 145 títulos: unos pocos KB.
             */
            'carpetas' => $this->carpetas($request),
        ];
    }

    /**
     * @return array<int, array{id: int, titulo: string, anio: int|null}>
     */
    private function carpetas(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return Serie::query()
            ->whereIn('fuente_id', Fuente::visiblesPara($request->user()))
            ->has('pistas')
            /*
             * Las series sin año al final: un `ORDER BY anio DESC` pone los NULL
             * adelante en MySQL y el Programa General —que no tiene año porque
             * corre todo el tiempo— encabezaría la lista.
             */
            ->orderByRaw('anio IS NULL, anio DESC')
            ->orderBy('titulo')
            ->get(['id', 'titulo', 'anio'])
            ->map(fn (Serie $s) => ['id' => $s->id, 'titulo' => $s->titulo, 'anio' => $s->anio])
            ->all();
    }
}
