# Arquitectura

## La regla

```
Domain  ←  Application  ←  Infrastructure
```

Las flechas son dependencias. `Domain` no conoce a nadie. `Application` conoce
`Domain`. `Infrastructure` conoce a ambos. Nunca al revés.

Los SDK OAuth (`KnpU\OAuth2ClientBundle` y `League\OAuth2\Client`) son también
dependencias exclusivas de Infrastructure. Deptrac los agrupa como capa externa
`OAuth`; Application recibe claims traducidos y Domain no conoce Google.

**`Domain` es PHP puro**: sin Symfony, sin Doctrine, sin Twig, sin PSR-7. Puede
usar el propio lenguaje (`DateTimeImmutable`, `Stringable`, excepciones) y las
interfaces PSR (`Psr\Clock\ClockInterface`, `Psr\Log\LoggerInterface`), que son
contratos del ecosistema y no atan a ningún proveedor.

Esto no es purismo. El valor de Turnin está en decidir si dos personas pueden
intercambiar un turno; esa lógica tiene que poder ejecutarse miles de veces en un
test unitario, sin contenedor y sin base de datos. Cada dependencia de framework
que entra en `Domain` es una capa de arranque que pagamos en cada ejecución.

Lo comprueban [`deptrac.yaml`](../deptrac.yaml) y `make architecture`; no es una
convención que dependa de que alguien la recuerde en una revisión.

## Estructura

```
src/
  <Contexto>/
    Domain/           modelo, value objects, invariantes, puertos
    Application/      casos de uso: commands, queries, handlers
    Infrastructure/   adaptadores: Doctrine, HTTP, consola, Redis…

  Platform/
    <Módulo técnico>/
      Domain|Application|Infrastructure/
```

Y no:

```
src/Entity/  src/Repository/  src/Service/  src/Controller/
```

La diferencia importa cuando hay veinte contextos. Un directorio por capa técnica
te obliga a abrir cuatro carpetas para entender una funcionalidad; un directorio
por contexto te deja borrar una funcionalidad entera con `rm -rf`.

Hoy existen físicamente tres módulos bajo `Platform` y un contexto de producto:

| Módulo             | Qué es                                                                |
| ------------------ | --------------------------------------------------------------------- |
| `Platform\System`  | Salud del sistema: `/health`, `turnin:health`, correlación de logs.    |
| `Platform\Web`     | El shell web: la landing pública. Sin dominio propio, y así se declara. |
| `Platform\Identity` | Cuenta, contraseña, OAuth, sesión y perfil personal protegido.        |
| `Workforce`        | Catálogo, asignación, pools y onboarding laboral reanudable.            |
| `Scheduling`       | El calendario personal: días, turnos, patrones y su entrada por voz.    |
| `Swap`             | Qué quiere hacer alguien con sus turnos y cuándo puede trabajar.        |

No hay más porque no hay más producto todavía. El mapa de contextos previsto está
en [CONTEXT_MAP.md](CONTEXT_MAP.md); se crean cuando se implementan, no antes.

### Contextos aislados

Un contexto no toca las clases de otro. Ni su dominio, ni sus handlers, ni sus
repositorios. Se integran por eventos de dominio y puertos de aplicación.

Lo comprueba [`deptrac.contexts.yaml`](../deptrac.contexts.yaml), un segundo
análisis con su propio eje de capas. Son dos ficheros porque Deptrac se comporta
de forma ambigua cuando una clase pertenece a dos capas a la vez.

Que un directorio nuevo bajo `src/` respete la forma `<Producto>/<Contexto>/<Capa>`
lo comprueba `tests/Architecture/ContextStructureTest`: Deptrac solo ve clases que
participan en alguna dependencia, así que las dos comprobaciones son complementarias.

## CQRS

Tres buses de Symfony Messenger, uno por responsabilidad
([`config/packages/messenger.yaml`](../config/packages/messenger.yaml)):

| Bus           | Para qué                | Middleware               |
| ------------- | ----------------------- | ------------------------ |
| `command.bus` | Cambiar estado          | `doctrine_transaction`   |
| `query.bus`   | Leer                    | ninguno                  |
| `event.bus`   | Eventos de dominio      | admite cero suscriptores |

**No hay wrappers.** `MessageBusInterface $queryBus` se autocablea al bus correcto
por el nombre del argumento. Envolver Messenger en un `CommandBus` propio añadiría
una interfaz que no aporta nada: Messenger ya es el puerto.

Los handlers son clases normales de `Application`, **sin atributos de Symfony**.
El bus al que pertenecen es una decisión de cableado, así que vive en
[`config/services.yaml`](../config/services.yaml) como registro por convención:

```yaml
turnin.query_handlers:
    namespace: App\
    resource: '../src/*/*/Application/Query/*Handler.php'
    tags: [{ name: 'messenger.message_handler', bus: 'query.bus' }]
```

Todo se procesa de forma **síncrona**. Redis está levantado y listo, pero no hay
transporte asíncrono hasta que exista un caso de uso lento de verdad (matching,
notificaciones, importaciones). Convertir todo en asíncrono desde el principio
añade latencia, complejidad de depuración y estados intermedios a cambio de nada.

## Persistencia

PostgreSQL con Doctrine. Doctrine vive **solo** en `Infrastructure`. El primer
mapeo real es `Workforce/Infrastructure/Persistence/Doctrine/Mapping/Workplace.orm.xml`.

Los agregados son objetos PHP normales, así que **el mapeo se declara en XML**
desde `Infrastructure`, no con atributos sobre las clases de dominio. Es lo que
permite que `deptrac` prohíba `Domain → Doctrine` de verdad y no como aspiración.

```yaml
# config/packages/doctrine.yaml — una entrada por contexto según vayan apareciendo
mappings:
    Workforce:
        type: xml
        is_bundle: false
        dir: '%kernel.project_dir%/src/Workforce/Infrastructure/Persistence/Doctrine/Mapping'
        prefix: 'App\Workforce\Domain'
```

`auto_mapping` está desactivado a propósito: buscaría entidades anotadas por todo
`src/`, que es exactamente lo que no queremos.

Los repositorios son **interfaces en `Domain`** con nombres del negocio
(`SwapRequests::save()`, no `SwapRequestRepository::persist()`), implementadas en
`Infrastructure`. No hay repositorio genérico: un `findBy(array $criteria)` es una
consulta SQL disfrazada que traslada la decisión al llamante.

Hay dos agregados que **no** pasan por el ORM: `WorkerOnboardingDraft` y
`RosterDay`. Ambos poseen una colección hija, y una asociación de Doctrine
exigiría un `Doctrine\Common\Collections\Collection` sobre una clase de
`Domain` —la dependencia que `deptrac.yaml` existe para impedir—. Sus
repositorios hablan DBAL y construyen el agregado a mano. Está razonado en
[ADR 8](adr/0008-roster-and-calendar-model.md).

## Identificadores

UUID v7 mediante `symfony/uid`, generados en `Infrastructure`. Nunca autoincrement.

v7 en lugar de v4 porque es ordenable temporalmente: los índices de PostgreSQL no
se fragmentan y las claves quedan naturalmente ordenadas por creación.

El `Domain` no conoce `Symfony\Component\Uid`: generarlos es tarea de un puerto
(`WorkplaceIdGenerator`, `RosterIdGenerator`…), igual que el reloj.

Un value object de identidad por agregado es opcional y se usa donde protege una
regla: `WorkplaceId` valida el formato porque la identidad del catálogo llega de
fuera. `WorkerAssignment`, `SwapPool` y los agregados de `Scheduling` usan
`string`, porque ahí el VO no protegería nada que la columna `UUID` no proteja ya.

## Tiempo

Ver [DOMAIN.md § Tiempo](DOMAIN.md#tiempo). En resumen: `Domain` y `Application`
reciben el instante actual por `Psr\Clock\ClockInterface` y **nunca** llaman a
`new DateTimeImmutable()`, `time()` o `date()`. Lo comprueba
`tests/Architecture/DeterministicTimeTest`.

## Nombres

Prohibidos como sufijo: `Manager`, `Helper`, `Util(s)`, `Data`, `Info`. Todos
significan «no supe dónde poner esto», y lo comprueba `ContextStructureTest`.

Un servicio de aplicación pequeño y con un nombre que describa la operación
(`AcceptSwapProposal`) siempre gana a un `SwapManager` con catorce métodos.

## Qué evitamos, explícitamente

* CRUD disfrazado de DDD;
* entidades anémicas de getters y setters;
* servicios enormes;
* lógica de dominio en controladores o en plantillas Twig;
* `EntityManager` usado desde `Application`;
* repositorios genéricos;
* interfaces que existen solo porque «Clean Architecture dice que haya interfaces».

Un puerto se crea cuando hay algo que sustituir o que simular en un test. Un
puerto con una sola implementación que nunca se sustituye es una indirección.

## Cuándo saltarse todo esto

Cuando la alternativa sea peor. Si un caso obliga a elegir entre una abstracción
prematura y una dependencia directa fea, la dependencia directa fea gana: es más
fácil de leer y de borrar. Si se toma una decisión así, se documenta en un ADR
(`docs/adr/`).
