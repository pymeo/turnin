# Roadmap

Orden previsto de las vertical slices. Cada una debe llegar hasta la interfaz y
con tests: media funcionalidad sin pantalla no es media funcionalidad, es deuda.

## Hecho

* **0. Bootstrap** — Docker, Symfony 7.4, PostgreSQL, Redis, Caddy, CI, PWA,
  sistema de diseño, `/health` como slice vertical completa, migraciones reales en
  tests, Deptrac, PHPStan, Playwright, Graft y esta documentación.
* **1. Workplace catalog / import oficial.** Catálogo público SNS desde Atención
  Primaria, Urgencia Extrahospitalaria y Hospitales; sincronización idempotente,
  bajas lógicas y búsqueda PostgreSQL para onboarding.
* **1b. Workforce assignment foundation.** Categorías con aliases, unidades por
  centro (incluidos equipos volantes), asignación y clave conservadora de
  `SwapPool`.
* **2. Identity + Google OAuth.** Registro, login, sesión, CSRF, rate limiting,
  credenciales externas sin tokens persistidos y perfil personal protegido.
  Cerrada de extremo a extremo el 2026-09-12: el entorno local se publica en
  `https://dev.turnin.es` por Cloudflare Tunnel y el login real con Google
  funciona desde el móvil (→ [DEVELOPMENT.md](DEVELOPMENT.md#desarrollo-remoto-con-devturnines)).
* **3. Perfil profesional + onboarding.** Flujo móvil reanudable para nombre,
  identidad, centro, categoría y destinos; crea memberships y permite actualizar
  la asignación laboral existente.
* **6/7a. Primera vuelta de intercambios.** `Swap` con `SwapRequest` y
  `Availability`: publicar un turno que se quiere soltar, declararse disponible
  y ver quién podría cubrir cada turno. Todo acotado por `SwapPool`, y nada
  toca el cuadrante. Ver [ADR 10](adr/0010-swap-requests-and-availability.md).
* **5. Calendario personal.** `Scheduling` con `RosterDay`, `ShiftSegment`,
  `ShiftPreset` y `RosterPattern`. Tres entradas —pintar, patrón y voz/texto—
  que convergen en un `ScheduleDraft`, con preview obligatorio y política de
  conflictos explícita. Parser determinista sin IA ni servicios externos.

## Siguiente

Los números son los de la slice, no el orden de la lista: 5, 6 y la primera
mitad de 7 ya están hechas.

4. **SwapPool y Membership.** El concepto del que depende todo el matching
   (→ [DOMAIN.md](DOMAIN.md#swappool-el-concepto-que-hay-que-entender)).
7b. **Propuesta y aceptación.** `SwapProposal` sobre una `SwapRequest` y una
   `Availability` que ya se conocen: quién confirma, en qué orden, qué pasa si
   dos aceptan a la vez.
8. **`SwapAgreement` y aplicar el cambio al calendario.** Cierra el primer ciclo
   completo. **Aquí Turnin empieza a servir para algo.**
9. **DirectMatcher.** Turnin propone, en vez de esperar a que el usuario elija.
10. **Notificaciones.** Push. Sin esto, el matching no llega a tiempo.
11. **Pro / Subscription.** Stripe. No antes: no hay nada que cobrar hasta que
    9 y 10 funcionen.
12. **Encuéntrame un día libre.** «Quiero librar el sábado 19» sin decir cómo.
13. **Encuéntrame un puente.**
14. **CycleMatcher.** Cambios encadenados de tres o más personas.
15. **ShiftDebt.** Favores pendientes.
16. **Coverage B2B.** Producto distinto, cliente distinto.

El corte de pago está entre 8 y 9 a propósito: participar es gratis, que Turnin
busque por ti se paga (→ [PRODUCT.md](PRODUCT.md#monetización)).

## Deuda técnica conocida

Problemas reales, no una lista de deseos.

| Qué | Por qué no está hecho | Cuándo toca |
| --- | --- | --- |
| Sin `Content-Security-Policy` | **Vencida.** La pantalla autenticada ya existe y el origen está ahora en Internet (`dev.turnin.es`). Ya se sabe qué carga la app: ninguna plantilla tiene `<script>` ni `style=` en línea, y el flujo OAuth es redirect de servidor —sin SDK de Google en el navegador—, así que la política puede ser estricta. El único inline es el `importmap` de AssetMapper, que necesita nonce | La próxima iteración; no se metió en la del entorno público para no arriesgar el flujo que había que demostrar |
| Sin transporte asíncrono | Redis está levantado, pero nada es lo bastante lento aún | Slice 9 o 10 |
| Sin copias de seguridad | No hay datos | Antes del primer usuario real |
| Perfiles de supervisor aún sin alta administrativa | La identidad ya soporta la capacidad, pero no se auto-concede permisos | Slice responsable |
| E2E solo en Chromium | La imagen de Playwright trae los tres motores; falta activarlos | Cuando haya UI que merezca la matriz |
| `graft/` no versionado | Se aparta de Pymeo; ver [GRAPH.md](GRAPH.md#qué-no-versionamos) | Si CI llega a depender del grafo |
| Rehacer el onboarding esconde el calendario | `CompleteWorkerOnboarding` desactiva la asignación e inserta una nueva con otro id, y el calendario cuelga de la asignación. Los datos siguen ahí, pero dejan de mostrarse | Con la primera edición real de perfil: o la asignación conserva su id cuando la clave de pool no cambia, o el calendario se traslada a la nueva |
| `workforce_worker_assignments.worker_id` sin clave foránea | Borrar una cuenta deja la asignación, sus memberships y ahora su calendario huérfanos. Añadirla exige limpiar primero los huérfanos existentes | Antes del primer usuario real, junto con las copias de seguridad |
| E2E contra la base de datos de desarrollo | La suite crea cuentas, asignaciones y unidades locales que alteran el ranking de sugerencias del onboarding. Los tests ya no asumen qué unidad sale primero, pero la acumulación sigue | Cuando `make test-e2e` necesite ser determinista en CI: base propia y reseteo entre ejecuciones |
| Imagen de producción ~910 MB | `php:8.5-fpm-trixie` son 741 MB de base. Alpine la dejaría en ~190 MB, pero musl trae ICU recortado (`icu-data-full`) y Turnin formatea fechas en español: no es el momento de arriesgar eso | Cuando el tiempo de despliegue moleste, con verificación de locales |

## Decisiones aplazadas

* **Importación de cuadrantes.** Cada centro los publica de forma distinta —PDF,
  Excel, capturas—. Es un producto en sí mismo y probablemente el foso defensivo
  más profundo de Turnin. No se toca hasta que el ciclo manual funcione. El
  camino ya está abierto: `RosterSource::IMPORT` existe y cualquier importador
  produce un `ScheduleDraft` que pasa por el escritor actual.
* **Exportar el calendario a `.ics`.** Descarga de un mes como calendario
  estándar. Barato de hacer y no bloquea nada, pero no aporta al ciclo de
  intercambio, que es lo que decide si Turnin sirve para algo.
* **Editar las horas de un segmento sin tocar el preset.** Hoy un turno se
  cambia eligiendo otro preset; un horario excepcional se resuelve creando un
  preset. Basta mientras no aparezca alguien con turnos irrepetibles.
* **Aprobación de supervisión.** Muchos centros exigen visto bueno para un cambio.
  Está previsto en la máquina de estados (`awaiting_approval`) y no implementado.
* **Motor de reglas por pool.** Hoy la compatibilidad se preguntará al `SwapPool`
  con reglas mínimas. El motor completo llega cuando haya varios centros reales
  con reglas contradictorias, no antes.
* **REGCESS privado.** Es una fuente futura posible para clínicas y otros centros
  privados; el catálogo inicial se limita deliberadamente a sanidad pública/SNS.

## Slice completada: primera vuelta de intercambios

`/app/changes` muestra lo que los compañeros del propio grupo necesitan cubrir,
lo que uno ha publicado y los días en los que se ha ofrecido. El calendario gana
las dos acciones que alimentan esa pantalla: «Quiero quitarme este turno» sobre
un día trabajado y «Puedo trabajar este día» sobre uno libre o sin indicar.

**Implementado**

* disponibilidad explícita básica, por trabajador, grupo y día;
* publicación de un turno propio que se quiere soltar;
* descubrimiento acotado por `SwapPool`, nunca por centro;
* candidatos disponibles por fecha y pool, visibles solo para quien publica;
* retirada de ambas declaraciones, sin borrar el histórico.

**Pendiente**

* `SwapAgreement`;
* aprobación de supervisión ligada a una organización;
* consumo de saldo mediante solicitud posterior;
* matching automático avanzado y cadenas;
* grados de disponibilidad.

**Ampliado el 2026-09-13**

* propuestas directas, diferidas y de cobertura con aceptación/rechazo/retirada;
* comparador por horas reales y saldo parcial sin dinero;
* preferencias futuras opcionales;
* detector determinista de puentes con umbral, score y razones explicables.

Esta pantalla de consulta es un andamio deliberado para validar la red y
producir los datos que consumirá el matcher. El producto final no es un tablón.

## Slice completada: calendario personal

`/app/calendar` muestra el mes en una cuadrícula de seis semanas y ofrece tres
maneras de rellenarlo. Pintar: se elige un turno y se tocan o arrastran los días,
con deshacer, y nada se guarda hasta confirmar. Patrón: se construye la rotación
tocándola, se elige desde cuándo y durante cuánto, y se previsualiza antes de
aplicar. Voz y texto: el navegador transcribe, el parser interpreta y el
resultado pasa por la misma pantalla de confirmación.

Las tres convergen en `ScheduleDraft`, se resuelven contra lo que ya hay con
`ConflictPolicy` y se escriben en una sola operación transaccional.

Queda fuera a propósito: la importación de cuadrantes, la exportación `.ics`, la
edición de horas por día sin pasar por un preset y cualquier sugerencia que
necesite un motor de matching. El panel de oportunidades solo dice lo que el
calendario ya sabe —«tienes 4 días libres seguidos»— y no inventa cambios
posibles.

## Slice completada: Identity y entrada a Workforce

La landing enlaza a registro/login con sesión Symfony, y un usuario autenticado
sin asignación laboral completa continúa en `/onboarding`. El onboarding usa los
catálogos buscables de Workforce y termina en `/app`; la asignación y el
`SwapPool` siguen siendo responsabilidad de Workforce. La capacidad de supervisor
se representa separada de la de trabajador y su lobby está preparado, pero la
creación administrativa de supervisores y la aprobación de cambios quedan para
la siguiente slice.

Google OAuth queda integrado como credencial principal de la identidad existente,
con contraseña como fallback. La única operación externa pendiente es cargar las
credenciales reales fuera del repositorio y las dos redirect URIs en Google Cloud
Console. El onboarding añade datos personales protegidos y un borrador persistente;
al terminar crea la asignación primaria y los accesos adicionales declarados.
# Slice calendario multi-asignación (2026-09)

Implementados calendarios independientes 0..N, selector y vista combinada,
overlaps entre fechas/zonas, presets configurables con color histórico, turnos
custom y derivados, edición puntual, Google Calendar manual import/export
idempotente y export `.ics`.

Quedan para una slice posterior los webhooks `watch`, resolución interactiva de
borrados/conflictos remotos, sincronización automática periódica e import `.ics`.
El esquema conserva `syncToken` y el adaptador entiende la expiración 410 para
cuando se active ese proceso.

# Pendiente: «Encuéntrame un puente» con el mismo calendario

`swap/_composer_calendar.html.twig` es un componente sin nada de intercambios
dentro: recibe semanas ya proyectadas y `interactive: false` lo deja en modo
lectura. La pantalla de puentes (`/app/changes/bridge`) sigue mostrando una lista
de oportunidades porque no tiene todavía un read model de calendario; cuando lo
tenga debe reutilizar ese componente en lugar de dibujar otro. Ver
[DECISIONS.md](DECISIONS.md).
