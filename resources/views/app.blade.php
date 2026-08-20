<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        {{-- Lo usan los fetch() del reproductor, que no pasan por Inertia y por
             lo tanto no llevan el header que este pone solo. --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{--
            Chrome dispara `beforeinstallprompt` apenas carga la página, casi
            siempre ANTES de que monte Vue. Si el listener se registra en un
            componente (onMounted), el evento ya pasó y el botón de instalar no
            aparece nunca — de forma intermitente, que es lo peor. Por eso va
            acá, inline y antes de todo.
        --}}
        <script>
            (function () {
                window.__pwaInstall = { prompt: null, installed: false };

                window.addEventListener('beforeinstallprompt', function (e) {
                    e.preventDefault(); // el banner lo lanzamos desde nuestro botón
                    window.__pwaInstall.prompt = e;
                    window.dispatchEvent(new CustomEvent('pwa:installable'));
                });

                window.addEventListener('appinstalled', function () {
                    window.__pwaInstall.prompt = null;
                    window.__pwaInstall.installed = true;
                    window.dispatchEvent(new CustomEvent('pwa:installed'));
                });
            })();
        </script>

        {{-- Pinta el fondo antes de que llegue el CSS. Son los mismos valores que
             --background en resources/css/app.css: si se cambian allá, acá también. --}}
        <style>
            html {
                background-color: hsl(210 40% 98%);
            }

            html.dark {
                background-color: hsl(220 49% 8%);
            }
        </style>

        <meta name="theme-color" content="#0b1220">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Dharmify">

        {{-- El ?v= no es opcional: son URLs fijas y hay tres cachés distintas
             (service worker, caché HTTP y la base de favicons de Chrome mobile)
             que si no se quedan con el ícono viejo para siempre. Al cambiar un
             ícono hay que subir este número Y el de sw.js Y el del manifest. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/favicon.ico?v=5" sizes="any">
        <link rel="icon" href="/icons/icon-192.png?v=5" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=5">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
