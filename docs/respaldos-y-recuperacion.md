# Respaldos y recuperación

## Alcance implementado

`BackupRecovery` y `php artisan backups:run` crean y verifican conjuntos cifrados
de PostgreSQL **18** y almacenamiento privado local. No hay interfaz web ni API.
El acceso es operativo por consola y permisos del servidor, sin nuevos roles.
No cambia los módulos, la base operativa, la conexión predeterminada ni `.env`.

Se reutiliza el diseño F04 de `etapa-6-2-diseno-fisico.md`: pausa efectiva de
escritores, captura conjunta y recuperación aislada. No existía una herramienta
de respaldo implementada. Los recursos cubiertos son:

- Base completa mediante `pg_dump --format=custom --no-owner --no-privileges`:
  tablas, datos, funciones, restricciones, índices e historial.
- Disco privado `fleet_documents`: documentos históricos, comprobantes de Gastos,
  staging y marcadores de operaciones pendientes.
- Disco privado `local` y raíz `workshop.storage_root`: evidencias, fotografías,
  facturas externas y reservas persistidas de Taller. Raíces anidadas se copian una
  sola vez. No se incluyen lockfiles de coordinación ni propietarios de procesos.
- Se incluye cualquier archivo privado persistido en esas raíces. Las facturas
  administrativas internas están en PostgreSQL; no se inventan PDFs inexistentes.

Solo se admiten discos locales y rutas absolutas sin symlinks. Se rechazan destinos
que se solapen con el proyecto, almacenamiento activo o directorios públicos.
El directorio de respaldos debe ser privado (0700), los archivos se crean 0600.

## Cifrado y manifiesto

Cada archivo, incluido el dump, se cifra en bloques con sodium
`secretstream-xchacha20poly1305`, autenticación y marcador final. Se comprueban
SHA-256 del contenido original y del cifrado. El manifiesto común tiene UUID,
fecha en `America/Costa_Rica`, inventario de archivos y tamaños, versión de formato,
PostgreSQL/pg_dump/PHP/Laravel/sodium, mapeo de almacenamiento y configuración de
codificación/locale del origen. Un HMAC-SHA-256 autentica el manifiesto completo.
No contiene contraseñas, claves ni detalles de conexión. Los nombres relativos
son metadatos privados: el conjunto entero requiere acceso restringido.

La clave es independiente de `APP_KEY`: archivo externo al proyecto y a las raíces
privadas, 0600, con 32 bytes aleatorios codificados en base64. Se conserva fuera del
respaldo y con una copia segura separada. Perderla impide recuperar el conjunto.
Ejemplo de generación **en el servidor**, sin imprimirla ni sobrescribir una clave:

```bash
umask 077
php -r '$h=fopen($argv[1],"x"); if(!$h)exit(1); chmod($argv[1],0600); fwrite($h,base64_encode(random_bytes(32))); fclose($h);' /etc/transportes/backups.key
```

Los directorios padre deben prepararse previamente con permisos y propietario
adecuados. No ejecutar este ejemplo contra archivos existentes. Rotar claves exige
conservar las anteriores para los conjuntos antiguos. Usar destinos separados por
clave evita que una política de retención encuentre conjuntos de otra clave.

## Configuración de servidor

Variables de entorno inyectadas por el supervisor/gestor de secretos, recogidas en
`config/backups.php`; no es necesario editar el `.env` del desarrollo:

| Variable | Uso / valor predeterminado |
| --- | --- |
| `BACKUP_DESTINATION` | Directorio privado local o volumen externo montado; sin destino por defecto |
| `BACKUP_KEY_FILE` | Archivo de clave independiente; obligatorio |
| `BACKUP_PG_BIN` | Directorio con herramientas PostgreSQL 18, o PATH si vacío |
| `BACKUP_TIMEOUT_SECONDS` | Límite por proceso, 600 segundos por defecto |
| `BACKUP_RETENTION_DAYS` | Días positivos; sin valor no se elimina ningún respaldo |
| `BACKUP_SCHEDULE_TIME` | `HH:MM` de Costa Rica; sin valor no hay tarea automática |
| `BACKUP_WRITERS_STOPPED` | Atestación operativa para la ventana programada; false por defecto |
| `BACKUP_RESTORE_CONNECTION` | Conexión explícitamente habilitada para recuperación, normalmente `pgsql_recovery` |

`config/database.php` incorpora únicamente el perfil **adicional** `pgsql_recovery`:
`BACKUP_RESTORE_HOST`, `BACKUP_RESTORE_PORT` (5432),
`BACKUP_RESTORE_MAINTENANCE_DB` (`postgres`), `BACKUP_RESTORE_USERNAME`,
`BACKUP_RESTORE_PASSWORD` y `BACKUP_RESTORE_SSLMODE` (`verify-full`). No hereda
credenciales del servidor activo. Configurar CA/certificados de PostgreSQL cuando
corresponda. Nunca pasar contraseñas como argumentos; los procesos reciben la
credencial de la configuración resuelta de Laravel mediante su entorno privado.

Los binarios deben ser PostgreSQL 18 y PHP debe disponer de sodium y pdo_pgsql.
Esta entrega reutilizó PHP 8.4.24 y PostgreSQL 18.3; no se ha validado PHP 8.5.

## Crear y verificar

Antes de crear, el operador debe activar mantenimiento, detener/drainar workers,
cargadores, reconciliadores, migraciones y escritores SQL externos, y esperar las
operaciones activas. `--quiesced` confirma esa condición; no la realiza ni la puede
verificar físicamente. Si no se puede detener algún escritor, no declarar respaldo
coherente. La herramienta rechaza crear si Laravel no está en mantenimiento.

```bash
php artisan backups:run create --connection=pgsql --quiesced
php artisan backups:run verify --archive=/mnt/respaldos/transportes/UUID_DEL_CONJUNTO
```

`--destination=/ruta/privada` permite reemplazar el destino configurado en una
invocación. Durante captura se mantienen locks SHARE de tablas y un snapshot
exportado usado por `pg_dump`. Se valida el formato con `pg_restore --list`, se
copian archivos y se comprueban referencias documentales contra los bytes capturados:
existencia para Flota y además tamaño/hash para comprobantes y adjuntos de Taller.
No se eliminan ni reparan registros ante faltantes: se aborta el respaldo.

El conjunto se publica con nombre UUID solo después de verificarlo. Los parciales
usan `.UUID.partial` y se limpian en errores controlados. Un corte abrupto puede
conservar un parcial, incluido un dump temporal en claro dentro del directorio
0700; usar un volumen cifrado también para staging. Un operador debe comprobar
que no haya captura activa antes de tratar esos parciales. Nunca se restauran ni
se purgan automáticamente como si fueran conjuntos terminados.

La verificación autentica el manifiesto y descifra todos los bloques sin escribir
plaintext, comprueba hashes, archivos ausentes/sobrantes, rutas y duplicados.
No demuestra por sí sola restaurabilidad: hay que ensayar la recuperación.

## Restaurar: siempre en destinos nuevos y aislados

El operador configura una conexión de recuperación a un servidor PostgreSQL 18
**aislado**. Fuera de pruebas se rechaza el mismo host/puerto de la conexión
operativa. Esta comprobación de endpoint no sustituye verificar DNS, aliases y
que el servidor realmente esté aislado. No hay opción `--force`, `--clean` ni
restauración sobre una base existente; el nombre nuevo debe empezar por `restore_`.

El directorio destino debe ser nuevo, fuera del proyecto, almacenamiento activo y
conjunto de respaldo. La base también debe ser inexistente. La cuenta necesita
CREATEDB y privilegios para restaurar los objetos/extensiones utilizados. El clúster
nuevo debe disponer de codificación, proveedor de locale, locale y versión de
collation compatibles con el origen; se comparan con `template0` y se rechazan
incompatibilidades antes de crear destinos. Roles globales, extensiones y certificados
necesarios se preparan en el servidor aislado, no mediante modificaciones al activo.

Ejemplo con parámetros ficticios, que deben coincidir exactamente con el perfil:

```bash
php artisan backups:run restore \
  --connection=pgsql_recovery \
  --archive=/mnt/respaldos/transportes/UUID_DEL_CONJUNTO \
  --database=restore_ensayo_20261016 \
  --files=/srv/recuperacion/ensayo_20261016 \
  --confirm='recovery-db.internal:5432/restore_ensayo_20261016|/srv/recuperacion/ensayo_20261016' \
  --isolated
```

La confirmación explícita incluye **host:puerto/base|directorio-absoluto**.
La integridad completa se verifica antes de crear destinos. Se descifran los archivos
en el directorio aislado 0700, se crea la base nueva y se ejecuta `pg_restore`
con `--single-transaction --exit-on-error --no-owner --no-privileges`. Después se
verifican nuevamente referencias y hashes, se elimina únicamente el dump temporal
propio y se genera `RESTORE_COMPLETE.json`.

Los archivos recuperados quedan en `private/<etiqueta>/...`; `manifest.storage`
indica las raíces correspondientes a `fleet_documents`, `local` y Taller. Un despliegue
**aislado** puede apuntar sus discos a esas rutas para pruebas. La herramienta no
cambia configuración de la aplicación ni hace un corte a producción.

Ante fallos, no se borra la base creada ni se eliminan los archivos recuperados;
se conserva `RESTORE_FAILED` cuando el almacenamiento lo permite. Sin marcador de
finalización, o con fallo registrado, el destino sigue incompleto: no conectar la
aplicación. Investigar y repetir en **otros destinos nuevos**, nunca sobrescribir.
Los procesos previos no existen en el destino: revisar reservas/staging y procedimientos
de conciliación de cada módulo antes de habilitar escritores. No ejecutar reconciliación
automática ni borrar históricos. Revisar sesiones, locks de cache y secretos de
aplicación de forma controlada en el entorno recuperado.

## Automatización, retención y copia externa

Una hora válida habilita una tarea diaria en Laravel Scheduler con
`withoutOverlapping`, `evenInMaintenanceMode` y zona `America/Costa_Rica`.
Conservar la entrada habitual del servidor para `php artisan schedule:run` cada
minuto y comprobar `schedule:list` tras configurar el despliegue. La tarea de
vencimientos documentales a las 06:00 se conserva intacta.

El servidor debe orquestar previamente la pausa/drenaje en una ventana aprobada y
suministrar la atestación `BACKUP_WRITERS_STOPPED`. El comando no pone la aplicación
en mantenimiento, no detiene procesos ni reanuda escrituras por cuenta propia. Si
faltan las condiciones, falla con código de salida 1 y registro estructurado sin
SQL, destinos, contraseñas ni salida cruda de procesos. Supervisar esos resultados;
no se envían mensajes externos desde este avance.

La retención solo se aplica si hay días positivos configurados, después de un respaldo
confirmado, o explícitamente:

```bash
php artisan backups:run prune
```

Solo elimina conjuntos completos autenticados, íntegros y vencidos del destino
configurado. Valida candidatos antes de borrar; corrupción o claves incompatibles
interrumpen la purga. No elimina parciales ni otros archivos/directorios. Un fallo
de retención puede dejar un respaldo recién creado válido y un comando con error:
consultar el ID de éxito y verificarlo, sin confundir purga fallida con pérdida del
respaldo.

Para copia externa, montar un volumen privado fuera del servidor y configurar allí
`BACKUP_DESTINATION`. El supervisor debe exigir que el montaje esté activo para
no guardar inadvertidamente en disco local. Los conjuntos ya cifrados también se
pueden replicar con herramientas de infraestructura existentes, conservando
manifiesto/HMAC/blobs y verificándolos en destino; no se instala ni simula un servicio
S3/remoto en esta entrega. Custodiar las claves en un canal separado.

Quedan por configurar/aprobar en el servidor: RPO, RTO, ventana, retención, responsables,
almacenamiento externo/cifrado de staging, supervisión y frecuencia de simulacros.
Programar verificaciones completas y restauraciones periódicas en destinos nuevos,
registrando ID, fecha, resultado, tiempo de recuperación y prueba funcional aislada.
No se afirma cumplimiento de un RPO/RTO ni disponibilidad de una copia externa actual.

## Validación específica

```bash
bash tests/run-postgres.sh --filter='BackupRecoveryTest|DocumentAlertsTest'
vendor/bin/pint --test
git diff --check
```

Se utilizan PostgreSQL 18 temporal, directorios sintéticos y claves efímeras fuera
de Git. Las pruebas cubren dump/restauración real, documento y comprobante registrados,
archivos vacíos y límite exacto de bloque, checksums, corrupción, HMAC/clave erróneos,
traversal/symlinks, destinos existentes, confirmación, mantenimiento, concurrencia,
fallo de dump, recuperación parcial, retención opt-in y compatibilidad del Scheduler.
Los fallos de procesos se inyectan con ejecutables sintéticos solo en esas pruebas;
el circuito satisfactorio utiliza `pg_dump` y `pg_restore` reales. No se modifican
`.env`, `transporte_db` ni otras bases existentes. No se ejecuta una suite integral
general ni pruebas frontend ajenas a comandos sin interfaz.

Resultados de esta entrega: **16 pruebas específicas y 156 aserciones correctas**
(10 de BackupRecovery y 6 de regresión documental/Scheduler), PostgreSQL 18.3
con `pg_dump`/`pg_restore` reales en recuperación satisfactoria. Pint y
`git diff --check` correctos. No se han configurado ni ejecutado respaldos reales
ni restauraciones de producción.

El respaldo no incluye `.env`, código, dependencias, `APP_KEY`, certificados,
roles globales PostgreSQL ni servicios externos. Custodiarlos/desplegarlos por sus
canales existentes; conservar las claves que requieran datos cifrados de la aplicación.
La cuenta de captura necesita privilegios suficientes de lectura y locks para todas
las tablas incluidas. No se conceden privilegios ni se alteran cuentas automáticamente.
