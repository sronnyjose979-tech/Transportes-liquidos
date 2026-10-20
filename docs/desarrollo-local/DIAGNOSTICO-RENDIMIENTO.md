# Diagnóstico de Navigation y Dashboard

Las [optimizaciones de consultas](OPTIMIZACION-CONSULTAS.md) conservan esta
instrumentación. Sus conteos de pruebas no incluyen el almacenamiento real de sesiones.

Instrumentación opcional para `GET /api/navigation` y `GET /api/dashboard`.
Desactivada por defecto. No cambia contenido, cabeceras, autorización ni reglas.

## Activación en Azure

En una actualización de código autorizada, configurar en el entorno efectivo del
contenedor Laravel (no en Vite):

```dotenv
PERFORMANCE_DIAGNOSTICS_ENABLED=true
```

Si Laravel usa configuración cacheada, regenerarla en ese mismo entorno con
`php artisan config:cache`. Renovar procesos persistentes mediante el procedimiento
habitual del entorno. No basta con cambiar una variable de Azure si no llega al
contenedor. Esta tarea no realiza despliegues ni cambios en Azure.

Los eventos usan el canal de logging existente. Debe admitir nivel `info` para
`performance.request` y `warning` para `database.operation_failed`. Revisar el
destino configurado: no necesariamente es `storage/logs/laravel.log`.

Abrir el Dashboard o reproducir la petición afectada con una sesión autorizada y
buscar ambos nombres de evento. Relacionarlos mediante `request_id`, generado en
el servidor; no se acepta un identificador proporcionado por el cliente ni se
añade a la respuesta. Peticiones concurrentes tienen identificadores diferentes.

Para desactivar, establecer la variable en `false` y regenerar la configuración
cacheada/renovar procesos de la misma manera.

## Métricas e interpretación

| Campo | Significado |
| --- | --- |
| `duration_ms` | Tiempo monotónico desde la entrada al primer middleware hasta su salida, incluida autenticación, sesión y manejo interno de errores. Excluye arranque previo de PHP/Laravel, cola de Nginx/FPM, envío por red y terminación posterior. |
| `sql_attempted_count` | Operaciones que alcanzan `beforeExecuting`; no cuenta como operación adicional un reintento interno de Laravel. |
| `sql_completed_count` | Consultas notificadas por `QueryExecuted`, incluidas sesiones y permisos dentro de la ventana. |
| `sql_completed_ms` | Suma de tiempos publicados por Laravel para consultas completadas; puede incluir apertura PDO y reintentos. No es tiempo puro de ejecución en PostgreSQL. |
| `failed_database_operation_ms` | Tiempo desde el inicio de la operación fallida hasta observar su excepción; puede incluir conexión, red y reintento. Se informa por separado de consultas completadas. |
| `connection_establishment_ms` | Siempre `null`: no hay medición aislada fiable con estos eventos. `ConnectionEstablished` puede preceder a la apertura diferida de PDO. No se fuerza una conexión ni se sustituye el conector. |
| `phase` | `request_pipeline`, `database_operation_before_first_success` o `database_operation_after_success`. Se conserva la fase del primer error SQL aunque después se guarde la sesión. |
| `sqlstate` | Código validado del primer `QueryException`, o `null` cuando no se observó uno. |
| `status` | Estado HTTP observado por el middleware. |

Un `08006` antes de la primera consulta completada puede ser fallo de conexión
inicial **o de la primera consulta**; esta instrumentación no distingue ambos.
Después de consultas completadas indica que la petición ya había utilizado la
base de datos. Ninguna fase determina por sí sola la causa del corte.

No se registran SQL, bindings, mensajes de excepción, nombres de conexión,
usuarios, direcciones IP, cookies, cabeceras de entrada ni cadenas de conexión.
No se guardan los objetos de eventos SQL. Los listeners se registran una vez al
arrancar el proveedor, y las métricas pertenecen al objeto de cada petición.
Las consultas internas de triggers y la configuración interna del conector PDO
no generan métricas individuales. Si una conexión fue creada antes de registrar
los listeners, sus intentos podrían no contarse; las consultas completadas sí.

La escritura del resumen es de mejor esfuerzo: un fallo del logger no altera la
respuesta. Una terminación abrupta del worker puede impedir que exista resumen;
la ausencia del evento no demuestra ausencia de una petición lenta. Los errores
SQL conservan su evento de advertencia y el mismo identificador cuando se reportan.

## Verificación segura

`vendor/bin/phpunit tests/Feature/RequestPerformanceTest.php` usa eventos y fallos
sintéticos, sesiones en memoria y peticiones sin acceso a PostgreSQL. Comprueba
activación, alcance, aislamiento entre peticiones, privacidad, correlación de
errores y conservación de respuestas/autorización de invitados.
