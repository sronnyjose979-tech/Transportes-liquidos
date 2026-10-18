# Primera entrega — Administración de Flota, Personal y Catálogos

Interfaz Vue 3 + TypeScript sobre las APIs vigentes, sin migraciones, endpoints JSON
nuevos, dependencias o cambios de autenticación/permisos. Identidad azul y blanco.

## Pantallas

- `/administracion/flota`: listado, búsqueda global por código, paginación, alta,
  consulta y edición de cabezales/cisternas. Compartimentos al crear; después solo
  códigos/capacidades, conforme a FleetAssetRequest. Estado de servicio de lectura.
- `/administracion/personal`: cuentas, roles existentes, contraseña, activación
  independiente, empleados y vínculo de cuenta mediante selector con búsqueda.
- `/administracion/catalogos`: clientes, productos líquidos y sitios operativos,
  consulta/edición, búsqueda y paginación. Solo campos permitidos en cada catálogo.

Las páginas solo entregan contenedor público sin datos privados; sesión y rol se
consultan mediante el cliente existente. Solo administrator accede a datos/acciones.
Las APIs mantienen Gates, validaciones, locks y restricciones. Se reutilizan getSession,
logout, refreshCsrf, ApiError, FieldError y SearchSelect; no se crean credenciales ni login
alternativo. El acceso existente de Taller sirve para ingresar; luego aparecen enlaces
administrativos desde Taller, Transporte y Documentación exclusivamente para administrador.

Código UI en `resources/js/modules/Administration/`, montaje en resources/js/app.ts,
Blade administration.blade.php y rutas web acotadas a las tres secciones. La capa UI
coordina llamadas HTTP; reglas de negocio permanecen en los módulos actuales.

## Flujo de uso

1. Iniciar sesión con una cuenta administrator existente en `/taller`.
2. Abrir Administración y registrar cabezal. Crear cisterna con configuración real de
   divisiones y compartimentos; si no hay divisiones, un compartimento lógico.
3. En Personal/Cuentas, crear cuenta activa con rol Chofer o Mecánico. En Empleados,
   crear empleado activo y seleccionar la cuenta disponible. Son dos escrituras reales
   independientes: crear la cuenta no crea ni confirma automáticamente al empleado.
4. Registrar cliente, producto activo y sitios con direcciones en Catálogos.
5. Abrir Transporte/Documentación/Taller mediante los enlaces. Se consultan recursos
   confirmados por API en cada módulo. En otra pestaña ya abierta, actualizar los datos;
   no se implementa sincronización en tiempo real.

Formularios conservan datos ante 422, muestran errores, impiden doble envío y solicitan
confirmación. Antes de escribir se reconsulta la sesión; revocación sigue validándose en
backend. Respuesta perdida/409/5xx bloquea nuevas escrituras hasta consultar el estado.
No reintento automático, éxito optimista, localStorage o IndexedDB. No se modifica historia
ni se ofrecen eliminación, desbloqueo de vehículos o cambios de estructura no soportados.

## APIs existentes utilizadas

GET/POST/PATCH `/api/tractor-units` y `/api/tankers` con detalle por ID;
GET/POST/PATCH `/api/users`, `/api/employees`, GET `/api/roles` y
PATCH `/api/users/{id}/activation`; GET/POST/PUT `/api/catalogs/customers`,
`liquid-products`, `operation-sites` y sus detalles por ID.

La búsqueda recorre las páginas permitidas (100 por solicitud), filtra todos los recursos
cargados y presenta páginas locales. No se finge que el backend ofrece búsqueda por nombre.
Con volúmenes elevados conviene revisar un contrato de búsqueda servidor; no se añade ahora.

## Administración de rutas

En Catálogos, seleccionar Rutas de transporte. Registrar sitios primero y crear la ruta
mediante selectores reales con búsqueda de origen/destino. El administrador administra;
la secretaria utiliza el lookup vigente al crear solicitudes. No se crean rutas por registrar sitios.

API nueva: GET/POST `/api/catalogs/routes`, GET/PATCH `/api/catalogs/routes/{id}`.
Nombre y FK a sitios existentes; PATCH exige `expected` con los tres valores originales
para detectar edición obsoleta bajo el coordinador transaccional de Transporte. Auditoría
administrativa en logs con actor real y antes/después, como el patrón existente de Personal.
No se implementan eliminaciones ni se imponen origen≠destino o unicidad de pares no aprobada.

La restricción PostgreSQL existente `transport_preserve_used_route` impide modificar/eliminar
rutas con solicitudes, incluso por SQL directo. La API devuelve 409 y la UI las ofrece en
consulta. Se mantienen solicitudes/viajes anteriores; no se modifican sus contratos.
D09 sigue pendiente para edición histórica: definir versionado o sustitución por otra ruta,
conservando las referencias anteriores. Esta entrega no decide esa política.

Otros límites: no crear roles nuevos; no modificar/añadir/eliminar compartimentos o su
activación después del alta; no gestionar Inventario, Compras o datos fiscales inexistentes.
Salvo rutas, que comprueban los valores originales mediante `expected`, las APIs de
administración actuales no exponen ETag/versiones para edición optimista;
la UI respeta sus bloqueos y validaciones, sin afirmar protección adicional contra todas
las ediciones obsoletas entre pestañas. Las altas separadas de cuenta y empleado siguen disponibles. El alta guiada descrita abajo guarda ambos dentro de una transacción conjunta.

## Pruebas reproducibles

Con herramientas aprobadas activadas y sin public/hot:

```sh
source /workspace/.tools/activate.sh
npm run typecheck
npm run build
npm run test:frontend
npm run test:frontend-api
bash tests/run-postgres.sh
bash tests/run-postgres.sh --e2e-administration
vendor/bin/pint --test
git diff --check
```

El modo E2E nuevo usa el runner/bootstrap verificado existente: Chromium real, Laravel,
sesión/CSRF y PostgreSQL 18 temporal, sin API simulada ni .env de desarrollo. Solo usuarios
y recursos sintéticos. Tests nuevos: AdministrationIntegrationTest, AdministrationWebTest
y tests/E2E/test_administration.py. Cubren altas/ediciones, validación, permisos/revocación,
activación, catálogos y presencia de recursos en los selectores reales, pérdida de respuesta
y presentación tablet. Los datos y trazas temporales quedan fuera del repositorio.

Resultados ejecutados:

- TypeScript y build: correctos; conserva el aviso preexistente de fontaine opcional.
- Backend completo PostgreSQL 18: **309 pruebas y 2.873 aserciones correctas**;
  /tmp/transporte-tests-XEQqHn, detenido al terminar. Tras añadir la regresión de comparación
  estricta, la suite afectada pasó nuevamente: **5 pruebas y 79 aserciones**
  (/tmp/transporte-tests-Unklge).
- Frontend Chromium con API simulada: **43 pruebas correctas**, regresiones de los módulos
  existentes y sus nuevos accesos. No se presentan como pruebas E2E reales.
- Contratos frontend existentes: **22 pruebas correctas** (9 API, 7 adjuntos, 6 operaciones).
- Administración E2E real: **3 recorridos correctos** en 47,264 s;
  /tmp/transporte-tests-2AcpmX/e2e/browser. El tercero verifica sitio → ruta → solicitud
  por secretaria y consulta de la ruta histórica sin edición. Incluyen alta/edición de flota, personal/cuentas/roles,
  activación y catálogos, selectores reales de Documentación/Transporte/Taller, errores 422,
  sesión y permisos, pérdida de respuesta confirmada y disposición tablet sin desbordamiento.
- Pint --test y git diff --check: correctos.

No se modifica .env, transporte_db ni los contratos JSON/migraciones existentes.
No se realizó commit ni push.


## Rediseño de experiencia por rol

Diagnóstico: Personal obligaba a coordinar dos altas; el menú plano mezclaba áreas;
Secretaría entraba en Transporte sin accesos orientados a su jornada; el detalle de
Chofer exponía formularios largos y referencias técnicas en primer plano. Se
reutilizaron SearchSelect, FieldError, contexto de permisos y patrones de resúmenes
de Taller. No se cambiaron componentes ni lógica del módulo de Mecánico.

Orden aplicado: alta guiada de Personal y validaciones; navegación por áreas y
accesos autorizados; contexto de listas/detalles en URL; presentación móvil del
conductor. La UI no muestra perfiles ni tareas que la API no ofrece.

### Alta guiada y contrato compatible

En Personal, «Registrar personal con acceso» guía por persona/perfil, credenciales
y revisión. El nombre inicial se envía explícitamente a cuenta y empleado; las dos
entidades conservan identidad independiente y pueden editarse con sus operaciones
existentes. Los formularios independientes siguen disponibles para cuentas sin
empleado y para vincular cuentas existentes.

POST `/api/users` admite opcionalmente:

```json
{"employee":{"name":"Persona sintética","is_active":true}}
```

Es un complemento a los campos de cuenta existentes, no una solicitud completa.
Sin `employee` el contrato anterior no cambia. Solo se admite en creación; no en
PATCH. No acepta user_id, UUID ni atributos adicionales dentro del objeto.
La respuesta conserva el Resource existente, incluido employee_id. No hay rutas,
columnas, migraciones ni permisos nuevos.

Justificación técnica: dos POST consecutivos no garantizan que la cuenta y el
empleado se confirmen juntos. AccountAdministration conserva el lock 873210 y
revalida al administrador. Crea cuenta, roles y empleado dentro de su transacción
existente; un fallo del último paso revierte todo. Los logs administrativos se
programan después del commit y no incluyen contraseñas. Las restricciones vigentes
impiden repetir login/correo y vincular una cuenta a dos empleados.

Esto no añade recibos idempotentes a Personal: ante respuesta perdida la UI bloquea
nuevas escrituras, limpia contraseñas y exige consultar el estado antes de otro alta.
No reenvía automáticamente ni declara éxito sin un vínculo identificable. Se
comprueban rollback real, repetición, campos protegidos y accesos no autorizados.

### Navegación, oficina y celular

El menú lateral de escritorio agrupa solo enlaces devueltos por `/api/navigation`.
Taller conserva su presentación original. El administrador tiene accesos a tareas
junto a los conteos existentes; Secretaría conserva su inicio `/transporte` con
«Mi jornada de oficina». No se le concede `/api/dashboard` ni se inventan métricas.
Personal conserva pestaña, estado y página en la URL; no almacena credenciales ni
formularios en el navegador. Transporte conserva vista/viaje, admite recarga y
Atrás del navegador y bloquea cambio de contexto con operación en curso o incierta.

Chofer conserva sus acciones y etiquetas, con botones táctiles, formularios
separados y accesos a kilometraje, reporte, gasto y comprobantes del viaje abierto.
Las referencias técnicas y ayudas detalladas se consultan bajo demanda. No se
modificaron IndexedDB, UUID, backoff, service worker, permisos, cola ni conflictos.
No se creó ningún registro en la base real para validar este rediseño.

### Verificación del rediseño (17 de octubre de 2026)

- `bash tests/run-postgres.sh`: 407 pruebas PHP aprobadas, 5.301 aserciones.
  Tras completar los mensajes y rechazar objetos de empleado vacíos/incompletos,
  `bash tests/run-postgres.sh --filter 'GuidedPersonnelTest|AccessAdministrationTest|EmployeeAccountLinkTest'`:
  22 aprobadas, 196 aserciones. Cada ejecución creó y detuvo su propio PostgreSQL 18
  temporal, por socket privado; no se utilizó la base de la aplicación.
- `pnpm run test:frontend`: 136 pruebas Chromium aprobadas. Después se añadió
  la persistencia del filtro a Personal y se ejecutaron sus 6 casos, todos aprobados
  (`python -m unittest discover -s tests/Frontend -p test_personnel_guided.py -v`
  con el Python de Playwright). Cobertura consolidada: 137 casos frontend distintos;
  las repeticiones no se suman como casos nuevos.
- `pnpm run test:frontend-api`: 29 contratos Node aprobados.
- E2E real: 55 casos distintos cubiertos mediante `bash tests/run-postgres.sh`
  y sus opciones `--e2e-administration`, `--e2e-navigation`, `--e2e-driver-offline`,
  `--e2e-transport`, `--e2e-workshop`, `--e2e-workshop-execution`,
  `--e2e-workshop-circuit`, `--e2e-billing`, `--e2e-purchase-requests`,
  `--e2e-supply`, `--e2e-expenses-driver`, `--e2e-document-alerts`,
  `--e2e-reports`, `--e2e-audit` y `--e2e`. Incluyen alta guiada con respuesta
  perdida después del commit, permisos, Mecánico y tres casos offline reales.
  En la primera pasada aprobaron 52 y fallaron 3 expectativas de prueba:
  redirección al login tras logout, UUID ahora desplegable y carrera entre consultas
  paralelas que reciben 401 al vencer la sesión. Se adaptaron las expectativas
  conservando las aserciones de seguridad; los 3 casos aprobaron al repetirse
  individualmente. No se presenta la primera pasada como totalmente exitosa.
- `pnpm run typecheck`, `pnpm run build`, Pint sobre los PHP modificados y
  `git diff --check`: correctos. Vite conserva el aviso no bloqueante de importación
  estática/dinámica de `DriverPortal/local.ts`.

Resultado consolidado: 628 casos distintos aprobados (407 PHP, 137 frontend,
29 contratos y 55 E2E); ninguno omitido. Registros de esta ejecución en
`/tmp/transport-qa/ux-*.log` y `/tmp/transport-qa/ux-e2e-results/`; son artefactos
temporales locales. Chromium verificó escritorio y vista móvil, incluidos capturas
y desplazamiento a formularios. No se realizó una prueba manual en un celular físico.
No se instalaron dependencias ni se modificaron infraestructura, datos reales,
migraciones, permisos o implementación de almacenamiento/sincronización offline.
