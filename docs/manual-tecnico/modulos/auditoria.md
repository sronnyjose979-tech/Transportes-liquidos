# Auditoría administrativa: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

No crea tablas propias: reutiliza datos persistidos por los módulos operativos..

## Contratos HTTP

[Referencia de las 2 rutas registradas](../api/auditoria.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [AdministrativeAudit](../../../app/Modules/AdministrativeAudit/Application/AdministrativeAudit.php) | `Application` | `consult`, `detail` |
| [AuditController](../../../app/Modules/AdministrativeAudit/Http/Controllers/AuditController.php) | `Http/Controllers` | `index`, `show` |
| [AuditQueryRequest](../../../app/Modules/AdministrativeAudit/Http/Requests/AuditQueryRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [AuditResource](../../../app/Modules/AdministrativeAudit/Http/Resources/AuditResource.php) | `Http/Resources` | `toArray` |
