<?php
/**
 * Settings for Uni+ (local_unimas) - Universal AI Adapter
 *
 * @package    local_unimas
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_unimas', get_string('pluginname', 'local_unimas'));
    $ADMIN->add('localplugins', $settings);

    // ── AI Provider ──────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'local_unimas/ai_provider',
        get_string('settings_ai_provider', 'local_unimas'),
        get_string('settings_ai_provider_desc', 'local_unimas'),
        'gemini',
        [
            'gemini'    => 'Google Gemini',
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic Claude',
            'deepseek'  => 'DeepSeek',
            'custom'    => get_string('settings_provider_custom', 'local_unimas'),
        ]
    ));

    // ── API Key ───────────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configpasswordunmask(
        'local_unimas/api_key',
        get_string('settings_api_key', 'local_unimas'),
        get_string('settings_api_key_desc', 'local_unimas'),
        '',
        PARAM_RAW
    ));

    // ── Model name ────────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'local_unimas/ai_model',
        get_string('settings_ai_model', 'local_unimas'),
        get_string('settings_ai_model_desc', 'local_unimas'),
        'gemini-1.5-flash',
        PARAM_TEXT
    ));

    // ── Custom Base URL ───────────────────────────────────────────────────────
    // PARAM_URL sanitises the value; the domain whitelist in action_handler.php
    // rejects any host not on the approved list when saved from the dashboard.
    $settings->add(new admin_setting_configtext(
        'local_unimas/ai_base_url',
        get_string('settings_ai_base_url', 'local_unimas'),
        get_string('settings_ai_base_url_desc', 'local_unimas'),
        '',
        PARAM_URL
    ));

    // ── RAG service endpoint (optional) ──────────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'local_unimas/rag_endpoint',
        get_string('settings_rag_endpoint', 'local_unimas'),
        get_string('settings_rag_endpoint_desc', 'local_unimas'),
        '',
        PARAM_URL
    ));
}
