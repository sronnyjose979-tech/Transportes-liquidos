# 07. Frontend: pantallas, estado y formularios


## Del HTML inicial al componente

**Blade** es el motor de plantillas del servidor. Entrega HTML con un elemento raíz y los recursos Vite. [`resources/js/app.ts`](../../resources/js/app.ts) busca esas raíces y monta la aplicación Vue correspondiente:

| Raíz | Componente | Páginas |
| --- | --- | --- |
| `fleet-documents-app` | `FleetDocumentsApp.vue` | `/documentacion` |
| `transport-app` | `TransportApp.vue` | `/transporte` |
| `workshop-app` | `WorkshopApp.vue` | `/taller` |
| `administration-app` | `AdminApp.vue` | `/administracion/flota`, `/personal`, `/catalogos`, `/compras` bajo el prefijo administrativo |
| `supply-app` | `SupplyApp.vue` | `/inventario`, `/compras` |
| `system-app` | `SystemApp.vue` | Login, dashboard, conductor, facturación, gastos, reportes, auditoría y alertas según sección Blade |

Consultar [`routes/web.php`](../../routes/web.php) para las rutas completas; `/personal` aislada no es una página registrada. `SystemApp` selecciona la sección, no sustituye las aplicaciones de negocio por una autenticación nueva.

[Inventario completo de archivos frontend](frontend/archivos.md): incluye componentes, contratos, presentación, CSS y adaptadores de las 12 agrupaciones reales.

## Composition API y estado

La **Composition API** de Vue organiza estado y funciones mediante `ref`, `reactive`, `computed` y ciclos de vida. Un `ref` conserva un valor reactivo; un `computed` lo deriva sin duplicar su fuente. Por ejemplo, el rol y el estado del viaje determinan qué acción mostrar, pero el backend vuelve a decidir si se permite.

Los módulos mantienen estado local/contextos específicos. No hay que buscar un almacén universal obligatorio. `contracts.ts` tipa los datos; `presentation.ts` formatea etiquetas, cantidades y fechas. Un tipo no convierte una respuesta inválida en segura: los adaptadores también comprueban objetos y confirmaciones cuando corresponde.

## Pantallas y componentes que conviene estudiar

- Administración: `FleetPanel`, `PersonnelPanel`, `CatalogPanel`, `RoutesPanel`. Reutiliza API y selectores; la búsqueda local sobre recursos paginados cargados no significa que exista un endpoint nuevo de búsqueda.
- Transporte: `RequestBoard`, `TripBoard`, `TripDetail`, `AssignmentPanel`, `OperationsPanel`, `OdometerPanel`, `AuthorizationPanel`, `HistoryList`, `CommandForm`.
- Taller: `WorkshopApp`, `ScopeForm`, `OrderOperations`, `EvidencePanel`, `ExitRequestForm`, `RecordTable` y `SearchSelect`.
- Suministros: `SupplyApp` organiza Inventario/Compras; mantiene intenciones inciertas del actor sin repetirlas automáticamente.
- Facturación: `BillingApp`, `TripsPanel`, `InvoicesPanel` distinguen propuesta/aprobación, factura y pagos.
- Gastos: `ExpensesApp`, `ExpensesPanel` presentan revisiones, decisiones y comprobantes.
- Documentación: selector de activo, tipos, formulario de carga, listado, acciones e información histórica.
- Sistema: `SharedNavigation`, `SystemApp`, `DriverTrips`; Reportes/Auditoría/Alertas tienen aplicaciones dedicadas bajo sus carpetas.

## Navegación y roles

`SharedNavigation` consume `/api/navigation`. `SystemNavigation::links()/home()` calcula enlaces e inicio a partir de Gates reales. Administrador inicia en `/dashboard`, secretaria en `/transporte`, mecánico en `/taller` y conductor en `/mis-viajes`. Una cuenta con varios roles sigue el orden implementado por `home()`; no crear una prioridad nueva en el navegador.

Las páginas también tienen middleware. Pegar una URL no elude la autorización. En Taller una respuesta 403 puntual obliga a reconsiderar el registro/identidad; no asumir que desapareció todo el módulo. La interfaz no ofrece sustitución de cisterna ni aprobaciones exclusivas a secretaria.

## HTTP desde Vue

[`Administration/api.ts`](../../resources/js/modules/Administration/api.ts) reutiliza `ApiError` y `refreshCsrf` de Documentación. Sus `request()`, `all()` y `paginated()` resuelven necesidades distintas:

- `request()` extrae `data` de un objeto confirmado.
- `all()` recorre páginas con `per_page=100` para selectores/catálogos y valida su envoltura.
- `paginated()` conserva datos y metadatos de paginación para tablas.

El envío usa `credentials: 'same-origin'`, `Accept: application/json`, `cache: 'no-store'` y `X-XSRF-TOKEN` en escrituras. Formularios con archivos usan `FormData`; no imponerles `Content-Type: application/json`, porque el navegador debe añadir el separador multipart.

`ApiError` conserva estado HTTP y errores de campo. `failure()` convierte fallos desconocidos en “no se pudo confirmar”, no en “no se guardó”. Este matiz evita duplicar una operación que el servidor ya confirmó.

## Ciclo fiable de un formulario

1. Mostrar formulario con datos actuales y precondiciones/versión.
2. Validar lo básico y deshabilitar envío mientras está ocupado.
3. Capturar intención/contenido cuando el contrato tiene recibo idempotente.
4. En 422 conservar datos y mostrar errores junto al campo mediante componentes como `FieldError`.
5. En 409 consultar el estado antes de formular otra intención; en 401/419 recuperar sesión de forma explícita.
6. Tras confirmación, mostrar éxito y refrescar datos. Si falla solo el refresco, no repetir el POST confirmado ni presentar el refresco como una creación fallida.
7. Limpiar únicamente el formulario confirmado. No borrar borradores/comprobantes de otro formulario, especialmente en el portal.

El tratamiento de P1-05 conserva una clave opaca en sessionStorage por actor/tipo, pero no almacena allí todo el formulario. La PWA tiene un mecanismo durable distinto, descrito en [08](08-pwa-y-sincronizacion.md).

## Presentación responsive y accesibilidad

**Responsive** significa que la distribución responde al ancho disponible. El estilo azul/blanco usa Tailwind y CSS existente. Tablas extensas deben desplazarse dentro de su contenedor, sin ampliar toda la página. Filtros/formularios se apilan, botones conservan tamaño utilizable y textos largos se parten donde corresponde. Los ajustes recientes de Facturación/Gastos evitan desbordar por motivos/descripciones extensos; no fueron un rediseño global.

Etiquetas de campo, estados de carga, confirmaciones y mensajes deben explicar qué ocurrió. No depender solo del color para diferenciar estado. Las pruebas de 320–390 px y tablet en Chromium no demuestran experiencia real en todos los teléfonos; teclado virtual, Safari y suspensión necesitan pruebas físicas.

## Qué no hace el frontend

No calcula stock canónico, aprueba reparaciones, convierte monedas ni valida disponibilidad final. Puede presentar totales devueltos por servidor y estados derivados para uso, pero las decisiones monetarias y restricciones están en backend/PostgreSQL. No guarda indiscriminadamente respuestas privadas en caché o IndexedDB.
