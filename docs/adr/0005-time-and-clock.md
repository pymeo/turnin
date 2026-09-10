# 5. El tiempo entra por un puerto

* **Estado**: aceptada
* **Fecha**: 2026-09-10

## Contexto

Turnin es un producto sobre el tiempo. Las reglas que le dan valor suenan así:

* ¿este cambio deja un descanso legal entre dos turnos?
* ¿esta solicitud ha caducado?
* ¿el sábado 19 queda libre si acepto esta propuesta?

Ninguna se puede probar si la respuesta depende del reloj de la máquina. Y hay una
trampa específica del dominio: **un turno de noche del sábado que termina el
domingo a las 08:00 es un turno del sábado**. Modelar eso como un timestamp
imputa medio cuadrante al día equivocado.

## Decisión

1. `Domain` y `Application` reciben el instante actual por
   **`Psr\Clock\ClockInterface`**. Nunca llaman a `new DateTimeImmutable()`,
   `time()`, `date()`, `strtotime()` ni `mktime()`.
2. Lo comprueba `tests/Architecture/DeterministicTimeTest`, que analiza el código
   con `token_get_all()` —así un docblock que menciona la construcción prohibida
   no da un falso positivo.
3. **Día laboral ≠ instante.** `WorkDate` será una fecha sin hora ni zona; los
   instantes técnicos son `DateTimeImmutable` en UTC, en columnas `TIMESTAMPTZ`.
4. **`Europe/Madrid` no aparece en el dominio.** España es el primer mercado, no
   el único. La zona es un dato del centro de trabajo y se pasa como argumento.
5. `date.timezone = UTC` en `php.ini`, para que ningún cálculo dependa de la
   configuración del servidor.

## Alternativas

**Un `Clock` propio en vez de PSR-20.** Descartada: PSR-20 ya es exactamente esa
interfaz, Symfony la implementa y trae `MockClock` para tests.

**Confiar en la revisión de código.** Descartada. Es la regla más fácil de
incumplir sin darse cuenta —`new DateTimeImmutable()` se escribe solo— y la más
cara de detectar después, porque el síntoma es un test que falla a medianoche.

**Guardar todo en hora local del centro.** Descartada: los cambios de horario de
verano crean instantes ambiguos y duplicados dos veces al año, justo en turnos de
noche.

## Consecuencias

* Cualquier regla temporal se puede probar en cualquier instante, incluido el
  cambio de hora y el 29 de febrero.
* Cada caso de uso que necesita la hora tiene una dependencia más en el
  constructor. Es visible, y así se sabe cuáles dependen del tiempo.
* El test de arquitectura prohíbe `date()` en `Domain` y `Application`. Formatear
  para mostrar es tarea de la capa de presentación, que es donde debe estar.
