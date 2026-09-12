# Despliegue

> Turnin todavía no está desplegado en ningún sitio. Este documento describe lo
> que la imagen y la configuración ya soportan, no una infraestructura existente.
>
> `dev.turnin.es` **no es un despliegue**: es la máquina de desarrollo publicada
> por un Cloudflare Tunnel, y se apaga con ella. Está en
> [DEVELOPMENT.md](DEVELOPMENT.md#desarrollo-remoto-con-devturnines).

## La imagen

[`Dockerfile`](../Dockerfile), multi-stage:

| Etapa | Para qué |
| --- | --- |
| `base` | Runtime PHP 8.5-FPM y extensiones. Sin código. |
| `development` | `base` + Xdebug + usuario del host. La que usa `compose.yaml`. |
| `builder` | Instala dependencias de producción y compila los assets. |
| `production` | Copia el resultado del builder. Sin dev, sin Xdebug, no-root. |
| `web` | Caddy con el `public/` ya compilado dentro. |

La imagen de producción:

* corre como **`www-data`**;
* **sin dependencias de desarrollo** (`composer install --no-dev`) y con
  autoloader `classmap-authoritative`;
* **OPcache con `validate_timestamps=0`** y **precarga** del contenedor de Symfony
  ([`opcache-prod.ini`](../docker/php/conf.d/opcache-prod.ini)): el código es
  inmutable dentro de la imagen, así que no hay que hacer `stat()` en cada
  petición;
* **`read_only: true`** salvo `/tmp` y el volumen de `var/`;
* con **healthcheck** propio: `php bin/console turnin:health`, que comprueba base
  de datos, Redis y versión del esquema sin depender de que Caddy esté arriba.

```bash
docker build --target production -t turnin-app .
docker build --target web        -t turnin-web .
```

## Levantarlo

```bash
APP_SECRET=…  DATABASE_URL=…  REDIS_URL=…  docker compose -f compose.prod.yaml up -d
```

[`compose.prod.yaml`](../compose.prod.yaml) declara esas variables como
`${VAR:?mensaje}`: si falta una, Compose se niega a arrancar. Es intencionado —
arrancar con un `APP_SECRET` por defecto es peor que no arrancar.

## Migraciones: primero el esquema, luego los contenedores

**El contenedor no ejecuta migraciones al arrancar.**
[`production-entrypoint.sh`](../docker/php/production-entrypoint.sh) solo calienta
la caché.

Si cada contenedor migrara al arrancar, un despliegue con dos réplicas lanzaría
dos migraciones a la vez sobre la misma base de datos. Doctrine tiene bloqueo,
pero el resultado es un despliegue que a veces tarda el doble y a veces falla, y
que nadie sabe reproducir.

El orden es:

```bash
# 1. Migrar, una vez, desde un contenedor efímero de la imagen nueva
docker run --rm -e DATABASE_URL=… turnin-app:nueva \
    php bin/console doctrine:migrations:migrate --no-interaction

# 2. Desplegar la imagen nueva
docker compose -f compose.prod.yaml up -d
```

Esto obliga a que **toda migración sea compatible hacia atrás** durante la
ventana del despliegue: la versión antigua sigue sirviendo tráfico contra el
esquema nuevo. En la práctica, para un cambio con ruptura son dos despliegues
(añadir columna → desplegar código que la usa → eliminar la vieja).

Mientras haya migraciones pendientes, la sonda `schema` reporta `unhealthy` y
`/health` responde 503, así que el balanceador no manda tráfico a un contenedor
que corre contra un esquema que no es el suyo.

## Health checks

| Endpoint | Quién lo usa | Qué comprueba |
| --- | --- | --- |
| `GET /health` | Balanceador, monitorización | Base de datos, Redis, versión del esquema |
| `php bin/console turnin:health` | `HEALTHCHECK` del contenedor | Lo mismo, sin HTTP |

`200` = sirve tráfico (`healthy` o `degraded`); `503` = no lo sirve.

La distinción importa: perder Redis es `degraded` y el contenedor **sigue
recibiendo tráfico**, porque Turnin funciona más lento pero correcto sin caché.
Sacar de rotación toda la flota por un Redis caído sería un fallo autoinfligido.
Perder PostgreSQL es `unhealthy`.

## Logs

Producción escribe **JSON estructurado a stderr**; el runtime de contenedores es
el recolector. Cada línea lleva `request_id`, así que una petición se sigue de
principio a fin y entre servicios.

Nivel `info` hacia arriba, con `fingers_crossed` en `error`: una petición que
falla vuelca su contexto completo, una que va bien no.

## TLS y proxy

Caddy escucha en `:80` y espera terminación TLS por delante (balanceador o túnel).
Confía en `X-Forwarded-*` **solo del salto inmediato privado**
(`trusted_proxies static private_ranges`), y Symfony hace lo propio con
`SYMFONY_TRUSTED_PROXIES`. Confiar en cualquier `X-Forwarded-For` permitiría
falsificar la IP de origen y saltarse el rate limiting.

**Qué cabeceras** se creen no es una variable de entorno: está fijado en
[`config/packages/framework.yaml`](../config/packages/framework.yaml)
(`x-forwarded-for`, `x-forwarded-proto`, `x-forwarded-port`; `x-forwarded-host`
deliberadamente no). Ahí y no solo en `SYMFONY_TRUSTED_HEADERS` porque esa
variable la lee el componente Runtime desde `public/index.php`, que los tests
funcionales no ejecutan; como configuración, el contrato se puede probar. El
entorno sigue decidiendo **en quién** confiar, vía `SYMFONY_TRUSTED_PROXIES`.

## Copias de seguridad

No hay nada configurado; tampoco hay datos que perder todavía. Antes del primer
usuario real hace falta: copias de PostgreSQL con restauración **probada**, y
verificar que la restauración deja la base en un estado al que las migraciones
puedan seguir aplicándose. Anotado en [ROADMAP.md](ROADMAP.md).

Redis no necesita copia: es caché. Si eso deja de ser cierto, este párrafo tiene
que cambiar a la vez.
