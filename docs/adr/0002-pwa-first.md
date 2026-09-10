# 2. PWA móvil en lugar de aplicación nativa

* **Estado**: aceptada
* **Fecha**: 2026-09-10

## Contexto

El usuario de Turnin es un trabajador por turnos que mira el móvil entre tareas, a
veces a las cuatro de la mañana. La experiencia principal es móvil, sin discusión.

Las opciones eran app nativa, multiplataforma (React Native, Flutter) o PWA.

## Decisión

**PWA instalable**, servida por Symfony con Twig y Tailwind.

* `display: standalone`, iconos incluido uno maskable, `theme-color` por esquema
  de color;
* meta tags de iOS, porque Safari ignora el manifest;
* service worker con una política de caché restrictiva (ver más abajo);
* `viewport-fit=cover` y `env(safe-area-inset-*)` para pantallas con notch;
* objetivos táctiles de 44 px como mínimo, en el token `--size-touch`;
* modo oscuro de serie: no es decoración cuando tus usuarios abren la app de
  madrugada.

## Alternativas

**App nativa.** Mejor push en iOS y mejor integración con el calendario del
sistema. Descartada por ahora: dos bases de código y dos revisiones de tienda para
un producto que aún no ha validado su hipótesis principal.

**React/Vue con API.** Descartada: añade una frontera de API, un bundler y un
estado duplicado antes de tener una sola pantalla. Twig renderiza en el servidor y
Stimulus se usa donde aporta —hoy, solo el botón de instalación.

## Consecuencias

* Un despliegue, un lenguaje, sin tiendas.
* **Push en iOS es limitado.** Es la contrapartida real, y afecta al slice 10
  (notificaciones), donde la inmediatez importa. Si resulta bloqueante, la
  decisión se revisa con datos.
* El service worker es un riesgo de privacidad si se usa mal, y por eso su
  política es restrictiva por defecto: no se cachea ninguna navegación, solo
  assets con hash. Ver [SECURITY.md](../SECURITY.md#datos-offline).
* La barra de depuración de Symfony queda desactivada para que los tests E2E
  midan el HTML real (ver [DEVELOPMENT.md](../DEVELOPMENT.md#la-barra-de-depuración)).
