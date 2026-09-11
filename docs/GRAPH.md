# Graft — el grafo del proyecto

[Graft](https://www.npmjs.com/package/@nanonets/graft) indexa el repositorio y
produce un grafo navegable: qué clases existen, quién llama a quién, qué se rompe
si tocas un símbolo. Es la misma herramienta que usamos en Pymeo.

## Para qué lo usamos

Para **entender antes de tocar**, sobre todo con agentes. Preguntar al grafo
cuesta unos cientos de tokens; reconstruir lo mismo leyendo ficheros cuesta miles
y además se pierde las aristas —justo lo que importa en un refactor.

En la práctica:

* orientarse en una zona desconocida (`graft map`);
* localizar dónde vive un comportamiento (`graft ask`);
* ver el radio de impacto antes de renombrar o borrar (`graft callers`);
* conocer la API de un fichero sin abrirlo (`graft skeleton`).

## Comandos

| Make | Qué hace |
| --- | --- |
| `make graph` | Reconstruye el grafo (`graft build`) |
| `make graph-check` | Falla si el grafo está desincronizado con el código |
| `make graph-map` | Clusters, hubs y hotspots del repositorio |
| `make graph-viz` | Visualización interactiva en el navegador |

Directamente, con más opciones:

```bash
npx graft ask "cómo se comprueba la salud del sistema" --source
npx graft grep "HealthProbe"
npx graft skeleton src/Platform/System/Domain/HealthReport.php
npx graft callers ComponentHealth --depth 2
```

Corre en el host con Node, no dentro del contenedor `app`.

## Qué analiza

Todo el repositorio menos lo ignorado: `src/`, `tests/`, `config/`, `assets/`,
`public/`, más `importmap.php` y `playwright.config.ts`. Reconoce PHP, JavaScript
y TypeScript, así que el service worker y las specs de Playwright también están
en el grafo.

Estado actual: **497 ficheros, 3.059 nodos, 5.652 aristas**.

## Qué no versionamos

`graft/` está en `.gitignore`, entero.

Es una decisión consciente y se aparta de Pymeo, que versiona
`graft/.graph/wiring.json` para detectar desincronización desde CI. El motivo del
cambio: en la versión que usamos aquí (0.18), **cada consulta refresca el grafo
antes de responder**, así que un grafo obsoleto en disco no engaña a nadie.
Versionar `wiring.json` solo añadiría un diff de 200 KB a cada cambio de código.

Por eso tampoco es una puerta de CI: reconstruirlo allí y comprobar que coincide
consigo mismo no prueba nada. Si en el futuro alguna comprobación depende del
grafo, se versionará `wiring.json` y se revisará esta decisión.

## Sobre `--deep`

`graft build --deep` añade una capa de resúmenes conceptuales generados por un
LLM. **No la usamos**: requiere una clave de API y envía código a un proveedor
externo. La construcción estructural por defecto no manda nada a ninguna parte.

`graft check` avisa de que esa capa está al 0 %; es lo esperado.

## Cuándo reconstruir

Casi nunca a mano: las herramientas de consulta refrescan el grafo solas. Ejecuta
`make graph` tras un cambio grande (mover un contexto, renombrar en masa) si
quieres que la primera consulta no pague ese coste.

Si Graft nombra un fichero que no existe en disco, su índice va por delante del
checkout (un cambio de rama sin traer). `make graph` lo arregla.
