import { onMounted, onUnmounted, ref } from 'vue';

/**
 * El evento que Chrome dispara cuando la app se puede instalar.
 *
 * No está en lib.dom: es propuesta, no estándar.
 */
type EventoDeInstalacion = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

declare global {
    interface Window {
        __pwaInstall?: {
            prompt: EventoDeInstalacion | null;
            installed: boolean;
        };
    }
}

/**
 * Estado de instalación de la PWA.
 *
 * El evento `beforeinstallprompt` lo captura un script inline en el <head> del
 * blade, NO este composable: Chrome lo dispara apenas carga la página, casi
 * siempre antes de que monte Vue. Registrarlo en un `onMounted` significa que el
 * evento ya pasó y el botón no aparece nunca — de forma intermitente, que es lo
 * peor de depurar. Acá sólo se lee lo que ese script ya guardó.
 */
export function usePwaInstall() {
    const sePuedeInstalar = ref(false);
    const yaInstalada = ref(false);
    const promptConsumido = ref(false);

    /**
     * iOS nunca dispara `beforeinstallprompt`: ahí la instalación es manual.
     * iPadOS se reporta como Mac, así que se lo distingue por los puntos táctiles.
     */
    const esIOS =
        typeof navigator !== 'undefined' &&
        (/iphone|ipod/i.test(navigator.userAgent) ||
            (/mac/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1));

    const revisarSiEstaInstalada = () => {
        yaInstalada.value =
            window.matchMedia('(display-mode: standalone)').matches ||
            // Safari en iOS no soporta display-mode y usa esto.
            (window.navigator as Navigator & { standalone?: boolean })
                .standalone === true ||
            window.__pwaInstall?.installed === true;
    };

    const alPoderInstalarse = () => {
        sePuedeInstalar.value = true;
    };

    const alInstalarse = () => {
        yaInstalada.value = true;
        sePuedeInstalar.value = false;
    };

    onMounted(() => {
        revisarSiEstaInstalada();
        sePuedeInstalar.value = !!window.__pwaInstall?.prompt;

        window.addEventListener('pwa:installable', alPoderInstalarse);
        window.addEventListener('pwa:installed', alInstalarse);
    });

    onUnmounted(() => {
        window.removeEventListener('pwa:installable', alPoderInstalarse);
        window.removeEventListener('pwa:installed', alInstalarse);
    });

    /**
     * Lanza el diálogo nativo.
     *
     * Devuelve `false` cuando no hay prompt disponible —iOS, o un prompt ya
     * consumido— para que la UI ofrezca el instructivo manual.
     *
     * El prompt se consume UNA sola vez, aunque la persona lo descarte. Por eso
     * no se esconde el botón después de usarlo: quien lo cerró sin querer no
     * podría reintentar hasta recargar, y en una SPA eso casi no pasa.
     */
    const instalar = async (): Promise<boolean> => {
        const evento = window.__pwaInstall?.prompt;

        if (!evento) {
            return false;
        }

        await evento.prompt();
        await evento.userChoice;

        window.__pwaInstall!.prompt = null;
        promptConsumido.value = true;

        return true;
    };

    return { sePuedeInstalar, yaInstalada, promptConsumido, esIOS, instalar };
}
