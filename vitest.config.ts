import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Config aparte de la de Vite, a propósito.
 *
 * La de build carga el plugin de Laravel y el de Wayfinder, que salen a leer
 * rutas de PHP y a escribir en resources/js. Nada de eso hace falta para probar
 * dos composables, y sí tarda: el 86% del build se va en Wayfinder.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        // El código bajo prueba toca window, document y navigator.
        environment: 'jsdom',
        include: ['tests/js/**/*.test.ts'],
        setupFiles: ['tests/js/preparar.ts'],
    },
});
