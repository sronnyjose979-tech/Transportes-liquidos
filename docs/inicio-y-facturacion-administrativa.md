# Inicio por roles y preparación de facturación

## Alcance implementado

Se reutilizan las sesiones Laravel (`SessionController`), CSRF, cuentas activas,
roles existentes y Gates. No hay nuevas entidades, migraciones ni autenticación.

| Ruta | Acceso | Función |
|---|---|---|
| `/` | Sesión activa | Redirige según permisos: administrador, secretaria, mecánico, conductor, en ese orden si tiene varios roles. |
| `/login` | Público | Inicio de sesión existente; al autenticar consulta el inicio autorizado. |
| `/dashboard` | Administrador | Conteos reales de activos, empleados, solicitudes, viajes, órdenes, documentos y solicitudes de compra. No calcula indicadores financieros. |
| `/transporte` | Administrador / secretaria | Interfaz existente. Inicio de secretaria. |
| `/taller` | Administrador / secretaria / mecánico | Interfaz existente. Inicio de mecánico. |
| `/mis-viajes` | Conductor / administrador | Consulta en línea de asignaciones vigentes; el administrador puede consultar todos. Sin PWA/offline ni transiciones oficiales del conductor. |
| `/facturacion` | Administrador / secretaria | Consulta paginada de viajes y clientes, filtros de cliente y ejecución. No declara elegibilidad comercial ni emite documentos. |

Todas las páginas operativas requieren `auth`, `EnsureActiveAccount` y
`auth.session`, y Gates correspondientes. Invitados se redirigen a `/login`;
APIs devuelven 401. Roles no autorizados reciben 403 en servidor. Los datos y
páginas privadas no se cachean. Una cuenta sin rol habilitado no recibe un
inicio arbitrario. El menú compartido recibe sus enlaces de los mismos Gates.

### Componentes y contratos nuevos

- `SystemNavigation`, `SystemController`, `PrivatePage`: permisos del menú,
  inicio y resumen real. `GET /api/navigation`, `GET /api/dashboard`.
- `AssignedTripController`, `AssignedTripQueryRequest`, `AssignedTripResource`:
  `GET /api/transport/my-trips` y `GET /api/transport/my-trips/{id}`.
  Reutilizan `DriverAssignment::current()` y empleado activo vinculado al
  usuario autenticado. Otros viajes devuelven 404. La liberación retira el acceso.
  La respuesta incluye producto histórico, ruta, activos y fechas; no incluye
  credenciales, documentos privados ni información económica. El detalle solo
  muestra reportes propios del usuario autenticado.
- `BillingReviewController`: `GET /api/billing/trips`, filtros validados y
  paginación; `billing_enabled=false` y `billing_eligibility=null` explícitos.
- Vue `SystemApp`, `SharedNavigation`, `DriverTrips`, `BillingReview`.
  `system-home.blade.php`, Vite y componentes de estilo existentes. Se integra
  el mismo menú en Administración, Taller, Transporte, Documentación y Supply.
  Las operaciones pendientes conservan sus bloqueos de navegación.

## Facturación: circuito pendiente

No existe aún persistencia de facturas/pagos ni precio acordado en `Trip`.
`TransportRequest` no contiene acuerdo comercial ni tarifa. El ER histórico
no autoriza trasladar entidades o reglas directamente a producción.

La instrucción vigente permite pagos parciales y totales y sustituye el
contrato histórico de RN-TR-33 (pago único completo). La implementación debe
respetar esta actualización junto con las reglas comerciales aún pendientes. Ningún
pago parcial o total se registra en esta entrega.

| Definición necesaria | Bloqueo concreto |
|---|---|
| Moneda, escala, rangos y redondeo | No puede definirse el almacenamiento exacto de montos ni calcular saldos sin inventar precisión. CRC de Taller no determina la moneda de facturación. |
| Importe acordado, concepto/unidad y autorización | RN-TR-03 y RN-TR-39: no hay tarifa real ni mecanismo de precio de viaje implementado; no se deducen importes de litros. |
| Unidad facturable y duplicación | Definir si se factura el viaje completo, cantidades parciales u otros conceptos; de ello depende la restricción de exclusividad. |
| Elegibilidad y pendientes (D11), viajes cancelados | Un viaje finalizado no equivale a uno facturable. El cobro de cancelación requiere importe y autorización específica RN-TR-38. |
| Contado/crédito, vencimiento, anticipos y aplicación de pagos | No imponer plazo, vencimiento o tratamiento de anticipos. |
| Métodos y comprobantes de pago | RN-TR-34 prevé catálogo configurable; no se crea un catálogo o requisito de referencia arbitrario. |
| Fiscal: impuestos, numeración, documento legal y emisión electrónica | No generar números fiscales, impuestos ni documentos legales; RN-TR-35/36 y D11 requieren definición normativa. |

No hay botones ficticios de crear factura o registrar pago. No se presentan
saldos cero ni importes estimados como reales. La consulta es una preparación,
no un circuito de facturación completo. No se alteran viajes históricos.

## Verificación

Se ejecutan TypeScript, build, Pint, diff check, suite backend PostgreSQL18,
pruebas frontend y contratos; los resultados reales se registran al finalizar.

Pruebas simuladas de interfaz: `npm run test:frontend` usa Chromium y los
Blade/Vite reales en un servidor aislado que renderiza exclusivamente las
pantallas para interceptar sesiones/APIs, sin BD. Este arnés no verifica
permisos de páginas; esas restricciones se prueban en Laravel/PostgreSQL real.

Pruebas reales: `CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-navigation`.
Clúster PostgreSQL18 temporal, Laravel real y Chromium, sin respuestas API
simuladas. Verifica inicio por cuatro roles, acceso a módulos autorizados,
denegación del servidor, datos reales del conductor, liberación manual,
consulta de facturación, sesión expirada y recuperación de respuesta perdida
de login sin reenviar automáticamente credenciales. Si se pierde también
la cookie de sesión regenerada, la consulta confirma que el navegador no
tiene sesión y se requiere un nuevo inicio manual; no se relaja la regeneración.

No es posible probar duplicación de facturas, pagos parciales o concurrencia
de saldos: no hay operaciones monetarias implementadas y sus reglas siguen
pendientes. No se sustituyen esos casos por pruebas triviales que pasen.

El entorno disponible tiene PHP 8.4.24 compatible con Laravel13; PHP8.5 no
está instalado. No se afirma validación bajo PHP8.5 ni se instalan herramientas.
No se modifica `.env`, `transporte_db`, ni se hace commit/push.

### Resultados ejecutados

| Verificación | Resultado |
|---|---|
| `npm run typecheck` | Aprobada. |
| `npm run build` | Aprobada con Vite8; aviso opcional preexistente de `fontaine`, sin instalar paquetes. |
| `bash tests/run-postgres.sh` | 334 pruebas y 3.237 aserciones aprobadas, 86,809 s. |
| `npm run test:frontend` | 70 pruebas de interfaz con APIs interceptadas aprobadas, 139,299 s. |
| `npm run test:frontend-api` | 23 pruebas de contratos/API frontend aprobadas. |
| `bash tests/run-postgres.sh --e2e-navigation` | 4 E2E reales aprobados, 43,656 s. |
| `bash tests/run-postgres.sh --e2e-supply` | 1 E2E real de regresión aprobado, 58,418 s: compras, recepciones, entrega, uso, devolución e idempotencia, con login central. |
| `vendor/bin/pint --test` | Aprobada. |
| `git diff --check` | Aprobada. |

No se probaron operaciones de facturas/pagos porque no existen todavía. Los
resultados monetarios no se simularon. Las suites E2E de Documentación,
Administración y Transporte tienen sus helpers de acceso adaptados a `/login`;
esta entrega ejecutó las suites reales de Navegación y Supply, además de la
suite backend y frontend completas, sin afirmar la ejecución de todos los
otros modos E2E.

Comprobaciones posteriores al último ajuste de protección de respuestas tardías
al desmontar la consulta de facturación: 6 pruebas backend de navegación con
109 aserciones, 4 pruebas frontend del inicio y 1 E2E real del viaje asignado,
consulta de facturación y liberación manual (10,861 s), aprobadas. Se corrigió
el fixture simulado de clientes para incluir el contrato real completo de
paginación; no se relajó el decodificador de la API. TypeScript, build, Pint y
diff check se repitieron y aprobaron tras los cambios relevantes.
