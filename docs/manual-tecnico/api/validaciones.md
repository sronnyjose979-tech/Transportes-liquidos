# Validación de entradas: catálogo de Form Requests

[Volver al backend](../06-api-y-backend.md).

Un Form Request valida y puede autorizar antes de llamar al controlador. Esta ficha enumera las claves declaradas y los puntos de ejecución; no aplana reglas condicionales en un contrato universal. Una misma clase puede elegir reglas según la ruta, el actor o la acción. Los archivos enlazados contienen las condiciones, mensajes y validaciones posteriores exactos.

## AccountRequest

- **Implementación:** [App\Modules\AccessAndPersonnel\Http\Requests\AccountRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/AccountRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `messages()`, `after()`.
- **Claves literales declaradas:** `options`, `min_range`, `is_active`, `login`, `name`, `email`, `password`, `roles`, `roles.*`, `required`, `string`, `max`, `min`, `unique`, `boolean`, `array`, `uuid`, `distinct`, `list`, `exists`, `confirmed`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
if ($this->route('id') !== null && filter_var($this->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
return ['is_active' => ['required', 'boolean']];
'login' => [$required, 'required', 'string', 'max:255', Rule::unique('users', 'login')->ignore($id)]
'name' => [$required, 'required', 'string', 'max:255']
'email' => [$required, 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)]
'password' => [$required, 'required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail) {
'roles' => [$required, 'array', 'list']
'roles.*' => ['required', 'uuid', 'distinct', Rule::exists('roles', 'id')->whereIn('name', Role::SUPPORTED_NAMES)]
'required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.'
'max' => 'El campo :attribute supera el límite permitido.', 'min' => 'El campo :attribute no alcanza el mínimo permitido.'
'email' => 'El correo no es válido.', 'unique' => 'El valor de :attribute ya está en uso.'
'boolean' => 'El campo :attribute debe ser verdadero o falso.', 'array' => 'El campo :attribute debe ser una lista.'
'uuid' => 'El identificador de rol no es válido.', 'distinct' => 'No se permiten roles duplicados.'
'list' => 'Los roles deben ser una lista.', 'exists' => 'El rol no existe o no está permitido.', 'confirmed' => 'La confirmación de contraseña no coincide.'
```

## EmployeeRequest

- **Implementación:** [App\Modules\AccessAndPersonnel\Http\Requests\EmployeeRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/EmployeeRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`, `messages()`.
- **Claves literales declaradas:** `name`, `is_active`, `user_id`, `required`, `string`, `max`, `boolean`, `integer`, `user_id.exists`, `user_id.unique`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'name' => [$presence, 'required', 'string', 'max:255']
'is_active' => [$presence, 'required', 'boolean']
'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($this->route('id'))]
'required' => 'El campo :attribute es obligatorio.'
'string' => 'El campo :attribute debe ser texto.'
'max' => 'El campo :attribute supera el límite permitido.'
'boolean' => 'El campo :attribute debe ser verdadero o falso.'
'integer' => 'El campo :attribute debe ser entero.'
'user_id.exists' => 'La cuenta de usuario seleccionada no existe.'
'user_id.unique' => 'La cuenta de usuario ya está vinculada a otro empleado.'
```

## AuditQueryRequest

- **Implementación:** [App\Modules\AdministrativeAudit\Http\Requests\AuditQueryRequest](../../../app/Modules/AdministrativeAudit/Http/Requests/AuditQueryRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `page`, `per_page`, `from`, `to`, `actor_id`, `module`, `operation`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'
'from' => 'sometimes|date_format:Y-m-d'
'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : [])
'actor_id' => 'sometimes|integer|min:1|exists:users,id'
'module' => ['sometimes', Rule::in(AdministrativeAudit::MODULES)]
'operation' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/']
```

## ReportQueryRequest

- **Implementación:** [App\Modules\AdministrativeReports\Http\Requests\ReportQueryRequest](../../../app/Modules/AdministrativeReports/Http/Requests/ReportQueryRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `transport`, `billing`, `expenses`, `workshop`, `stock`, `movements`, `page`, `per_page`, `from`, `to`, `customer_id`, `fleet_asset_id`, `currency`, `status`, `spare_part_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'transport' => ['pending', 'in_progress', 'completed', 'cancelled']
'billing' => ['pending', 'partially_paid', 'paid']
'expenses' => ['pending', 'approved', 'rejected']
'workshop' => ['pending_diagnosis', 'pending_authorization', 'authorized', 'in_repair', 'finished', 'closed', 'cancelled']
'stock' => [], 'movements' => ['initial', 'adjustment', 'reversal', 'receipt', 'delivery', 'usage', 'return']
'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'
'from' => $kind === 'stock' ? 'prohibited' : 'sometimes|date_format:Y-m-d'
'to' => $kind === 'stock' ? 'prohibited' : array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : [])
'customer_id' => in_array($kind, ['transport', 'billing']) ? 'sometimes|uuid|exists:customers,id' : 'prohibited'
'fleet_asset_id' => in_array($kind, ['transport', 'workshop']) ? 'sometimes|uuid|exists:fleet_assets,id' : 'prohibited'
'currency' => in_array($kind, ['billing', 'expenses']) ? ['sometimes', Rule::in(['CRC', 'USD'])] : 'prohibited'
'status' => $kind !== 'stock' ? ['sometimes', Rule::in(self::STATES[$kind] ?? [])] : 'prohibited'
'spare_part_id' => in_array($kind, ['stock', 'movements']) ? 'sometimes|uuid|exists:spare_parts,id' : 'prohibited'
```

## BillingWriteRequest

- **Implementación:** [App\Modules\BillingAndPayments\Http\Requests\BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `request_key`, `reason`, `amount`, `currency`, `supersedes_id`, `decision`, `customer_id`, `items`, `items.*`, `items.*.trip_id`, `items.*.price_proposal_id`, `items.*.description`, `paid_at`, `method`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['request_key' => 'required|uuid', 'reason' => 'required|string|max:2000'];
return $rules + ['amount' => $money, 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'supersedes_id' => 'nullable|uuid|exists:billing_trip_amounts,id'];
return $rules + ['decision' => ['required', Rule::in(['approved', 'rejected'])]];
return $rules + ['customer_id' => 'required|uuid|exists:customers,id', 'items' => 'required|array|min:1|max:100', 'items.*' => 'required|array:trip_id,price_proposal_id,description', 'items.*.trip_id' => 'required|uuid|distinct|exists:trips,id', 'items.*.price_proposal_id' => 'required|uuid|distinct|exists:billing_trip_amounts,id', 'items.*.description' => 'required|string|max:2000'];
return $rules + ['amount' => $money, 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'paid_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'], 'method' => 'nullable|string|max:255'];
```

## ExpenseWriteRequest

- **Implementación:** [App\Modules\Expenses\Http\Requests\ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `request_key`, `file`, `expected_revision_id`, `decision`, `reason`, `concept`, `category`, `amount`, `currency`, `occurred_at`, `description`, `supersedes_id`, `trip_id`, `pdf`, `jpg`, `png`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$key = ['request_key' => 'required|uuid'];
return $key + ['file' => 'required|file'];
return $key + ['expected_revision_id' => 'required|uuid', 'decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required|string|max:2000'];
$fields = ['concept' => 'required|string|max:255', 'category' => 'required|string|max:255', 'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', 'not_in:0,0.0,0.00'], 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'occurred_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'], 'description' => 'nullable|string|max:4000'];
return $key + $fields + ['supersedes_id' => 'required|uuid', 'reason' => 'required|string|max:2000'];
return $key + $fields + ['trip_id' => 'nullable|uuid|exists:trips,id'];
$expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
```

## DocumentAlertQueryRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\DocumentAlertQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertQueryRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `employee_id`, `person_id`, `page`, `per_page`, `status`, `attention`, `asset_type`, `fleet_asset_id`, `document_type_id`, `from`, `to`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['employee_id' => 'prohibited', 'person_id' => 'prohibited', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'
'status' => ['sometimes', Rule::in(['expired', 'expiring_soon'])]
'attention' => ['sometimes', Rule::in(['reviewed', 'unreviewed'])]
'asset_type' => ['sometimes', Rule::in(['tractor_unit', 'tanker'])]
'fleet_asset_id' => 'sometimes|uuid|exists:fleet_assets,id', 'document_type_id' => 'sometimes|uuid|exists:document_types,id'
'from' => 'sometimes|date_format:Y-m-d'
'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : [])
```

## DocumentAlertReviewRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\DocumentAlertReviewRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertReviewRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** ninguna regla literal; revisar herencia/preparación del request.

## DocumentQueryRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `page`, `per_page`, `validity`, `document_type_id`, `fleet_asset_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'page' => ['sometimes', 'integer', 'min:1']
'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']
'validity' => ['sometimes', Rule::enum(DocumentValidity::class)]
'document_type_id' => ['sometimes', 'uuid']
'fleet_asset_id' => ['sometimes', 'uuid']
```

## DocumentTypeRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\DocumentTypeRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentTypeRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `name`, `tracks_expiration`, `required_for_tractor_units`, `required_for_tankers`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'name' => [$presence, 'required', 'string', 'max:255']
'tracks_expiration' => [$presence, 'required', 'boolean']
'required_for_tractor_units' => [$presence, 'required', 'boolean']
'required_for_tankers' => [$presence, 'required', 'boolean']
```

## DocumentUploadRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\DocumentUploadRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `document_type_id`, `issued_on`, `expires_on`, `file`, `pdf`, `jpg`, `png`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'document_type_id' => [$this->routeIs('fleet.documents.renew') ? 'prohibited' : 'required', 'uuid', Rule::exists('document_types', 'id')]
'issued_on' => ['nullable', 'date_format:Y-m-d']
'expires_on' => ['nullable', 'date_format:Y-m-d']
'file' => ['required', 'file', function (string $attribute, mixed $value, \Closure $fail): void {
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$extension] ?? null;
```

## FleetAssetLookupRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\FleetAssetLookupRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/FleetAssetLookupRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `page`, `per_page`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'page' => ['sometimes', 'integer', 'min:1']
'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']
```

## FleetAssetRequest

- **Implementación:** [App\Modules\FleetAndDocuments\Http\Requests\FleetAssetRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/FleetAssetRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`, `messages()`.
- **Claves literales declaradas:** `code`, `required`, `string`, `max`, `min`, `boolean`, `array`, `list`, `numeric`, `decimal`, `gt`, `uuid`, `distinct`, `code.unique`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'code' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255', Rule::unique('fleet_assets', 'code')->ignore($this->route('id'))]
'required' => 'El campo :attribute es obligatorio.'
'string' => 'El campo :attribute debe ser texto.'
'max' => 'El campo :attribute supera el límite permitido.'
'min' => 'El campo :attribute debe contener al menos un elemento.'
'boolean' => 'El campo :attribute debe ser verdadero o falso.'
'array' => 'El campo :attribute contiene una estructura no permitida.'
'list' => 'El campo :attribute debe ser una lista.'
'numeric' => 'El campo :attribute debe ser numérico.'
'decimal' => 'El campo :attribute admite como máximo dos decimales.'
'gt' => 'El campo :attribute debe ser mayor que cero.'
'uuid' => 'El campo :attribute debe ser un UUID válido.'
'distinct' => 'El campo :attribute no debe repetirse.'
'code.unique' => 'El código del activo ya está en uso.'
```

## InventoryWriteRequest

- **Implementación:** [App\Modules\Inventory\Http\Requests\InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `request_key`, `occurred_at`, `reason`, `spare_part_id`, `kind`, `quantity`, `unit_snapshot`, `reverses_movement_id`, `decision`, `delivery_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['request_key' => 'required|uuid', 'occurred_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'], 'reason' => 'required|string|max:2000'];
$rules += ['spare_part_id' => 'required|uuid|exists:spare_parts,id', 'kind' => ['required', Rule::in(['initial', 'adjustment', 'reversal'])], 'quantity' => ['required', 'string', 'regex:/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/'], 'unit_snapshot' => 'required|string|max:255', 'reverses_movement_id' => 'nullable|uuid|exists:inventory_movements,id'];
$rules += ['decision' => ['required', Rule::in(['approved', 'rejected'])]];
$rules += ['delivery_id' => 'required|uuid|exists:workshop_part_deliveries,id', 'quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/']];
```

## PurchaseFulfillmentRequest

- **Implementación:** [App\Modules\Purchasing\Http\Requests\PurchaseFulfillmentRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseFulfillmentRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `request_key`, `reason`, `items`, `items.*`, `items.*.quantity`, `received_at`, `purchase_request_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:5000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'array:'.$key.',quantity'], 'items.*.'.$key => ['required', 'uuid', 'distinct', 'exists:'.($receipt ? 'purchase_order_items' : 'purchase_request_items').',id'], 'items.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
return $rules + ($receipt ? ['received_at' => ['required', 'date']] : ['purchase_request_id' => ['required', 'uuid', 'exists:purchase_requests,id']]);
```

## PurchaseWriteRequest

- **Implementación:** [App\Modules\Purchasing\Http\Requests\PurchaseWriteRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`.
- **Claves literales declaradas:** `request_key`, `reason`, `decision`, `supplier_id`, `items`, `items.*`, `items.*.spare_part_id`, `items.*.quantity`, `items.*.workshop_material_need_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:5000']];
return $rules + ['decision' => ['required', 'in:approved,rejected']];
'supplier_id' => ['required', 'uuid', 'exists:suppliers,id']
'items' => ['required', 'array', 'min:1', 'max:100']
'items.*' => ['required', 'array:spare_part_id,quantity,workshop_material_need_id']
'items.*.spare_part_id' => ['required', 'uuid', 'exists:spare_parts,id']
'items.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
'items.*.workshop_material_need_id' => ['nullable', 'uuid', 'exists:workshop_material_needs,id']
```

## AssignedTripQueryRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\AssignedTripQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignedTripQueryRequest.php).
- **Métodos públicos:** `rules()`, `authorize()`.
- **Claves literales declaradas:** `trip_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return parent::rules() + ['trip_id' => ['sometimes', 'uuid']];
```

## AssignmentWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\AssignmentWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignmentWriteRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `resource_kind`, `reason`, `resource_id`, `decisions`, `decisions.*`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['resource_kind' => ['required', Rule::in(['driver', 'tractor_unit', 'tanker'])], 'reason' => ['required', 'string', 'max:5000']];
$rules += ['resource_id' => ['required', 'uuid']
'decisions' => ['sometimes', 'array', 'max:10'], 'decisions.*' => ['uuid', 'distinct', 'exists:transport_authorization_decisions,id']];
```

## AuthorizationWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\AuthorizationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AuthorizationWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `decision`, `reason`, `type`, `content`, `content.action`, `content.resource_kind`, `content.resource_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:5000']];
return ['type' => ['required', Rule::in(['assignment_conflict', 'driver_substitution', 'tractor_substitution', 'volume_difference'])]
'reason' => ['required', 'string', 'max:5000'], 'content' => ['required_unless:type,volume_difference', 'prohibited_if:type,volume_difference', 'array:action,resource_kind,resource_id']
'content.action' => ['required_with:content', Rule::in(['assign', 'substitute'])]
'content.resource_kind' => ['required_with:content', Rule::in(['driver', 'tractor_unit', 'tanker'])]
'content.resource_id' => ['required_with:content', 'uuid']];
```

## DriverRecordWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\DriverRecordWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/DriverRecordWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `expected_trip_context`, `request_key`, `occurred_at`, `tractor_assignment_id`, `reading_type`, `reading_value`, `reason`, `type`, `information`, `information.observation`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['expected_trip_context' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'request_key' => ['required', 'uuid'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN]];
return $rules + ['tractor_assignment_id' => ['required', 'uuid'], 'reading_type' => ['required', Rule::in(['initial', 'final'])]
'reading_value' => ['required', 'string', 'max:100', 'regex:/\A[0-9]+(?:\.[0-9]+)?\z/'], 'reason' => ['required', 'string', 'max:5000']];
return $rules + ['type' => ['required', Rule::in(['information', 'incident', 'completion_report'])], 'information' => ['required', 'array:observation'], 'information.observation' => ['required', 'string', 'max:5000']];
```

## OdometerWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\OdometerWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OdometerWriteRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `reading_value`, `occurred_at`, `reason`, `evidence`, `tractor_assignment_id`, `reading_type`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['reading_value' => ['required', 'string', 'max:100', 'regex:/\A[0-9]+(?:\.[0-9]+)?\z/']
'occurred_at' => ['required', 'date', self::INSTANT_PATTERN], 'reason' => ['required', 'string', 'max:5000'], 'evidence' => ['sometimes', 'array', 'min:1']];
$rules += ['tractor_assignment_id' => ['required', 'uuid'], 'reading_type' => ['required', Rule::in(['initial', 'final'])]];
```

## OperationWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `operation_site_id`, `occurred_at`, `reason`, `evidence`, `items`, `items.*`, `items.*.liters`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['operation_site_id' => $this->routeIs('transport.operations.*-items') ? ['prohibited'] : ['required', 'uuid', 'exists:operation_sites,id'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN]
'reason' => ['required', 'string', 'max:5000'], 'evidence' => ['sometimes', 'array', 'min:1']
'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:'.$key.',liters']
'items.*.liters' => ['required', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/']];
```

## ReportWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\ReportWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/ReportWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `type`, `information`, `occurred_at`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['type' => ['required', Rule::in(['information', 'completion_report', 'incident'])]
'information' => ['required', 'array', 'min:1'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN]];
```

## RouteWriteRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\RouteWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/RouteWriteRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `after()`, `messages()`.
- **Claves literales declaradas:** `name`, `origin_site_id`, `destination_site_id`, `expected`, `expected.name`, `expected.origin_site_id`, `expected.destination_site_id`, `required`, `string`, `max`, `uuid`, `exists`, `array`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['name' => ['required', 'string', 'max:255']
'origin_site_id' => ['required', 'uuid', 'exists:operation_sites,id']
'destination_site_id' => ['required', 'uuid', 'exists:operation_sites,id']];
$rules += ['expected' => ['required', 'array:name,origin_site_id,destination_site_id']
'expected.name' => ['required', 'string', 'max:255'], 'expected.origin_site_id' => ['required', 'uuid']
'expected.destination_site_id' => ['required', 'uuid']];
return ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.'
'max' => 'El campo :attribute supera el límite permitido.', 'uuid' => 'Selecciona un identificador válido.'
'exists' => 'El sitio operativo seleccionado no existe.', 'array' => 'Consulta los datos actuales de la ruta antes de editar.'];
```

## TransportInput

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\TransportInput](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportInput.php).
- **Métodos públicos:** `authorize()`, `withValidator()`.
- **Claves literales declaradas:** ninguna regla literal; revisar herencia/preparación del request.

## TransportQueryRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `page`, `per_page`, `transport_request_id`, `execution_status`, `administrative_status`, `resource_kind`, `resource_id`, `tractor_assignment_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']
'transport_request_id' => ['sometimes', 'uuid'], 'execution_status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])]
'administrative_status' => ['sometimes', Rule::in(['open', 'closed'])], 'resource_kind' => ['sometimes', Rule::in(['driver', 'tractor_unit', 'tanker'])]
'resource_id' => ['required_with:resource_kind', 'uuid'], 'tractor_assignment_id' => ['sometimes', 'uuid']];
```

## TransportRequestWrite

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\TransportRequestWrite](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportRequestWrite.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `version`, `reason`, `requested_liters`, `requested_at`, `request_key`, `customer_id`, `liquid_product_id`, `route_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:5000']
'requested_liters' => ['sometimes', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/', 'numeric', 'gt:0']
'requested_at' => ['sometimes', 'date', self::INSTANT_PATTERN]];
return ['request_key' => ['required', 'uuid'], 'customer_id' => ['required', 'uuid', 'exists:customers,id'], 'liquid_product_id' => ['required', 'uuid', 'exists:liquid_products,id']
'route_id' => ['required', 'uuid', 'exists:routes,id'], 'requested_liters' => ['required', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/', 'numeric', 'gt:0']
'requested_at' => ['required', 'date', self::INSTANT_PATTERN]];
```

## TripActionRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `version`, `reason`, `scheduled_start_at`, `scheduled_end_at`, `decision_id`, `warnings`, `warnings.*`, `warnings.*.code`, `warnings.*.evidence`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:5000']];
'scheduled_start_at' => ['required', 'date', self::INSTANT_PATTERN], 'scheduled_end_at' => ['nullable', 'date', self::INSTANT_PATTERN, 'after_or_equal:scheduled_start_at']];
$rules += ['decision_id' => ['nullable', 'uuid', 'exists:transport_authorization_decisions,id']];
$rules += ['warnings' => ['sometimes', 'array', 'max:100']
'warnings.*' => ['array:code,evidence'], 'warnings.*.code' => ['required', 'string', 'max:100']
'warnings.*.evidence' => ['required', 'array', 'min:1']];
```

## TripCreateRequest

- **Implementación:** [App\Modules\TransportAndDispatch\Http\Requests\TripCreateRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripCreateRequest.php).
- **Métodos públicos:** `rules()`.
- **Claves literales declaradas:** `request_key`, `transport_request_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['request_key' => ['required', 'uuid'], 'transport_request_id' => ['required', 'uuid', 'exists:transport_requests,id']];
```

## WorkshopAttachmentRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Métodos públicos:** `reservationFields()`, `rules()`.
- **Claves literales declaradas:** `category`, `extension`, `mime`, `size_bytes`, `checksum`, `reason`, `external_repair_id`, `previous_attachment_id`, `decision_id`, `request_key`, `reserve`, `confirm`, `file`, `release`, `index`, `page`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
return ['category' => ['required', 'in:photo,document,invoice'], 'extension' => ['required', 'in:pdf,jpg,jpeg,png'], 'mime' => ['required', 'in:application/pdf,image/jpeg,image/png'], 'size_bytes' => ['required', 'integer', 'min:1', 'max:10000000'], 'checksum' => ['required', 'regex:/^[0-9a-f]{64}$/D'], 'reason' => ['required', 'string', 'max:10000'], 'external_repair_id' => ['nullable', 'uuid'], 'previous_attachment_id' => ['nullable', 'uuid'], 'decision_id' => ['nullable', 'uuid']];
$base = ['request_key' => ['required', 'uuid']];
'reserve' => $base + self::reservationFields()
'confirm' => $base + ['file' => ['sometimes', 'file', 'max:9766', 'mimetypes:application/pdf,image/jpeg,image/png']]
'release' => $base + ['reason' => $this->reason()]
'index' => ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]
```

## WorkshopCoreRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`, `withValidator()`.
- **Claves literales declaradas:** `request_key`, `reason`, `fleet_asset_id`, `kind`, `description`, `priority`, `created_at`, `version`, `trip_event_id`, `occurred_at`, `report_id`, `employee_id`, `starts_at`, `ends_at`, `mechanic_assignment_id`, `problem`, `probable_cause`, `proposed_repair`, `admission_id`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['request_key' => ['required', 'uuid'], 'reason' => self::reason()];
'order-create' => ['fleet_asset_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['corrective', 'preventive'])], 'description' => $text, 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'created_at' => self::instant()]
'order-update' => ['version' => ['required', 'integer:strict', 'min:1'], 'description' => ['sometimes', ...array_slice($text, 1)], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])]]
'report-create' => ['fleet_asset_id' => ['required', 'uuid'], 'trip_event_id' => ['nullable', 'uuid'], 'description' => $text, 'occurred_at' => self::instant()]
'report-revise' => ['description' => $text, 'occurred_at' => self::instant()]
'report-link' => ['report_id' => ['required', 'uuid']]
'mechanic-assign' => ['employee_id' => ['required', 'uuid'], 'starts_at' => self::instant()]
'mechanic-release' => ['ends_at' => self::instant()]
'diagnosis-record' => ['mechanic_assignment_id' => ['nullable', 'uuid'], 'problem' => $text, 'probable_cause' => $text, 'proposed_repair' => $text, 'occurred_at' => self::instant()]
'diagnosis-revise' => ['problem' => $text, 'probable_cause' => $text, 'proposed_repair' => $text, 'occurred_at' => self::instant()]
'admission-link' => ['admission_id' => ['required', 'uuid']]
```

## WorkshopInput

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopInput](../../../app/Modules/Workshop/Http/Requests/WorkshopInput.php).
- **Métodos públicos:** `authorize()`, `instant()`, `reason()`, `decimal()`, `key()`, `withValidator()`, `commandData()`.
- **Claves literales declaradas:** ninguna regla literal; revisar herencia/preparación del request.

## WorkshopMaterialRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Métodos públicos:** `authorize()`, `fields()`, `rules()`.
- **Claves literales declaradas:** `cost`, `usage`, `batch`, `revise`, `activity`, `time`, `delivery`, `external`, `need`, `attachment`, `occurred_at`, `reason`, `scope_part_id`, `delivered_to_assignment_id`, `quantity`, `delivery_id`, `mechanic_assignment_id`, `spare_part_id`, `description`, `scope_task_id`, `supplier_id`, `performed_at`, `cost_kind`, `basis`, `amount`, `currency`, `task_id`, `observations`, `event_type`, `request_key`, `version`, `executor_id`, `changes`, `decisions`, `decisions.*`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'delivery', 'cost' => ['administrator', 'secretary']
'usage' => ['administrator', 'mechanic']
'external', 'rectify', 'batch' => ['administrator']
'revise' => in_array($this->route('kind'), ['usage', 'need'], true) ? ['administrator', 'mechanic'] : ['administrator']
public const TABLES = ['activity' => 'workshop_activities', 'time' => 'workshop_time_events', 'delivery' => 'workshop_part_deliveries', 'usage' => 'workshop_part_usages', 'external' => 'workshop_external_repairs', 'cost' => 'workshop_cost_records', 'need' => 'workshop_material_needs', 'attachment' => 'workshop_attachments'];
$common = ['occurred_at' => ['required', ...$this->instant()], 'reason' => $this->reason()];
'delivery' => $common + ['scope_part_id' => ['required', 'uuid'], 'delivered_to_assignment_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()]]
'usage' => $common + ['delivery_id' => ['required', 'uuid'], 'mechanic_assignment_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()]]
'need' => $common + ['scope_part_id' => $uuid, 'spare_part_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()], 'description' => ['required', 'string', 'max:10000']]
'external' => ['scope_task_id' => ['required', 'uuid'], 'supplier_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:10000'], 'performed_at' => ['required', ...$this->instant()], 'reason' => $this->reason()]
'cost' => $common + array_fill_keys(['scope_task_id', 'scope_part_id', 'usage_id', 'external_repair_id', 'source_attachment_id', 'covered_by_cost_id'], $uuid) + ['cost_kind' => ['nullable', 'in:labor,part,external'], 'basis' => ['required', 'in:estimated,actual'], 'amount' => ['nullable', ...array_values(array_filter($this->decimal(true), fn ($rule) => $rule !== 'required'))], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/D'], 'description' => ['required', 'string', 'max:10000']]
'activity' => $common + ['task_id' => ['required', 'uuid'], 'mechanic_assignment_id' => $uuid, 'description' => ['required', 'string', 'max:10000'], 'observations' => ['nullable', 'string', 'max:10000']]
'time' => $common + ['mechanic_assignment_id' => ['required', 'uuid'], 'event_type' => ['required', 'in:start,pause,resume,finish']]
'attachment' => WorkshopAttachmentRequest::reservationFields()
return ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer:strict', 'min:1'], 'executor_id' => ['required', 'integer:strict', 'min:1'], 'reason' => $this->reason(), 'changes' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1']] + collect($fields)->mapWithKeys(fn ($rules, $key) => ['changes.'.$key => $rules])->all();
return ['request_key' => ['required', 'uuid'], 'decisions' => ['required', 'array', 'min:1', 'max:100'], 'decisions.*' => ['required', 'uuid', 'distinct']];
return ['request_key' => ['required', 'uuid']];
return ['request_key' => ['required', 'uuid']] + $this->fields($action);
```

## WorkshopMechanicLookupRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopMechanicLookupRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMechanicLookupRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `page`, `per_page`, `search`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'page' => ['sometimes', 'integer', 'min:1']
'per_page' => ['sometimes', 'integer', 'between:1,100']
'search' => ['sometimes', 'string', 'max:255']
```

## WorkshopProcessRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `exit`, `impediment`, `finish`, `request_key`, `reason`, `occurred_at`, `scope`, `diagnosis_id`, `description`, `scope_kind`, `previous_scope_id`, `previous_request_id`, `tasks`, `tasks.*`, `tasks.*.line_number`, `tasks.*.description`, `tasks.*.supplier_id`, `parts`, `parts.*`, `parts.*.line_number`, `parts.*.task_line_number`, `parts.*.spare_part_id`, `parts.*.quantity`, `decide`, `decision`, `type`, `version`, `priority`, `activity`, `task_id`, `mechanic_assignment_id`, `observations`, `previous_activity_id`, `decision_id`, `time`, `event_type`, `previous_event_id`, `close`, `review`, `review.*`, `admit`, `kind`, `admitted_at`, `resolve`, `admission_id`, `work_order_id`, `effect`, `resolve_ids`, `resolve_ids.*`, `exited_at`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
'close','historical','resolve','exit-request','decide','scope-apply','order-apply','exit' => ['administrator']
'order-request','admit','impediment' => ['administrator', 'secretary']
'activity','time','finish' => ['administrator', 'mechanic']
$base = ['request_key' => ['required', 'uuid']];
$reason = ['reason' => $this->reason()];
$at = ['occurred_at' => $this->instant()];
'scope' => $reason + [
'diagnosis_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:10000']
'scope_kind' => ['sometimes', Rule::in(['initial', 'extension', 'revision'])]
'previous_scope_id' => ($this->input('scope_kind', 'initial') === 'revision' ? ['required', 'uuid'] : ['prohibited']), 'previous_request_id' => $uuid
'tasks' => ['required', 'array', 'min:1', 'max:100']
'tasks.*' => ['required', 'array:line_number,description,supplier_id']
'tasks.*.line_number' => ['required', 'integer:strict', 'min:1', 'distinct']
'tasks.*.description' => ['required', 'string', 'max:10000'], 'tasks.*.supplier_id' => $uuid
'parts' => ['sometimes', 'array', 'max:100'], 'parts.*' => ['required', 'array:line_number,task_line_number,spare_part_id,quantity']
'parts.*.line_number' => ['required', 'integer:strict', 'min:1', 'distinct'], 'parts.*.task_line_number' => ['sometimes', 'nullable', 'integer:strict', 'min:1']
'parts.*.spare_part_id' => ['required', 'uuid'], 'parts.*.quantity' => $this->decimal()
'decide' => $reason + ['decision' => ['required', Rule::in(['approved', 'rejected'])]]
'scope-apply', 'order-apply', 'exit' => []
'order-request' => $reason + ['type' => ['required', Rule::in(['cancellation', 'reopening', 'rectification'])]
'version' => ['required', 'integer:strict', 'min:1'], 'previous_request_id' => $uuid] + ($this->input('type') === 'rectification' ? [
'description' => ['sometimes', 'string', 'max:10000'], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])]] : [])
'activity' => $reason + $at + ['task_id' => ['required', 'uuid'], 'mechanic_assignment_id' => $uuid
'description' => ['required', 'string', 'max:10000'], 'observations' => ['sometimes', 'nullable', 'string', 'max:10000']
'previous_activity_id' => $uuid, 'decision_id' => $uuid, 'version' => ['sometimes', 'integer:strict', 'min:1']]
'time' => $reason + $at + ['mechanic_assignment_id' => ['required', 'uuid'], 'event_type' => ['required', Rule::in(['start', 'pause', 'resume', 'finish'])]
'previous_event_id' => $uuid, 'decision_id' => $uuid, 'version' => ['sometimes', 'integer:strict', 'min:1']]
'finish', 'close' => $reason + $at + ['version' => ['required', 'integer:strict', 'min:1'], 'mechanic_assignment_id' => $uuid] + ($this->route('action') === 'close' ? [
'review' => ['required', 'array', 'min:1', 'max:20'], 'review.*' => ['required', 'string', 'max:10000']
'admit' => $reason + ['kind' => ['required', Rule::in(['corrective', 'preventive'])], 'admitted_at' => $this->instant()]
'historical', 'resolve' => $reason
'impediment' => $reason + $at + ['admission_id' => $uuid, 'work_order_id' => $uuid, 'effect' => ['required', Rule::in(['maintenance', 'out_of_service'])], 'description' => ['required', 'string', 'max:10000']]
'exit-request' => $reason + ['resolve_ids' => ['present', 'array', 'max:100'], 'resolve_ids.*' => ['required', 'uuid', 'distinct'], 'exited_at' => $this->instant(), 'previous_request_id' => $uuid]
'link-admission' => $reason + ['admission_id' => ['required', 'uuid']]
```

## WorkshopQueryRequest

- **Implementación:** [App\Modules\Workshop\Http\Requests\WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Métodos públicos:** `authorize()`, `rules()`.
- **Claves literales declaradas:** `page`, `per_page`, `fleet_asset_id`, `work_order_id`, `from`, `to`, `status`, `priority`.

Declaraciones encontradas (las ramas se conservan en el archivo fuente):

```php
$rules = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
$rules += ['fleet_asset_id' => ['sometimes', 'uuid'], 'work_order_id' => ['sometimes', 'uuid']];
$rules += ['from' => ['sometimes', 'string', 'date', ...array_slice(self::instant(), 3)], 'to' => ['sometimes', 'string', 'date', ...array_slice(self::instant(), 3)]];
$rules += ['status' => ['sometimes', 'in:pending_diagnosis,pending_authorization,authorized,in_repair,finished,closed,cancelled'], 'priority' => ['sometimes', 'in:low,normal,high,urgent']];
```
