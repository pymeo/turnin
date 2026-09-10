# Decisiones

Las decisiones con peso arquitectónico viven en [`adr/`](adr/). Este documento
recoge el resto: elecciones concretas que alguien podría cuestionar más adelante,
con el motivo al lado.

## ADR

| # | Decisión |
| --- | --- |
| [1](adr/0001-architecture.md) | DDD por contextos con Clean Architecture |
| [2](adr/0002-pwa-first.md) | PWA móvil en lugar de aplicación nativa |
| [3](adr/0003-postgresql.md) | PostgreSQL con Doctrine y mapeo XML |
| [4](adr/0004-cqrs-messenger.md) | CQRS sobre Symfony Messenger, sin wrappers |
| [5](adr/0005-time-and-clock.md) | El tiempo entra por un puerto |

## Versiones

**PHP 8.5.10**, no 8.4. Todo el toolchain lo soporta: PHPUnit 13, PHPStan 2.2,
Deptrac 4.7, php-cs-fixer 3.95 y phpredis 6.3 compilan y funcionan sobre 8.5. No
hubo que renunciar a nada.

Una trampa encontrada por el camino: **OPcache ya viene instalado y activo en la
imagen oficial de PHP 8.5**. Pedírselo a `docker-php-ext-install` rompe la
construcción con un `cp: cannot stat 'modules/*'` que no dice nada.

**Symfony 7.4 LTS**, no 8.0. Soporte largo y ecosistema de bundles maduro.

**PostgreSQL 18**, **Redis 8**, **Caddy 2.10**. Todas mantenidas, ninguna EOL.

## phpredis, no predis

Primer intento: predis (PHP puro), porque `pecl install redis` había fallado. El
diagnóstico era erróneo —lo que fallaba era OPcache en la misma instrucción, no
phpredis—. Con eso arreglado, phpredis 6.3 compila sin problemas sobre PHP 8.5, y
es notablemente más rápido, así que predis se desinstaló.

Merece la pena anotarlo: la primera explicación de un fallo de build fue la
equivocada, y de haberla dejado estar el proyecto habría cargado una dependencia
peor con un comentario justificándola.

## La sonda de Redis no usa el pool de caché

La primera versión preguntaba a `CacheItemPoolInterface`. Reportaba «cache sana»
con Redis apagado.

El motivo es que los adaptadores de caché de Symfony están **diseñados** para
sobrevivir a un backend caído: capturan la excepción, registran y devuelven un
fallo de caché. Es el comportamiento correcto para una caché y el equivocado para
una sonda.

`RedisProbe` abre su propia conexión con timeouts cortos y hace `ping()`. Hay un
test de integración contra un Redis realmente inalcanzable que fija la regresión.

## `executionOrder="random"` en PHPUnit

Es como se descubre que un test empezó a depender de otro. Ya pagó su coste
durante el bootstrap: el test de migraciones borraba la base de datos de test
compartida, y solo se notó porque el orden aleatorio hizo fallar a otros tests
después de él.

## `SYMFONY_DOTENV_VARS` y los procesos hijo

`MigrationsFromScratchTest` lanza `bin/console` con `DATABASE_URL` sobrescrito. No
funcionaba: Dotenv anota en `SYMFONY_DOTENV_VARS` qué variables le pertenecen y
las **vuelve a sobrescribir** en el siguiente arranque. Heredada por el hijo, esa
variable hacía que el proceso ignorase el `DATABASE_URL` que se le pasaba y usara
el de `.env.test` —o sea, la base de datos de test real, que luego borraba.

La solución es pasar `'SYMFONY_DOTENV_VARS' => false` al proceso hijo. El test
lleva además una aserción que comprueba que la base creada es la desechable, para
que un fallo así vuelva a ser ruidoso.

## La barra de depuración, desactivada

`web_profiler.toolbar: false`. Así el HTML que mide Playwright es el que ve un
usuario, en vez de llevar 40 px de barra fija encima del viewport. El profiler
sigue recogiendo datos y cada respuesta lleva `X-Debug-Token-Link`.

No puede ser una variable de entorno: el bundle lee esa opción al compilar el
contenedor y falla con un `TypeError` si recibe un placeholder.

## Componentes Tailwind con `@utility`, no `@layer components`

Tailwind v4 solo permite `@apply` sobre utilidades **registradas**. Con los
componentes en `@layer components`, `@apply btn` dentro de `.btn-primary` falla la
compilación con `Cannot apply unknown utility class 'btn'`. Declarados con
`@utility`, la composición funciona.

## E2E con `--network host`

Los service workers solo existen en un contexto seguro. Contra
`http://web` (la red de compose) el navegador ni siquiera expone
`navigator.serviceWorker`, así que la mitad de la PWA no se puede probar.
`http://127.0.0.1` sí cuenta como seguro, y para llegar ahí desde el contenedor de
Playwright hace falta `--network host`.

## Los tests de arquitectura no duplican a Deptrac

Deptrac comprueba dependencias entre capas y contextos. Los tests de PHPUnit
comprueban lo que Deptrac no ve: la forma del árbol de directorios, que el
namespace coincida con la ruta, los nombres comodín (`…Manager`, `…Helper`) y que
`Domain`/`Application` no lean el reloj.

Son complementarios de verdad: Deptrac solo conoce las clases que participan en
alguna dependencia, así que una clase nueva y aislada en un directorio inventado
le pasa desapercibida y el test la caza.

## Caches de herramientas bajo `var/`

PHPStan, PHPUnit, php-cs-fixer, Deptrac y Playwright escriben todos por defecto en
la raíz del proyecto. Están reconfigurados para escribir bajo `var/`, que ya está
ignorado. La raíz es lo primero que ve quien abre el repositorio, y debe contener
solo cosas que alguien haya decidido poner ahí.
