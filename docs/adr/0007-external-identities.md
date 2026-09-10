# ADR 0007: proveedores externos como credenciales de una identidad

## Decisión

Los proveedores OAuth se modelan como `ExternalIdentity`, enlazada al mismo
`Identity\User` que usa contraseña. La identidad estable es el `sub` del
proveedor, no su email. La vinculación por email solo ocurre en el primer acceso
y exige email verificado.

No se almacenan tokens OAuth. La creación de usuario y la vinculación se hacen
en una transacción y PostgreSQL impide subjects duplicados o dos identidades del
mismo proveedor para un usuario.

## Alternativas descartadas

Una tabla de usuarios Google duplicaría cuentas y rompería Workforce. Usar email
como identidad permanente fallaría cuando cambiase en el proveedor. Implementar
OAuth mediante HTTP manual duplicaría la validación de `state`, el intercambio
del código y el manejo de errores ya resueltos por librerías mantenidas.

## Consecuencias

Una cuenta puede combinar contraseña y Google. Añadir otro proveedor requiere un
adapter de infraestructura y un valor en `ExternalIdentityProvider`, sin cambiar
Workforce ni la sesión.
