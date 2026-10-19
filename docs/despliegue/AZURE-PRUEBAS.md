# Azure Ubuntu 24.04: Docker Compose para pruebas

Configuración preparada para una VM de 2 vCPU / 8 GB RAM y una sola instancia.
PostgreSQL 18 permanece en Oracle Cloud. Este procedimiento no ejecuta migraciones,
seeders ni `composer setup`; tampoco provisiona Azure ni configura Oracle.
La base remota de pruebas debe tener previamente el esquema compatible aprobado.
No reutilizar el `.env`, documentos o credenciales de la instalación local.

## Servicios y persistencia

- `app`: PHP 8.4 FPM, dependencias de `composer.lock`, sin dependencias de desarrollo.
- `web`: Nginx sirve Vue/PWA compilados desde `public/` y pasa únicamente
  `index.php` a `app:9000`. Node 24 interviene solo durante la construcción.
- `scheduler`: `schedule:work`, una réplica y bloqueo `flock` en el volumen
  compartido. No agregar otro cron/scheduler, ni otro proyecto Compose sobre la
  misma base. Al apagar la VM no se ejecutan tareas ni se recuperan automáticamente
  horarios perdidos. La consulta diaria usa America/Costa_Rica en el código.
- `worker`: perfil opcional; actualmente no hay jobs de aplicación identificados.
- `certbot`: herramienta bajo demanda, sin proceso residente adicional.

Los volúmenes `private-files`, `framework`, `logs`, `certificates` y `acme`
conservan documentos, comprobantes, adjuntos, sesiones, caché/locks, logs y
certificados al recrear contenedores. Los privados nunca se montan en Nginx.
`bootstrap/cache` permanece dentro de cada imagen/contenedor y las vistas
compiladas usan un tmpfs por proceso para evitar cachés obsoletas entre versiones. No se genera caché de configuración durante el build.
Los permisos iniciales de los volúmenes Laravel proceden de la imagen (UID 33).
No sustituirlos por bind mounts vacíos sin preparar sus permisos.

Conservar el nombre de proyecto y el disco persistente de la VM. No ejecutar
`down -v`, `volume prune` ni eliminar ese disco. Los volúmenes no son un respaldo:
proteger/cifrar el disco y acordar copias separadas si se conservan datos de pruebas.
Los respaldos de aplicación quedan deshabilitados; esta imagen no incluye las
herramientas PostgreSQL de respaldo/restauración.

## 1. Preparar la VM (cuando se autorice)

Instalar Git y Docker Engine con el plugin Compose siguiendo el
[repositorio apt oficial para Ubuntu](https://docs.docker.com/engine/install/ubuntu/#install-using-the-apt-repository).
Usar Docker Compose v2 actual con soporte de `scale`, `profiles`, secretos y límites
por servicio. No instalar PHP, Node o PostgreSQL en el host. Verificar:

```sh
docker version
docker compose version
sudo systemctl enable --now docker
```

Los comandos posteriores requieren permisos Docker. Pertenecer al grupo `docker`
equivale a acceso administrativo; usar únicamente cuentas de administración.
Clonar el repositorio en `/opt/transporte-liquidos` y entrar en ese directorio.

Asignar un dominio DNS real a la IP pública de Azure (preferiblemente estática).
Publicar solo 80/443 para ACME/HTTPS; limitar SSH a administración en NSG/firewall.
No publicar 9000 ni 5432. Para HTTP-01, el dominio debe resolver desde Internet y
el puerto 80 ser accesible; comprobar también cualquier registro AAAA.

En Oracle, preparar TLS y una ruta autorizada desde Azure: VPN/red privada o
firewall/NSG restringidos a la IP de salida real de Azure. Configurar `pg_hba.conf`
y privilegios mínimos para la base exclusiva de pruebas. No abrir 5432 a Internet
sin restricción. Estas acciones quedan pendientes de datos y autorización reales.

## 2. Variables y confianza PostgreSQL

```sh
umask 077
cp .env.azure-testing.example .env.azure-testing
chmod 600 .env.azure-testing
```

Editar únicamente este nuevo archivo. Completar `APP_DOMAIN`, `APP_URL` con
`https://DOMINIO_REAL`, `APP_KEY`, host/base/usuario/contraseña Oracle y
`ORACLE_CA_FILE` con una ruta absoluta del host. Mantener contraseñas entre comillas
simples si contienen `$` o `#`, respetando el formato dotenv.

Obtener la cadena CA auténtica del administrador PostgreSQL mediante un canal
confiable y guardarla fuera del repositorio/contexto, por ejemplo bajo
`/etc/transporte-liquidos/`. El certificado CA es público; debe ser legible por UID
33 en el contenedor (por ejemplo archivo 0644, directorio host de acceso restringido).
Compose lo monta solo lectura en `/run/secrets/oracle-ca.crt`. No generar una CA
ficticia, ni desactivar validación. Si el servidor exige mTLS, se requiere preparar
el montaje del certificado/clave cliente antes de habilitar la conexión.

Compose fuerza `pgsql`, `verify-full`, ruta de CA y `DB_URL` vacío para evitar
que una URL reemplace el destino o TLS. El hostname `DB_HOST` debe coincidir con el
certificado del servidor: [PostgreSQL 18, verificación TLS](https://www.postgresql.org/docs/18/libpq-ssl.html).
Credenciales vacías no conectan a ninguna base predeterminada útil; completar y
revisar todo antes de iniciar PHP/scheduler. No cambiar el `.env` local.

Se usan sesiones y caché de archivos persistentes para esta única VM, sin Redis
ni nuevas tablas. `APP_DEBUG=false`, cookies seguras y sesiones cifradas se
mantienen en este entorno de pruebas accesible por red.

## 3. Validar y construir

```sh
# Valida sin imprimir la configuración expandida con secretos.
docker compose --env-file .env.azure-testing config --quiet
# Construye ambas imágenes desde los lockfiles; no conecta a PostgreSQL.
docker compose --env-file .env.azure-testing build app web
# Genera una clave NUEVA de este entorno, sin arrancar Laravel ni tocar la DB.
docker compose --env-file .env.azure-testing run --rm --no-deps app php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Guardar esa clave en `APP_KEY` mediante el editor y conservarla en reinicios y
reconstrucciones. No regenerarla en un entorno existente. Este comando crea los
volúmenes Laravel, pero no ejecuta Artisan. La construcción excluye `.env`,
`storage/`, `vendor/`, `node_modules`, certificados y documentos reales. El build
PWA usa `TRANSPORTE_BUILD_OFFLINE=1` (fallback de fuentes del sistema existente),
sin descarga de fuentes Bunny. No ejecutar npm o Composer adicionales en la VM.

Las bases de imagen usan versiones de rama explícitas; los paquetes de aplicación
usan locks. Para reproducir imágenes byte por byte, registrar los digests de las
bases y de las imágenes construidas cuando se haga la primera construcción
verificada; los tags de rama pueden recibir parches. No se ha construido en Azure.

## 4. Emitir certificado y arrancar

Antes de iniciar, confirmar que todas las variables Oracle corresponden a pruebas.

```sh
# Sin certificado, Nginx solo ofrece desafío ACME y redirección HTTPS.
# Inicia PHP-FPM como dependencia; no inicia scheduler ni worker.
docker compose --env-file .env.azure-testing up -d web
# Sustituir DOMINIO_REAL y CORREO_REAL; deben coincidir con APP_DOMAIN.
docker compose --env-file .env.azure-testing run --rm --no-deps certbot certonly --webroot -w /var/www/acme --cert-name DOMINIO_REAL -d DOMINIO_REAL --email CORREO_REAL --agree-tos --non-interactive
# Regenera la configuración para habilitar TLS con el certificado persistente.
docker compose --env-file .env.azure-testing restart web
# Inicia los tres servicios principales, incluido el scheduler único.
docker compose --env-file .env.azure-testing up -d
```

Si falla la emisión, revisar DNS/puerto 80; no sustituir por certificados locales
ni exponer Laravel mediante HTTP. Nginx entrega el build, no Vite dev server.
FPM recibe HTTPS y la IP del visitante mediante FastCGI, sin ampliar la confianza
de proxies Laravel ni fiarse de `X-Forwarded-*` enviados por el cliente.

Solo si se necesitan trabajos de cola y existen sus tablas en la base remota:

```sh
docker compose --env-file .env.azure-testing --profile worker up -d worker
```

El timeout del worker es 60 s, menor que el `retry_after` actual de 90 s. Mantener
esa relación si se cambia la configuración de la cola.

## 5. Renovación y VM apagada

Certbot conserva cuenta, certificados y configuración en el volumen
`certificates`. El script siguiente renueva si corresponde y reinicia Nginx para
cargar el certificado; produce una interrupción breve y no inicia un sitio detenido:

```sh
./docker/renew-certificates.sh
# Ensayo real contra ACME staging, solo cuando dominio/red estén preparados:
docker compose --env-file .env.azure-testing run --rm --no-deps certbot renew --dry-run
```

Preparar en la VM `/etc/systemd/system/transporte-tls.service`:

```ini
[Unit]
Description=Renovar HTTPS de Transportes Liquidos pruebas
After=network-online.target docker.service
Wants=network-online.target
Requires=docker.service

[Service]
Type=oneshot
WorkingDirectory=/opt/transporte-liquidos
ExecStart=/opt/transporte-liquidos/docker/renew-certificates.sh
```

Y `/etc/systemd/system/transporte-tls.timer`:

```ini
[Unit]
Description=Comprobar renovacion HTTPS dos veces al dia

[Timer]
OnCalendar=*-*-* 00,12:00:00
OnBootSec=5min
RandomizedDelaySec=15min
Persistent=true
Unit=transporte-tls.service

[Install]
WantedBy=timers.target
```

Activar mediante `sudo systemctl daemon-reload` y
`sudo systemctl enable --now transporte-tls.timer`.
La renovación usa el [flujo de Certbot](https://eff-certbot.readthedocs.io/en/stable/using.html#renewing-certificates).
Una VM apagada no puede renovar. Tras períodos largos, arrancar Nginx y ejecutar
el script antes de usar la aplicación, comprobando la vigencia. El desafío HTTP
permite renovar aunque el certificado HTTPS haya vencido. DNS debe seguir apuntando
a la VM si su IP cambió. Revisar fallos con `journalctl -u transporte-tls.service`.

## 6. Parar y volver a utilizar

```sh
# Detiene también el worker opcional; conserva todos los volúmenes.
docker compose --env-file .env.azure-testing --profile worker stop
# Después puede apagarse/desasignarse la VM desde Azure.
# Al volver: Docker debe estar activo; up inicia lo que se detuvo manualmente.
docker compose --env-file .env.azure-testing up -d
./docker/renew-certificates.sh
# Reanudar worker solo si se estaba utilizando.
docker compose --env-file .env.azure-testing --profile worker up -d worker
```

`restart: unless-stopped` recupera procesos tras fallo/reinicio, pero respeta una
parada manual. Para reemplazar imágenes: construir, detener scheduler/worker,
recrear servicios y reactivar únicamente el worker necesario. Nunca arrancar dos
versiones de scheduler en paralelo ni crear otro proyecto Compose para actualizar.

## 7. Diagnóstico sin cambios de datos

```sh
docker compose --env-file .env.azure-testing ps
docker compose --env-file .env.azure-testing logs --tail=100 app web scheduler
docker compose --env-file .env.azure-testing exec web nginx -t
docker compose --env-file .env.azure-testing exec app php -m
curl --fail --show-error https://DOMINIO_REAL/up
curl --fail --show-error -I https://DOMINIO_REAL/build/driver-shell.html
```

`/up` acredita respuesta HTTP, no conectividad Oracle. No usar `curl -k`.
Una prueba de conexión remota debe autorizarse posteriormente y limitarse a lectura;
no ejecutar `migrate`, `db:seed`, pruebas con RefreshDatabase ni scripts de setup.
Para errores de conexión revisar DNS, ruta/firewall, CA legible, hostname del
certificado, usuario y base. No bajar `verify-full`. Para 502 revisar FPM; para 413
revisar límites Nginx/PHP (el contrato de negocio sigue en 10.000.000 bytes).
No publicar salida de configuración completa ni logs con datos sensibles.

Los límites iniciales son 3 GB para FPM (máximo 8 hijos), 512 MB por proceso CLI
y 256 MB para Nginx; ajustar solo con mediciones. Los logs Docker rotan a 3×10 MB
por contenedor. Supervisar disco/volúmenes y errores del scheduler.

## Verificación de esta entrega

Validado localmente: `php -l`, Pint del archivo PHP afectado, `sh -n`, análisis
YAML de Compose y sus controles de puertos/TLS/montajes, compilación Vue/PWA
con `TRANSPORTE_BUILD_OFFLINE=1` y `nginx -t` para HTTP/HTTPS. Nginx se validó
con puertos altos, resolución local del upstream y certificado sintético temporal;
no se inició servidor. El entrypoint real del scheduler se comprobó con dos
procesos concurrentes y un Artisan sintético aislado: solo ejecutó uno y permitió
reiniciar tras liberar el lock. El build conserva el aviso previo de importación
estática/dinámica de `DriverPortal/local.ts`.

Docker Desktop no está integrado en la distribución WSL utilizada: `docker compose
config`, construcción y smoke test de contenedores, persistencia real, exclusión
mutua entre contenedores, ACME y TLS Oracle quedan pendientes en un entorno
Docker autorizado.
No se ejecutaron migraciones, seeders, conexiones a bases ni despliegues.
