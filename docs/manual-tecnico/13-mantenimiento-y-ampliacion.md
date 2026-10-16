# 13. Mantenimiento y ampliación segura


## Encontrar una funcionalidad

Un cambio debe empezar por el recorrido existente, no por crear otra capa. Ejemplo: para entender un pago, buscar `payments` en rutas → `BillingController::pay()` → `BillingWriteRequest` → `BillingPayments::payment()` → tablas y triggers de Facturación → `InvoicesPanel.vue` → pruebas de pago/saldo. El [índice API](06-api-y-backend.md) y el [mapa de clases](03-modulos.md) acortan este recorrido.

Comandos de búsqueda de lectura útiles:

```bash
rg 'function payment|billing.payments' app routes tests
rg --files app/Modules/BillingAndPayments resources/js/modules/BillingAndPayments
rg 'billing_payments' database/migrations
```

Una **fuente de verdad** es el registro que decide un dato. Total de factura sale de detalles/pagos, no del texto visible; disponibilidad sale de asignación/impedimentos, no del selector; documento actual sale de su referencia, no de ordenar archivos por fecha. Localizar esa fuente evita duplicaciones.

## Añadir una validación

1. Aclarar si se trata de formato técnico o nueva regla empresarial. No convertir una sugerencia de interfaz en bloqueo de negocio sin aprobación.
2. Revisar Request y servicio: la misma clase puede variar por `routeIs()`/acción.
3. Conservar valores introducidos y errores de campo en Vue. TypeScript debe representar el contrato real.
4. Si la regla protege relaciones o cantidades ante concurrencia, evaluar constraint/trigger además de HTTP; una validación previa no basta.
5. Añadir prueba de rechazo y del caso válido afectado. Si el defecto era SQL directo, demostrar rechazo en PostgreSQL real.

No desactivar autorización para que una prueba pase. No transformar un conflicto en éxito ni quitar saneamiento SQL P0-04. Las altas P1-05 deben conservar su clave/snapshot y conflicto por contenido distinto.

## Crear una migración posterior

Una migración es una versión incremental del esquema. No editar las publicadas. Antes de crear otra: comprobar esquema final, datos históricos que requeriría completar, duración de locks, ruta de recuperación y compatibilidad del código anterior/siguiente. No elegir “último documento” ni inventar snapshots si no se puede reconstruir historia de forma fiable.

Separar cambios de estructura y decisiones de negocio; solicitar aprobación donde corresponda. Probar con `tests/run-postgres.sh` exclusivamente en aislamiento. Nunca ejecutar `migrate:fresh`, seeders o pruebas sobre `transporte_db`. Un rollback técnico no garantiza recuperar datos borrados ni rehacer archivos: el procedimiento necesita respaldo ensayado.

## Cambiar un servicio

Mantener controlador pequeño y transacción breve. Tomar locks en el orden existente; revalidar rol/versión después de obtenerlos. Conservar entidad, auditoría y recibo en la misma unidad atómica. No efectuar llamadas externas prolongadas dentro de una transacción. Si un trigger ya origina movimiento, no repetirlo en el servicio.

Para nuevas operaciones idempotentes, definir actor/ámbito/clave/contenido canónico/resultado. Misma clave y contenido debe devolver resultado original; distinta intención utiliza nueva clave. Probar commit con respuesta perdida, concurrencia y contenido distinto. No deducir duplicación comercial por cliente/fecha/ruta.

Para archivos, reutilizar mecanismo concreto de reserva/compensación del módulo. No borrar un histórico al limpiar un pendiente. Para cambios de gastos, alcances o lecturas, respetar revisiones y autorizaciones específicas.

## Modificar Vue sin romper recuperación

Partir del componente y adaptador existentes. No introducir estado global ni librería nueva si no hay necesidad. Guardar estado de envío antes de esperar; bloquear doble clic; conservar datos ante 422; distinguir POST confirmado de refresco fallido. No generar nueva clave al recibir timeout. Mantener confirmación para operaciones importantes y mensajes comprensibles.

En la PWA, no cambiar la versión IndexedDB sin migración local que preserve capturas. No cachear APIs privadas en service worker para resolver una pantalla vacía. No borrar capturas por cerrar sesión, actualizar shell o limpiar formulario distinto. Cualquier modificación de sincronización necesita pruebas con Chromium persistente, reinicio y respuestas perdidas.

## Escribir y elegir pruebas

Reproducir primero el defecto: un test debe fallar por el comportamiento incorrecto, no solo reflejar la implementación. Elegir nivel proporcional: formato aislado, HTTP/permisos, PostgreSQL para constraints/concurrencia, frontend para estados y E2E para integración real. No repetir batería integral porque cambió una frase Markdown.

`npm run typecheck`, `npm run build`, Pint cuando hay PHP, suites afectadas y diff check completan la revisión. Los resultados se informan como ejecutados, omitidos o bloqueados, con versiones y limitaciones. No sumar aserciones de ejecuciones superpuestas.

## Diagnosticar fallos comunes

| Síntoma | Investigación segura |
| --- | --- |
| 401/419 | Identidad, sesión y CSRF; conservar intención, no reenviar a ciegas |
| 403 | Gate/Policy y acceso concreto; cuenta activa, rol actual y asignación |
| 409 | Versión, autorización exacta, clave/contenido, conflicto de recursos; volver a consultar |
| 422 | Errores de campos, relaciones, precisión o constraint traducida; conservar formulario |
| SQL interno | SQLSTATE saneado y operación conocida; reproducir con datos sintéticos, no habilitar log crudo |
| Stock inesperado | Origen del movimiento, unidad, conciliación, bodega/custodia/consumido; no editar saldo |
| Falta comprobante | Recibo/reserva/marcador y raíz privada; usar reconciliación conservadora |
| Captura offline pendiente | Actor local, conexión real, sesión, dependencia, estado, intentos y contexto |
| Reporte duplicado | Agregaciones antes de joins uno-a-muchos, filtros y significado temporal de cada total |

## Publicación y límites

Leer `AGENTS.md` en cada trabajo; revisar `git status`/diff y excluir secretos, artefactos, respaldos y datos sintéticos. No desplegar por el hecho de poder hacer push. La documentación de producción enumera decisiones y configuración aún no demostradas. Cambios comerciales pendientes no se resuelven desde un fix técnico.
