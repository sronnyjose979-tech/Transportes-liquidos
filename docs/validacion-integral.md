# Validación integral del sistema

**Fecha:** 16 de octubre de 2026. **Base:** `a0e78c4fdd77f3aed0e2e1a72b744598c54fa89d`.
Entorno reutilizado: Laravel 13.35.0, PHP 8.4.24, PostgreSQL 18.3, Vue 3/TypeScript,
Node 24.19.0, Playwright 1.62.0 y Chromium 154.0.8037.57. No se instalaron dependencias,
no hubo despliegue ni cambios en `.env` o `transporte_db`. Todos los datos y archivos
son sintéticos. El PHP ejecutado es 8.4, no se afirma validación en 8.5.

## Procesos verificados

| Circuito | Evidencia y resultado |
| --- | --- |
| Transporte y facturación | Solicitud con cliente/producto/ruta → programación → conductor/cabezal/cisterna → inicio oficial → carga/descarga por compartimento → finalización D02. Diferencias de litros exigen autorización específica. Viajes finalizados con importe aprobado → factura interna → pagos parciales/total; CRC y USD separados, saldo exacto y rechazo de duplicación/sobrepago. |
| Taller | Avería → orden independiente → diagnóstico/alcance → autorización → actividades/tiempos/materiales → terminación/cierre. Ingreso, impedimentos y salida autorizada conservan historial y disponibilidad de Transporte; no liberación automática. |
| Compras e inventario | Necesidad → solicitud/aprobación → orden → recepciones parciales → movimientos reales. Conciliación sintética autorizada, entrega a mecánico, consumo comunicado y devolución física; no stock negativo ni duplicación por reintentos. |
| Gastos y conductor | Gastos generales/de viaje, CRC/USD, comprobantes privados, correcciones y decisión administrativa. Conductor limitado a viajes asignados; reenvío confirmado recuperable y nueva operación tras liberación rechazada. No altera pagos de clientes. |
| Documentación y alertas | PDF/JPG/PNG privados, límite exacto de 10.000.000 bytes, renovación y documento actual único con historial inmutable; expirados/próximos/sin vencimiento, revisión persistida única y filtros. |
| Administración | Alta/edición de Flota, compartimentos, empleados/cuentas y catálogos; recursos disponibles en selectores reales de Transporte, Taller y Documentación. Rutas utilizadas conservan restricciones históricas. |
| Reportes y auditoría | Conciliación con registros originales, filtros y paginación; agrupaciones sin multiplicación por relaciones, monedas separadas y eventos canónicos únicos. Lectura sin cambios operativos ni datos privados de almacenamiento. |
| PWA | Chromium persistente: consulta/capturas offline de kilometraje, reportes, gastos y comprobante; recarga y reinicio del navegador, reconexión, sincronización, sesiones/cuentas y conflictos. Se valida el archivo descargado y una sola persistencia por operación. |
| Respaldos | La suite backend incluye creación cifrada, restauración real de base/archivos en destinos nuevos, integridad y fallos parciales; ninguna restauración operativa. |

Se reutilizaron suites existentes: los circuitos se comprueban con fixtures sintéticas,
servicios, HTTP y navegador según cada prueba; no se presenta todo el sistema como una
única sesión monolítica. Las suites E2E usan Laravel/PostgreSQL reales, sin respuestas
API simuladas; el proxy solo interrumpe conexiones o descarta respuestas para probar recuperación.

## Resultados finales

| Verificación | Resultado |
| --- | --- |
| Backend completo, PostgreSQL 18 aislado | **391 pruebas, 4.124 aserciones correctas** |
| Frontend Chromium/IndexedDB con API sintética | **106 pruebas correctas** |
| Contratos frontend Node | **29 pruebas correctas** |
| E2E reales Chromium + Laravel + PostgreSQL 18 | **53 recorridos correctos, 15 suites** |
| TypeScript, build, Pint y diff check | Correctos; build con aviso previo de importación estática/dinámica de `DriverPortal/local.ts` |

Desglose E2E: Transporte 4; Facturación 3; Taller 10;
Compras/Inventario 2; Gastos/Conductor 4; PWA 3;
Documentación 14; Alertas 2; Reportes 2; Auditoría 2;
Navegación 4; Administración 3.

Las aserciones cuantificadas son las informadas por PHPUnit. Las suites Python/Node
informan pruebas/recorridos, sin sumar un número de aserciones que no publican.

Permisos comprobados: administrador, secretaria, mecánico y conductor; acceso a
registros ajenos y archivos privados, operaciones directas sin frontend, sesiones
expiradas, usuarios inactivos y roles revocados. Incluye CSRF real 419 y errores
401/403/404/409/422, fallos de almacenamiento y respuestas perdidas.
Concurrencia verificada en asignaciones, autorizaciones, recepciones/stock, documentos,
gastos y pagos; los reintentos confirmados conservan un único resultado.

## Errores encontrados y correcciones

- **Entorno:** cinco suites de Taller/Compras intentaban abrir el Chromium descargado
  de Playwright, ausente aquí. Se repitieron únicamente las afectadas con
  `CHROMIUM_EXECUTABLE=/usr/bin/chromium`; no se instaló otro navegador.
- **E2E de Administración desactualizado:** esperaba enlaces del menú anterior y
  acceso incrustado. Se actualizaron los selectores al menú compartido actual y se
  verificó el enlace de inicio de sesión, ausencia de formularios privados y denegación
  de Flota/Personal/Catálogos para secretaria. No se cambiaron permisos ni pantallas.
- **Reloj del E2E documental:** congelar globalmente una fecha histórica vencía las
  cookies nativas del navegador. Se reutilizó `real-clock` y fechas relativas a
  Costa Rica para +15/+16 días, hoy y ayer. Se interrumpió la corrida obsoleta y se
  repitió completa. Las pruebas backend de 23:59:59/00:00 y vigencia del día completo
  permanecieron intactas (`FleetDocumentTest`, `FleetDocumentApiTest`, `DocumentAlertsTest`).
- No se demostraron defectos de lógica operativa que requirieran modificar módulos.
  Solo se modificaron dos archivos E2E y este informe. No se relajaron restricciones
  ni se sustituyeron verificaciones reales por simulaciones para obtener éxito.

No se repitieron backend/frontend/contratos aprobados tras cambios exclusivos en los
E2E afectados. Los intentos iniciales fallidos/interrumpidos no se cuentan como aprobados.

## Reproducción

En el entorno ya preparado, activar `/workspace/.tools/activate.sh`. PostgreSQL 18
se selecciona con `POSTGRES_TEST_BIN`; Chromium debe apuntar al ejecutable instalado:

```bash
export CHROMIUM_EXECUTABLE=/usr/bin/chromium
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
npm run test:frontend
npm run test:frontend-api
POSTGRES_TEST_DATA_ROOT=/workspace/scratch/integral-db bash tests/run-postgres.sh
# E2E: no definir POSTGRES_TEST_DATA_ROOT; el bootstrap verifica su clúster temporal.
for suite in --e2e-transport --e2e-billing --e2e-workshop --e2e-workshop-execution \
  --e2e-workshop-circuit --e2e-purchase-requests --e2e-supply --e2e-expenses-driver \
  --e2e-driver-offline --e2e-document-alerts --e2e-reports --e2e-audit \
  --e2e-navigation --e2e-administration --e2e; do
  bash tests/run-postgres.sh "$suite" || exit 1
done
vendor/bin/pint --test
git diff --check
```

El backend usó el volumen de trabajo para los datos temporales por capacidad de `/tmp`.
Los E2E usaron clústeres separados bajo `/tmp`, conservando logs, capturas y trazas
fuera de Git. No ejecutar build mientras el E2E offline comprueba la actualización del SW.

## Escenarios no cubiertos y límites antes de producción

- No se probaron carga sostenida, hardware móvil real, Safari/iOS, todos los escenarios
  de pérdida de energía ni infraestructura de producción. No es una auditoría externa
  de seguridad ni demuestra RPO/RTO o existencia de una copia externa configurada.
- Offline requiere datos previamente descargados y la aplicación abierta para sincronizar.
  Borrar datos del navegador puede perder capturas pendientes; IndexedDB no es respaldo.
- Siguen pendientes RN-TR-17, D05/sustitución de cisterna, cambios de producto y cancelación
  posterior a finalización; no se forzaron operaciones bloqueadas por esas decisiones.
- Facturación es administrativa interna: no emisión fiscal, impuestos ni documentos legales.
  Anulaciones/correcciones posteriores y reversiones financieras necesitan procedimientos
  aprobados; no se inventaron reglas comerciales o de valoración de Inventario.
- Auditoría cubre evidencia existente; no reconstruye ediciones de catálogos sin historial.
  Alertas de personal siguen fuera hasta existir un modelo documental aprobado.
- Mantener configurados y supervisados el Scheduler, mantenimiento/drenaje de escritores,
  custodia de claves, destino externo, retención y simulacros de recuperación.

La validación del alcance implementado fue satisfactoria; no equivale a autorización de
despliegue ni a declarar el sistema listo para producción con decisiones pendientes.
