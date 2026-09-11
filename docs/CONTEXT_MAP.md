# Mapa de contextos

## Qué existe hoy

```
Platform
├── System     salud del sistema, correlación de peticiones     [IMPLEMENTADO]
├── Web        shell web público (landing, PWA)                 [IMPLEMENTADO]
└── Identity   cuenta, credenciales, sesión y perfil personal   [IMPLEMENTADO]

Workforce
└── Workplace + Workforce assignment + SwapPool                 [IMPLEMENTADO]

Scheduling
└── RosterDay + ShiftPreset + RosterPattern                     [IMPLEMENTADO]
```

Swap, Matching, Notification, Billing y Coverage siguen siendo el destino, no el
presente. De `Scheduling` existe el calendario personal; `Availability` todavía
no.

**Un contexto se crea cuando se implementa.** Crear veinte directorios vacíos con
sus tres capas cada uno no es diseño, es ruido: nadie sabe cuáles están vivos, el
grafo de Graft se llena de nodos huecos y la primera funcionalidad real acaba
colocándose en el sitio equivocado porque «ya había una carpeta».

## Destino previsto

```
Platform
└── Identity            personas, credenciales, sesión

Workforce
├── Organization + Workplace + StaffCategory + Specialty
├── OrganizationalUnit + Employer + WorkerAssignment
└── SwapPool + Membership

Scheduling
├── Calendar + Shift + Availability

Swap
├── SwapRequest + SwapPreference + SwapProposal + SwapAgreement + ShiftDebt

Matching
├── DirectMatching + CycleMatching

Notification

Billing                 (futuro)
Coverage                (futuro, B2B)
```

## Por qué esta agrupación y no la lista larga

El planteamiento inicial tenía un contexto por concepto. Al analizarlos, varios
resultan ser **agregados del mismo contexto**, no contextos distintos: comparten
lenguaje, cambian a la vez y sus invariantes se cruzan. Separarlos crearía
fronteras que habría que atravesar en cada operación —el síntoma clásico de un
contexto mal cortado.

### `Workforce` (antes: Organization, Workplace, SwapPool, Membership)

Los cuatro responden a la misma pregunta: **quién trabaja dónde y con quién puede
cambiar**. Un `SwapPool` no significa nada sin su `Workplace`, y una `Membership`
no significa nada sin su pool. Sus invariantes se cruzan constantemente («esta
persona pertenece a un pool de este centro»), y comprobarlas a través de una
frontera de contexto sería una llamada remota para responder algo trivial.

Son un contexto con cuatro agregados. `SwapPool` es la raíz interesante.

### `Scheduling` (antes: Calendar, Shift, Availability)

`Calendar` no es un agregado: es la *vista* de los turnos de una persona en un
intervalo. Modelarlo como agregado propio lleva derecho a un objeto gigante que
carga meses de datos para responder «¿qué hago el sábado?».

`Shift` y `Availability` sí son agregados, y comparten contexto porque
`Availability` solo tiene sentido contra el calendario: «quiero mañanas» es una
afirmación sobre los turnos que uno aceptaría.

### `Swap` (antes: SwapRequest, SwapPreference, SwapProposal, SwapAgreement, ShiftDebt)

Aquí la separación habría sido peor. `SwapProposal` y `SwapAgreement` viven y
mueren con su `SwapRequest`: son parte del mismo agregado o vecinos inmediatos, y
aceptar una propuesta tiene que ser atómico respecto a la solicitud. Partirlos
significa coordinación distribuida para una operación que es un `UPDATE`.

`ShiftDebt` sí es agregado aparte —sobrevive al acuerdo que lo creó y tiene su
propio ciclo de vida— pero comparte el lenguaje de `Swap` y se queda en el mismo
contexto.

`SwapPreference` no es un agregado, es un value object dentro de `SwapRequest`.

### `Matching` separado de `Swap`

Este corte sí paga. `Swap` guarda *qué quiere la gente*; `Matching` calcula *qué
es posible*. Son responsabilidades distintas con perfiles operativos distintos:
`Swap` es transaccional y rápido; `Matching` es cómputo, será lo primero que se
haga asíncrono y lo primero que se optimice o se reescriba. Que dependa de `Swap`
y no al revés es lo que permite cambiarlo sin tocar el modelo.

`DirectMatching` y `CycleMatching` son dos estrategias, no dos contextos.

### `Notification` propio

Único contexto que habla con el exterior (push, email). Su fallo no puede tumbar
un cambio de turno, así que se acopla solo por eventos.

### `Platform\Identity` separado de `Workforce`

Quién eres (credenciales, sesión) es distinto de qué eres en el trabajo
(categoría, unidad, pool). Se separan porque cambian por motivos distintos y
porque los datos de `Identity` tienen un régimen de privacidad más estricto.

## Relaciones

```
                    ┌──────────────┐
                    │   Identity   │
                    └──────┬───────┘
                           │ userId
                    ┌──────▼───────┐
                    │  Workforce   │  ← SwapPool: la frontera del matching
                    └──────┬───────┘
                  membershipId │
              ┌────────────┴────────────┐
      ┌───────▼────────┐        ┌───────▼────────┐
      │   Scheduling   │        │      Swap      │
      └───────┬────────┘        └───┬────────┬───┘
              │  shiftId             │        │ eventos
              └──────────┬───────────┘        │
                  ┌──────▼───────┐    ┌───────▼────────┐
                  │   Matching   │    │  Notification  │
                  └──────────────┘    └────────────────┘
```

| Relación | Patrón | Por qué |
| --- | --- | --- |
| Identity → Workforce | *Shared kernel* mínimo: solo el `UserId` | Workforce no necesita saber nada más de una persona |
| Identity → Scheduling | Puerto `AuthenticatedWorkers`, declarado por Scheduling | Quién ha iniciado sesión lo sabe Identity; Scheduling solo necesita el id |
| Workforce → Scheduling | Puerto `AssignedWorkers`, declarado por Scheduling | El calendario cuelga de la asignación y de su zona horaria, que son datos de Workforce |
| Workforce → Scheduling / Swap | *Customer–supplier* | Ambos preguntan a `SwapPool` si un cambio es admisible |
| Swap → Matching | *Customer–supplier*, invocación explícita | `Swap` pide candidatos; `Matching` no conoce a `Swap` |
| Swap → Notification | *Publisher–subscriber* (eventos) | Notificar no puede bloquear ni fallar un acuerdo |
| Scheduling ← Swap | *Publisher–subscriber* (eventos) | Un acuerdo cerrado aplica el cambio al calendario |
| Coverage → Workforce, Scheduling | *Conformist* (futuro) | El producto B2B consume el modelo existente sin alterarlo |

Ninguna de estas flechas es una llamada a una clase de otro contexto: son eventos
de dominio o puertos declarados en el contexto que consume. Lo comprueba
[`deptrac.contexts.yaml`](../deptrac.contexts.yaml).

## Centros sanitarios: importación implementada

## Identity y entrada autenticada

`Platform/Identity` contiene la identidad y autenticación, no datos laborales.
`User` puede tener capacidad de trabajador y/o supervisor sin convertirlas en
cuentas distintas. Registro y login usan sesión Symfony y el destino inicial se
resuelve hacia Workforce cuando falta la asignación. `/app` y `/supervisor` son
entradas separadas; el primero es el contexto por defecto para quien tiene ambos.

Google OAuth es un adapter de Infrastructure de Identity. KnpU/League no cruzan
hacia Application o Domain; ambos reciben únicamente proveedor, subject, email y
la confirmación de verificación.

El onboarding laboral consume nombre y evidencia identificativa a través del
puerto `WorkerOnboardingIdentity`, declarado por Workforce e implementado por un
adapter de Identity. Así el controlador de Workforce no conoce comandos, vistas
ni tipos de seguridad internos de otro contexto.

El catálogo público español entra por un puerto en `Workforce\Domain`:

```php
interface WorkplaceCatalogSource
{
    public function fetch(): CompleteWorkplaceCatalog;
}
```

Infrastructure configura un adapter por catálogo del Ministerio: Atención
Primaria, Urgencia Extrahospitalaria y Hospitales. Prueba primero la descarga CSV
oficial y admite el XLSX anual oficial como fallback, porque en septiembre de
2026 dos exports CSV responden vacíos. **El dominio no conoce CSV, XLSX,
endpoints ni al Ministerio**: recibe una foto completa de `ImportedWorkplace`.

REGCESS completo queda como fuente futura para sanidad privada. No participa en
esta slice.

## Scheduling: el calendario personal implementado

`Scheduling` contiene el cuadrante de una persona. Sus agregados son `RosterDay`
—un día de una asignación, con sus `ShiftSegment`—, `ShiftPreset` —los botones
rápidos del trabajador— y `RosterPattern` —una rotación repetible—. `Availability`
sigue siendo trabajo posterior.

No toca ninguna clase de otro contexto. Declara dos puertos y otros los
implementan, que es la dirección *customer–supplier* de esta tabla:

```php
// App\Scheduling\Domain
interface AssignedWorkers        // lo implementa Workforce
{
    public function primaryFor(string $workerId): ?AssignedWorker;
}

interface AuthenticatedWorkers   // lo implementa Identity
{
    public function idForEmail(string $email): ?string;
}
```

`AssignedWorker` lleva la zona horaria del centro, resuelta por Workforce desde
la comunidad autónoma: `Atlantic/Canary` o `Europe/Madrid`. Scheduling nunca
escribe `Europe/Madrid`.

Cuando exista intercambio aprobado, aplicarlo al calendario será construir un
`ScheduleDraft` con `RosterSource::SWAP` y pasarlo por el escritor que ya existe.

### Asignación laboral y personal volante

La base de Workforce aporta categorías con aliases y unidades por centro. Las
unidades distinguen `fixed_service` de `floating_team`; esta última cubre
`Equipo volante`, `Correturnos`, `Retén` y equivalentes locales. No se modela
como `service = null`: su ID forma parte de la clave de `SwapPool`, por lo que
los volantes no se mezclan automáticamente con la plantilla fija de la unidad
que cubran temporalmente. La ubicación puntual del turno quedará en Scheduling.
# Calendario multi-asignación e integraciones

Scheduling consume la proyección mínima de Workforce mediante `AssignedWorkers`
(`primaryFor`, `activeFor`, `byIdFor`). Workforce implementa el puerto y conserva
la propiedad de `WorkerAssignment`, workplace, timezone y SwapPool. Scheduling
no lee sus tablas ni modifica sus entidades.

Los proveedores de calendario son puertos de Scheduling Application.
`GoogleCalendarProvider` es un adaptador Infrastructure; los DTO normalizados
impiden que tipos del SDK/API entren en Domain.
