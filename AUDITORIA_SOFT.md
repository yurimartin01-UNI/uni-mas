# Auditoría Uni+ (versión ejecutiva)

Fecha: 2026-06-01  
Rama de trabajo: `marco-review`  
Objetivo de esta versión: resumir, en lenguaje no técnico, qué se revisó, qué se corrigió y qué queda pendiente.

---

## 1) Resumen general

Durante esta ronda se hizo una revisión integral del plugin Uni+ para Moodle, con foco en cuatro temas:

1. estabilidad técnica y operación en ambiente de pruebas,  
2. privacidad de la información enviada al agente de IA,  
3. cruce de datos por Excel,  
4. riesgos de código y mantenibilidad.

El resultado general es positivo: se corrigieron los problemas más sensibles de seguridad, permisos, configuración de IA y consistencia de datos. Además, se dejó un entorno de pruebas reproducible con Docker y utilitarios para reiniciar datos demo rápidamente.

Todavía hay algunos temas abiertos que no son “incendio”, pero sí conviene resolver en una siguiente fase para reducir deuda técnica y operativa (compatibilidad de versiones Moodle, modelo final de permisos del plugin y funciones RAG/export que aún están a medio camino).

---

## 2) Qué se corrigió (lo más importante)

## 2.1 Seguridad y exposición de datos

Se cerró un riesgo de XSS (inyección de contenido malicioso en pantalla) en varias zonas del panel.  
En simple: antes había casos en que texto proveniente de encuestas o respuesta IA podía mostrarse sin suficiente protección. Ahora se escapa y sanitiza de forma consistente.

También se redujo la información enviada al modelo de IA:

- antes se enviaba demasiado contexto del estudiante,  
- ahora se envía un payload mínimo orientado al análisis pedagógico,  
- se excluyen datos directos innecesarios (por ejemplo, correo y otros campos internos).

Esto baja riesgo de privacidad y mantiene funcionalidad.

## 2.2 Permisos de configuración global

Se corrigió un punto crítico: usuarios con permisos de curso podían alterar la configuración global de IA del sitio.

Ahora esa operación quedó restringida a permisos de administración del sitio (`moodle/site:config`), y el botón de configuración también se oculta para quien no tiene ese nivel de acceso.

## 2.3 Proveedores IA y errores de endpoint

Se unificó la lógica de proveedores para evitar inconsistencias entre frontend y backend.  
Además, se agregó validación de valores permitidos y reglas claras para proveedor custom.

Resultado práctico: menos errores del tipo “endpoint incorrecto”, “URL no configurada” o llamadas incompatibles.

## 2.4 Mejora puntual por incidente 404 (Base URL)

En pruebas apareció un caso muy concreto: al dejar `Base URL` en proveedores estándar, el informe UNI+ podía fallar con 404.

Se aplicó hardening del modal:

- `Base URL` quedó como opción avanzada (colapsada),  
- advertencia visible de que solo aplica para `custom`,  
- el sistema ya no envía esa URL para proveedores estándar,  
- si se usa `custom`, la URL pasa a ser obligatoria.

Esto reduce errores de configuración por uso cotidiano.

## 2.5 Carga masiva por Excel (matching)

Se fortaleció el cruce:

- prioridad por correo institucional,  
- fallback por nombre solo si no hay correo,  
- detección de conflicto si nombre y correo apuntan a personas distintas.

Con esto baja el riesgo de asignar respuestas de un estudiante al perfil equivocado.

## 2.6 Estabilidad operativa y datos demo

Se dejó utilitario de reset para pruebas (`reset-demo`) que:

- limpia tablas funcionales del plugin,  
- recrea usuarios demo globales,  
- estandariza password de pruebas.

Esto acelera testeo y evita “estado sucio” entre iteraciones.

---

## 3) Estado actual del ambiente de testing

El ambiente Docker para Moodle + plugin está operativo para desarrollo.

Se resolvieron incidencias de permisos que bloqueaban acceso (`Invalid permissions...`) y quedó una rutina clara para estabilizar entorno (configuración dev, permisos, purge de caché).

En la práctica:

- se puede entrar a Moodle,  
- probar el panel Uni+,  
- cambiar código y verificar resultados,  
- resetear datos de prueba de forma rápida.

---

## 4) Riesgos y pendientes que siguen abiertos

Estos puntos no quedaron sin mirar; están identificados y documentados para siguiente fase:

## 4.1 Versión mínima de Moodle inconsistente

Hoy la documentación y la metadata técnica no dicen lo mismo sobre la versión mínima soportada.  
No rompe ahora, pero genera confusión en despliegue y soporte.

## 4.2 Modelo de permisos del plugin no completamente unificado

Existe una capability propia del plugin, pero el acceso principal sigue pasando por un permiso más amplio de curso.  
Se recomienda decidir un esquema único para simplificar administración y auditoría.

## 4.3 Funciones RAG/export parcialmente implementadas

Hay rutas/acciones visibles, pero parte del backend es stub/no-op.  
Recomendación: o completar flujo end-to-end o esconder temporalmente lo que aún no está listo.

## 4.4 Comportamiento de fallback en estudiantes nuevos

Alumnos recién creados pueden mostrar métricas “de arranque” que no representan aún comportamiento real.  
Está aceptado temporalmente para testing, pero conviene definir luego si se quiere otra presentación para producción.

---

## 5) Recomendación de cierre para esta etapa

Para el contexto actual (testing y preparación de evolución), el estado es suficientemente bueno para empezar una fase de mejora funcional y refinamiento.

Lo ya corregido cubre riesgos relevantes de:

- seguridad visual/persistente,  
- sobreexposición de datos al LLM,  
- permisos globales críticos,  
- errores comunes de configuración IA,  
- consistencia básica de carga por Excel.

En otras palabras: no está “terminado”, pero sí quedó en una base mucho más segura y gobernable que el punto de partida.

---

## 6) Próximos pasos sugeridos (orden recomendado)

1. Definir oficialmente compatibilidad de versiones Moodle y alinear documentación/metadata.  
2. Cerrar decisión de modelo de permisos del plugin (capability principal).  
3. Decidir estrategia de RAG/export (completar o ocultar).  
4. Ejecutar una ronda de pruebas funcionales guiadas (flujo docente completo: carga Excel, análisis IA, seguimiento, exportación).  
5. Luego recién entrar a refactor más grande del `index.php` monolítico, para no mezclar riesgos funcionales y estructurales en la misma etapa.

---

## 7) Conclusión breve

La auditoría permitió pasar de un estado “vibecodeado con riesgos difusos” a un estado controlado, con problemas críticos tratados y con trazabilidad clara de decisiones.

Se puede seguir avanzando con cambios de producto y UX, pero con mejor piso técnico y menor probabilidad de incidentes evitables.
