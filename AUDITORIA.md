# Auditoría técnica Uni+ (detalle completo)

Fecha base de auditoría: 2026-06-01  
Rama: `marco-review`  
Alcance: revisión técnica + hardening incremental sobre plugin local `unimas` montado en entorno Docker de Moodle.

---

## 0) Contexto de ejecución y criterio de trabajo

### Entorno usado
- Proyecto levantado con Docker Compose (Moodle + DB + sync plugin).
- Iteración validada contra contenedor Moodle activo.
- Cambios aplicados sin alterar estructura de carpetas del plugin.

### Criterio
- Resolver primero riesgos que pueden romper operación o seguridad.
- Mantener trazabilidad de:
  - problema observado
  - causa técnica
  - impacto
  - fix aplicado
  - validación y estado

---

## 1) Métricas iniciales de estudiantes nuevos (fallback funcional)

### Problema observado
- Al crear usuarios nuevos y matricularlos al curso, aparecen valores como:
  - `IS = 0.40`
  - `delta = -0.10`
  - historial `0.50, 0.50, 0.50, 0.40` (o variantes equivalentes)
  - componente de entregas `1.00`

### Evidencia técnica
- Cálculo de indicadores: `unimas/classes/data_provider.php`
  - fórmula IS en línea ~151
  - submissions retorna `1.0` si no hay tareas vencidas en línea ~257
  - historial se completa con `0.5` hasta 4 puntos en línea ~176

### Causa raíz
- El modelo de indicadores usa supuestos por defecto para ausencia de datos:
  - no vencimientos => entregas ideales
  - historial incompleto => padding con `0.5`

### Impacto
- Riesgo de interpretación: el docente puede leer esos números como señal real del estudiante en lugar de un fallback.

### Fix aplicado
- No se alteró este comportamiento por decisión funcional de testing (explícita del usuario).

### Estado
- `Aceptado temporalmente / pendiente decisión de producto`.

### Explicación y fix sencillo
- Los números iniciales que ves en alumnos nuevos no significan necesariamente que ya fueron evaluados; son valores de relleno cuando todavía no hay datos reales suficientes.
- Por ahora se dejó así porque sirve para pruebas internas y porque la definición funcional actual lo permite.

---

## 2) Warning en listado de usuarios (`deleted` / `suspended`)

### Problema observado
- Warnings repetidos:
  - `Undefined property: stdClass::$deleted`
  - `Undefined property: stdClass::$suspended`

### Evidencia técnica
- `unimas/classes/data_provider.php`
  - método `get_course_students_robust` en línea ~309
  - query principal con `u.suspended, u.deleted` en línea ~314
  - query fallback ahora con `u.suspended, u.deleted` en línea ~317
  - lectura defensiva con `isset` en líneas ~328-329

### Causa raíz
- El fallback SQL previo no seleccionaba `deleted/suspended`, pero el filtro posterior sí asumía esos campos.

### Impacto
- Ruido de logs + posible filtrado inconsistente de participantes.

### Fix aplicado
- SELECT fallback corregido para incluir `u.suspended` y `u.deleted`.
- Filtro robustecido para manejar ausencia inesperada de campos (`isset` + cast).

### Validación
- Validación sintáctica PHP en contenedor: OK.

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- El sistema intentaba revisar dos campos del usuario que en algunos casos no venían en la consulta, y por eso aparecían warnings.
- Se corrigió la consulta y además se agregó una validación para que, si faltara un dato, no rompa ni ensucie logs.

---

## 3) Cruce Excel (match de estudiantes)

### Problema observado
- Riesgo de asignar respuestas de encuesta al estudiante incorrecto cuando el nombre coincide parcialmente o hay duplicidades.

### Evidencia técnica
- `unimas/index.php`
  - `processExcel()` en línea ~1044
  - columnas esperadas:
    - nombre: `row[1]`
    - correo: `row[2]`
    - respuestas: `row[3..6]`
  - match por correo/nombre en líneas ~1094-1110

### Causa raíz
- Lógica previa priorizaba nombre y no manejaba conflicto explícito nombre/correo.

### Impacto
- Riesgo de contaminación de contexto pedagógico entre estudiantes.

### Fix aplicado
- Orden de match cambiado:
  - primero por correo institucional
  - fallback por nombre solo si no hubo match por correo
- Regla de conflicto:
  - si nombre y correo apuntan a usuarios distintos, no se carga la fila
  - se muestra `⚠ Conflicto nombre/correo` en la previsualización
- Se mantiene contrato de plantilla Excel vigente (B/C/D..G).

### Decisión de alcance
- El equipo define que el Excel de entrada mantiene formato fijo y controlado.
- Por esa razón, **no se implementa validación de encabezados** en esta fase.
- Se mantiene mapeo posicional por columnas como diseño intencional.

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- Antes se podía emparejar por nombre y eso es riesgoso cuando hay nombres parecidos o repetidos.
- Ahora primero se usa correo (más confiable), y si hay contradicción entre nombre y correo, el sistema no carga esa fila y la marca para revisión.

---

## 4) XSS persistente en panel (riesgo crítico)

### Problema observado
- Datos provenientes de:
  - respuestas de encuesta (contexto)
  - resumen/reporte de IA
  - vista previa de importación Excel
  podían renderizarse en `innerHTML` sin escape suficiente.

### Evidencia técnica
- `unimas/index.php`
  - `UNIMAS_DATA` embebido en script en línea ~463
  - helper `escapeHtml` en línea ~613
  - helper `formatAiReport` en línea ~623
  - uso de escape en resumen IA, nombre/id, contexto, fallback texto y textarea en líneas ~652, ~658, ~718, ~728, ~792
- `unimas/classes/action_handler.php`
  - `handle_bulk_context` en línea ~211
  - saneamiento backend `sanitize_context_text` en línea ~258

### Causa raíz
- Interpolación directa de strings no confiables dentro de HTML dinámico.

### Impacto
- Ejecución de script persistente al abrir el dashboard (riesgo de toma de sesión/acciones con permisos docentes o admins).

### Fix aplicado
- Frontend:
  - escape sistemático para strings dinámicos (`escapeHtml`)
  - render de reporte IA transformado a modo seguro (`formatAiReport`), permitiendo solo formato controlado de encabezados `###`
  - `json_encode` endurecido con:
    - `JSON_HEX_TAG`
    - `JSON_HEX_AMP`
    - `JSON_HEX_APOS`
    - `JSON_HEX_QUOT`
- Backend:
  - saneamiento defensivo de payload de `bulk_context` antes de persistir en DB.

### Validación
- `php -l` en contenedor para archivos modificados: OK.

### Estado
- `Resuelto (mitigación principal aplicada)`.

### Explicación y fix sencillo
- Había riesgo de que texto ingresado por usuarios o devuelto por la IA se mostrara como código HTML/JS.
- Se cambió para mostrarlo como texto seguro y además se limpia al guardar, reduciendo el riesgo de ataques en el panel.

---

## 5) Permisos: configuración global editable desde permisos de curso

### Problema observado
- Un docente con permiso de curso podía modificar configuración global (`api_key`, `provider`, `model`, `base_url`) del plugin.

### Evidencia técnica
- `unimas/classes/action_handler.php`
  - `handle_save_ai_config` en línea ~284
  - control de acceso ahora en `moodle/site:config` línea ~286
- `unimas/index.php`
  - botón de modal de configuración visible solo con `moodle/site:config` en línea ~333

### Causa raíz
- Guardado global vía `set_config(...)` protegido originalmente con permiso de contexto curso.

### Impacto
- Un curso podía alterar comportamiento IA de todo el sitio Moodle.

### Fix aplicado
- Backend:
  - restricción de `save_ai_config` a contexto de sistema `moodle/site:config`
- UI:
  - ocultamiento del control de configuración para usuarios sin capacidad de sistema

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- Un docente de un curso podía cambiar la configuración global de IA para todo Moodle, lo cual no corresponde.
- Ahora solo perfiles de administración del sitio pueden cambiar esas claves y parámetros globales.

---

## 6) Desacople de proveedores IA frontend/backend (rotura funcional)

### Problema observado
- Riesgo de desalineación entre valores de proveedor configurados en UI y rutas reales del backend.
- Casos legacy (`google`, `claude`) podían derivar en errores por endpoint vacío o ruta incompatible.

### Evidencia técnica
- `unimas/classes/action_handler.php`
  - lista canónica `ALLOWED_AI_PROVIDERS` línea ~16
  - normalización `normalize_ai_provider` línea ~62
  - validación de proveedor en `handle_save_ai_config` líneas ~296-299
  - regla `custom` requiere `base_url` en líneas ~305-310
- `unimas/classes/ai_agent.php`
  - lista canónica `SUPPORTED_PROVIDERS` línea ~16
  - normalización `normalize_provider` línea ~18
  - enrutamiento central en `call_ai_provider` línea ~305
  - fallback endpoint en `get_default_endpoint` línea ~328
- `unimas/index.php`
  - selector de proveedor (set canónico) en líneas ~399-403
  - normalización al cargar config para UI (`google/claude` a canónico) en bloque de configuración inicial

### Causa raíz
- Ausencia previa de capa explícita de normalización + validación centralizada.

### Impacto
- Errores de llamada (`URL de API no configurada`) o uso de endpoint equivocado.

### Fix aplicado
- Canonicalización uniforme:
  - `google -> gemini`
  - `claude -> anthropic`
- Rechazo explícito de proveedor fuera de whitelist.
- `custom` exige `Base URL` obligatoria.
- Limpieza de `ai_base_url` residual al volver a proveedor no-custom para evitar endpoint “pegado”.

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- Había nombres de proveedor distintos entre pantallas y backend, y eso podía romper llamadas de IA.
- Se unificaron los nombres, se agregó traducción de valores antiguos y se bloquean valores inválidos para evitar errores.

---

## 6.1) Hardening UX de configuración IA: Base URL como opción avanzada (prevención de 404)

### Problema observado
- En uso real, al generar informes UNI+ aparecían errores `404` cuando se guardaba una `Base URL` en proveedores estándar.
- Aunque backend ya limpiaba `ai_base_url` al pasar a proveedor no-custom si la URL venía vacía, desde UI el campo seguía visible y era fácil dejar un override inválido.

### Evidencia técnica
- `unimas/index.php`:
  - modal de configuración IA con campo `config-baseurl` siempre visible (estado previo).
  - envío de `ai_base_url` en `saveAIConfig()` aunque el proveedor no fuera `custom` (estado previo).
- cambio aplicado:
  - bloque avanzado `config-advanced-wrap` con toggle para mostrar/ocultar Base URL.
  - warning contextual en `baseUrlWarning`.
  - lógica `updateBaseUrlUI()` + `toggleAdvancedBaseUrl()` + `setAdvancedBaseUrlOpen()`.
  - guardado defensivo en `saveAIConfig()`:
    - si proveedor != `custom`, fuerza `baseurl = ''`.
    - si proveedor == `custom` y no hay URL, bloquea guardado con mensaje.

### Causa raíz
- Diseño del modal no dejaba suficientemente claro que `Base URL` es una configuración excepcional.
- Faltaba guardrail en frontend para evitar enviar URL cuando no corresponde.

### Impacto
- Sobrescritura accidental del endpoint por defecto y fallos de inferencia (`404`/endpoint no encontrado).
- Fricción operativa para docentes/admins al configurar proveedor.

### Fix aplicado
- UI:
  - `Base URL` pasa a opción avanzada colapsada por defecto.
  - advertencia explícita para no usar en proveedores estándar.
  - habilitación del input solo en `custom`.
- Frontend:
  - no envía `ai_base_url` para `gemini/openai/anthropic/deepseek`.
  - exige URL para `custom`.

### Validación
- Lint PHP en contenedor Moodle:
  - `/bitnami/moodle/local/unimas/index.php` sin errores de sintaxis.
- Prueba funcional esperada:
  - proveedores estándar funcionan sin necesidad de `Base URL`.
  - proveedor `custom` solicita URL y permite guardar.

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- El campo `Base URL` se estaba usando por error en proveedores donde no corresponde, y eso rompía llamadas.
- Ahora quedó escondido como opción avanzada, con advertencia clara, y el sistema evita enviarlo salvo cuando se usa `custom`.

---

## 7) Clase legacy `lock_manager` con comentario roto (riesgo de parseo)

### Problema observado
- Bloque de comentario/docblock incompleto alrededor de `render_lock_banner`.

### Evidencia técnica
- `unimas/classes/lock_manager.php`
  - clase `lock_manager` línea ~20
  - método `render_lock_banner` línea ~58

### Causa raíz
- Docblock mal cerrado/alineado.

### Impacto
- Si se carga el archivo en rutas legacy/autoload, puede romper runtime por parse error.

### Fix aplicado
- Reescritura del docblock a formato PHPDoc válido para `render_lock_banner`.

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- Había un comentario mal cerrado en una clase antigua que podía causar falla de carga en ciertos escenarios.
- Se corrigió ese bloque para dejar el archivo estable y sin riesgo de error de parseo.

---

## 8) Cambios UI/UX ejecutados durante la revisión

### Objetivo
- Separar visualmente acciones de panel vs seguimiento.
- Mejorar legibilidad y jerarquía.

### Cambios aplicados
- `unimas/index.php` (CSS + markup):
  - separación de bloques:
    - `action-bar` arriba
    - `summary-bar` abajo
  - barra de búsqueda integrada en acciones (lado derecho)
  - botones rediseñados:
    - texto panel: `Volver al panel`
    - icono SVG pequeño a la derecha en ambos botones
  - botón `Analizar y Refrescar Agente`:
    - removido degradado
    - color celeste sólido (`#60a5fa`)

### Estado
- `Aplicado`.

### Explicación y fix sencillo
- Se reorganizó la pantalla para que las acciones y los indicadores no compitan visualmente.
- El objetivo fue que sea más claro “qué botón hace cosas” y “qué parte muestra estado”.

---

## 9) Estado de entorno de pruebas

### Resultado
- Entorno Docker Compose operativo para Moodle + plugin.
- Flujo de edición local del plugin habilitado para iteración.
- Problemas de permisos de arranque/acceso estabilizados durante la puesta a punto.
- Se dejó automatización para reset funcional + seed de usuarios demo (`make reset-demo`).

### Estado
- `Operativo para testing manual`.

### Explicación y fix sencillo
- El entorno está listo para que el equipo pruebe cambios sin tocar producción.
- Puedes iterar cambios en el plugin y verificar comportamiento dentro de Moodle de forma controlada.

---

## 9.1) Incidencia de guardado de configuración (`$PAGE->set_url`) y corrección

### Problema observado
- Al guardar configuración del plugin desde el modal, Moodle devolvía:
  - `This page did not call $PAGE->set_url(...)`
  - y el frontend mostraba `Error al guardar la configuración`.

### Evidencia técnica
- `unimas/index.php`
  - definición de URL de página:
    - `$pageurl = new moodle_url('/local/unimas/index.php', array('courseid' => $courseid));`
  - seteo temprano de página:
    - `$PAGE->set_url($pageurl);`
  - manejo de acciones AJAX inmediatamente después usando ese `$pageurl`.

### Causa raíz
- El flujo de acción (`action_handler`) podía ejecutarse antes de que Moodle tuviera la URL de página registrada en ese request.

### Impacto
- Fallo al guardar configuración desde UI, aun con permisos correctos.

### Fix aplicado
- Se aseguró `set_url` antes del bloque que despacha acciones (`optional_param('action', ...)` + `action_handler::handle(...)`).

### Estado
- `Resuelto`.

### Explicación y fix sencillo
- Moodle necesita saber la URL de la página desde el inicio del request.
- Al mover esa definición al principio, el guardado dejó de romperse.

---

## 9.2) Incidencia operativa de permisos en Moodle y uso correcto de utilitarios

### Problema observado
- Error de acceso en Moodle:
  - `Invalid permissions detected when trying to create a directory`.

### Evidencia técnica
- Flujo de corrección operativo en Makefile/scripts:
  - `make moodle-config` (`scripts/moodle-dev-config.sh`)
  - `make fix-perms` (ajuste de `directorypermissions`, ownership y permisos de cache/temp/sessions).

### Causa raíz
- Permisos/propietarios inconsistentes entre `config.php` y carpetas de runtime de `moodledata` durante la inicialización.

### Impacto
- Moodle no podía crear directorios temporales/cache y bloqueaba acceso/operación.

### Fix aplicado
- Se estandarizó uso de tareas operativas:
  - aplicar configuración dev
  - reparar permisos y ownership de runtime
  - purgar cachés para estabilizar estado.

### Estado
- `Resuelto (operativo)`.

### Explicación y fix sencillo
- No era un bug funcional del plugin sino de permisos del entorno.
- Ajustando permisos de carpetas de trabajo de Moodle, el acceso volvió a funcionar.

---

## 10) Validaciones ejecutadas

- Validación sintáctica PHP ejecutada en contenedor para archivos tocados:
  - `unimas/index.php`
  - `unimas/classes/action_handler.php`
  - `unimas/classes/ai_agent.php`
  - `unimas/classes/data_provider.php`
  - `unimas/classes/lock_manager.php`
- Resultado: sin errores de sintaxis detectados en las versiones actuales.

### Explicación y fix sencillo
- Se revisó que los archivos modificados no tengan errores básicos de PHP.
- Esto no reemplaza pruebas funcionales completas, pero sí reduce riesgo de caída inmediata por sintaxis.

---

## 11) Pendientes técnicos sugeridos (siguiente ronda)

- Manejo explícito de duplicados de correo en Excel antes de persistencia.

---

## 11.1) Privacidad de payload al agente + caché por idioma (implementado en esta ronda)

### Problema observado
- Se enviaba demasiada información al LLM en el flujo individual (objeto completo del estudiante).
- En el flujo global se enviaban identificadores directos y contexto libre de encuesta.
- La caché IA no separaba idioma y podía devolver texto en idioma de otro usuario.

### Evidencia técnica
- `unimas/classes/ai_agent.php`:
  - antes: `json_encode($student_data, ...)` directo en flujo individual.
  - caché previa por `courseid + week + student_id`, sin dimensión de idioma.
- `unimas/index.php`:
  - primera carga de IA se hacía sin enviar `lang`.

### Impacto
- Sobreexposición de datos personales/sensibles al proveedor externo.
- Riesgo de respuesta en idioma equivocado según quién calentó la caché primero.

### Fix aplicado
- Minimización de payload (DTO explícito) en `ai_agent.php`:
  - Nuevo builder individual: `build_student_ai_payload(...)`.
  - Nuevo builder global: `build_global_ai_payload(...)`.
  - Se excluye por defecto del envío al LLM:
    - `name`
    - `email`
    - `action`
    - `note`
    - `hist` completo crudo
    - texto libre completo de `ctx`
  - Se envían solo señales mínimas:
    - `sid`/`uid` seudonimizado para el prompt
    - `level`, `is`, `delta`, `I_A`, `I_R`, `I_E`, `no_info`
    - `trend` resumida
    - `ctx_signal` acotada (`ctx_present` y señal de solicitud de contacto)
- Seudonimización en flujo global:
  - Al prompt se envían `uid` técnicos (`S1`, `S2`, ...).
  - Al volver la respuesta, se remapea a `uid` real interno con `remap_recommendation_uids(...)`.
- Separación de caché por idioma sin migración de schema:
  - Se implementó contenedor `__lang_cache` dentro del campo `recommendation`.
  - Lectura por idioma con `get_cached_lang_response(...)`.
  - Escritura por idioma con `upsert_lang_cache(...)`.
  - Compatibilidad retroactiva: caché antigua se interpreta como `es`.
- Idioma en primera carga:
  - `index.php` ahora llama `get_recommendations(...)` con `current_language()`.
  - Se normalizan códigos (`pt_br` -> `pt-br`) con `normalize_lang(...)`.
- Mensaje “sin estudiantes en atención” también se devuelve por idioma.

### Explicación y fix sencillo
- Antes se mandaban más datos de los necesarios a la IA y eso subía el riesgo de privacidad.
- Ahora se envía solo lo mínimo para análisis pedagógico, con IDs técnicos y sin datos personales directos.
- Además, la caché ahora distingue idioma para que cada usuario reciba respuesta en su lengua.

### Validación
- `php -l` OK en:
  - `unimas/classes/ai_agent.php`
  - `unimas/index.php`

### Estado
- `Resuelto`.

### Nota operativa posterior
- Se detectó una excepción en runtime por tipado estricto de caché:
  - `get_cached_lang_response(): Argument #1 must be of type ?stdClass, bool given`.
- Causa:
  - Moodle `get_record(...)` puede devolver `false` cuando no hay fila.
- Corrección aplicada:
  - Se relajó la firma de helpers de caché para aceptar el valor real devuelto por Moodle (`stdClass|false|null`) y manejarlo internamente de forma segura.

---

## 11.2) Integridad en carga masiva de contexto (transacción DB)

### Problema observado
- La carga masiva (`bulk_context`) hacía múltiples upserts secuenciales sin transacción.

### Evidencia técnica
- `unimas/classes/action_handler.php`, método `handle_bulk_context(...)`.
- Antes: loop directo de `update/insert` por fila sin bloque transaccional.

### Impacto
- Si una fila falla en mitad del proceso, el curso podía quedar con estado parcial (algunos estudiantes actualizados y otros no).

### Fix aplicado
- Se envolvió la operación completa en `start_delegated_transaction()`.
- Se hace `allow_commit()` solo si todo el lote termina correctamente.
- Ante excepción:
  - rollback de la transacción
  - respuesta AJAX de error controlado (`Error al guardar carga masiva de contexto.`)

### Explicación y fix sencillo
- Antes, si se caía una parte de la carga, quedaban cambios “a medias”.
- Ahora la carga es “todo o nada”: o se guarda todo el lote, o no se guarda nada.

### Validación
- `php -l` OK en `unimas/classes/action_handler.php`.

### Estado
- `Resuelto`.

---

## 11.3) Script de reseteo + seed demo global (creado y ejecutado)

### Objetivo
- Limpiar estado funcional del plugin y recrear usuarios demo para pruebas repetibles.

### Script agregado
- `unimas/cli/reset_demo_data.php`

### Qué hace
- Borra datos de:
  - `local_unimas_indicators`
  - `local_unimas_actions`
  - `local_unimas_context`
  - `local_unimas_ai_cache`
- Activa `allowaccountssameemail=1` para permitir escenario de prueba con correo duplicado.
- Elimina usuarios demo previos (si existen) por username/email.
- Crea usuarios globales:
  - `demo1` -> `Estudiante Demo 1` -> `student1@example.com`
  - `demo2` -> `Estudiante Demo 2` -> `student2@example.com`
  - `demo3` -> `Estudiante Demo 3` -> `student3@example.com`
  - `demo4` -> `Estudiante Demo 4` -> `student4@example.com`

### Comando de ejecución
- `php local/unimas/cli/reset_demo_data.php --force --password='Demo123!'`
- Atajo en Makefile:
  - `make reset-demo`
  - `make reset-demo DEMO_PASSWORD='TuPass123!'`

### Resultado verificado
- Script ejecutado en contenedor Moodle con éxito.
- Usuarios creados y visibles en DB con los datos solicitados.

### Explicación y fix sencillo
- Se dejó un “botón de reinicio” por línea de comandos para partir de cero y volver a crear usuarios demo en segundos.
- Esto evita configurar todo a mano cada vez que se quiere probar flujo de Excel/seguimiento.

### Estado
- `Resuelto / disponible para uso recurrente`.

---

## 11.4) Integridad referencial con llaves foráneas (FK) en base y upgrade

### Problema observado
- Las tablas del plugin se creaban sin llaves foráneas explícitas a `course`/`user`.

### Impacto
- Mayor riesgo de basura referencial a mediano plazo (filas huérfanas), mantenimiento manual y consultas defensivas adicionales.

### Fix aplicado
- Se agregaron FK en esquema base (`install.xml`) para:
  - `local_unimas_indicators`: `courseid`, `userid`
  - `local_unimas_actions`: `courseid`, `userid`, `authorid`
  - `local_unimas_context`: `courseid`, `userid`
  - `local_unimas_ai_cache`: `courseid`, `student_id`
- Se agregó etapa de upgrade para instancias ya instaladas:
  - `db/upgrade.php` con savepoint `2026060101`
  - actualización de `version.php` a `2026060101` (`release 0.2.1`)

### Incidencia detectada en upgrade y corrección
- Error encontrado al actualizar:
  - `Call to undefined method database_manager::key_exists()`
- Causa:
  - algunas versiones de Moodle no exponen `key_exists()` en `database_manager`.
- Corrección:
  - se reemplazó por estrategia compatible:
    - uso de `find_key_name()` cuando existe
    - fallback idempotente con `try/catch` en `add_key()` para ignorar duplicados de llave ya existente.

### Explicación y fix sencillo
- Dejamos la base “bien amarrada” para que no se guarden relaciones rotas entre cursos/usuarios y tablas del plugin.
- También se corrigió el proceso de actualización para que funcione en versiones de Moodle que no tienen ciertos métodos internos.

### Validación
- `php -l` OK en:
  - `unimas/db/upgrade.php`
  - `unimas/version.php`

### Estado
- `Resuelto`.

---

## 12) Hallazgos abiertos (documentados, no intervenidos en esta ronda)

### 12.1) Inconsistencia de versión mínima Moodle (documentación vs metadata)

#### Problema observado
- La documentación funcional y la metadata técnica no dicen lo mismo sobre la versión mínima soportada.

#### Evidencia técnica
- `unimas/README.md` línea 13: declara Moodle `4.5+`.
- `unimas/version.php` línea 12: `requires = 2022112800` (Moodle `4.1`).

#### Impacto
- Confusión para despliegue, soporte y expectativas del equipo TI.
- Riesgo de instalar en versiones no validadas o descartar versiones realmente compatibles.

#### Decisión en esta ronda
- No se modificó código ni documentación todavía (se deja como punto abierto para definir oficialmente).

#### Recomendación técnica
- Definir “fuente de verdad”:
  - si el plugin realmente soporta 4.1, ajustar README.
  - si solo debe correr en 4.5+, subir `requires` en `version.php`.
- Acompañar con una matriz breve de pruebas por versión Moodle.

#### Explicación y fix sencillo
- Hoy el manual dice una cosa y el sistema otra sobre la versión mínima.
- Para evitar confusiones, hay que unificar ambos lados con una sola versión oficial.

#### Estado
- `Abierto (pendiente decisión de compatibilidad objetivo)`.

---

### 12.2) Capability declarada pero no usada como puerta de acceso principal

#### Problema observado
- Existe capability propia del plugin, pero el acceso real al panel se controla con otra capability de curso.

#### Evidencia técnica
- `unimas/db/access.php` línea 16: capability `local/unimas:view`.
- `unimas/index.php` línea 23: acceso real vía `require_capability('moodle/course:manageactivities', $context)`.

#### Impacto
- Modelo de autorización difícil de administrar:
  - se declara un permiso “oficial” del plugin, pero no gobierna realmente el acceso.
  - obliga a usar permisos más amplios de los necesarios.

#### Decisión en esta ronda
- No se cambió autorización base en código en esta iteración.

#### Recomendación técnica
- Definir modelo único:
  - opción A: usar `local/unimas:view` como guard principal del panel y acciones de lectura.
  - opción B: mantener `manageactivities`, pero eliminar capability no usada para evitar deuda.
- Separar capacidades por operación:
  - ver panel
  - cargar contexto
  - forzar regeneración IA
  - configurar IA global (ya restringido a `moodle/site:config`).

#### Explicación y fix sencillo
- Se creó un permiso propio, pero el panel no lo usa para decidir quién entra.
- Hay que elegir un solo esquema de permisos para que administración y auditoría sean claras.

#### Estado
- `Abierto (pendiente decisión de modelo de permisos)`.

---

### 12.3) Flujo RAG/export expuesto con implementación parcial (stubs)

#### Problema observado
- Hay rutas/acciones de sync-ingest-export visibles en handler, pero parte del backend está en modo stub/no-op.

#### Evidencia técnica
- `unimas/classes/data_provider.php`:
  - `get_course_files` stub en líneas ~341-350.
  - `create_assign_activity` stub en líneas ~365-370.
- `unimas/classes/action_handler.php`:
  - acciones `sync`, `ingest`, `export` expuestas en `handle()` líneas ~85-97.
  - handlers de flujo RAG/export activos en líneas ~131-190.

#### Impacto
- En producción puede parecer que la funcionalidad existe, pero devolver resultados parciales o vacíos.
- Mayor riesgo de soporte por expectativas no cumplidas.

#### Decisión en esta ronda
- No se removieron rutas ni se completó implementación RAG/export.

#### Recomendación técnica
- Elegir una de estas vías:
  - opción A: ocultar/deshabilitar acciones hasta completar integración real.
  - opción B: completar implementación end-to-end con validaciones y mensajes de estado claros.
- Si se mantiene temporalmente:
  - agregar banner explícito “funcionalidad en desarrollo/no disponible”.

#### Explicación y fix sencillo
- Hay botones/rutas de funciones avanzadas que todavía están a medio camino.
- Para evitar confusión, o se terminan bien o se ocultan hasta que estén listas.

#### Estado
- `Abierto (pendiente decisión de producto y alcance técnico)`.

---

## 13) Resumen ejecutivo de pendientes priorizados

### Pendiente alto (definir pronto)
- Unificar versión mínima soportada entre `README.md` y `version.php`.
- Definir modelo de permisos principal del plugin (`local/unimas:view` vs `moodle/course:manageactivities`).
- Decidir estrategia final de funcionalidades RAG/export (completar o ocultar).

### Pendiente medio (mejora de robustez)
- Duplicados de correo en carga Excel: hoy no bloquea por defecto, depende de la regla operacional del curso.

### Fuera de alcance actual (aceptado temporalmente)
- Valores de fallback inicial en indicadores de estudiantes nuevos (se documentó comportamiento, no se cambió lógica).
