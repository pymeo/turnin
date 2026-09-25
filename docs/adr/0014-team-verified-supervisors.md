# ADR 14 — Responsables verificados por su propio equipo

Fecha: 2026-09-25

## Contexto

Los pools pueden exigir aprobación (`ShiftExchangePolicy::requiresApproval`) y
el flujo de espera y decisión ya existía, pero nadie podía llegar a ser
responsable: la tabla `workforce_swap_supervisors` solo se rellenaba a mano y la
capacidad `has_supervisor_profile` de la cuenta no tenía ningún camino de
alta. Había dos extremos que evitar:

* que cualquiera pulse «Soy responsable» y empiece a aprobar cambios;
* integrarse desde el primer día con RRHH de cada hospital para saber quién lo es.

## Decisión

**Invitación + verificación por quórum de los trabajadores del propio pool.**

```
compañero invita ─▶ responsable entra con Google ─▶ acepta
   ─▶ PENDING_VERIFICATION ─▶ el equipo confirma (quórum) ─▶ VERIFIED
```

1. **Una sola identidad.** No hay login ni tabla de responsables. La misma
   cuenta Turnin puede ser trabajadora de UCI, responsable de UCI y nada en
   Urgencias. El acceso adicional viene de una asignación organizativa, no de
   otra cuenta. Las altas nuevas usan el Google OAuth existente; no hay
   contraseñas, magic links, códigos ni emails transaccionales nuevos.
2. **La responsabilidad es por pool.** `SupervisorAssignment` (Workforce) une
   persona y `SwapPool` con un estado explícito:
   `PENDING_VERIFICATION → VERIFIED → LEFT`, más `REVOKED` reservado. La
   autoridad responde siempre a «¿es responsable VERIFIED de *este* pool?»;
   nunca a un rol global, al flag de la cuenta, a un enlace ni a la página de
   origen. Un pool admite varios responsables verificados; cualquiera puede
   decidir y la primera decisión válida cierra el cambio (bloqueo de fila en la
   propuesta).
3. **Invitación ≠ verificación.** `SupervisorInvitation` lleva un token de 256
   bits del que solo se guarda el hash; caduca a los 7 días (configurable) y
   solo permite *pedir* ser responsable. Aceptar crea un assignment pendiente
   sin ningún permiso. Una invitación aceptada deja de importar: el proceso
   continúa por el assignment.
4. **Verificación por quórum.** `SupervisorVerification` guarda cada respuesta
   con `UNIQUE(assignment, verifier)`. Solo cuentan miembros activos del pool
   (membership, asignación y pool activos, leídos en servidor) distintos de la
   candidata. `SupervisorVerificationPolicy` es el único sitio que dice cuántas
   hacen falta: 2 si el equipo (sin la candidata) tiene menos de 5 personas, 3
   si tiene 5 o más. Los umbrales son parámetros del contenedor, a la espera de
   que existan organizaciones con configuración propia.
5. **El invitador cuenta, de forma auditable.** Quien crea la invitación ya
   afirma conocer a esa persona. Al aceptarse se registra su confirmación como
   fila con `source = INVITATION`, solo si sigue siendo miembro activo y no es
   la propia candidata. No hay contadores mágicos.
6. **Transición atómica.** El voto que alcanza el quórum y el paso a
   `VERIFIED` ocurren en la misma transacción, con la fila del assignment
   bloqueada (`FOR UPDATE`). El agregado devuelve `true` solo en esa llamada, y
   solo entonces se publica `SupervisorVerified` tras el commit. Dos votos
   simultáneos se serializan; no hay doble verificación ni doble notificación.
7. **Renunciar no borra nada.** `LEFT` + `leftAt`. La fila, sus verificaciones
   y `swap_proposals.approved_by` se conservan («Aprobado por Laura»). La
   autoridad se lee en cada petición, así que se pierde en la siguiente. Si no
   queda nadie verificado, los cambios `PENDING_APPROVAL` siguen esperando:
   nunca se aprueban por defecto ni se cancelan.
8. **Los cambios ya pendientes no se pierden.** El panel `/app/responsable` lee
   las propuestas en `PENDING_APPROVAL` de los pools verificados, no eventos.
   Un acuerdo alcanzado antes de que hubiera responsable aparece en cuanto se
   verifica, y la notificación de verificación incluye cuántos esperan.
9. **WhatsApp no es dominio.** La aplicación expone `shareUrl` y
   `shareMessage`; el navegador elige Web Share, un enlace `wa.me` con el texto
   o el portapapeles. No se piden contactos ni se guardan teléfonos.

### Qué significa «verificado»

`TEAM_VERIFIED` es una **verificación comunitaria del equipo**: compañeros
del pool han confirmado en Turnin que esa persona es quien suele gestionar sus
cambios. **No es una certificación legal ni oficial del hospital**, y ninguna
pantalla debe presentarla así. Turnin no se convierte en autoridad
organizativa; registra lo que el equipo afirma.

`ORGANIZATION_VERIFIED` queda preparado para el día en que un administrador
oficial del centro asigne responsables directamente, sin quórum. Hoy solo lo
llevan las filas migradas de la tabla administrativa anterior; no hay
backoffice.

## Alternativas descartadas

* **`ROLE_MANAGER` o `has_supervisor_profile` como autorización.** No dice de
  qué pool, no deja historia y se hereda a todos los equipos. El flag se
  mantiene solo como navegación (la cuenta tiene algo de responsable que
  enseñar en su inicio).
* **Autoasignación («Soy responsable»).** Cualquiera podría aprobar cambios de
  cualquier equipo.
* **Integración con RRHH desde el día uno.** Bloquea el producto meses por cada
  hospital. Queda como `ORGANIZATION_VERIFIED`.
* **Verificación con un solo voto o fallback administrativo automático en
  equipos pequeños.** Una verificación que nadie pudo rechazar no verifica.
  Con menos compañeros que el quórum, la solicitud sigue pendiente y la
  pantalla lo explica.
* **Usar el UUID del assignment como enlace de verificación.** Es un
  identificador interno y ordenable. El enlace lleva un token HMAC derivado del
  id con el secreto de la aplicación, del que se guarda el hash: se puede volver
  a compartir sin guardarlo en claro y no es enumerable. Aun así, el enlace no
  concede nada: la pertenencia se comprueba en cada petición.
* **Impugnación («Esta persona no es nuestra responsable») con estado
  `DISPUTED`.** Exigiría una resolución manual que todavía no existe. «No puedo
  confirmarlo» se registra, no cuenta y deja de insistir a esa persona.
* **Guardar la foto de Google.** Sería otro dato personal para algo que el
  nombre y el email enmascarado ya resuelven. Se muestra la inicial.

## Consecuencias

* Nuevas tablas `workforce_supervisor_invitations`,
  `workforce_supervisor_assignments` (índice único parcial sobre pool+persona
  activos) y `workforce_supervisor_verifications`. `workforce_swap_supervisors`
  desaparece; sus filas pasan a assignments `ORGANIZATION_VERIFIED`.
* `ShiftExchangeGovernance` sigue siendo el puerto de Swap: `policyFor` dice
  *qué* exige un pool y `canApprove`/`supervisedPools`/`approversOf` dicen
  *quién* tiene autoridad. `ReviewSwapApproval` comprueba la autoridad antes que
  cualquier otra cosa.
* Notification escucha cuatro eventos nuevos de Workforce y reutiliza
  `DeliverNotification`. Push solo para lo que exige actuar (pedir verificación,
  quedar verificado, cambio pendiente de revisar, quedarse sin responsable con
  cambios esperando); el resto, solo campanita.
* Tras el login, una cuenta solo responsable va a `/app`, que enseña su estado
  de responsable; `/supervisor` redirige a `/app/responsable`.
* Un equipo de una sola persona no puede verificar a nadie hasta que entren más
  compañeros o exista la verificación organizativa.

## Addendum 2026-09-25 — Segundo camino: autosolicitud de un miembro del pool

Quien ya trabaja en el pool puede pedir él mismo que su equipo lo verifique
(«Solicitar ser responsable»), desde el final del onboarding, el inicio o Mi
equipo. Los tres llaman al mismo comando, `RequestSupervision`.

```
Camino A: compañero invita → acepta → 1/N (el invitador cuenta) → quórum → VERIFIED
Camino B: miembro solicita          → 0/N (nadie ha avalado)   → quórum → VERIFIED
```

* **Mismo agregado, misma verificación.** `SupervisorAssignment::selfRequested`
  crea el mismo assignment `PENDING_VERIFICATION`, con el mismo enlace, la
  misma política, el mismo evento `SupervisorVerificationRequested` (sin
  invitador) y la misma autorización. No se crea `SupervisorInvitation`.
* **Solo miembros activos del pool.** Lo comprueba el propio agregado con el
  `SwapPoolTeam` leído en servidor. Nadie puede buscar un hospital y
  proclamarse responsable de un equipo ajeno: quien coordina un equipo en el
  que no trabaja entra por invitación.
* **Empieza en cero.** Nadie ha avalado a la candidata, que nunca cuenta.
* **Origen auditable.** `SupervisorAssignmentOrigin`: `INVITATION`,
  `SELF_REQUEST`, `ORGANIZATION`. Solo para auditoría: la autoridad sigue
  dependiendo exclusivamente de `VERIFIED`.
* **Duplicados.** Pedirlo dos veces devuelve el assignment en curso (un
  advisory lock por persona y pool serializa el doble toque, y el índice único
  parcial sigue siendo la última garantía). Tras `LEFT`, una nueva solicitud
  crea un assignment nuevo y el anterior queda como historia.

Descartado: un interruptor «responsable sí/no» en el perfil (sugiere que
activarlo da permisos) y contar la propia solicitud como primera confirmación
(sería votarse a sí misma).
