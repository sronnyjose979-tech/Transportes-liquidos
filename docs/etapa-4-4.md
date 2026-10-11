# Etapa 4.4 — Integración real y pruebas completas del módulo documental

Base: `772ccfc`. Sin dependencias, cambios de esquema, contratos nuevos, datos
reales, cambios de `.env`, commit ni push. No se inició Transporte y Despacho.

## Resultado y correcciones

No se detectaron defectos funcionales del módulo que requirieran modificar código
de producción o reglas de negocio. Se cerró la limitación de 4.3 mediante pruebas
reales de Vue → Laravel → sesión/CSRF → PostgreSQL 18 → filesystem privado.

La suite preserva los bytes reales de los archivos al inyectar fallos de transporte,
y coordina la caducidad de sesión después de acabar las consultas que renuevan su
actividad. Los accesos directos al almacenamiento deben ser denegados (403 o 404),
sin confundir una respuesta de denegación válida con exposición de archivos.

## Archivos

Creados:

- `tests/E2E/bootstrap.php`: aislamiento, configuración temporal, identidad de base
  y reloj de dominio de pruebas; no carga el `.env` de desarrollo.
- `tests/E2E/console.php`: preparación sintética, inspección SQL y hashes, caducidad,
  bloqueo de concurrencia, rechazo de persistencia y recuperación manual.
- `tests/E2E/server.php`: kernel y rutas reales de Laravel, más assets compilados.
- `tests/E2E/network.py`: proxy transparente localhost para fallos de transporte.
- `tests/E2E/test_document_module.py`: 14 pruebas automatizadas reales en Chromium.
- `docs/etapa-4-4.md`.

Modificados:

- `tests/run-postgres.sh`: opción `--e2e`, manteniendo la ejecución de PHPUnit.
- `.gitignore`: excluye caché Python E2E.
- `ARCHITECTURE.md`: diseño, garantías, cobertura y límites de la integración real.
- `docs/etapa-4-3.md`: referencia a la cobertura real añadida en esta etapa.

## Ejecución reproducible

Se reutilizan las herramientas instaladas; no hay instalación automática.

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
npm run typecheck
npm run build
npm run test:frontend
bash tests/run-postgres.sh --e2e
vendor/bin/pint --test
git diff --check
```

`source` corresponde al entorno actual; en otra máquina usar PHP compatible,
PostgreSQL 18 (o `POSTGRES_TEST_BIN`), Node compatible con Vite 8 y Python con
Playwright y `/usr/bin/chromium` ya instalados. No ejecutar con Vite dev activo.
La suite E2E requiere Linux para los workers PHP y el socket PostgreSQL del runner.

Cada ejecución E2E crea su propio clúster PostgreSQL, sin TCP, base `transporte_test`
y usuario `transport_test`. Antes de migrar y en cada proceso Laravel se verifica
el destino/versiones. Solo queda habilitada esa conexión. Se genera una clave
efímera, se ignora `.env`/caches del desarrollo y se separan disco, logs, cache,
vistas y sesiones (estas últimas en PostgreSQL). Se inicializan cuatro cuentas
sintéticas, sin modificar credenciales reales. Los fixtures se crean por servicios
existentes y consultas autorizadas únicamente en el clúster temporal.

Servidor, proxy, workers y navegador se cierran al terminar y PostgreSQL se detiene
por el trap del runner. Se conservan logs, capturas y trazas ZIP en
`/tmp/transporte-tests-*/e2e/browser/`. No hay endpoints de instrumentación ni hooks
E2E en el código de producción. El comando puede repetirse con otro clúster limpio.

## Cobertura real

| Área | Evidencia |
| --- | --- |
| Sesión y roles | Acceso/cierre con cookies y sesiones PostgreSQL reales; administrador y secretaria; anónimos 401; conductor/mecánico 403; escrituras de tipos/flota denegadas a secretaria. |
| Tipos | Creación administrativa desde Vue; consulta de secretaria; cambio de nombre y vencimiento sin reinterpretar historial. |
| Cargas | PDF/JPG/PNG en cabezal/cisterna; archivo exactamente 10.000.000 bytes guardado con hash correcto; contenido inválido y mayor tamaño rechazados con 422. |
| Fechas | Emisión posterior a vencimiento e imposible 30 de febrero producen 422. |
| Vigencia | +15 días próximo, +16 vigente, día completo de vencimiento válido; medianoche Costa Rica cambia a vencido; sin vencimiento y requisitos faltantes. |
| Renovación/historial | Nuevo documento actual único, filas/snapshots previos idénticos; historial muestra requisitos congelados; descarga binaria coincide con fixture. |
| Privacidad | Sin storage_key público, descarga denegada sin sesión/rol; accesos directos a rutas de archivos rechazados. |
| CSRF | Middleware real: petición sin origen/token válido 419; Vue conserva entradas y permite refrescar CSRF explícitamente; no reenvío automático. |
| Sesión expirada | last_activity envejecido con lecturas ya finalizadas; 401 real elimina contenido privado y exige nuevo acceso. |
| Recurso ausente | UUID inexistente 404 real, incluido aviso de descarga en Vue. |
| Interrupción | Navegador sin conexión; estado PostgreSQL inspeccionado; sin reintentos. |
| Respuesta perdida | Proxy cierra solo tras 201 real de renovación; SQL confirma commit/único actual; archivo histórico intacto; actualizar muestra historial confirmado. |
| Concurrencia | Dos renovaciones HTTP independientes en workers distintos; barrera de lock, referencia anterior visible durante espera; una referencia y tres versiones/archivos tras commit. |
| Rollback/recuperación | Rechazo SQL desde servicio con filesystem real, estado anterior preservado; recuperación manual repetible conserva histórico y elimina solo huérfano sin referencia. |

La suite real no simula respuestas API, no usa SQLite ni `Storage::fake`. El proxy
transmite peticiones completas y respuestas Laravel originales. En pruebas puntuales
retira metadatos de origen o solicita un UUID inexistente para obtener errores
reales; al probar respuesta perdida descarta bytes después de confirmar el 201,
sin generar JSON alternativo. El resto de la suite usa conexión normal al backend.

Se reutiliza PHPUnit para probar restricciones/serialización SQL con conexiones
independientes, failovers de almacenamiento/persistencia, rollback, conservación
de snapshots y recuperación diferida mientras hay transacciones activas. Algunas
pruebas de filesystem de esa suite usan `Storage::fake`; se reportan separadas del
disco real E2E.

## Resultados finales

Herramientas existentes: PHP 8.4.24, Laravel 13.35.0, PostgreSQL 18.3,
Node 24.19.0 y Chromium/Playwright del entorno.

| Suite/verificación | Resultado real |
| --- | --- |
| `bash tests/run-postgres.sh` | 96 pruebas correctas, 1.113 aserciones, PostgreSQL 18 temporal. |
| `npm run test:frontend` | 14 pruebas correctas **con API simulada**; regresión de UI de 4.3. |
| `bash tests/run-postgres.sh --e2e` | 14 pruebas correctas **con API/PostgreSQL/filesystem reales**, 70,195 segundos. |
| `npm run typecheck` | Correcto. |
| `npm run build` | Correcto; aviso previo de fuente opcional fontaine, sin instalarla. |
| `vendor/bin/pint --test` | Correcto. |
| `git diff --check` | Correcto; comprobados también los archivos nuevos. |
| `bash -n tests/run-postgres.sh` | Correcto. |

Clúster PHPUnit final: `/tmp/transporte-tests-9KMAgB`.
Clúster E2E final: `/tmp/transporte-tests-FdqbHi`.
Capturas y trazas E2E: `/tmp/transporte-tests-FdqbHi/e2e/browser/`.
Capturas de UI simulada: `/tmp/transporte-web-tests-j8e38lye`.
Los dos clústeres fueron detenidos al terminar. Se verificó que dependencias,
migraciones y código productivo de app/resources/routes/config permanecen iguales
al commit base. No se ejecutó nada contra `transporte_db` ni se modificó `.env`.

## Límites pendientes

- Validación local con Chromium, HTTP y filesystem compartido; no prueba el TLS,
  proxy o despliegue productivo ni Firefox/Safari. No implica despliegue.
- Se controla únicamente el reloj de dominio para las fronteras de calendario.
  Sesión y timestamps de registro siguen siendo reales; no se modifica reloj del
  sistema ni PostgreSQL.
- Las pruebas de UI simulada de 4.3 se conservan como regresión independiente y no
  se presentan como E2E real.
- No se añade idempotencia: tras pérdida de respuesta hay que consultar antes de
  reenviar. Un reenvío deliberado puede registrar otra versión válida.
- Siguen vigentes las restricciones de despliegue 4.2: historial previo no inferible,
  almacenamiento compartido y recuperación coherente de base/archivos.
- Sin frontend offline, notificaciones, tareas programadas ni funciones de despacho.
