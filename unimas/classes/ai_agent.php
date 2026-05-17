<?php
namespace local_unimas;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Agent for Uni_mas (local_unimas) - Universal Adapter with Claude Support
 * 
 * @package    local_unimas
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_agent
{

    /**
     * Get recommendations from the configured AI provider
     * 
     * @param int $courseid
     * @param int $week
     * @param array $dashboard_data
     * @return array
     */
    /**
     * Get a recommendation for a single specific student (On-Demand)
     */
    public static function get_student_recommendation($courseid, $userid, $student_data, $week, $coursename, $lang = 'es')
    {
        global $DB;

        $provider = get_config('local_unimas', 'ai_provider') ?: 'gemini';
        $apikey = get_config('local_unimas', 'api_key');

        if (empty($apikey))
            return ['error' => 'API Key no configurada'];

        // 1. Check specific student cache
        $cached = $DB->get_record('local_unimas_ai_cache', ['courseid' => $courseid, 'week' => $week, 'student_id' => $userid]);
        if ($cached && (time() - $cached->timecreated < 43200)) {
            return json_decode($cached->recommendation, true);
        }

        // 2. Build targeted prompt
        $student_json = json_encode($student_data, JSON_PRETTY_PRINT);
        $prompt = self::build_individual_prompt($week, $coursename, $student_json, $lang);

        // 3. Call AI
        $response = self::call_ai_provider($prompt, $provider, $apikey);

        // 4. Cache
        if (!isset($response['error'])) {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->week = $week;
            $record->student_id = (int) $userid; // Explicit cast to int
            $record->recommendation = json_encode($response);
            $record->timecreated = time();

            error_log("local_unimas: Saving AI report for student " . $record->student_id . " week " . $record->week);

            if ($cached) {
                $record->id = $cached->id;
                $DB->update_record('local_unimas_ai_cache', $record);
            } else {
                $DB->insert_record('local_unimas_ai_cache', $record);
            }
        }

        return $response;
    }

    public static function get_recommendations($courseid, $week, $dashboard_data, $lang = 'es')
    {
        global $DB;

        $provider = get_config('local_unimas', 'ai_provider') ?: 'gemini';
        $apikey = get_config('local_unimas', 'api_key');

        if (empty($apikey)) {
            return ['error' => 'Falta configurar la API Key en los ajustes del plugin. Haz clic en el ícono de engranaje para configurar tu proveedor.'];
        }

        // 1. Check cache first
        // We might want to clear cache if provider changes, but handled in action_handler
        $cached = $DB->get_record('local_unimas_ai_cache', ['courseid' => $courseid, 'week' => $week, 'student_id' => null]);
        if ($cached && (time() - $cached->timecreated < 43200)) { // 12h cache
            return json_decode($cached->recommendation, true);
        }

        // 2. Filter students according to Uni_mas logic (only those needing attention)
        $target_students = [];
        foreach ($dashboard_data['students'] as $s) {
            $is_ideal = ($s['level'] === 'norm' && $s['delta'] >= 0);
            if (!$is_ideal) {
                $target_students[] = [
                    'uid' => (int) $s['uid'],
                    'name' => $s['name'],
                    'level' => $s['level'],
                    'is' => (float) $s['is'],
                    'delta' => (float) $s['delta'],
                    'I_A' => $s['comps']['ent'],  // Entregas
                    'I_R' => $s['comps']['rend'], // Rendimiento
                    'I_E' => $s['comps']['act'],  // Enganche
                    'ctx' => $s['ctx'],
                    'no_info' => $s['no_info']
                ];
            }
        }

        if (empty($target_students)) {
            return [
                'global' => 'Todos los estudiantes muestran un rendimiento ideal y tendencia positiva. ¡Buen trabajo!',
                'recommendations' => []
            ];
        }

        // 3. Prepare Prompt
        $prompt = self::build_prompt($week, $dashboard_data['name'], $target_students, $lang);

        // 4. Call Universal AI Adapter
        $response = self::call_ai_provider($prompt, $provider, $apikey);

        // 5. Store in cache if successful
        if (!isset($response['error'])) {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->week = $week;
            $record->student_id = null;
            $record->recommendation = json_encode($response);
            $record->timecreated = time();

            if ($cached) {
                $record->id = $cached->id;
                $DB->update_record('local_unimas_ai_cache', $record);
            } else {
                $DB->insert_record('local_unimas_ai_cache', $record);
            }
        }

        return $response;
    }

    private static function build_individual_prompt($week, $coursename, $student_json, $lang = 'es')
    {
        $langNames = ['es' => 'Español', 'en' => 'Inglés', 'pt-br' => 'Portugués de Brasil (BR)', 'gl' => 'Gallego'];
        $langName = $langNames[$lang] ?? 'Español';
        $date = date('d/m/Y H:i');

        return "# Prompt: Agente de priorización de estudiantes UNI+

## Rol y propósito
Eres un asistente de apoyo a la toma de decisiones docentes en educación superior. Tu función es mapear y categorizar estudiantes según su nivel de prioridad de atención, explicar la lógica de cada clasificación y orientar posibles líneas de intervención pedagógica o derivación institucional.

> **Principio fundamental:** No tomas decisiones ni prescribes acciones cerradas. Toda acción concreta corresponde al criterio profesional del docente. Tu rol es informar, contextualizar y sugerir — nunca decidir.

---

## 1. Datos de entrada esperados
Recibes datos en tiempo real de Moodle por cada estudiante. Estamos analizando al estudiante en el curso '$coursename', semana $week.

### 1.1 Datos de actividad en Moodle (fuente principal)
$student_json

---

## 2. Interpretación contextual por patrón
Complementa el análisis con la lectura del patrón observado. Usa los datos del formulario solo para enriquecer la interpretación.

| Patrón | I_A | I_R | I_E | Lectura orientadora |
|---|:---:|:---:|:---:|---|
| **P1** | 0 | 0 | 0 | Posible desvinculación académica intensa o sostenida. Señal fuerte si persiste ≥ 2 periodos consecutivos. |
| **P2** | 0 | 0 | 1 | Brecha entre presencia y producción. Posibles dificultades de activación, bloqueo ante la evaluación, o factores externos que no impiden asistir pero sí completar tareas. |
| **P3** | 1 | 0 | 1 | Compromiso sin rendimiento. Posible necesidad de apoyo pedagógico, ajuste metodológico o refuerzo en comprensión de contenidos y criterios evaluativos. |
| **P4** | 1 | 1 | 0 | Baja presencia con buen rendimiento. Puede reflejar factores estructurales (trabajo, cuidados, acceso). No equivale automáticamente a riesgo alto. |

> Cada lectura es orientadora, no determinística. Formula siempre las sugerencias como \"Se podría considerar…\" o \"Podría valorarse…\"

---

## 3. Consideraciones temporales
- Una variación puntual **no equivale** a una tendencia sostenida — interpreta con prudencia.
- El mismo patrón durante **≥ 2 periodos consecutivos** aumenta la prioridad de seguimiento.
- La **mejora sostenida** en cualquier indicador modera el riesgo, incluso si el IS sigue en zona de atención.
- La **caída progresiva** de notas aumenta el riesgo; la recuperación sostenida lo reduce.
- Evita repetir la misma orientación si el patrón ya fue detectado y comunicado previamente.

---

## 4. Restricciones
- ❌ No uses ni menciones datos sensibles: género, renta, raza, religión, origen, discapacidad.
- ❌ No asumas intenciones, actitudes ni causas personales sin evidencia objetiva.
- ❌ No uses lenguaje punitivo, controlador ni etiquetas determinísticas sobre el estudiante.
- ❌ No prescribas acciones cerradas: toda decisión final corresponde al docente.
- ❌ No repitas orientaciones ya registradas sin agregar nueva perspectiva.
- ✅ Distingue siempre entre señales de desvinculación, dificultad académica y condicionantes estructurales.
- ✅ Explica siempre la clasificación de prioridad con la lógica de los indicadores.

---

## 5. Estructura de salida obligatoria (JSON Estricto)

Debes responder exclusivamente con un objeto JSON:
{
  \"summary\": \"Resumen de 1 línea del estado y patrón principal.\",
  \"full_report\": \"[Genera aquí el informe siguiendo estas estrictas instrucciones:]\\n\\nGenera siempre la respuesta en prosa continua, sin listas ni bullets. Usa un tono directo, humano y orientado al docente. La salida tiene tres bloques, en este orden:\\n\\n### Bloque 1 — Interpretación contextual (sin título visible)\\nRedacta un párrafo continuo que describa la situación del estudiante integrando: la trayectoria temporal del IS; los indicadores actuales y qué combinación de patrón representan; una hipótesis orientadora sobre qué podría estar ocurriendo, usando lenguaje condicional ('podría indicar', 'esto sugiere', 'no es posible afirmar sin más información'); si hay datos del formulario disponibles, incorpóralos para matizar la lectura sin que reemplacen los indicadores objetivos.\\n\\n### Bloque 2 — Línea de acción sugerida (solo cuando el nivel es Atención o Prioritario)\\nEncabezado en mayúsculas: **UNA POSIBLE LÍNEA DE ACCIÓN**\\nRedacta una sugerencia concreta y acotada de primer contacto o intervención inicial. Debe ser: específica y accionable (qué hacer, cómo, con qué tono); no evaluativa ni punitiva; formulada como posibilidad, no como obligación. (Omite este bloque si el nivel es Normal).\\n\\n### Bloque 3 — Factores a explorar\\nEncabezado en mayúsculas: **QUÉ CONVIENE EXPLORAR ANTES DE INTERVENIR**\\nRedacta dos preguntas concretas que el docente debería hacerse o indagar antes de tomar una decisión. Deben estar formuladas en segunda persona implícita (¿Hubo...? ¿Está...? ¿Existen...?) y orientadas a verificar hipótesis, no a juzgar al estudiante.\\n\\nCierra siempre con esta frase fija, en párrafo aparte:\\n\\n> _Este análisis es una interpretación basada en datos. No es un diagnóstico. La decisión siempre es del docente. (Análisis generado el $date)_\"
}
 
> **Tono general:** claro, objetivo, explicativo y no determinístico. Usa siempre lenguaje condicional: \"podría\", \"se observa\", \"sugiere\". Escribe en prosa, nunca en listas.

> **IMPORTANTE Y MANDATORIO:** EL ANÁLISIS COMPLETO (SÍNTESIS Y EL REPORTE COMPLETO EN `full_report`) DEBE REDACTARSE EXCLUSIVAMENTE EN IDIOMA **$langName**.";
    }

    private static function build_prompt($week, $coursename, $students, $lang = 'es')
    {
        $langNames = ['es' => 'Español', 'en' => 'Inglés', 'pt-br' => 'Portugués de Brasil (BR)', 'gl' => 'Gallego'];
        $langName = $langNames[$lang] ?? 'Español';
        $date = date('d/m/Y H:i');

        $student_context = json_encode($students, JSON_PRETTY_PRINT);

        return "# Prompt: Agente de priorización de estudiantes UNI+

## Rol y propósito
Eres un asistente de apoyo a la toma de decisiones docentes en educación superior. Tu función es mapear y categorizar estudiantes según su nivel de prioridad de atención, explicar la lógica de cada clasificación y orientar posibles líneas de intervención pedagógica o derivación institucional.

> **Principio fundamental:** No tomas decisiones ni prescribes acciones cerradas. Toda acción concreta corresponde al criterio profesional del docente. Tu rol es informar, contextualizar y sugerir — nunca decidir.

---

## 1. Datos de entrada esperados
Recibes datos en tiempo real de Moodle por cada estudiante. Estamos analizando el curso '$coursename', semana $week.

### 1.1 Datos de actividad en Moodle (fuente principal)
$student_context

---

## 2. Interpretación contextual por patrón
Complementa el análisis con la lectura del patrón observado. Usa los datos del formulario solo para enriquecer la interpretación.

| Patrón | I_A | I_R | I_E | Lectura orientadora |
|---|:---:|:---:|:---:|---|
| **P1** | 0 | 0 | 0 | Posible desvinculación académica intensa o sostenida. Señal fuerte si persiste ≥ 2 periodos consecutivos. |
| **P2** | 0 | 0 | 1 | Brecha entre presencia y producción. Posibles dificultades de activación, bloqueo ante la evaluación, o factores externos que no impiden asistir pero sí completar tareas. |
| **P3** | 1 | 0 | 1 | Compromiso sin rendimiento. Posible necesidad de apoyo pedagógico, ajuste metodológico o refuerzo en comprensión de contenidos y criterios evaluativos. |
| **P4** | 1 | 1 | 0 | Baja presencia con buen rendimiento. Puede reflejar factores estructurales (trabajo, cuidados, acceso). No equivale automáticamente a riesgo alto. |

> Cada lectura es orientadora, no determinística. Formula siempre las sugerencias como \"Se podría considerar…\" o \"Podría valorarse…\"

---

## 3. Consideraciones temporales
- Una variación puntual **no equivale** a una tendencia sostenida — interpreta con prudencia.
- El mismo patrón durante **≥ 2 periodos consecutivos** aumenta la prioridad de seguimiento.
- La **mejora sostenida** en cualquier indicador modera el riesgo, incluso si el IS sigue en zona de atención.
- La **caída progresiva** de notas aumenta el riesgo; la recuperación sostenida lo reduce.
- Evita repetir la misma orientación si el patrón ya fue detectado y comunicado previamente.

---

## 4. Restricciones
- ❌ No uses ni menciones datos sensibles: género, renta, raza, religión, origen, discapacidad.
- ❌ No asumas intenciones, actitudes ni causas personales sin evidencia objetiva.
- ❌ No uses lenguaje punitivo, controlador ni etiquetas determinísticas sobre el estudiante.
- ❌ No prescribas acciones cerradas: toda decisión final corresponde al docente.
- ❌ No repitas orientaciones ya registradas sin agregar nueva perspectiva.
- ✅ Distingue siempre entre señales de desvinculación, dificultad académica y condicionantes estructurales.
- ✅ Explica siempre la clasificación de prioridad con la lógica de los indicadores.

---

## 5. Estructura de salida obligatoria (JSON Estricto)
 
Debes responder exclusivamente con un objeto JSON siguiendo esta estructura exacta:
{
  \"global\": \"[Resumen macro de la clase: tendencias, porcentaje de participación general y alertas principales. Máximo 60 palabras, enfocado a la toma de decisión del docente. Al final agrega textualmente: (Análisis generado el $date)]\",
  \"recommendations\": [
    {
      \"uid\": \"[UID exacto recibido]\",
      \"summary\": \"[Resumen del nivel de prioridad y patrón en 1 línea. Ej: 'Prioritario (P1): Ausencia prolongada en plataforma y baja entrega.']\",
      \"full_report\": \"[Análisis extendido: incluye justificación del patrón, matices basados en formulario si existen, y UNA línea potencial de contacto o acción. Finaliza SIEMPRE cada full_report con: > _Este análisis es una interpretación basada en datos. No es un diagnóstico. La decisión siempre es del docente. (Análisis generado el $date)_]\"
    }
  ]
}

> **Tono general:** claro, objetivo, explicativo y no determinístico. Usa siempre condicionales como \"podría\", \"se observa\", \"sugiere\". Nunca incluyas a estudiantes que no estén en la lista de entrada. Escribe en prosa, nunca en listas.
 
> **IMPORTANTE Y MANDATORIO:** EL ANÁLISIS COMPLETO (SÍNTESIS, INFORME GLOBAL Y REPORTES COMPLETOS) DEBE REDACTARSE EXCLUSIVAMENTE EN IDIOMA **$langName**.";
    }

    private static function call_ai_provider($prompt, $provider, $apikey)
    {
        $model = get_config('local_unimas', 'ai_model') ?: 'gemini-1.5-flash';
        $baseurl = get_config('local_unimas', 'ai_base_url');

        switch ($provider) {
            case 'gemini':
                return self::call_gemini($prompt, $model, $apikey);
            case 'anthropic':
                return self::call_anthropic($prompt, $model, $apikey);
            default:
                $endpoint = $baseurl ?: self::get_default_endpoint($provider);
                return self::call_openai_compatible($prompt, $model, $apikey, $endpoint);
        }
    }

    private static function get_default_endpoint($provider)
    {
        switch ($provider) {
            case 'openai':
                return 'https://api.openai.com/v1/chat/completions';
            case 'deepseek':
                return 'https://api.deepseek.com/chat/completions';
            case 'anthropic':
                return 'https://api.anthropic.com/v1/messages';
            default:
                return '';
        }
    }

    private static function call_gemini($prompt, $model, $apikey)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apikey}";
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['response_mime_type' => 'application/json']
        ];

        $res = self::post_json($url, $payload);
        if (isset($res['error']))
            return $res;

        if (isset($res['candidates'][0]['content']['parts'][0]['text'])) {
            return json_decode($res['candidates'][0]['content']['parts'][0]['text'], true);
        }
        return ['error' => 'La IA (Gemini) no devolvió un formato válido.'];
    }

    private static function call_openai_compatible($prompt, $model, $apikey, $url)
    {
        if (empty($url))
            return ['error' => 'URL de API no configurada.'];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Eres un asistente experto en educación que responde solo en JSON estricto.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $headers = ['Authorization: Bearer ' . $apikey, 'Content-Type: application/json'];
        $res = self::post_json($url, $payload, $headers);
        if (isset($res['error']))
            return $res;

        if (isset($res['choices'][0]['message']['content'])) {
            return json_decode($res['choices'][0]['message']['content'], true);
        }
        return ['error' => 'La IA no devolvió un formato compatible.'];
    }

    private static function call_anthropic($prompt, $model, $apikey)
    {
        $url = "https://api.anthropic.com/v1/messages";
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'messages' => [['role' => 'user', 'content' => $prompt . "\n\nResponde únicamente con el JSON solicitado."]]
        ];
        $headers = [
            'x-api-key: ' . $apikey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ];
        $res = self::post_json($url, $payload, $headers);
        if (isset($res['error']))
            return $res;

        if (isset($res['content'][0]['text'])) {
            $json_text = $res['content'][0]['text'];
            if (preg_match('/\{.*\}/s', $json_text, $matches))
                $json_text = $matches[0];
            return json_decode($json_text, true);
        }
        return ['error' => 'No se recibió respuesta de Anthropic.'];
    }

    private static function post_json($url, $payload, $headers = ['Content-Type: application/json'])
    {
        // Use Moodle's \curl class so it automatically inherits the proxy
        // configuration from $CFG->proxyhost / proxyport / proxyuser / proxypassword.
        $curl = new \curl();
        $curl->setHeader($headers);

        $response = $curl->post($url, json_encode($payload), [
            'CURLOPT_TIMEOUT' => 60,
        ]);

        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($curl->get_errno()) {
            return ['error' => 'Error de conexión: ' . $curl->error];
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            return ['error' => "HTTP $httpcode: " . ($response ?: 'Error')];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['error' => 'Respuesta inválida del proveedor de IA.'];
        }

        return $decoded;
    }
}
