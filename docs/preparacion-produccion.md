# Auditoría de preparación para producción

Fecha: 16 de octubre de 2026. Código revisado: `ba9f5f44cf912a5171f95c792f29ae375be2dbc8`,
coincidente con `main` remoto al comenzar. Alcance: inspección de código y documentación;
no se inspeccionó un servidor de producción, no se leyeron secretos, no se modificaron
configuración, código operativo ni bases de datos. Este informe no autoriza un despliegue.

## Evidencia y clasificación

Se reutiliza [la validación integral](validacion-integral.md): 391 pruebas backend / 4.124
aserciones, 106 pruebas frontend con API sintética, 29 de contratos y 53 E2E reales
en 15 suites; TypeScript, build y Pint correctos. Son resultados de la validación anterior,
no ejecuciones nuevas de esta auditoría. Aplican al código operativo actual: el commit
posterior solo ajustó dos E2E y documentó sus resultados. No equivalen a pruebas de carga,
auditoría externa de seguridad ni validación de infraestructura real.

**Comprobado** significa comportamiento o carencia observable en el código/documentos;
**riesgo por verificar** no afirma un incidente ni una configuración insegura del servidor;
**decisión pendiente** requiere definición funcional. Severidad refleja el impacto si se
materializa. No se demostró una vulnerabilidad crítica, corrupción financiera ni pérdida
de aislamiento entre usuarios. Los riesgos altos siguientes requieren atención antes
de autorizar el uso productivo correspondiente.

## Trabajos priorizados

Las rutas de evidencia son relativas a la raíz del repositorio. «Bloqueo» distingue
requisitos generales de salida de restricciones sobre una funcionalidad concreta.

| Prioridad / hallazgo | Evidencia concreta | Severidad | Impacto | Acción recomendada | ¿Decisión del negocio? | Bloqueo |
| --- | --- | --- | --- | --- | --- | --- |
| P0-01. Riesgo por verificar: configuración efectiva de seguridad del despliegue | `AGENTS.md`, §8; `config/app.php` (`debug`, `url`); `config/session.php` (`secure`, dominio, SameSite); `bootstrap/app.php` | Alta | Debug, HTTP, cookies o proxy mal configurados podrían exponer información o comprometer sesiones. Los valores efectivos de producción no se han auditado. | Verificar HTTPS y redirección, `APP_DEBUG=false`, dominio/URL, cookie Secure/HttpOnly, confianza de proxy limitada, exposición exclusiva de `public/`, ausencia de caché compartida de respuestas privadas y acceso restringido a PostgreSQL. Probar login/CSRF y descarga privada en el destino antes de habilitar usuarios. | No; asignar responsable técnico. | Sí, hasta aportar evidencia del destino. |
| P0-02. Riesgo por verificar: recuperación operativa y copia externa sin acreditación | `config/backups.php`; `docs/respaldos-y-recuperacion.md`, configuración y límites; `tests/Feature/BackupRecoveryTest.php` | Alta | Una avería del servidor podría dejar sin datos/archivos recuperables aunque las pruebas aisladas pasen. | Aprobar RPO/RTO, frecuencia, retención y responsables; configurar copia aislada, clave externa y custodia separada de `APP_KEY`/configuración; ensayar restauración en servidor de recuperación y medir tiempos. | Sí: tolerancia a pérdida e interrupción, retención y responsables. | Sí, antes de almacenar datos operativos reales. |
| P0-03. Comprobado: respaldo automático requiere ventana y drenaje explícitos | `routes/console.php`; `config/backups.php`; `BackupRecovery/Application/BackupArchive.php::create` | Alta | Programar el comando no detiene escritores; sin mantenimiento falla, y una declaración incorrecta de drenaje puede invalidar la consistencia conjunta base/archivos. | Preparar procedimiento que detenga admisión de escrituras, drene HTTP/cargas/procesos/reconciliadores, entre en mantenimiento, capture/verifique y reactive; supervisar salida y antigüedad de copia. `BACKUP_WRITERS_STOPPED` es una declaración operativa, no un verificador. | Sí: ventana operativa; ejecución técnica. | Sí, para considerar automatizada la protección de datos. |
| P0-04. Corregido: saneamiento centralizado de errores SQL | `bootstrap/app.php`, callbacks `report(QueryException)` y `render(QueryException)`; `tests/Feature/QueryExceptionSafetyTest.php` | Alta (hallazgo original; resuelto en código) | Se eliminó la caída al reporte SQL predeterminado. Todas las rutas y consola registran únicamente evento fijo y SQLSTATE validado; las respuestas genéricas no contienen payload de debug. | Mantener la regresión y verificar configuración productiva según P0-01. Se conservan los contratos HTTP específicos de los módulos y el 500 para fallos genéricos. | No. | No: corrección y regresiones verificadas; no implica que los demás bloqueos estén resueltos. |
| P0-05. Decisión pendiente: facturación interna no sustituye emisión fiscal | `docs/facturacion-administrativa-y-pagos.md`, límites; `ARCHITECTURE.md`, Facturación administrativa | Alta | Utilizar documentos internos como comprobantes legales produciría un uso no soportado. | Mantener identificación interna; acordar proceso externo legal/fiscal y conciliación o aprobar una entrega fiscal posterior. No agregar impuestos/numeración por inferencia. | Sí. | Sí para sustituir facturación legal; no bloquea por sí sola un piloto interno con proceso fiscal externo definido. |
| P0-06. Decisión pendiente: correcciones posteriores a factura y reversión de pagos | `docs/facturacion-administrativa-y-pagos.md`, límites; `BillingAndPayments/Application/BillingPayments.php`; migración `000360` | Alta | Un error financiero confirmado no dispone del circuito aprobado de compensación; editar SQL vulneraría auditoría/saldos. | Aprobar responsables y procedimiento compensatorio para anulaciones, correcciones y pagos erróneos antes de operar cobros reales. Conservar la inmutabilidad actual. | Sí. | Sí para operación financiera real sin un procedimiento seguro aprobado. |
| P1-01. Riesgo por verificar: permisos efectivos y custodia de secretos | `config/database.php`; `.gitignore`; `AccessAndPersonnel/Console/BootstrapAdministrator.php`; `AGENTS.md`, §8 | Alta | Un usuario SQL propietario/superusuario puede eludir protecciones; pérdida de claves o acceso administrativo compromete recuperación y confidencialidad. | Separar usuario de aplicación, migración, administración y respaldo; verificar privilegios mínimos y permisos de archivos. Crear administrador por comando interactivo; custodiar y rotar secretos fuera de Git. Verificar recuperación de acceso sin inventar cuentas por defecto. Solo `.env.example` está versionado; no se hizo análisis forense completo del historial Git. | No; acordar responsables de custodia. | Sí: comprobar antes del despliegue. |
| P1-02. Riesgo por verificar: migración y publicación en el destino real | `composer.json`, script `setup`; `database/migrations/`; `docs/validacion-integral.md`, entorno | Alta | `composer setup` genera clave y migra automáticamente; no es un procedimiento seguro para una actualización productiva. Locks, triggers y locales de PostgreSQL requieren entorno compatible. | Ensayar migraciones desde la versión instalada sobre copia aislada, medir bloqueos, comprobar PG18/extensiones/locales y dependencias PHP. Publicar desde locks con dependencias productivas, compilar con el modo PWA aprobado, preservar `APP_KEY`, verificar cachés/configuración/rutas y ejecutar smoke test. Preparar recuperación antes de cambios irreversibles; no confiar en un rollback genérico. | No; aprobar ventana de despliegue. | Sí: ensayo y procedimiento previos. |
| P1-03. Comprobado: captura offline no es una copia de seguridad | `resources/js/modules/DriverPortal/local.ts` (`captures`, `cachedTrips`, `lockLocal`); `docs/conductor-offline.md`, límites | Alta | Limpieza/evicción del navegador o pérdida del teléfono puede perder comprobantes/capturas sin sincronizar. El bloqueo de sesión no cifra físicamente IndexedDB; acceso al perfil o XSS queda fuera del aislamiento lógico probado. | Exigir bloqueo del dispositivo y perfiles personales, revisar pendientes antes de borrar/reasignar equipos y sincronizar al recuperar conexión con la aplicación abierta. Informar el límite a conductores; aprobar retención de capturas y tratamiento de equipos perdidos. No borrar pendientes automáticamente. | Sí: uso de equipos, retención y aceptación del riesgo offline. | Condicional: política y capacitación antes de depender del offline. |
| P1-04. Decisión pendiente: permisos de salida obligatorios y sustitución de cisterna | `docs/etapa-5-6.md`, riesgos; `docs/validacion-integral.md`, límites; reglas RN-TR-17 / D05 | Alta | No existe conducta aprobada ante permiso obligatorio ausente; la sustitución de cisterna sigue bloqueada. | Definir el alcance operativo permitido y procedimiento humano para situaciones no soportadas; resolver reglas antes de habilitar esas operaciones. No interpretar autorizaciones de conflicto como disponibilidad mecánica. | Sí. | Condicional: no operar escenarios que dependan de estas decisiones. |
| P1-05. Corregido: creación idempotente de solicitudes y viajes | `TransportAndDispatch/Application/TransportAdministration.php::createOnce`; migración `000400`; `tests/Feature/TransportCreationIdempotencyTest.php`; `tests/E2E/test_transport_module.py` | Media (hallazgo original; resuelto en código) | Una clave por usuario conserva el resultado original; cambios de contenido/tipo dan 409. El frontend mantiene la intención en reintentos y la clave tras recarga. | Mantener recibos inmutables y regresiones; revisar limitaciones de pestaña descritas al final. No se añade unicidad comercial. | No. | No para las dos altas identificadas. |
| P1-06. Riesgo por verificar: observabilidad, rotación y capacidad | `config/logging.php` (stack/single predeterminado, daily disponible); `routes/console.php`; `/up` en `bootstrap/app.php` | Media | Logs sin rotación pueden llenar disco; tareas fallidas o una aplicación que responde HTTP con DB indisponible pueden pasar inadvertidas. `/up` no acredita por sí solo disponibilidad de todas las dependencias. | Configurar rotación, acceso restringido, umbrales de disco, comprobación de PostgreSQL/almacenamiento y alertas operativas por fallos de scheduler/respaldo. Usar capacidades del servidor; no construir otro módulo de notificaciones. | No; asignar responsable y umbrales. | Comprobar supervisión mínima antes de operar. |
| P1-07. Riesgo por verificar: scheduler y reconciliación de archivos | `routes/console.php`; `FleetAndDocuments/Console/ReconcileDocumentStorage.php`; `Expenses/Infrastructure/PrivateReceiptStorage.php` | Media | Sin cron no se ejecuta la consulta diaria; fallos parciales pueden dejar archivos pendientes ocupando espacio. La consulta no envía avisos externos, por diseño. | Configurar `schedule:run`, zona horaria y cache de locks persistente; comprobar salida y supervisar ejecución. Documentar reconciliación conservadora y revisión de pendientes fuera de la captura de respaldo. | No. | Condicional: necesario para prometer automatización, no para consultar alertas manualmente. |
| P1-08. Riesgo por verificar: almacenamiento y límites HTTP | `config/filesystems.php`; `config/workshop.php`; `FleetAndDocuments/Infrastructure/PrivateDocumentStorage.php`; `Expenses/Infrastructure/PrivateReceiptStorage.php`; `Workshop/Infrastructure/PrivateWorkshopFiles.php` | Media | Permisos web incorrectos, cuotas agotadas o límites PHP/proxy menores al contrato impedirían archivos válidos; algunos flujos necesitan disco local y locks de archivos. | Verificar raíces privadas fuera de publicación, permisos, espacio, máximo de 10.000.000 bytes y margen multipart; configurar PHP/proxy coherentes. Mantener inicialmente una instancia con almacenamiento persistente; no asumir compatibilidad multiinstancia o S3 sin validarla. | No. | Validación del destino necesaria; no se demostró exposición actual. |
| P2-01. Riesgo por verificar: rendimiento de agregados y serialización | `AdministrativeReports/Application/AdministrativeReports.php`; `AdministrativeAudit/Application/AdministrativeAudit.php`; `TransportAndDispatch/Application/TransportAdministration.php::transaction`; `AccessAndPersonnel/Application/AccountAdministration.php` | Media | Paginación no elimina el coste de totales/COUNT/UNION; el lock común `873210` serializa escrituras entre módulos. No hay medición que demuestre lentitud con la flota actual. | Medir tiempos, espera de locks y EXPLAIN con volumen representativo; privilegiar períodos acotados. Añadir índices solo con evidencia. No reemplazar transacciones ni introducir infraestructura distribuida preventivamente. | No; acordar tolerancias operativas. | No general; medir antes de crecimiento/carga significativa. |
| P2-02. Riesgo por verificar: navegador y móvil reales | `docs/conductor-offline.md`, requisitos; `DriverPortal/local.ts::sessionLock`; `docs/validacion-integral.md`, escenarios no cubiertos | Media | Web Locks/IndexedDB y persistencia difieren por navegador; iOS, suspensión del sistema y todos los cortes de energía no fueron probados. | Definir Chromium soportado, piloto en teléfonos reales con sesión expirada/conflictos y comprobantes; advertir TTL de 24 horas del caché de consulta y sincronización solo con aplicación abierta. | Sí: dispositivos/navegadores admitidos. | Condicional: no prometer soporte de plataformas no validadas. |
| P2-03. Comprobado: cobertura de auditoría y alertas delimitada | `docs/auditoria-administrativa.md`; `AdministrativeAudit/Application/AdministrativeAudit.php`; `docs/alertas-y-vencimientos.md` | Media | La auditoría consolida evidencia existente, no todas las ediciones de catálogos/flota. Personal no tiene modelo documental para alertas. | Comunicar cobertura; si se necesita auditoría durable de otras ediciones o documentos de personal, aprobar un alcance específico. No reconstruir eventos históricos ni inventar obligatoriedad. | Sí: cobertura requerida. | Condicional según obligaciones operativas; no invalida los eventos actuales. |
| P2-04. Decisiones pendientes fuera del alcance operativo actual | `docs/validacion-integral.md`, límites; `docs/facturacion-administrativa-y-pagos.md`, límites | Media | Cambios de producto, cancelación tras finalización, valoración de inventario, anticipos/conversión/crédito y otras compensaciones no están resueltos. Los módulos no deben ofrecerlos como soportados. | Aprobar procedimientos solo cuando se necesiten; mantener bloqueo y documentación. No convertir stock físico ni gastos aprobados en valoración contable o desembolsos. | Sí. | Condicional por proceso; no exige reescribir módulos existentes. |
| P2-05. Riesgo por verificar: parches, colas y versiones del destino | `composer.lock`; `package-lock.json`; `config/queue.php`; `composer.json`; `docs/validacion-integral.md` | Media | No se hizo contraste actualizado con avisos de seguridad ni se probó PHP8.5. Tener conexión queue database no acredita trabajadores supervisados ni demuestra trabajos pendientes. | Revisar avisos de dependencias sin actualizar automáticamente; validar la versión PHP elegida y extensiones. Inventariar trabajos realmente usados antes de habilitar workers; supervisar fallos/reintentos si se usan. El flujo principal actual es síncrono: no introducir colas por requisito supuesto. | No. | Resolver incompatibilidades/vulnerabilidades aplicables antes del despliegue; no se identificó una CVE concreta en esta revisión. |
| P2-06. Comprobado: respaldo puede dejar staging parcial tras caída abrupta | `BackupRecovery/Application/BackupArchive.php::create`; `docs/respaldos-y-recuperacion.md`, publicación y parciales | Media | Puede quedar dump temporal en claro dentro de directorio privado; el cifrado final no protege ese parcial ante acceso al volumen. Alias de servidor tampoco prueban aislamiento de restauración. | Usar volumen protegido/cifrado y permisos mínimos; inspeccionar parciales solo sin captura activa; comprobar aislamiento real del servidor de recuperación y conservar claves fuera de ese volumen. No restaurar sobre destinos existentes. | No. | Condicional a protección del almacenamiento de respaldos. |
| P3-01. Comprobado: aviso de empaquetado no bloqueante | `docs/validacion-integral.md`, resultados; importación de `DriverPortal/local.ts` | Baja | Importación estática y dinámica simultáneas limita separación del bundle; el build y E2E pasaron. | Atender solo si mediciones de carga lo justifican; no refactorizar para obtener una falsa mejora de seguridad. | No. | No. |

Los prefijos de evidencia `AccessAndPersonnel/`, `TransportAndDispatch/`,
`FleetAndDocuments/`, `Workshop/`, `Expenses/`, `AdministrativeReports/`,
`AdministrativeAudit/`, `BillingAndPayments/` y `BackupRecovery/` corresponden a
`app/Modules/`. Los números cortos de migración identifican su sufijo único en
`database/migrations/`.

## Controles existentes que deben conservarse

- Sesión Laravel, regeneración al autenticar, CSRF y límite de cinco intentos/minuto
  por IP (`SessionController`, `AppServiceProvider`, `routes/backend.php`). Cuenta
  activa y roles vigentes se revisan en servidor; las pruebas cubren revocación,
  contraseñas/sesiones y permisos de administrador, secretaria, mecánico y conductor.
  Configurar correctamente IP de proxy evita compartir límites indebidamente.
- Archivos privados con autorización, nombres internos, validación MIME/contenido,
  tamaño y compensación conservadora. Las pruebas existentes cubren acceso ajeno,
  errores y preservación de históricos. No se observó necesidad de reemplazarlo.
- PostgreSQL protege ledgers inmutables, FK, cantidades y transiciones; servicios
  coordinan transacciones, bloqueos y autorizaciones ligadas a datos concretos.
  Pagos/recepciones/consumos y operaciones PWA tienen pruebas de concurrencia e
  idempotencia; ello no promete idempotencia universal de todas las altas.
- Dinero exacto, respuestas decimales como cadenas, CRC/USD separados y saldos
  derivados; reportes agregan por fuentes independientes en lectura consistente.
  Gasto aprobado no es desembolso y facturación no equivale a cobro.
- PWA usa separación por actor, colas con archivos, confirmación del servidor y
  conflictos conservados. El service worker evita cachear APIs y documentos privados;
  las pruebas reales de sesión/cambio de usuario no mostraron fuga entre cuentas.
- Respaldos cifrados/autenticados, checksums y restauración a destinos nuevos con
  confirmación explícita; no hay eliminación automática sin retención configurada.

## Condiciones para autorizar producción

1. P0-04 está corregido; acreditar P0-01/02/03 y P1-01/02 en el destino, sin inferirlos
   del éxito de las pruebas locales. No hace falta repetir todas las suites: usar
   regresiones específicas para cambios y smoke tests del despliegue.
2. Definir el uso financiero permitido y resolver P0-05/06 antes de cobrar/facturar
   en operación real; mantener un proceso fiscal externo si se usa el módulo interno.
3. Acordar procedimientos para escenarios de negocio bloqueados y para dispositivos
   offline; verificar supervisión, archivos y scheduler. Un piloto acotado no habilita
   sustitución de cisterna ni comportamientos pendientes.
4. Registrar responsables y evidencia de cada requisito. No hay aquí certificación
   de producción ni declaración de que el servidor esté inseguro: no fue inspeccionado.

## Verificaciones de esta entrega

Lectura dirigida de reglas, arquitectura, evidencia integral, rutas, configuración,
controladores de sesión, servicios transaccionales/reportes/auditoría, persistencia
PWA y respaldo. Comprobación remota de `main`; verificación de referencias y
`git diff --check`. No se repitieron suites, no se ejecutaron migraciones/restauraciones
ni se añadieron dependencias. La publicación de esta entrega incluye solo este Markdown;
ningún hallazgo fue corregido durante la auditoría original. La corrección posterior de P0-04 se detalla a continuación.

## Corrección posterior de P0-04

Se modificó únicamente el manejo central de `QueryException` en `bootstrap/app.php`.
El evento `database.operation_failed` conserva diagnóstico mediante SQLSTATE de cinco
caracteres alfanuméricos mayúsculos; valores inválidos se sustituyen por `unknown`.
No se pasan al logger la excepción, mensaje de PostgreSQL, SQL, parámetros, conexión,
credenciales ni ruta solicitada. El callback detiene el reporte predeterminado también
fuera de HTTP. Los errores siguen siendo errores: no se convierten en respuestas exitosas.

Se conservaron los mapeos y cuerpos específicos de Transporte y los demás módulos.
Las rutas sin mapeo previo devuelven un 500 genérico JSON o texto, con no-store/nosniff,
incluso con debug activado. Las validaciones y excepciones funcionales ya gestionadas
por los servicios no cambian; no hay migraciones, dependencias ni reglas nuevas.

Regresión: `tests/Feature/QueryExceptionSafetyTest.php` verifica superficies existentes
y futuras, HTML/JSON con debug, logs HTTP/consola, SQLSTATE no confiable, contratos
409/422/503/500 y un error real PostgreSQL con parámetro sintético sensible.

Validación aislada: `bash tests/run-postgres.sh` con filtro
`QueryExceptionSafetyTest|TransportApiTest|FleetDocumentApiTest|AccountSecurityTest|AccessAdministrationTest|BillingPaymentsTest`:
**71 pruebas correctas, 1.921 aserciones**. No se repitió la validación integral.
Regresión final tras ampliar la comprobación HTML: **4 pruebas correctas, 1.103
aserciones** (incluidas en el alcance anterior, no se suman como pruebas distintas).
`vendor/bin/pint --test` y `git diff --check`: correctos.
No se modificaron `.env`, `transporte_db` ni los demás hallazgos.

## Corrección posterior de P1-05

Los POST `/api/transport/requests` y `/api/transport/trips` requieren ahora
`request_key` UUID, generado por el cliente antes de enviar y conservado por intención.
No se generan claves nuevas en el servidor para reintentos sin identificador: esos
clientes reciben 422 y deben adoptar el contrato. El resto de respuestas no cambia:
creación y repetición confirmada devuelven 201 con el resultado original.

La migración incremental `2026_10_16_000400_create_transport_creation_receipts.php`
es indispensable para conservar recibos entre procesos/reinicios. Registra usuario,
clave, tipo, huella SHA-256 y snapshot original, con FK al resultado, unicidad de
usuario/clave y triggers que impiden edición, eliminación y truncado. No altera
migraciones publicadas ni añade unicidad por cliente/ruta/fecha/solicitud.

`TransportAdministration` reutiliza su transacción, el bloqueo `873210` y guardia
existentes: vuelve a autorizar, busca el recibo tras adquirir el bloqueo y confirma
alta/recibo atómicamente. Otra creación concurrente espera; si la primera confirma,
recupera el mismo resultado. Si falla la persistencia del recibo se revierte el alta.
La huella normaliza orden de campos, UUID, instante UTC y litros a dos decimales sin
float. La misma clave con contenido válido diferente o tipo distinto da 409 seguro.
Cada usuario tiene su ámbito; no se concede acceso por poseer una clave. El replay
no restaura ni modifica el estado actual del registro: devuelve el snapshot de alta.
Los recibos no tienen purga automática que debilite la garantía de reintento.

En Vue, `CommandForm` mantiene clave y datos pendientes por actor/tipo en memoria,
con campos congelados ante resultados inciertos y recuperación manual; no hay reenvío
automático. Solo el UUID opaco se guarda en `sessionStorage` antes del envío, sin
persistir datos del formulario. Tras recargar se puede volver a introducir el contenido
original usando la misma clave; si difiere del confirmado se rechaza con conflicto.
Un 422 permite corregir campos conservando la clave. Una confirmación inequívoca
retira la intención; otra creación legítima o la acción explícita «Nueva creación
distinta» genera una clave nueva. Esa acción advierte que la anterior podría existir.

Límite: cerrar/borrar el almacenamiento de la pestaña puede perder la clave. En ese
caso debe consultarse el listado antes de comenzar otra intención; no se deduce
unicidad comercial ni se pretende identificar una intención perdida por sus datos.
Las claves de otra cuenta no se reutilizan; sesión y permisos siguen siendo obligatorios.
Este cambio no convierte las demás operaciones de Transporte en universalmente idempotentes.

Pruebas enfocadas: backend PostgreSQL 18 aislado (repetición, snapshots, conflicto,
permisos revocados, rollback del recibo, inmutabilidad y dos procesos concurrentes);
frontend Chromium con API sintética (clave/datos, recarga, validación y otra intención);
E2E Chromium/Laravel/PostgreSQL reales que descartan respuestas después del commit
de ambas altas y recuperan sus mismos IDs. Se conservan los flujos existentes de
Transporte y el contrato actualizado de navegación. Resultados ejecutados:

| Verificación enfocada | Resultado |
| --- | --- |
| Backend: recibos, API Transporte/concurrencia y administración de rutas | 27 pruebas / 334 aserciones correctas |
| Regresión final de recibos tras añadir conflicto de viaje con otra solicitud válida | 6 pruebas / 46 aserciones correctas; incluidas en el alcance anterior, no se suman como pruebas distintas |
| Frontend Transporte, API sintética | 14 pruebas correctas |
| Contratos frontend existentes | 29 pruebas correctas |
| E2E reales de Transporte | 5 recorridos correctos; se repitió solo el nuevo de respuestas perdidas tras el ajuste final del frontend y pasó |
| E2E real de navegación afectado por el campo obligatorio | 1 recorrido correcto |
| TypeScript, build, Pint y diff check | Correctos; persiste aviso previo de importación de `DriverPortal/local.ts` |

No se repitió la validación integral. Los fallos iniciales de las pruebas nuevas
(observación de estadísticas PostgreSQL, expectativa 401 frente al 403 existente y
mock de POST que devolvía una lista) se corrigieron sin relajar los contratos reales.
