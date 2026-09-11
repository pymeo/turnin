# Desarrollo

## Requisitos

* **Docker** y **Docker Compose v2**. Es todo lo que hace falta para la aplicación:
  no necesitas PHP ni Composer instalados.
* **Node 20+** en el host, solo para Graft y para lanzar Playwright.
* **make**.

## Arrancar

```bash
git clone https://github.com/pymeo/turnin.git
cd turnin
make setup
```

`make setup` construye las imágenes, levanta los servicios, instala dependencias,
crea la base de datos, aplica migraciones, compila los assets e instala las
dependencias de Node. Al terminar, la aplicación está en <http://localhost:8080>.

Es idempotente: puedes volver a ejecutarlo cuando quieras.

## Servicios

| Servicio | Imagen | Puerto (host) | Para qué |
| --- | --- | --- | --- |
| `app` | build local, PHP 8.5-FPM | — | La aplicación |
| `web` | `caddy:2.10-alpine` | 8080 | Servidor web y proxy a PHP-FPM |
| `postgres` | `postgres:18-alpine` | 5432 | Base de datos |
| `redis` | `redis:8-alpine` | 6379 | Caché, y transporte asíncrono futuro |

Cuatro y no más. Un servicio nuevo tiene que justificar su coste de arranque, de
memoria y de mantenimiento.

Todos los puertos se publican en `127.0.0.1`, no en `0.0.0.0`: en una cafetería,
un Postgres de desarrollo escuchando en toda la red es un problema.

El contenedor `app` corre como el usuario del host (`HOST_UID`/`HOST_GID` en
`.env`), así que los ficheros que genera —migraciones, assets compilados— quedan
tuyos y editables fuera de Docker.

## Comandos

`make help` los lista todos. Los habituales:

```bash
make up / down / restart / ps / logs
make shell                  # bash dentro del contenedor de aplicación
make migrate                # aplica migraciones (VERSION=... para una concreta)
make migration              # genera una migración desde el mapeo
make db-reset               # recrea la base de dev desde cero, con migraciones
make assets                 # recompila Tailwind y el asset map
make test / test-unit / test-integration / test-functional / test-architecture
make test-e2e               # Playwright
make lint / lint-fix / static-analysis / architecture / audit
make qa                     # todo lo anterior
make graph / graph-check / graph-map / graph-viz
```

## Variables de entorno

`.env` está versionado y contiene **solo cableado de infraestructura, nunca
secretos**. Lo leen tanto Docker Compose como Symfony.

Orden de carga (gana el último): `.env` → `.env.$APP_ENV` → `.env.local` →
`.env.$APP_ENV.local` → variables reales del entorno.

Tus ajustes locales van en `.env.local`, que está ignorado por git. En producción
los valores vienen del entorno, no de un fichero.

`APP_SECRET` está deliberadamente vacío en `.env`: dev y test tienen el suyo en
`.env.dev` y `.env.test`, y producción tiene que inyectarlo o arrancar fallando.

## Frontend

Symfony AssetMapper —sin bundler, sin `node_modules` en el navegador— más Tailwind
v4 vía `symfonycasts/tailwind-bundle`, que descarga su propio binario.

```bash
make assets                                                   # compilar
docker compose exec app php bin/console tailwind:build --watch  # en desarrollo
```

El sistema de diseño está en [`assets/styles/app.css`](../assets/styles/app.css):
tokens en `@theme`, componentes en `@utility`. Tailwind v4 solo permite `@apply`
sobre utilidades registradas, así que los componentes se declaran con `@utility`
y no dentro de `@layer components`; si no, `@apply btn` falla la compilación.

## La barra de depuración

Está **desactivada** por defecto
([`config/packages/web_profiler.yaml`](../config/packages/web_profiler.yaml)), para
que el HTML que ve Playwright sea el que ve un usuario. El profiler sigue activo:
cada respuesta lleva `X-Debug-Token-Link` y `/_profiler/latest` está a un clic.

Si la prefieres, cambia `toolbar: false` a `true` en ese fichero. No puede ser una
variable de entorno: el bundle la lee al compilar el contenedor.

## Xdebug

Está instalado pero inactivo. Actívalo por ejecución:

```bash
docker compose exec -e XDEBUG_MODE=debug app php bin/console <comando>
```

o pon `XDEBUG_MODE=debug` en `.env.local` y `make restart`. Se conecta a
`host.docker.internal:9003`.

Los objetivos de test y QA fuerzan `XDEBUG_MODE=off` para no pagar su coste.

## Problemas conocidos

**`make setup` tarda la primera vez.** Construye la imagen de PHP compilando
extensiones; a partir de ahí va en caché.

**`make test-e2e` descarga ~3,5 GB la primera vez.** Es la imagen oficial de
Playwright con los navegadores y sus librerías de sistema. A cambio, no hay que
instalar nada en tu máquina y CI ejecuta exactamente lo mismo.

**Assets que no se actualizan.** `public/assets/` es salida compilada y, mientras
exista, Symfony la sirve en lugar de leer `assets/`. Es la trampa más fácil de
este repo: añades una utilidad en `app.css`, la plantilla la usa, y la pantalla
sigue exactamente igual porque el navegador recibe el CSS de la última
compilación. **Si tocas CSS, plantillas o JS, `make assets`.**

El síntoma típico es una clase que existe en el fuente y no aparece en el CSS
servido. Para comprobarlo sin abrir el navegador:

```bash
grep -c '\.calendar-tone-amber{' var/tailwind/app.built.css   # 0 = compilación vieja
```

Tailwind v4 solo emite las utilidades que *ve escritas* en el código, así que un
nombre de clase construido concatenando (`'calendar-tone-' ~ color`) tampoco
aparecerá nunca. Por eso las plantillas declaran el mapa completo de tonos como
literales.
