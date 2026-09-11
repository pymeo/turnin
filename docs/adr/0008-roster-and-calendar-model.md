# 8. El calendario personal: RosterDay, snapshots y un único escritor

* **Estado**: aceptada
* **Fecha**: 2026-09-11

## Contexto

Turnin no puede proponer un cambio de turno sin saber qué trabaja la gente. El
calendario es, por tanto, el primer dato del que depende todo lo demás: matching,
disponibilidad, puentes, rachas libres. Y es también el punto donde se pierde a
un usuario: si meter el cuadrante cuesta diez minutos, no lo mete.

De ahí salen tres exigencias que tiran en direcciones distintas:

1. **Entrar rápido.** Tres formas de introducir un mes: pintarlo, repetir un
   patrón, o dictarlo. «Me han dado el cuadrante» tiene que resolverse en menos
   de un minuto.
2. **No mentir.** Un día del que no sabemos nada no es un día libre. Ofrecer el
   descanso de alguien porque una celda estaba vacía sería el peor fallo posible
   de este producto.
3. **No reescribir el pasado.** Los horarios cambian. Que alguien corrija su
   turno de mañana en octubre no puede alterar lo que hizo en marzo.

## Decisión

### `RosterDay` es el agregado, y su ausencia significa UNKNOWN

Un día existe como fila solo cuando el trabajador ha dicho algo sobre él. Los
estados son `REST` y `WORKING`; **no hay un tercer estado `UNKNOWN`**, porque no
saber no es un dato del día, es la falta del día.

La alternativa —una tabla prerrellenada con días «desconocidos»— obliga a crear
365 filas por persona y año para no decir nada, y hace que la diferencia entre
«libro» y «no lo he rellenado» dependa de un `VARCHAR`. La otra alternativa
—leer una celda vacía como «libre»— es el fallo que describe el punto 2.

Constraint `UNIQUE (worker_assignment_id, work_date)` en PostgreSQL: la base de
datos es la última garantía, no la primera.

### Los segmentos guardan una copia, no una referencia

`ShiftSegment` copia del `ShiftPreset` la etiqueta, la abreviatura y las horas en
el momento de aplicarlo. El preset es una plantilla; el calendario histórico es
un registro. Cambiar «Mañana» de 08:00–15:00 a 07:30–14:30 afecta al siguiente
toque y a nada que ya haya ocurrido.

Un día puede tener varios segmentos desde el principio. La UX v1 usa uno, pero el
turno partido y el «turno + guardia» son corrientes en sanidad, y añadir el
segundo segmento después significa migrar cuadrantes vivos.

### Las horas son hora de pared, no instantes

`starts_at` y `ends_at` son columnas `TIME`, no `TIMESTAMPTZ`. Un turno etiquetado
22:00 empieza a las 22:00 del reloj de la pared, en marzo y en octubre. Atarlo a
un instante es lo que hace que el fin de semana del cambio horario muestre la
noche en el día equivocado.

«¿Termina al día siguiente?» **se deriva**, no se almacena: un fin igual o
anterior al inicio solo puede significar el día siguiente, y 08:00→08:00 es la
guardia de 24 horas. Guardar además el flag permitiría que los dos se
contradijeran, y una fila contradictoria no tiene interpretación correcta.

La zona horaria viene del centro (`Europe/Madrid` o `Atlantic/Canary`, resuelta
en Workforce a partir de la comunidad autónoma) y solo se usa para responder
«¿qué día es hoy aquí?» y «¿cuál es mi próximo turno?».

### Todo converge en `ScheduleDraft`

```
pintar   ┐
patrón   ├─→ ScheduleDraft ─→ resolver conflictos ─→ preview ─→ confirmar ─→ escribir
voz/texto┘
```

Un único modelo y un único escritor (`ApplyScheduleDraftHandler`). Tres caminos
de persistencia distintos serían tres sitios donde equivocarse con los conflictos,
la propiedad de los turnos y las transacciones.

El borrador **no se persiste**: vive en el navegador mientras se pinta y en la
petición cuando se confirma. No hay nada que recuperar entre sesiones que
justifique una tabla más.

### Los conflictos se resuelven con una política explícita

`ConflictPolicy` es un enum con `SKIP_EXISTING` (por defecto) y
`REPLACE_EXISTING`. Un booleano en la llamada no se lee, y la diferencia entre
los dos valores es un mes de cuadrante introducido a mano.

### El parser de voz es determinista y local

La voz produce texto en el navegador (`SpeechRecognition`); Turnin solo recibe la
transcripción. El texto lo interpreta un parser propio en `Domain`, sin modelo,
sin red y sin servicio externo. Tres motivos, en orden: el cuadrante es el dato
más sensible que nos dan y no sale de aquí; un parser que se equivoca siempre
igual se arregla con un test, uno que se equivoca distinto cada vez no; y dictar
un cuadrante tiene que funcionar en un pasillo con mala cobertura.

Lo que no entiende lo devuelve como fragmento no reconocido. Nunca adivina.

### La persistencia usa DBAL, no el ORM

`RosterDay` posee una colección de segmentos. Mapearla como asociación de
Doctrine obligaría a poner un `Doctrine\Common\Collections\Collection` sobre una
clase de `Domain`, que es justo la dependencia que
[`deptrac.yaml`](../../deptrac.yaml) existe para impedir. Se sigue el precedente
de `DoctrineWorkerOnboardingDrafts`, que tiene el mismo problema y lo resuelve
igual.

El beneficio secundario es el que importa en la práctica: aplicar un patrón a un
trimestre son cuatro sentencias, sea cual sea el rango, en lugar de cien grafos
de entidades.

## Consecuencias

* El calendario cuelga del `WorkerAssignment`. Si alguien rehace el onboarding,
  Workforce crea una asignación nueva y el calendario anterior deja de mostrarse
  —los datos siguen ahí—. Está anotado en [ROADMAP.md](../ROADMAP.md) como deuda
  con su arreglo previsto.
* `Scheduling` declara dos puertos que implementan otros contextos
  (`AssignedWorkers` en Workforce, `AuthenticatedWorkers` en Identity). Es la
  dirección *customer–supplier* que describe
  [CONTEXT_MAP.md](../CONTEXT_MAP.md): el que consume declara, el dueño del dato
  implementa.
* Cuando exista intercambio aprobado, aplicarlo al calendario es construir un
  `ScheduleDraft` con `RosterSource::SWAP` y pasarlo por el mismo escritor. No
  hace falta nada nuevo.

## Alternativas descartadas

**`user.calendar = JSON`.** Imposible consultar «¿quién libra el sábado 19 en
este pool?» sin leer el calendario entero de todo el mundo, que es exactamente la
consulta sobre la que se construye el matching.

**`shift(date, type)` sin estados.** No distingue libre de desconocido, que es la
distinción de la que depende no ofrecer el descanso de alguien.

**FullCalendar u otra librería.** La cuadrícula que necesitamos son 42 celdas con
una letra cada una y un gesto de pintado; una librería de calendarios trae una
agenda por horas que habría que desactivar casi entera, y pesa más que toda la
funcionalidad.

**Guardar cada toque.** Pintar diez días serían diez peticiones, imposibilitaría
«mantener lo que ya tengo» —cada petición solo ve su día— y dejaría medio mes
aplicado si se cae la conexión.
