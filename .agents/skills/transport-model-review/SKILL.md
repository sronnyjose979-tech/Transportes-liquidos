---
name: transport-model-review
description: Revisar propuestas de implementación del sistema de transporte de líquidos frente a su documentación de modelo y negocio, verificando entidades, atributos, cardinalidades, restricciones y reglas críticas de integridad y concurrencia. Usar antes de implementar cambios o al revisar su conformidad; no implementa cambios ni convierte decisiones propuestas en aprobadas.
---

# Revisión del modelo de transporte

## Objetivo y alcance

Contrastar la tarea o propuesta de implementación con las fuentes del proyecto
y detectar discrepancias o decisiones faltantes antes de implementar.

Realizar la revisión en modo de lectura. Esta skill no modifica documentación,
código ni datos. Aplicar las reglas generales de AGENTS.md sin reproducirlas
en el informe salvo que expliquen un hallazgo.

## Fuentes

Localizar la raíz del proyecto y leer AGENTS.md y las instrucciones aplicables
al alcance revisado. Consultar:

- docs/propuesta-tecnica.md: requisitos, propuestas, procedimientos y pendientes.
- docs/modelo-er.mmd: entidades, atributos y relaciones del modelo conceptual.
- docs/tabla-entidades.md: responsabilidades y atributos.
- docs/tabla-relaciones.md: cardinalidades y restricciones complementarias.
- docs/arquitectura.mmd: módulos, dependencias y capacidades transversales.

Leer las secciones relevantes completas, no solo coincidencias de búsqueda.
Comprobar también las referencias internas necesarias para interpretarlas.

Si falta una fuente o no puede leerse, indicarlo y explicar qué parte de la
revisión queda sin verificar. No reconstruir su contenido desde código,
migraciones, nombres de tablas o conocimientos generales.

## Estado y autoridad de la documentación

Antes de comprobar conformidad, identificar el estado de cada definición:

- Requisito confirmado: declarado expresamente como confirmado.
- Decisión aprobada: respaldada por aprobación explícita y aplicable.
- Diseño propuesto: alternativa descrita sin aprobación acreditada.
- Asunto pendiente: decisión o procedimiento todavía sin resolver.
- Estado no determinado: no existe evidencia suficiente para clasificarlo.

Respetar las aprobaciones explícitas del usuario y registrar su alcance.
Aprobar un análisis no implica aprobar automáticamente el modelo o todas
las decisiones contenidas en sus documentos.

No considerar que una definición está aprobada por aparecer en Mermaid,
una tabla, código existente o una sección sin advertencias.
No resolver contradicciones por mayoría de documentos ni asumir que el
archivo más reciente tiene autoridad si no está establecido.

## Procedimiento de revisión

1. Delimitar la tarea: identificar comportamiento, entidades, operaciones,
   módulos y contratos afectados. Si todavía no existe una propuesta concreta,
   limitar el resultado a revisión documental y requisitos para evaluarla.

2. Identificar las definiciones aplicables y su estado de aprobación.
   Relacionar cada parte relevante de la propuesta con una fuente concreta.

3. Verificar:
   - Entidades existentes y responsabilidades.
   - Atributos, tipos, unidades, precisión y nulabilidad definidos.
   - Claves y referencias, incluido el destino correcto de cada FK.
   - Cardinalidades mínimas y máximas en ambas direcciones.
   - Unicidad, exclusividad, participación obligatoria e integridad referencial.
   - Restricciones condicionadas por estado.
   - Valores derivados frente a valores persistidos e historia preservada.
   - Límites de módulos y dependencias descritas.

   Distinguir ausencia permitida en borrador de requisitos al confirmar,
   despachar, completar o emitir. No inferir nulabilidad definitiva únicamente
   por la notación de atributos del diagrama.

4. Contrastar diagramas, tablas y texto para detectar diferencias reales.
   Separar una contradicción de una omisión, una ambigüedad o un detalle de
   diseño físico todavía pendiente.

5. Revisar integridad y concurrencia aplicables:
   - Asignaciones y reservas temporales de activos y conductores.
   - Coherencia cliente/producto/ruta/tarifa y conservación histórica.
   - Correspondencia carga–descarga–aceptación.
   - Consumos, recepciones, ajustes y reversas de inventario.
   - Litros facturables, facturados y reservados; pagos y saldos.
   - Reintentos offline, idempotencia, permisos y conflictos.

   Identificar la invariante, las operaciones que pueden competir y si la
   propuesta protege ambas de forma atómica. Distinguir una regla requerida
   de un mecanismo técnico sugerido. No elegir aislamiento, bloqueos o
   restricciones físicas como si ya estuvieran aprobados.

6. Determinar si algún pendiente afecta la corrección de la tarea.
   Solicitar aclaraciones concretas citando la decisión afectada y explicar
   qué parte no puede validarse hasta resolverla. Continuar revisando las
   partes independientes.

## Distinciones esenciales del proyecto

Consultar la versión vigente de las fuentes antes de aplicar estas distinciones:

- Litros cargados, descargados y aceptados son conceptos diferentes.
- Pagos parciales no autorizan facturación parcial del volumen de un viaje.
- Una cardinalidad provisional no habilita el procedimiento pendiente.
- UUID/idempotencia evita repetir una operación; no sustituye las restricciones
  que impiden operaciones de negocio incompatibles con identificadores distintos.
- Los diagramas no garantizan por sí solos restricciones entre tablas o por estado.

No introducir entidades, atributos ni reglas para resolver vacíos documentales.
Si una discrepancia sugiere cambiar el modelo, explicar primero la necesidad,
la evidencia y el impacto. Presentarlo como propuesta sujeta a autorización,
sin editar el modelo ni tratar el cambio como aprobado.

## Presentación del resultado

Generar informes concisos por defecto: mostrar solo hallazgos relevantes para
la tarea, bloqueos y referencias necesarias. No enumerar comprobaciones sin
incidencias ni reproducir documentación; ampliar el detalle si el usuario lo pide.

Abrir con una conclusión limitada al alcance revisado:

- Conforme en el alcance revisado.
- Con discrepancias.
- Pendiente de aclaración o aprobación.
- No verificable por fuentes o propuesta insuficientes.

Resumir por separado requisitos confirmados, decisiones aprobadas, propuestas
y pendientes relevantes. Omitir categorías vacías.

Para cada hallazgo indicar:
- Clasificación: contradicción, incumplimiento, ambigüedad, omisión o pendiente.
- Evidencia de la propuesta o artefacto revisado.
- Documento y sección de referencia; añadir enlace y línea cuando estén disponibles.
- Impacto sobre la tarea.
- Aclaración o acción necesaria y si impide validar esa parte.

En contradicciones documentales, citar ambas fuentes.
No afirmar conformidad global si solo se revisó una parte.
Indicar qué no pudo comprobarse y separar la revisión conceptual de cualquier
garantía que requiera implementación o pruebas.
