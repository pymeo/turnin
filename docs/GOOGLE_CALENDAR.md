# Google Calendar

Turnin es la fuente de verdad. Calendar es una integración opcional con OAuth
independiente del login Google normal.

## Configuración en Google Cloud

1. Activa **Google Calendar API** en el proyecto OAuth existente.
2. Añade a la pantalla de consentimiento los scopes usados por el producto:
   `openid`, `email`, `calendar.calendarlist.readonly`,
   `calendar.events.readonly` y `calendar.events`.
3. Registra además de la URI de login esta redirect URI exacta por entorno:
   `https://<host>/app/calendar/integrations/google/callback`.
4. Inyecta `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET` y una
   `CALENDAR_TOKEN_ENCRYPTION_KEY` base64 que decodifique a 32 bytes. No se
   versionan credenciales reales.
5. En producción, verifica/publica la pantalla OAuth conforme a la política de
   Google antes de abrir la integración a usuarios externos.

## Permisos y credenciales

Import solicita lectura de lista y eventos. Export vuelve a consentimiento
incremental y solicita escritura de eventos. Nunca se solicitan Drive, Gmail,
Contacts ni el scope total de Calendar. `access_type=offline` permite obtener
refresh token; access y refresh token se cifran en reposo y solo se descifran en
el adaptador de infraestructura.

## Pipeline

```text
Google event → ExternalCalendarEvent → ScheduleDraft → preview → apply
RosterDay/ShiftSegment → ExternalCalendarEventDraft → Google event
```

El título solo resuelve metadata de un preset. Inicio y fin vienen siempre del
evento externo. Eventos all-day se ignoran por defecto y títulos desconocidos
quedan para revisión. El mapping incluye calendar, event, assignment, roster day
y segment; sus constraints hacen idempotentes import y re-export.

Google entrega `nextSyncToken` tras una sincronización completa y puede
invalidarlo con HTTP 410. Persistimos el cursor y el adaptador representa 410
explícitamente; la ejecución automática incremental, borrados remotos y
webhooks `watch` quedan fuera del MVP manual y constan en ROADMAP.

Desconectar revoca localmente las credenciales y desactiva mappings. No elimina
turnos importados ni eventos exportados. `.ics` funciona sin cuenta Google y
exporta únicamente el assignment seleccionado.
