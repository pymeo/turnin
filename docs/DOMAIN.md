# Dominio

Este documento es el vocabulario compartido. Si un nombre no está aquí, no
debería aparecer en el código; si aparece en el código y no está aquí, falta
actualizarlo.

> **Estado**: `Platform\System`, `Platform\Identity`, el catálogo
> `Workforce.Workplace`, la base del contexto laboral (`StaffCategory`,
> `OrganizationalUnit`, `WorkerAssignment` y `SwapPool`) y el calendario personal
> (`Scheduling`: `RosterDay`, `ShiftPreset`, `RosterPattern`) están
> implementados, y también la primera vuelta de `Swap`: publicar un turno que se
> quiere soltar y declararse disponible para trabajar un día. Propuestas,
> aceptación y matching siguen siendo trabajo posterior.

## Vocabulario

| Término | Significado en Turnin |
| --- | --- |
| **Organization** | La entidad empleadora. «Servicio Andaluz de Salud». |
| **Workplace** | El centro físico. «Hospital Universitario Virgen de las Nieves». |
| **SwapPool** | El conjunto dentro del cual ciertas personas *pueden* intercambiar. «UCI · Enfermería». |
| **Membership** | La pertenencia de una persona a un pool, con su categoría y su unidad. |
| **StaffCategory** | Categoría profesional normalizada, con aliases y reglas de especialidad/área. |
| **Specialty** | Especialidad opcional ligada a una categoría. |
| **OrganizationalUnit** | Destino o unidad de adscripción dentro de un centro; fija o volante. |
| **WorkerAssignment** | Asignación laboral actual, separada de la cuenta. |
| **PersonalProfile** | Nombre y teléfono protegido de una persona, separado de su asignación laboral. |
| **UsageIdentity** | Evidencia privada que impide cuentas duplicadas mediante huellas no reversibles de DNI/NIE y teléfono. |
| **Employer** | Empresa o servicio de salud que emplea a la persona en el centro. |
| **RosterDay** | Un día del cuadrante de una persona: `REST` o `WORKING`. Que no exista significa que aún no lo ha indicado. |
| **ShiftSegment** | Un tramo de trabajo dentro de un día. Guarda copia del preset con el que se creó. |
| **ShiftPreset** | El botón rápido del trabajador: «Mañana, M, 08:00–15:00». Plantilla, no registro. |
| **RosterPattern** | Una rotación repetible, en `PatternSlot` ordenados: M M T T N N L L L. |
| **ScheduleDraft** | Una propuesta de días, antes de escribir nada. Pintar, patrón y voz terminan aquí. |
| **ShiftWindow** | Las horas de un segmento, en hora de pared. Deriva si termina al día siguiente. |
| **Shift** | Un turno concreto: quién, dónde, qué día laboral, qué franja. |
| **ShiftKind** | Mañana, tarde, noche… La franja, no las horas exactas. |
| **WorkDate** | El *día laboral* al que se imputa un turno. No es un timestamp. |
| **Availability** | Cuándo alguien quiere o puede trabajar, con grados. |
| **SwapRequest** | «Tengo este turno y busco quien pueda hacerlo». Una sola intención, y no cambia el cuadrante. |
| **Availability** | Declaración explícita: «el 21 puedo trabajar en este grupo». Nunca se deduce del calendario. |
| **SwapProposal** | Una solución concreta que el motor propone a las personas implicadas. |
| **SwapAgreement** | Una propuesta aceptada por todos y, si hace falta, aprobada. |
| **ShiftDebt** | «Marta hizo mi turno y le debo uno». Nominal, sin precio. |
| **Cycle** | Un cambio encadenado: A→B→C→A. |

## `Workplace` — catálogo implementado

`Workplace` representa un centro físico o dispositivo público donde
potencialmente puede utilizarse Turnin. No representa al empleador
(`Organization`) ni crea por sí solo una frontera de intercambio (`SwapPool`).

El agregado conserva: UUID v7 interno; fuente y su identificador externo; nombre;
tipo; comunidad autónoma, provincia y municipio; titularidad; actividad; instante
de última modificación observada en la fuente; creación y actualización. Para
Atención Primaria conserva además Área de Salud y Zona Básica de Salud cuando la
fuente las publica.

Tipos implementados: `HOSPITAL`, `HEALTH_CENTER`, `LOCAL_CLINIC` y
`OUT_OF_HOSPITAL_URGENT_CARE`. La titularidad implementada es únicamente
`PUBLIC`: una fila privada no entra en el agregado.

Invariantes:

* `(source, externalId)` identifica unívocamente una fila y tiene constraint
  único en PostgreSQL; el nombre nunca es identidad;
* desaparecer de una foto completa desactiva el centro, nunca lo borra;
* reaparecer reactiva la misma identidad;
* una descarga o parseo incompletos no constituyen una foto y no causan bajas;
* `sourceUpdatedAt` cambia solo cuando cambia el registro observado, de modo que
  una sincronización idéntica es realmente idempotente.

Palabras que **no** usamos: *oferta*, *precio*, *puja*, *mercado*, *crédito*,
*token*. Turnin no es un mercado y el vocabulario lo refleja
(→ [PRODUCT.md](PRODUCT.md#favores-pendientes)).

## `Scheduling` — el calendario personal implementado

El dato del que depende todo lo demás: sin saber qué trabaja la gente no hay
matching, ni disponibilidad, ni puentes.

### `RosterDay` y por qué no existe `UNKNOWN`

Un día existe como fila **solo cuando el trabajador ha dicho algo sobre él**. Los
estados son `REST` y `WORKING`. No hay un tercer estado: no saber no es un dato
del día, es la ausencia del día.

```
sin fila        →  no lo ha indicado        (UNKNOWN)
state = REST    →  libra                    (sin segmentos)
state = WORKING →  trabaja                  (uno o más ShiftSegment)
```

**`vacío` no es `libre`.** Es la invariante más importante de este contexto:
ofrecer el descanso de alguien porque una celda estaba vacía sería el peor fallo
que puede cometer Turnin. Los resúmenes del mes cuentan los días desconocidos
aparte y nunca como libres.

Invariantes:

* `UNIQUE (worker_assignment_id, date)`, con constraint real en PostgreSQL;
* un día `REST` no puede llevar segmentos;
* un día `WORKING` necesita al menos uno, y sus segmentos no se solapan;
* borrar la información de un día **elimina la fila** y lo devuelve a UNKNOWN;
  marcarlo libre **crea** una fila `REST`. Son operaciones distintas.

### `ShiftSegment` guarda un snapshot

Al aplicar un `ShiftPreset`, el segmento **copia** etiqueta, abreviatura y horas.
El preset es una plantilla; el calendario histórico es un registro. Corregir
«Mañana» de 08:00–15:00 a 07:30–14:30 afecta al siguiente toque, nunca a marzo.

Un día admite varios segmentos desde el principio: el turno partido y el
«turno + guardia» son corrientes, y añadir el segundo después obliga a migrar
cuadrantes vivos.

### `ShiftPreset`: libre no es un turno

Los botones rápidos del trabajador, con sus aliases para el dictado («guardia»,
«g», «24 horas»). Se retiran (`active = false`), nunca se borran: hay segmentos
de hace meses que los nombran.

**No existe un preset «Libre».** Librar es `RosterDayState::REST`, una propiedad
del día. Modelarlo como turno lo metería en toda consulta que cuente horas
trabajadas. En la interfaz sí aparece junto a los turnos, porque ahí es donde el
pulgar lo busca.

### `ScheduleDraft`: un solo camino de escritura

```
pintar   ┐
patrón   ├─→ ScheduleDraft ─→ resolver ─→ preview ─→ confirmar ─→ escribir
voz/texto┘
```

Tres formas de entrar, un modelo y un escritor. Nada se guarda sin confirmación
explícita, y los conflictos se resuelven con `ConflictPolicy` —`SKIP_EXISTING`
por defecto, `REPLACE_EXISTING` solo si la persona lo pide—. Ver
[ADR 8](adr/0008-roster-and-calendar-model.md).

### Voz y texto

La voz produce texto en el navegador; Turnin recibe la transcripción y nada más.
**No se almacena audio.** El `ScheduleTextParser` es determinista, vive en
`Domain` y no llama a ningún servicio: entiende «1 y 2 mañana», «del 1 al 4
mañana», «1-4 mañana», «uno y dos mañana», los aliases de cada preset y las
rotaciones dictadas («mi patrón es mañana mañana tarde tarde…»). Lo que no
entiende lo devuelve como fragmento no reconocido; nunca lo adivina.

## `Swap` — solicitudes, propuestas y saldos

La disponibilidad descubre posibilidades; una propuesta explícita es la que
puede llegar a ejecutar un cambio.

```
Pedro   18 sep · Noche  →  «quiero quitarme este turno»
María   18 sep          →  «puedo trabajar»
Turnin  →  María ve el turno de Pedro.  Pedro ve que María podría hacerlo.
```

### La frontera es el pool, no el centro

Solo se ven solicitudes y disponibilidades de un `SwapPool` en el que se tiene
membership activa. Mismo hospital y distinto pool es invisible, y hay tests que
lo comprueban desde el dominio hasta el navegador.

`Swap` no recalcula esa frontera: la pregunta a Workforce por un puerto
(`SwapGroups`). `SwapPoolResolver` sigue siendo el único sitio donde se decide
qué hace comparables a dos personas.

### `SwapRequest`

Referencia el `RosterDay` por identidad; no copia el turno. Invariantes:

* el día es futuro y pertenece a una asignación activa del propio trabajador;
* ese día está en estado `WORKING` y tiene al menos un `ShiftSegment`;
* el pool es alcanzable desde esa asignación;
* solo una solicitud `OPEN` por asignación y día —constraint parcial en
  PostgreSQL, no solo comprobación de aplicación.

Retirar no borra la fila. Una solicitud resuelta deja de participar en
descubrimiento.

**Publicar no modifica el cuadrante.** El turno sigue siendo de quien lo publicó
hasta que se acepta una propuesta.

### `Availability`

«Puedo trabajar este día, en este grupo». Explícita siempre:

* un día `UNKNOWN` **sí** puede ofrecerse —la propia acción es el dato— y
  declararlo **no** crea un día libre en el calendario;
* un día `REST` puede ofrecerse;
* un día `WORKING` no: ya se trabaja.

Una fila por trabajador, pool y día, con índice único, de modo que ofrecerse dos
veces es una declaración y no dos. Retirar pone `active = false`.

### Candidatos

Quien publica ve quién se ha ofrecido ese día en ese pool, y nadie más lo ve.
Sin descansos legales, sin solapes, sin ranking: eso es el matcher, y el matcher
necesita antes los datos que esta fase produce. Ver
[ADR 10](adr/0010-swap-requests-and-availability.md).

### `SwapProposal` y `ExchangeBalance`

`EXCHANGE` referencia dos turnos reales; `COVERAGE`, solo el solicitado; y
`DEFERRED` puede guardar una `ReturnPreference`, nunca un turno ficticio. Al
aceptar el diferido se crea un saldo nominal con minutos derivados del horario.
Se puede reservar y consumir parcialmente; disponer de saldo nunca mueve por sí
solo el calendario. Ver [ADR 11](adr/0011-real-shift-proposals-balances-and-rest-opportunities.md).

### Oportunidades de descanso

`RestBlockOpportunityFinder` elimina virtualmente un único turno y cuenta solo
días `REST` confirmados a ambos lados. Ignora desconocidos, exige tres días
resultantes y conserva score y razones explicables.

Es **el único detector**. Lo usan tanto «Encuéntrame un puente»
(`FindRestBlockOpportunities`) como la pantalla en la que eliges qué turno pedir
a cambio (`GetSwapComposerCalendar`): esta última lo ejecuta una vez por
calendario sobre el rango ya cargado, y la plantilla no vuelve a llamarlo. Si
algún día cambia el umbral, cambia en los dos sitios a la vez.

## SwapPool: el concepto que hay que entender

**Dos personas del mismo hospital no pueden intercambiar turnos automáticamente.**
Una enfermera de UCI y un celador de urgencias comparten centro y no comparten
nada más. Modelar «todos los del hospital pueden cambiar entre sí» sería un error
que costaría una migración de datos y una reescritura del matcher.

```
Organization ─── Servicio Andaluz de Salud
     └── Workplace ─── Hospital Universitario Virgen de las Nieves
              └── SwapPool ─── UCI · Enfermería
              └── SwapPool ─── Urgencias · Celadores
```

El `SwapPool` es la frontera del matching: **el motor nunca propone un cambio
entre personas que no comparten pool**. Una persona puede pertenecer a varios.

`WorkerAssignment` y acceso a `SwapPool` no son intercambiables. El primero
significa «trabajo aquí» y genera una tarjeta en «Mis lugares de trabajo»; el
acceso principal y los accesos adicionales solo describen con qué grupos puede
cambiar dentro de ese empleo. Por tanto, una asignación con un destino principal
y dos compatibilidades sigue siendo un único lugar de trabajo, aunque alcance
tres pools.

Las reglas de compatibilidad dentro de un pool acabarán dependiendo de categoría
profesional, unidad, puesto, experiencia, especialidad, formación, horarios,
descansos, duración, reglas internas y aprobación de supervisión.

**No vamos a construir ese motor de reglas ahora.** Lo que sí hacemos es no
cerrarnos la puerta:

* la compatibilidad se preguntará al pool (`SwapPool::allows(Membership, Membership, Shift, Shift)`),
  no se calculará en el matcher;
* la respuesta será un resultado explicable —qué regla falló—, no un booleano,
  porque «no encontramos nada» sin motivo es una pésima experiencia;
* las reglas serán datos del pool, no clases, para que un centro pueda tener las
  suyas sin desplegar código.

### Personal volante

`OrganizationalUnit` no es sinónimo de servicio asistencial. También modela una
unidad de adscripción como `Equipo volante`, `Correturnos`, `Retén` o `Equipo de
apoyo`, con `kind = floating_team` y aliases locales. La unidad siempre pertenece
a un `Workplace`: que dos centros usen el literal «volantes» no los convierte en
la misma unidad.

El `WorkerAssignment` conserva esa unidad y el `SwapPoolResolver` la incluye en
la clave. Por tanto, `Hospital X + Enfermería + Equipo volante` forma un pool
distinto de `Hospital X + Enfermería + Urgencias`. La ubicación concreta de un
turno futuro (`ShiftAssignment`) será otro dato de Scheduling y nunca mutará la
adscripción ni el pool del trabajador.

## Agregados previstos

Un agregado es una frontera de consistencia: lo que tiene que ser cierto dentro de
una transacción. Lo demás se referencia por identidad.

### `SwapRequest` — el agregado central

La intención de un trabajador. Es raíz porque su ciclo de vida y sus estados son
la parte más delicada del producto.

* **Contiene**: quién la abre, en qué pool, qué quiere (librar un día, ceder un
  turno, coger turnos, un puente), restricciones, caducidad, y las propuestas
  generadas.
* **Referencia por id**: `Membership`, `Shift`.
* **Invariantes**:
  * una solicitud pertenece a exactamente un `SwapPool`;
  * no se puede aceptar una propuesta de una solicitud caducada o cancelada;
  * una solicitud solo puede tener un `SwapAgreement`;
  * las transiciones de estado son explícitas y unidireccionales salvo cancelación.

Estados previstos, como enum, nunca como string suelto:

```
draft → searching → proposed → partially_accepted → accepted
                              → awaiting_approval → approved
       ↘ rejected   ↘ expired   ↘ cancelled
```

No se implementarán todos de golpe. Los primeros serán `searching`, `proposed`,
`accepted` y `cancelled`; el resto llega con su caso de uso.

### `Shift`

Un turno asignado a una persona. Vive dentro de `Scheduling` y es referenciado
por identidad desde `Swap`.

* **Invariantes**: una persona no tiene dos turnos solapados en el mismo pool; un
  turno pertenece a un `WorkDate` y a un `ShiftKind`.

### `Membership`

La pertenencia de una persona a un pool. Es el nexo entre `Identity` y todo lo
demás, y donde viven categoría profesional y unidad.

* **Invariante**: una persona tiene como mucho una pertenencia activa por pool.

### `ShiftDebt`

«A le debe un turno a B», nacido de un `SwapAgreement` sin contrapartida.

* **Invariantes**: acreedor y deudor son distintos; ambos comparten pool; una
  deuda saldada no se reabre.
* **Explícitamente ausente**: cualquier importe. Ver
  [PRODUCT.md](PRODUCT.md#favores-pendientes).

### `Availability`

Cuándo alguien quiere trabajar. Con grados —`quiero`, `podría`, `no`—, no un
booleano: la diferencia entre «podría» y «quiero» es justo lo que ordena los
resultados del matcher.

## Value objects

Los que ya tienen una regla que proteger. Un VO que solo envuelve un string sin
validar nada es ruido.

* `WorkDate` — el día laboral. Un turno de noche del sábado que termina el domingo
  a las 08:00 **es un turno del sábado**. Confundir esto con un timestamp rompe
  cuadrantes enteros. Implementado en `Scheduling\Domain` con aritmética entera
  sobre días julianos: ninguna zona ni cambio horario puede mover una fecha.
* `RosterMonth` — el mes que carga y navega la pantalla del calendario.
* `ShiftKind` — enum: mañana, tarde, noche, 12 h, guardia…
* `LocalTime` — hora de pared, sin zona. `'22:00'` no es un instante.
* `ShiftDuration` — cómo se escribe una duración de turno (`7 h`, `7 h 30 min`).
  Existe porque la misma regla escribe dos números: lo que dura un turno y la
  diferencia entre dos. Una guardia de 08:00 a 08:00 dura 24 h, nunca cero.
* `ShiftBalance` — la diferencia de minutos entre el turno que coges y el que
  ofreces, desde el lado de quien propone. Sabe decirla (`+17 h`) y explicarla
  («Trabajarías 17 h más»).
* `ShiftWindow` — inicio y fin de un segmento. **Fin > inicio no se cumple**: un
  turno de noche va de 22:00 a 08:00, y `endsNextDay()` se deriva de ahí en lugar
  de almacenarse.
* `<Aggregate>Id` — UUID v7 validado.
* `ProfessionalCategory` — enfermería, TCAE, celador…

Ya implementados (en `Platform\System`): `ComponentName`, `ComponentHealth`,
`HealthReport`, y los enums `HealthStatus` y `ComponentCriticality`.

## Tiempo

Turnin es un producto sobre el tiempo. Estas reglas no son negociables.

1. **Día laboral ≠ timestamp.** `WorkDate` es una fecha sin hora ni zona; los
   instantes técnicos (creado, aceptado, caducado) son `DateTimeImmutable` en UTC.
   Mezclarlos es el error que produce turnos de noche imputados al día siguiente.

2. **Zona horaria explícita, siempre.** En base de datos, `TIMESTAMPTZ`: es el
   único tipo de PostgreSQL que guarda un instante sin ambigüedad. Configurado en
   [`config/packages/doctrine.yaml`](../config/packages/doctrine.yaml).

3. **`Europe/Madrid` no se escribe en el dominio.** España es el primer mercado,
   no el único. La zona es un dato del `Workplace` (o del usuario), y el dominio
   la recibe; no la asume.

4. **Nada de strings sueltas para horarios.** `'22:00-08:00'` no es un horario, es
   un problema de parsing esperando a ocurrir.

5. **El instante actual entra por un puerto.** `Psr\Clock\ClockInterface`. `Domain`
   y `Application` **nunca** llaman a `new DateTimeImmutable()`, `time()`, `date()`
   ni `strtotime()`. Lo comprueba `tests/Architecture/DeterministicTimeTest`, no
   la buena voluntad de quien revisa.

   El motivo es concreto: «¿este cambio deja un descanso legal entre turnos?»
   tiene que poder responderse para cualquier instante, no solo para ahora mismo.
   Una regla que lee el reloj del sistema no se puede testear.

## Concurrencia

Dos personas van a intentar quedarse con la misma oportunidad. Es cuestión de
tiempo, y de que el producto funcione.

Todavía no hay nada que proteger, así que no hay nada implementado. Cuando lo
haya, el orden de preferencia es:

1. **Restricciones de base de datos.** Un índice único sobre «un acuerdo por
   solicitud» es la única garantía que sobrevive a un despliegue con dos
   contenedores, a un reintento y a un bug de aplicación.
2. **Bloqueo optimista** (`version`) en los agregados con contención real.
3. **Idempotencia** en los comandos que puedan reintentarse: aceptar dos veces la
   misma propuesta tiene que ser inofensivo.
4. **Transacciones** por comando, ya activadas vía `doctrine_transaction` en el
   command bus.

> La base de datos es la última garantía de las invariantes críticas. El código de
> aplicación es la primera, no la definitiva.

## Eventos de dominio previstos

Se publican cuando ocurre algo que el negocio reconoce, no en cada `save()`.

| Evento | Quién escucha (previsto) |
| --- | --- |
| `SwapRequestOpened` | Matching |
| `SwapProposalCreated` | Notification |
| `SwapProposalAccepted` | Swap (para cerrar el acuerdo), Notification |
| `SwapAgreementSettled` | Scheduling (aplicar el cambio al calendario vía `ScheduleDraft` con `RosterSource::SWAP`), ShiftDebt |
| `ShiftDebtIncurred` / `ShiftDebtSettled` | Notification |
| `AvailabilityChanged` | Matching |

Es la vía por la que los contextos se comunican sin conocerse
(→ [CONTEXT_MAP.md](CONTEXT_MAP.md)).

## Identity y capacidades

`Platform/Identity` modela `User`, `Email`, `UserId`, el hash de contraseña,
`PersonalProfile` y `UsageIdentity`.
La identidad no contiene centro, categoría ni destino: esas decisiones viven en
`Workforce\WorkerAssignment`. Las capacidades `worker` y `supervisor` son
independientes para permitir una misma cuenta con ambos perfiles. El supervisor
solo recibe acceso por una relación de dominio o invitación; nunca por un botón
público de autoasignación.

`ExternalIdentity` es una credencial de esa misma cuenta. Conserva proveedor,
subject estable, email observado al vincular y fecha; nunca tokens OAuth. El
subject resuelve al usuario antes de considerar el email. Un email verificado
solo permite enlazar durante el primer acceso.

El nombre se puede corregir desde el onboarding. DNI/NIE y teléfono se
normalizan antes de comparar: solo se guardan huellas HMAC para detectar su uso
en otra cuenta, y el teléfono recuperable se cifra con XChaCha20-Poly1305. El
DNI/NIE no se persiste de forma reversible. Una vez asociados, esos
identificadores no se sustituyen silenciosamente desde la interfaz.

`WorkerOnboardingDraft` conserva el progreso laboral entre sesiones. Al
completarlo se crea o sustituye la asignación primaria, se materializan los
accesos principal y adicionales a pools y se elimina el borrador. Repetir el
flujo permite actualizar la asignación sin dejar dos asignaciones o memberships
primarias activas.
# Calendarios y turnos exactos

Una persona puede tener `0..N WorkerAssignment` activos y, entre ellos, como
máximo uno principal. Cada assignment posee su calendario, sus `ShiftPreset`,
sus patrones y sus pools; `REST` es relativo al assignment.

`ShiftPreset` es una plantilla visual/de entrada. `ShiftSegment` es el snapshot
histórico (incluido `colorKeySnapshot`) y `ShiftInterval` materializa
`WorkDate + ShiftWindow + timezone` en instantes. `ShiftKind`, abreviatura,
nombre y color nunca determinan compatibilidad. La vista «Todos» deriva
`GLOBAL_FREE`, `WORKING`, `PARTIALLY_KNOWN`, `UNKNOWN` u `OVERLAPPING` sin crear
filas combinadas.
