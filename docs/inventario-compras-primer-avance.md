# Inventario y Compras — avance parcial

> Documento del primer avance parcial. El desarrollo posterior del circuito cuantitativo
> se describe en [Inventario y Compras operativo](inventario-compras-operativo.md).

## Alcance implementado

La revisión confirma catálogos existentes `SparePart` y `Supplier`, y necesidades,
entregas y usos de Taller ya operativos. Se reutilizan sin crear entidades duplicadas.

- `/administracion/catalogos`: gestionar repuestos (código, descripción, unidad) y
  proveedores (nombre), usando sus APIs existentes.
- `/administracion/compras`: registrar, buscar y consultar solicitudes de compra;
  vincular líneas a necesidades reales de Taller y aprobar/rechazar el contenido
  exacto, con historial y cantidades decimales como texto.
- Administrador activo: operaciones independientes disponibles por su acceso completo.
  Los permisos de solicitud/recepción para otros roles siguen sujetos a definición.
- Una solicitud/decisión no es una compra ejecutada, recepción ni movimiento de stock.
  No existen pantallas que presenten existencias supuestas o compras ficticias.

No se implementa aún Inventario operativo, órdenes de compra ni recepciones: los
contratos que determinan sus efectos físicos siguen pendientes. El avance no se
presenta como el circuito completo solicitado.

## Persistencia y contratos

Migración nueva `2026_10_13_000320_create_purchase_requests.php`:

| Tabla | Responsabilidad |
| --- | --- |
| `purchase_requests` | Proveedor existente, solicitante autenticado, motivo y fecha. |
| `purchase_request_items` | Repuesto existente, cantidad exacta, snapshots de código/descripción/unidad y necesidad de Taller opcional. |
| `purchase_request_decisions` | Una decisión administrativa inmutable por solicitud, actor, fecha y motivo. |
| `purchasing_operations` | Clave UUID, ámbito/actor, huella del contenido y referencias originales de recuperación idempotente. |

UUID para nuevas entidades, bigint para usuarios. Cantidades exactas con hasta seis
decimales y límites equivalentes a NUMERIC(18,6), sin redondeo silencioso al escribir.
Solicitudes, líneas, decisiones y recibos son inmutables en PostgreSQL. No se admiten
solicitudes sin líneas, registros sin auditoría ni vínculos con necesidades de otro
repuesto. Se preservan las unidades históricas; el catálogo puede cambiar posteriormente.

API privada bajo sesión/CSRF y autorización administrativa vigente:

- `GET /api/purchasing/requests`: paginación.
- `POST /api/purchasing/requests`: proveedor, motivo, líneas, `request_key` UUID.
- `GET /api/purchasing/requests/{id}`: detalle e historial de decisión.
- `POST /api/purchasing/requests/{id}/decisions`: `approved`/`rejected`, motivo y clave.

No hay actualizaciones ni eliminaciones históricas. Un nuevo intento con la misma
clave/contenido recupera las mismas referencias sin repetir efectos; contenido distinto
produce 409. El coordinador PostgreSQL 873210 serializa confirmaciones y se revalida
la cuenta/rol actual, compatible con la revocación de permisos existente. Errores se
representan sin SQL ni datos privados y con `Cache-Control: private, no-store`.

La interfaz conserva la intención exacta en memoria para reenvío manual con la misma
clave. No usa localStorage/IndexedDB ni reintentos automáticos. Cambiar de actor impide
reenviar operaciones ajenas. El éxito de escritura se distingue del refresco posterior.

## Decisiones indispensables pendientes

Fuentes: `docs/propuesta-tecnica.md` (apartado Taller, Inventario y Compras) y
`docs/etapa-6-2.md` (contrato futuro y T01/T09). Las reglas de Taller no autorizan
implícitamente una compra ni convierten registros históricos en stock.

1. **Descuento y entrega:** elegir entrega física o uso confirmado como único evento
   de salida; si se elige uso, definir tratamiento/reserva de lo ya entregado.
2. **Compras y recepción:** quién registra solicitudes/recepciones; varias órdenes por
   solicitud; recepción parcial y control del cumplimiento autorizado.
3. **Ajustes, reversiones y devolución:** motivos, condiciones físicas, solicitantes y
   aprobación previa de repuestos/cantidades; tratamiento de rectificaciones de hechos
   de Taller ya vinculados a movimientos.
4. **Conciliación inicial:** cómo registrar la existencia física inicial y tratar
   entregas/usos anteriores sin inventar cantidades ni descontarlos automáticamente.
5. **Valoración:** método y conversiones pendientes; no bloquea un futuro control
   exclusivamente cuantitativo, pero no se presentarán importes de stock supuestos.

Estas definiciones no se sustituyen por recomendaciones técnicas ni por una política
implícita. La autorización del trabajo independiente no selecciona los efectos físicos
pendientes.

## Pruebas reproducibles

```sh
source /workspace/.tools/activate.sh
npm run typecheck
npm run build
npm run test:frontend
npm run test:frontend-api
bash tests/run-postgres.sh
CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-purchase-requests
vendor/bin/pint --test
git diff --check
```

Las bases son exclusivamente temporales y aisladas PostgreSQL 18. El E2E real cubre
necesidad de Taller → solicitud → decisión, sin API simulada. Recepción → stock →
entrega/uso no se ejecuta ni se simula mientras falten las decisiones anteriores.
No se modifica `.env`, `transporte_db` ni migraciones publicadas. Sin commit/push.

### Resultados ejecutados

- TypeScript, build, Pint y `git diff --check`: correctos.
- Backend completo PostgreSQL 18: **318 pruebas, 3.023 aserciones** correctas; incluye
  permisos, integridad SQL, historial y concurrencia real de solicitudes/decisiones.
- Frontend con API interceptada: **58 pruebas correctas**. Tras reforzar recuperación
  de operaciones inciertas, se reejecutaron las **4 pruebas de solicitudes**, correctas:
  cancelar el diálogo de reintento y refrescar no descarta la clave pendiente.
- Contratos frontend: **23 pruebas correctas**.
- E2E real parcial: **1 prueba correcta, 17,429 s**, PostgreSQL 18.3, Chromium y Laravel;
  artefactos `/tmp/transporte-tests-UHNYTC/e2e/browser`.
- **No ejecutado:** recepción → movimiento → entrega/uso con stock real. El flujo
  depende de las decisiones pendientes y no se sustituye por respuestas simuladas.
