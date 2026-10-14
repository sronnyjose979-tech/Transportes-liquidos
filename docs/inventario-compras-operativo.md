# Inventario operativo y órdenes de compra

## Circuito implementado y alcance

Este avance continúa las solicitudes/decisiones de Compra de `000320`. Reutiliza
`SparePart`, `Supplier`, usuarios/roles, órdenes, asignaciones, entregas, usos y
necesidades de Taller. No crea almacenes, trabajadores o catálogos duplicados.

### Inventario

- `/inventario`: disponibilidad en bodega, cantidades contabilizadas bajo custodia,
  consumo, conciliaciones, ajustes, decisiones, aplicaciones e historial.
- Cada repuesto necesita una conciliación física inicial explícita: cantidad observada,
  unidad, fecha, responsable y motivo. Secretaria/administrador solicitan; solo
  administrador decide y aplica. Cero es válido si fue contado y autorizado; antes
  de conciliar, la API devuelve `null`, no existencias supuestas.
- Entrega existente de Taller: disminuye bodega y aumenta custodia de su entrega,
  mecánico y orden; no contabiliza consumo. Todo se confirma en la misma transacción.
- Mecánico confirma uso de su propia entrega/asignación vigente: disminuye custodia y
  aumenta consumo sin volver a descontar bodega ni solicitar aprobación por cada uso.
  Se mantiene autorización previa del alcance de reparación y acceso completo del
  administrador. Otro mecánico de la misma orden no puede consumir la entrega ajena.
- Secretaria/administrador reciben físicamente repuestos no utilizados: registran
  devolución, fecha y motivo; solo entonces custodia disminuye y bodega aumenta.
- Ajustes firmados y reversiones de ajustes se solicitan, deciden y aplican una vez,
  con movimientos compensatorios. No se borran originales ni se permiten negativos.
- Rectificar un consumo con la autorización existente de Taller compensa únicamente
  custodia/consumo. Reducir consumo no devuelve automáticamente existencias a bodega.

### Compras

- `/compras`: solicitudes y necesidades relacionadas, decisiones, órdenes, cantidades
  autorizadas/ordenadas/recibidas/pendientes e historial de recepciones.
- Secretaria/administrador crean solicitudes y órdenes; solo administrador decide la
  autorización de compra. La reparación autorizada no concede autorización de compra.
- Una orden referencia una solicitud aprobada y conserva proveedor, líneas y unidades.
  Las asignaciones de cantidad entre órdenes no pueden superar el total autorizado
  de cada detalle. No se inventan precios ni se implementa valoración monetaria.
- Recepción física parcial o total, registrada por oficina con fecha, responsable,
  cantidades y motivo. Su proveedor proviene de la orden y solicitud originales.
- Cada detalle confirmado genera exactamente una entrada de Inventario en la misma
  transacción. La solicitud/orden/decisión por sí solas no aumentan stock.
- El saldo pendiente se calcula en PostgreSQL con cantidades exactas. No se aceptan
  recepciones por encima de las cantidades autorizadas ni de la orden.

## Persistencia nueva de este avance

| Migración | Responsabilidad |
| --- | --- |
| `000330_create_inventory_ledger` | Seis tablas: operaciones idempotentes, correcciones, decisiones, aplicaciones, devoluciones y movimientos. Integración transaccional con fuentes de Taller. |
| `000340_create_purchase_orders_and_receipts` | Cinco tablas: órdenes/detalles, recepciones/detalles y operaciones idempotentes; vínculo exacto al movimiento de Inventario. |
| `000345_enable_office_purchase_requests` | Amplía solamente creación/consulta de solicitudes a secretaria, preservando decisiones exclusivas del administrador. |
| `000350_protect_approved_purchase_units` | Protege unidades de compras autorizadas y rechaza aprobaciones con unidades incompatibles con el catálogo. |

UUID para entidades nuevas, bigint para usuarios. NUMERIC exacto con hasta seis
decimales y límites equivalentes a NUMERIC(18,6), sin redondeo silencioso. No hay
migración de datos históricos ni modificación de migraciones publicadas.

## APIs

Todas privadas bajo sesión y CSRF existentes:

- Inventario GET: `/api/inventory/stock`, `/movements`, `/custody`, `/corrections`.
- Inventario POST: `/api/inventory/corrections`, `/corrections/{id}/decisions`,
  `/decisions/{id}/apply`, `/returns`.
- Compras reutiliza GET/POST `/api/purchasing/requests`, GET detalle y POST decisiones.
- Compras añade GET/POST `/api/purchasing/orders`, GET `/orders/{id}`,
  POST `/orders/{id}/receipts`, GET `/lookups` para oficina.
- Entregas/usos siguen utilizando las APIs de Taller existentes; no se duplican.

Oficina consulta Inventario y Compras; mecánico consulta su propia custodia y confirma
uso. Ningún conductor obtiene acceso por estos cambios. Los nuevos lookups no conceden
permisos para modificar los catálogos administrativos.

## Integridad y recuperación

Coordinador PostgreSQL `873210` y guardia existente protegen escrituras concurrentes,
incluidas escrituras SQL directas. Las restricciones preservan no negativos, unidades,
cuotas, actor habilitado, origen exacto por clase de movimiento y correspondencia
repuesto/cantidad/fecha/actor de cada recepción. Fuentes, decisiones y aplicaciones
son inmutables y sus referencias únicas impiden repetir efectos.

La idempotencia se liga al actor, ámbito, UUID y huella del contenido exacto; cambiar
contenido con la misma clave produce conflicto. Vue bloquea doble envío y navegación
incierta, conserva la intención en memoria y solo reenvía explícitamente la misma
clave/contenido. Si cambia la identidad o los roles, no reenvía con otra autorización.
No utiliza almacenamiento persistente del navegador ni PWA/offline.

Se corrigió la pérdida de zona horaria/microsegundos al persistir recepciones Eloquent;
los movimientos conservan la misma fecha exacta. También se cerró una posibilidad
SQL de inventar entradas usando una referencia de origen incompatible.

## Límites y decisiones que siguen pendientes

No se declara completo el tratamiento de todos los casos históricos y excepcionales:

1. **Custodia histórica:** entregas previas al módulo no se convierten en movimientos
   automáticamente. No se permite nuevo consumo sin custodia conciliada. Falta definir
   su conciliación explícita por entrega/mecánico, incluyendo entregas corregidas o
   asignaciones ya liberadas. La conciliación inicial implementada confirma bodega.
2. **Reentrega después de devolución:** se conserva la cuota histórica de entregas de
   Taller. Definir si las devoluciones permiten reutilizar esa cuota en la misma orden.
3. **Rectificación física de entregas ya contabilizadas:** se bloquean cambios que
   alteren cantidad/identidad física; necesita un procedimiento de compensación y
   recepción física específico. Rectificación de consumo sí preserva sus reglas.
4. **Reversión de recepción o devolución a proveedor:** no se trata como reversión de
   un ajuste. Falta definir su efecto sobre cumplimiento de compra y sus evidencias.
5. **Ampliar una orden para sobre-recepción:** falta el contrato de autorización
   adicional vinculado a cantidades concretas. El exceso se rechaza; pueden registrarse
   cantidades adicionales mediante otra solicitud expresamente aprobada y su orden.
6. **Valoración y precios:** no implementados; el circuito actual es cuantitativo.

## Cómo probar

1. Registrar repuesto/proveedor en `/administracion/catalogos`.
2. Solicitar, aprobar y aplicar su conciliación física en `/inventario`.
3. Registrar necesidad de Taller y solicitud en `/compras`; administrador la decide.
4. Crear orden y confirmar recepciones físicas parciales; comprobar entradas y saldos.
5. Entregar desde la orden de Taller a un mecánico; comprobar bodega/custodia.
6. Mecánico confirma utilización; oficina recibe devolución del sobrante.
7. Consultar historial, actores y cantidades; probar reenvío manual sin duplicaciones.

## Validaciones reproducibles

```sh
source /workspace/.tools/activate.sh
npm run typecheck
npm run build
npm run test:frontend
npm run test:frontend-api
bash tests/run-postgres.sh
CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-supply
CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-workshop-execution
vendor/bin/pint --test
git diff --check
```

PostgreSQL 18 temporal, Chromium y Laravel reales; usuarios y cantidades sintéticos.
No se utiliza `transporte_db` ni se modifica `.env`. Sin commit/push.

### Resultados ejecutados

- TypeScript, build, Pint y `git diff --check`: correctos.
- Backend completo: **328 pruebas, 3.126 aserciones** correctas. Tras mejorar la
  identificación legible de actores/custodios se reejecutó la suite afectada:
  **21 pruebas, 157 aserciones** correctas.
- Frontend con API interceptada: **66 pruebas correctas**. Suite Supply reejecutada
  tras los últimos ajustes: **8 pruebas correctas**.
- Contratos frontend: **23 pruebas correctas**.
- E2E real nuevo: **1 recorrido correcto, 39,082 s**, Chromium/Laravel/PostgreSQL 18.3,
  `/tmp/transporte-tests-NTF32l/e2e/browser`. Conciliación cero autorizada → necesidad →
  compra/orden → recepciones 3+2 → entrega 4 → consumo 2 → devolución física 2;
  resultado bodega 3, custodia 0, consumo 2. Incluye respuesta de recepción perdida,
  reenvío idempotente, excesos rechazados, permisos y responsive.
- E2E real de Taller existente: **3 recorridos correctos, 125,939 s**,
  `/tmp/transporte-tests-JqUHN9/e2e/browser`, con conciliaciones sintéticas explícitas.

No se simuló éxito de recepciones, compras o stock en las suites reales. La suite
frontend interceptada se distingue expresamente de esas verificaciones.
