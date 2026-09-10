# Tests

Los tests no son negociables. Existen desde el primer commit y `make qa` no pasa
sin ellos.

## Las suites

```
tests/
├── Unit/           dominio puro. Sin kernel, sin base de datos. Milisegundos.
├── Architecture/   convenciones de estructura y determinismo. Sin kernel.
├── Integration/    adaptadores reales contra PostgreSQL y Redis.
├── Functional/     peticiones HTTP reales a través del kernel de Symfony.
└── E2E/            Playwright contra la aplicación levantada en Docker.
```

Están ordenadas por coste. Una regla de negocio se prueba en `Unit`; si solo se
puede probar en `Functional`, es que la regla está en el sitio equivocado.

| Comando | Qué ejecuta |
| --- | --- |
| `make test` | Todas las suites PHP (recrea antes la base de test) |
| `make test-unit` | Solo dominio |
| `make test-architecture` | Solo convenciones |
| `make test-integration` | Solo adaptadores |
| `make test-functional` | Solo HTTP |
| `make test-e2e` | Playwright |

## Migraciones: la regla que no se toca

**El esquema de la base de datos de test se construye ejecutando las migraciones
reales.** Nunca `doctrine:schema:create`, nunca `schema:update --force`.

```
make test
   └─ composer test
        ├─ composer test-reset-db
        │     ├─ doctrine:database:drop   --force --if-exists --env=test
        │     ├─ doctrine:database:create --env=test
        │     └─ doctrine:migrations:migrate --env=test     ← aquí
        └─ phpunit
```

El motivo es directo: `schema:create` construye el esquema a partir del *mapeo
actual*. Si una migración está rota, o falta, o hace algo distinto de lo que dice
el mapeo, los tests pasan igualmente y el problema aparece en producción, que es
el único sitio donde el esquema sí se construye con migraciones.

La definición vive en `composer.json` (`test-reset-db`), no en el Makefile, para
que local, Docker y CI ejecuten literalmente lo mismo.

### El test que protege esto

`tests/Integration/Platform/Persistence/MigrationsFromScratchTest` comprueba que
**una base de datos vacía llega al esquema actual ejecutando solo migraciones**.

Lo hace con el comando de consola real, sobre una base de datos desechable con
nombre aleatorio, y la borra al terminar. Sin esto, una migración de hace seis
meses puede llevar rota meses sin que nadie lo note: nadie parte de cero salvo
producción el día que se levanta un entorno nuevo.

El test lleva además una guarda explícita: comprueba que la base creada es la
desechable. Durante el desarrollo del bootstrap, una fuga de `SYMFONY_DOTENV_VARS`
al proceso hijo hizo que el test operase sobre la base de test compartida y la
borrase; la aserción está ahí para que eso falle en voz alta y no en silencio.

## Orden aleatorio

`phpunit.dist.xml` fija `executionOrder="random"`. Es deliberado: es como se
descubre que un test empezó a depender de otro. Si la suite falla solo con cierta
semilla, el test es el problema, no el orden.

## Qué prueba cada suite

### Unit

El modelo, aislado. `CheckSystemHealthHandlerTest` es el ejemplo de por qué la
regla de dependencias paga: el handler se instancia con dos dobles y un
`MockClock`, sin contenedor ni red.

### Architecture

Lo que Deptrac no puede expresar:

* `ContextStructureTest` — cada clase vive en `<Producto>/<Contexto>/<Capa>`, el
  namespace coincide con el directorio, y ninguna clase se llama `…Manager`,
  `…Helper` o `…Util`.
* `DeterministicTimeTest` — `Domain` y `Application` no llaman a
  `new DateTimeImmutable()`, `time()`, `date()` ni `strtotime()`. El tiempo entra
  por `Psr\Clock\ClockInterface`.

Las reglas de dependencia entre capas y contextos las cubre Deptrac
(`make architecture`), que es la herramienta adecuada para eso.

### Integration

Adaptadores contra infraestructura real. `RedisProbeTest` es un caso que enseña
por qué hace falta: la primera versión de la sonda preguntaba al pool PSR-6 y
reportaba «cache sana» con Redis caído, porque los adaptadores de Symfony están
diseñados para degradar en silencio. Solo un test contra un Redis realmente
inalcanzable lo detecta.

En este entorno `cache.app` es el adaptador de array, así que los tests de Redis
construyen su propia conexión: probar el doble no prueba nada.

### Functional

HTTP real a través del kernel. Aquí viven las garantías del contrato público:
`/health` no filtra infraestructura, la home no finge que el registro ya existe.

### E2E

Playwright, en tres viewports: móvil pequeño (iPhone SE), móvil normal (Pixel 7) y
escritorio. Comprueba comportamiento y usabilidad, **nunca píxeles**: un test de
capturas se rompe en cada cambio de copy sin haber detectado jamás un problema
real.

Cubre: la home responde 200, no hay scroll horizontal en ningún viewport, el CTA
principal mide al menos 44 px de alto, Tailwind se cargó de verdad, el manifest es
válido y sus iconos existen, el service worker se registra con scope `/`, la
página offline es autosuficiente, y la consola no tiene errores.

Playwright se ejecuta **en su imagen oficial**, conectada a la red de compose y
hablando con Caddy directamente. Así local y CI ejecutan exactamente los mismos
navegadores, y no hace falta instalar librerías de sistema en la máquina de nadie.
`make test-e2e` levanta la aplicación antes; `playwright.config.ts` no tiene
bloque `webServer` porque dentro del contenedor no hay cliente de Docker.

La barra de depuración de Symfony está desactivada
([`config/packages/web_profiler.yaml`](../config/packages/web_profiler.yaml)) para
que el HTML que mide Playwright sea el que ve un usuario. El profiler sigue
recogiendo datos: cada respuesta lleva `X-Debug-Token-Link`.

## Escribir un test nuevo

* El nombre dice qué debe pasar, en snake_case:
  `test_losing_an_optional_component_only_degrades_the_system`.
* Una razón para fallar por test.
* Nada de datos compartidos entre tests: el orden es aleatorio.
* Si necesitas el kernel para probar una regla de negocio, la regla está mal
  colocada.
