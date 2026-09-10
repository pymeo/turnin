# 4. CQRS sobre Symfony Messenger, sin wrappers

* **Estado**: aceptada
* **Fecha**: 2026-09-10

## Contexto

Turnin tiene una asimetría clara entre lectura y escritura. Las escrituras
(solicitar un cambio, aceptar una propuesta) son transaccionales y tienen
invariantes duras. Las lecturas (mi calendario, propuestas para mí) son
consultas de proyección que no deberían cargar agregados enteros.

Y el matching acabará siendo asíncrono, porque será lo primero que tarde.

## Decisión

**Tres buses de Symfony Messenger**: `command.bus` (con middleware
`doctrine_transaction`), `query.bus` y `event.bus` (que admite cero suscriptores).

Y dos decisiones sobre cómo se usan:

**Sin wrappers.** Nada de `CommandBusInterface` propia. `MessageBusInterface
$queryBus` se autocablea al bus correcto por el nombre del argumento. Envolver
Messenger añadiría una interfaz cuyo único cuerpo sería `return
$this->bus->dispatch($m)`.

**Sin atributos de framework en `Application`.** Los handlers son clases normales
y el bus al que pertenecen se declara en
[`config/services.yaml`](../../config/services.yaml) por convención de
directorio:

```yaml
turnin.query_handlers:
    namespace: App\
    resource: '../src/*/*/Application/Query/*Handler.php'
    tags: [{ name: 'messenger.message_handler', bus: 'query.bus' }]
```

**Todo síncrono por ahora** (`sync://`). Redis está levantado y preparado, pero
no hay transporte asíncrono hasta que exista un caso de uso lento de verdad.

## Alternativas

**Command bus propio sobre Messenger.** El argumento habitual es «no acoplarse a
Symfony». Descartada: Messenger *es* el puerto —una interfaz con un método
`dispatch`—, y el envoltorio solo añade un fichero por bus.

**Llamar a los handlers directamente desde los controladores.** Más simple hoy,
con dos adaptadores. Descartada porque el bus es justo lo que permitirá mover el
matching a asíncrono cambiando una línea de routing en vez de cada llamada.

**`#[AsMessageHandler]` en los handlers.** Es lo idiomático en Symfony y evita la
configuración YAML. Descartada por poco: mantiene `Application` completamente
libre de framework por el precio de cuatro líneas de configuración, y el registro
por convención escala igual de bien.

**Todo asíncrono desde el principio.** Descartada: añade latencia, estados
intermedios y depuración difícil a cambio de una escalabilidad que nadie necesita
con cero usuarios.

## Consecuencias

* Un caso de uso se puede probar llamando al handler directamente, sin bus.
* Pasar algo a asíncrono es una entrada en `routing`, no un refactor.
* Un handler nuevo en una carpeta que no encaje con el glob no se registra, y
  falla al arrancar. Es ruidoso, y es lo que queremos.
* `HandleTrait` devuelve `mixed`; los adaptadores declaran el tipo de retorno en
  un método privado para que sea PHP quien garantice el tipo, no un `assert()`
  que producción desactiva.
