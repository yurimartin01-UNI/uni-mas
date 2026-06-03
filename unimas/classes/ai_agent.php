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
    private const SUPPORTED_PROVIDERS = ['gemini', 'openai', 'anthropic', 'deepseek', 'custom'];
    private const SUPPORTED_LANGS = ['es', 'en', 'pt-br', 'gl'];
    private const CACHE_TTL_SECONDS = 43200;

    private static function normalize_provider(string $provider): string {
        $p = strtolower(trim($provider));
        switch ($p) {
            case 'google':
                return 'gemini';
            case 'claude':
                return 'anthropic';
            default:
                return $p;
        }
    }

    private static function normalize_lang(string $lang): string {
        $normalized = strtolower(trim(str_replace('_', '-', $lang)));
        if (in_array($normalized, self::SUPPORTED_LANGS, true)) {
            return $normalized;
        }
        return 'es';
    }

    private static function get_cache_payload($cached): array {
        if (!$cached || empty($cached->recommendation)) {
            return ['__lang_cache' => []];
        }

        $decoded = json_decode($cached->recommendation, true);
        if (!is_array($decoded)) {
            return ['__lang_cache' => []];
        }

        if (isset($decoded['__lang_cache']) && is_array($decoded['__lang_cache'])) {
            return $decoded;
        }

        // Backward compatibility for old cache format without language separation.
        return ['__lang_cache' => ['es' => ['ts' => (int)$cached->timecreated, 'response' => $decoded]]];
    }

    private static function get_cached_lang_response($cached, string $lang): ?array {
        $payload = self::get_cache_payload($cached);
        $entry = $payload['__lang_cache'][$lang] ?? null;
        if (!is_array($entry) || !isset($entry['response'])) {
            return null;
        }

        $ts = (int)($entry['ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        return is_array($entry['response']) ? $entry['response'] : null;
    }

    private static function upsert_lang_cache(int $courseid, int $week, ?int $studentid, string $lang, array $response, $cached): void {
        global $DB;

        $cachepayload = self::get_cache_payload($cached);
        $cachepayload['__lang_cache'][$lang] = [
            'ts' => time(),
            'response' => $response,
        ];

        $record = new stdClass();
        $record->courseid = $courseid;
        $record->week = $week;
        $record->student_id = $studentid;
        $record->recommendation = json_encode($cachepayload, JSON_UNESCAPED_UNICODE);
        $record->timecreated = time();

        if ($cached) {
            $record->id = $cached->id;
            $DB->update_record('local_unimas_ai_cache', $record);
        } else {
            $DB->insert_record('local_unimas_ai_cache', $record);
        }
    }

    private static function build_survey_signal($ctx): array {
        if (empty($ctx) || !is_string($ctx)) {
            return ['ctx_present' => false, 'contact_request' => 'unknown'];
        }

        $signal = ['ctx_present' => true, 'contact_request' => 'unknown'];
        $decoded = json_decode($ctx, true);
        if (!is_array($decoded)) {
            return $signal;
        }

        foreach ($decoded as $question => $answer) {
            $q = mb_strtolower(trim((string)$question));
            if (strpos($q, 'ponga en contacto') !== false || strpos($q, 'contacto contigo') !== false) {
                $ans = mb_strtolower(trim((string)$answer));
                if ($ans === 'si' || $ans === 'sí' || strpos($ans, 'si') === 0 || strpos($ans, 'sí') === 0) {
                    $signal['contact_request'] = 'yes';
                } else if ($ans === 'no' || strpos($ans, 'no') === 0) {
                    $signal['contact_request'] = 'no';
                } else {
                    $signal['contact_request'] = 'other';
                }
                break;
            }
        }

        return $signal;
    }

    private static function build_trend_summary($hist, float $delta): array {
        $values = [];
        if (is_array($hist)) {
            foreach ($hist as $v) {
                if (is_numeric($v)) {
                    $values[] = round((float)$v, 2);
                }
            }
        }

        $last4 = array_slice($values, -4);
        return [
            'recent_values' => $last4,
            'delta' => round($delta, 2),
        ];
    }

    private static function build_student_ai_payload(array $studentdata, int $courseid): array {
        $uid = (int)($studentdata['uid'] ?? 0);
        $comps = is_array($studentdata['comps'] ?? null) ? $studentdata['comps'] : [];
        $ctxsignal = self::build_survey_signal($studentdata['ctx'] ?? null);

        return [
            'sid' => 'S-' . $courseid . '-' . $uid,
            'level' => (string)($studentdata['level'] ?? 'nodata'),
            'is' => isset($studentdata['is']) && is_numeric($studentdata['is']) ? round((float)$studentdata['is'], 2) : null,
            'delta' => isset($studentdata['delta']) && is_numeric($studentdata['delta']) ? round((float)$studentdata['delta'], 2) : 0.0,
            'I_A' => isset($comps['ent']) ? (float)$comps['ent'] : 0.0,
            'I_R' => isset($comps['rend']) ? (float)$comps['rend'] : 0.0,
            'I_E' => isset($comps['act']) ? (float)$comps['act'] : 0.0,
            'no_info' => !empty($studentdata['no_info']),
            'trend' => self::build_trend_summary($studentdata['hist'] ?? [], isset($studentdata['delta']) ? (float)$studentdata['delta'] : 0.0),
            'ctx_signal' => $ctxsignal,
        ];
    }

    private static function build_global_ai_payload(array $students, int $courseid): array {
        $payload = [];
        $sidmap = [];
        $counter = 1;

        foreach ($students as $s) {
            $isideal = (($s['level'] ?? '') === 'norm' && ((float)($s['delta'] ?? 0)) >= 0);
            if ($isideal) {
                continue;
            }

            $realuid = (int)($s['uid'] ?? 0);
            $sid = 'S' . $counter++;
            $sidmap[$sid] = $realuid;
            $comps = is_array($s['comps'] ?? null) ? $s['comps'] : [];

            $payload[] = [
                'uid' => $sid,
                'level' => (string)($s['level'] ?? 'nodata'),
                'is' => isset($s['is']) && is_numeric($s['is']) ? round((float)$s['is'], 2) : null,
                'delta' => isset($s['delta']) && is_numeric($s['delta']) ? round((float)$s['delta'], 2) : 0.0,
                'I_A' => isset($comps['ent']) ? (float)$comps['ent'] : 0.0,
                'I_R' => isset($comps['rend']) ? (float)$comps['rend'] : 0.0,
                'I_E' => isset($comps['act']) ? (float)$comps['act'] : 0.0,
                'no_info' => !empty($s['no_info']),
                'ctx_signal' => self::build_survey_signal($s['ctx'] ?? null),
                'course_ref' => 'C-' . $courseid,
            ];
        }

        return [$payload, $sidmap];
    }

    private static function remap_recommendation_uids(array $response, array $sidmap): array {
        if (!isset($response['recommendations']) || !is_array($response['recommendations'])) {
            return $response;
        }

        foreach ($response['recommendations'] as &$rec) {
            $sid = isset($rec['uid']) ? (string)$rec['uid'] : '';
            if ($sid !== '' && isset($sidmap[$sid])) {
                $rec['uid'] = $sidmap[$sid];
            }
        }
        unset($rec);

        return $response;
    }

    private static function get_no_attention_message(string $lang): string {
        $messages = [
            'es' => 'Todos los estudiantes muestran un rendimiento ideal y tendencia positiva. ¡Buen trabajo!',
            'en' => 'All students show strong performance and a positive trend. Great work!',
            'pt-br' => 'Todos os estudantes apresentam bom desempenho e tendência positiva. Ótimo trabalho!',
            'gl' => 'Todo o estudantado amosa bo rendemento e tendencia positiva. Bo traballo!',
        ];
        return $messages[$lang] ?? $messages['es'];
    }

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
        $lang = self::normalize_lang((string)$lang);

        $provider = get_config('local_unimas', 'ai_provider') ?: 'gemini';
        $apikey = get_config('local_unimas', 'api_key');

        if (empty($apikey))
            return ['error' => 'API Key no configurada'];

        // 1. Check specific student cache
        $cached = $DB->get_record('local_unimas_ai_cache', ['courseid' => $courseid, 'week' => $week, 'student_id' => $userid]);
        $cachedresponse = self::get_cached_lang_response($cached, $lang);
        if ($cachedresponse !== null) {
            return $cachedresponse;
        }

        // 2. Build targeted prompt
        $minimalstudent = self::build_student_ai_payload($student_data, (int)$courseid);
        $student_json = json_encode($minimalstudent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt = self::build_individual_prompt($week, $coursename, $student_json, $lang);

        // 3. Call AI
        $response = self::call_ai_provider($prompt, $provider, $apikey);

        // 4. Cache
        if (!isset($response['error'])) {
            self::upsert_lang_cache((int)$courseid, (int)$week, (int)$userid, $lang, $response, $cached);
        }

        return $response;
    }

    public static function get_recommendations($courseid, $week, $dashboard_data, $lang = 'es')
    {
        global $DB;
        $lang = self::normalize_lang((string)$lang);

        $provider = get_config('local_unimas', 'ai_provider') ?: 'gemini';
        $apikey = get_config('local_unimas', 'api_key');

        if (empty($apikey)) {
            return ['error' => 'Falta configurar la API Key en los ajustes del plugin. Haz clic en el ícono de engranaje para configurar tu proveedor.'];
        }

        // 1. Check cache first
        // We might want to clear cache if provider changes, but handled in action_handler
        $cached = $DB->get_record('local_unimas_ai_cache', ['courseid' => $courseid, 'week' => $week, 'student_id' => null]);
        $cachedresponse = self::get_cached_lang_response($cached, $lang);
        if ($cachedresponse !== null) {
            return $cachedresponse;
        }

        // 2. Build minimized payload (only students requiring attention + pseudonymous IDs).
        [$target_students, $sidmap] = self::build_global_ai_payload($dashboard_data['students'], (int)$courseid);

        if (empty($target_students)) {
            return [
                'global' => self::get_no_attention_message($lang),
                'recommendations' => []
            ];
        }

        // 3. Prepare Prompt
        $prompt = self::build_prompt($week, $dashboard_data['name'], $target_students, $lang);

        // 4. Call Universal AI Adapter
        $response = self::call_ai_provider($prompt, $provider, $apikey);
        if (!isset($response['error'])) {
            $response = self::remap_recommendation_uids($response, $sidmap);
        }

        // 5. Store in cache if successful
        if (!isset($response['error'])) {
            self::upsert_lang_cache((int)$courseid, (int)$week, null, $lang, $response, $cached);
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
        $provider = self::normalize_provider((string)$provider);
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return ['error' => 'Proveedor de IA no soportado: ' . $provider];
        }

        $model = get_config('local_unimas', 'ai_model') ?: 'gemini-1.5-flash';
        $baseurl = get_config('local_unimas', 'ai_base_url');

        switch ($provider) {
            case 'gemini':
                return self::call_gemini($prompt, $model, $apikey);
            case 'anthropic':
                return self::call_anthropic($prompt, $model, $apikey);
            case 'custom':
                return self::call_openai_compatible($prompt, $model, $apikey, $baseurl);
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
