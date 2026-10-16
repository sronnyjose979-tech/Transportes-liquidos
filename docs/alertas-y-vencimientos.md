# Alertas y vencimientos documentales

## Alcance real

`/alertas` está disponible para administrador y secretaria, mediante el permiso
existente `manage-fleet-documents`. Detecta **documentos actuales** vencidos o
próximos a vencer de cabezales y cisternas. Se integra con la navegación y añade
indicadores al dashboard del administrador y al inicio de Transporte de oficina.

**Personal está pendiente:** `Employee` solo contiene usuario, nombre y activación;
no hay documentos personales, tipos aplicables ni fechas de vencimiento. `Document`
requiere `FleetAsset`. No se inventaron documentos personales ni se reutilizaron
activos ficticios para representar personas. Incorporar personal requiere definir
su modelo y tipos documentales antes de otra implementación.

## Reglas reutilizadas

- Se usan `Document::current()`, `Document::withValidity()` y
  `Document::validity()`, con configuración histórica por documento.
- Zona horaria `America/Costa_Rica`. Vencido: fecha anterior al día local actual.
  Próximo a vencer: de hoy a **15 días inclusive**. El día completo de vencimiento
  sigue siendo válido. Los días negativos representan atraso.
- Sin vencimiento y documentos vigentes a más de 15 días no generan alertas.
  Cambiar la configuración del tipo no reinterpreta documentos históricos.
- Se consulta `current_fleet_documents`; nunca se elige simplemente el último
  registro. Documentos renovados/reemplazados dejan de aparecer como actuales.
- Las alertas son informativas. No hay cambios a disponibilidad, asignaciones,
  viajes, bloqueos operativos existentes o renovación automática. Tampoco se
  introducen requisitos documentales, avisos de documentos ausentes o plazos nuevos.

## Atención y persistencia

La migración incremental `2026_10_16_000390_create_document_alert_reviews.php`
crea únicamente `document_alert_reviews`: UUID, documento con FK/UNIQUE, usuario
bigint, fecha del servidor y estado de vencimiento al revisar. Las revisiones son
inmutables, con integridad referencial y protección PostgreSQL contra edición,
eliminación y truncate. Solo una cuenta activa de oficina puede registrarlas.

La alerta se calcula al consultar; no existe una tabla paralela de alertas que
pueda duplicarse o quedar obsoleta. Marcar revisada no oculta la alerta, no resuelve
el vencimiento y no cambia el documento. Una revisión previa sigue visible si el
documento pasa de próximo a vencer a vencido, conservando fecha y estado al revisar.
El filtro predeterminado incluye revisadas y no revisadas.

Una renovación crea otra identidad documental y no hereda la revisión anterior.
La revisión antigua se conserva. Los reintentos devuelven la primera revisión sin
sobrescribir actor/fecha. El servicio usa la misma coordinación transaccional
`873210` que el registro documental, vuelve a comprobar permisos y rechaza una
revisión nueva si el documento ya fue reemplazado o dejó de ser una alerta.
La unicidad protege contra dos revisiones concurrentes. No hay reenvío automático
en Vue después de una respuesta incierta; se ofrece confirmar la misma operación.

## Pantalla y API

Filtros: vencido/próximo, revisada/sin revisar, clase de activo, activo, tipo y
período de **fecha de vencimiento** (`from`/`to`, inclusivos). Listado paginado,
nombres históricos de tipos, fecha, días restantes/atrasados y responsable de
revisión. El enlace **Ver registro original** abre el endpoint documental privado
real; **Abrir Documentación** permite continuar la consulta/renovación existente.
No se exponen archivos mediante URLs públicas ni se entrega `storage_key`.

| Endpoint | Uso |
| --- | --- |
| `GET /api/document-alerts` | Listado, filtros y paginación. |
| `GET /api/document-alerts/summary` | Conteos actuales de vencidos, próximos y sin revisar; fecha local y cobertura. |
| `POST /api/document-alerts/{document}/review` | Marca única y auditable; cuerpo vacío, actor de sesión. |

Se reutilizan sesión, CSRF y permisos Laravel en página/API. Conductor y mecánico
no acceden. La interfaz comprueba roles, cancela aplicación de respuestas tardías,
limpia resultados ante cambios de cuenta y muestra errores. Las respuestas usan
`private, no-store`. No se modifica la PWA ni se guardan alertas en IndexedDB.

## Comprobación programada

Comando disponible:

```bash
php artisan documents:check-expirations
```

Imprime JSON con el resumen actual y no crea/modifica documentos, revisiones ni
alertas persistidas. Repetirlo no duplica resultados ni produce notificaciones.
Laravel programa el comando diariamente a las **06:00 de Costa Rica**, con
`withoutOverlapping()`, en `routes/console.php`.

En el despliegue, un operador debe habilitar el scheduler habitual de Laravel:

```cron
* * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Alternativa en un entorno controlado: `php artisan schedule:work`. No se instaló
ni activó cron en este entorno. Las pantallas calculan vencimientos en vivo y no
dependen de que se haya ejecutado el comando. No se conserva historial de corridas;
la persistencia de atención corresponde a las revisiones. No hay correo, SMS,
push, colas externas ni servicios de notificaciones.

## Validación

Pruebas ejecutadas con PostgreSQL 18.3 temporal, Laravel y PHP 8.4.24 compatible:
**35 pruebas backend, 537 aserciones**; **18 pruebas frontend** (4 del módulo,
4 de sistema y 10 de Transporte); **26 contratos frontend**; **2 E2E reales** con
Chromium. TypeScript, build, Pint y diff check correctos.

Se comprobaron permisos y revocación, límites de fechas y medianoche, tipos sin
vencimiento, snapshots, renovaciones, filtros, revisiones persistentes/inmutables,
comando repetible y scheduler. El E2E incluye revisiones iniciales concurrentes,
respuesta perdida/reintento, fuente y archivo sin cambios, renovación y navegación.
Se añadió reloj real a la instrumentación E2E para no producir cookies ya vencidas
con el antiguo fixture de fechas; no se modificaron sesiones de aplicación.

```bash
bash tests/run-postgres.sh --filter 'DocumentAlertsTest|FleetDocumentTest|FleetDocumentApiTest|SystemNavigationTest'
bash tests/run-postgres.sh --e2e-document-alerts -v
python3 -m unittest discover -s tests/Frontend -p test_document_alerts.py -v
python3 -m unittest discover -s tests/Frontend -p test_system.py -v
python3 -m unittest discover -s tests/Frontend -p test_transport.py -v
npm run test:frontend-api
npm run typecheck
TRANSPORTE_BUILD_OFFLINE=1 npm run build
vendor/bin/pint --test
git diff --check
```

No se ejecutaron migraciones ni pruebas sobre `transporte_db`, ni se modificó
`.env`. Las nuevas migraciones se ejecutaron exclusivamente en el runner aislado.
