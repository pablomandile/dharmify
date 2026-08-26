<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    Check,
    Copy,
    Link2,
    RotateCcw,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

type Invitacion = {
    id: number;
    /** null mientras es un link que nadie usó todavía. */
    email: string | null;
    /** La URL a pasar, sólo en los links sin reclamar. */
    url: string | null;
    estado: 'pendiente' | 'aceptada' | 'vencida' | 'revocada' | 'link';
    invitada_el: string | null;
    expira_en: string | null;
    invitada_por: string | null;
    usuario: {
        nombre: string;
        avatar: string | null;
        favoritos: number;
        listas: number;
        ultima_actividad: string | null;
    } | null;
};

const props = defineProps<{ invitaciones: Invitacion[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Invitaciones', href: '/settings/invitaciones' },
        ],
    },
});

const page = usePage();
const estado = computed(() => page.props.estado as string | null);
const error = computed(() => page.props.error as string | null);

/*
 * Tres grupos, porque son tres cosas distintas: sobre quien ya entró se decide
 * si sigue viendo la biblioteca; sobre una invitación por email, sólo si sigue
 * en pie; y un link sin usar todavía no tiene dueño, así que lo único que hay
 * para hacer con él es copiarlo o darlo de baja.
 */
const conCuenta = computed(() => props.invitaciones.filter((i) => i.usuario));
const links = computed(() =>
    props.invitaciones.filter((i) => !i.usuario && i.estado === 'link'),
);
const pendientes = computed(() =>
    props.invitaciones.filter((i) => !i.usuario && i.estado !== 'link'),
);

const comparte = (i: Invitacion) =>
    i.estado === 'pendiente' || i.estado === 'aceptada';

const etiqueta: Record<Invitacion['estado'], string> = {
    pendiente: 'Todavía no entró',
    aceptada: 'Con acceso',
    vencida: 'Vencida',
    revocada: 'Sin acceso',
    link: 'Sin usar',
};

const abierto = ref(false);

const form = useForm({ email: '', vence_en_dias: 'nunca' });

const invitar = () => {
    form.transform((datos) => ({
        ...datos,
        vence_en_dias:
            datos.vence_en_dias === 'nunca'
                ? null
                : Number(datos.vence_en_dias),
    })).post('/settings/invitaciones', {
        preserveScroll: true,
        /*
         * Se cierra en el éxito: la navegación del cliente no desmonta los
         * overlays, y si no queda el formulario abierto sobre la pantalla ya
         * actualizada.
         */
        onSuccess: () => {
            form.reset();
            abierto.value = false;
        },
    });
};

const revocar = (i: Invitacion) => {
    const aviso =
        `¿Dejar de compartir la biblioteca con ${i.email}?\n\n` +
        'Conserva su cuenta, sus favoritos y sus listas: lo que deja de ver es ' +
        'el contenido. Podés volver a compartirla cuando quieras.';

    if (confirm(aviso)) {
        router.post(
            `/settings/invitaciones/${i.id}/revocar`,
            {},
            { preserveScroll: true },
        );
    }
};

const restaurar = (i: Invitacion) =>
    router.post(
        `/settings/invitaciones/${i.id}/restaurar`,
        {},
        { preserveScroll: true },
    );

const crearLink = () =>
    router.post('/settings/invitaciones/link', {}, { preserveScroll: true });

/*
 * Cuál se acaba de copiar, para confirmarlo en el botón. Sin esa confirmación
 * no hay forma de saber si el clic hizo algo: el portapapeles no se ve.
 */
const copiado = ref<number | null>(null);

const copiar = async (i: Invitacion) => {
    if (!i.url) {
        return;
    }

    try {
        await navigator.clipboard.writeText(i.url);
        copiado.value = i.id;
        setTimeout(() => (copiado.value = null), 2000);
    } catch {
        // Sin permiso al portapapeles queda el link a la vista para copiarlo a
        // mano, que es justamente por lo que se muestra entero.
    }
};

const darDeBaja = (i: Invitacion) => {
    if (
        confirm(
            '¿Dar de baja este link? Si ya se lo pasaste a alguien, deja de servir.',
        )
    ) {
        router.delete(`/settings/invitaciones/${i.id}`, {
            preserveScroll: true,
        });
    }
};

const cancelar = (i: Invitacion) => {
    if (confirm(`¿Cancelar la invitación de ${i.email}?`)) {
        router.delete(`/settings/invitaciones/${i.id}`, {
            preserveScroll: true,
        });
    }
};

const iniciales = (nombre: string) =>
    nombre
        .split(' ')
        .slice(0, 2)
        .map((parte) => parte.charAt(0).toUpperCase())
        .join('');
</script>

<template>
    <Head title="Invitaciones" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="Invitaciones"
                description="Con quién compartís tu biblioteca"
            />

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="crearLink">
                    <Link2 class="size-4" />
                    Crear link
                </Button>
                <Button variant="outline" @click="abierto = true">
                    <UserPlus class="size-4" />
                    Invitar por email
                </Button>
            </div>
        </div>

        <div
            v-if="estado"
            class="rounded-md border border-primary/40 bg-primary/10 px-4 py-3 text-sm"
        >
            {{ estado }}
        </div>

        <div
            v-if="error"
            class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <div
            v-if="!invitaciones.length"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Todavía no invitaste a nadie. Si sabés su correo de Google,
                invitala por email; si no, creá un link y pasáselo. En los dos
                casos entra con Google y ya está.
            </p>
        </div>

        <section v-if="conCuenta.length" class="space-y-3">
            <h3 class="text-sm font-medium text-muted-foreground">
                Ya entraron
            </h3>

            <div
                v-for="i in conCuenta"
                :key="i.id"
                class="rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 gap-3">
                        <Avatar class="size-9 shrink-0 overflow-hidden">
                            <AvatarImage
                                v-if="i.usuario?.avatar"
                                :src="i.usuario.avatar"
                                :alt="i.usuario.nombre"
                            />
                            <AvatarFallback class="text-xs">
                                {{ iniciales(i.usuario?.nombre ?? '') }}
                            </AvatarFallback>
                        </Avatar>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    {{ i.usuario?.nombre }}
                                </span>
                                <Badge
                                    :variant="
                                        comparte(i) ? 'secondary' : 'outline'
                                    "
                                >
                                    {{ etiqueta[i.estado] }}
                                </Badge>
                            </div>

                            <p
                                class="mt-1 truncate text-xs text-muted-foreground"
                            >
                                {{ i.email }}
                            </p>

                            <p class="mt-2 text-sm text-muted-foreground">
                                {{ i.usuario?.favoritos }} favoritos ·
                                {{ i.usuario?.listas }} listas ·
                                <template v-if="i.usuario?.ultima_actividad">
                                    activo {{ i.usuario.ultima_actividad }}
                                </template>
                                <template v-else>
                                    sin datos recientes
                                </template>
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <Button
                            v-if="comparte(i)"
                            variant="ghost"
                            size="sm"
                            @click="revocar(i)"
                        >
                            <Ban class="size-4" />
                            Dejar de compartir
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            @click="restaurar(i)"
                        >
                            <RotateCcw class="size-4" />
                            Volver a compartir
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="links.length" class="space-y-3">
            <h3 class="text-sm font-medium text-muted-foreground">
                Links sin usar
            </h3>

            <div v-for="i in links" :key="i.id" class="rounded-lg border p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">
                                {{ etiqueta[i.estado] }}
                            </Badge>
                            <span class="text-xs text-muted-foreground">
                                sirve una sola vez
                            </span>
                        </div>

                        <!--
                            El link se muestra entero y no detrás de un botón:
                            si el portapapeles falla o el navegador no da
                            permiso, tiene que poder copiarse a mano.
                        -->
                        <p
                            class="mt-2 font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ i.url }}
                        </p>

                        <p class="mt-2 text-sm text-muted-foreground">
                            <template v-if="i.invitada_el">
                                Creado el {{ i.invitada_el }}
                            </template>
                            <template v-if="i.expira_en">
                                · vence el {{ i.expira_en }}
                            </template>
                            <template v-else>· sin vencimiento</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <Button variant="outline" size="sm" @click="copiar(i)">
                            <Check v-if="copiado === i.id" class="size-4" />
                            <Copy v-else class="size-4" />
                            {{ copiado === i.id ? 'Copiado' : 'Copiar' }}
                        </Button>
                        <Button variant="ghost" size="sm" @click="darDeBaja(i)">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="pendientes.length" class="space-y-3">
            <h3 class="text-sm font-medium text-muted-foreground">
                Invitadas, todavía sin entrar
            </h3>

            <div
                v-for="i in pendientes"
                :key="i.id"
                class="rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">
                                {{ i.email }}
                            </span>
                            <Badge
                                :variant="comparte(i) ? 'secondary' : 'outline'"
                            >
                                {{ etiqueta[i.estado] }}
                            </Badge>
                        </div>

                        <p class="mt-2 text-sm text-muted-foreground">
                            <template v-if="i.invitada_el">
                                Invitada el {{ i.invitada_el }}
                            </template>
                            <template v-if="i.expira_en">
                                · vence el {{ i.expira_en }}
                            </template>
                            <template v-else>· sin vencimiento</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <Button
                            v-if="!comparte(i)"
                            variant="outline"
                            size="sm"
                            @click="restaurar(i)"
                        >
                            <RotateCcw class="size-4" />
                            Reactivar
                        </Button>
                        <Button variant="ghost" size="sm" @click="cancelar(i)">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <Dialog v-model:open="abierto">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Invitar a la biblioteca</DialogTitle>
                    <DialogDescription>
                        Tiene que ser el correo con el que esa persona entra a
                        Google. No se manda ningún mail: el link se lo pasás
                        vos.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="invitar">
                    <div class="grid gap-2">
                        <Label for="email">Correo de Google</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="persona@gmail.com"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="vence">Vencimiento</Label>
                        <Select v-model="form.vence_en_dias">
                            <SelectTrigger id="vence">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="nunca">
                                    Sin vencimiento
                                </SelectItem>
                                <SelectItem value="7">7 días</SelectItem>
                                <SelectItem value="30">30 días</SelectItem>
                                <SelectItem value="90">90 días</SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Al vencer deja de ver la biblioteca, pero conserva
                            su cuenta, sus favoritos y sus listas.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="abierto = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            Invitar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
