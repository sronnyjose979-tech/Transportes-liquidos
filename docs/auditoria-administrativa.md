# Auditoría administrativa

## Consulta y acceso

`/auditoria` y `GET /api/audit` son exclusivos del administrador activo mediante
`view-administrative-audit`, reutilizando `TransportCorePolicy::decide`, sesión,
control de cuenta activa y navegación compartida. Secretaria, mecánico y conductor
no tienen acceso. No existen operaciones para editar o eliminar auditoría.

Filtros opcionales: `from`, `to` (`YYYY-MM-DD`, días completos de Costa Rica),
`actor_id`, `module`, `operation` (código exacto visible), `page`, `per_page`
(25 por defecto, máximo 100). Los filtros se aplican antes de combinar fuentes.
Orden estable: registro descendente, fuente e ID. No hay consultas por fila ni
uniones uno-a-muchos. La página y su conteo comparten una transacción de lectura
consistente. No se han añadido índices sin evidencia de necesidad; grandes
volúmenes requieren medir planes con filtros representativos antes de optimizar.

`GET /api/audit/{source}/{id}` devuelve el evento y los cambios disponibles.
`source` pertenece a una lista fija y `id` debe ser UUID; nunca se aceptan nombres
de tablas arbitrarios. Listados y errores llevan `no-store`. El navegador conserva
resultados solo en memoria y los descarta al perder sesión o permisos.

## Cobertura: fuentes originales

| Módulo | Fuente canónica | Qué se muestra |
| --- | --- | --- |
| Flota/documentación | `documents` | Registro documental, actor original y fecha; incluye versiones históricas sin llamarlas renovaciones si no hay evidencia explícita |
| Transporte | `transport_requests`, `trips` | Registro inicial con su responsable, sin inferir cambios posteriores |
| Transporte | `transport_changes` | Cambios y transiciones auditadas |
| Transporte | `transport_assignment_operations` | Asignaciones, sustituciones, conflictos/liberaciones según operaciones existentes |
| Transporte | `transport_authorization_requests`, `transport_authorization_decisions` | Solicitudes y decisiones; no se duplica la aplicación ya representada por la operación correspondiente |
| Taller | `workshop_operations` | Órdenes, diagnósticos, actividades, materiales, tiempos, ingresos/salidas y autorizaciones registradas por el módulo |
| Inventario | `inventory_operations` | Conciliaciones, solicitudes/decisiones/aplicaciones de ajustes, devoluciones |
| Compras | `purchasing_operations`, `purchase_fulfillment_operations` | Solicitudes, decisiones, órdenes y recepciones |
| Facturación | `billing_operations` | Importes propuestos, decisiones, facturas internas y pagos |
| Gastos | `expense_operations` | Registro/correcciones, decisiones y comprobantes |
| Alertas | `document_alert_reviews` | Revisiones documentales persistidas, incluso de documentos reemplazados |

Cada fila se identifica por `(source, id)` original. No se copian eventos ni se
escriben nuevas bitácoras. Un pago/recepción/revisión reenviado idempotentemente
continúa siendo un solo evento. Una solicitud de autorización, su decisión y su
aplicación son operaciones diferentes, no tres copias del mismo hecho.

Las entregas y usos de repuestos ya aparecen en Taller; las recepciones en Compras.
No se añaden otra vez sus movimientos derivados de Inventario. El registro de
Inventario puede referenciar su propio recibo inmutable (`record_type=operation`);
los otros módulos muestran la FK de la entidad afectada. El detalle incluye el ID
de evento y el de registro. El enlace abre la pantalla existente del módulo; no se
inventa una ruta profunda que no tenga soporte actual.

## Seguridad, historia y límites

El actor se representa solo por ID y nombre **actual** de usuario: no se inventa
un snapshot histórico del nombre. Fecha/hora de registro del servidor, presentada
en `America/Costa_Rica`; no se confunde con fecha efectiva indicada en la operación.

Solo `transport_changes` y `workshop_operations` aportan snapshots anteriores y
posteriores. Se selecciona una lista explícita de campos escalares operativos
(estados, versiones, referencias, fechas y cantidades); se omiten textos libres,
metadatos anidados y contenido interno. `null` significa sin snapshot disponible;
una proyección vacía significa sin campos permitidos. No se reconstruye el estado
anterior desde tablas actuales. Nunca se exponen contraseñas, tokens, sesiones,
correos, rutas privadas, claves de almacenamiento, claves de reintento, huellas ni
resultados internos completos. Las evidencias se consultan en su módulo autorizado.

No es una auditoría exhaustiva de todas las ediciones del sistema: altas/cambios
de activos, compartimentos y catálogos sin actor/historial persistido no se
atribuyen retrospectivamente. No se incorporan reportes informativos ni cada fila
operativa de Transporte como supuesto cambio administrativo. Se conservan los
historiales detallados originales de cada módulo. No se altera la lógica operativa,
financiera, documental ni offline, y no se requieren migraciones ni dependencias.

## Validación reproducible

Activar el entorno ya disponible y ejecutar:

```bash
bash tests/run-postgres.sh --filter='AdministrativeAuditTest|SystemNavigationTest|AdministrativeReportsTest|DocumentAlertsTest'
python3 -m unittest discover -s tests/Frontend -p test_audit.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
npm run test:frontend-api
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
bash tests/run-postgres.sh --e2e-audit
vendor/bin/pint --test
git diff --check
```

Backend usa exclusivamente PostgreSQL 18 temporal. `--e2e-audit` utiliza Chromium,
Laravel, sesión y PostgreSQL reales sin API simulada, reutilizando fixtures
sintéticas existentes de operaciones de Transporte, Taller, Inventario,
Facturación y Gastos. Las pruebas frontend/contratos utilizan respuestas sintéticas
y se reportan separadamente. No se ejecutan migraciones sobre `transporte_db`.

### Resultados de esta entrega

- Backend específico y regresión de navegación, reportes y alertas: **25 pruebas,
  595 aserciones correctas**, PostgreSQL 18 aislado. Incluye operaciones reales de
  Taller, compras/recepción, facturación/pagos, gastos, inventario, documentos y
  revisiones; filtros, días completos, paginación y reintentos sin duplicación.
- Interfaz con API sintética: **4 pruebas de auditoría + 4 de navegación/sesión**
  correctas; incluye errores, detalle, permisos y escritorio/tablet/móvil.
- Contratos frontend existentes y nuevos: **29 pruebas correctas**.
- E2E real: **2 pruebas correctas**, Chromium → Laravel → PostgreSQL 18,
  sin respuestas de API simuladas; cotejo con las fuentes, consulta sin escrituras,
  permisos y expiración de sesión.
- TypeScript, build, Pint y `git diff --check`: correctos. El build conserva el
  aviso previo de importación estática/dinámica de `DriverPortal/local.ts`.
- Entorno reutilizado: PHP 8.4.24 compatible con las dependencias instaladas;
  no se afirma validación en PHP 8.5. Sin cambios de `.env` ni `transporte_db`.
