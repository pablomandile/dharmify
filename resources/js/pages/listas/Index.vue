<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ListMusic, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ListaBreve } from '@/types/biblioteca';

defineProps<{
    listas: ListaBreve[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mis listas', href: '/listas' }],
    },
});

const nueva = useForm({ nombre: '' });

const crear = () =>
    nueva.post('/listas', {
        preserveScroll: true,
        onSuccess: () => nueva.reset(),
    });
</script>

<template>
    <Head title="Mis listas" />

    <div class="space-y-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">Mis listas</h1>
            <p class="text-sm text-muted-foreground">
                Enseñanzas de retiros distintos, juntas y en el orden que
                quieras.
            </p>
        </div>

        <form class="flex max-w-md gap-2" @submit.prevent="crear">
            <Input
                v-model="nueva.nombre"
                placeholder="Nombre de la lista nueva"
                maxlength="80"
            />
            <Button type="submit" :disabled="nueva.processing">
                <Plus class="size-4" />
                Crear
            </Button>
        </form>

        <p v-if="nueva.errors.nombre" class="-mt-4 text-sm text-destructive">
            {{ nueva.errors.nombre }}
        </p>

        <div
            v-if="!listas.length"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <ListMusic class="mx-auto size-8 text-muted-foreground/60" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no armaste ninguna lista.
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                También podés crear una desde el botón «+» de cualquier
                enseñanza, y queda agregada de una.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="lista in listas"
                :key="lista.id"
                :href="`/listas/${lista.id}`"
                class="group flex items-center gap-3 rounded-xl border p-4 transition-colors hover:border-primary/60 hover:bg-accent/30"
            >
                <div
                    class="grid size-12 shrink-0 place-items-center rounded-lg bg-linear-to-br from-primary/30 to-primary/5 text-primary"
                >
                    <ListMusic class="size-5" />
                </div>

                <div class="min-w-0">
                    <p class="truncate font-medium group-hover:text-primary">
                        {{ lista.nombre }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ lista.pistas }}
                        {{ lista.pistas === 1 ? 'enseñanza' : 'enseñanzas' }}
                    </p>
                </div>
            </Link>
        </div>
    </div>
</template>
