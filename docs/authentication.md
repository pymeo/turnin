# Autenticación

Turnin mantiene una sola identidad por persona. Una cuenta puede autenticarse
con contraseña, con Google o con ambas credenciales; Google no crea una tabla de
usuarios paralela ni un tipo especial de usuario.

## Contraseña

`POST /register` crea `Identity\User` con email normalizado y hash. `POST /login`
usa Symfony Security y la sesión del servidor. Un usuario creado exclusivamente
mediante Google tiene `password_hash = NULL`, por lo que no puede entrar por el
formulario hasta que una futura funcionalidad le permita establecer contraseña.

## Google OAuth / OpenID Connect

Las dos entradas (`/login` y `/register`) enlazan al mismo endpoint:

```text
GET /auth/google
GET /auth/google/callback
```

KnpU OAuth2 Client inicia Authorization Code con `state` y los scopes mínimos
`openid email profile`. El access token solo vive durante el callback; Turnin no
persiste access tokens ni refresh tokens.

`AuthenticateWithExternalIdentity` aplica estas reglas en una transacción:

1. Si `(provider, provider_subject)` existe, devuelve su usuario sin volver a
   depender del email.
2. Para una identidad nueva exige `email_verified = true`.
3. Si el email ya pertenece a una cuenta, enlaza Google a esa misma cuenta.
4. Si no existe, crea un usuario sin contraseña y lo enlaza.
5. Una cuenta no puede tener dos subjects del mismo proveedor.

PostgreSQL refuerza la concurrencia con índices únicos en email,
`(provider, provider_subject)` y `(user_id, provider)`. Si dos callbacks compiten,
el adaptador transaccional reevalúa una vez desde el estado ya confirmado: ambos
resuelven la misma identidad o el segundo termina como conflicto controlado.

## Configuración

Los secretos nunca se versionan. En desarrollo se colocan en `.env.local`:

```dotenv
GOOGLE_OAUTH_CLIENT_ID=...
GOOGLE_OAUTH_CLIENT_SECRET=...
```

No hace falta `GOOGLE_REDIRECT_URI`: la URL del callback la genera el router de
Symfony desde `identity_google_callback` y el origen HTTPS reconocido tras el
proxy. Un string repetido es justo lo que se desincroniza del cliente de Google.

Para probar el login de verdad hace falta que `dev.turnin.es` resuelva a esta
máquina —`make tunnel-up`, ver
[DEVELOPMENT.md](DEVELOPMENT.md#desarrollo-remoto-con-devturnines)—. Con el túnel
apagado, `/auth/google` sigue redirigiendo a Google, pero Google no puede volver.

Producción los inyecta desde el gestor de secretos del despliegue. `DEFAULT_URI` (en `.env.dev`,
`https://dev.turnin.es`) debe ser el origen HTTPS público: es lo que usan los
comandos de consola, donde no hay petición de la que deducir el host. Caddy y Symfony confían únicamente en proxies
privados para interpretar `X-Forwarded-Proto`.

En Google Cloud Console, el OAuth Client de tipo **Web application** debe tener
exactamente estas Authorized redirect URIs, sin slash final:

```text
https://dev.turnin.es/auth/google/callback
https://turnin.es/auth/google/callback
```

La ruta se genera desde el request/configuración; no hay dominios hardcodeados
en PHP.

## Destino posterior y logout

Contraseña y Google terminan en `PostAuthenticationSuccessHandler`, que consume
un `target_path` interno y delega el resto en
`PostAuthenticationDestinationResolver`. URLs externas se descartan. El shell
autenticado muestra `Salir` en onboarding y ambos lobbies. Logout requiere POST
+ CSRF, invalida solo Turnin y nunca cierra la sesión global de Google.
