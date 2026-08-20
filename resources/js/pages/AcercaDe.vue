<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Globe, Mail } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Acerca de', href: '/acerca-de' }],
    },
});

const EMAIL = 'pablo.mandile@gmail.com';
const SITIO = 'https://bioinfo.pablomandile.com.ar/pablo';
</script>

<template>
    <Head title="Acerca de" />

    <div class="mx-auto max-w-2xl p-4">
        <h1 class="text-2xl font-semibold">Acerca de</h1>

        <div class="mt-6 rounded-xl border bg-card p-6 sm:p-8">
            <!--
                El logo sobre su propio fondo negro: la marca trae un resplandor
                que se funde con el negro y sobre la tarjeta clara quedaría con
                un halo cuadrado alrededor.
            -->
            <div
                class="inline-flex items-center gap-3 rounded-xl bg-black px-4 py-3"
            >
                <AppLogoIcon class="h-10 w-auto" />
                <span class="text-xl font-semibold text-white">Dharmify</span>
            </div>

            <p class="mt-6 text-lg leading-relaxed">
                Mi biblioteca de enseñanzas de Dharma, para escuchar con o sin
                conexión.
            </p>

            <p class="mt-3 text-muted-foreground">
                Las grabaciones viven en OneDrive y se traen sólo cuando hacen
                falta. Lo que marcás para escuchar sin conexión queda guardado
                en el dispositivo, así que anda igual en el colectivo o en un
                retiro sin señal.
            </p>

            <div class="mt-8 border-t pt-6">
                <p
                    class="text-xs font-bold tracking-[0.075em] text-muted-foreground uppercase"
                >
                    Creado por
                </p>
                <p class="mt-1 text-lg font-semibold">Pablo Mandile</p>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:gap-6">
                    <a
                        :href="`mailto:${EMAIL}`"
                        class="inline-flex items-center gap-2 text-sm transition-colors hover:text-primary"
                    >
                        <Mail class="size-4 shrink-0" />
                        <span class="break-all">{{ EMAIL }}</span>
                    </a>

                    <a
                        :href="SITIO"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 text-sm transition-colors hover:text-primary"
                    >
                        <Globe class="size-4 shrink-0" />
                        <span>Bioinfo</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
