# 10. Respaldos y operación


## Conceptos antes de operar

Un **respaldo** conserva una copia recuperable. **Cifrar** protege contenido con una clave; un **checksum SHA-256** detecta cambios en bytes; un **HMAC** autentica el manifiesto con una clave. **RPO** indica cuántos datos se acepta perder por antigüedad de la copia; **RTO** cuánto tiempo se acepta tardar en recuperar. Ambos necesitan decisión operativa, no se deducen del cron.

`BackupArchive` y [`BackupCommand`](../../app/Console/Commands/BackupCommand.php) implementan `php artisan backups:run`. No existe interfaz web. La descripción detallada y procedimientos actuales están en [respaldos y recuperación](../respaldos-y-recuperacion.md).

## Qué se copia y cómo

1. PostgreSQL 18 con `pg_dump --format=custom --no-owner --no-privileges`: datos, tablas, funciones, triggers, constraints e índices.
2. Raíces privadas `fleet_documents`, `local` y `workshop.storage_root`, deduplicando raíces anidadas. Incluye documentos históricos, comprobantes, evidencias y reservas persistidas. Facturas administrativas están en PostgreSQL; no inventa PDFs.
3. Manifiesto con UUID, fecha Costa Rica, versiones, archivos/tamaños/hashes y parámetros de locale/codificación necesarios para restaurar.

Creación exige Laravel en mantenimiento y `--quiesced`: el operador declara que detuvo/drenó escritores, incluidos workers, migraciones, cargadores, reconciliadores y SQL externo. El comando no puede detenerlos físicamente por sí solo. Mantiene locks SHARE y snapshot exportado para el dump; comprueba referencias de archivos contra los bytes capturados.

Cada archivo se cifra por bloques con sodium `secretstream-xchacha20poly1305`; el manifiesto se autentica con HMAC-SHA-256. Se verifican hashes original/cifrado y marcador final. La clave es independiente de `APP_KEY`, en archivo externo 0600, con copia segura separada. Perder la clave impide recuperar respaldos anteriores; rotarla no vuelve legibles los antiguos con la nueva.

Destinos privados 0700, archivos 0600, sin symlinks ni solapamiento con proyecto/almacenamiento activo/público. Un conjunto se publica como UUID después de verificar. Un corte abrupto puede dejar un parcial con dump temporal en claro en directorio privado: usar también volumen cifrado para staging y revisión operativa, no borrar archivos mientras una captura pudiera seguir activa.

## Configuración disponible

[`config/backups.php`](../../config/backups.php) recibe:

| Configuración externa | Efecto |
| --- | --- |
| `BACKUP_DESTINATION` | Destino privado local o volumen externo montado; no tiene destino implícito |
| `BACKUP_KEY_FILE` | Clave de cifrado independiente |
| `BACKUP_PG_BIN` | Herramientas PostgreSQL 18; PATH si vacío |
| `BACKUP_TIMEOUT_SECONDS` | Límite por proceso, 600 por defecto |
| `BACKUP_RETENTION_DAYS` | Días positivos; sin política no elimina respaldos |
| `BACKUP_SCHEDULE_TIME` | HH:MM Costa Rica; sin valor no programa copia |
| `BACKUP_WRITERS_STOPPED` | Atestación para la ventana programada; no detiene escritores |
| `BACKUP_RESTORE_CONNECTION` | Conexión habilitada exclusivamente para recuperación |

Estas son instrucciones de configuración futura; durante la elaboración del manual no se modificó `.env`. El destino externo requiere montar/proteger el volumen o un mecanismo operativo externo. No existe un cargador automático S3 inventado por el manual. Credenciales, configuración del servidor, certificados, roles globales y custodia de claves se preparan separadamente; no incluirlos en Git.

## Comandos existentes

Ejemplos didácticos con destinos ficticios; no ejecutarlos sobre datos de desarrollo o producción sin el procedimiento autorizado:

```bash
php artisan backups:run create --connection=pgsql --quiesced
php artisan backups:run verify --archive=/mnt/respaldos/transportes/UUID_DEL_CONJUNTO
php artisan backups:run prune --destination=/mnt/respaldos/transportes
```

`--destination` puede reemplazar el destino de creación. `prune` no elimina sin retención configurada; autentica conjuntos antes de purgar antiguos y se detiene ante corruptos. No trata parciales como respaldos terminados.

Restauración siempre a servidor aislado, base inexistente `restore_*` y directorio nuevo:

```bash
php artisan backups:run restore \
  --connection=pgsql_recovery \
  --archive=/mnt/respaldos/transportes/UUID_DEL_CONJUNTO \
  --database=restore_ensayo \
  --files=/srv/recuperacion/ensayo \
  --confirm='recovery-db.internal:5432/restore_ensayo|/srv/recuperacion/ensayo' \
  --isolated
```

La identidad debe coincidir exactamente con host:puerto/base y ruta absoluta configurados; no sustituye verificar aislamiento real ante DNS/aliases. El perfil adicional `pgsql_recovery` no hereda credenciales activas. Se valida manifiesto antes de crear destinos, compara locale/collation compatibles con `template0`, usa `pg_restore` en transacción y comprueba referencias finales. No hay `--clean`, sobrescritura de bases ni cambio automático de aplicación al destino. Un fallo parcial conserva evidencia/destino fallido para revisión; no ejecuta borrados destructivos automáticos. `RESTORE_COMPLETE` identifica finalización, no una aprobación del negocio.

## Scheduler y tareas existentes

El **scheduler** de Laravel decide cuándo ejecutar comandos; necesita que el servidor invoque `schedule:run` periódicamente. [`routes/console.php`](../../routes/console.php) mantiene:

- `documents:check-expirations`, diario 06:00 America/Costa_Rica, sin solapamiento. Consulta el mismo resumen; no envía correo ni crea alertas duplicadas.
- `backups:run create`, solo con hora válida configurada, sin solapamiento y permitido en mantenimiento. No interfiere ni sustituye las alertas.
- `expenses:reconcile-receipts`, reconciliación manual de archivos pendientes; no debe correrse durante captura no coordinada.

`php artisan fleet-documents:reconcile-storage` reconcilia el almacenamiento privado de Flota; su firma está en [`ReconcileDocumentStorage`](../../app/Modules/FleetAndDocuments/Console/ReconcileDocumentStorage.php). No confundir reconciliar almacenamiento con renovar documentos.

## Logs, colas y recuperación de fallos

Un **log** registra diagnóstico, no reemplaza auditoría de negocio. `config/logging.php` define canales; P0-04 sanea excepciones SQL. Definir retención/acceso de logs fuera del código. Las tablas `jobs`, `job_batches` y `failed_jobs` son soporte Laravel: su existencia no demuestra un worker desplegado ni que la PWA use colas del servidor para sincronizar cerrada.

Si una operación HTTP pierde respuesta, consultar recibo/estado antes de repetir. Si falla un archivo, usar el reconciliador/reserva adecuado, conservando los confirmados. Si falla una restauración, detener el corte y revisar el destino aislado; no apuntar la aplicación a una recuperación no verificada.

## Migraciones, compilación y despliegue

Una **migración** cambia versión del esquema; no se modifica una ya publicada. Las nuevas requieren revisión y copia recuperable. En un despliegue autorizado se compilan recursos con `npm run build`, se instala la resolución aprobada y se aplican migraciones revisadas en el destino correcto. Cachear configuración/rutas exige valores productivos resueltos; no copiar cachés de pruebas. El servidor debe servir `public/`, mantener HTTPS y no exponer raíces privadas.

Esto describe preparación, no un despliegue realizado. Pendientes operativos: servidor/cron/supervisor, privilegios separados, destino externo, claves/certificados, política de retención/RPO/RTO, ensayo periódico y compatibilidad PHP 8.5. Las pruebas usan únicamente PostgreSQL/almacenamiento aislados; nunca `transporte_db`.
