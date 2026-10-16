# 01. Introducción: qué resuelve el sistema


## Empresa, problema y alcance

La empresa transporta líquidos para clientes externos, cobra por viaje y repara su propia flota. Opera cabezales y cisternas independientes: cambiar de conductor no cambia automáticamente el equipo; una cisterna puede trabajar con diferentes cabezales. La unidad de volumen es el litro. Una solicitud contiene un producto líquido y puede originar varios viajes; cada viaje conserva un único producto histórico.

El problema no se reduce a registrar vehículos. Es necesario coordinar recursos ocupados, documentos próximos a vencer, averías, autorización de trabajos, materiales entregados a mecánicos y cobros. Cada operación debe conservar quién la realizó y qué información utilizó. Una **trazabilidad** es precisamente esa cadena verificable entre origen, decisión y resultado.

El sistema implementa administración de recursos, documentación de flota, Transporte y Despacho, Taller, Inventario cuantitativo, Compras, facturación administrativa, pagos, Gastos, portal del conductor, reportes, alertas, auditoría y respaldo mediante consola. El [capítulo 03](03-modulos.md) separa sus responsabilidades y el [capítulo 12](12-casos-practicos.md) los recorre con ejemplos.

## Usuarios reales

Un **rol** agrupa atribuciones; un **permiso** responde si la cuenta puede ejecutar una operación. Los identificadores técnicos están en inglés y las etiquetas de las pantallas están en español.

| Rol | Uso habitual | Límites esenciales |
| --- | --- | --- |
| `administrator` | Inicio general, recursos, autorizaciones, consultas y operación administrativa | Las reglas de integridad siguen aplicando: no puede convertir una autorización en un permiso universal ni borrar historia |
| `secretary` | Coordinación de Transporte y Taller, documentación, Compras, Gastos y facturación administrativa | No aprueba importes, gastos, excepciones o reparaciones reservadas al administrador |
| `mechanic` | Órdenes asignadas, diagnósticos, actividades, tiempos, evidencias y uso real de repuestos | No crea órdenes oficiales ni autoriza reparaciones; sus accesos se filtran por participación |
| `driver` | `/mis-viajes`, lecturas, reportes y gastos de viajes asignados, captura offline | No inicia/finaliza oficialmente viajes ni aprueba gastos; el servidor revalida la asignación al recibir información |

Una cuenta puede tener roles; un empleado puede tener una cuenta opcional. No son la misma entidad. Los choferes y mecánicos se identifican a partir de empleados/cuentas habilitados; no hay que crear tablas paralelas para cada oficio.

## Qué significa “implementado” en este manual

Se describe el código de `main` usado como base, `4eb7da35307f0f6927e88d57f49abaaffd887aad`. El inventario físico se contrastó con las migraciones y un esquema nuevo aislado, no con datos de la empresa. “Existe” significa que hay código y contrato identificables; no significa que esté desplegado, configurado o validado en teléfonos físicos.

Los documentos de etapas anteriores conservan propuestas que después se implementaron o cambiaron por decisiones autorizadas. Ante una diferencia, estudiar código, migraciones incrementales y la aclaración más reciente. Por ejemplo, el avance inicial de Inventario no tenía existencias; el circuito actual sí tiene movimientos reales. El avance inicial del portal no era offline; ahora tiene IndexedDB y sincronización. No leer esos textos históricos como descripción exclusiva del presente.

## Límites que afectan el uso

- Facturación produce documentos **administrativos internos**, sin numeración fiscal, impuestos ni comprobantes electrónicos. CRC y USD permanecen separados. Correcciones posteriores a facturación y reversiones de pagos necesitan procedimiento aprobado.
- RN-TR-17 no define qué hacer ante un permiso de salida obligatorio ausente. No se inventa un bloqueo ni una excepción.
- La sustitución de cisterna está bloqueada por D05: no existe un procedimiento aprobado para comprobar que está vacía. Cambios de producto y ciertos tratamientos de cancelación continúan limitados por sus decisiones pendientes.
- La vigencia documental implementada corresponde a activos de flota; no hay un modelo documental de personal que permita fingir alertas de empleados.
- Inventario controla cantidades y custodia; no inventa valoración, tipos de cambio ni movimientos de compras aún no recibidas.
- Un gasto aprobado no acredita pago ni reembolso. Anticipos, desembolsos y reglas contables no se deducen del estado de aprobación.
- La PWA sincroniza con la aplicación abierta y conexión recuperada. El navegador puede limitar o eliminar almacenamiento; no existe garantía de sincronización con la aplicación cerrada. Safari/iOS y suspensión real requieren validación específica.
- Respaldos requieren configuración y operación del servidor. Que el comando exista no demuestra cron instalado, copias externas ni un tiempo de recuperación aprobado.

## Cómo estudiar

Leer 02 para entender las capas; 03 y 04 para el negocio; 05 y 06 para persistencia/contratos; 07 y 08 para navegador; 09 y 10 para seguridad/operación; 11 y 12 para comprobar los recorridos. El [índice final](15-indice-y-mapa-del-proyecto.md) contiene los mapas completos y las fuentes.
