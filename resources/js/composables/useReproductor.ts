import { computed, reactive, ref } from 'vue';
import { useOffline } from '@/composables/useOffline';

export type PistaReproducible = {
    id: number;
    titulo: string;
    serie: string;
    serieId: number;
    en_server: boolean;
    en_nube: boolean;
};

/**
 * El estado del reproductor.
 *
 * Vive en el módulo y no dentro de un componente a propósito: es un único
 * reproductor para toda la app, y cualquier pantalla tiene que poder darle play
 * a algo sin que el audio se reinicie al navegar.
 */
const audio = typeof Audio !== 'undefined' ? new Audio() : null;

const actual = ref<PistaReproducible | null>(null);
const sonando = ref(false);
const posicion = ref(0);
const duracion = ref(0);
const velocidad = ref(1);
const cola = ref<PistaReproducible[]>([]);

/** Mensaje por pista: "Trayendo de la nube…", errores. */
const avisos = reactive<Record<number, string>>({});

/** Lo que la UI usa para pintar la pastilla sin recargar la página. */
const enServer = reactive<Record<number, boolean>>({});

if (audio) {
    audio.addEventListener(
        'timeupdate',
        () => (posicion.value = audio.currentTime),
    );
    audio.addEventListener('durationchange', () => {
        duracion.value = audio.duration || 0;
        avisarDuracion();
    });
    audio.addEventListener('play', () => (sonando.value = true));
    audio.addEventListener('pause', () => (sonando.value = false));
    audio.addEventListener('ended', () => siguiente());

    /*
     * Un <audio> que no puede con su fuente falla en silencio: se queda quieto y
     * no avisa nada. Fue justo lo que tapó el bug de la copia local —el server
     * contestaba 202, el elemento no sabe qué hacer con eso y la pantalla se
     * quedaba como si nada—. Vale para cualquier fuente que no cargue.
     *
     * La guarda de `actual` no sobra: cerrar() pone src en vacío, y eso también
     * dispara un error.
     */
    audio.addEventListener('error', () => {
        const pista = actual.value;

        if (!pista || !audio.src) {
            return;
        }

        avisos[pista.id] = 'No se pudo reproducir este audio.';
        sonando.value = false;
    });
}

const urlAudio = (id: number) => `/pistas/${id}/audio`;

const cargar = (pista: PistaReproducible) => {
    if (!audio) {
        return;
    }

    actual.value = pista;
    delete avisos[pista.id];
    audio.src = urlAudio(pista.id);
    audio.playbackRate = velocidad.value;
    audio.play().catch(() => {});

    ponerMediaSession(pista);
};

/**
 * La ficha del sistema operativo: título, serie y carátula en la pantalla de
 * bloqueo, con sus controles. En un teléfono, sin esto la app se siente rota:
 * hay que desbloquear y volver a la pestaña para poder pausar.
 */
const ponerMediaSession = (pista: PistaReproducible) => {
    if (!('mediaSession' in navigator)) {
        return;
    }

    navigator.mediaSession.metadata = new MediaMetadata({
        title: pista.titulo,
        artist: pista.serie,
        album: 'Dharmify',
        artwork: [
            {
                src: `/series/${pista.serieId}/portada`,
                sizes: '512x512',
                type: 'image/jpeg',
            },
        ],
    });

    navigator.mediaSession.setActionHandler('play', () => audio?.play());
    navigator.mediaSession.setActionHandler('pause', () => audio?.pause());
    navigator.mediaSession.setActionHandler('seekbackward', () => saltar(-15));
    navigator.mediaSession.setActionHandler('seekforward', () => saltar(30));
    navigator.mediaSession.setActionHandler('nexttrack', () => siguiente());
};

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

/**
 * Le devuelve al servidor la duración exacta que acaba de medir el navegador.
 *
 * El barrido de encabezados resuelve casi todas las pistas, pero no todas. Esto
 * completa el resto sin costo: el dato ya está en memoria apenas carga el audio.
 * Se avisa una sola vez por pista y por sesión, para no mandar un pedido en cada
 * play.
 */
const avisadas = new Set<number>();

const avisarDuracion = () => {
    const pista = actual.value;
    const segundos = audio?.duration ?? 0;

    if (!pista || !Number.isFinite(segundos) || segundos < 1) {
        return;
    }

    if (avisadas.has(pista.id)) {
        return;
    }

    avisadas.add(pista.id);

    // Si falla no pasa nada: es un dato de más, no parte de reproducir.
    fetch(`/pistas/${pista.id}/duracion`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ segundos: Math.round(segundos) }),
    }).catch(() => {});
};

/**
 * Play sobre algo que puede no estar en el server.
 *
 * El orden es dispositivo → server → nube, y empieza por el dispositivo a
 * propósito: lo que se bajó para escuchar sin conexión tiene que sonar SIN
 * conexión. Mirando primero `en_server` —que es el estado del server, no el del
 * teléfono— darle play a algo ya bajado salía a pedirle permiso a la red para
 * reproducir un archivo que estaba a diez centímetros, y sin señal se quedaba
 * colgado en "Trayendo de la nube…" para siempre.
 *
 * Si de verdad no está en ninguna de las dos, que esté sólo en la nube NO es un
 * error: se pide traerlo y el usuario espera unos segundos. Es la diferencia
 * entre una biblioteca de 928 clases y una de 20 con 908 enlaces rotos.
 */
const reproducir = async (pista: PistaReproducible) => {
    const { guardadas, estaGuardada } = useOffline();

    if (
        guardadas.value.has(pista.id) ||
        pista.en_server ||
        enServer[pista.id]
    ) {
        cargar(pista);

        return;
    }

    /*
     * El repuesto de `guardadas`, que puede estar vacío si la pantalla todavía
     * no llamó a revisar(). Va después y no antes porque es asíncrono: en el
     * camino normal el play tiene que salir dentro del gesto del usuario, o
     * Safari lo bloquea por la política de reproducción automática.
     */
    if (await estaGuardada(pista.id)) {
        cargar(pista);

        return;
    }

    if (!pista.en_nube) {
        avisos[pista.id] = 'Este audio no está disponible.';

        return;
    }

    avisos[pista.id] = 'Trayendo de la nube…';

    try {
        const res = await fetch(`/pistas/${pista.id}/restaurar`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });
        const datos = await res.json();

        if (datos.estado === 'listo') {
            delete avisos[pista.id];
            enServer[pista.id] = true;
            cargar({ ...pista, en_server: true });

            return;
        }

        avisos[pista.id] = datos.mensaje ?? 'No se pudo traer de la nube.';
    } catch {
        avisos[pista.id] = 'No se pudo traer de la nube.';
    }
};

const alternar = () => {
    if (!audio || !actual.value) {
        return;
    }

    if (audio.paused) {
        audio.play().catch(() => {});
    } else {
        audio.pause();
    }
};

const saltar = (segundos: number) => {
    if (!audio || !audio.duration) {
        return;
    }

    audio.currentTime = Math.min(
        Math.max(0, audio.currentTime + segundos),
        audio.duration,
    );
};

const irA = (segundo: number) => {
    if (audio) {
        audio.currentTime = segundo;
    }
};

const cambiarVelocidad = (v: number) => {
    velocidad.value = v;

    if (audio) {
        audio.playbackRate = v;
    }
};

const siguiente = () => {
    if (!actual.value) {
        return;
    }

    const i = cola.value.findIndex((p) => p.id === actual.value?.id);

    if (i >= 0 && i + 1 < cola.value.length) {
        reproducir(cola.value[i + 1]);
    }
};

const anterior = () => {
    if (!actual.value) {
        return;
    }

    const i = cola.value.findIndex((p) => p.id === actual.value?.id);

    if (i > 0) {
        reproducir(cola.value[i - 1]);
    }
};

const cerrar = () => {
    audio?.pause();

    if (audio) {
        audio.src = '';
    }

    actual.value = null;
};

export function useReproductor() {
    return {
        actual,
        sonando,
        posicion,
        duracion,
        velocidad,
        cola,
        avisos,
        enServer,
        reproducir,
        alternar,
        saltar,
        irA,
        cambiarVelocidad,
        siguiente,
        anterior,
        cerrar,
        hayAnterior: computed(
            () => cola.value.findIndex((p) => p.id === actual.value?.id) > 0,
        ),
        haySiguiente: computed(() => {
            const i = cola.value.findIndex((p) => p.id === actual.value?.id);

            return i >= 0 && i + 1 < cola.value.length;
        }),
    };
}
