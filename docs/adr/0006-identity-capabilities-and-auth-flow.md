# ADR 0006: identidad única y capacidades laborales separadas

## Decisión

Turnin usa una única identidad autenticada por sesión Symfony. `User` guarda
email normalizado, hash y las capacidades disponibles; el contexto laboral se
resuelve en Workforce mediante `WorkerAssignment`. Trabajador y supervisor no
son tipos excluyentes ni cuentas separadas.

El login es el punto de entrada. Si no existe asignación laboral completa, la
sesión continúa en `/onboarding`; si existe, el destino normal es `/app`. La
entrada de supervisor se autoriza aparte y nunca se concede desde el registro
público.

## Motivo

Evita duplicar personas y permite que una misma cuenta trabaje y gestione. La
separación mantiene Identity independiente de catálogos, centros y `SwapPool`.

## Consecuencias

El estado de onboarding se puede recuperar tras cerrar la PWA porque la cuenta
ya existe. El lobby de supervisor y las invitaciones profundas se incorporan
sin cambiar la identidad; la gestión administrativa de esas asignaciones y la
aprobación de cambios son trabajo posterior.
