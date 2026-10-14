# Reglas de negocio — Transporte y Despacho

**Origen:** decisiones tomadas durante la conversación de levantamiento de requisitos. Este documento consolida acuerdos para que Codex los incorpore a la documentación del repositorio. **No representa código implementado ni reglas previamente existentes en GitHub.**

**Estados:** Confirmada = decisión explícita del usuario; Pendiente = requiere decisión; Derivación técnica = propuesta de diseño, no decisión explícita.

## Seguridad transversal

**RN-SEG-01 — Confirmada.** El administrador es el rol de máxima autoridad y dispone de acceso a todos los módulos y operaciones. La secretaria es el segundo rol con acceso más amplio; puede ejecutar las operaciones expresamente habilitadas, pero no autorizar acciones reservadas al administrador. Conductores y mecánicos acceden según sus funciones. Validar permisos en backend y frontend; el backend es la autoridad.

## Solicitudes, viajes y recursos

**RN-TR-01 — Confirmada.** Cada solicitud de transporte corresponde a un único producto líquido y puede originar varios viajes.

**RN-TR-02 — Confirmada.** Cada viaje pertenece a exactamente una solicitud y transporta únicamente el producto asociado a esa solicitud al momento aplicable. Los compartimentos de una cisterna pueden transportar ese mismo producto en cantidades separadas. Conservar la identidad histórica del producto en viajes ante cambios posteriores de la solicitud.

**RN-TR-03 — Confirmada.** El precio depende del cliente, la ruta o el acuerdo comercial. No imponer una fórmula fija por viaje o por litro; el modelo comercial exacto queda por definir.

**RN-TR-04 — Confirmada.** Detectar conflictos de asignación simultánea de un mismo cabezal, cisterna o conductor; advertir y permitir excepción únicamente con autorización del administrador. Registrar autorización, motivo, actor y fecha; proteger contra conflictos concurrentes. La autorización no garantiza disponibilidad física.

**RN-TR-05 — Confirmada.** Los recursos asignados a un viaje (conductor, cabezal, cisterna) solo se liberan manualmente por la secretaria o el administrador. No liberarlos automáticamente al finalizar, cancelar o llegar la hora prevista. Auditar liberaciones.

**RN-TR-06 — Confirmada.** Solo se puede asignar como conductor a un empleado activo identificado como conductor y vinculado a una cuenta de usuario activa.

**RN-TR-07 — Confirmada.** El inicio oficial de un viaje requiere confirmación manual de la secretaria o el administrador, con registro de actor y fecha. No iniciar automáticamente por hora prevista o carga. El conductor no puede iniciar oficialmente el viaje.

**RN-TR-08 — Confirmada.** El conductor reporta desde su teléfono que terminó; después la secretaria o el administrador confirma la finalización oficial. Son eventos separados y auditados. La confirmación no libera recursos automáticamente.

**RN-TR-09 — Confirmada.** Cada viaje tiene una operación de carga y una de descarga, con posibles detalles por compartimento. Un solo producto líquido por viaje. Los requisitos exactos para declarar la operación completa deben concretarse en el diseño.

**RN-TR-10 — Confirmada.** Si los litros efectivamente cargados y descargados difieren, solo el administrador puede autorizar la diferencia para permitir la finalización oficial. Conservar valores reales, justificación, responsable y fecha. La secretaria puede revisar, pero no autorizar.

**RN-TR-11 — Confirmada.** La secretaria o el administrador pueden cancelar un viaje incluso después de iniciado. Exigir motivo y registrar actor y fecha; conservar historial, cargas, descargas y eventos. No liberar recursos automáticamente.

**RN-TR-12 — Confirmada.** Sustituir cabezal o conductor durante un viaje requiere autorización del administrador, motivo y trazabilidad de asignaciones anteriores y nuevas, sin crear un viaje distinto. La secretaria puede solicitar, no autorizar. Aplicar también RN-TR-04.

**RN-TR-13 — Confirmada.** Sustituir una cisterna durante un viaje solo se permite si la cisterna reemplazada está vacía y el administrador lo autoriza. Registrar motivo e historial. No modelar trasvase de producto como sustitución rutinaria.

## Operación, documentos y gastos

**RN-TR-14 — Confirmada.** La secretaria o el administrador registra los volúmenes reales cargados y descargados según la información comunicada por el conductor. El conductor reporta información, pero no registra los volúmenes definitivos. Auditar actor y fecha.

**RN-TR-15 — Confirmada.** En sitios como CATSA el conductor recibe un permiso de salida físico con información de la carga, que puede enviar por fotografía de WhatsApp o entregar en papel. La secretaria o el administrador registra sus datos y puede adjuntar foto o PDF al viaje. No se requiere integración automática con WhatsApp.

**RN-TR-16 — Confirmada.** La obligatoriedad del permiso de salida depende del sitio de carga y debe ser configurable por sitio de operación.

**RN-TR-17 — PENDIENTE, NO APROBADA.** No está definido qué debe hacer el sistema cuando falta un permiso de salida que el sitio exige. No asumir bloqueo, simple advertencia ni autorización excepcional; pedir decisión antes de implementar ese comportamiento.

**RN-TR-18 — Confirmada.** La secretaria o el administrador registra lecturas de odómetro/kilometraje del cabezal a partir de información del conductor. Registrar lecturas inicial/final, distancia calculada y correcciones con historial y responsable.

**RN-TR-19 — Confirmada.** La secretaria o el administrador registra gastos del viaje informados por el conductor, vinculados al viaje y auditados. Evitar duplicación al integrar el futuro módulo de gastos.

**RN-TR-20 — Confirmada.** Los gastos sin comprobante requieren autorización exclusiva del administrador. La secretaria puede registrarlos como pendientes. Auditar monto, motivo, autorización y fecha.

**RN-TR-21 — Confirmada.** La empresa entrega viáticos a conductores. El tratamiento del saldo no utilizado depende del viaje o acuerdo con el conductor; no imponer devolución o retención uniforme. Registrar condiciones, entrega y liquidación cuando corresponda.

**RN-TR-22 — Confirmada.** El administrador autoriza viáticos. La secretaria puede registrar el desembolso previamente autorizado. Conservar viaje, conductor, monto, autorizador y registrador.

**RN-TR-23 — Confirmada.** Un viaje puede tener múltiples desembolsos de viáticos, cada uno autorizado individualmente por el administrador. Solo importes efectivamente entregados integran el total desembolsado.

## Cambios y cierre

**RN-TR-24 — Confirmada.** La secretaria o el administrador puede reprogramar viajes aún no iniciados. Guardar horarios anteriores y nuevos, motivo, actor y fecha. Revalidar conflictos conforme a RN-TR-04.

**RN-TR-25 — Confirmada.** La secretaria o el administrador puede cambiar el volumen solicitado aunque existan viajes asociados. Conservar historial y revisar viajes afectados. No alterar retroactivamente litros reales. Permitir consultar volumen solicitado, transportado y pendiente, con reglas de cálculo por precisar.

**RN-TR-26 — Confirmada.** Cambiar el producto de una solicitud con viajes asociados exige autorización del administrador y revisión de viajes afectados. Los viajes ya cargados o completados conservan su producto histórico; los pendientes deben revisarse antes de aplicar cambios. Auditar productos anterior/nuevo, motivo, responsable y fecha.

**RN-TR-27 — Confirmada.** La secretaria o el administrador puede cerrar administrativamente un viaje después de su finalización oficial y revisión de información. Registrar actor y fecha. Cierre administrativo, finalización operativa y liberación de recursos son operaciones distintas.

**RN-TR-28 — Confirmada.** Se permite cerrar administrativamente un viaje con información pendiente, mostrando advertencias. Registrar los pendientes existentes al cerrar; no marcarlos resueltos ni eliminarlos. El cierre no libera recursos automáticamente. Las autorizaciones especiales siguen siendo exigibles.

**RN-TR-29 — Confirmada.** La secretaria o el administrador puede corregir información de viajes administrativamente cerrados, preservando valores anteriores/nuevos, motivo, actor y fecha. Mantener controles especiales: por ejemplo, la diferencia de litros requiere autorización del administrador. No modificar automáticamente datos de otros módulos.

## Integración futura con facturación y pagos

**RN-TR-30 — Confirmada.** Facturar viajes individualmente o agrupar varios viajes del mismo cliente en una factura, según el acuerdo comercial. Los viajes agrupados no tienen que proceder de la misma solicitud. Evitar duplicación de conceptos facturados.

**RN-TR-31 — Confirmada.** Poder facturar viajes completados antes de finalizar todos los viajes de la solicitud depende del acuerdo comercial con el cliente.

**RN-TR-32 — Confirmada.** El cliente puede pagar al contado o a crédito, según acuerdo comercial. Para crédito, registrar plazo y vencimiento; consultar estados de cobro.

**RN-TR-33 — Actualizada por instrucción del usuario (2026-10-14).** Permitir pagos parciales o totales y consultar el saldo pendiente. Esta instrucción sustituye la decisión anterior de un único pago completo. La persistencia y aplicación monetaria requieren definir moneda, precisión, conceptos y contratos comerciales pendientes; todavía no están implementadas.

**RN-TR-34 — Confirmada.** Admitir efectivo, transferencia bancaria, SINPE Móvil y otros métodos de pago configurables. El administrador puede gestionar métodos. Registrar medio, monto, fecha, usuario y referencia/comprobante cuando aplique.

**RN-TR-35 — Confirmada.** Solo el administrador puede autorizar la anulación de facturas. La secretaria puede solicitarla. Conservar factura e historial y cumplir la normativa fiscal aplicable.

**RN-TR-36 — Confirmada.** No editar directamente facturas ya emitidas; corregir mediante el documento fiscal que corresponda y con autorización del administrador. Preservar original, documento relacionado, motivo y trazabilidad. La determinación del documento fiscal exacto requiere validación normativa.

**RN-TR-37 — Confirmada.** Un viaje cancelado puede generar cobro según acuerdo comercial y circunstancias de la cancelación. Registrar concepto y motivo, sin cambiar el estado operativo cancelado.

**RN-TR-38 — Confirmada.** Solo el administrador puede autorizar si se cobra un viaje cancelado y el importe. La secretaria puede registrar la cancelación y solicitar evaluación. No facturar cobro de cancelación sin autorización; auditar.

**RN-TR-39 — Confirmada.** Solo el administrador autoriza cambios en el precio acordado de un viaje. La secretaria puede registrar o solicitar el cambio, pero no aprobarlo. Guardar importe anterior/nuevo, motivo, fecha y autorizador.

## Límites de alcance y precauciones para Codex

1. Estas reglas son **requisitos de negocio**, no una especificación cerrada de tablas o endpoints.
2. Resolver en diseño el versionado/snapshot del producto por viaje cuando RN-TR-26 cambie el producto de una solicitud sin reescribir historia.
3. Distinguir autorización de RN-TR-10 de RN-TR-28: cerrar administrativamente con pendientes no permite omitir una autorización necesaria para finalizar oficialmente.
4. Distinguir asignación, sustitución y liberación de recursos; mantener historial y concurrencia segura.
5. Las RN-TR-30 a RN-TR-38 describen principalmente integración futura; no implementar Facturación y Pagos durante etapa 5.
6. No inferir automáticamente políticas faltantes sobre documentos, precios, facturación fiscal, liquidación de viáticos o casos excepcionales.
7. En etapa 5.1 actualizar solo documentación y propuesta de diseño. **No crear migraciones, modificar código, ejecutar migraciones en `transporte_db`, hacer commit ni push sin autorización explícita.**
