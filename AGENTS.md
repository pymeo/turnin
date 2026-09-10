# Instrucciones para agentes

Turnin es una PWA para trabajadores por turnos que encuentra automáticamente
compañeros compatibles con los que cambiar un turno. Lee
[README.md](README.md) y [docs/PRODUCT.md](docs/PRODUCT.md) antes de dar por
supuesto qué hace el producto.

## Antes de tocar nada

1. **Consulta el grafo primero.** El repositorio está indexado con Graft.
   `npx graft ask "<pregunta>" --source` para localizar y entender,
   `npx graft grep "<símbolo>"` para encontrar todos los usos,
   `npx graft callers <símbolo> --depth 2` antes de renombrar o borrar.
   Cuesta cientos de tokens; reconstruirlo leyendo ficheros cuesta miles y se
   pierde las aristas. Ver [docs/GRAPH.md](docs/GRAPH.md).
2. **Lee `docs/` antes de tocar dominio.** [DOMAIN.md](docs/DOMAIN.md) tiene el
   vocabulario y las invariantes; [CONTEXT_MAP.md](docs/CONTEXT_MAP.md), las
   fronteras. Si un nombre no está ahí, no lo inventes en el código.
3. **Comprueba si ya hay un ADR.** `docs/adr/` explica por qué la arquitectura es
   como es. Varias decisiones que parecen raras están justificadas allí.

## La regla de dependencias

```
Domain  ←  Application  ←  Infrastructure
```

* `Domain` es **PHP puro**. Sin Symfony, sin Doctrine, sin Twig. Puede usar el
  lenguaje (`DateTimeImmutable`, `Stringable`) e interfaces PSR.
* `Application` conoce `Domain`. **Sin atributos de framework**: el bus de un
  handler se declara en `config/services.yaml`.
* `Infrastructure` conoce a ambos y es donde vive todo lo demás.

Estructura: `src/<Producto>/<Contexto>/{Domain,Application,Infrastructure}/`.

Lo comprueban `make architecture` (Deptrac, dos ejes: capas y contextos) y
`tests/Architecture/`. No son sugerencias.

## Reglas concretas

* **El tiempo entra por `Psr\Clock\ClockInterface`.** `Domain` y `Application`
  nunca llaman a `new DateTimeImmutable()`, `time()`, `date()` ni `strtotime()`.
  Hay un test que lo comprueba. El porqué, en [ADR 5](docs/adr/0005-time-and-clock.md).
* **Nada de `Manager`, `Helper`, `Util`, `Data`, `Info`** como nombre de clase.
  Todos significan «no supe dónde poner esto». Hay un test que lo comprueba.
* **Nada de `$status === 'pending'`.** Los estados de negocio son enums.
* **IDs**: UUID v7 vía `symfony/uid`, generados en `Infrastructure`. Nunca
  autoincrement, nunca expuestos secuencialmente.
* **Mapeo de Doctrine en XML** desde `Infrastructure`, no atributos sobre las
  clases de dominio. Ver [ADR 3](docs/adr/0003-postgresql.md).
* **Nunca confíes en un identificador enviado por el cliente.** Que llegue un
  `swapRequestId` no significa que quien lo envía participe en esa solicitud.
* **No caches datos privados.** Antes de tocar `public/sw.js`, lee la cabecera del
  fichero y [docs/SECURITY.md](docs/SECURITY.md#datos-offline).

## Tests

**No se salta ninguno.** Los tests existen desde el primer commit y `make qa` no
pasa sin ellos.

```
tests/Unit/           dominio puro, sin kernel ni base de datos
tests/Architecture/   convenciones y determinismo temporal
tests/Integration/    adaptadores reales contra PostgreSQL y Redis
tests/Functional/     HTTP real a través del kernel
tests/E2E/            Playwright, tres viewports
```

Una regla de negocio se prueba en `Unit`. Si solo se puede probar en `Functional`,
la regla está en el sitio equivocado.

`executionOrder="random"`: nada de estado compartido entre tests.

## Migraciones: obligatorio

**El esquema de test se construye ejecutando las migraciones reales.** Nunca
`doctrine:schema:create`, nunca `schema:update --force`.

```bash
make migration     # genera una migración desde el mapeo
make migrate       # la aplica en desarrollo
make test          # recrea la base de test CON migraciones y lanza PHPUnit
```

Si añades un agregado, añades su mapeo XML **y** su migración. El test
`MigrationsFromScratchTest` comprueba que una base vacía llega al esquema actual
solo con migraciones; si lo rompes, arréglalo, no lo marques como skipped.

## Comandos

```bash
make help              # todos, con descripción
make setup             # desde cero
make test              # suite PHP
make test-e2e          # Playwright
make lint              # estilo + YAML/Twig/contenedor
make static-analysis   # PHPStan nivel max
make architecture      # Deptrac
make qa                # todo. La puerta de calidad.
make graph             # reconstruye el grafo de Graft
```

Todo corre en Docker. No hace falta PHP en el host, y no lo instales.

## Criterios de aceptación

Un cambio está terminado cuando:

1. `make qa` pasa —incluye `composer validate`, estilo, lint, PHPStan nivel max,
   Deptrac y toda la suite PHP;
2. `make test-e2e` pasa, si tocaste UI, plantillas, assets o el service worker;
3. hay tests nuevos para el comportamiento nuevo, en la suite más barata posible;
4. la documentación está actualizada —ver abajo;
5. `git status` está limpio: nada generado, nada con pinta de secreto.

## Actualizar la documentación

No es opcional:

* **cambias arquitectura o una convención** → [ARCHITECTURE.md](docs/ARCHITECTURE.md),
  y un ADR nuevo en `docs/adr/` si es una decisión de peso;
* **añades o mueves un contexto o un agregado** → [DOMAIN.md](docs/DOMAIN.md) y
  [CONTEXT_MAP.md](docs/CONTEXT_MAP.md);
* **cambias el producto, el pricing o el paywall** → [PRODUCT.md](docs/PRODUCT.md);
* **tomas una decisión menor que alguien podría cuestionar** →
  [DECISIONS.md](docs/DECISIONS.md), con el motivo;
* **completas una slice** → [ROADMAP.md](docs/ROADMAP.md).

Un ADR se escribe cuando la decisión es difícil de revertir o cuando descartaste
alternativas razonables. Documenta también lo que descartaste y por qué: es la
parte que le sirve al que venga después.

## Qué NO hacer todavía

Está documentado como futuro, no como pendiente inmediato: Stripe y
suscripciones, importación masiva de hospitales, el motor de matching completo,
cambios encadenados, push, emails, login con Google, chat, backoffice, IA, OCR,
pagos entre trabajadores y Coverage B2B.

Y nunca, en ninguna iteración: **convertir los favores entre trabajadores en
dinero, tokens negociables o moneda interna.** Ver
[PRODUCT.md](docs/PRODUCT.md#favores-pendientes).

## Cómo trabajamos

Las prioridades, en este orden:

```
correctitud > mantenibilidad > simplicidad > tests > experiencia móvil > rendimiento > features
```

* **YAGNI.** DDD no significa cientos de ficheros vacíos. Un contexto se crea
  cuando se implementa; un puerto, cuando hay algo que sustituir o simular.
* **Vertical slices.** Una funcionalidad llega hasta la pantalla, o no es una
  funcionalidad.
* **Nada de TODOs vagos.** Si algo queda pendiente, va a
  [ROADMAP.md](docs/ROADMAP.md) con su motivo.
* **Ante la duda, la opción más simple** compatible con esta documentación, y
  déjalo por escrito.
