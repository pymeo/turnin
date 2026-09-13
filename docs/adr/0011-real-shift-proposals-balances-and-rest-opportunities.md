# 11. Propuestas sobre turnos reales, saldos y oportunidades de descanso

* **Estado**: aceptada
* **Fecha**: 2026-09-13

## Contexto

`Availability` se estaba usando a la vez como señal de descubrimiento y como
oferta. Además, el primer cierre de cobertura permitía al autor escoger a una
persona disponible y mover el cuadrante sin una propuesta de esa persona. Eso
no podía expresar un intercambio 12 h ↔ 8 h, una cobertura sin retorno ni «ya
me lo devolverás».

## Decisión

`SwapRequest` sigue siendo la intención de librarse de un turno real.
`Availability` solo alimenta descubrimiento. Una acción entre profesionales
nace siempre como `SwapProposal`: `EXCHANGE` referencia además el turno real de
retorno; `COVERAGE` prohíbe ese turno; `DEFERRED` guarda como mucho una
`ReturnPreference`, que nunca es un turno.

Al aceptar una propuesta diferida, la duración se deriva de las horas reales y
crea un `ExchangeBalance` entre dos profesionales. `remainingMinutes` y
`availableMinutes` se derivan. Reservar antes de confirmar y bloquear la fila al
leerla evita reutilizar simultáneamente los mismos minutos. La interfaz lo llama
«saldo de intercambio», nunca deuda ni moneda; no hay precio ni transferencia.

`RestBlockOpportunityFinder` es determinista y opera sobre la proyección del
cuadrante que `Scheduling` entrega a `Swap`. Solo `REST` confirmado cuenta como
descanso; ausencia de dato sigue siendo desconocido. Una oportunidad necesita
al menos tres días resultantes. El score suma longitud, días ganados, unión de
dos bloques y presencia de candidatos explícitamente disponibles. Las razones
se conservan para poder explicar cada recomendación.

## Alternativas descartadas

* Convertir disponibilidad o preferencias en turnos: inventaría cuadrante.
* Un contexto nuevo de IA/matching: aún no hay reglas suficientes; el servicio
  puro y los puertos existentes mantienen el corte más pequeño.
* Consumir saldo automáticamente: un saldo solo permite solicitar; ambas partes
  y, cuando proceda, el responsable siguen decidiendo.
* Asumir fines de semana libres: no representa trabajo a turnos.

## Consecuencias

La aprobación por responsable continúa siendo un paso separado. La capacidad
de supervisor existe, pero su asignación organizativa todavía no está modelada;
por eso no se atribuye aprobación a cualquier usuario con ese rol.
