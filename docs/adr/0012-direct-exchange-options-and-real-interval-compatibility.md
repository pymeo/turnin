# 12. Opciones de retorno y compatibilidad por intervalos en el intercambio directo

* **Estado**: aceptada
* **Fecha**: 2026-09-13

## Contexto

El flujo directo obligaba a escoger un único turno de retorno y mezclaba
disponibilidad, nombres de plantilla y fecha laboral con la pregunta real:
«¿puede esta persona trabajar este intervalo?». En móvil, tocar una celda no
producía una lista visible y un resumen fijo podía quedar detrás de la
navegación. Además, editar el perfil creaba una asignación nueva y hacía que una
propuesta válida pareciese pertenecer a un equipo distinto al confirmarla.

## Decisión

`SwapRequest` continúa siendo «quiero librar este turno». Al decir «Se lo hago»,
el proponente ofrece entre uno y cinco `SwapProposalOption`, todos turnos reales
propios. El autor elige exactamente una opción. Antes de ejecutar se vuelven a
validar solicitud, pertenencias activas, propiedad de ambos turnos, intervalos y
estado; la UI nunca decide compatibilidad.

`ShiftCompatibility` usa inicios y finales efectivos. El mismo `WorkDate`, el
`templateId`, el label y `ShiftKind` no invalidan un intercambio. Un solape real
sí lo invalida; un intervalo corto entre turnos se devuelve como explicación
informativa y no como prohibición mientras no exista una política organizativa
que lo ordene.

La selección se representa en texto y forma, además de color: `+ PUEDE`,
`× NO PUEDE`, `✓ ELEGIDO` y `→ TÚ HACES`. Navegar al mes anterior o siguiente
transporta las claves seleccionadas. El resumen enseña fechas y horas, nunca
UUID. Tras ejecutar, Scheduling marca los días afectados con
`RosterSource::SWAP` y el calendario muestra `↔`.

La asignación primaria del perfil se actualiza conservando su identidad. Para
datos creados antes de esta decisión, la ejecución resuelve la asignación activa
actual dentro del mismo pool; no concede acceso a otro pool ni confía en IDs del
navegador.

## Alternativas descartadas

* Cinco columnas nullable en `SwapProposal`: impiden invariantes y evolución
  limpia; las opciones son filas con FK, posición y unicidad.
* Bloquear todo el día si existe cualquier turno: rechaza turnos consecutivos
  válidos y falla con noches.
* Tratar doce horas como ley universal: Turnin no conoce todavía la política de
  descanso de cada organización.
* Hacer el resumen sólo con JavaScript o color: oculta el estado a tecnologías
  de asistencia y hace incomprensible el fallo cuando no carga el asset.

## Consecuencias

La aceptación es idempotente y transaccional: una opción se elige una sola vez,
los dos cuadrantes cambian juntos y propuestas competidoras dejan de ser
ejecutables. `Availability` queda como señal de descubrimiento. Las políticas de
aprobación organizativa siguen siendo una fase separada y no se simulan con
roles globales ni correos hardcodeados.
