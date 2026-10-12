# Reglas de negocio — Taller Mecánico

Fuente: instrucciones aprobadas del usuario para etapa 6.1. Las 28 reglas están
**confirmadas** y conservan exactamente su numeración. Las precisiones abiertas
no revocan estas reglas. Las aclaraciones D-TAL-01..13 aprobadas posteriormente
se consolidan en [etapa-6-2.md](etapa-6-2.md); allí se incorporan T01–T09 como decisiones técnicas aprobadas
en el cierre documental, sin autorizar todavía implementación. [etapa-6-1.md](etapa-6-1.md) conserva el análisis inicial.
Prevalecen sobre las hipótesis del ER y propuesta técnica históricos.

**RN-TAL-01 — Confirmada. Bloqueo por ingreso al taller.** Cuando un cabezal o cisterna ingresa al taller por una avería, queda automáticamente bloqueado para nuevas asignaciones de transporte. El ingreso no cancela ni libera asignaciones existentes. Registrar advertencias y conservar trazabilidad. Solo el administrador puede autorizar la salida; el vehículo solo estará disponible si no existen otros impedimentos.

**RN-TAL-02 — Confirmada. Reportes y creación de órdenes.** Los mecánicos pueden reportar averías, pero no crear órdenes de trabajo oficiales. La secretaria y el administrador pueden registrar reportes y crear órdenes. El administrador autoriza reparaciones. Registrar actores, fechas y auditoría.

**RN-TAL-03 — Confirmada. Autorización previa.** Los mecánicos pueden diagnosticar y proponer trabajos y repuestos. No pueden iniciar reparaciones ni consumir repuestos sin autorización previa del administrador.

**RN-TAL-04 — Confirmada. Reparaciones adicionales.** Si aparece otra avería, el mecánico la registra y solicita autorización independiente. Los trabajos previamente autorizados pueden continuar. No se pueden ejecutar trabajos adicionales ni consumir sus repuestos sin autorización.

**RN-TAL-05 — Confirmada. Finalización y salida.** El mecánico registra el trabajo terminado. El vehículo continúa bloqueado hasta que el administrador revise y autorice su salida. Registrar la autorización y verificar otros impedimentos de disponibilidad.

**RN-TAL-06 — Confirmada. Una reparación por orden.** Cada orden de trabajo representa una única reparación. Si un cabezal necesita reparar frenos y suspensión, deben existir dos órdenes independientes, aunque correspondan al mismo vehículo.

**RN-TAL-07 — Confirmada. Órdenes simultáneas.** Un vehículo puede tener varias órdenes abiertas al mismo tiempo, gestionadas independientemente.

**RN-TAL-08 — Confirmada. Identificación.** Cada orden debe tener identificador único, vehículo, descripción de avería, fecha, prioridad y responsable de creación.

**RN-TAL-09 — Confirmada. Mecánicos asignados.** Una orden puede asignarse a uno o varios mecánicos.

**RN-TAL-10 — Confirmada. Estados.** Contemplar los estados: pendiente de diagnóstico, pendiente de autorización, autorizada, en reparación, terminada, cerrada y cancelada. Documentar transiciones permitidas, responsables y validaciones.

**RN-TAL-11 — Confirmada. Diagnóstico.** El mecánico registra problema encontrado, causa probable y reparación propuesta antes de solicitar autorización.

**RN-TAL-12 — Confirmada. Presupuesto.** Permitir estimar repuestos, cantidades, costos y mano de obra cuando esos datos estén disponibles, sin inventar valores.

**RN-TAL-13 — Confirmada. Repuestos.** Cada orden registra los repuestos utilizados. Cuando exista integración con Inventario, los consumos autorizados deberán generar movimientos reales y trazables.

**RN-TAL-14 — Confirmada. Faltantes y compras.** Permitir registrar repuestos faltantes y necesidades de compra. La reparación puede quedar esperando materiales. Las compras requieren autorización administrativa.

**RN-TAL-15 — Confirmada. Trabajo realizado.** Los mecánicos registran actividades, fechas y observaciones. Las modificaciones deben preservar el historial.

**RN-TAL-16 — Confirmada. Tiempo.** Registrar inicio, pausas, reanudaciones y finalización de la reparación.

**RN-TAL-17 — Confirmada. Evidencias.** Permitir fotografías, facturas y documentos vinculados a las órdenes, con almacenamiento privado y permisos.

**RN-TAL-18 — Confirmada. Prioridades.** Contemplar baja, normal, alta y urgente.

**RN-TAL-19 — Confirmada. Reparaciones externas.** Registrar reparaciones realizadas por talleres externos, incluyendo proveedor, costo, factura y autorización administrativa.

**RN-TAL-20 — Confirmada. Cancelaciones.** Secretaria o administrador pueden solicitar cancelación. Si ya existen trabajos o consumos, el administrador debe decidir el cierre correspondiente, preservando el historial.

**RN-TAL-21 — Confirmada. Liberación del vehículo.** Si existen varias órdenes abiertas que impiden operar, el vehículo sigue bloqueado hasta que las reparaciones correspondientes estén resueltas y el administrador autorice la salida.

**RN-TAL-22 — Confirmada. Viajes existentes.** Si el vehículo ingresa al taller estando asignado a un viaje, mostrar advertencia. No cancelar el viaje ni liberar recursos automáticamente.

**RN-TAL-23 — Confirmada. Historial.** Cada cabezal y cisterna debe tener historial de averías, órdenes, diagnósticos, reparaciones, repuestos y costos.

**RN-TAL-24 — Confirmada. Preventivos.** Contemplar mantenimiento preventivo, por ejemplo aceite, filtros e inspecciones. Mantener una reparación o servicio por orden. Los criterios exactos de programación todavía no están definidos.

**RN-TAL-25 — Confirmada. Pendientes y notificaciones.** Mostrar autorizaciones pendientes al administrador, órdenes asignadas a mecánicos y vehículos bloqueados a la secretaria.

**RN-TAL-26 — Confirmada. Reportes.** Consultar órdenes por estado, vehículo y fechas, reparaciones frecuentes, costos y tiempo fuera de servicio.

**RN-TAL-27 — Confirmada. Rectificaciones.** Los errores deben corregirse con registros auditables, sin eliminar silenciosamente la información original.

**RN-TAL-28 — Confirmada. Acceso móvil.** Los mecánicos necesitan una interfaz adaptable a celular o tablet para consultar órdenes y registrar diagnósticos y trabajos.

## Interpretaciones expresamente aprobadas

- Una avería adicional diferente requiere **otra orden oficial**, creada por secretaria o
  administrador; el reporte del mecánico no la crea ni autoriza automáticamente.
- Ampliar el alcance de la **misma reparación** requiere solicitud sobre la orden
  existente y autorización administrativa independiente (D-TAL-01); los trabajos
  previamente autorizados pueden continuar.
- Estado de orden y disponibilidad del activo son independientes. Terminar, cerrar o
  cancelar una orden no autoriza por sí solo salida ni desbloquea el activo.
- No se cancelan viajes ni se liberan asignaciones por ingreso, reparación o cierre.
- No se simulan existencias, movimientos de inventario ni compras efectivas.
- Urgencia no concede una excepción implícita a la autorización administrativa previa.
- RN-SEG-01 conserva la jerarquía: administrador con acceso completo, secretaria sin
  autorizaciones exclusivas y mecánico limitado a sus funciones. Acceso completo no
  significa omitir trazabilidad, autorización explícita ni integridad.
