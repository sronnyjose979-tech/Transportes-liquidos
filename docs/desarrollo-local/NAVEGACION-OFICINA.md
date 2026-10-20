# Navegación persistente de oficina

La extensión parte de `882698b`. Integra 14 destinos en una raíz Vue con Vue
Router, navegación compartida y carga diferida:

| Destino | Componente de contenido |
| --- | --- |
| `/dashboard` | OfficeDashboard |
| `/transporte` | TransportApp |
| `/administracion/flota` | AdminApp: Flota |
| `/administracion/personal` | AdminApp: Personal |
| `/administracion/catalogos` | AdminApp: Catálogos |
| `/administracion/compras` | AdminApp: solicitudes administrativas |
| `/inventario` | SupplyApp: Inventario |
| `/compras` | SupplyApp: Compras |
| `/taller` | WorkshopApp |
| `/facturacion` | BillingApp |
| `/gastos` | ExpensesApp |
| `/alertas` | AlertsApp |
| `/auditoria` | AuditApp |
| `/reportes` | ReportsApp |

Administración de Compras conserva su Gate administrativo; no adquiere los
permisos más amplios de `/compras`. `/mis-viajes` y su PWA permanecen independientes.

## Arquitectura y autorización

- `OfficePage`: representa HTML o contexto JSON después de los middleware y
  Gates existentes. Las rutas Laravel siguen disponibles para URL directa y recarga.
- `office.blade.php`: codifica de forma segura el contexto inicial autorizado.
- `Office/bootstrap.ts`: monta SessionBoundary y OfficeApp una sola vez.
- `Office/context.ts` y `pages.ts`: catálogo de destinos y componentes diferidos.
- `Office/workspace.ts`: autorización del destino, historial, bloqueos y recuperación.
- `Office/useModuleRequests.ts`: cancela lecturas al desmontar componentes. Las
  escrituras conservan su respuesta definitiva o incierta; no se cancelan al salir.

Cada cambio de módulo solicita su ruta con `X-Office-Navigation: 1`,
`Accept: application/json` y la identidad esperada (`X-Session-User`). Se ejecutan
los controles Laravel de autenticación, cuenta activa, sesión y Gate del destino.
El contexto contiene `page`, `session` y `navigation`; nunca concede permisos.
HTML y contexto son privados y no se cachean. No cambian contratos de las API.

Los módulos reutilizan la sesión y navegación recién autorizadas para entrar,
sin repetir `/api/session` o `/api/navigation`. Permanecen las verificaciones por
foco/pestañas de SessionBoundary, las comprobaciones previas a escrituras y las
revalidaciones del backend. No se guardan permisos globales ni entre peticiones.
Los cambios de query dentro del módulo conservan sus API autorizadas; no son
una nueva autorización de acceso a otro módulo.

## Salida, operaciones y fallos

- Los formularios modificados piden confirmación de descarte al salir del módulo.
  Los formularios de consulta usan `data-office-filter`; ciertos controles de
  escritura externos a formularios se identifican con `data-office-dirty`.
- Los bloqueos del módulo impiden salir durante escrituras, resultados inciertos
  y reservas/adjuntos pendientes. También protegen historial y cierre de sesión.
- Supply, Facturación, Gastos, solicitudes administrativas y Taller conservan
  su recuperación en mapas de la instancia de oficina, separados por cuenta.
  Se conserva el cuerpo original, la clave idempotente y, cuando corresponde,
  el File. No hay reintentos automáticos ni persistencia nueva de archivos privados.
- Ante expiración se oculta el módulo. La opción «Volver a comprobar» solicita
  nuevamente su ruta autorizada y permite recuperar operaciones de la misma
  cuenta. Si es necesario autenticarse, puede hacerse en otra pestaña y volver
  a comprobar; un cambio de identidad detectado por SessionBoundary exige recarga.
- Los mapas no sobreviven al cierre o recarga forzada del documento. Se mantiene
  un aviso nativo de salida incluso después de ocultar/desmontar el contenido.
  Este aviso no puede garantizar recuperación ante cierre forzado del navegador.
  Taller conserva además su protocolo existente de recibos y metadatos de reservas.
- Las transiciones canceladas no pueden instalar contextos tardíos. Los emisores
  de componentes anteriores no pueden invalidar otra página/cuenta. Taller
  cancela sus lecturas y limpia su temporizador; las demás lecturas adaptadas
  cancelan solicitudes al desmontarse y conservan sus controles de generación.
- La descarga del destino se prepara antes de abandonar el origen. Si falla,
  se conserva el origen, el historial y el destino con sus parámetros para un
  reintento explícito. No se recarga automáticamente para resolver chunks ausentes.
  Un asset retirado por una futura publicación podría exigir recarga manual;
  antes deben resolverse los pendientes.

## Bloqueo de Documentación

`/documentacion` conserva la entrada tradicional y su autorización. Se excluye
expresamente de la integración segura de esta entrega.

Evidencia: `FleetAndDocuments/components/DocumentUploadForm.vue` envía un archivo
sin clave idempotente; los contratos de carga del backend tampoco ofrecen una
clave/recibo para resolver inequívocamente una respuesta perdida. Un desmontaje
nuevo podría perder el archivo y facilitar repetir una carga ya aplicada.

Resolver primero la recuperación de cargas documentales en un trabajo específico:
acordar identificación de la operación y consulta de resultado, conservar el
archivo y bloquear resultados inciertos. Esto puede requerir un contrato nuevo,
fuera de las restricciones actuales. No se simula una confirmación ni se añaden
reintentos inseguros. Entrar o salir de Documentación aún recarga el documento.

## Incorporación de módulos

Conservar el Gate Laravel y representar la página con OfficePage. Registrar el
path/título en `context.ts` y el import dinámico en `pages.ts`. Consumir el contexto
mediante `useOffice` o el adaptador de props/eventos de OfficeApp; no crear otra
navegación o verificación inicial de sesión. Registrar bloqueos, usar recuperación
por cuenta y limpiar lecturas/listeners/temporizadores. Añadir el destino a las
matrices PHP y de navegador antes de habilitarlo.

## Verificación y tamaño

- 64 pruebas PHP y 1.024 aserciones: rutas directas/contextos, roles, revocación,
  identidad, sesión, instrumentación, contratos de conductor, facturación y Taller.
  PostgreSQL 18 temporal y datos sintéticos; sin acceso a bases reales.
- 38 pruebas Node de contratos, adjuntos, claves, identidad y navegación.
- 37 pruebas de navegador de oficina: matriz de 196 pares entre los 14 destinos (incluye 14 permanencias),
  una sola carga de documento y ausencia de consultas iniciales redundantes de
  sesión/navegación. También historial, URL/recarga, permisos, cuenta cambiada,
  sesión expirada, formularios, descarga fallida y cancelación de lecturas.
- Operaciones: doble envío, validación, recuperación tras desmontaje autorizado,
  mismo cuerpo/clave, y mismos bytes del comprobante de Gastos tras recuperar sesión.
- Regresión de conductor: 16 pruebas de portal/IndexedDB, respuesta perdida,
  pendientes, cuentas, expiración, concurrencia y reconexión; además 4 de alertas.
- Pint, TypeScript y build satisfactorios. No se incorporan dependencias.

Medición local del build: entrada `app` de aproximadamente 14,6 kB. Sumando sus
imports estáticos y los chunks necesarios de bootstrap/Inicio: 130.994 bytes JS
(51.947 bytes gzip, 7 archivos). No incluye CSS, fuentes ni respuestas HTTP. Los
componentes de Administración, Transporte, Taller, Supply, Finanzas, Auditoría y
Reportes aparecen como entradas dinámicas separadas. La prueba de navegador
confirma que Inicio no solicita Taller/Auditoría/Reportes hasta entrar en ellos.
La política de precaché de la PWA permanece intacta; puede descargar assets después
para uso offline. El tamaño inicial no equivale al total de assets de la aplicación.

No se midió latencia en Azure. Persisten el aviso de fuentes opcionales de Vite y
los avisos experimentales del transformador TypeScript de Node; no son errores.
No se realiza push, despliegue ni cambio de infraestructura.
