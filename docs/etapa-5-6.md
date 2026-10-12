# Etapa 5.6 — Integración y estabilización de Transporte y Despacho

Base publicada: b155aa37a9f7231cfc8962acc6a1d1147f81e4a1. Se revisan las reglas
RN-TR-01..39, RN-SEG-01, D02, el ER y los contratos de 5.2A, 5.2B, 5.3, 5.4 y 5.5.
No se introducen módulos, dependencias, tablas ni cambios de migraciones publicadas.

## Correcciones verificadas

### Captura incompleta de carga y descarga

Defecto de integración: PostgreSQL y los modelos permiten conservar una cabecera
única con detalles registrados progresivamente, pero la API solo permitía crear la
cabecera una vez. Una descarga parcial dejaba detalles cargados sin contraparte y
D02 impedía finalizar sin que existiera una recuperación por API o interfaz.

Se habilita **añadir detalles omitidos**, nunca reemplazar los existentes:

- POST `/api/transport/trips/{id}/load/items`: `items[{compartment_id,liters}]`.
- POST `/api/transport/trips/{id}/unload/items`: `items[{load_item_id,liters}]`.
- Ambas reciben `occurred_at` ISO con offset, `reason` y `evidence` opcional;
  no reciben sitio nuevo ni identidad del actor. Devuelven 201 con la cabecera original
  y todos sus detalles mediante el Resource existente. El responsable proviene de la sesión.
- Administrator y secretary conservan sus Gates. El conductor no registra cantidades.
- Solo se completa captura pendiente/en curso; esta operación no implementa correcciones
  de registros terminales ni modifica el contrato de correcciones de kilometraje cerrado.
- Para añadir carga, la cisterna de la cabecera debe continuar asignada. Se conservan las
  validaciones de compartimento activo y capacidad, con snapshot tomado al registrar el
  nuevo detalle, como en 5.3. No se inventa una configuración anterior desconocida.
- Cabeceras, detalles anteriores, producto histórico, motivos, fechas y actores no se
  actualizan. Cada detalle añadido conserva su propio actor, instante real, registro,
  motivo y evidencia. La interfaz muestra esta auditoría individual.
- El lote es atómico bajo el bloqueo transaccional y la fila del viaje existentes. Una
  relación inválida, capacidad excedida o detalle repetido revierte todo el lote.
  UNIQUE carga/compartimento y detalle de descarga/carga siguen evitando duplicados.
- Triggers existentes verifican pertenencia al mismo viaje e inmutabilidad, también
  ante SQL directo. Las restricciones de D02 y autorización exacta RN-TR-10 se reutilizan.
  Añadir datos cambia el contenido aprobado: una autorización anterior no puede consumirse
  para cantidades o detalles distintos, aunque la versión raíz del viaje no haya cambiado.
- No se reintenta automáticamente un POST. Tras perder una respuesta se consulta la
  operación confirmada y los detalles desaparecen de la selección pendiente.

La interfaz muestra únicamente detalles todavía no registrados y mantiene un registro
único de carga/descarga. La descarga sigue exigiendo valores para todos los detalles
faltantes, incluido cero. No exige cargar todos los compartimentos físicos.

**Límite distinto:** corregir una cantidad ya registrada, cambiar el sitio original o
reinterpretar una operación terminal requiere un procedimiento histórico específico.
No se crea ese procedimiento ni se permite edición/eliminación silenciosa.

### Distancia y accesibilidad

La distancia consultada permanecía visible al seleccionar otra asignación de cabezal.
Se limpia al cambiar el tramo y antes de otra consulta; una respuesta de otro tramo
no reemplaza el seleccionado. La prueba Chromium reproduce la consulta y el cambio.

El selector de historial carecía de un nombre accesible estable para elegir reportes.
Se añade `aria-label`; el flujo real selecciona el reporte del conductor y consulta sus
valores, sin confundirlo con finalización oficial. El aviso de captura incompleta ahora
indica la recuperación disponible, en lugar de afirmar que no existe endpoint.

## Flujos e integridad verificados

La suite real usa Chromium, Blade/Vue, Laravel, sesiones, CSRF y PostgreSQL 18. No
intercepta ni fabrica respuestas API de negocio. El proxy únicamente pierde una
respuesta real después de confirmarla en Laravel.

1. Secretaria registra solicitud y viaje, programa, consulta disponibilidad y asigna
   conductor/cabezal/cisterna. Inicia oficialmente; se pierde la respuesta confirmada,
   consulta el estado y verifica una sola auditoría de inicio.
2. Registra kilometraje inicial durante el viaje. El conductor asignado, en otra sesión,
   registra reporte informativo; su intento de finalización se rechaza. Registra carga,
   descarga y kilometraje final, finaliza y cierra con pendientes. Los recursos siguen
   asignados. Libera manualmente los tres; corrige kilometraje cerrado tras liberar el
   cabezal, conserva revisión previa y consulta distancia exacta. Lee el reporte e historial
   y verifica presentación en tablet.
3. Diferencia 100/90 litros: secretaria solicita aprobación y no puede decidirla;
   administrador revisa y aprueba contenido exacto; finalización consume una aplicación.
4. Cabecera de descarga histórica incompleta: añade detalle desde la pantalla, pierde su
   respuesta confirmada, recupera mediante GET, conserva cabecera y solo un detalle;
   finaliza con D02 completo. Un reenvío no altera el registro.
5. Dos sesiones intentan asignar el mismo conductor: una confirma y la otra recibe 409.
   Se rechazan inicio sin activos y fin incompleto. CSRF sin token ni metadatos de origen
   devuelve 419. Se cancela, rechaza una versión obsoleta, cierra sin finalización ficticia
   y libera manualmente, conservando actores, fechas y avisos.

Laravel 13 permite origen `Sec-Fetch-Site: same-origin` o token válido: una prueba sin
token debe omitir también metadatos válidos de origen. No se cambia ese middleware.
Los datos, sesiones, cache, credenciales sintéticas y archivos E2E son temporales.
La fixture limpia solo el cache aislado entre casos; el límite de login real permanece.

Se reutilizan las suites PostgreSQL existentes para capacidad y elegibilidad, compartimentos
ajenos, relaciones entre viajes, revocación de roles, autorizaciones no reutilizables,
transacciones/auditoría fallidas, cierre con avisos, historial inmutable y concurrencia de
asignaciones/inicio/fin/lecturas. No se añaden pruebas duplicadas de esas restricciones.
Las nuevas pruebas HTTP cubren captura progresiva, lote revertido, roles, actor falsificado,
sitio prohibido, diferencia aprobada obsoleta y finalización recuperada. Conexiones PostgreSQL
independientes verifican que dos detalles rivales esperan y no duplican el detalle confirmado.

## Ejecución

Con las herramientas aprobadas del entorno activadas, sin `public/hot`:

```sh
source /workspace/.tools/activate.sh
npm run typecheck
npm run build
npm run test:frontend
bash tests/run-postgres.sh
bash tests/run-postgres.sh --e2e-transport
vendor/bin/pint --test
git diff --check
```

El runner crea socket, clúster y base `transporte_test` bajo /tmp, comprueba PostgreSQL 18,
detiene el clúster al terminar y conserva trazas/capturas. No carga el .env de desarrollo
para E2E. Nunca se migra, modifica o prueba `transporte_db`.

## Resultados

Resultados finales ejecutados, sin pruebas omitidas:

- `bash tests/run-postgres.sh`: **194 pruebas, 1.846 aserciones correctas**;
  /tmp/transporte-tests-LycSis. Cuatro pruebas backend adicionales respecto a 5.5.
- `npm run test:frontend`: **24 pruebas correctas** en Chromium con API explícitamente
  simulada; incluye regresión de distancia al cambiar el tramo. Artefactos de transporte:
  /tmp/transporte-web-tests-z0mlszk1.
- `bash tests/run-postgres.sh --e2e-transport`: **4 pruebas reales correctas** en 64,638 s;
  /tmp/transporte-tests-ZA9MGs/e2e/browser. Chromium/Laravel/PostgreSQL 18.3 sin API simulada.
  Incluye capacidad excedida con rollback, solicitud incompleta, denegación administrativa
  al conductor y a la secretaria, CSRF, concurrencia y recuperación de respuestas perdidas.
- `npm run typecheck`, `npm run build`, `vendor/bin/pint --test` y `git diff --check`:
  correctos. Vite mantiene el aviso preexistente de fontaine opcional; no se instala.

Las ejecuciones iniciales detectaron una fixture multicompartimento sin particiones y
ajustes de navegación/origen CSRF/límite de login en la ampliación E2E. Se corrigieron
las fixtures y aserciones sin desactivar controles del sistema. El selector de historial
recibió un nombre accesible estable; todas las suites finales anteriores terminaron bien.

Archivos creados: `docs/etapa-5-6.md` y `tests/Feature/TransportPartialCaptureTest.php`.
Archivos modificados: ARCHITECTURE.md y nota de seguimiento en docs/etapa-5-5.md;
TripOperations, TripOperationController, OperationWriteRequest y routes/transport.php;
OperationsPanel, OdometerPanel, TripDetail y contracts.ts;
TripOperationsConcurrencyTest, test_transport.py, test_transport_module.py y
transport-console.php. **Sin migraciones nuevas o modificadas**, dependencias ni .env.

## Riesgos y decisiones pendientes

- RN-TR-17: conducta ante permisos obligatorios ausentes sigue sin definición.
- D05: sustitución de cisterna bloqueada; no se deduce vacía ni se crea protocolo.
- Cambios de producto y cancelación posterior a finalización siguen fuera de la API.
- No se implementa corrección de cantidades históricas ni evidencia documental obligatoria.
  Un dato ya registrado incorrectamente requiere decisión sobre su corrección auditable.
- Recuperación de respuesta perdida usa GET y revisión humana, sin reenvío automático.
  No hay clave durable de idempotencia ni cola offline. Tras recargar el navegador debe
  consultarse el historial antes de volver a crear una solicitud sin respuesta confirmada.
- Capacidad/configuración de un nuevo detalle se captura al registrarlo; no se reconstruyen
  datos anteriores desconocidos. Registros existentes mantienen sus snapshots.
- Disponibilidad es informativa. Las excepciones autorizadas no garantizan disponibilidad
  física; el bloqueo global existente serializa escrituras y su rendimiento debe medirse
  antes de un despliegue con alto volumen.
- API sin creación de rutas, adjuntos operativos o lista global de autorizaciones; se usan
  catálogos existentes, metadata de evidencia y autorizaciones por viaje.

Se verifica el funcionamiento del alcance aprobado; **no se declara listo para producción**
con estos límites de negocio y operación pendientes. Antes del siguiente módulo conviene
resolver procedimientos de corrección histórica y preparar datos maestros reales, respaldos
probados y criterios de despliegue. Esta etapa no inicia Taller Mecánico ni otra funcionalidad.
