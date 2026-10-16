# Rutas: Auditoría administrativa

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/audit

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AuditController::index](../../../app/Modules/AdministrativeAudit/Http/Controllers/AuditController.php)().
- **Form Request identificado en la firma:** [AuditQueryRequest](../../../app/Modules/AdministrativeAudit/Http/Requests/AuditQueryRequest.php).
- **Coordinadores referidos por el controlador:** [AdministrativeAudit](../../../app/Modules/AdministrativeAudit/Application/AdministrativeAudit.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:view-administrative-audit`.

- **Representaciones utilizadas por esta clase:** [AuditResource](../../../app/Modules/AdministrativeAudit/Http/Resources/AuditResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/audit/{source}/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AuditController::show](../../../app/Modules/AdministrativeAudit/Http/Controllers/AuditController.php)().
- **Form Request identificado en la firma:** [AuditQueryRequest](../../../app/Modules/AdministrativeAudit/Http/Requests/AuditQueryRequest.php).
- **Coordinadores referidos por el controlador:** [AdministrativeAudit](../../../app/Modules/AdministrativeAudit/Application/AdministrativeAudit.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:view-administrative-audit`.

- **Representaciones utilizadas por esta clase:** [AuditResource](../../../app/Modules/AdministrativeAudit/Http/Resources/AuditResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
