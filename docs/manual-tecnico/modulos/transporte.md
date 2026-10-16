# Transporte y Despacho: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`customers`](../base-de-datos/transporte.md#customers), [`driver_assignments`](../base-de-datos/transporte.md#driver_assignments), [`driver_operation_receipts`](../base-de-datos/transporte.md#driver_operation_receipts), [`driver_releases`](../base-de-datos/transporte.md#driver_releases), [`liquid_products`](../base-de-datos/transporte.md#liquid_products), [`load_items`](../base-de-datos/transporte.md#load_items), [`operation_sites`](../base-de-datos/transporte.md#operation_sites), [`routes`](../base-de-datos/transporte.md#routes), [`transport_assignment_conflicts`](../base-de-datos/transporte.md#transport_assignment_conflicts), [`transport_assignment_guard`](../base-de-datos/transporte.md#transport_assignment_guard), [`transport_assignment_operations`](../base-de-datos/transporte.md#transport_assignment_operations), [`transport_authorization_applications`](../base-de-datos/transporte.md#transport_authorization_applications), [`transport_authorization_decisions`](../base-de-datos/transporte.md#transport_authorization_decisions), [`transport_authorization_requests`](../base-de-datos/transporte.md#transport_authorization_requests), [`transport_changes`](../base-de-datos/transporte.md#transport_changes), [`transport_creation_receipts`](../base-de-datos/transporte.md#transport_creation_receipts), [`transport_requests`](../base-de-datos/transporte.md#transport_requests), [`trip_asset_assignments`](../base-de-datos/transporte.md#trip_asset_assignments), [`trip_asset_releases`](../base-de-datos/transporte.md#trip_asset_releases), [`trip_events`](../base-de-datos/transporte.md#trip_events), [`trip_loads`](../base-de-datos/transporte.md#trip_loads), [`trip_odometer_readings`](../base-de-datos/transporte.md#trip_odometer_readings), [`trip_product_reviews`](../base-de-datos/transporte.md#trip_product_reviews), [`trip_unloads`](../base-de-datos/transporte.md#trip_unloads), [`trip_warning_resolutions`](../base-de-datos/transporte.md#trip_warning_resolutions), [`trip_warnings`](../base-de-datos/transporte.md#trip_warnings), [`trips`](../base-de-datos/transporte.md#trips), [`unload_items`](../base-de-datos/transporte.md#unload_items).

## Contratos HTTP

[Referencia de las 60 rutas registradas](../api/transporte.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php) | `Application` | `assign`, `substitute`, `release`, `requestAuthorization`, `decide` |
| [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php) | `Application` | `inspect`, `revalidate` |
| [DriverOfflineContext](../../../app/Modules/TransportAndDispatch/Application/DriverOfflineContext.php) | `Application` | `token`, `check` |
| [DriverTripRecords](../../../app/Modules/TransportAndDispatch/Application/DriverTripRecords.php) | `Application` | `write` |
| [RouteAdministration](../../../app/Modules/TransportAndDispatch/Application/RouteAdministration.php) | `Application` | `save` |
| [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php) | `Application` | `transaction`, `createRequest`, `updateRequest`, `createTrip`, `tripAction`, `requestAuthorization`, `decide` |
| [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php) | `Application` | `start`, `finish`, `load`, `unload`, `addDetails`, `report`, `reading`, `correctReading`, `distance` |
| [AssignedTripController](../../../app/Modules/TransportAndDispatch/Http/Controllers/AssignedTripController.php) | `Http/Controllers` | `index`, `show` |
| [CustomerController](../../../app/Modules/TransportAndDispatch/Http/Controllers/CustomerController.php) | `Http/Controllers` | heredados / propiedades |
| [DriverTripRecordController](../../../app/Modules/TransportAndDispatch/Http/Controllers/DriverTripRecordController.php) | `Http/Controllers` | `history`, `store` |
| [LiquidProductController](../../../app/Modules/TransportAndDispatch/Http/Controllers/LiquidProductController.php) | `Http/Controllers` | heredados / propiedades |
| [OperationSiteController](../../../app/Modules/TransportAndDispatch/Http/Controllers/OperationSiteController.php) | `Http/Controllers` | heredados / propiedades |
| [RouteController](../../../app/Modules/TransportAndDispatch/Http/Controllers/RouteController.php) | `Http/Controllers` | `index`, `show`, `store`, `update` |
| [TransportAuthorizationController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportAuthorizationController.php) | `Http/Controllers` | `index`, `show`, `store`, `decide` |
| [TransportLookupController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportLookupController.php) | `Http/Controllers` | `index` |
| [TransportRequestController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php) | `Http/Controllers` | `index`, `show`, `store`, `update`, `history` |
| [TripAssignmentController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php) | `Http/Controllers` | `write`, `history`, `availability` |
| [TripController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php) | `Http/Controllers` | `index`, `show`, `store`, `action`, `history`, `warnings` |
| [TripOperationController](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php) | `Http/Controllers` | `store`, `addDetails`, `show`, `report`, `reports`, `reading`, `correct`, `readings`, `distance`, `differences` |
| [TransportApiErrors](../../../app/Modules/TransportAndDispatch/Http/Middleware/TransportApiErrors.php) | `Http/Middleware` | `handle`, `databaseError` |
| [AssignedTripQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignedTripQueryRequest.php) | `Http/Requests` | `rules`, `authorize` |
| [AssignmentWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignmentWriteRequest.php) | `Http/Requests` | `rules` |
| [AuthorizationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AuthorizationWriteRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [DriverRecordWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/DriverRecordWriteRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [OdometerWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OdometerWriteRequest.php) | `Http/Requests` | `rules` |
| [OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php) | `Http/Requests` | `rules` |
| [ReportWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/ReportWriteRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [RouteWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/RouteWriteRequest.php) | `Http/Requests` | `authorize`, `rules`, `after`, `messages` |
| [TransportInput](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportInput.php) | `Http/Requests` | `authorize`, `withValidator` |
| [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php) | `Http/Requests` | `rules` |
| [TransportRequestWrite](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportRequestWrite.php) | `Http/Requests` | `rules` |
| [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php) | `Http/Requests` | `rules` |
| [TripCreateRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripCreateRequest.php) | `Http/Requests` | `rules` |
| [AssignedTripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/AssignedTripResource.php) | `Http/Resources` | `toArray` |
| [RouteResource](../../../app/Modules/TransportAndDispatch/Http/Resources/RouteResource.php) | `Http/Resources` | `toArray` |
| [TransportLookupResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportLookupResource.php) | `Http/Resources` | `toArray` |
| [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php) | `Http/Resources` | `toArray` |
| [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php) | `Http/Resources` | `toArray` |
| [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php) | `Http/Resources` | `toArray` |
| [Customer](../../../app/Modules/TransportAndDispatch/Models/Customer.php) | `Models` | heredados / propiedades |
| [DriverAssignment](../../../app/Modules/TransportAndDispatch/Models/DriverAssignment.php) | `Models` | `scopeCurrent`, `trip`, `employee`, `operation`, `release` |
| [DriverRelease](../../../app/Modules/TransportAndDispatch/Models/DriverRelease.php) | `Models` | `assignment`, `operation` |
| [LiquidProduct](../../../app/Modules/TransportAndDispatch/Models/LiquidProduct.php) | `Models` | heredados / propiedades |
| [LoadItem](../../../app/Modules/TransportAndDispatch/Models/LoadItem.php) | `Models` | `tripLoad`, `compartment`, `unloadItem`, `recordedBy` |
| [OperationSite](../../../app/Modules/TransportAndDispatch/Models/OperationSite.php) | `Models` | heredados / propiedades |
| [Route](../../../app/Modules/TransportAndDispatch/Models/Route.php) | `Models` | `originSite`, `destinationSite`, `transportRequests` |
| [TransportAssignmentConflict](../../../app/Modules/TransportAndDispatch/Models/TransportAssignmentConflict.php) | `Models` | `operation`, `assetAssignment`, `driverAssignment` |
| [TransportAssignmentOperation](../../../app/Modules/TransportAndDispatch/Models/TransportAssignmentOperation.php) | `Models` | `trip`, `actor`, `assetAssignment`, `driverAssignment`, `assetRelease`, `driverRelease`, `conflicts`, `applications` |
| [TransportAuthorizationApplication](../../../app/Modules/TransportAndDispatch/Models/TransportAuthorizationApplication.php) | `Models` | `decision`, `change`, `appliedBy`, `operation` |
| [TransportAuthorizationDecision](../../../app/Modules/TransportAndDispatch/Models/TransportAuthorizationDecision.php) | `Models` | `authorizationRequest`, `decidedBy`, `application` |
| [TransportAuthorizationRequest](../../../app/Modules/TransportAndDispatch/Models/TransportAuthorizationRequest.php) | `Models` | `transportRequest`, `trip`, `requestedBy`, `decision` |
| [TransportChange](../../../app/Modules/TransportAndDispatch/Models/TransportChange.php) | `Models` | `transportRequest`, `trip`, `actor`, `application`, `productReviews` |
| [TransportRequest](../../../app/Modules/TransportAndDispatch/Models/TransportRequest.php) | `Models` | `customer`, `liquidProduct`, `route`, `registeredBy`, `trips`, `changes`, `authorizationRequests` |
| [Trip](../../../app/Modules/TransportAndDispatch/Models/Trip.php) | `Models` | `transportRequest`, `liquidProduct`, `registeredBy`, `startedBy`, `completedBy`, `closedBy`, `cancelledBy`, `events`, `changes`, `productReviews`, `warnings`, `authorizationRequests`, `assetAssignments`, `driverAssignments`, `assignmentOperations`, `tripLoad`, `unload`, `odometerReadings` |
| [TripAssetAssignment](../../../app/Modules/TransportAndDispatch/Models/TripAssetAssignment.php) | `Models` | `scopeCurrent`, `trip`, `fleetAsset`, `operation`, `release` |
| [TripAssetRelease](../../../app/Modules/TransportAndDispatch/Models/TripAssetRelease.php) | `Models` | `assignment`, `operation` |
| [TripEvent](../../../app/Modules/TransportAndDispatch/Models/TripEvent.php) | `Models` | `trip`, `createdBy` |
| [TripLoad](../../../app/Modules/TransportAndDispatch/Models/TripLoad.php) | `Models` | `trip`, `tankerAssignment`, `operationSite`, `items`, `unload`, `recordedBy` |
| [TripOdometerReading](../../../app/Modules/TransportAndDispatch/Models/TripOdometerReading.php) | `Models` | `scopeCurrent`, `trip`, `tractorAssignment`, `previousReading`, `successor`, `recordedBy` |
| [TripProductReview](../../../app/Modules/TransportAndDispatch/Models/TripProductReview.php) | `Models` | `trip`, `requestChange`, `previousProduct`, `proposedProduct`, `reviewedBy` |
| [TripUnload](../../../app/Modules/TransportAndDispatch/Models/TripUnload.php) | `Models` | `trip`, `tripLoad`, `operationSite`, `items`, `recordedBy` |
| [TripWarning](../../../app/Modules/TransportAndDispatch/Models/TripWarning.php) | `Models` | `trip`, `resolutions` |
| [TripWarningResolution](../../../app/Modules/TransportAndDispatch/Models/TripWarningResolution.php) | `Models` | `warning`, `actor` |
| [UnloadItem](../../../app/Modules/TransportAndDispatch/Models/UnloadItem.php) | `Models` | `unload`, `loadItem`, `recordedBy` |
| [TransportCorePolicy](../../../app/Modules/TransportAndDispatch/Policies/TransportCorePolicy.php) | `Policies` | `manage`, `decide`, `report` |

## Protección compartida por los modelos históricos

El trait [`PreservesTransportHistory`](../../../app/Modules/TransportAndDispatch/Models/Concerns/PreservesTransportHistory.php) rechaza eventos Eloquent `updating` y `deleting` en los modelos que lo incorporan. Un **trait** comparte comportamiento entre clases. No protege por sí solo Query Builder o SQL directo; por eso existen también los triggers PostgreSQL documentados por tabla.

[`ValidatesTransportVolume`](../../../app/Modules/TransportAndDispatch/Models/Concerns/ValidatesTransportVolume.php) comparte validación de cantidades en los modelos que lo usan. La precisión/rango y relaciones también se protegen en PostgreSQL; no convertir el formulario en fuente de verdad de litros.
