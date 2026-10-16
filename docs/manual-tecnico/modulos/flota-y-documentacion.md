# Flota y Documentación: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`compartments`](../base-de-datos/flota-y-documentacion.md#compartments), [`current_fleet_documents`](../base-de-datos/flota-y-documentacion.md#current_fleet_documents), [`document_alert_reviews`](../base-de-datos/flota-y-documentacion.md#document_alert_reviews), [`document_configuration_snapshots`](../base-de-datos/flota-y-documentacion.md#document_configuration_snapshots), [`document_types`](../base-de-datos/flota-y-documentacion.md#document_types), [`documents`](../base-de-datos/flota-y-documentacion.md#documents), [`fleet_assets`](../base-de-datos/flota-y-documentacion.md#fleet_assets), [`tankers`](../base-de-datos/flota-y-documentacion.md#tankers), [`tractor_units`](../base-de-datos/flota-y-documentacion.md#tractor_units).

## Contratos HTTP

[Referencia de las 23 rutas registradas](../api/flota-y-documentacion.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php) | `Application` | `__construct`, `configure`, `documents`, `register` |
| [DocumentAlerts](../../../app/Modules/FleetAndDocuments/Application/DocumentAlerts.php) | `Application` | `query`, `summary`, `review` |
| [FleetAdministration](../../../app/Modules/FleetAndDocuments/Application/FleetAdministration.php) | `Application` | `save` |
| [ReconcileDocumentStorage](../../../app/Modules/FleetAndDocuments/Console/ReconcileDocumentStorage.php) | `Console` | `handle` |
| [DocumentOperationFailed](../../../app/Modules/FleetAndDocuments/Domain/DocumentOperationFailed.php) | `Domain` | `render` |
| [DocumentAlertController](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentAlertController.php) | `Http/Controllers` | `index`, `summary`, `review` |
| [DocumentController](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php) | `Http/Controllers` | `index`, `missing`, `show`, `store`, `renew`, `download` |
| [DocumentTypeController](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentTypeController.php) | `Http/Controllers` | `index`, `store`, `update` |
| [FleetAssetLookupController](../../../app/Modules/FleetAndDocuments/Http/Controllers/FleetAssetLookupController.php) | `Http/Controllers` | `index` |
| [FleetController](../../../app/Modules/FleetAndDocuments/Http/Controllers/FleetController.php) | `Http/Controllers` | `index`, `show`, `store`, `update` |
| [TankerController](../../../app/Modules/FleetAndDocuments/Http/Controllers/TankerController.php) | `Http/Controllers` | heredados / propiedades |
| [TractorUnitController](../../../app/Modules/FleetAndDocuments/Http/Controllers/TractorUnitController.php) | `Http/Controllers` | heredados / propiedades |
| [DocumentAlertQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertQueryRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [DocumentAlertReviewRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertReviewRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [DocumentTypeRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentTypeRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [DocumentUploadRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [FleetAssetLookupRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/FleetAssetLookupRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [FleetAssetRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/FleetAssetRequest.php) | `Http/Requests` | `authorize`, `rules`, `after`, `messages` |
| [DocumentAlertResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentAlertResource.php) | `Http/Resources` | `toArray` |
| [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php) | `Http/Resources` | `toArray` |
| [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php) | `Http/Resources` | `toArray` |
| [FleetAssetLookupResource](../../../app/Modules/FleetAndDocuments/Http/Resources/FleetAssetLookupResource.php) | `Http/Resources` | `toArray` |
| [FleetAssetResource](../../../app/Modules/FleetAndDocuments/Http/Resources/FleetAssetResource.php) | `Http/Resources` | `toArray` |
| [PrivateDocumentStorage](../../../app/Modules/FleetAndDocuments/Infrastructure/PrivateDocumentStorage.php) | `Infrastructure` | `disk`, `register`, `reconcile`, `download` |
| [Compartment](../../../app/Modules/FleetAndDocuments/Models/Compartment.php) | `Models` | `scopeWithTankerCapacity`, `tanker` |
| [Document](../../../app/Modules/FleetAndDocuments/Models/Document.php) | `Models` | `documentType`, `fleetAsset`, `uploadedBy`, `configurationSnapshot`, `scopeCurrent`, `validity`, `scopeWithValidity` |
| [DocumentConfigurationSnapshot](../../../app/Modules/FleetAndDocuments/Models/DocumentConfigurationSnapshot.php) | `Models` | heredados / propiedades |
| [DocumentType](../../../app/Modules/FleetAndDocuments/Models/DocumentType.php) | `Models` | `documents`, `scopeRequiredFor`, `scopeMissingFor` |
| [FleetAsset](../../../app/Modules/FleetAndDocuments/Models/FleetAsset.php) | `Models` | `documents`, `tractorUnit`, `tanker` |
| [FleetImpedimentResolution](../../../app/Modules/FleetAndDocuments/Models/FleetImpedimentResolution.php) | `Models` | `impediment`, `operation`, `resolvedBy` |
| [FleetOperatingImpediment](../../../app/Modules/FleetAndDocuments/Models/FleetOperatingImpediment.php) | `Models` | `fleetAsset`, `admission`, `workOrder`, `actor`, `resolution` |
| [Tanker](../../../app/Modules/FleetAndDocuments/Models/Tanker.php) | `Models` | `fleetAsset`, `compartments` |
| [TractorUnit](../../../app/Modules/FleetAndDocuments/Models/TractorUnit.php) | `Models` | `fleetAsset` |

## Enum de vigencia

[`DocumentValidity`](../../../app/Modules/FleetAndDocuments/Domain/DocumentValidity.php) es un enum PHP con `valid`, `expiring_soon`, `expired`, `no_expiration`. No es una tabla ni contiene por sí solo el cálculo: `Document::validity()` y `scopeWithValidity()` aplican la fecha local y el snapshot. `current_fleet_documents` se administra mediante consultas del servicio/triggers. No se añade otro modelo por el hecho de documentar esa referencia.
