import { Blob as BlobDeNode } from 'node:buffer';
import { beforeEach, vi } from 'vitest';
import { AudioFalso, CacheStorageFalso } from './dobles';

/*
 * Los dobles se ponen ANTES de que se importe nada del código bajo prueba.
 *
 * `useReproductor` construye su <audio> al cargarse el módulo, no dentro de una
 * función: si el doble llegara después, el reproductor ya tendría el de jsdom,
 * cuyo play() no devuelve una promesa y hace explotar el .catch() de cargar().
 */
vi.stubGlobal('Audio', AudioFalso);

/*
 * El Blob de jsdom no implementa stream(), y la Response de Node —undici— lo
 * primero que hace con un Blob es pedirle el stream. Sin esto, guardar un audio
 * falla en el entorno de pruebas con "object.stream is not a function", que no
 * tiene nada que ver con el código: en un navegador de verdad las dos cosas son
 * la misma implementación.
 */
vi.stubGlobal('Blob', BlobDeNode);

beforeEach(() => {
    // Caché nueva en cada prueba: si no, lo que una guarda se lo encuentra la
    // siguiente y las pruebas se contaminan en el orden en que corran.
    vi.stubGlobal('caches', new CacheStorageFalso());
    vi.stubGlobal('fetch', vi.fn());
});
