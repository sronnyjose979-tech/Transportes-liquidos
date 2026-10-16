# Reportes administrativos

`/reportes` permite al **administrador activo** consultar datos persistidos de los
módulos existentes. La secretaria, el mecánico y el conductor no tienen acceso a
la página ni a su API. Se reutilizan autenticación por sesión, cuenta activa,
Gates, navegación compartida, estilos azul/blanco y selectores con búsqueda.

## Consultas y filtros

API exclusivamente de lectura: `GET /api/reports/{kind}`. Cada respuesta incluye
`data.rows`, `data.summary`, `data.meta`, una explicación de la fecha/alcance,
`module_href` y zona horaria `America/Costa_Rica`. Paginación por servidor (25 por
defecto, máximo 100); los totales corresponden a **todos** los resultados filtrados,
no solo a la página visible. No hay endpoints de escritura ni nuevos roles.

| kind | Datos y criterio temporal | Filtros específicos |
| --- | --- | --- |
| `transport` | Viajes por fecha de registro, cliente y estados actuales de ejecución, planificación y cierre. Totales agrupados por ejecución/cliente. | Cliente, ejecución, vehículo. |
| `billing` | Facturas por fecha de registro; total, pagado a la fecha y saldo actual de esas facturas. Cobros del período por fecha efectiva de pago, incluso de facturas anteriores. | Cliente, estado actual de pago, CRC/USD. |
| `expenses` | Gasto según fecha e importe de su versión actual. Registrados/paginación y totales por moneda y decisión actual. | Pendiente, aprobado, rechazado; CRC/USD. |
| `workshop` | Órdenes por creación, estado y vehículo; cantidad y fecha de última actividad, contando solo la versión actual de cada actividad histórica. | Estado, vehículo. |
| `stock` | Existencias **actuales** por repuesto: bodega, custodia y consumo neto; catálogo con/sin movimientos. | Repuesto. No admite período. |
| `movements` | Movimientos confirmados de inventario por fecha efectiva, incluidos ajustes/reversiones. | Tipo de movimiento, repuesto. |

Para las consultas temporales: `from` y `to` son días `YYYY-MM-DD` de Costa Rica.
El comienzo es inclusivo y el final es exclusivo al inicio del día siguiente,
conservando la fecha completa y fracciones de segundo. Se traducen los límites a
UTC sin aplicar funciones sobre las columnas temporales. Los parámetros de otros
reportes se rechazan; identificadores, estados y paginación se validan en Laravel.

El vehículo de Transporte incluye cualquier asignación histórica, aunque ya esté
liberada. Se utiliza existencia de relación para no multiplicar viajes. Las
actividades de Taller corresponden a toda la historia de las órdenes seleccionadas;
el período filtra creación de órdenes, no fecha de actividad. El reporte no
reconstruye estados históricos ni disponibilidad pasada.

En Facturación, cliente, moneda y estado actual se aplican tanto a las facturas
como a los cobros. Las fechas de las facturas no restringen la consulta de cobros;
las fechas de cobros no alteran el saldo actual de una factura. Los nombres de
clientes facturados respetan sus snapshots. No son documentos fiscales.

## Integridad y seguridad

- `AdministrativeReports\Application\AdministrativeReports` consulta tablas
  existentes; no crea persistencia, modelos paralelos, migraciones o índices.
- Importes y sumas se calculan exactamente en PostgreSQL y llegan como cadenas.
  No hay float, conversiones CRC/USD, sumas entre monedas o valoración de stock.
- Facturas/detalles y pagos se agregan por separado antes de relacionarlos.
  Versiones sustituidas de gastos y actividades no vuelven a contarse.
- Resumen, filas y paginación se leen en la misma transacción PostgreSQL
  `REPEATABLE READ, READ ONLY`, sin bloqueos de escritura ni operaciones de dominio.
- `view-administrative-reports` reutiliza la comprobación de administrador de
  `TransportCorePolicy`; se valida en página, API y componente Vue.
- Se seleccionan campos públicos explícitos. No se entregan comprobantes, rutas
  privadas, credenciales ni `storage_key`. Respuestas y errores: `private, no-store`.
  Los errores de base no exponen SQL o bindings en la API ni los registran en logs.
- La interfaz invalida resultados antiguos ante una nueva consulta, fallo o cambio
  de sesión; ignora respuestas tardías. No persiste reportes en el navegador.

Una aprobación de gasto no representa pago/reembolso. Un repuesto sin movimientos
muestra **sin saldo registrado**, sin inventar un cero físico. No se suman cantidades
de diferentes repuestos/unidades. Los reportes no modifican Transporte, Taller,
Facturación, Gastos, Inventario ni la PWA offline.

## Pantalla y uso

1. Como administrador, abrir **Reportes** desde el menú compartido.
2. Seleccionar tipo de reporte, período y filtros aplicables; consultar.
3. Revisar totales, tablas y páginas. Monedas y cobros tienen secciones explícitas.
4. Usar **Abrir módulo original para consultar detalles** y localizar el registro
   por el identificador/código mostrado en la fila. Los módulos actuales no tienen
   enlaces profundos por registro; no se inventaron rutas de detalle.

Hay estados de carga, errores de permisos/validación/servidor y resultados vacíos.
Las tablas permiten desplazamiento horizontal en pantallas pequeñas. No hay
exportaciones PDF/Excel, gráficos, indicadores estimados o reglas financieras nuevas.

## Verificación

Entorno aprobado reutilizado, PHP 8.4.24 y PostgreSQL 18.3; no se modificaron `.env`,
`transporte_db` ni datos existentes. Activar las herramientas configuradas antes de:

```bash
bash tests/run-postgres.sh --filter 'AdministrativeReportsTest|SystemNavigationTest'
bash tests/run-postgres.sh --e2e-reports -v
python3 -m unittest discover -s tests/Frontend -p test_reports.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
npm run test:frontend-api
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
vendor/bin/pint --test
git diff --check
```

Las pruebas HTTP con PostgreSQL comprueban permisos, cuentas inactivas/revocadas,
validaciones, ausencia de escrituras, períodos completos, cliente/vehículo/estado,
monedas separadas, pagos múltiples, revisiones de gastos/actividades, inventario
real y vacíos. Las pruebas frontend usan API sintética y se distinguen del E2E
real con Chromium + Laravel + PostgreSQL temporal, que compara los seis reportes
con registros originales, conserva los conteos de hechos/auditoría y verifica
navegación, adaptabilidad, roles y expiración de sesión.


Resultados de esta entrega: **13 pruebas backend / 254 aserciones**, **9 pruebas
frontend** (5 del módulo y 4 de navegación), **26 contratos frontend** (3 nuevos)
y **2 E2E reales** correctos. TypeScript, compilación, Pint y diff check correctos.
No se repitieron suites ajenas ni se alteraron datos de desarrollo.
