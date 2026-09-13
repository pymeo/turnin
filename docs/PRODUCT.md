# Turnin — producto

> Tengo un turno que no quiero, o necesito librar un día.
> Encuéntrame automáticamente compañeros compatibles con los que resolverlo.

Turnin no es un calendario ni un tablón de anuncios. Esos ya existen y no
resuelven el problema: hoy la gente pega su cuadrante en un grupo de WhatsApp y
persigue de uno en uno a quince compañeros hasta que alguien dice que sí.

**La ventaja del producto es el matching automático** entre calendarios,
preferencias, disponibilidad y reglas del grupo de trabajo. Todo lo demás
—perfiles, centros, notificaciones— existe para alimentar ese motor.

## A quién servimos

Trabajadores por turnos, empezando por sanidad en España: enfermería, TCAE,
celadores, medicina, técnicos y urgencias. Después residencias, emergencias,
bomberos, policía, seguridad e industria.

El orden importa: cada colectivo trae sus propias reglas de compatibilidad, y el
modelo tiene que soportarlas sin reescribirse. Por eso el `SwapPool`
(→ [DOMAIN.md](DOMAIN.md)) es un concepto de primera clase desde el día uno.

## Qué puede hacer un usuario

* introducir su calendario pintándolo, repitiendo su patrón o dictándolo
  —implementado—; importarlo desde un PDF o una foto, todavía no;
* pertenecer a un centro y a uno o varios grupos de intercambio compatibles
  —implementado—;
* indicar su disponibilidad para un día y un grupo —implementado; los grados
  «quiero» / «podría», todavía no—;
* publicar un turno suyo buscando quien pueda hacerlo, y ver quién se ha
  ofrecido —implementado—;
* recibir propuestas —y solo las relevantes;
* aceptar propuestas;
* pedir intercambios;
* decir «quiero librar este día»;
* decir «quiero coger turnos»;
* ceder un turno sin recibir otro a cambio;
* registrar que un compañero le debe un turno, y devolverlo más adelante;
* recibir contrapropuestas;
* participar en cambios encadenados de tres o más personas.

## Funciones futuras que condicionan el diseño

No están implementadas. Están aquí porque el modelo de dominio tiene que poder
crecer hacia ellas sin una reescritura.

### Encuéntrame un puente

El usuario no pide un cambio concreto, pide un resultado: juntar días libres,
conseguir un puente, evitar una noche o un fin de semana, reorganizar varios
turnos. El motor analiza su calendario y busca combinaciones entre compañeros.

Implicación de diseño: el matching no puede ser una función de «un turno contra
otro turno». Tiene que poder razonar sobre un intervalo del calendario completo.

### Quiero librar un día

«Quiero librar el sábado 19», y el sistema intenta resolverlo solo.

Implicación: una solicitud de cambio no siempre nombra una contrapartida. El
agregado tiene que admitir una intención sin destinatario ni turno concreto.

### Quiero trabajar más

Mañanas, tardes, noches, fines de semana; disponible, podría estar disponible, no
disponible. Es lo que conecta a quien quiere librar con quien quiere trabajar.

Implicación: la disponibilidad es un modelo con grados, no un booleano.

### Favores pendientes

**No existe compraventa de turnos entre trabajadores.** Nunca convertiremos un
favor en dinero, en tokens negociables ni en una moneda interna: eso cambiaría la
naturaleza del producto y su encaje legal.

Sí representamos «Marta hizo mi turno y ya se lo devolveré» como **saldo de
intercambio**: horas nominales entre dos personas, sin precio ni mercado, con
consumo parcial y una nueva aceptación para cada devolución. Una preferencia
futura ayuda a descubrir oportunidades, pero nunca crea un turno ficticio.

### Cobertura B2B

Más adelante un centro podrá publicar «necesitamos una enfermera mañana de 08:00
a 15:00» y Turnin localizará trabajadores compatibles y disponibles. Producto
distinto, cliente distinto, contexto distinto (`Coverage`).

## Monetización

Precio objetivo inicial: **3,49 €/mes**. Stripe no está integrado y no lo estará
hasta que haya algo que cobrar (→ [ROADMAP.md](ROADMAP.md)).

### Gratis, siempre

Registrarse, configurar el perfil, pertenecer al centro y al grupo, calendario
básico, indicar disponibilidad, recibir propuestas, ver las propuestas dirigidas
a ti, aceptar o rechazar cambios, coger turnos, recibir notificaciones.

> **Aceptar un cambio es gratis. Sin excepciones.**

Los usuarios gratuitos no son un coste que toleramos: son la red. Un usuario Pro
sin compañeros a los que proponer nada no tiene producto. Bloquear a alguien por
ayudar a otro sería destruir el activo que hace que Turnin funcione.

### Pro

Solicitar cambios, «quiero librar este día», búsquedas ilimitadas, matching
avanzado, cambios encadenados, «encuéntrame un puente», optimización del
calendario.

> No cobramos por participar en la red.
> Cobramos cuando Turnin busca una solución para ti.

La frontera es esa: **participar** es gratis, **que trabajemos para ti** se paga.

### Cómo se refleja esto en el código

Hoy no se refleja de ninguna manera, y es deliberado: no hay funciones de pago
que proteger. Cuando las haya, la restricción se expresará como una política de
dominio consultable (del tipo `FeatureAccess`/`Entitlement`), no como
`if ($user->isPro())` repartido por los casos de uso. La decisión de *qué* puede
hacer alguien es una regla de negocio; *cómo* ha pagado es infraestructura.

## Lo que Turnin no es

* No es un mercado de turnos: no hay precio, ni puja, ni dinero entre trabajadores.
* No es un sistema de gestión para el centro: el usuario es el trabajador.
* No es un tablón: si el usuario tiene que leer una lista y decidir, hemos fallado.
* No es un chat.
