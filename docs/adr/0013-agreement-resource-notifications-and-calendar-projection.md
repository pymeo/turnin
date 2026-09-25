# ADR 13 — Recurso de acuerdo, notificaciones y proyección del calendario

Fecha: 2026-09-24

## Contexto

Una propuesta aceptada modificaba el cuadrante, pero después dejaba de existir
como concepto visible. `RosterSource::SWAP` solo permitía pintar `↔`; no podía
explicar quién hacía cada turno, enlazar al acuerdo ni distinguir pendiente de
aprobado. Tampoco había una vía durable para avisar ni una consulta mínima para
un responsable externo.

## Decisión

La fuente de verdad sigue siendo `SwapProposal + SwapRequest`. Al alcanzar un
acuerdo se captura una `SwapAgreementSnapshot` inmutable con las piernas y el
contexto que deben poder explicarse aunque cambie después un preset. La ficha
privada se identifica por la propuesta y solo la ven sus participantes.

Cada instantánea recibe un único token público de 256 bits. Se persiste el hash
para buscar y el token cifrado para devolver siempre el mismo enlace. La página
pública es GET-only, mínima, no navegable hacia datos privados y obtiene el
estado actual de la propuesta.

`Notification` es un bounded context independiente. Consume hechos de Swap
después del commit, persiste `UserNotification` de forma idempotente y luego
intenta Web Push para todas las suscripciones. El fallo de cualquier canal no
participa en el éxito del acuerdo.

Scheduling declara `RosterSwapTraces`; Swap implementa ese puerto con una
consulta en lote. `RosterShiftSegmentView` transporta rol, compañero, acuerdo y
estado sin contaminar `ShiftSegment` con presentación y sin analizar strings en
Twig o JavaScript.

## Alternativas descartadas

* Un agregado mutable `ChangeAgreement`: duplicaría estados y concurrencia de la
  propuesta sin aportar una invariante nueva.
* Usar el UUID de propuesta como token: convierte un identificador interno en
  capacidad pública y no permite revocarla de forma independiente.
* Enviar push desde el controller/handler: puede avisar antes del commit y hace
  que una caída externa tumbe un cambio válido.
* Inferir roles desde `RosterSource::SWAP` o `describe()`: pierde compañero,
  acuerdo, varios segmentos y estado de gobierno.

## Consecuencias

Existe una ficha estable alcanzable desde bandeja, push, calendario y sección de
cambios. La consulta pública puede seguir resolviendo después de una cancelación
para mostrar el estado real. El snapshot añade datos deliberadamente duplicados,
pero son evidencia histórica, no otra autoridad sobre el flujo.
