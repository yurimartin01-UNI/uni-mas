<?php
/**
 * Strings for component 'local_unimas', language 'en'
 */

$string['pluginname']                  = 'Uni+ Student Dashboard';
$string['unimas:view']                 = 'View Uni+';
$string['introduction']                = 'Welcome to Uni+, your pedagogical evaluation assistant.';
$string['coursereport']                = 'Student Dashboard';
$string['section']                     = 'Section';
$string['activity']                    = 'Activity';

// Settings page
$string['settings_ai_provider']        = 'AI Provider';
$string['settings_ai_provider_desc']   = 'Select the AI engine that will process the student data.';
$string['settings_provider_custom']    = 'Custom / Local model';
$string['settings_api_key']            = 'API Key';
$string['settings_api_key_desc']       = 'Access key for the selected AI provider.';
$string['settings_ai_model']           = 'Model name';
$string['settings_ai_model_desc']      = 'Technical model identifier, e.g. gemini-1.5-flash, gpt-4o, llama3-70b.';
$string['settings_ai_base_url']        = 'Base URL (optional)';
$string['settings_ai_base_url_desc']   = 'Only required for Custom providers or local models (e.g. Ollama). Must be an approved domain.';
$string['settings_rag_endpoint']       = 'RAG service endpoint (optional)';
$string['settings_rag_endpoint_desc']  = 'Base URL of the external Python RAG microservice used for sync/ingest. Leave blank if not using RAG.';

// Errors
$string['invalidcourseid']             = 'Invalid course ID.';
$string['nopermission']                = 'You do not have permission to access this page.';
