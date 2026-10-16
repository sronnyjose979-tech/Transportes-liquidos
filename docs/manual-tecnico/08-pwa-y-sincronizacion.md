# 08. PWA y sincronización del conductor


## Piezas diferentes con responsabilidades diferentes

Un **service worker** es un script del navegador que puede responder a ciertas peticiones aun sin red. Una **caché** conserva recursos para reutilizarlos. **IndexedDB** persiste objetos/archivos locales mediante transacciones. La **cola de capturas** registra intenciones pendientes de confirmar. No confundir tener interfaz offline con tener datos ya guardados en PostgreSQL.

| Archivo real | Papel |
| --- | --- |
| `resources/js/modules/DriverPortal/pwa-build.mjs` | Generación de recursos/shell y service worker durante Vite |
| `resources/js/modules/DriverPortal/pwa.ts` | Registro y ciclo de actualización de la PWA |
| `resources/js/modules/DriverPortal/local.ts` | IndexedDB, caché mínima, capturas y bloqueo de sesión |
| `resources/js/modules/DriverPortal/offline.ts` | Transmisión y sincronización de la cola |
| `resources/js/modules/DriverPortal/contracts.ts` | Proyección tipada de viajes y registros del conductor |
| `resources/js/modules/System/DriverTrips.vue` | Interfaz móvil, formularios, estados y reintentos |
| `app/Modules/TransportAndDispatch/Application/DriverOfflineContext.php` | Token de contexto y validación de cambios de viaje/asignación |
| `app/Modules/TransportAndDispatch/Application/DriverTripRecords.php` | Registro idempotente de reportes/lecturas |

La ruta `/driver-sw.js` sirve el artefacto generado con ámbito permitido `/mis-viajes`. El SW precarga recursos públicos y un shell vacío. No cachea indiscriminadamente HTML autenticado, respuestas API o comprobantes privados. Actualizaciones esperan a que cierren ventanas antiguas; no reemplazan silenciosamente una aplicación con capturas en curso.

## Datos locales exactos

`local.ts` abre `indexedDB.open('driver-portal', 1)` y crea:

- `trips`, clave compuesta `['actor', 'trip.id']`: proyección mínima del viaje y gastos sin bytes de comprobantes. Conserva producto, estado, programación, nombres de ruta y equipos; no toda la respuesta administrativa ni historiales privados completos.
- `captures`, clave `id`: actor, título, ruta, cuerpo, archivo opcional, dependencia, estado, mensaje, intentos, siguiente intento, resultado y fecha local.

La caché de lectura caduca a las 24 horas. La lectura elimina caché vencida; **no equivale a borrar capturas pendientes**. `localStorage` conserva únicamente marcador local de actor/roles con expiración para desbloquear la experiencia offline; no contiene contraseña ni token de sesión. Ese marcador no autentica en el servidor. `lockLocal()` lo retira, conservando las capturas separadas por actor.

Una captura se crea con `crypto.randomUUID()` y el mismo UUID se envía como `request_key`. Su cuerpo permanece estable al reenviar. La hora del dispositivo organiza presentación local; no sustituye fechas/orden oficiales del servidor.

## Estados visibles y significado

| Estado guardado | Qué significa |
| --- | --- |
| `pendiente` | Captura local todavía no confirmada o fallo recuperable de conexión |
| `sincronizando` | Envío iniciado, esperando confirmación; no es éxito |
| `sincronizado` | Servidor devolvió identificador inequívoco del resultado |
| `rechazado` | 422: el servidor rechazó los datos; no reenviar automáticamente |
| `en conflicto` | 403/404/409: permiso, contexto o recurso incompatibles; necesita revisión |

Una sincronización anterior exitosa no convierte un conflicto nuevo en éxito. Los formularios independientes conservan sus borradores/comprobantes. Los textos explican que hay que abrir la aplicación y recuperar conexión para sincronizar.

## Algoritmo real de sincronización

1. `sessionLock()` obtiene Web Locks `driver-offline-session-v1`, común a envíos y cambios de cuenta entre pestañas. Sin Web Locks se informa que el navegador no ofrece el mecanismo requerido; no simula seguridad equivalente.
2. `synchronize()` compara actor local y `/api/session`; si cambió la cuenta, bloquea acceso local y conserva capturas del actor original.
3. Refresca CSRF y lee capturas en orden: primero padres sin dependencia y luego dependientes.
4. Omite confirmadas/rechazadas/conflictivas. En modo automático respeta `next` y máximo cinco intentos; el reintento manual conserva clave/contenido y puede volver a intentar pendientes.
5. Marca sincronizando y guarda antes de transmitir. Cada envío vuelve a comprobar identidad. El timeout de `transmit()` es 20 segundos.
6. POST usa sesión y CSRF. Un gasto devuelve `expense_id`; su comprobante depende de ese resultado y usa `/expenses/{id}/receipts`; reporte/lectura devuelve `id`; archivo devuelve `receipt_id`.
7. Solo después de confirmar se marca sincronizado y se liberan bytes del archivo local. Si la respuesta es incompleta, conserva la captura aunque HTTP haya sido exitoso.
8. Ante fallo recuperable aumenta intentos y programa espera exponencial limitada a 60 segundos; 401/419 bloquea contexto local para recuperar sesión. No borra contenido ni crea una nueva intención automáticamente.

```mermaid
sequenceDiagram
    participant D as Conductor
    participant L as IndexedDB
    participant S as synchronize / Web Lock
    participant A as Laravel
    participant P as PostgreSQL
    D->>L: guardar captura + UUID + archivo
    L-->>D: pendiente local
    Note over D,L: No es confirmación del servidor
    D->>S: sincronizar con aplicación abierta
    S->>A: comprobar sesión y CSRF
    S->>A: POST con mismo request_key y contexto
    A->>P: lock, recibo previo, elegibilidad y escritura
    P-->>A: commit / resultado previo
    A-->>S: identificador confirmado
    S->>L: sincronizado; liberar bytes confirmados
```

## Respuesta perdida e idempotencia

```mermaid
sequenceDiagram
    participant C as Cola local
    participant A as Backend
    participant P as PostgreSQL
    C->>A: primera intención K
    A->>P: registro + recibo K en una transacción
    P-->>A: confirmado
    A--xC: respuesta perdida
    Note over C: Sigue pendiente; conserva K y cuerpo
    C->>A: reintento de K con mismo contenido
    A->>P: buscar recibo y comparar huella
    P-->>A: resultado original
    A-->>C: mismo identificador
```

El contexto offline refleja viaje/asignaciones. Si cambiaron antes de procesar una captura nueva, el servidor puede devolver conflicto; no aplica “último gana”. Si el recibo ya existe, recuperarlo evita convertir una escritura confirmada en fallo por una liberación posterior. La caché no permite finalizar viajes ni eludir que un conductor dejó de estar habilitado.

## Comprobantes y dependencias

Gasto y archivo son capturas distintas vinculadas. Primero se confirma el gasto; el archivo utiliza su identificador real. Conservar un `File`/Blob en IndexedDB permite reiniciar el navegador sin perder esos bytes mientras el navegador conserve almacenamiento. No se guarda una ruta de disco del teléfono como si fuera un archivo recuperable. Un error de cuota debe informarse, sin presentar captura guardada.

## Sesiones y salida

El portal avisa si hay pendientes antes de salir. El usuario no queda encerrado indefinidamente. Sin conexión se explica de inmediato que cerrar la sesión del servidor necesita conexión; no se afirma que invalidó la cookie remotamente ni se borran capturas para simularlo. Otra cuenta no puede ver ni enviar los pendientes anteriores: debe recuperarse la original.

## Límites reales

La sincronización requiere página abierta, conectividad efectiva y sesión válida; `navigator.onLine` por sí solo no demuestra acceso a Laravel. No hay garantía de Background Sync, ejecución con aplicación cerrada ni persistencia frente a borrado por el sistema operativo. IndexedDB no está cifrada por usuario frente a un XSS dentro del mismo origen; controlar dispositivo y contenido sigue siendo necesario.

La evidencia disponible incluye Chromium persistente, reinicio offline y conservación/sincronización de comprobantes, sesiones, idempotencia y conflictos. No acredita pruebas en teléfono físico, Safari/iOS, cuota agotada de todos los dispositivos ni suspensión prolongada. Ver [documentación especializada](../conductor-offline.md) y [pruebas](11-pruebas.md).
