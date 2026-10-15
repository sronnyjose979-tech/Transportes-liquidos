# Gastos administrativos y portal operativo del conductor

Estado posterior del trabajo offline, todavía sin validación integral:
[implementación y bloqueo del conductor](conductor-offline.md). Las verificaciones
de este documento corresponden exclusivamente al avance online original.

## Alcance implementado

Se conserva el dashboard por roles, facturación y Transporte existentes. Este
avance incorpora control administrativo de gastos y captura **en línea** desde
el portal móvil, sin alterar pagos, facturas o saldos de clientes.

- `/gastos`: administrador y secretaria registran gastos generales o de un viaje,
  consultan filtros, detalle, versiones y comprobantes privados. Solo el
  administrador aprueba o rechaza una versión concreta, con motivo y auditoría.
- `/mis-viajes`: conductor activo consulta sus asignaciones efectivas y registra
  observaciones, incidencias, reportes informativos de terminación, lecturas de
  odómetro y gastos del viaje. Adjunta comprobantes a gastos ya confirmados.
- El mecánico no tiene acceso a Gastos ni a registros del conductor. El
  administrador conserva acceso al portal. Oficina consulta reportes y lecturas
  por los endpoints existentes del detalle de Transporte y gastos en `/gastos`.

El conductor nunca confirma inicio, finalización ni cierre oficial. Las
correcciones de kilometraje siguen siendo operaciones de oficina auditadas en
Transporte. Reportar una avería conserva el viaje y no crea una orden de Taller
ni ejecuta bloqueos o liberaciones automáticas.

## Persistencia e integridad

Migración incremental `2026_10_14_000370_create_administrative_expenses.php`:

| Tabla | Responsabilidad |
| --- | --- |
| `expenses` | Identidad del gasto, viaje opcional, actor y fecha del registro. |
| `expense_revisions` | Concepto, categoría, importe, moneda, fecha, descripción y correcciones encadenadas. |
| `expense_decisions` | Decisión única por versión; administrador, fecha y motivo. |
| `expense_receipts` | Comprobante privado, contenido, integridad y actor; la referencia privada no se expone. |
| `expense_operations` | Recibo idempotente por actor, operación y clave, ligado al resultado exacto. |

Una corrección no sobrescribe el original: crea una versión nueva pendiente de
aprobación y preserva las decisiones anteriores. No se editan ni borran hechos
históricos. Triggers PostgreSQL y restricciones diferidas verifican actores,
moneda, importes, relaciones, unicidad de versiones y correspondencia del recibo.
Importes positivos exactos de hasta `999999999999.99`, escala máxima 2, CRC/USD:
se reutiliza `BillingAndPayments\Application\Money`, con cadenas e integer
centavos, sin float ni conversión de moneda. Categoría y concepto son texto
administrativo; no se inventa un catálogo contable.

Migración incremental `2026_10_14_000380_create_driver_operation_receipts.php`:
`driver_operation_receipts` enlaza claves por actor con `trip_events` o
`trip_odometer_readings` existentes. No duplica hechos operativos. Ajusta mediante
migración nueva las funciones de integridad para admitir lecturas originales del
conductor con su asignación y cabezal vigentes; las correcciones conservan permiso
de oficina. Su rollback exige un plan explícito para no perder auditoría.

Las escrituras usan transacciones, bloqueo compartido `873210`, guardia de
asignaciones y bloqueo de usuario/roles. Las decisiones y correcciones no pueden
aplicarse a una versión distinta. Se vuelve a comprobar la asignación efectiva,
usuario/empleado activos y roles al confirmar. Fechas programadas no liberan ni
expiran asignaciones: se respetan inicio efectivo y liberación manual existentes.

Un reenvío de una operación ya confirmada devuelve su resultado original aun
tras liberar al conductor; requiere la cuenta activa y permisos de consulta
vigentes. Una clave con contenido distinto produce 409. Una operación nueva
requiere asignación vigente. Los registros propios permanecen consultables tras
la liberación, con paginación y sin acceso a datos de otros conductores.

## API y contratos

Todas las rutas son privadas y reutilizan sesión Laravel, CSRF y cuenta activa.
El actor se obtiene de la sesión; identificadores de actor del cliente se rechazan.

| Método/ruta | Operación |
| --- | --- |
| `GET /api/expenses` | Consulta paginada; estado, moneda, viaje, texto, fechas y `mine=1`. |
| `POST /api/expenses` | Registro pendiente con `request_key` UUID. |
| `GET /api/expenses/{id}` | Detalle, versiones, decisiones y comprobantes autorizados. |
| `POST /api/expenses/{id}/corrections` | Versión nueva con `supersedes_id`, motivo y clave. |
| `POST /api/expenses/{id}/decisions` | Aprobación/rechazo de `expected_revision_id` y motivo. |
| `POST /api/expenses/{id}/receipts` | Archivo privado y clave; operación separada del registro del gasto. |
| `GET /api/expenses/receipts/{id}/download` | Descarga autorizada, sin URL pública. |
| `GET /api/transport/my-trips` y `/{id}` | Viajes autorizados, asignaciones y registros propios. |
| `GET /api/transport/my-records` | Reportes y lecturas propios, paginados; filtro opcional por viaje. |
| `POST /api/transport/my-trips/{id}/reports` | Reporte informativo idempotente. |
| `POST /api/transport/my-trips/{id}/odometer` | Lectura original idempotente. |

El conductor solo registra gastos de sus viajes y consulta gastos creados por él;
no registra gastos generales ni corrige o aprueba importes. Oficina consulta
registros recibidos mediante las rutas existentes de Transporte. Ningún endpoint
permite editar directamente historial. Errores 401/403/404/409/419/422 y fallos del
servidor se presentan sin atribuir éxito a una operación rechazada.

## Comprobantes y recuperación

PDF/JPG/PNG reales, extensión y MIME coherentes, hasta **10.000.000 bytes**.
Se valida cabecera PDF y estructura reconocible de imágenes; no se requiere GD.
Almacenamiento en el disco privado existente `fleet_documents`, prefijo
`expenses/files/`, nombres internos UUID. Sin rutas libres del cliente, exposición
de `storage_key`, URLs públicas o sobrescritura de archivos históricos.

La carga física precede la transacción corta. Marcadores privados pendientes y
coordinación mediante flock permiten compensar únicamente archivos nuevos que
no estén referenciados. Una transacción activa o una comprobación inconclusa
conserva el marcador y el archivo para recuperación. La reconciliación comprueba
referencias y actividad PostgreSQL; nunca borra comprobantes históricos.

Comando de mantenimiento disponible: `php artisan expenses:reconcile-receipts`.
Antes de utilizarlo, verificar el entorno y base de destino; no se ejecuta sobre
la base de desarrollo durante estas pruebas. Requiere el disco local privado y
acceso a la información de transacciones de PostgreSQL.

Vue conserva intenciones inciertas y archivos solamente en memoria de la página,
por actor. No reenvía automáticamente tras una respuesta perdida; ofrece reintento
explícito con contenido y clave originales, bloquea doble envío y navegación
interna mientras hay una operación pendiente. La recuperación de sesión en la
misma página conserva esa intención. Cerrar/recargar la página pierde esta cola
en memoria: no existe persistencia offline ni sincronización en este avance.

## Prueba manual

1. Asignar conductor y recursos a un viaje usando Transporte.
2. Ingresar como conductor a `/mis-viajes`, consultar el viaje y registrar reporte,
   lectura y gasto; confirmar cada operación y después adjuntar el comprobante.
3. Como secretaria, abrir `/gastos`, filtrar por viaje y consultar el detalle;
   registrar también un gasto general o una corrección con motivo.
4. Como administrador, revisar historial y comprobantes, aprobar o rechazar la
   versión actual. Una corrección vuelve a quedar pendiente.
5. Liberar manualmente recursos cuando las reglas de Transporte lo permitan.
   El conductor conserva acceso a sus registros históricos, pero no puede crear
   nuevos registros del viaje liberado.

## Verificación reproducible

Activar el entorno de herramientas aprobado cuando corresponda. Nunca utilizar
`transporte_db`, `.env` del desarrollo ni migraciones fuera del runner aislado:

```bash
bash tests/run-postgres.sh --filter 'ExpenseApiTest|DriverPortalTest|SystemNavigationTest|TripOperationsTest|TransportApiTest'
bash tests/run-postgres.sh --e2e-expenses-driver
npm run typecheck
npm run build
python3 -m unittest discover -s tests/Frontend -p test_expenses.py -v
python3 -m unittest discover -s tests/Frontend -p test_driver_portal.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
npm run test:frontend-api
vendor/bin/pint --test
git diff --check
```

Las pruebas frontend de contrato usan respuestas sintéticas y se reportan
separadas del E2E. Este último usa Chromium real, Laravel real, sesiones/CSRF,
archivos privados y PostgreSQL 18 temporal. La inyección de fallos únicamente
pierde una respuesta ya confirmada; no simula respuestas exitosas.

Resultados de la validación del avance (2026-10-14):

| Verificación | Resultado real |
| --- | --- |
| Backend específico y regresiones de Transporte/navegación | 50 pruebas, 559 aserciones; correctas. |
| Interfaz con API sintética: Gastos, Conductor, sistema y regresión de Facturación | 19 pruebas correctas (5 + 2 + 4 + 8). |
| Contratos frontend existentes | 23 pruebas correctas. |
| E2E real de ambos módulos | 4 pruebas correctas, Chromium + Laravel + PostgreSQL 18.3. |
| TypeScript, build, Pint y diff check | Correctos. |

E2E comprueba captura móvil, archivo privado, revisión por secretaria y aprobación
administrativa; dos registros concurrentes con la misma clave y dos decisiones
incompatibles; permisos del mecánico; respuesta confirmada perdida y reenvío tras
cancelación y liberación manual; compensación de archivo nuevo rechazado sin
eliminar comprobantes históricos. No se ejecutó nuevamente toda la suite global:
se eligieron pruebas afectadas y regresiones pertinentes al alcance.

El entorno utilizado dispone de PHP **8.4.24**, compatible con las dependencias
bloqueadas y Laravel 13; no se afirma haber validado PHP 8.5.

## Pendientes explícitos

- PWA, IndexedDB, captura offline y sincronización durable: siguiente avance.
- Reembolsos, anticipos, desembolsos, medios de pago y reglas contables/fiscales:
  requieren definición; aprobar un gasto no registra un pago ni un desembolso.
- No conversiones CRC/USD ni sumas de monedas diferentes.
- RN-TR-17, D05 y transiciones oficiales de Transporte permanecen sin cambios.
