# Seguridad y privacidad

Turnin maneja información laboral: quién trabaja, cuándo, dónde y con quién. Eso
revela la rutina y la ubicación de una persona real. No es «solo un calendario».

No perseguimos ninguna certificación todavía. Sí perseguimos que las decisiones
por defecto sean las correctas, porque son las que se quedan.

## Privacidad por defecto

1. **Los grupos son privados.** Un `SwapPool` no es descubrible desde fuera. Se
   entra por invitación o por verificación, nunca buscando.
2. **Exposición mínima del calendario.** Para resolver un cambio hace falta saber
   que alguien libra el sábado, no su mes entero. Las consultas del matcher
   devolverán lo justo para la decisión concreta.
3. **Nunca se publica quién está de turno.** Ni siquiera dentro del pool, salvo
   que sea imprescindible para el cambio en curso.
4. **Sin enumeración de usuarios.** Ningún endpoint permite recorrer personas ni
   comprobar si un email está registrado. El registro y la recuperación de
   contraseña responderán lo mismo exista o no la cuenta.

## Amenazas iniciales

Lo que consideramos realista para este producto, en orden de daño.

### 1. Un compañero curioso dentro del pool

El atacante más probable no es externo: es alguien con acceso legítimo que quiere
saber quién libra cuándo, o quién pidió cambiar un turno y por qué.

*Mitigación*: exponer solo lo necesario para el cambio en curso; no listar
solicitudes ajenas; no revelar el motivo de una solicitud a quien no participa.

### 2. Enumeración y perfilado desde fuera

Descubrir quién trabaja en qué unidad de qué hospital.

*Mitigación*: pools no descubribles; respuestas idénticas para cuentas existentes
y no existentes; rate limiting en autenticación y búsqueda; sin IDs secuenciales
(UUID v7, ver [ARCHITECTURE.md](ARCHITECTURE.md#identificadores)).

### 3. Suplantación para robar o colar turnos

Aceptar un cambio en nombre de otro, o alterar uno ya acordado.

*Mitigación*: **no confiar en ningún identificador enviado por el cliente**. Que
un formulario traiga `swapRequestId` no significa que quien lo envía participe en
esa solicitud; se comprueba en el caso de uso, siempre. Cookies de sesión
`httponly` + `samesite=lax` + `secure` (ya configurado en
[`framework.yaml`](../config/packages/framework.yaml)), CSRF en toda mutación.

### 4. Fuga por caché

Una PWA con service worker puede dejar el calendario de un trabajador en el
navegador de un dispositivo compartido —y el puesto de enfermería es un
dispositivo compartido.

Ver [§ Datos offline](#datos-offline). Es la amenaza más específica de este
producto y la más fácil de provocar sin querer.

### 5. Fuga por logs

Los cuadrantes son datos personales. Un log de depuración con el calendario
entero es una filtración con retención.

*Mitigación*: no registrar contenido de calendarios ni datos de terceros. Los
logs llevan identificadores, no cuerpos. Cada línea lleva `request_id` para poder
investigar sin volcar datos.

## Datos offline

La política del service worker está en la cabecera de
[`public/sw.js`](../public/sw.js). El resumen:

**Se cachea** —y solo esto—: `/assets/*` (salida de build con hash, inmutable),
`/icons/*`, `/manifest.webmanifest` y `/offline.html`. Todo idéntico para
cualquier visitante y sin un solo dato personal.

**No se cachea nunca**:

* **ninguna navegación**. Una página puede estar personalizada en cuanto exista
  el login, y una página cacheada es cómo una PWA acaba enseñando el calendario
  de un compañero al siguiente que usa el ordenador;
* `/health`, `/api/*`, `/_profiler/*`;
* nada que no sea un `GET` del mismo origen;
* ninguna respuesta con `Cache-Control: private`/`no-store` o `Vary: Cookie`. El
  service worker lo comprueba en `isCacheable()` antes de guardar: si el servidor
  la marcó como privada, el cliente lo respeta.

Caddy refuerza lo mismo desde el otro lado: en producción todo lo que renderiza
PHP sale con `Cache-Control: private, no-store`
([`Caddyfile.prod`](../docker/caddy/Caddyfile.prod)).

`/sw.js` se sirve con `no-cache`. Un service worker cacheado no se puede
reemplazar en el siguiente despliegue.

## Contraseñas y sesión

Todavía no hay autenticación. Cuando la haya:

* hashing con el algoritmo por defecto de Symfony (`auto` → bcrypt/argon2id), sin
  inventar nada;
* cookies de sesión `secure` (auto en dev por HTTP, obligatorio en producción),
  `httponly`, `samesite=lax`;
* sesión en Redis, no en disco: con varios contenedores el sistema de ficheros no
  es compartido;
* rate limiting en login y recuperación.

## `/health`

Es público y sin autenticar, porque lo consultan Docker y el balanceador antes de
que exista sesión alguna. Por eso responde lo mínimo:

```json
{"status":"healthy","observedAt":"…","components":{"cache":{"status":"healthy"}}}
```

Nombres de componentes y su estado. **Nunca** hosts, puertos, versiones, DSN ni
mensajes de excepción: las sondas capturan el error y devuelven un token fijo
(`unreachable`, `pending_migrations`), porque un mensaje de driver lleva dentro el
usuario y el host de la base de datos.

Hay un test que lo comprueba (`HealthEndpointTest::test_it_does_not_leak_infrastructure_details`),
para que no se degrade con el tiempo.

## Correlación de peticiones

Cada petición recibe un `X-Request-Id` (UUID v7) que va en la respuesta y en cada
línea de log. Si el cliente envía uno, se reutiliza —pero **solo si encaja en
`[A-Za-z0-9._-]{1,64}`**: sin ese filtro, una cabecera con saltos de línea permite
inyectar líneas falsas en el log.

Hay un test para eso también.

## Cabeceras

Caddy añade `X-Content-Type-Options: nosniff` y `Referrer-Policy: same-origin` en
todas partes; en producción, además, `X-Frame-Options: DENY` y HSTS.

Falta una **Content-Security-Policy**. No está puesta porque una CSP escrita antes
de saber qué carga la aplicación acaba en `unsafe-inline` y no protege de nada.
Entra con la primera pantalla autenticada. Anotado en [ROADMAP.md](ROADMAP.md).

## Secretos

* `.env` está versionado y **no contiene secretos**: solo cableado.
* `APP_SECRET` está vacío ahí. Dev y test tienen valores propios y marcados como
  tales; producción lo inyecta desde el entorno o falla al arrancar.
* `.gitignore` cubre `.env.local`, `*.pem`, `*.key` y `*secret*.txt`.
* `compose.prod.yaml` usa `${VAR:?mensaje}`: si falta un secreto, el despliegue se
  detiene en lugar de arrancar con un valor por defecto.

## Contenedor de producción

Corre como `www-data`, con el sistema de ficheros de solo lectura salvo `/tmp` y
`var/`, sin dependencias de desarrollo y sin Xdebug. No ejecuta migraciones al
arrancar: eso es un paso deliberado del despliegue
(→ [DEPLOYMENT.md](DEPLOYMENT.md)).
