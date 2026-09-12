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

## Desarrollo remoto con dev.turnin.es

`http://localhost:8080` no sirve para probar en el móvil ni para hablar con
Google: OAuth exige un `redirect_uri` HTTPS registrado de antemano. Turnin
publica por eso el entorno local en un dominio estable:

```text
Móvil / Internet → https://dev.turnin.es → Cloudflare DNS → Cloudflare Tunnel
    → tu máquina → Caddy → PHP-FPM
```

El día a día son dos órdenes:

```bash
make up          # PostgreSQL, Redis, PHP y Caddy
make tunnel-up   # publica https://dev.turnin.es
```

y al terminar:

```bash
make tunnel-down
make down
```

`make tunnel-status` dice si el túnel está vivo y comprueba `/health` por dentro
y por fuera. Es idempotente: `make tunnel-up` dos veces no arranca dos procesos.

**Mientras el túnel o el ordenador estén apagados, `dev.turnin.es` no responde.**
No es un servidor: es esta máquina. Cloudflare devuelve entonces un error 1033 o
502, que significa exactamente eso y no que algo esté roto.

### No hay ningún puerto abierto

`cloudflared` abre una conexión **saliente** hacia Cloudflare y sirve por ella el
mismo `127.0.0.1:8080` que ya usa `curl` en local. El router no tiene ni una regla
nueva, y Caddy sigue sin escuchar en `0.0.0.0`.

### Preparar la máquina una sola vez

Ya está hecho en el equipo de desarrollo. Para reproducirlo en otro:

```bash
# 1. cloudflared en el PATH (binario oficial)
curl -fsSL -o ~/.local/bin/cloudflared \
    https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
chmod +x ~/.local/bin/cloudflared

# 2. autorizar la zona turnin.es en el navegador
cloudflared tunnel login

# 3. crear el túnel y su ruta DNS
cloudflared tunnel create turnin-dev
cloudflared tunnel route dns turnin-dev dev.turnin.es
```

`cloudflared tunnel create` escribe las credenciales en
`~/.cloudflared/<UUID>.json` y `login` deja el certificado en
`~/.cloudflared/cert.pem`. **Ninguno de los dos entra jamás en el repositorio**
(ver [SECURITY.md](SECURITY.md#secretos)).

Falta el fichero que enlaza túnel y destino, `~/.cloudflared/turnin-dev.yml`, que
es lo que leen los objetivos de `make`:

```yaml
tunnel: <UUID del túnel>
credentials-file: /home/<usuario>/.cloudflared/<UUID>.json

ingress:
  - hostname: dev.turnin.es
    service: http://localhost:8080
  - service: http_status:404
```

Va fuera de `~/.cloudflared/config.yml` a propósito: así no puede pisar las reglas
de otro túnel de la misma máquina.

### El esquema público tiene que sobrevivir al salto

Cloudflare termina TLS y llega a Caddy por HTTP plano. Si nadie propaga el
esquema original, Symfony construye URLs `http://` y Google rechaza el callback
por no coincidir con el `redirect_uri` registrado.

Lo resuelven dos piezas, y hacen falta las dos:

* [`docker/caddy/Caddyfile`](../docker/caddy/Caddyfile) — `trusted_proxies static
  private_ranges`. Sin esto Caddy sobrescribe el `X-Forwarded-Proto` entrante con
  el esquema con el que recibió la petición, que es `http`.
* [`config/packages/framework.yaml`](../config/packages/framework.yaml) —
  `trusted_proxies` y `trusted_headers`. Está en configuración, y no solo en la
  variable `SYMFONY_TRUSTED_PROXIES`, porque esa la lee el componente Runtime
  desde `public/index.php` y los tests funcionales no pasan por ahí. Como
  configuración, el contrato se puede probar: lo hace
  `GoogleOAuthFlowTest::test_the_public_https_origin_survives_the_reverse_proxy_hop`.

`X-Forwarded-Host` **no** está entre las cabeceras de confianza: el `Host`
original llega intacto por el túnel, así que no hay nada que recuperar y hay una
cabecera menos en la que confiar.

### El profiler no existe en el dominio público

`dev.turnin.es` está en Internet y el panel de configuración del profiler
renderiza `$_SERVER`, donde en desarrollo vive el secreto real de Google. El
Caddyfile responde `404` a `/_profiler*` y `/_wdt*` cuando el `Host` es
`dev.turnin.es`. Por `localhost` siguen funcionando igual que siempre.

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
