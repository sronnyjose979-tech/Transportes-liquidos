# Etapa 5.5 — Interfaz web de Transporte y Despacho

Base: main a3e23fdba828f38783af21588ec602ca2d956575. Vue 3 Composition API,
TypeScript estricto, Blade/Vite y Tailwind 4 existentes. Sin nuevas dependencias,
cambios de esquema, endpoints REST o reglas de negocio. No se avanza a 5.6.

## Acceso y pantallas

Ruta web `/transporte`, enlazada desde la navegación interna de documentación de flota;
el módulo ofrece el enlace de regreso. Blade sirve un contenedor público sin datos
privados, con no-store; Vue comprueba la sesión antes de consultar datos operativos.
Las secciones se navegan en memoria, sin Vue Router ni enlaces profundos nuevos.

- Panel general: número real de solicitudes y viajes obtenido de meta.total, accesos
  a los listados y actualización. No hay gráficos, métricas comerciales ni datos ficticios.
- Solicitudes: listado paginado, cliente/producto/ruta existentes, litros y fecha solicitada.
  Crear, editar volumen/fecha con motivo y versión, consultar auditoría y acceder a viajes
  asociados mediante transport_request_id. El producto histórico no es editable.
- Viajes: listado paginado, filtros de ejecución/cierre y de solicitud desde su listado.
  Crear viaje asociado utilizando un selector paginado de solicitudes. La creación se
  confirma primero; la programación es una operación posterior en el detalle, evitando
  simular atomicidad entre dos endpoints independientes.
- Detalle: identificación, producto histórico, cliente/ruta, programación, inicio, fin,
  cancelación, cierre y cantidades registradas. Planificación, ejecución y administración
  se presentan por separado. Secciones: resumen/estados, recursos, carga/descarga,
  kilometraje, autorizaciones e historial/reportes.
- Recursos: seleccionar conductor/cabezal/cisterna, consultar elegibilidad y ocupantes,
  mostrar conflictos, solicitar excepciones, sustituir conductor/cabezal y liberar manualmente.
  Las liberaciones individuales de un viaje activo no se ofrecen como operación válida.
  La cisterna no puede sustituirse. Historia disponible en la sección de historial.
- Carga/descarga: sitios reales, fecha/hora, motivo y litros por compartimento. Selección
  de compartimentos de cisterna asignada, capacidad visible y producto del viaje.
  La descarga pide explícitamente litros de cada detalle cargado, incluido cero; no se
  crean valores cero por defecto. Carga/descarga y sus totales quedan visibles como historia.
- Kilometraje: registrar lecturas por asignación de cabezal, lista paginada, correcciones
  mediante revisiones nuevas y consulta de distancia por tramo. El servidor rechaza
  revisiones ya sustituidas; la API no expone un indicador de lectura actual en ese listado.
- Autorizaciones: motivos, tipo, versión, datos afectados, decisiones y consumo. Administrador
  revisa y aprueba/rechaza con motivo; secretaria solo solicita y consulta. Se muestran
  los datos exactos y el estado aplicado/sin consumir, sin garantizar disponibilidad física.
- Historia: cambios/estados, asignaciones/liberaciones, reportes informativos y advertencias
  con resoluciones. Paginación, fechas y detalle expandible de registros inmutables.

## Integración y permisos

Se consumen exclusivamente rutas de routes/transport.php, documentadas en etapa-5-4.md:
requests, trips, acciones oficiales, assignments, availability, authorizations, load,
unload, odometer/corrections, distance, volume-differences, reports, warnings y lookups.
Sesión/login/logout/CSRF se reutilizan desde la interfaz documental existente.

Administrator y secretary activos usan la interfaz administrativa. Las acciones de
aprobación solo se muestran para administrator. Driver y mechanic no acceden a estas
pantallas administrativas: la captura del conductor mediante PWA todavía está fuera.
Laravel conserva todas las autorizaciones, reglas, integridad y controles concurrentes.
El cliente nunca envía identificadores de actor ni fechas oficiales.

Las condiciones visuales reflejan los datos recibidos: inicio con recursos presentes;
programación/reprogramación de no iniciados; cancelación pendiente/en curso; finalización
con carga/descarga y cobertura D02; cierre de completados/cancelados abiertos. El servidor
vuelve a validar elegibilidad, transiciones y permisos al ejecutar cada operación.
La cobertura visual no certifica por sí sola una finalización válida.

Aprobaciones de diferencias se seleccionan solo si coinciden viaje, versión y contenido
exacto devuelto por volume-differences y no tienen aplicación. En recursos se comparan
acción, tipo/recurso, asignación saliente y ocupantes actuales consultados. El usuario
selecciona las decisiones a consumir; no hay aprobación ni selección automática de excepciones.
Una consulta de disponibilidad no reserva un recurso ni evita su validación transaccional.

## Experiencia, seguridad y errores

Estilos y Pagination/FieldError se reutilizan sin modificar su mecánica documental.
Formularios pequeños por operación, labels únicos, errores accesibles, confirmación
antes de escrituras, botones/fieldset bloqueados durante envío y mensajes de éxito/error.
El bloqueo global y la comprobación previa evitan solicitudes por doble clic dentro del módulo.
Los datos se vuelven a consultar después de las operaciones; no se fabrican estados locales
confirmados antes de recibir y revisar la respuesta.

Fechas mostradas en America/Costa_Rica e inputs explícitos de hora de Costa Rica enviados
con offset -06:00. Litros se envían como cadenas decimales y los totales de detalles usan
aritmética entera de centilitros para conservar precisión; no se calculan pendientes comerciales.
Las cantidades ausentes no se interpretan como cero. Tablas con desplazamiento horizontal
propio, navegación flexible y formularios de dos columnas en escritorio/tablet.

401 limpia pantallas privadas; 403 limpia y vuelve a consultar roles; 404 retira el detalle
no disponible. 422 conserva entradas y presenta errores. 419 ofrece actualización explícita
de CSRF sin reenviar la operación. 409, fallos de conexión y 500 impiden nuevas escrituras
hasta consultar el estado confirmado. No se muestra el cuerpo interno de errores 500.

Perder la respuesta no significa que el servidor haya rechazado el cambio. La recuperación
es una consulta explícita y remonta las vistas con datos actuales, sin repetir el POST.
No se agregan claves de idempotencia ni offline: D10 sigue pendiente. Después de recargar
el navegador también deben revisarse los registros antes de recrear una operación dudosa.

Datos solo en memoria del módulo; se limpian al salir/revocar sesión. Sin localStorage,
IndexedDB, caché offline, archivos guardados en navegador o documentos por URLs públicas.
Los únicos cookies son los de sesión/CSRF existentes. Evidencias y archivos no tienen
formulario de carga en la API 5.4; no se simula esa funcionalidad.

## Archivos principales

resources/js/modules/TransportAndDispatch/:

- TransportApp.vue: sesión, navegación, contexto, bloqueo de escrituras y recuperación.
- contracts.ts, api.ts, presentation.ts, context.ts: contratos/tipos, fetch seguro,
  representación y coordinación de componentes.
- components/RequestBoard.vue, TripBoard.vue, TripDetail.vue: solicitudes, viajes y detalle.
- components/AssignmentPanel.vue, OperationsPanel.vue, OdometerPanel.vue,
  AuthorizationPanel.vue, HistoryList.vue, CommandForm.vue: operaciones y presentación.

Montaje en resources/js/app.ts y resources/views/transport.blade.php; ruta en routes/web.php.
FleetDocumentsApp.vue añade únicamente el enlace interno. No se modifican servicios de
backend, migraciones, contratos REST, dependencias ni funcionalidades documentales.

Tests: TransportWebTest.php verifica el contenedor y privacidad; test_transport.py agrega
pruebas Chromium con respuestas API explícitamente simuladas. test_transport_module.py y
transport-console.php usan un navegador real, Laravel, sesión/CSRF y PostgreSQL 18 temporal.
La instrumentación CLI usa el bootstrap aislado existente, nunca .env de desarrollo.
run-postgres.sh añade --e2e-transport sin cambiar el modo backend o --e2e documental.
FaultProxy permite elegir el status de la respuesta que se pierde; mantiene 201 como
default para las pruebas documentales previas. El proxy no fabrica respuestas de negocio.

## Ejecución y verificación

Con las herramientas existentes activadas y servicios locales configurados, abrir
`/transporte` en Laravel y usar una cuenta administrativa existente. Los catálogos,
rutas, personal y flota deben estar configurados; la interfaz no crea datos iniciales.
Para Vite de desarrollo, `npm run dev`; para compilar y validar:

```sh
source /workspace/.tools/activate.sh
npm run typecheck
npm run build
npm run test:frontend
bash tests/run-postgres.sh
bash tests/run-postgres.sh --e2e-transport
vendor/bin/pint --test
git diff --check
```

Las suites de navegador requieren compilación actual y ningún public/hot. Se reutilizan
Chromium y Playwright ya instalados; no se instalan herramientas. Los modos PostgreSQL
crean clúster/socket/base temporales, los detienen al terminar y conservan trazas en /tmp.
Los registros sintéticos solo se usan en pruebas aisladas, nunca como contenido operativo.

Resultados reales de la ejecución final:

- Backend PostgreSQL 18: **190 pruebas y 1.806 aserciones correctas**; clúster temporal
  /tmp/transporte-tests-A7YnNs, detenido al terminar.
- Frontend Chromium con API explícitamente simulada: **24 pruebas correctas**, 14 documentales
  existentes y 10 del módulo nuevo. Incluye roles, navegación, formularios, conflictos,
  estados, captura, kilometrajes históricos/correcciones, errores y presentación de cantidades ausentes.
- Integración real Chromium/Vue/Laravel/sesión/CSRF/PostgreSQL: **2 pruebas correctas**, sin
  API simulada. Flujo de solicitud→programación→asignación→inicio→carga→descarga→fin→cierre
  y liberaciones manuales; diferencia de litros solicitada por secretaria y decidida por
  administrador con consumo específico. También respuesta de inicio perdida tras commit,
  recuperación sin repetirlo, lecturas de cabezal ya liberado, corrección de viaje cerrado
  y distancia real. Trazas/capturas: /tmp/transporte-tests-LFU7mP/e2e/browser.
- TypeScript, npm run build, Pint --test y git diff --check: correctos. La compilación conserva
  el aviso preexistente sobre fontaine opcional; no impide compilar ni motiva instalar dependencias.

No se ejecutaron migraciones o pruebas sobre transporte_db, ni se modificó .env.
La publicación se autoriza después de estas verificaciones y revisión de alcance/secretos.

## Límites pendientes

- RN-TR-17: no se decide qué hacer con un permiso obligatorio ausente.
- D05: no se sustituye cisterna ni se inventa comprobación de estado vacío.
- Cambio de producto y cancelación posterior de completados siguen fuera de los contratos.
- La API no crea rutas, carga archivos/evidencias ni modifica/agrega detalles a operaciones
  ya registradas; estas funciones no se simulan. Una operación previa incompleta permanece
  bloqueada para finalización y muestra esa limitación. Las descargas nuevas piden toda la
  cobertura de detalles para evitar introducir ese caso desde la interfaz.
- La API no publica un listado global de autorizaciones: la revisión se hace por viaje.
  Tampoco proporciona nombres de todos los usuarios históricos; se muestran los IDs reales.
- Sin facturación, viáticos, gastos, taller, inventario, compras, estadísticas comerciales,
  compensación de odómetros, PWA/offline ni etapa 5.6.

Actualización posterior: [etapa-5-6.md](etapa-5-6.md) resuelve la captura incompleta
mediante adición auditada de detalles omitidos. No habilita edición de cantidades
históricas, cambios de sitio ni correcciones terminales.
