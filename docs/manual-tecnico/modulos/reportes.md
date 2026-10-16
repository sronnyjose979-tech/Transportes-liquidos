# Reportes administrativos: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

No crea tablas propias: reutiliza datos persistidos por los módulos operativos..

## Contratos HTTP

[Referencia de las 1 rutas registradas](../api/reportes.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [AdministrativeReports](../../../app/Modules/AdministrativeReports/Application/AdministrativeReports.php) | `Application` | `consult` |
| [ReportController](../../../app/Modules/AdministrativeReports/Http/Controllers/ReportController.php) | `Http/Controllers` | `show` |
| [ReportQueryRequest](../../../app/Modules/AdministrativeReports/Http/Requests/ReportQueryRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [ReportResource](../../../app/Modules/AdministrativeReports/Http/Resources/ReportResource.php) | `Http/Resources` | `toArray` |
