# Dharmify

Una biblioteca personal de enseñanzas de Dharma, para escuchar con o sin
conexión. **928 grabaciones en 145 series de 37 maestros: 1.029 horas, 52 GB.**

Los originales viven en OneDrive y no se mueven de ahí. El servidor guarda sólo
lo que se está escuchando, y el teléfono guarda lo que uno marca para llevarse.
Eso es todo el proyecto; el resto son consecuencias de esa decisión.

<https://dharmify.pablomandile.com.ar>

---

## Por qué está hecho así

La biblioteca son 52 GB en OneDrive y el hosting tiene 200 GB para todo. Bajarla
entera no era una opción, y tener 20 clases con 908 enlaces rotos tampoco. De
ahí las tres capas:

| Capa            | Qué guarda                       | Quién la maneja             |
| --------------- | -------------------------------- | --------------------------- |
| **OneDrive**    | El original de todo, siempre     | `rclone` desde el servidor  |
| **Servidor**    | Sólo lo que se escuchó hace poco | Se trae al darle play       |
| **Dispositivo** | Lo que la persona marcó          | Cache Storage del navegador |

Tres reglas que salen de eso y que atraviesan todo el código:

1. **La nube es la única copia que importa.** Lo del servidor es descartable.
2. **El catálogo es la fuente de verdad, no la carpeta.** Una enseñanza existe en
   la biblioteca aunque su archivo no esté en ningún disco del servidor.
3. **Darle play a algo que no está no es un error.** Es un 202, un aviso de
   "Trayendo de la nube…" y unos segundos de espera.

## Cómo se arma el catálogo sin bajar 52 GB

Todo lo que la biblioteca sabe de un audio sale de **los primeros 1,5 MB** del
archivo, que es donde viven las etiquetas. Nunca se descarga un mp3 para leerlo.

Cuatro cosas se sacan de ahí, cada una con su comando reanudable:

```bash
php artisan dharma:portadas            # la carátula: el flyer de la carpeta o la imagen embebida
php artisan dharma:portadas-genericas  # dibuja una para las series que no tienen ninguna
php artisan dharma:duraciones          # la duración real de cada pista
php artisan dharma:titulos             # el título, desde la etiqueta de álbum (mostrar / --aplicar)
php artisan dharma:iconos              # el favicon y los íconos de la PWA, desde el logo
```

Van por tandas y se pueden volver a correr: cada ítem se marca apenas se
resuelve, así que si el hosting mata el proceso a mitad de camino —pasa—,
relanzar retoma donde iba en vez de empezar de cero.

### Tres cosas que sólo se supieron midiendo

Están acá porque ninguna era obvia y las tres cambiaron el diseño.

**A getID3 hay que decirle el tamaño real del archivo.** Si no, mide el pedazo
que uno le pasó. Sobre tres grabaciones de una hora contestó 46, 23 y 12
segundos; con el tamaño verdadero, 1:03:47, 1:03:05 y 1:24:28 — exactas al
segundo contra el archivo entero.

**Pedir más encabezado sale gratis.** Contra OneDrive, 400 KB tardan 8,1 s y
2,5 MB tardan 7,8 s: lo que se paga es la ida y vuelta, no los bytes. Empezamos
pidiendo 400 KB y una serie entera quedó sin duración porque su carátula
embebida ocupa 748 KB y el audio recién empieza en el byte 758.794.

**Tres lecturas simultáneas, no cuatro.** rclone está escrito en Go y pide hilos
del sistema; el hosting los limita para la cuenta entera. Con cuatro en paralelo
muere con `failed to create new OS thread` y devuelve encabezados vacíos.

## Sonar sin cortarse

- El audio se sirve **fuera del docroot**, con `Range` / 206 implementado a mano.
  Sin el 206 el audio suena pero no se puede adelantar, que es media aplicación.
- El `<audio>` vive en un **layout persistente** de Inertia, no en una página:
  es lo que hace que la música no se corte al navegar.
- **Media Session API**: título, serie y carátula en la pantalla de bloqueo. En
  un teléfono, sin eso la app se siente rota.
- Sin conexión, el service worker **sintetiza el 206** a partir del blob
  guardado. Una entrada de Cache Storage devuelve siempre el archivo entero con
  200, así que suena pero no se puede adelantar.

## Instalar

Hace falta PHP 8.4, MySQL, Node y —para leer OneDrive— el binario de
[rclone](https://rclone.org) en `bin/rclone` o en el `PATH`.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
```

En `.env`, además de la base:

```dotenv
ADMIN_EMAIL=              # a estas cuentas se les da admin al entrar (separadas por coma)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

El acceso es **con Google y por invitación**: no hay alta pública. La primera
fuente se agrega desde _Configuración → Fuentes_, con una ruta de rclone
(`onedrive:Carpeta/Sub`) o una carpeta del disco.

> Sobre una carpeta de OneDrive sincronizada por Windows, leerle el primer byte a
> un archivo lo **descarga entero**. Listar nombres y tamaños no. Por eso
> `dharma:duraciones`, que lee 928 encabezados de una, se niega a correr contra
> una fuente local salvo que se insista con `--igual`: sin esa traba son 52 GB
> bajándose sin avisar.

## Desarrollo

```bash
composer dev        # servidor, cola, logs y Vite, todo junto
composer ci:check   # lo mismo que corre el CI
```

`ci:check` es ESLint + Prettier + vue-tsc + Pint + PHPStan + 109 pruebas. Las
pruebas usan SQLite en memoria; producción es MySQL, y la diferencia se nota en
al menos un lugar (el cotejo: SQLite ordena "Ángeles" después de la Z).

### Desplegar

El servidor no tiene Node: se compila local y se copia `public/build`.

```bash
npm run build
git push
# en el servidor: git pull && php artisan migrate --force && limpiar cachés
scp -r public/build servidor:.../public/build_nuevo   # y recién ahí se cambia
```

Al cambiar un ícono hay que subir el `?v=` en **tres** lugares —el blade, el
manifest y `CACHE_APP` de `sw.js`— o queda pegado el viejo en la caché del
service worker, en la del navegador y en la base de favicons de Chrome.

## Cómo está organizado

```
app/Importacion/     Leer la nube y armar el catálogo
  Lectores/            LectorRclone (producción) y LectorLocal (desarrollo)
  TaxonomiaDeCarpeta   Saca tipo, año, idioma y maestros del nombre de la carpeta
  Extraer*             Carátula, duración y título desde el encabezado del mp3
app/Http/Controllers/
  PistaController      El 206, el 202 y la restauración desde la nube
resources/js/
  composables/         useReproductor (el <audio> único) y useOffline (la caché)
  components/FilaDePista.vue   Una enseñanza y sus botones, en un solo lugar
public/sw.js         Service worker: audio offline con seek, y rescate de Inertia
```

## Estado

Andando en producción: reproductor, offline, favoritos, listas, descargas,
búsqueda y filtros. **145 de 145 series con carátula** (83 propias, 62
dibujadas), 901 pistas con duración exacta.

Pendiente: retomar donde quedaste, la purga automática del servidor, el panel de
invitaciones y la biblioteca pública —el modelo ya la soporta, falta apuntarla a
su carpeta—.

---

Hecho por [Pablo Mandile](https://bioinfo.pablomandile.com.ar/pablo).
