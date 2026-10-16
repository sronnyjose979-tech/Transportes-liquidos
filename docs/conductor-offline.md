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

## Pulido móvil y estados del conductor

Base de esta entrega: `0d8544e`. Cambios limitados a `System/DriverTrips.vue`,
la integración del conductor en `System/SystemApp.vue` y sus pruebas. Se conservan
IndexedDB, Web Locks, claves idempotentes, comprobantes, contexto de asignaciones,
service worker y contratos del servidor. No hay migraciones ni dependencias nuevas.

Problemas corregidos:

- Guardar un formulario borraba también borradores de los otros. Ahora, después
  de confirmar la escritura local, solo se limpian los campos de esa operación.
  Un reporte no borra kilometraje ni gastos/comprobantes sin enviar; los dos inputs
  de comprobantes tienen generaciones independientes. Si falla IndexedDB se mantienen
  los datos introducidos, sin anunciar que se guardaron.
- La presencia de una captura sincronizada antigua disparaba una confirmación verde
  durante un intento nuevo fallido. Ahora se cuentan únicamente las capturas que
  recibieron confirmación en el intento actual; se elimina el mensaje anterior al
  iniciar otro intento/captura. La persistencia local y la confirmación del servidor
  siguen siendo estados diferentes.
- Se distinguen conexión, pendientes, enviando, sincronizadas, conflictos y rechazos
  con texto, recuentos y señales visuales. Sin conexión se explica que hay que recuperar
  conexión y mantener abierta la aplicación. El botón manual no se ofrece como acción
  efectiva cuando no hay conexión o solo hay conflictos/rechazos. Estos permanecen
  disponibles para descargar y revisar con oficina, sin editar ni reenviar sus datos.
- Los datos técnicos de cada captura se consultan mediante un desplegable. Textos
  largos, controles de fecha/archivo y paginación se ajustan a anchos pequeños, sin
  ocultar contenido con un recorte horizontal global.
- El aviso antes de salir incluye capturas con error/conflicto y aclara que no es
  obligatorio sincronizarlas primero. Sin conexión, la salida responde inmediatamente
  con una explicación y libera el botón; **no afirma que cerró la sesión del servidor**.
  Se puede cerrar la aplicación conservando sus datos y volver con conexión. La salida
  efectiva y el cambio de cuenta requieren conexión y conservan la coordinación de
  sesión existente. Un cambio desde otra pestaña informa que las capturas se conservan
  para la cuenta original.

### Verificación de esta entrega

| Prueba ejecutada | Resultado |
| --- | --- |
| Portal, IndexedDB/Web Locks y sistema, Chromium con HTTP sintético | 19 pruebas correctas: 7 de portal, 8 de almacenamiento y 4 de sistema |
| Pantallas pequeñas | 320/360/390 px con ruta/textos largos, JSON desplegado, comprobante seleccionado y borradores conservados; sin desbordamiento horizontal |
| E2E PWA, Chromium persistente + Laravel + PostgreSQL 18 aislado | 3 escenarios correctos: reinicio offline con comprobante/sincronización; conflictos/actualización del SW; expiración/cambio de cuenta/recuperación, este último a 320 px |
| TypeScript, build y diff check | Correctos; se conserva el aviso previo de importación estática/dinámica de `DriverPortal/local.ts` |

Los tests nuevos cubren borradores de otros formularios y archivos, fallo de cuota,
estado enviando sin éxito prematuro, conflicto después de un envío anterior confirmado,
aviso de salida offline y el reintento existente con la misma clave. El primer E2E
no arrancó por falta de espacio en `/tmp`; se trasladaron clústeres sintéticos anteriores,
comprobados como detenidos, al volumen de trabajo, conservándolos. Una vez arrancado,
el caso de reinicio/comprobante pasó; dos selectores quedaron desactualizados por el
desplegable y el mensaje nuevo. Se adaptaron y se repitieron solo esos dos casos,
que pasaron, incluyendo las verificaciones del SW e historial originales.

No se repitieron suites backend generales ni se modificó PHP; las comprobaciones
reales del servidor fueron las del E2E aislado. No hubo cambios de `.env`,
`transporte_db` ni configuración del servidor.

### Límites que se conservan

Las dimensiones anteriores son viewports automatizados, **no teléfonos físicos**.
Queda probar teclado/selector de archivos, instalación, suspensión, batería y cuota
con los dispositivos y navegadores de la empresa, especialmente Safari/iOS si se
pretende admitirlos. Sincronizar exige la aplicación abierta y conexión; cerrar la
aplicación no garantiza ejecución en segundo plano. Los borradores sin capturar solo
están en memoria: esta entrega no añade guardado automático ni preservación entre
cuentas. Las capturas confirmadas en IndexedDB se conservan según las reglas anteriores;
borrar el almacenamiento del navegador sigue pudiendo perder pendientes.
