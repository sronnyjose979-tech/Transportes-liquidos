# Auditoría funcional y simulaciones de procesos

Fecha: 17–18 de octubre de 2026; cierre el 18. Alcance: implementación local, navegación y procesos
existentes. No se accedió a `transporte_db`, Oracle Cloud ni infraestructura; no se
modificaron esquema, permisos, reglas de negocio o datos empresariales. No se hace push.

## Método y alcance de la evidencia

Se inspeccionaron rutas, Gates, formularios, coordinadores y documentación del
circuito vigente. El inspector aislado inventarió **249 declaraciones de rutas API**,
con métodos, controlador y middleware: [inventario completo](auditoria-funcional/endpoints.json).
GET/HEAD cuentan como una declaración; rutas parametrizadas, como reportes, pueden
admitir varias operaciones. Las autorizaciones por registro también se comprueban
en Requests, Policies y servicios; la lista de middleware no reemplaza esos controles.

Las simulaciones nuevas están en [test_business_processes.py](../tests/E2E/test_business_processes.py)
y usan Chromium, Vue compilado, sesiones/CSRF reales, Laravel y PostgreSQL 18
temporal por socket privado. `tests/run-postgres.sh` comprueba host, usuario,
base y versión; el bootstrap no carga `.env` ni cachés de la aplicación.
El inspector [functional-inspect.php](../tests/E2E/functional-inspect.php) abre una
transacción `READ ONLY`, limita tablas/campos y nunca devuelve contraseñas o tokens.

En A/B solo las cuentas y roles sintéticos iniciales proceden del bootstrap.
Clientes, productos, sitios, rutas, flota, cuenta de chofer, empleado, solicitud,
viaje, operaciones, factura y pago se crean mediante formularios. C/D declara
vehículo, mecánico y proveedor sintéticos como precondiciones; crea un repuesto
nuevo por interfaz para evitar utilizar saldos heredados de las fixtures.
Las lecturas instrumentales verifican persistencia; no se usan para completar pasos.

Los estados siguientes se refieren al proceso descrito y a esas precondiciones,
no a todos los casos excepcionales de cada módulo. Las suites antiguas que hacen
escrituras directas a la API se consideran evidencia mixta, no un recorrido íntegro
por interfaz. Tampoco equivalen a validación en un celular físico.

## Inventario y matriz funcional

A = administrador; S = Secretaría; M = mecánico; C = chofer. Los roles indicados
son una síntesis de los controles existentes, no una concesión nueva.

| Módulo | Proceso, dependencias y roles | Backend | Frontend | E2E | Estado | Problema o límite |
|---|---|---|---|---|---|---|
| Administración y Personal | Crear/consultar/editar empleado y cuenta, asignar perfil, vincular, activar/desactivar. A; requiere rol existente y vínculo único. | `/api/users`, `/employees`, `/roles`: 10 declaraciones; alta guiada atómica existente. | `/administracion/personal`: alta guiada y formularios independientes, activación desde listado. | B por formularios y lectura DB; alta guiada en regresión Administración. | COMPLETO | El alcance incluye revocación de sesión y rechazo de login tras desactivar; no administración de un catálogo arbitrario de permisos. |
| Catálogos operativos | Crear/consultar/editar clientes, productos, sitios, rutas, repuestos y proveedores. A; ruta depende de sitios. | `/api/catalogs/*`: 24 declaraciones; ruta POST/PATCH, otros POST/PUT. | `/administracion/catalogos`; `CatalogPanel`, `RoutesPanel`. | A crea sitios/ruta desde catálogo vacío, recarga y uso por S. | COMPLETO | P2: accesos poco descubribles, corregidos. Ruta usada conserva historia y se consulta sin editar. |
| Flota y documentación | Cabezal/cisterna/compartimentos (A); tipos (A); documentos, renovación, privados y alertas (A/S). | 8 rutas de activos; 12 documentales y 3 de alertas. | Flota, Documentación y Vencimientos; formularios y descarga privada. | A crea flota; regresiones Documentación/Alertas verifican el ciclo documental. | PARCIAL | A usa activos sin tipos documentales obligatorios iniciales; la habilitación con todos los requisitos empresariales concretos no se presupone. |
| Transporte y despacho | Solicitud, programación, recursos, disponibilidad, estados, carga/descarga, kilometraje y autorizaciones. A/S; decisiones excepcionales A. | `/api/transport/*`: 44 declaraciones, incluye portal; validación por asignación e historia. | `/transporte`, formularios de solicitud, viaje, recursos, operaciones, estados e historia. | A, desde catálogo hasta fin/cierre; regresiones cubren conflictos y respuesta perdida. | COMPLETO | Completo para viaje sin diferencia de volumen; excepciones requieren autorizaciones exactas existentes. No implica aceptación comercial/fiscal. |
| Portal del chofer | Consultar viajes propios, kilometraje, reportes, gastos y comprobantes; captura y sincronización offline. C/A; cuenta, empleado y asignación válidos. | `my-trips`, `my-records`, registros y gastos limitados al actor. | `/mis-viajes`, IndexedDB, cola y acciones móviles existentes. | A usa perfil de navegador separado, captura offline y sincroniza; regresiones PWA cubren reapertura y conflictos. | COMPLETO | El chofer informa; no inicia/finaliza/cierra oficialmente. Operaciones locales pendientes no son confirmaciones. |
| Taller | Problema, orden, asignación, diagnóstico, alcance, decisión/aplicación, materiales, actividad, finalización y revisión. A/S/M según participación y acción. | `/api/workshop/*`: 108 declaraciones; hechos/rectificaciones y operaciones de ingreso/salida. | `/taller`; formularios especializados y pestañas por orden. | C/D nuevo por formularios; regresiones de ingresos/salidas y rectificaciones. | COMPLETO | C/D probado de problema a cierre; precondiciones maestras declaradas. Ingresos/salidas y rectificaciones se verifican aparte, sin certificar todos sus casos excepcionales. |
| Inventario | Conteo inicial, decisión/aplicación, stock, movimientos, custodia y consumo. A/S; decisiones A; M solo custodia/uso permitido. | `/api/inventory/*`: 8 declaraciones; movimientos también nacen de recepciones y Taller. | `/inventario`, Materiales de Taller. | C/D nuevo; circuito anterior mixto de recepción, entrega, uso y devolución. | COMPLETO | C/D confirma cuatro movimientos y saldo bodega 0, custodia 0, consumo 2. Custodia histórica y reentrega tras devolución siguen pendientes de definición. |
| Compras | Solicitud, decisión, orden, recepción física e historial. A/S; aprobación A; proveedor/repuesto/necesidad opcional. | `/api/purchasing/*`: 9 declaraciones. | `/compras`; el circuito operativo permite crear solicitudes. `/administracion/compras` conserva otro formulario existente. | C/D nuevo; regresión de recepciones parciales y respuesta perdida. | COMPLETO | C/D recorre solicitud, decisión, orden y recepción por UI. Solo la recepción aumenta stock; reversión física y valoración quedan excluidas. |
| Facturación y pagos | Proponer/aprobar importe, factura interna, consulta y pago parcial/total. A/S; aprobar importe A; viaje elegible e importe aprobado. | `/api/billing/*`: 7 declaraciones; exclusividad por viaje e idempotencia. | `/facturacion`, importes, selección de viajes y Facturas y pagos. | A factura su propio viaje y registra pago completo por formularios; regresión de pagos parciales. | COMPLETO | Solo facturación administrativa interna, sin documento fiscal ni impuestos. |
| Gastos | Registrar/consultar/corregir/decidir y adjuntar/descargar comprobantes. A/S oficina; C registros propios permitidos. | `/api/expenses*`: 7 declaraciones. | `/gastos` y formularios del chofer. | A crea gasto offline y lo consulta en oficina; regresión cubre aprobación/corrección y comprobantes. | PARCIAL | No se simula un reembolso: aprobar no paga. A no recorre todas las decisiones/correcciones. |
| Reportes y auditoría | Consultar filtros, paginación, detalle y fuentes autorizadas; A. | `/api/reports/{kind}` y `/api/audit*`: 1 + 2 declaraciones. | `/reportes`, `/auditoria`; consultas de solo lectura. | A consulta transporte de su cliente; regresiones de reportes y auditoría. | PARCIAL | A no verifica todos los tipos de reporte ni todas las fuentes históricas por interfaz. |
| Autenticación transversal | Login, sesión, logout, navegación y dashboard; permisos según actor. | 6 declaraciones básicas; guard web, CSRF y precondición de identidad existentes. | Login e inicios por rol; barrera entre pestañas. | B y regresión Navegación. | COMPLETO | Se mantiene una sesión compartida por perfil/sitio; para operadores simultáneos se usan perfiles separados. |

## Hallazgos y correcciones

### P2 confirmado: descubrir sitios y rutas

El backend y la interfaz ya tenían la función: A → Catálogos → selector Catálogo →
Sitios operativos/Rutas de transporte. El formulario de solicitud decía que las
rutas debían existir, pero no orientaba al usuario hacia su creación. No se encontró
un permiso faltante ni se creó otro endpoint.

Corrección acotada: cuatro accesos visibles para preparar transporte; selección
del catálogo conservada en `?catalogo=...`; desde Rutas se puede ir a registrar
sitios y se explica el estado vacío. Transporte ofrece al administrador enlaces
a sitios/rutas; Secretaría recibe la indicación de solicitar esos datos al
administrador. No recibe enlaces de escritura ni permisos nuevos. Se reutilizan
los formularios, Requests y transacciones existentes. No se modificó Mecánico ni PWA.

### Límites que requieren decisión del propietario

- **PENDIENTE DE DEFINICIÓN:** edición de rutas con solicitudes asociadas. Se mantiene
  el bloqueo y la conservación histórica; no se habilita una edición silenciosa.
- **NO IMPLEMENTADO dentro del alcance fiscal:** emisión electrónica, impuestos,
  numeración legal y notas fiscales; requieren definición normativa. El viaje de A
  concluye en factura interna y pago administrativo. Véase
  [límites de Facturación](facturacion-administrativa-y-pagos.md#límites-y-decisiones-pendientes-fuera-del-circuito-aprobado).
- **PENDIENTE DE DEFINICIÓN:** anulación/corrección posterior a facturación y reversión
  de pagos, cobro de cancelaciones, anticipos y crédito. No hay botones ficticios.
- **PENDIENTE DE DEFINICIÓN:** custodia histórica, cuota de reentrega, reversión de
  recepciones, devolución a proveedor y sobre-recepción. Véase
  [límites de Inventario](inventario-compras-operativo.md#límites-y-decisiones-que-siguen-pendientes).
- **NO IMPLEMENTADO:** valoración monetaria de compras/stock y desembolso/reembolso
  de gastos. No se confunden compras con facturas de clientes ni gastos con pagos.

Estas decisiones no se sustituyen por reglas inventadas durante las simulaciones.
La matriz no declara completos los procesos excluidos.

## Pendientes ordenados por prioridad

1. **P0/P1:** no se confirmaron problemas de seguridad, corrupción o bloqueo esencial
   en los recorridos ejecutados. Esto limita la conclusión al alcance probado.
   No se justificó modificar transacciones, esquema, roles o permisos.
2. **P2 corregido:** descubrimiento de sitios/rutas y dependencia oculta en la
   solicitud de transporte. Validado con catálogo inicialmente vacío y con A/S.
3. **P2 pendiente:** al mecánico sin catálogo administrativo, la entrega recibida
   puede mostrarse con etiqueta genérica «Entrega», cantidad y fecha. La selección
   autorizada y el consumo funcionan. Mejorar identificación dentro de los datos
   permitidos por su orden, sin abrir el catálogo general; no se modificó Mecánico.
4. **P2 de cobertura:** las excepciones históricas y cada variante de los 249
   endpoints no tienen aquí un nuevo recorrido íntegro por interfaz. Los casos
   mixtos existentes se identifican como regresión; no completan por sí solos una
   certificación funcional de todos esos procedimientos.
5. **P3:** hay dos entradas implementadas de solicitudes de compra, la operativa en
   `/compras` y la administrativa en `/administracion/compras`. El circuito principal
   funciona desde Compras; no se hizo una consolidación ajena al problema prioritario.

Antes de utilizar el sistema para emisión fiscal, reversos físicos/financieros,
valoración o reembolsos, el propietario debe definir los contratos enumerados en
la sección anterior y autorizar su implementación. No se interpretan como funciones
ya aprobadas por el hecho de necesitarlas en una operación empresarial más amplia.

## Evidencia y verificaciones

Resultados finales comprobados en los logs existentes; no se repitieron las suites
aprobadas para cerrar este informe. Las ejecuciones focalizadas y reintentos de
construcción de las simulaciones no se suman de nuevo.

| Comprobación | Comando ejecutado | Aprobadas | Fallidas | Omitidas | Resultado |
| --- | --- | ---: | ---: | ---: | --- |
| Backend focalizado | `bash tests/run-postgres.sh --filter 'RouteAdministration\|AdministrationIntegrationTest\|CatalogPrivilegeRevocationTest\|TripAssignment'` | 28 | 0 | 0 | Aprobado; 351 aserciones |
| Frontend completo | `pnpm run test:frontend` | 139 | 0 | 0 | Aprobado |
| Contratos frontend/API | `pnpm run test:frontend-api` | 35 | 0 | 0 | Aprobado |
| Regresión E2E, 15 suites | `bash tests/run-postgres.sh <opción>`; opciones abajo | 57 | 0 | 0 | Aprobado |
| Simulaciones A, B y C/D | `bash tests/run-postgres.sh --e2e-processes` | 3 | 0 | 0 | Aprobado |
| TypeScript | `pnpm run typecheck` | — | — | — | Aprobado |
| Compilación | `pnpm run build` | — | — | — | Aprobado; aviso no bloqueante de imports de DriverPortal/local.ts |

**Total: 262 pruebas distintas aprobadas; 0 fallidas y 0 omitidas en estas
ejecuciones finales.** No equivale a ejecutar de nuevo toda la suite backend ni a
certificar cada variante funcional de los 249 endpoints.

Opciones E2E ejecutadas: `--e2e-administration`, `--e2e-navigation`,
`--e2e-driver-offline`, `--e2e-transport`, `--e2e-workshop`,
`--e2e-workshop-execution`, `--e2e-workshop-circuit`, `--e2e-billing`,
`--e2e-purchase-requests`, `--e2e-supply`, `--e2e-expenses-driver`,
`--e2e-document-alerts`, `--e2e-reports`, `--e2e-audit` y `--e2e`.

Los comandos de navegador utilizaron las herramientas ya instaladas con estas
variables por proceso (no modifican `.env`):

```sh
export PLAYWRIGHT_PYTHON=/tmp/transport-qa/venv/bin/python
export CHROMIUM_EXECUTABLE=/tmp/transport-qa/browsers/chromium-1243/chrome-linux64/chrome
export LD_LIBRARY_PATH=/tmp/transport-qa/runtime/usr/lib/x86_64-linux-gnu
export FONTCONFIG_FILE=/tmp/transport-qa/fonts.conf
```

Logs locales: `/tmp/transport-qa/process-backend.log`,
`process-frontend-all.log`, `process-contracts.log`, `process-types.log`,
`process-build.log`, `process-simulations-final.log` y
`/tmp/transport-qa/process-regression-results/` (logs por suite y `summary.json`).
Son artefactos temporales, no archivos versionados ni evidencia durable tras limpiar
`/tmp`. Las nuevas simulaciones finalizaron en 99,221 segundos; frontend completo,
en 318,691 segundos.

Capturas, trazas y comprobaciones de registros sintéticos de A/B y C/D:
`/tmp/transporte-tests-phV7bX/e2e/browser/OfficeProcessesE2E/` y
`/tmp/transporte-tests-phV7bX/e2e/browser/WorkshopPurchaseProcessesE2E/`.
Incluyen captura móvil, snapshots de lectura y movimientos/saldos de inventario.
Los procesos PostgreSQL temporales se detuvieron al finalizar cada ejecución.
No se modificaron datos reales, `.env`, infraestructura, migraciones ni permisos.

Los primeros intentos de construir las simulaciones fallaron por selectores que
no coincidían con los textos reales, una tabla ambigua, confirmaciones nativas no
aceptadas, expectativas HTTP 201 frente al contrato 200 y búsqueda de una entrega
por un código de catálogo no disponible para el mecánico. Se ajustaron las pruebas
al comportamiento autorizado. Además, el harness compartido colisionaba al crear
la carpeta de artefactos para dos clases: ahora separa carpetas y logs por clase.
Estos fallos iniciales no se ocultan ni se contabilizan como defectos resueltos del
negocio; los recorridos finales completos pasan. La etiqueta genérica de entrega
queda documentada como P2 pendiente.

Administrador registra sitios y rutas mediante botones visibles de Catálogos y
el acceso desde Transporte; se comprobó con datos inicialmente vacíos y recarga.
Secretaría recibe una indicación visible para solicitar el registro al administrador,
sin enlaces administrativos ni permisos nuevos. Es una orientación de interfaz;
no se implementó un flujo nuevo de tickets o solicitudes de alta de rutas.

Se entrega un commit local de esta auditoría, sin push. Las funcionalidades parciales
y pendientes de definición de la matriz siguen abiertas; no se declaran aprobadas
por el éxito de las pruebas de los circuitos definidos.
