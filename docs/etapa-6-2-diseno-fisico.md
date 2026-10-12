# Taller Mecánico — fase 0: diseño físico PostgreSQL 18

## Autoridad, alcance y aprobaciones

Fecha: 12 de octubre de 2026, America/Costa_Rica. Complementa el
[cierre lógico 6.2](etapa-6-2.md), [RN-TAL](REGLAS_TALLER_MECANICO.md),
[arquitectura vigente](../ARCHITECTURE.md) y [perfiles](agentes-codex.md).
El ER global histórico no sustituye estas fuentes.

El usuario aprobó las decisiones físicas F01–F07 siguientes y autorizó completar
su documentación. Las etiquetas F distinguen esta aprobación de T01–T09, que
permanecen íntegras. Se conservan exactamente las 27 entidades del cierre lógico.
Este diseño documentado no acredita ejecución ni autoriza crear/aplicar migraciones,
modelos, servicios, API o frontend, operar bases del proyecto, commit o push.

| Decisión física aprobada | Concreción y condición incorporada |
| --- | --- |
| F01 | Decimal como texto, validación SQL anterior al cast; funciones de escritura y privilegios separados. Tipos NUMERIC aprobados conservados. |
| F02 | Observación administrativa explícita de estados previos desconocidos en #22; resolución administrativa y FK explícita de auditoría, sin reparación ficticia. |
| F03 | Reserva/confirmación/liberación como operaciones técnicas definitivas en #13; #20 solo adjuntos confirmados; máximo un terminal por reserva mediante UNIQUE, restricciones diferidas y locks. |
| F04 | Primer despliegue: servidor único y almacenamiento privado local; respaldo/restauración PostgreSQL y archivos como conjunto coherente, conciliación incluida. |
| F05 | Secretaria: coordinación, evidencia y costos documentados; mecánico: lectura histórica de sus órdenes y escritura solo con participación vigente; administrador: realización externa y conciliación. |
| F06 | cost_kind NULL exclusivamente para costo documental pendiente de clasificación; fuera de totales; clasificación por revisión autorizada; global externo puede cubrir piezas/mano de obra. |
| F07 | Parámetros configurables: tres intentos totales y diez segundos iniciales; solo errores transitorios seguros, transacción completa y misma clave. |

## Convenciones físicas normativas

La notación abrevia columnas, no deja su tipo o nulabilidad pendientes:

| Notación | Expansión exacta |
| --- | --- |
| PK | Cada tabla contiene id UUID NOT NULL PRIMARY KEY; UUID generado por servidor de aplicación antes de su transacción, sin consecutivo comercial. |
| U | UUID NOT NULL. U? = UUID NULL. |
| B | BIGINT NOT NULL. B? = BIGINT NULL. Los actores referencian users(id), sin convertir User a UUID. |
| T | TEXT NOT NULL. T? = TEXT NULL. Campos requeridos de descripción/motivo: CHECK btrim(valor) <> ''. |
| I | INTEGER NOT NULL, con CHECK > 0 para revisión/número de línea. |
| V | BIGINT NOT NULL CHECK > 0; DEFAULT 1 únicamente en raíces mutables nuevas. |
| Z | TIMESTAMPTZ(6) NOT NULL. Z? = TIMESTAMPTZ(6) NULL. registered_at tiene DEFAULT clock_timestamp() del servidor; nunca hora cliente. |
| Q | NUMERIC(18,6) NOT NULL: finito y 0 < valor < 10^12. |
| C? | NUMERIC(20,6) NULL: si presente, finito y 0 <= valor < 10^14. |
| J | JSONB NOT NULL CHECK jsonb_typeof(valor) = 'object'. J? permite NULL; si presente, objeto. |
| H | actor_id B → users; occurred_at Z; registered_at Z; reason T. El actor es el auténtico ejecutor. |
| R(p) | root_record_id U → misma tabla(id); columna p U? → misma tabla(id); revision I. Raíz inicial = id, p NULL y revision=1; sucesor conserva raíz, p no NULL y revision>1. |
| FKI | B-tree en cada FK como prefijo izquierdo, salvo cobertura por PK/UNIQUE/índice compuesto ya declarado. En FK nullable puede ser parcial WHERE FK IS NOT NULL; no omitirlo sin justificar cobertura. |

Todas las FK nuevas: ON DELETE RESTRICT y ON UPDATE RESTRICT. Las FK cuya
inserción exige ciclos son DEFERRABLE INITIALLY DEFERRED para comprobar existencia
al commit; RESTRICT no permite diferir una eliminación. Nunca cascade ni soft-delete.
Las FK restantes son inmediatas. No hay updated_at/deleted_at implícitos.
Fechas de hechos y de registro son conceptos distintos; presentación America/Costa_Rica.
No imponer occurred_at <= registered_at como regla comercial nueva.
Campos opcionales no reciben cero, cadena vacía ni moneda inventada como default.

Las tablas históricas son append-only: privilegios y triggers rechazan
UPDATE/DELETE/TRUNCATE. Excepciones de mutabilidad: version de #2; status/priority/
description/version de #3 únicamente mediante cambio oficial auditado y permiso
correspondiente. No cambiar su activo. Revisión de hechos crea otro registro.
Los slots técnicos de reserva también son hechos inmutables, no filas provisionales.

R exige UNIQUE(p) cuando p no NULL, UNIQUE(root_record_id,revision), CHECK raíz/
predecesor distinto de id salvo raíz inicial, y trigger de igualdad de ámbito,
predecesor hoja, revisión anterior+1 y ausencia de ciclos bajo bloqueo de raíz.
Cada consulta de hechos efectivos toma una sola hoja por raíz. No SUM de revisiones.
Excepción de selección: scope tiene hoja propuesta y revisión aplicada distintas.
Para ejecución/cuotas tomar la última revisión aplicada de cada raíz, no una hoja
pendiente/rechazada. La hoja propuesta sirve a solicitudes, no habilita trabajos.
La inmutabilidad de un scope incluye sus líneas: no añadir líneas después de la
solicitud ni modificar un snapshot. Construir cabecera/líneas/solicitud atómicamente.

### Claves compuestas de ámbito

Declarar UNIQUE(id,fleet_asset_id) en #1/#2/#3; UNIQUE(id,work_order_id) en
#5/#7/#8/#19/#20/#21/#26; UNIQUE(id,scope_id) en #9/#10.
Usar FK compuesta donde el hijo contiene esas columnas. Por ejemplo #27 referencia
(work_order_id,fleet_asset_id) y (admission_id,fleet_asset_id); #8 referencia
(diagnosis_id,work_order_id); #10 referencia (task_id,scope_id) si task_id presente.
Las claves compuestas con componente nullable usan MATCH SIMPLE y las combinaciones
permitidas se completan con CHECK; no confundir NULL con un vínculo validado.
Si el hijo no contiene ambas columnas, un trigger valida el ámbito por la cadena
de FK; no añadir copias de activo/orden a todas las tablas solo para simplificarlo.

## Diccionario de las 27 tablas

FK: cada flecha indica destino exacto id salvo clave compuesta expresamente indicada.
Cada entrada incorpora PK, FKI y las convenciones aplicables. Los índices de lectura
terminan en id para orden estable/paginación cuando el orden previo no es único.

### 1. workshop_reports — reporte, no orden ni ingreso

- Columnas: fleet_asset_id U → fleet_assets; trip_event_id U? → trip_events;
  description T; reporter_id B? → users; recorded_by_id B → users;
  occurred_at Z; registered_at Z; reason T; R(previous_report_id).
- reporter_id NULL significa comunicador no identificado, no actor registrador ausente.
- Restricciones: R; evento/activo corresponden al historial de asignaciones al instante
  del hecho. Corrección conserva identidad del activo y el evento original comunicado.
- Índices: (fleet_asset_id,occurred_at,id), (trip_event_id) parcial, R y FKI.

### 2. workshop_admissions — ingreso físico

- Columnas: fleet_asset_id U → fleet_assets; kind T = corrective/preventive;
  admitted_at Z; admitted_by_id B → users; reason T; registered_at Z; version V.
- UNIQUE(id,fleet_asset_id). Índice (fleet_asset_id,admitted_at,id); FKI.
- Un ingreso abierto se deriva de ausencia de #24. Trigger de restricción diferido
  en ingreso/salida y bloqueo del activo garantizan <=1 abierto, incluso dos INSERT
  de una sentencia. No índice parcial basado en otra tabla.
- Inserción exige impedimento de ingreso #22, proyección y operación #13 en mismo commit.
  Salida incrementa version mediante operación exacta; no booleano de liberación.
  Rectificación autorizada de fecha/tipo/motivo conserva columnas originales y registra
  valores anteriores/nuevos tipados y validados en #13 admission_rectify; incrementa
  version. Lectura efectiva reconstruye los escalares según operaciones por to_version,
  nunca mueve fleet_asset_id ni inventa segundo ingreso. Validaciones temporales de
  salida usan fecha efectiva y rechazan corrección incompatible con salida existente.
  JSON de esa operación es auditoría/revisión escalar aprobada, no sustituto de FK.

### 3. workshop_work_orders — una reparación o servicio

- Columnas: fleet_asset_id U → fleet_assets; kind T = corrective/preventive;
  description T; priority T = low/normal/high/urgent; status T según siete estados;
  created_at Z; created_by_id B → users; version V.
- Estados: pending_diagnosis/pending_authorization/authorized/in_repair/finished/
  closed/cancelled. CHECK enumeraciones. UNIQUE(id,fleet_asset_id).
- Índices: (fleet_asset_id,status,created_at,id), (status,created_at,id); FKI.
- No UNIQUE de orden abierta por activo. Cambios/versiones correlacionados con #13;
  una única reparación es validación funcional de oficina, no semántica automática SQL.

### 4. workshop_report_links — reporte N:M orden

- Columnas: report_id U → #1; work_order_id U → #3; linked_by_id B → users;
  registered_at Z; reason T.
- UNIQUE(report_id,work_order_id); índice (work_order_id,registered_at,id); FKI.
- Trigger de mismo activo. Invalidación mediante operación #13 report_link_invalidate,
  no DELETE; una invalidación por vínculo y consulta efectiva que la excluya.

### 5. workshop_mechanic_assignments — participación histórica

- Columnas: work_order_id U → #3; employee_id U → employees; starts_at Z;
  registered_at Z; assigned_by_id B → users; reason T.
- UNIQUE(id,work_order_id); índices (work_order_id,employee_id,starts_at,id),
  (employee_id,starts_at,id); FKI.
- Cuenta/empleado activos y rol mechanic al asignar. Bajo bloqueo de orden/pareja,
  trigger diferido con #6 impide dos participaciones vigentes de la misma pareja.
  No exclusividad del mecánico entre órdenes.

### 6. workshop_mechanic_releases — fin de participación

- Columnas: assignment_id U → #5; ends_at Z; recorded_by_id B → users;
  reason T; registered_at Z.
- UNIQUE(assignment_id); índice (ends_at,id); FKI.
- Trigger ends_at >= starts_at y ámbito/actor oficina. No libera activo ni cierra
  tiempos automáticamente. No inventar fin de trabajo a partir de liberación.

### 7. workshop_diagnoses — diagnóstico revisable

- Columnas: work_order_id U → #3; mechanic_assignment_id U? → #5;
  problem T; probable_cause T; proposed_repair T; H; R(previous_diagnosis_id).
- UNIQUE(id,work_order_id); índice (work_order_id,registered_at,id); R y FKI.
- Participante de misma orden; NULL solo para administrador con actor propio.
  Problema/causa/propuesta presentes antes de solicitar reparación inicial.

### 8. workshop_scopes — snapshot relacional de alcance

- Columnas: work_order_id U → #3; diagnosis_id U → #7; scope_kind T =
  initial/extension/revision; proposed_by_id B → users; description T;
  registered_at Z; R(previous_scope_id).
- UNIQUE(id,work_order_id); índice (work_order_id,registered_at,id); R y FKI.
- Diagnóstico de misma orden. initial/extension crean raíz nueva, revision=1 y
  previous_scope_id NULL; revision sustituye versión de esa raíz, con R.
  Una ampliación no se cuenta como revisión ni reemplaza autorización anterior.
- Cambio autorizado de líneas se identifica por raíz scope + número de línea:
  conservar número de línea al revisar la misma tarea/repuesto, no reutilizarlo para
  otro origen; si cambia identidad funcional, requiere alcance explícito autorizado.
  Cuotas acumuladas de una línea revisada no vuelven a cero.
  Techo efectivo es el de la última revisión aprobada y aplicada de esa raíz/línea;
  una revisión propuesta/rechazada conserva el anterior. Aplicar revisión bloquea
  raíz scope/líneas y valida todo entregado/usado antes de sustituir ese techo.

### 9. workshop_scope_tasks — trabajos concretos

- Columnas: scope_id U → #8; description T; line_number I;
  supplier_id U? → suppliers.
- UNIQUE(scope_id,line_number); UNIQUE(id,scope_id); FKI.
- Al menos una tarea al solicitar autorizar reparación, comprobado diferidamente.
  Proveedor externo concreto cuando corresponda; sin inventar proveedor ni compra.

### 10. workshop_scope_parts — repuestos solicitados/autorizables

- Columnas: scope_id U → #8; task_id U? → #9; spare_part_id U → spare_parts;
  line_number I; quantity Q; unit_snapshot T; description_snapshot T.
- UNIQUE(scope_id,line_number); UNIQUE(id,scope_id); FK(task_id,scope_id) → #9.
- FKI; cantidad exacta y unidad del catálogo al registrar. No UNIQUE de repuesto:
  puede aparecer en tareas/líneas distintas. Snapshot no cambia al editar catálogo.

### 11. workshop_authorization_requests — solicitud exacta

- Columnas: work_order_id U? → #3; admission_id U? → #2; scope_id U? → #8;
  type T = repair/extension/rectification/reopening/cancellation/exit;
  previous_request_id U? → #11; content J; target_version B?;
  requested_by_id B → users; reason T; registered_at Z.
- UNIQUE(previous_request_id) si presente; predecesor de mismo ámbito/tipo y solicitud
  corregida tras rechazo, sin ciclo. target_version NULL o >0.
- CHECK: repair/extension tienen orden+scope y no ingreso; exit tiene solo ingreso;
  reopening/cancellation tienen orden y no scope/ingreso; rectification tiene orden
  o ingreso (exactamente uno), scope opcional solo si afecta alcance de esa orden.
- FK(scope_id,work_order_id) → #8. Índices (type,registered_at,id),
  (work_order_id,registered_at,id), (admission_id,registered_at,id); FKI.
- Pendiente = ausencia de decisión; no índice parcial con subconsulta.
  No type purchase_need ni FK hacia compra inexistente.
  Trigger repair exige scope initial; extension exige extension; rectification que
  revisa alcance exige scope revision. target_version requerido para exit/reopening/
  cancellation y rectification de raíz mutable; repair/extension conservan NULL
  y contenido exacto del scope, sin invalidar aprobación por avances ajenos.

### 12. workshop_authorization_decisions — decisión administrativa

- Columnas: request_id U → #11; decision T = approved/rejected;
  decided_by_id B → users; reason T; registered_at Z.
- UNIQUE(request_id); índice (decision,registered_at,id); FKI.
- Cuenta/rol administrator vigentes; decisión inmutable, rechazo nunca UPDATE a aprobado.

### 13. workshop_operations — operación, auditoría y recibo

- Columnas: work_order_id U? → #3; admission_id U? → #2; report_id U? → #1;
  fleet_asset_id U? → fleet_assets; operation_type T; before_values J?;
  after_values J; from_version B?; to_version B?; H; request_key U;
  request_fingerprint T; idempotency_scope T; confirmed_result J.
- FK de destino explícitas, todas U? y RESTRICT: report_link_id → #4;
  mechanic_assignment_id → #5; mechanic_release_id → #6; diagnosis_id → #7;
  scope_id → #8; authorization_request_id → #11; authorization_decision_id → #12;
  activity_id → #15; time_event_id → #16; delivery_id → #17; usage_id → #18;
  external_repair_id → #19; attachment_id → #20; cost_record_id → #21;
  impediment_id → #22; warning_id → #25; material_need_id → #26;
  order_admission_link_id → #27; reservation_operation_id → #13.
- Justificación: fleet_asset_id/impediment_id enlazan observación/resolución sin orden
  ficticia (F02); reservation_operation_id expresa relación técnica reserva-terminal
  (F03). Los otros destinos concretan las FK de hechos sensibles previstas en 6.2;
  no entity_type/entity_id ni referencias huérfanas escondidas en JSON.
- CHECK exactamente una raíz entre orden/ingreso/reporte/activo. Los destinos extra
  no son raíces alternativas: cada operación permite solo los de su fila de la matriz
  siguiente y exige correspondencia relacional al mismo ámbito.
- CHECK from/to ambos NULL o positivos, to=from+1. Solo raíces mutables orden/ingreso
  tienen from/to; creación usa version=1 y operación de creación sin incremento;
  revisión append-only de reporte no inventa version mutable del reporte.
- UNIQUE(actor_id,idempotency_scope,request_key); huella SHA-256 hexadecimal de 64
  caracteres. UNIQUE parcial(work_order_id,to_version) y (admission_id,to_version)
  donde to_version no NULL. Auditoría diferida exige 1 cambio por incremento.
- UNIQUE parcial(reservation_operation_id) WHERE operation_type IN
  ('attachment_confirm','attachment_release'): mismo índice para ambos terminales.
- CHECK reservation_operation_id no NULL exactamente para los dos terminales;
  reserva siempre NULL. UNIQUE parcial(report_link_id) para invalidación y
  (order_admission_link_id) para invalidación, sin prohibir operaciones distintas
  sobre el hecho por otros motivos.
- Índices (work_order_id,registered_at,id), (admission_id,registered_at,id),
  (fleet_asset_id,registered_at,id), (report_id,registered_at,id); FKI para destinos.

Tipos y destinos permitidos (CHECK enumeración + triggers de ámbito y autorización):

| operation_type | Raíz | Destinos exigidos, además de raíz; los no utilizados son NULL |
| --- | --- | --- |
| report_create/report_revise | reporte | Revisión mediante previous_report_id del reporte destino |
| order_create/order_update/order_finish/order_close/order_cancel/order_reopen | orden | Cambio/versiones; cancel/reopen requieren aplicación #14; close conserva revisión administrativa |
| admission_create | ingreso | impediment_id; avisos #25 y links #27 se vinculan por ingreso/operación correspondientes |
| admission_exit/admission_rectify | ingreso | Aplicación #14; resoluciones #23 y salida #24 por sus FK; rectify conserva contenido autorizado |
| report_link_create/report_link_invalidate | orden | report_link_id |
| order_admission_link_create/order_admission_link_invalidate | orden | order_admission_link_id |
| mechanic_assign/mechanic_release | orden | mechanic_assignment_id; además mechanic_release_id en release |
| diagnosis_record/diagnosis_revise | orden | diagnosis_id |
| scope_request | orden | scope_id, authorization_request_id |
| authorization_request | orden o ingreso | authorization_request_id; scope_id solo si solicitud corresponde a alcance |
| authorization_decide | orden o ingreso | authorization_request_id, authorization_decision_id |
| scope_apply | orden | scope_id; aplicación #14 referencia la operación |
| activity_record/activity_revise | orden | activity_id |
| time_record/time_revise | orden | time_event_id |
| delivery_record/delivery_revise | orden | delivery_id |
| usage_record/usage_revise | orden | usage_id |
| external_record/external_revise | orden | external_repair_id |
| cost_record/cost_revise/cost_classify | orden | cost_record_id |
| material_need_record/material_need_revise | orden | material_need_id |
| impediment_record/impediment_resolve | activo | impediment_id |
| historical_observe/historical_resolve | activo | impediment_id; captura/resolución administrativa observada |
| warning_note | ingreso | warning_id |
| attachment_reserve | orden | Ningún destino adicional; metadata de carga en snapshot técnico |
| attachment_confirm | orden | reservation_operation_id, attachment_id |
| attachment_release | orden | reservation_operation_id; attachment_id NULL |

Los tipos son nombres físicos de acciones aprobadas, no procesos nuevos. Un comando
puede crear varias operaciones con claves/ámbitos derivados determinísticamente
del comando padre; su unidad ACID y recibo de respuesta tienen una operación principal.
No generar una clave diferente al reintentar. Operación principal conserva IDs de las
operaciones hijas en su resultado; cada hija tiene FK real al hecho que documenta.

### 14. workshop_authorization_applications — aplicación única

- Columnas: decision_id U → #12; operation_id U → #13;
  applied_by_id B → users; registered_at Z.
- UNIQUE(decision_id); índice (operation_id); FKI.
- Trigger exige approved, tipo compatible, contenido exacto y ámbito coincidente.
  Una operación puede aplicar varias decisiones distintas explícitamente necesarias;
  no volver a aplicar una decisión por cada actividad/entrega parcial.

### 15. workshop_activities — ejecución técnica

- Columnas: task_id U → #9; mechanic_assignment_id U? → #5;
  description T; observations T?; H; R(previous_activity_id).
- Índice (task_id,occurred_at,id); R y FKI.
- Scope aplicado y participante de esa orden; NULL solo administrador con actor propio.
  Revisar texto técnico no modifica una línea autorizada ni habilita otra reparación.

### 16. workshop_time_events — secuencia por participante

- Columnas: work_order_id U → #3; mechanic_assignment_id U → #5;
  event_type T = start/pause/resume/finish; H; R(previous_event_id).
- FK(mechanic_assignment_id,work_order_id) → #5;
  índice (mechanic_assignment_id,occurred_at,id); R y FKI.
- Secuencia efectiva start→pause/finish, pause→resume, resume→pause/finish;
  finish permite nuevo start. Orden temporal no negativo validado bajo lock de participante.
  No desempatar hechos contradictorios con UUID/hora cliente: rechazar secuencia ambigua.
  Intervalos abiertos provisionales; fin de orden no inserta finish ficticio.

### 17. workshop_part_deliveries — entrega física, no stock

- Columnas: scope_part_id U → #10; quantity Q; delivered_to_assignment_id U → #5;
  recorded_by_id B → users; occurred_at Z; registered_at Z; reason T;
  R(previous_delivery_id).
- Índices (scope_part_id,occurred_at,id), (delivered_to_assignment_id,occurred_at,id);
  R y FKI. Participante y línea de misma orden, habilitación de alcance vigente.
- Suma efectiva por identidad de línea autorizada <= techo vigente; funcionario
  secretary/administrator activo. Revisiones sensibles necesitan autorización.

### 18. workshop_part_usages — uso comunicado

- Columnas: delivery_id U → #17; quantity Q; confirmed_by_id B → users;
  mechanic_assignment_id U → #5; occurred_at Z; registered_at Z; reason T;
  R(previous_usage_id).
- Índices (delivery_id,occurred_at,id), (mechanic_assignment_id,occurred_at,id);
  R y FKI. Mismo ámbito; entrega anterior al uso; actor competente vigente.
- Uso conserva delivery_id histórico; agregado por raíz de entrega y hojas efectivas
  de uso, <= entrega efectiva y autorización. No mover FK ni descontar stock.

### 19. workshop_external_repairs — hecho externo autorizado

- Columnas: work_order_id U → #3; scope_task_id U → #9; supplier_id U → suppliers;
  description T; performed_at Z; recorded_by_id B → users; registered_at Z;
  reason T; R(previous_record_id).
- UNIQUE(id,work_order_id); índice (work_order_id,performed_at,id); R y FKI.
- Administrador registra; tarea de esa orden y proveedor exacto del scope aplicado.
  No amount duplicado aquí; costo #21 y factura #20 opcionales, sin compra/pago.

### 20. workshop_attachments — únicamente archivo confirmado

- Columnas: work_order_id U → #3; category T = photo/document/invoice;
  external_repair_id U? → #19; storage_key T; mime T; extension T;
  size_bytes B; checksum T; uploaded_by_id B → users; registered_at Z;
  R(previous_attachment_id); reservation_operation_id U → #13.
- UNIQUE(storage_key); UNIQUE(reservation_operation_id); UNIQUE(id,work_order_id);
  FK(external_repair_id,work_order_id) → #19.
- CHECK size_bytes entre 1 y 10.000.000; extension pdf/jpg/jpeg/png; mime
  application/pdf o image/jpeg o image/png con par extensión coherente;
  checksum SHA-256 hexadecimal de 64 caracteres. Categoría no prueba pago.
- Índice (work_order_id,registered_at,id); R y FKI. Revisión conserva original y
  también cuenta en cuota. MIME/estructura/hash/privacidad requieren almacenamiento,
  no basta CHECK sobre metadata declarada. Trigger diferido confirma protocolo #13.

### 21. workshop_cost_records — estimado/real, cobertura y clasificación

- Columnas: work_order_id U → #3; scope_task_id U? → #9; scope_part_id U? → #10;
  usage_id U? → #18; external_repair_id U? → #19; cost_kind T? = labor/part/external;
  basis T = estimated/actual; amount C?; currency CHAR(3) NULL;
  source_attachment_id U? → #20; covered_by_cost_id U? → #21;
  description T; H; R(previous_cost_id).
- UNIQUE(id,work_order_id); FK(source_attachment_id,work_order_id) → #20;
  FK(external_repair_id,work_order_id) → #19;
  FK(covered_by_cost_id,work_order_id) → #21; cobertura no apunta a sí mismo.
- CHECK amount presente exige currency presente; currency si presente tres letras
  ASCII mayúsculas. Moneda original comunicada, sin conversión/ISO catálogo inventado.
- CHECK clasificado: labor exige solo scope_task_id como origen; part exige exactamente
  uno de scope_part_id/usage_id y scope_task_id/external_repair_id NULL;
  external exige scope_part_id/usage_id NULL y exactamente uno de scope_task_id/
  external_repair_id (estimación externa puede preceder hecho realizado). Trigger
  valida tarea externa con proveedor en el alcance cuando ese es el origen external.
- CHECK pendiente: cost_kind NULL exige source_attachment_id y descripción de
  incertidumbre; orígenes funcionales y covered_by_cost_id NULL hasta clasificación.
  Puede conservar importe conocido o NULL; no sumarlo aunque basis sea actual.
- Índice (work_order_id,basis,currency,registered_at,id); R y FKI.
- Cobertura acíclica: misma orden/basis/moneda, referencias efectivas. Fuente documental
  NO UNIQUE: una factura puede justificar varias líneas. Protección de origen efectivo
  bajo lock de orden identifica el mismo costo comunicado/cubierto y exige revisión
  de su raíz en vez de duplicarlo; no inferir duplicación por compartir factura/tarea.
  Para un origen cuyo desglose no permita distinguir costos, conservarlo pendiente
  hasta clasificación, no inventar discriminadores ni prohibir costos legítimos.
- Totales: solo hojas clasificadas con importe conocido y sin cobertura por otro
  costo efectivo; agrupar basis y currency. Detalle cubierto informa composición.
  Un global external documentado puede cubrir labor/part. Al revisar global/cobertura,
  actualizar mediante revisiones de las referencias dependientes en unidad atómica.
  Clasificar pendiente es revisión sensible autorizada, original intacto.

### 22. fleet_operating_impediments — propiedad FleetAndDocuments

- Columnas: fleet_asset_id U → fleet_assets; kind T = workshop/reviewed_other_cause/
  historical_observation; admission_id U? → #2; work_order_id U? → #3;
  effect T = maintenance/out_of_service; description T; H.
- FK(admission_id,fleet_asset_id) → #2; FK(work_order_id,fleet_asset_id) → #3;
  índice (fleet_asset_id,effect,registered_at,id); FKI.
- CHECK workshop exige ingreso u orden; reviewed_other_cause/historical_observation
  no inventan ingreso/orden: ambos NULL. historical_observation es discriminador de
  procedencia F02, no categoría de avería; occurred_at es momento de observación.
- Causa canónica del ingreso: kind=workshop, admission_id presente, work_order_id
  NULL y effect=maintenance. UNIQUE parcial(admission_id) para esa forma, y trigger
  diferido exige exactamente una por ingreso. Causas de reparación de órdenes son
  independientes, incluso si también referencian ingreso; no se resuelven por salir.
- Ingreso genera causa maintenance, con admission_id. Causas adicionales de avería
  se identifican individualmente; no inferir severidad out_of_service de texto libre.
  No ofrecer altas genéricas no-Taller fuera de la política de Flota aprobada.

### 23. fleet_impediment_resolutions — resolución individual

- Columnas: impediment_id U → #22; operation_id U → #13; resolved_by_id B → users;
  resolved_at Z; registered_at Z; reason T; evidence J.
- UNIQUE(impediment_id); índices (operation_id), (resolved_at,id); FKI.
- Administrador actual; resolución <=1, append-only. Referencia a causa y operación
  exactas; resolución del ingreso por salida y revisión autorizadas en mismo commit.
  Resolver observación histórica no resuelve causas Taller independientes.

### 24. workshop_admission_exits — salida independiente

- Columnas: admission_id U → #2; authorization_application_id U → #14;
  exited_at Z; recorded_by_id B → users; registered_at Z; reason T; reviewed_content J.
- UNIQUE(admission_id); UNIQUE(authorization_application_id); índice (exited_at,id); FKI.
- Trigger exited_at >= admitted_at efectivo reconstruido; actor administrator; aplicación tipo exit de
  mismo ingreso. Restricción diferida exige resolución de causa ingreso/proyección
  y preserva causas independientes. No requiere todas las órdenes cerradas.

### 25. workshop_warnings — captura de coordinación

- Columnas: admission_id U → #2; trip_asset_assignment_id U? → trip_asset_assignments;
  work_order_id U? → #3; code T; context J; captured_by_id B → users; registered_at Z.
- UNIQUE parcial(admission_id,trip_asset_assignment_id,code) cuando assignment no NULL.
  Sin unicidad artificial para avisos sin asignación.
- Índice (admission_id,registered_at,id); FKI. code inicial assigned_trip para
  asignación capturada; otros códigos se revisan al integrar una advertencia concreta,
  no un nuevo estado/acción. Trigger mismo activo y asignación real current al capturar.
- Nota posterior #13 warning_note referencia warning_id; no editar captura ni liberar trip.

### 26. workshop_material_needs — necesidad comunicada

- Columnas: work_order_id U → #3; scope_part_id U? → #10;
  spare_part_id U → spare_parts; quantity Q; description T;
  requested_by_id B → users; occurred_at Z; registered_at Z; reason T;
  R(previous_need_id).
- UNIQUE(id,work_order_id); índice (work_order_id,registered_at,id); R y FKI.
- Línea opcional de misma orden/repuesto. No convertir diferencia de cantidades en
  compra urgente; no FK/estado compra, saldo ni movimiento inexistente.

### 27. workshop_order_admission_links — asociación histórica

- Columnas: work_order_id U → #3; admission_id U → #2; fleet_asset_id U → fleet_assets;
  linked_by_id B → users; registered_at Z; reason T; operation_id U → #13.
- UNIQUE(work_order_id,admission_id); FK compuestas a orden/activo e ingreso/activo;
  índices (admission_id,registered_at,id), (fleet_asset_id,registered_at,id); FKI.
- Operación tipo order_admission_link_create de esa orden. Invalidación explícita #13 conserva enlace;
  no UPDATE admission_id en orden. Reapertura puede añadir otro enlace al mismo activo.

## Transacciones, estados y coordinación

### Protocolo común de escritura

1. Sesión autenticada; actor del servidor y campos permitidos. Buscar recibo por
   actor+ámbito+clave y reautorizar consulta. Comparar huella antes de comprobar una
   versión que la propia operación pudo incrementar: duplicado confirmado devuelve
   IDs/resultado originales, no falso conflicto de versión.
2. Para archivos, obtener antes el flock privado estable de la reserva. Para otras
   escrituras, no tomar un lock de almacenamiento innecesario.
3. Abrir transacción READ COMMITTED; adquirir 873210 antes de cualquier lock de fila;
   actualizar transport_assignment_guard conforme al coordinador existente.
   BEFORE STATEMENT cubre escrituras SQL autorizadas en raíces/hechos/proyección.
4. Revalidar cuenta/roles/empleado/competencia vigentes y sus locks. Si se necesitan
   viajes, mantener trips→fleet_assets. Taller no bloquea trips para capturar avisos.
   Luego raíces orden/ingreso, participantes/reservas y líneas en UUID estable.
5. Releer hechos efectivos y recibo; verificar versiones, alcance aplicado y cuotas.
   Insertar operación/recibo y hechos, actualizaciones y aplicaciones de la unidad.
6. Forzar las restricciones diferidas relevantes antes de éxito, y confirmar commit
   real (sin transacción exterior desconocida). Confirmed_result solo durable con commit.

La raíz común existente serializa también revocaciones y puede limitar throughput;
no dividir el coordinador antes de medir y aprobar un reemplazo completo. Funciones
que escriben otras tablas respetan el mismo orden; no tomar activo y luego trip.
SQL verifica integridad/rol del actor declarado, no autentica conexiones arbitrarias.
Credenciales privilegiadas no están dentro de la garantía de escritor aplicativo.

| Unidad ACID | Efectos confirmados juntos / locks específicos |
| --- | --- |
| Crear ingreso | #2+#22+#13+capturas #25, proyección; activo bloqueado; asignaciones conservadas |
| Vínculo histórico | #27+#13, mismo activo; no reemplazar vínculo anterior |
| Participante | #5 o #6 + operación; orden/pareja; sin fin ficticio de tiempos |
| Solicitar alcance | #8–10+#11+#13; diagnóstico y líneas exactas; orden/scope |
| Decidir/aplicar | #12 u #14+#13 y cambio específico; solicitud/decisión/raíz; UNIQUE competidores |
| Actividad/tiempo | Hecho/revisión+#13; orden/participante, secuencia efectiva reconstruida |
| Entrega/uso/rectificación | #17/#18+#13+#14 si sensible; orden, raíz scope, línea lógica y raíz entrega; agregados efectivos |
| Terminar/cerrar/cancelar/reabrir | Raíz status/version+#13; #14 cuando requerido; no salida/resolución implícita |
| Salida | #24+#23+#14+#13+version ingreso+proyección; activo/ingreso/causas, revisión actual |
| Observación/resolución histórica | #22 o #23+#13 y proyección; activo, responsable administrador |
| Costo/clasificación | #21+#13+#14 cuando sensible; orden y cadenas cobertura/revisión |
| Reserva | #13 attachment_reserve y recibo; orden bajo cuota; sin fila #20 |
| Confirmación | #20+#13 attachment_confirm y recibo en mismo commit; orden/reserva |
| Liberación | #13 attachment_release y recibo; orden/reserva; limpieza externa posterior |

Un CHECK no comprueba agregados ni otras tablas. Triggers de restricción diferidos
validan ingreso abierto, correlación versión/operación, proyección, terminal/adjunto,
ámbitos y cuotas con coordinación. Triggers de sentencia obtienen el coordinador
antes de filas; triggers de fila solos no sustituyen esa protección. Pruebas incluirán
sentencias multirregistro y snapshots previos REPEATABLE READ, sin afirmar validación actual.

### Alcance exacto y transiciones

Contenido canónico de repair/extension: orden, scope/revisión, diagnóstico, números
de línea, tareas/proveedor, repuestos/unidades/cantidades; se reconstruye en servidor.
scope inmutable es el objeto aprobado: cambios ajenos de participante o avance no
invalidan su contenido. target_version no se usa como candado global artificial en
estos dos tipos; NULL, con IDs/revisión exactos en content y validación SQL relacional.
rectification/reopening/cancellation/exit conservan target_version de raíz afectada,
datos originales y efecto autorizado; salida incluye causas/resoluciones propuestas,
órdenes revisadas y versiones actuales. Una causa nueva obliga a revalidar contenido,
no se elimina para aprovechar aprobación anterior. El hash nunca sustituye contenido.

| Transición | Ejecutor y condición |
| --- | --- |
| creación→pending_diagnosis | Oficina, reparación única, actor registrado |
| pending_diagnosis→pending_authorization | Diagnóstico y solicitud inicial completos |
| pending_authorization→authorized | Aprobación inicial aplicada exactamente una vez |
| authorized→in_repair | Inicio autorizado con participante/actividad de ese alcance |
| in_repair→finished | Terminación técnica por competente; no fin ficticio de participantes |
| finished→closed | Administrador revisa trabajos/repuestos/tiempos/pendientes; operación auditable, sin segunda aprobación |
| estado activo→cancelled | Solicitud oficina, aprobación/aplicación administrador y resolución documentada de hechos |
| finished/closed/cancelled→destino activo | Reapertura explícita aprobada: pending_diagnosis si falta diagnóstico, pending_authorization si falta alcance habilitado, authorized si hay alcance y no comenzó nuevo ciclo, in_repair solo con alcance y inicio/reanudación confirmados |

No terminal→terminal como reapertura. Ampliación pendiente/rechazada deja continuar
solo líneas ya habilitadas y no vuelve in_repair a pending_authorization. Revisiones
de scope no doble habilitan una línea ni borran decisiones previas. Cancelar, cerrar,
terminar o reabrir no devuelve repuestos ni resuelve impedimentos. Intervalos abiertos
siguen provisionales; no se incorpora requisito nuevo que prohíba cierre por ellos.

### Conciliación histórica y proyección

Procedimiento futuro autorizado, no ejecutado:

1. Antes de activar proyección, impedir escrituras intermedias y capturar bajo
   coordinador/guard/activo los estados previos y causas existentes.
2. Si maintenance/out_of_service no está explicado o la proyección rebajaría su
   efecto, registrar #22 historical_observation y #13 historical_observe con efecto
   observado. Fecha/actor son de observación real, no de avería pasada.
3. Validar que ningún activo queda disponible o con bloqueo menos severo por perder
   historia desconocida; detener integración si no se puede preservar significado.
4. Proyección protegida: out_of_service si alguna causa efectiva tiene ese efecto;
   maintenance si no hay out_of_service y sí maintenance; available solo sin causas.
5. Administrador revisa y resuelve cada observación mediante #23/#13 con razón y
   evidencia. No resolverla automáticamente por salida ni por cerrar/cancelar orden.

No hay lectura de datos reales en fase 0. Ocupación de Transporte permanece aparte;
available no equivale a sin asignaciones. El estado observado no sirve para calcular
duración histórica anterior desconocida. No incorporar alta genérica de otras causas.

## Decimal exacto y privilegios PostgreSQL

Entrada de cantidades/importes: texto ASCII con dígitos, signo opcional y punto
decimal opcional con 1..6 cifras; sin exponente, separadores, moneda ni coma.
Rechazar escala >6 incluso si sus cifras adicionales son ceros. Canonicalización
posterior elimina signo positivo y ceros redundantes manteniendo el valor exacto;
no convertir mediante float, ni JS Number para comunicar decimales.

Función de escritura: primero formato/longitud técnica, después NUMERIC sin typmod
para comparar finitud/rango, finalmente cast a Q/C aprobado; NULL permitido solo
para importe desconocido. Es imposible recuperar escala original desde una columna
ya redondeada: BEFORE ROW sobre NUMERIC(p,s) no resuelve este requisito.

Rol de ejecución aplicativo separado del propietario/despliegue: sin INSERT/UPDATE
directos a #10/#17/#18/#21/#26 y sin capacidad de alterar/inhabilitar triggers,
TRUNCATE ni CREATE en esquemas confiables. Acceso de escritura por funciones
específicas, no función SQL genérica. SECURITY DEFINER con propietario no-login de
privilegios mínimos, nombres cualificados, search_path fijo a esquemas confiables
(pg_temp sin precedencia), sin SQL dinámico, EXECUTE revocado a PUBLIC y concedido
selectivamente. FK/triggers/versiones/roles se siguen comprobando dentro de funciones.
No cambiar usuarios/credenciales del proyecto en esta entrega; ese aprovisionamiento
pertenece al futuro plan de migración autorizado.

## Permisos por operación y recurso

Todas las filas exigen cuenta activa y rol vigente. Mecánico escritor vinculado a
orden también empleado activo y participación vigente; no asignación por user_id libre.
El reporte permitido por RN-TAL-02 no exige que ya exista orden ni participación.
Administrador conserva actor propio y autorización explícita cuando requerida.

| Operación | Administrator | Secretary | Mechanic | Driver |
| --- | --- | --- | --- | --- |
| Consulta operativa/pendientes/impedimentos | Todos | Todos para coordinación | Órdenes con participación actual o histórica | Sin API Taller; comunica en Transporte |
| Reporte | Sí | Sí | Sí, reporte no crea orden | Evento de Transporte, oficina formaliza |
| Orden/ingreso/vínculo/participante | Sí | Sí | No | No |
| Diagnóstico/actividad/tiempo/terminación | Sí con actor real | No | Vigente y competente | No |
| Solicitud reparación/ampliación/repuestos/necesidad | Sí | Coordina sin aprobar ni inventar diagnóstico | Vigente de su orden | No |
| Aprobar/rechazar/aplicar alcance | Sí, contenido exacto | No | No | No |
| Solicitar cancelación | Sí | Sí | No | No |
| Confirmar cancelación/cierre/reapertura | Sí según solicitud/aplicación; cierre ordinario sin segunda solicitud | No | No | No |
| Rectificar técnica activa | Sí | No | Competente vigente y no sensible | No |
| Autorizar rectificación sensible | Sí | No | No | No |
| Aplicar rectificación sensible | Sí; ejecutor competente explícito de aprobación puede actuar dentro de su función | Sin autonomía técnica/económica sensible | Solo hecho de su competencia, vigente y expresamente autorizado | No |
| Entrega física | Sí | Sí | No | No |
| Uso comunicado | Sí | No | Vigente de esa orden | No |
| Realización externa técnica | Sí | No | No registro oficial externo autónomo | No |
| Evidencia: cargar/consultar/descargar | Todas | Evidencia de coordinación de todas las órdenes | Evidencias de sus órdenes, lectura histórica; carga técnica vigente | No |
| Costo documentado | Registra/consulta todos | Registra documentados; consulta por orden/activo para coordinación | Sin importes/reportes financieros generales; repuestos/tiempos propios | No |
| Clasificar/rectificar costo confirmado | Revisión sensible autorizada | No autónomamente | No | No |
| Salida: solicitar/decidir/aplicar | Sí exclusivamente | No | No | No |
| Observar/resolver histórico desconocido | Sí | Consulta | Consulta vinculada | No |
| Conciliar carga huérfana/liberar reserva | Administrador con actor auténtico; dueño autorizado puede desistir su carga sin tocar confirmados | Solo desistir carga propia aún no confirmada | Solo desistir carga propia y ámbito vigente | No |
| Consulta de recibo | Propio o recurso autorizado según operación | Propio y recurso permitido | Propio y recurso permitido, revalidación vigente | No |

La recuperación técnica desatendida no recibe una identidad ficticia: primera versión
conciliación administrativa explícita; automatizar un actor de servicio requiere
contrato de ejecución/auditoría separado. No conceder manage-fleet a secretaria
para simular sus funciones Taller. Policies por recurso aplican esta matriz y
revalidan en commit; Gates son nombres de implementación futura, no código instalado.
Descargas filtran evidencia económica para mecánico conforme T06; lectura histórica
no concede consulta de otras órdenes ni escritura después de liberar participación.

## Archivos, reservas e idempotencia

### Identidad, cuota y constraints

Al reservar, generar id de operación #13 estable. Se deriva storage_key privado
de ese UUID y extensión validada; no rutas/nombre del cliente. Recibo de reserva
contiene categoría, formato, tamaño/hash esperados y orden. Firma/hash del archivo
se verifica al transferir, sin SQL transaction abierta. Reserva, confirmación y
liberación usan ámbitos de operación distintos con claves estables de la carga;
no reutilizar misma combinación ámbito/clave con otro contenido.

Constraints SQL requeridas:

- CHECK de forma reserva/terminal; FK terminal→#13 reserva real y mismo work_order.
- UNIQUE parcial compartido para confirm/release: máximo un terminal, aun con
  usuarios/claves diferentes o SQL directo.
- #20 UNIQUE reserva; trigger diferido en #13/#20: confirmación exige exactamente
  un adjunto cuyo attachment_id/reservation_operation_id/orden coincidan; liberación
  exige ninguno; todo adjunto exige terminal confirm. No reserva liberada con adjunto.
- Lock orden serializa count(#20) + count(reservas sin terminal) frente al límite
  inicial configurable 20. Confirmar reemplaza reserva por adjunto en mismo commit;
  revisiones #20 siguen contando. Liberación no resta adjuntos históricos.
- SQL puede proteger relación/cuota, no existencia de bytes: solo escritor confiable
  de almacenamiento confirma después de comprobarlos. Restricción de credenciales
  y validación de formato real son parte del contrato de persistencia.

### Secuencia y orden de locks

Host único, disco propio Taller sin acceso público, permisos de filesystem mínimos.
Namespace separado de fleet_documents y de su reconciliador Document. PDF/JPG/JPEG/PNG,
1..10.000.000 bytes, coherencia MIME/estructura/hash. No sobrescritura ni conversión
automática. Descarga autorizada, attachment, private/no-store, nosniff; sin storage_key
ni URL pública en representaciones.

1. Obtener flock exclusivo en archivo de coordinación estable por UUID de reserva;
   nunca unlink de ese lockfile (evitar dos inodos/coordinadores). Orden uniforme:
   flock→873210→guard→actor/filas. Un timeout de flock usa presupuesto acotado.
2. Transacción corta de reserva: permiso/orden/cuota/recibo. Commit reserva, sin #20.
   Si respuesta se pierde, consultar recibo antes de crear otra reserva.
   Solicitudes repetidas pueden haber generado un UUID candidato distinto antes
   de recuperar el recibo ganador. Si el recibo devuelve otro UUID de reserva,
   soltar flock candidato, adquirir flock del UUID canónico y releer permisos,
   recibo/terminal antes de transferir. Nunca escribir bytes bajo lock de un UUID
   que no sea el de la reserva durable; no mantener ambos flocks simultáneamente.
3. Transferir/verificar fuera de transacción a clave inmutable privada. Mantener flock.
   Staging es ubicación privada final si confirma; no necesitar promoción tras commit.
   Verificar escritura completa/durable antes de marcar disponible, incluyendo cierre
   de archivo y estrategia de persistencia de disco a validar ante caída del host.
4. Transacción corta: revalidar permiso, recibo, reserva sin terminal y hash/tamaño real;
   insertar #20+terminal confirm+recibo, comprobar constraints y commit real.
5. Soltar flock solo después de commit real o de clasificar resultado como incierto.
   No aceptar transacción exterior que posponga commit detrás de la liberación del lock.

### Fallos y recuperación concurrente

| Ventana de fallo | Tratamiento |
| --- | --- |
| Antes de commit de reserva | Sin reserva durable; consultar recibo para distinguir rollback/resultado incierto |
| Reserva confirmada sin bytes o transferencia parcial | Conciliador espera flock; bajo transacción prueba no adjunto/terminal, confirma release; limpieza posterior repetible |
| Bytes completos antes de confirmación | Cargador puede recuperar confirmación con misma identidad y revalidación; conciliador compite por el mismo flock, nunca ambos propietarios |
| Confirmación contra liberación | Mismo lock reserva/orden y UNIQUE terminal; uno confirma, el otro recupera resultado o conflicto técnico sin segundo terminal |
| Commit incierto/respuesta perdida | Conservar bytes/plaza, consultar recibo/terminal; no generar nueva clave ni borrar |
| Confirmado, pero bytes ausentes/corruptos | Conservar #20/confirm/recibo y cuota; incidente técnico, restaurar bytes de copia verificada, no presentar descarga disponible |
| Release confirmado pero eliminación falla | Liberación ya durable; limpieza posterior bajo flock, no borrar ninguna referencia confirmada |
| Base inaccesible/recibo incoherente | Conservar y diferir; ausencia de lectura no demuestra ausencia de commit |
| Proceso viejo retoma ejecución | Debe adquirir mismo flock y comprobar reserva abierta antes de escribir/confirmar; terminal release le impide continuar |
| Rol revocado durante transferencia | Confirmación rechazada; conservar información para conciliación administrativa y liberar solo con pruebas seguras |

No expirar reservas por tiempo solo. Tras crash flock se libera; reconciliador puede
adquirirlo y verificar estado. No trasladar esta garantía local a multinodo/objetos:
esa evolución exige coordinación distribuida y fencing aprobados.
Desistir de una carga propia exige actor de reserva, permiso actual de cargar en
esa orden, reserva sin terminal/adjunto y el mismo protocolo de exclusión/liberación.
No es permiso para rectificar o borrar evidencia confirmada.

### Recibo y reintentos configurables

Identidad: actor autenticado + tipo de comando + raíz estable. Crear ingreso usa
activo; crear orden usa tipo de comando sin UUID nuevo dentro del ámbito. Huella
SHA-256 de contenido canónico incluye IDs/versiones/decimales/unidades, excluye clave
y metadatos HTTP. No reinterpretar el mismo payload conforme un catálogo ya cambiado:
comparar datos solicitados/snapshots originales y revalidar solo si aún no confirmado.

UNIQUE de recibo arbitra solicitudes concurrentes: si ganador confirma, devolver
resultado original con autorización actual; si revierte, competidor puede ejecutar
después de revalidar. Repetición con huella distinta se rechaza. Recibos/huellas no
expiran; consulta autorizada, sin datos privados tras revocación. Nunca responder
éxito por una fila no confirmada ni filtrar SQL, rutas privadas o secretos en errores.

| Parámetro de configuración futura | Valor inicial aprobado |
| --- | --- |
| max_attempts | 3, incluido inicial |
| transaction_budget_ms | 10000, medido por reloj monotónico |
| lock_timeout_ms | 2000, local a transacción y limitado por tiempo restante |
| retry_wait_1_ms | aleatoria 100..300 |
| retry_wait_2_ms | aleatoria 300..900 |
| attachment_limit | 20, no reducir silenciosamente por debajo de confirmados/reservas |

Validar parámetros positivos/coherentes. Aplicación no inicia intento/espero que
exceda presupuesto; statement_timeout local acotado por tiempo restante cubre SQL
activo, además de lock_timeout. Presupuesto SQL excluye transferencia de bytes.
Timeout de sentencia/cancelación no se añade automáticamente a errores reintentables.
Retry solo 40001/40P01/55P03, tras rollback completo confirmado, comando idempotente
y ausencia de efecto externo irrepetible dentro de la transacción. 23505 de recibo
es recuperación de duplicado, no retry ciego; 23503/23514/validación/403/conflicto
funcional no retry. Rollback incierto exige consulta, no repetición automática.
Tras presupuesto, devolver resultado temporal/pendiente recuperable con misma clave;
no afirmar fracaso definitivo cuando commit es desconocido. Transferencia no se
repite como parte del retry SQL. Los valores necesitan medición, no ajuste automático.

## Respaldo, restauración y conciliación PostgreSQL/archivos

Diseño operativo compatible con F04; no ejecución ni aprobación de destino productivo.
RPO, RTO, frecuencia, retención, responsables y custodia de claves deben aprobarse
antes de operar producción. No se deducen de tres intentos ni de la cuota de archivos.

### Respaldo coherente de primera versión

1. Activar barrera de mantenimiento antes de entrar a locks de cargas/SQL: impedir
   nuevas escrituras de todos los módulos, cargadores y reconciliadores; esperar las
   operaciones activas. No tomar 873210 y después esperar flock (inversión de orden).
   Incluye tareas en segundo plano y escritores SQL administrativos autorizados,
   no solo HTTP. Si no se puede detener algún escritor, abortar la captura como
   respaldo coherente hasta disponer de una barrera efectiva.
2. Capturar PostgreSQL y almacenamiento privado de Taller/Flota bajo esa pausa;
   respaldar metadata/recibos confirmados y reservas aún abiertas, sin copiar procesos
   ni asumir que lockfiles representan un propietario restaurable.
3. Manifiesto común con ID conjunto, instante de captura, versión PG, referencias,
   tamaños y hashes de archivos confirmados y staging/reservas. No secretos en manifiesto.
4. Verificar cada #20 y documento Fleet contra bytes/hash cuando disponible; registrar
   faltantes como incidente, no declarar conjunto íntegro ni borrar sus registros.
5. Cifrar conjunto, acceso restringido y copia aislada del servidor; reanudar escrituras
   tras finalizar captura coherente. Un backup completado aún no prueba restaurabilidad.

### Restauración ensayada

1. Autorización explícita y comprobación de destino aislado; para sobrescribir datos
   de cualquier entorno se documenta impacto/recuperación previa.
2. Restaurar PostgreSQL y archivos del mismo conjunto/manifiesto, manteniendo pausa.
   No combinar DB reciente y archivos antiguos sin conciliación demostrada.
3. Verificar FK/restricciones, integridad de historia/recibos y todos los confirmados
   por tamaño/SHA-256; reservar cualquier discrepancia para recuperación, no borrar #20.
4. Procesos anteriores no existen en destino; obtener nuevos locks estables,
   conciliar reservas sin terminal y huérfanos con el protocolo anterior. Conservar
   confirmados y no crear terminales duplicados. Restablecer privacidad/permisos.
5. Comprobar proyección/impedimentos y ocupación sin liberar activos ni asignaciones.
   Ejecutar verificaciones sintéticas de recuperación idempotente y descargas privadas.
6. Documentar resultado/tiempos/limitaciones; habilitar escrituras solo cuando se
   acredite conjunto coherente y resolución de incidencias. Restauración productiva
   queda sujeta a autorización separada, no implícita por aprobar fase 0.

## Orden de migraciones futuras y riesgos de aplicación

Migraciones nuevas exclusivamente; no alterar las publicadas. Separar creación
física de tablas de habilitación funcional. No exponer escrituras entre pasos.

| Paso | Dependencias y contenido |
| --- | --- |
| M0 | Revisar esquema/destino desplegado y compatibilidad versiones, privilegios, guard/coordinador; diagnóstico autorizado de datos previos, respaldo/recuperación antes de riesgo |
| M1 | #1/#2/#3, #4/#5/#6/#7 y #13 con FK a raíces/existentes; autorreferencias; claves compuestas |
| M2 | #8/#9/#10, #11/#12/#14; construcción exacta/autorización; claves candidatas |
| M3 | #15/#16/#17/#18/#19/#20/#21/#26; #22/#23/#24/#25/#27; orden interno respetando destinos, sin FK a destinos aún ausentes |
| M4 | Completar FK sensibles #13↔hechos/#27/#20/#22 y FK ciclos, UNIQUE terminal/reserva, restricciones diferidas e índices; ningún destino queda JSON sin FK |
| M5 | Funciones de decimal, triggers de inmutabilidad/ámbito/cuota/autorización/versiones, roles y grants; escritores todavía deshabilitados |
| M6 | Integración protegida de proyección/guard/coordinador mediante funciones/triggers nuevos o reemplazo versionado reversible, conservando comportamiento publicado; captura administrativa de estados previos antes de activar proyección |
| M7 | Validar constraints/datos, pruebas aisladas/regresiones y recuperación; habilitación solo en despliegue autorizado posterior |

M3 topología: ejecución15/16, entrega17→uso18, externo19→adjunto20→costo21;
necesidad26; impedimento22→resolución23 y salida24 (depende14), advertencia25,
vínculo27. Referencias #13 a esos destinos se añaden M4, no FK a tabla inexistente.
FK cíclicas DEFERRABLE se prueban en ambas inserciones sin permitir historia huérfana.

Riesgos: ALTER TABLE/validación y reemplazo de triggers pueden bloquear escrituras;
captura/proyección sobre datos previos exige pausa y comprobación por activo.
Tablas nuevas vacías admiten índices ordinarios transaccionales. Si se añaden índices
a tablas existentes grandes, revisar volumen/ventana antes de elegir creación
concurrente (fuera de transacción); no asumir magnitud actual. VALIDATE y recuperación
se diseñan según diagnóstico autorizado, no datos consultados en esta fase.
Rollback destructivo que elimine historia nueva no es recuperación aceptable tras
uso: preferir corrección hacia adelante, preservar tablas/archivos y deshabilitar
escrituras si falla despliegue. Ensayar recuperación antes de aplicación real.

## Plan de pruebas PostgreSQL 18

No se ejecutó ninguna prueba de base ni migración en esta entrega. Entorno futuro:
PostgreSQL 18 aislado, conexión/credenciales exclusivas y datos sintéticos. Revisar
tests/run-postgres.sh y configuración efectiva antes de autorizar su ejecución;
APP_ENV=testing o SQLite no demuestran aislamiento/conformidad. Dos conexiones
independientes con barreras deterministas, no sleeps como única coordinación.

| Grupo | Escenarios y oráculo persistido |
| --- | --- |
| Diccionario/DDL | Exactamente 27 tablas nuevas, PK/FK tipo correcto, nulabilidad, RESTRICT, índices y constraints instaladas; dependencias y ciclos al commit |
| Ámbitos | Evento de otro activo, participante/tarea/línea/adjunto ajenos, link con activo distinto: rechazo y rollback íntegro |
| Historia | UPDATE/DELETE/TRUNCATE rechazados con rol app; raíz/predecesor incorrecto, rama, ciclo, revisión duplicada; hojas efectivas sin doble conteo |
| Versiones | Actualización sin operación, doble incremento, operación sin cambio, falta aplicación y rollback tras constraint diferida |
| Ingreso/asignación | Ambos órdenes de commit: asignación primero conserva/advierte; ingreso primero rechaza nueva incompatible; no liberaciones |
| Disponibilidad | Doble ingreso, salida/causa nueva, out_of_service sobre maintenance, otra causa tras salida, cancelación/cierre sin desbloqueo; RC/RR con snapshot previo |
| Histórico | Estado observado desconocido conserva bloqueo/severidad; no fecha/actor ficticios; resolver observación no resuelve otra causa |
| SQL directo | Sentencia multirregistro, lock BEFORE STATEMENT, guard, inversión/deadlock, proyección manipulada y límites escritor privilegiado explícitos |
| Autorización | Rechazo inmutable, decisión/aplicación duplicadas, scope alterado, ampliación pendiente sin detener anterior, reapertura destino/versión incorrectos |
| Cuotas | Dos entregas superando techo, usos simultáneos, revisión de entrega menor que usado, scope revisado sin reponer cuota, hechos antiguos preservados |
| Decimal | 6 cifras aceptadas; 7 incluso cero rechazadas; NaN/infinito/exponente/coma/límites/signos; costo NULL/0 distintos; escritura directa denegada y función sin elusión |
| Economía | NULL kind fuera total, clasificación auditada, global external+part/labor sin doble conteo, monedas/basis separadas, cobertura cíclica, fuente real duplicada vs líneas legítimas de misma factura |
| Tiempo | Secuencia inválida, pausas y ciclos, revisión reconstruida, participantes paralelos; horas-persona y unión impedimentos diferentes; abierto provisional sin finish ficticio |
| Permisos | Cuatro roles, órdenes ajenas, participación liberada lectura/no escritura, revocación cuenta/rol durante comando/carga, secretaria sin autorizaciones exclusivas ni rectificación sensible |
| Idempotencia | Duplicados iguales/distintos, ganador rollback, respuesta perdida antes/después commit, lookup antes versión actual, resultados privados revocados; mismo ámbito estable |
| Reserva terminal | Confirm/confirm, release/release y confirm/release con distintas claves/actores; máximo un terminal; no #20 tras release, no confirm sin #20; misma transacción y constraint diferida |
| Cuota archivos | 19 confirmados+dos reservas; confirm no doble cuenta; revisiones cuentan; abandono no expira por reloj; límite configurable coherente |
| Almacenamiento | Límites 1/10.000.000/10.000.001 bytes, .jpeg, MIME/estructura falsa, traversal, checksum incorrecto, interrupción/cierre durable, lockfile estable, cargador tardío |
| Recuperación | Cada ventana de fallo de tabla anterior, DB inaccesible, commit invisible, dos conciliadores, release durable antes limpieza, nunca borrar confirmado |
| Reintentos | Inyectar 40001/40P01/55P03 seguros, rollback completo, misma clave, máximo3/10s configurables; funcional/23514/403 sin retry; deadline efectivo SQL |
| Backup/restore | Par sintético coherente/manifiesto, bytes ausentes/corruptos, reservas sin terminal, privacidad, proyección/ocupación preservadas; restauración repetible documentada |
| Regresión | Flota/documentos/transporte existentes, inicio con activo impedido, sustitución conductor sin desbloquear, cisterna D05 intacta; sin stock/compras/pagos ficticios |

Las pruebas PostgreSQL de metadata no acreditan filesystem; usar además disco local
sintético y procesos reales para flock/caídas. PHPUnit/SQL y pruebas de almacenamiento
no acreditan API/navegador futuros. Pint/tipos/build solo cuando haya código autorizado.

## Preparación y límites de validación

Fase 0 completa documentalmente con F01–F07 y sus condiciones. Especifica columnas,
FK, índices, constraints, unidades ACID, permisos y recuperación sin tabla 28 ni
modificación de RN/T01–T09. Principal único editor; revisiones independientes por
analista, arquitecto, integración y auditor siguiendo sus perfiles locales.

Antes de comenzar implementación debe autorizarse crear migraciones y pruebas
aisladas. Antes de aplicarlas a un entorno real: diagnóstico autorizado, privilegios,
conciliación previa y recuperación ensayada. Pendientes operativos: política RPO/RTO/
frecuencia/retención/responsables; medición throughput/timeouts; pruebas efectivas de
triggers/concurrencia, respaldo y durabilidad de bytes. No se presentan como nuevas
decisiones funcionales ni como experimentos ya realizados. Inventario/Compras,
programación automática preventiva y offline mecánico quedan fuera del alcance.

Referencias técnicas consultables: [constraints PG18](https://www.postgresql.org/docs/18/ddl-constraints.html),
[triggers](https://www.postgresql.org/docs/18/sql-createtrigger.html),
[aislamiento](https://www.postgresql.org/docs/18/transaction-iso.html),
[NUMERIC](https://www.postgresql.org/docs/18/datatype-numeric.html),
[funciones y SECURITY DEFINER](https://www.postgresql.org/docs/18/sql-createfunction.html).
Estas referencias no sustituyen las pruebas pendientes del diseño.

### Verificación documental realizada

El principal consolidó y corrigió la revisión del documento real por arquitecto,
analista, integración y auditor. Se verificaron por lectura/script las 27 entradas
con numeración/nombres iguales al catálogo, enlaces locales existentes y conservación
exacta de T01–T09, D-TAL-01–13 y del archivo RN respecto de HEAD. git diff --check
sin incidencias y documento nuevo comprobado también por espacios finales.
No se ejecutaron migraciones, pruebas PostgreSQL, almacenamiento, restauraciones ni
operaciones sobre bases del proyecto. Solo cambian este documento, el enlace de
etapa-6-2.md y la sección complementaria de ARCHITECTURE.md; cambios ajenos preservados.
