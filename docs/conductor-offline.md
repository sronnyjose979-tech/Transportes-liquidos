# PWA del conductor: implementación pendiente de validación integral

Trabajo sobre `main` actualizado a `2123ae2`. Alcance exclusivo: `/mis-viajes`.
No hay migraciones, cambios de reglas operativas ni modificaciones de `.env`.

## Comportamiento preparado

- Manifest instalable y SW generado por Vite: precarga únicamente archivos públicos
  y un shell vacío. Nunca guarda HTML autenticado, API ni comprobantes privados.
- IndexedDB `driver-portal`, versión 1: almacenes separados `trips` y `captures`.
  Viajes y gastos mínimos consultados por usuario, vigencia local de 24 horas.
  No se guardan historiales, contraseñas, cookies ni tokens de autenticación.
- Reportes, lecturas, gastos y archivos se guardan antes del envío. Gasto nuevo y
  comprobante se guardan en una sola transacción; el archivo depende del ID del
  gasto confirmado. No se libera su contenido antes de confirmar su recepción.
- Estados locales: pendiente, sincronizando, sincronizado, rechazado y en conflicto.
  La captura local no es confirmación del servidor. Un gasto confirmado continúa
  pendiente de aprobación administrativa; aprobar no implica pago ni reembolso.
- Cola por usuario sin caducidad ni borrado automático de capturas. Contenido y
  comprobantes pendientes se pueden descargar para revisión, sin sobrescribirlos.
- Sincronización manual, al reconectar y comprobación cada 15 segundos con la
  aplicación abierta. Espera progresiva hasta 60 segundos y máximo cinco intentos
  automáticos por captura; el botón manual permite reintentar conservando la clave.
  No implementa ni promete sincronización con la aplicación cerrada.
- Web Locks serializa sincronización, login y logout entre pestañas. Se comprueba
  la cuenta antes de enviar. Expirar sesión bloquea el acceso local; autenticarse
  con la cuenta original recupera sus capturas. Logout elimina acceso y caché de
  lectura; conserva capturas ocultas, con aviso al usuario si están pendientes.
- El SW actualizado espera el cierre de las ventanas anteriores. No fuerza recargas
  ni altera IndexedDB. La cuota insuficiente o DB bloqueada se muestra como error;
  no se presenta como guardada una escritura que no terminó.

`DriverPortal/local.ts` contiene almacenamiento y coordinación local sin API;
`offline.ts` coordina los envíos existentes; `pwa-build.mjs` genera el shell público.
Los cambios del cliente se limitan al portal y la integración de sesión necesaria
para impedir cruces de cuenta durante la sincronización.

## Contexto y conflictos del servidor

`AssignedTripResource` añade `offline_context`: hash de versión/estado del viaje y
asignaciones efectivas. El portal guarda ese valor dentro del contenido inmutable
con `expected_trip_context`. Los servicios existentes lo comprueban bajo los
bloqueos transaccionales de Transporte. No cambia las transiciones ni asignaciones.

Reportes, odómetro, gastos y comprobantes aceptan este campo opcional. Los recibos
idempotentes ya existentes se consultan antes del contexto: una respuesta perdida
puede recuperarse incluso tras cambios del viaje. Una operación nueva incompatible
recibe 409; permisos y reglas existentes se validan nuevamente. 403/404/409 quedan
como conflicto, 422 como rechazado. Se preservan los datos para revisión explícita;
no hay resolución automática ni edición del contenido original.

## Entrega provisional para continuar en otro entorno (2026-10-15)

Rama de traslado: `feature/conductor-offline`, basada en `2123ae2`.
**No se declara completada la PWA.** Las últimas ejecuciones se interrumpieron;
queda pendiente consolidar la validación integral y sus resultados definitivos.
No se incluyen herramientas, dependencias instaladas, archivos temporales ni datos.

Resultados confirmados en ejecuciones previas:

- Backend PostgreSQL aislado: 52 pruebas, 576 aserciones, correctas. Después se
  amplió el caso de comprobantes con conflicto de contexto y replay: pasó con
  24 aserciones. No se repitió toda la suite tras ampliar ese caso.
- Chromium con HTTP sintético: ocho pruebas de cola/IndexedDB, dos del portal,
  cinco de gastos y cuatro del sistema correctas. No sustituyen al E2E real.
- Contratos frontend: 23 pruebas correctas. TypeScript, build y Pint pasaron.
- E2E real con Laravel, Chromium y PostgreSQL 18.6: se verificaron captura offline,
  recarga/reapertura y envío de reportes, kilometraje, gastos y comprobantes;
  recuperación de sesión/cuenta; conflictos, actualización del SW y logout.
  Estos casos pasaron en ejecuciones específicas anteriores.

Pendientes concretos para la siguiente conversación:

1. Confirmar la suite E2E offline completa después de añadir las comprobaciones
   de manifest/instalabilidad de Chromium y los últimos ajustes de interfaz.
   La última ejecución no dejó un resultado final recuperable.
2. Reejecutar el caso E2E de respuesta perdida tras el ajuste de su expectativa:
   una operación nueva con contexto anterior a la liberación devuelve **409**;
   la misma clave ya confirmada devuelve el resultado original. La ejecución
   previa falló porque el test esperaba 403; la corrección aún requiere confirmar.
3. Consolidar verificaciones finales pertinentes y revisar el diff antes de integrar.
   No volver a instalar ni repetir análisis si el entorno ya está preparado.

Herramientas utilizadas: PHP 8.4.24, Composer 2.8.12, Node 24 y PostgreSQL 18.6;
Laravel 13 y dependencias se instalaron desde los archivos de bloqueo sin cambios.
El entorno temporal anterior se activaba con `/tmp/driver-tools/activate`; esa
ruta no forma parte del repositorio y puede no existir en la siguiente sesión.

El proxy bloqueaba `fonts.bunny.net`. Para un build sin descargas de fuentes se
incluye `TRANSPORTE_BUILD_OFFLINE=1`, que usa el fallback de sistema existente;
la configuración normal conserva la descarga de fuentes. Se corrigió además la
precarga del SW para generarla después de que Vite retire sus chunks provisionales.
No compilar mientras el test de actualización del SW modifica temporalmente el
archivo generado; este test lo restaura en `finally`.

Comandos de referencia, usando exclusivamente la base temporal del runner:

```bash
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
python3 -m unittest discover -s tests/Frontend -p 'test_driver*.py' -v
python3 -m unittest discover -s tests/Frontend -p test_expenses.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
npm run test:frontend-api
bash tests/run-postgres.sh --filter 'ExpenseApiTest|DriverPortalTest|SystemNavigationTest|TripOperationsTest|TransportApiTest'
timeout --kill-after=10s 180s bash tests/run-postgres.sh --e2e-driver-offline -v
timeout --kill-after=10s 180s bash tests/run-postgres.sh --e2e-expenses-driver -v
vendor/bin/pint --test
git diff --check
```

En el runner E2E no establecer `POSTGRES_TEST_DATA_ROOT`: su bootstrap exige el
clúster dentro del directorio temporal de sockets. Nunca utilizar `.env`,
`transporte_db` ni bases existentes para ejecutar estas verificaciones.

## Límites

Chromium actualizado, HTTPS (o localhost), IndexedDB, Web Locks y almacenamiento
permitidos. Habilitar offline requiere una primera consulta online y que el SW
termine de instalarse. El navegador puede borrar datos si el usuario limpia el
sitio, usa modo privado o elimina almacenamiento; la aplicación no puede garantizar
recuperación de bytes eliminados externamente. IndexedDB no cifra frente a XSS
ni protege contra alguien con acceso al perfil local: usar dispositivos confiables.
