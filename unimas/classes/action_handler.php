<?php
namespace local_unimas;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles the three server-side actions that redirect: sync, ingest, export.
 *
 * Each action processes data, then issues a Moodle redirect().
 * After calling handle(), the script never continues (redirect dies).
 */
class action_handler {
    /**
     * Canonical provider names accepted by backend.
     */
    private const ALLOWED_AI_PROVIDERS = ['gemini', 'openai', 'anthropic', 'deepseek', 'custom'];

    /**
     * Domains that are allowed as ai_base_url values.
     * Subdomains are also accepted (e.g. my.proxy.openai.com).
     * Extend this list when adding new approved providers.
     */
    private const ALLOWED_AI_DOMAINS = [
        'generativelanguage.googleapis.com', // Google Gemini
        'api.openai.com',                    // OpenAI
        'api.anthropic.com',                 // Anthropic Claude
        'api.deepseek.com',                  // DeepSeek
        'localhost',                         // Local models (dev)
        '127.0.0.1',                         // Local models (dev)
    ];

    /**
     * Validate that a URL's host belongs to the allowed AI domain whitelist.
     *
     * @param  string $url  The URL to check (may be empty).
     * @return bool         True if allowed or empty, false otherwise.
     */
    private static function validate_ai_url(string $url): bool {
        if (empty($url)) {
            return true; // Empty means "use default endpoint" — always OK.
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false; // Malformed URL.
        }

        $host = strtolower($parsed['host']);

        foreach (self::ALLOWED_AI_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize legacy/provider aliases to canonical names.
     */
    private static function normalize_ai_provider(string $provider): string {
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

    /**
     * Dispatch the given action. Returns false if the action is not recognized
     * (so the caller can continue rendering). On recognized actions, this method
     * never returns — it calls redirect() which dies.
     *
     * @param string     $action    One of: 'sync', 'ingest', 'export'
     * @param int        $course_id
     * @param \moodle_url $base_url  The current $PAGE->url
     * @param bool       $is_ajax
     * @return bool  false if action not handled
     */
    public static function handle(string $action, int $course_id, \moodle_url $base_url, bool $is_ajax): bool {
        switch ($action) {
            case 'sync':
                self::handle_sync($course_id, $base_url, $is_ajax);
                return true; // never reached

            case 'ingest':
                self::handle_ingest($course_id, $base_url, $is_ajax);
                return true;

            case 'export':
                self::handle_export($course_id, $base_url, $is_ajax);
                return true;

            case 'bulk_context':
                self::handle_bulk_context($course_id, $base_url, $is_ajax);
                return true;

            case 'save_ai_config':
                self::handle_save_ai_config($course_id, $base_url, $is_ajax);
                return true;

            case 'refresh_ai':
                self::handle_refresh_ai($course_id, $base_url, $is_ajax);
                return true;

            case 'generate_student_ai':
                self::handle_generate_student_ai($course_id, $base_url, $is_ajax);
                return true;

            case 'save_followup':
                self::handle_save_followup($course_id, $base_url, $is_ajax);
                return true;

            default:
                return false;
        }
    }

    // ------------------------------------------------------------------
    // Individual action handlers
    // ------------------------------------------------------------------

    /**
     * Sync course files to the Python service and redirect to Step 1.
     */
    private static function handle_sync(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        // Release session lock so nav/AJAX works while sync runs.
        if (method_exists('\core\session\manager', 'write_close')) {
            \core\session\manager::write_close();
        }

        // Extract files to sync dir + get summary
        data_provider::get_course_files($course_id, true);
        $summary = data_provider::get_course_summary($course_id);

        // POST /sync
        rag_client::sync($summary);

        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Trigger embedding ingestion and redirect to Step 1 with result status.
     */
    private static function handle_ingest(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        \core_php_time_limit::raise(600);

        $res_data = rag_client::ingest($course_id);

        // Determine ingestion state: 1=success, 2=empty, -1=error
        if ($res_data && $res_data->status == 'success') {
            $state = ($res_data->chunks > 0) ? 1 : 2;
        } else {
            $state = -1;
        }
        if ($res_data && $res_data->chunks == 0) {
            $state = 2;
        }

        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $state]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Export the generated instrument + rubric as a Moodle Assign activity.
     */
    private static function handle_export(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $inst_name = session_manager::get('instrument', '') . ' - Uni+';
        $final_desc = session_manager::get('inst_content', '');

        $rubric = session_manager::get('rubric_content', '');
        if (!empty($rubric)) {
            $final_desc .= "\n\n### Rúbrica\n" . $rubric;
        }

        if (!$inst_name) {
            $inst_name = 'Evaluación Uni+';
        }
        if (!$final_desc) {
            $final_desc = 'Instrumento generado por Uni+.';
        }

        $moduleinfo = data_provider::create_assign_activity($course_id, $inst_name, $final_desc);

        $redir = new \moodle_url($base_url, [
            'step'     => 7,
            'exported' => 1,
            'cmid'     => $moduleinfo->coursemodule,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Process bulk context updates from the student survey.
     */
    private static function handle_bulk_context(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $DB;

        $json_data = optional_param('data', '', PARAM_RAW);
        if (empty($json_data)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'No data received']); die(); }
            redirect($base_url);
        }

        $updates = json_decode($json_data);
        if (!$updates || !is_array($updates)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Invalid data format']); die(); }
            redirect($base_url);
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($updates as $entry) {
                if (!isset($entry->userid) || !isset($entry->context)) {
                    continue;
                }

                $userid = (int)$entry->userid;
                $contexttext = self::sanitize_context_text($entry->context);

                $record = $DB->get_record('local_unimas_context', ['courseid' => $course_id, 'userid' => $userid]);
                if ($record) {
                    $record->context_text = $contexttext;
                    $record->timecreated = time();
                    $DB->update_record('local_unimas_context', $record);
                } else {
                    $DB->insert_record('local_unimas_context', [
                        'courseid' => $course_id,
                        'userid' => $userid,
                        'context_text' => $contexttext,
                        'timecreated' => time()
                    ]);
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                // rollback() rethrows; ignore to return controlled AJAX error below.
            }

            if ($is_ajax) {
                echo json_encode(['status' => 'error', 'message' => 'Error al guardar carga masiva de contexto.']);
                die();
            }
            redirect($base_url);
        }

        if ($is_ajax) {
            echo json_encode(['status' => 'success']);
            die();
        }
        redirect($base_url);
    }

    /**
     * Sanitize survey context payload before storing it in DB.
     * Accepts either JSON object text or plain text.
     */
    private static function sanitize_context_text($rawcontext): string {
        $contexttext = is_string($rawcontext) ? $rawcontext : json_encode($rawcontext);
        if (!is_string($contexttext)) {
            return '';
        }

        $decoded = json_decode($contexttext, true);
        if (!is_array($decoded)) {
            return clean_param($contexttext, PARAM_TEXT);
        }

        $sanitized = [];
        foreach ($decoded as $question => $answer) {
            $q = clean_param((string)$question, PARAM_TEXT);
            $a = clean_param((string)$answer, PARAM_TEXT);
            if ($q !== '' && $a !== '') {
                $sanitized[$q] = $a;
            }
        }

        return json_encode($sanitized, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save AI configuration settings from the dashboard.
     */
    private static function handle_save_ai_config(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        // Global plugin configuration must be restricted to site admins/config managers.
        $systemcontext = \context_system::instance();
        if (!has_capability('moodle/site:config', $systemcontext)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'No tienes permisos para cambiar la configuración.']); die(); }
            redirect($base_url);
        }

        $provider = optional_param('ai_provider', '', PARAM_ALPHANUMEXT);
        $apikey   = optional_param('api_key',      '', PARAM_RAW);
        $model    = optional_param('ai_model',     '', PARAM_TEXT);
        $baseurl  = optional_param('ai_base_url',  '', PARAM_URL);
        $provider = self::normalize_ai_provider($provider);

        if (!in_array($provider, self::ALLOWED_AI_PROVIDERS, true)) {
            $msg = 'Proveedor de IA inválido.';
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => $msg]); die(); }
            redirect($base_url);
            return;
        }

        if ($provider === 'custom' && empty($baseurl)) {
            $msg = 'Para proveedor custom debes indicar una Base URL.';
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => $msg]); die(); }
            redirect($base_url);
            return;
        }

        // ── Domain whitelist: reject ai_base_url values pointing to untrusted hosts ──
        if (!self::validate_ai_url($baseurl)) {
            $msg = 'La URL base de IA no está en la lista de dominios permitidos: ' . s($baseurl);
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => $msg]); die(); }
            redirect($base_url);
            return;
        }

        if ($provider) set_config('ai_provider', $provider, 'local_unimas');
        if ($apikey)   set_config('api_key',      $apikey,   'local_unimas');
        if ($model)    set_config('ai_model',     $model,    'local_unimas');
        if ($provider !== 'custom' && empty($baseurl)) {
            // Prevent stale custom endpoint values when switching back to standard providers.
            unset_config('ai_base_url', 'local_unimas');
        } else if ($baseurl) {
            set_config('ai_base_url',  $baseurl,  'local_unimas');
        }

        // Clear AI cache for this course when settings change to force a new call with new config
        global $DB;
        $DB->delete_records('local_unimas_ai_cache', ['courseid' => $course_id]);

        if ($is_ajax) {
            echo json_encode(['status' => 'success']);
            die();
        }
        redirect($base_url);
    }

    /**
     * Clear AI cache for the course to force a re-generation of analysis.
     */
    private static function handle_refresh_ai(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $DB;
        $context = \context_course::instance($course_id);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'No tienes permisos.']); die(); }
            redirect($base_url);
        }

        $DB->delete_records('local_unimas_ai_cache', ['courseid' => $course_id]);

        // Pre-generar la caché global antes de volver, garantizando que esté en el idioma del usuario
        $lang = optional_param('lang', 'es', PARAM_TEXT);
        $dashboard_data = \local_unimas\data_provider::get_dashboard_data($course_id);
        \local_unimas\ai_agent::get_recommendations($course_id, $dashboard_data['semana'], $dashboard_data, $lang);

        if ($is_ajax) {
            echo json_encode(['status' => 'success']);
            die();
        }
        redirect($base_url);
    }

    /**
     * Generate AI analysis for a single specific student.
     */
    private static function handle_generate_student_ai(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $DB;
        $context = \context_course::instance($course_id);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Sin permisos']); die(); }
            redirect($base_url);
        }

        $userid = optional_param('userid', 0, PARAM_INT);
        $refresh = optional_param('refresh', 0, PARAM_INT);

        if ($refresh) {
            // Determine current week to clear specific cache
            $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
            $current_week = \local_unimas\data_provider::get_current_week($course);
            
            $DB->delete_records('local_unimas_ai_cache', [
                'courseid' => $course_id,
                'student_id' => $userid,
                'week' => (int)$current_week
            ]);
        }

        if (!$userid) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'User ID faltante']); die(); }
            redirect($base_url);
        }

        $dashboard = data_provider::get_dashboard_data($course_id);
        $student_info = null;
        foreach ($dashboard['students'] as $s) {
            if ((int)$s['uid'] === $userid) {
                $student_info = $s;
                break;
            }
        }

        if (!$student_info) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']); die(); }
            redirect($base_url);
        }

        $lang = optional_param('lang', 'es', PARAM_TEXT);
        $result = ai_agent::get_student_recommendation($course_id, $userid, $student_info, $dashboard['semana'], $dashboard['name'], $lang);
        if ($is_ajax) {
            echo json_encode($result);
            die();
        }
        redirect($base_url);
    }

    /**
     * Save a follow-up action for a student.
     */
    private static function handle_save_followup(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $DB, $USER;
        
        $context = \context_course::instance($course_id);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Sin permisos']); die(); }
            redirect($base_url);
        }

        $userid = optional_param('userid', 0, PARAM_INT);
        $action_val = optional_param('action_val', '', PARAM_TEXT);
        $note = optional_param('note', '', PARAM_TEXT);

        if (!$userid || !$action_val) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']); die(); }
            redirect($base_url);
        }

        $record = new \stdClass();
        $record->courseid = $course_id;
        $record->userid = $userid;
        $record->authorid = $USER->id;
        $record->action = $action_val;
        $record->note = $note;
        $record->timecreated = time();

        $DB->insert_record('local_unimas_actions', $record);

        if ($is_ajax) {
            echo json_encode(['status' => 'success']);
            die();
        }
        redirect($base_url);
    }
}
