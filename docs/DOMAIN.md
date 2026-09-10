# Dominio

Este documento es el vocabulario compartido. Si un nombre no está aquí, no
debería aparecer en el código; si aparece en el código y no está aquí, falta
actualizarlo.

> **Estado**: `Platform\System` y el catálogo `Workforce.Workplace` están
> implementados. El resto continúa siendo diseño previsto. Está escrito antes de
> implementarlo a propósito: el orden de las decisiones importa más que el orden
> del código.

## Vocabulario

| Término | Significado en Turnin |
| --- | --- |
| **Organization** | La entidad empleadora. «Servicio Andaluz de Salud». |
| **Workplace** | El centro físico. «Hospital Universitario Virgen de las Nieves». |
| **SwapPool** | El conjunto dentro del cual ciertas personas *pueden* intercambiar. «UCI · Enfermería». |
| **Membership** | La pertenencia de una persona a un pool, con su categoría y su unidad. |
| **Shift** | Un turno concreto: quién, dónde, qué día laboral, qué franja. |
| **ShiftKind** | Mañana, tarde, noche… La franja, no las horas exactas. |
| **WorkDate** | El *día laboral* al que se imputa un turno. No es un timestamp. |
| **Availability** | Cuándo alguien quiere o puede trabajar, con grados. |
| **SwapRequest** | La intención de alguien: «quiero librar el sábado 19». |
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
  cuadrantes enteros.
* `ShiftKind` — enum: mañana, tarde, noche, guardia…
* `TimeRange` — inicio y fin, con la invariante de que fin > inicio.
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
| `SwapAgreementSettled` | Scheduling (aplicar el cambio al calendario), ShiftDebt |
| `ShiftDebtIncurred` / `ShiftDebtSettled` | Notification |
| `AvailabilityChanged` | Matching |

Es la vía por la que los contextos se comunican sin conocerse
(→ [CONTEXT_MAP.md](CONTEXT_MAP.md)).
