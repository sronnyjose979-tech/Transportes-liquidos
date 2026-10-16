# PWA offline del conductor: validación e integración

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

## Validación e integración (2026-10-16)

Rama revisada: `feature/conductor-offline`, commit de traslado `32d062c`, basada
en `main` `2123ae2`. Se reutilizó el entorno existente sin instalar dependencias,
crear migraciones ni modificar `.env` o `transporte_db`.

Se corrigió la instrumentación E2E: el contexto de incógnito de Playwright impide
instalar una PWA (`in-incognito`). La suite utiliza ahora un perfil persistente
**temporal y aislado**, sin omitir la comprobación de instalabilidad de Chromium.
El caso de persistencia cierra Chromium completamente y lo vuelve a iniciar sin
conexión: conserva el shell, cuatro capturas y el comprobante; al reconectar,
Laravel confirma cada registro una sola vez y se verifica la descarga privada.

| Verificación ejecutada | Resultado |
| --- | --- |
| Backend específico y regresiones de Transporte/navegación, PostgreSQL 18.3 | 52 pruebas, 580 aserciones correctas. |
| Chromium/IndexedDB con API sintética y portal | 10 pruebas correctas. |
| Interfaz de Gastos y sistema, API sintética | 9 pruebas correctas (5 + 4). |
| Contratos frontend existentes | 23 pruebas correctas. |
| E2E offline, Chromium persistente + Laravel + PostgreSQL aislado | 3 pruebas correctas. |
| E2E de Gastos/Conductor y recuperación de respuesta perdida | 4 pruebas correctas. |
| TypeScript, build, Pint y diff check | Correctos. |

Las pruebas sintéticas no sustituyen al E2E real. Este verifica instalabilidad,
recarga/reinicio offline, reconexión automática, gastos y comprobantes, sesión
expirada, cambio de cuenta y recuperación de capturas propias; conflictos por
cancelación/liberación, actualización del SW sin borrar pendientes y logout.
El E2E de respuesta perdida confirma replay de la clave original y conflicto 409
para una captura nueva cuyo contexto ya cambió. Los tests de almacenamiento
cubren serialización entre pestañas, reintentos, espera progresiva y archivos
conservados hasta recibir confirmación.

Entorno validado: PHP 8.4.24 (no PHP 8.5), Laravel 13, PostgreSQL 18.3 y Chromium.
Activación local reutilizada: `/workspace/.tools/activate.sh`; no forma parte del
repositorio. El build verificó `TRANSPORTE_BUILD_OFFLINE=1`, que utiliza fuentes de
sistema sin descargarlas; no debe ejecutarse mientras el test de actualización
del SW modifica temporalmente el archivo generado, restaurado mediante `finally`.

Comandos reproducibles, exclusivamente con la base temporal del runner:

```bash
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
python3 -m unittest discover -s tests/Frontend -p 'test_driver*.py' -v
python3 -m unittest discover -s tests/Frontend -p test_expenses.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
npm run test:frontend-api
bash tests/run-postgres.sh --filter 'ExpenseApiTest|DriverPortalTest|SystemNavigationTest|TripOperationsTest|TransportApiTest'
bash tests/run-postgres.sh --e2e-driver-offline -v
bash tests/run-postgres.sh --e2e-expenses-driver -v
vendor/bin/pint --test
git diff --check
```

En E2E no establecer `POSTGRES_TEST_DATA_ROOT`: el bootstrap exige el clúster en
el directorio temporal de sockets. Los perfiles persistentes son sintéticos y
pertenecen a los artefactos temporales de cada ejecución.

## Límites

Chromium actualizado, HTTPS (o localhost), IndexedDB, Web Locks y almacenamiento
permitidos. Habilitar offline requiere una primera consulta online y que el SW
termine de instalarse. El navegador puede borrar datos si el usuario limpia el
sitio, usa modo privado o elimina almacenamiento; la aplicación no puede garantizar
recuperación de bytes eliminados externamente. IndexedDB no cifra frente a XSS
ni protege contra alguien con acceso al perfil local: usar dispositivos confiables.
