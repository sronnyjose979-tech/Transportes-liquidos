# Taller Mecánico: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`fleet_impediment_resolutions`](../base-de-datos/taller.md#fleet_impediment_resolutions), [`fleet_operating_impediments`](../base-de-datos/taller.md#fleet_operating_impediments), [`workshop_activities`](../base-de-datos/taller.md#workshop_activities), [`workshop_admission_exits`](../base-de-datos/taller.md#workshop_admission_exits), [`workshop_admissions`](../base-de-datos/taller.md#workshop_admissions), [`workshop_attachments`](../base-de-datos/taller.md#workshop_attachments), [`workshop_authorization_applications`](../base-de-datos/taller.md#workshop_authorization_applications), [`workshop_authorization_decisions`](../base-de-datos/taller.md#workshop_authorization_decisions), [`workshop_authorization_requests`](../base-de-datos/taller.md#workshop_authorization_requests), [`workshop_cost_records`](../base-de-datos/taller.md#workshop_cost_records), [`workshop_diagnoses`](../base-de-datos/taller.md#workshop_diagnoses), [`workshop_external_repairs`](../base-de-datos/taller.md#workshop_external_repairs), [`workshop_material_needs`](../base-de-datos/taller.md#workshop_material_needs), [`workshop_mechanic_assignments`](../base-de-datos/taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](../base-de-datos/taller.md#workshop_mechanic_releases), [`workshop_operations`](../base-de-datos/taller.md#workshop_operations), [`workshop_order_admission_links`](../base-de-datos/taller.md#workshop_order_admission_links), [`workshop_part_deliveries`](../base-de-datos/taller.md#workshop_part_deliveries), [`workshop_part_usages`](../base-de-datos/taller.md#workshop_part_usages), [`workshop_report_links`](../base-de-datos/taller.md#workshop_report_links), [`workshop_reports`](../base-de-datos/taller.md#workshop_reports), [`workshop_scope_parts`](../base-de-datos/taller.md#workshop_scope_parts), [`workshop_scope_tasks`](../base-de-datos/taller.md#workshop_scope_tasks), [`workshop_scopes`](../base-de-datos/taller.md#workshop_scopes), [`workshop_time_events`](../base-de-datos/taller.md#workshop_time_events), [`workshop_warnings`](../base-de-datos/taller.md#workshop_warnings), [`workshop_work_orders`](../base-de-datos/taller.md#workshop_work_orders).

## Contratos HTTP

[Referencia de las 108 rutas registradas](../api/taller.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php) | `Application` | `fresh`, `module`, `roles`, `office`, `admin`, `isOffice`, `orders`, `readOrder`, `writeOrder`, `readAsset`, `readReport`, `readAdmission`, `requestOrder` |
| [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php) | `Application` | `__construct`, `reserve`, `confirm`, `release`, `listForOrder`, `receipt`, `download` |
| [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php) | `Application` | `__construct`, `requestScope`, `request`, `decide`, `applyScope`, `applyOrderDecision` |
| [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php) | `Application` | `__construct`, `admit`, `captureHistorical`, `recordImpediment`, `resolve`, `requestExit`, `exit`, `linkOrder` |
| [WorkshopCommands](../../../app/Modules/Workshop/Application/WorkshopCommands.php) | `Application` | `run`, `record`, `authorizeRoles`, `fingerprint` |
| [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php) | `Application` | `__construct`, `execute` |
| [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php) | `Application` | `__construct`, `record`, `revise`, `totals` |
| [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php) | `Application` | `__construct`, `recordActivity`, `recordTime`, `transition`, `times` |
| [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php) | `Application` | `__construct`, `apply`, `applyMany` |
| [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php) | `Application` | `__construct`, `deliver`, `usePart`, `requestMaterial`, `recordExternal`, `reviseDelivery`, `reviseUsage`, `reviseExternal`, `reviseMaterialNeed` |
| [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php) | `Application` | `__construct`, `request` |
| [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php) | `Application` | `__construct`, `request` |
| [WorkshopAttachmentController](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php) | `Http/Controllers` | `__construct`, `reserve`, `confirm`, `release`, `index`, `receipt`, `download` |
| [WorkshopCoreController](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php) | `Http/Controllers` | `__invoke` |
| [WorkshopMaterialController](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php) | `Http/Controllers` | `__construct`, `delivery`, `usage`, `need`, `external`, `cost`, `totals`, `rectify`, `revise`, `batch` |
| [WorkshopProcessController](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php) | `Http/Controllers` | `__construct`, `order`, `decide`, `apply`, `asset`, `resolve`, `requestExit`, `times` |
| [WorkshopQueryController](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php) | `Http/Controllers` | `__construct`, `mechanics`, `index`, `show`, `asset`, `forOrder` |
| [WorkshopApiErrors](../../../app/Modules/Workshop/Http/Middleware/WorkshopApiErrors.php) | `Http/Middleware` | `handle`, `databaseError` |
| [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php) | `Http/Requests` | `reservationFields`, `rules` |
| [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php) | `Http/Requests` | `authorize`, `rules`, `withValidator` |
| [WorkshopInput](../../../app/Modules/Workshop/Http/Requests/WorkshopInput.php) | `Http/Requests` | `authorize`, `instant`, `reason`, `decimal`, `key`, `withValidator`, `commandData` |
| [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php) | `Http/Requests` | `authorize`, `fields`, `rules` |
| [WorkshopMechanicLookupRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMechanicLookupRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php) | `Http/Requests` | `authorize`, `rules` |
| [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php) | `Http/Resources` | `toArray` |
| [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php) | `Http/Resources` | `toArray`, `decode` |
| [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php) | `Http/Resources` | `toArray` |
| [PrivateWorkshopFiles](../../../app/Modules/Workshop/Infrastructure/PrivateWorkshopFiles.php) | `Infrastructure` | `locked`, `path`, `write`, `verify`, `remove` |
| [WorkshopActivity](../../../app/Modules/Workshop/Models/WorkshopActivity.php) | `Models` | heredados / propiedades |
| [WorkshopAdmission](../../../app/Modules/Workshop/Models/WorkshopAdmission.php) | `Models` | `fleetAsset`, `admittedBy`, `orderLinks`, `operations`, `exit`, `warnings` |
| [WorkshopAdmissionExit](../../../app/Modules/Workshop/Models/WorkshopAdmissionExit.php) | `Models` | `admission`, `authorizationApplication`, `recordedBy` |
| [WorkshopAttachment](../../../app/Modules/Workshop/Models/WorkshopAttachment.php) | `Models` | heredados / propiedades |
| [WorkshopAuthorizationApplication](../../../app/Modules/Workshop/Models/WorkshopAuthorizationApplication.php) | `Models` | `decision`, `operation`, `appliedBy` |
| [WorkshopAuthorizationDecision](../../../app/Modules/Workshop/Models/WorkshopAuthorizationDecision.php) | `Models` | `request`, `decidedBy`, `application` |
| [WorkshopAuthorizationRequest](../../../app/Modules/Workshop/Models/WorkshopAuthorizationRequest.php) | `Models` | `workOrder`, `admission`, `scope`, `previousRequest`, `requestedBy`, `decision` |
| [WorkshopCostRecord](../../../app/Modules/Workshop/Models/WorkshopCostRecord.php) | `Models` | heredados / propiedades |
| [WorkshopDiagnosis](../../../app/Modules/Workshop/Models/WorkshopDiagnosis.php) | `Models` | `workOrder`, `mechanicAssignment`, `actor`, `rootRecord`, `previousDiagnosis`, `revisions` |
| [WorkshopExternalRepair](../../../app/Modules/Workshop/Models/WorkshopExternalRepair.php) | `Models` | heredados / propiedades |
| [WorkshopMaterialNeed](../../../app/Modules/Workshop/Models/WorkshopMaterialNeed.php) | `Models` | heredados / propiedades |
| [WorkshopMechanicAssignment](../../../app/Modules/Workshop/Models/WorkshopMechanicAssignment.php) | `Models` | `workOrder`, `employee`, `assignedBy`, `release`, `diagnoses` |
| [WorkshopMechanicRelease](../../../app/Modules/Workshop/Models/WorkshopMechanicRelease.php) | `Models` | `assignment`, `recordedBy` |
| [WorkshopOperation](../../../app/Modules/Workshop/Models/WorkshopOperation.php) | `Models` | `workOrder`, `admission`, `report`, `fleetAsset`, `actor`, `reportLink`, `mechanicAssignment`, `mechanicRelease`, `diagnosis`, `orderAdmissionLink`, `reservationOperation`, `scope`, `authorizationRequest`, `authorizationDecision`, `impediment`, `warning`, `activity`, `timeEvent`, `delivery`, `usage`, `externalRepair`, `attachment`, `costRecord`, `materialNeed` |
| [WorkshopOrderAdmissionLink](../../../app/Modules/Workshop/Models/WorkshopOrderAdmissionLink.php) | `Models` | `workOrder`, `admission`, `fleetAsset`, `linkedBy`, `operation` |
| [WorkshopPartDelivery](../../../app/Modules/Workshop/Models/WorkshopPartDelivery.php) | `Models` | heredados / propiedades |
| [WorkshopPartUsage](../../../app/Modules/Workshop/Models/WorkshopPartUsage.php) | `Models` | heredados / propiedades |
| [WorkshopReport](../../../app/Modules/Workshop/Models/WorkshopReport.php) | `Models` | `fleetAsset`, `tripEvent`, `reporter`, `recordedBy`, `rootRecord`, `previousReport`, `revisions`, `links` |
| [WorkshopReportLink](../../../app/Modules/Workshop/Models/WorkshopReportLink.php) | `Models` | `report`, `workOrder`, `linkedBy` |
| [WorkshopScope](../../../app/Modules/Workshop/Models/WorkshopScope.php) | `Models` | `workOrder`, `diagnosis`, `proposedBy`, `rootRecord`, `previousScope`, `tasks`, `parts`, `requests`, `revisions` |
| [WorkshopScopePart](../../../app/Modules/Workshop/Models/WorkshopScopePart.php) | `Models` | `scope`, `task`, `sparePart` |
| [WorkshopScopeTask](../../../app/Modules/Workshop/Models/WorkshopScopeTask.php) | `Models` | `scope`, `supplier`, `parts` |
| [WorkshopTimeEvent](../../../app/Modules/Workshop/Models/WorkshopTimeEvent.php) | `Models` | heredados / propiedades |
| [WorkshopWarning](../../../app/Modules/Workshop/Models/WorkshopWarning.php) | `Models` | `admission`, `assignment`, `workOrder`, `capturedBy` |
| [WorkshopWorkOrder](../../../app/Modules/Workshop/Models/WorkshopWorkOrder.php) | `Models` | `fleetAsset`, `createdBy`, `reportLinks`, `mechanicAssignments`, `diagnoses`, `admissionLinks`, `operations`, `scopes`, `authorizationRequests`, `timeEvents`, `externalRepairs`, `attachments`, `costRecords`, `materialNeeds` |
| [WorkshopWorkOrderPolicy](../../../app/Modules/Workshop/Policies/WorkshopWorkOrderPolicy.php) | `Policies` | `__construct`, `view`, `execute` |

## Protección compartida por los modelos históricos

El trait [`PreservesWorkshopHistory`](../../../app/Modules/Workshop/Models/Concerns/PreservesWorkshopHistory.php) rechaza eventos Eloquent `updating` y `deleting` en los modelos que lo incorporan. Un **trait** comparte comportamiento entre clases. No protege por sí solo Query Builder o SQL directo; por eso existen también los triggers PostgreSQL documentados por tabla.
