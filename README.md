# Estadística

Aplicación de Nextcloud 34 para APS Conecta Gestión: las cifras REM que el DEIS publica para todos los establecimientos del país, con las del propio centro al lado de las nacionales y de las de establecimientos parecidos, y las Metas Sanitarias de Atención Primaria mes a mes.

## Qué es

Estadística guarda las cifras REM de todos los establecimientos del país, etiquetadas con el diccionario oficial de cada año, para que un centro ponga las suyas junto a las nacionales y a las de sus pares. También sigue mes a mes las Metas Sanitarias. La interfaz está en español; todo lo demás, en inglés. Licencia AGPL-3.0-or-later.

**Guarda solo conteos agregados que publican el DEIS y FONASA, y no toca datos de pacientes.** No llena ninguna planilla REM, no la valida y no lee las series BS, BM ni D. Nada en ella decide cuál es el establecimiento «propio»: es configuración de la instancia ([ADR-0009 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0009-the-establishment-is-instance-configuration.md)).

Tiene cuatro vistas, más una sección de administración:

- **Consultar**: una consulta armada en cuatro pasos (Datos, Filtrar, Resumir, Visualizar) sobre las series REM A y P de todos los establecimientos y meses de la ventana de años, con el centro junto al país y a un grupo de pares.
- **Preguntas**: consultas guardadas, visibles para todo el personal.
- **Tableros**: preguntas guardadas dispuestas como tarjetas; cada tarjeta hace su propia consulta.
- **Metas Sanitarias**: las Metas I a VIII del año, una tarjeta por meta, mes a mes.
- **Administración → Estadística**: la ventana de años, el estado de cada carga, la población de FONASA, los compromisos de las metas de cada año y una puerta para los archivos que la aplicación no puede descargar. Un administrador puede delegarla a cualquier grupo.

## Documentación

La documentación vive en el sitio de documentación de la organización:

- [Guía de uso](https://aps-conecta.github.io/documentation/usuario/estadistica.html): cada vista y la administración, con las palabras de la pantalla.
- [Referencia para desarrollo](https://aps-conecta.github.io/documentation/desarrollo/estadistica.html): la API OCS, la especificación de consulta, la carga REM por año y serie, las claves de configuración, los invariantes, los dominios de fallo y la deuda técnica.
- [Aplicaciones propias](https://aps-conecta.github.io/documentation/administracion/aplicaciones.html): cómo gestion instala la aplicación.
- [Novedades](https://aps-conecta.github.io/documentation/proyecto/novedades/estadistica.html): los cambios que ve una persona usuaria o administradora.

Dentro de este repositorio quedan los documentos que viajan con el código:

- [`CONTEXT.md`](CONTEXT.md): el vocabulario (serie, fuente, consulta, pares, informante, meta comprometida, PIV), en inglés.
- [`docs/adr/`](docs/adr/): las decisiones, de la 0001 a la 0008.
- [`docs/threat-model.md`](docs/threat-model.md): qué busca un atacante, las entradas y sus protecciones.
- [`openapi.json`](openapi.json): el contrato OCS para otras aplicaciones, generado por `make openapi` desde el controlador, nunca a mano. La respuesta de una consulta solo se extiende, nunca cambia de forma ([ADR-0006](docs/adr/0006-one-consulta-spec-compiled-to-bound-sql.md)). El [sitio de documentación](https://aps-conecta.github.io/documentation/desarrollo/apis/plataforma-y-rest-interno.html) lo indexa.
- [`seeds/metas-2026.json`](seeds/metas-2026.json): las Metas Sanitarias 2026 tal como se leyeron de las Orientaciones Técnicas, con notas sobre lo leído y no copiado.
- [`CHANGELOG.md`](CHANGELOG.md): qué cambió y cuándo.

Los errores abiertos y el trabajo pendiente están en los issues de este repositorio.

## Estado

**0.1.0, hito M1: el pilar REM.** Todo lo anterior está construido y probado con datos sintéticos: la carga por rangos HTTP y por un zip dejado a mano, el catálogo, el motor de consultas y su API OCS, las preguntas y los tableros, la importación de FONASA y las metas 2026. `info.xml`, `package.json` y `openapi.json` dicen 0.1.0. Está etiquetada `v0.1.0` y se entrega en el conjunto `OWN_APPS` de gestion como un tarball fijado (APS-Conecta/gestion#198, 2026-09-30).

Falta:

- Queda comparar las Metas I a VII de junio de 2026 con el cálculo de la unidad de Estadística-REM antes de avisar al personal. Con datos reales en el laboratorio el 2026-09-29 (la ventana por defecto, 27,3 millones de filas, y la exportación de FONASA) aparecieron tres hallazgos para el hito M2: una fecha de corte que un solo establecimiento puede mover, etiquetas prestadas del diccionario de otro año y consultas nacionales de hoja entera que llegan al límite de 10 s.
- Los archivos del personal (CSV, XLSX) como fuente son el hito M2.

## Inicio rápido de desarrollo

Requiere una máquina con Docker y una copia de [`aps-common`](https://github.com/APS-Conecta/aps-common) como hermana (`../aps-common`). Levanta un Nextcloud 34 desechable con PostgreSQL 18 en el puerto 8092; no es la pila de gestion.

1. **Levantar la instancia** (en este repositorio): `make instance-up`. Habilita la aplicación, reinicia los procesos web y termina con `Instance ready at http://localhost:8092 (admin / admin)`.
2. **Revisar el cableado**: `make smoke`. Cada comprobación dice `ok` y termina con `All good.`
3. **Abrir `http://localhost:8092/apps/estadistica`** como `admin` / `admin`. Consultar muestra «Todavía no hay cifras REM» y el aviso de que no hay establecimiento configurado: todavía no se carga nada, y eso es lo correcto.
4. **Cargar un año** en Administración → Estadística: dejar un zip del DEIS (`SERIE_REM_<año>.zip`) en Archivos y elegirlo en «Cargar un archivo a mano». Informa «Recibido: cifras de <año>…», y «Cifras REM cargadas» muestra cada serie a medida que se carga, una por hora. Sin archivo, el trabajo horario descarga la ventana del DEIS por sí solo, donde corran los trabajos en segundo plano de Nextcloud.

`make instance-clean` elimina la instancia y su base de datos.

Una clínica recibe Estadística por gestion, no por la tienda de aplicaciones: la fase 12 desempaqueta el tarball fijado en `provisioning/apps/estadistica/` (construido desde una etiqueta de este repositorio) y la fase 16 escribe el establecimiento. Para desarrollar, clonarla como `gestion/apps/estadistica` (el nombre del directorio es el id de la aplicación); así la fase 12 deja intacto el clon en vez de desempaquetar encima. Ambos comandos se ejecutan desde el repositorio de gestion:

    git clone https://github.com/APS-Conecta/estadistica.git apps/estadistica
    make fix-mount-perms && make seed

Requiere Nextcloud 34, PHP 8.2–8.5 y **solo PostgreSQL**: `info.xml` lo declara, y una instalación sobre otro motor se rechaza al habilitar la aplicación ([ADR-0002](https://github.com/APS-Conecta/estadistica/blob/main/docs/adr/0002-postgresql-only.md)). La ventana por defecto de cuatro años ocupa unos 4,9 GB de base de datos (27,3 millones de filas, medido en el laboratorio de gestion el 2026-09-29), y lo mismo en cada respaldo.

La configuración de la aplicación se escribe con `occ config:app:set estadistica <clave> --value=<valor>`. Las claves `deis_code`, `establishment_type` y `comuna_cut` identifican el establecimiento: las escribe la fase 16 de gestion desde el archivo del sitio y la aplicación no tiene un método que las escriba; `first_year` es la ventana de años y se cambia en Administración → Estadística. Su tabla completa, y las claves de estado del trabajo (`deis_checked_at`, `deis_etags`, `deis_check_errors`, `deis_register`), están en la [referencia para desarrollo](https://aps-conecta.github.io/documentation/desarrollo/estadistica.html). Quién administra Estadística es el grupo de administradores de Nextcloud más cualquier grupo con la sección delegada, en Administración → Privilegios de administración o con `occ admin-delegation:add 'OCA\Estadistica\Settings\AdminSettings' <grupo>` ([ADR-0003](https://github.com/APS-Conecta/estadistica/blob/main/docs/adr/0003-every-admin-write-declares-its-delegation.md)).

Otras aplicaciones leen la API OCS (con `OCS-APIRequest: true`) bajo `/ocs/v2.php/apps/estadistica/api/v1/`: `fuentes`, `fuentes/{id}`, `consultas` (POST), `preguntas`, `tableros` e `indicadores`. Toda ruta es legible por cualquier usuario y tiene límite de tasa. `consultas` recibe `{consulta, establecimiento?}`: sin `establecimiento`, la serie local es la configurada. El sobre OCS es un mecanismo de Nextcloud: [API OCS](https://docs.nextcloud.com/server/34/developer_manual/client_apis/OCS/index.html).

Las pruebas y compuertas:

- `make test`: la batería unitaria en un contenedor PHP básico, sin Nextcloud.
- `npm run lint-js` y `npm test`: ESLint y Vitest.
- `make aps-drift`: las copias vendorizadas de `aps-common` están al día (`make aps-sync` las refresca).
- `make smoke`: el cableado contra la instancia de desarrollo, incluida la comprobación «openapi.json regenerates without drift».
- `make test-integration`: la batería de integración contra el PostgreSQL de la instancia de desarrollo (requiere `make deps` antes). Ningún trabajo de CI la ejecuta; correrla antes de cambiar `lib/`.
- `make test-browser`: las cifras de una tarjeta de tablero contra las de la API, en Chromium (Playwright para Python en el host). Tampoco la ejecuta CI.
- `npm run build`: genera los dos paquetes de `js/` (no se confirman; el flujo de release los compila y CI falla si la compilación no los produce).

La integración continua (`.github/workflows/ci.yml`) ejecuta `make aps-drift`, `make test`, `npm run lint-js`, `npm test`, la comprobación de los paquetes y `make smoke` sobre una instancia en frío; el flujo de release (`.github/workflows/release.yml`) compila y empaqueta en cada PR.

Tres trampas que esta aplicación ya pagó:

- **Reiniciar los procesos web tras `occ app:enable`.** Guardan las rutas en caché durante una hora, y toda ruta responde 404 hasta que se reinician. `make instance-up` lo hace.
- **La configuración de la aplicación llega al proceso web unos 3 s tarde**, y un borrado también. Una comprobación justo después de `occ config:app:set` ve el valor anterior.
- **Cada bloque `<style>` de un componente incluye todo el archivo `.vue`** como mapa de fuente en línea. Un componente sin bloque `<style>` es mucho más barato (deuda técnica B-013 en la referencia para desarrollo).

## Licencia

AGPL-3.0-or-later ([`LICENSE`](LICENSE)). Las cifras REM son datos públicos del DEIS, la población es de FONASA y las metas son las Orientaciones Técnicas del MINSAL; ninguna viaja en este repositorio: la aplicación descarga la primera y un administrador carga la segunda. El archivo de metas 2026 reformula las rutas y las metas nacionales de las Orientaciones Técnicas.

Los avisos del código de terceros incluido en los paquetes (Vue, Chart.js, las bibliotecas de Nextcloud) están en `js/estadistica-main.js.LICENSE.txt` y `js/estadistica-admin.js.LICENSE.txt`, que la compilación escribe a su lado. Los avisos y licencias de la organización viven en [Aviso y licencias](https://aps-conecta.github.io/documentation/aviso.html).
