<!DOCTYPE html>
{{--
    La pantalla que sirve el service worker cuando no hay conexión.

    Es una vista suelta y no una página de Inertia, con todo el estilo embebido:
    tiene que poder mostrarse sin los assets compilados, que es exactamente el
    caso en el que aparece.
--}}
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0b1220">
        <title>Sin conexión - Dharmify</title>
        <style>
            :root { color-scheme: dark; }
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: hsl(220 49% 8%);
                color: hsl(214 32% 91%);
                font-family: ui-sans-serif, system-ui, sans-serif;
                text-align: center;
                padding: 2rem;
            }
            img { width: 96px; height: 96px; opacity: .9; }
            h1 { font-size: 1.375rem; margin: 1.5rem 0 .5rem; font-weight: 600; }
            p { color: hsl(215 20% 65%); margin: 0 0 1.75rem; line-height: 1.6; max-width: 32ch; }
            button {
                background: hsl(198 93% 60%);
                color: hsl(220 49% 8%);
                border: 0;
                border-radius: .5rem;
                padding: .7rem 1.4rem;
                font: inherit;
                font-weight: 600;
                cursor: pointer;
            }
            button:hover { background: hsl(199 95% 74%); }
        </style>
    </head>
    <body>
        <main>
            <img src="/icons/icon-192.png?v=1" alt="">
            <h1>Sin conexión</h1>
            <p>No pudimos llegar al servidor. Lo que hayas guardado para escuchar
               sin conexión sigue disponible.</p>
            <button onclick="location.reload()">Reintentar</button>
        </main>
    </body>
</html>
