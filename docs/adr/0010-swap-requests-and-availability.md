# 10. Publicar un turno y declararse disponible

* **Estado**: aceptada
* **Fecha**: 2026-09-12

## Contexto

Turnin ya sabe quién trabaja dónde (`Workforce`) y qué trabaja cada día
(`Scheduling`). Falta lo que da sentido a las dos: qué quiere hacer alguien con
un turno suyo, y cuándo podría cubrir el de otro.

El destino es un motor que propone cambios. Pero un motor necesita datos que
todavía no existen, y construirlo antes de tenerlos significa inventarse las
reglas. Antes hace falta la red: gente publicando turnos y gente ofreciéndose.

## Decisión

### Un contexto nuevo, `Swap`

```
Workforce    →  dónde trabajo y con quién soy compatible
Scheduling   →  qué trabajo cada día
Swap         →  qué quiero hacer con mis turnos y cuándo puedo trabajar
```

`Swap` no lee ninguna tabla ajena. Declara tres puertos y los implementa quien
posee el dato: `SwapGroups` en Workforce, `RosteredDays` en Scheduling,
`AuthenticatedWorkers` y `WorkerDisplayNames` en Identity. Por eso es el
contexto que más va a cambiar en las siguientes fases y el que menos arrastra.

### La frontera es el `SwapPool`, nunca el centro

Una persona solo ve solicitudes y disponibilidades de pools en los que tiene una
membership activa. Una enfermera de UCI y un celador de urgencias comparten
edificio y no comparten nada más.

El algoritmo que decide eso ya existe: `SwapPoolResolver` materializa la clave
—centro, categoría, especialidad, destino, área funcional, empleador— como un
pool. `Swap` **lee esa decisión, no la repite**. Duplicar la regla aquí sería
garantizar que las dos versiones divergen.

Ningún `swapPoolId` que llega del navegador se usa sin volver a resolverlo
contra la sesión en `SwapWorkspace`.

### Una sola intención, y dos estados

`SwapRequest` significa exactamente una cosa:

> Tengo este turno y busco a alguien compatible que pueda hacerlo.

No hay `GIVE_AWAY` / `SWAP_REQUIRED` / `TRADE` / `DEBT`. Las tres son formas
distintas de **acuerdo**, y los acuerdos dependen de un flujo de propuestas que
no existe. Partir la intención antes de tener los desenlaces es adivinar tres
juegos de reglas con cero ejemplos.

Estados: `OPEN` y `CANCELLED`. **No hay `CLOSED`**: nada puede cerrar una
solicitud todavía, y un estado inalcanzable es peor que uno ausente porque
invita a escribir código para una situación imposible y disimula que el flujo
está a medias. Retirar no borra la fila: cuánta gente se arrepiente es la
primera pregunta que se le hará a esta red.

### La disponibilidad es una declaración, jamás una deducción

```
no existe RosterDay  →  no lo sé          ≠  está libre
```

Leer una celda vacía como disponibilidad pondría el descanso de alguien delante
de desconocidos. Por eso `Availability` vive en `Swap` y no se deriva del
calendario; y por eso declararse disponible **no escribe en Scheduling**: decir
«podría trabajar el 21» y «el 21 libro» no son la misma frase.

Sin grados todavía. `active` cubre la retirada; «quiero» frente a «podría» será
una columna con default cuando exista un matcher capaz de usar la diferencia.

Una fila por trabajador, pool y día, con índice único: pulsar dos veces en un
móvil es una declaración, no dos, y eso se garantiza en la base de datos y no
solo deshabilitando un botón.

### Publicar no mueve nada

Un turno publicado sigue siendo de quien lo publicó. Aunque alguien pulse
«Puedo hacerlo», el `RosterDay` no se copia, ni se borra, ni se reasigna: eso
requiere un `SwapAgreement`, que es la fase siguiente.

«Puedo hacerlo» crea la `Availability` correspondiente a ese pool y ese día, y
nada más. Es deliberadamente lo mismo que declararse disponible desde el
calendario: una sola forma de decir «puedo».

### Candidatos: la regla más tonta posible

```
Availability.date == SwapRequest.date
AND Availability.swapPoolId == SwapRequest.swapPoolId
AND Availability.active
AND Availability.workerId != request.workerId
```

Sin descansos legales, sin solapes, sin duración equivalente, sin ranking. Todo
eso pertenece a un matcher que pueda explicar por qué descarta a alguien; una
lista ordenada por una regla que nadie ha escrito sería un ranking disfrazado de
hecho.

Solo el autor ve quién se ha ofrecido. Un compañero ve el turno; la cola de
interesados es privada.

## Consecuencias

* Una solicitud referencia el `RosterDay` por identidad. Si el autor borra o
  cambia ese día después de publicar, la solicitud queda obsoleta y **deja de
  mostrarse** en lugar de mostrarse mal. No hay clave foránea hacia Scheduling:
  un `ON DELETE CASCADE` borraría en silencio una solicitud abierta.
* `Swap` tiene su propio `WorkDate`. Es el mismo concepto que el de Scheduling y
  deliberadamente no la misma clase: los contextos se integran por puertos.
* Cuando exista `SwapProposal`, el camino ya está: una propuesta nace de una
  `SwapRequest` y una `Availability` que ya se conocen.

## Alternativas descartadas

**Emparejar por centro.** Pondría a un celador a cubrir una noche de UCI. El
`SwapPool` existe precisamente para que eso no pueda ocurrir.

**Derivar la disponibilidad del calendario.** Convierte «no lo he rellenado» en
«estoy libre», que es el peor fallo que puede cometer este producto.

**Implementar ya propuesta y aceptación.** Sin datos reales de una red en uso,
las reglas de aceptación —quién confirma, en qué orden, qué pasa si dos aceptan
a la vez, si hace falta visto bueno— se diseñarían a ciegas. Esta fase produce
justo esos datos.

**Un tablón de anuncios como producto.** Esta pantalla es un andamio para
validar la red, no el destino. El destino es que Turnin proponga.
