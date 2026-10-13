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
las ediciones obsoletas entre pestañas. Cuenta/empleado no se guardan en transacción conjunta.

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
