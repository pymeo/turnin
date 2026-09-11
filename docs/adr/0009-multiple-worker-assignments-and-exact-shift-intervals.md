# ADR 0009 — Múltiples asignaciones e intervalos exactos de turno

Fecha: 2026-09-11  
Estado: aceptado

## Contexto

Una misma persona puede trabajar simultáneamente en varios centros. Las letras
M, T, N, G o 12D son atajos humanos y pueden describir horas distintas según
el centro o la persona. Usarlas como compatibilidad produciría falsos cambios.

## Decisión

```text
User → Worker → WorkerAssignment 1..N → RosterDay

ShiftPreset → aplica una copia → ShiftSegment
ShiftSegment + WorkDate + timezone → ShiftInterval
```

- Puede haber cero o más asignaciones activas y como máximo una principal. La
  primera se hace principal; al desactivarla se promociona la activa más antigua.
- Cada asignación posee calendario, presets, patrones y memberships de pools
  independientes. La vista «Todos» es una proyección acotada, nunca persistencia.
- `ShiftPreset` es plantilla de entrada/presentación. Aplicarlo copia nombre,
  abreviatura, horas, clasificación y `colorKey` al `ShiftSegment` histórico.
- `ShiftKind` clasifica; no establece compatibilidad. Color tampoco.
- `ShiftInterval` es la materialización única de fecha, ventana local y zona en
  instantes. Overlaps y el futuro matching usan intervalos reales, assignment y
  pool, nunca `presetId`, abreviatura o color.
- Se elimina el ambiguo `ShiftSegment::matches()`. Las comparaciones admitidas
  nombran su semántica: horas locales, duración o presentación.

## Integraciones externas

Turnin sigue siendo la fuente de verdad. Google Calendar se conecta con OAuth
incremental separado del login. Sus eventos se normalizan en Application y
entran por `ScheduleDraft`; Google no aparece en Domain ni escribe tablas de
roster directamente. Calendar mapping y event mapping aportan ownership,
idempotencia y cursor `syncToken`; las credenciales se cifran con una clave
separada. Importar y exportar son modos explícitos, no sincronización
bidireccional implícita.

## Alternativas descartadas

- `User.calendar`, `workplace2` o un límite de dos: no representan 0..N.
- Un calendario combinado persistido: duplicaría verdad y estados.
- Compatibilidad por M/M, tipo o preset: las etiquetas no son horas.
- Fracciones persistidas: el resultado real es siempre una `ShiftWindow`.
- Pedir Calendar durante Google login o guardar tokens en ExternalIdentity:
  acopla autenticación a una integración revocable y aumenta permisos.

## Consecuencias

Las URLs pueden transportar `assignment`, pero `RosterWorkspace` valida
ownership en cada command/query. La vista combinada carga assignments una vez y
un rango de roster en una sola consulta. Los cambios de horario/color de un
preset solo afectan aplicaciones futuras.
