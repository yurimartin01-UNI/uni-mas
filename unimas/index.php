<?php
/**
 * uni+_panel_docente - Dashboard for teaching staff
 */

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', optional_param('id', 0, PARAM_INT), PARAM_INT);

if (!$courseid) {
    throw new moodle_exception('invalidcourseid', 'local_unimas');
}


$course = $DB->get_record('course', array('id' => $courseid));
if (!$course) {
    throw new moodle_exception('invalidcourseid');
}

$context = context_course::instance($courseid);
require_login($course);
require_capability('moodle/course:manageactivities', $context);

$pageurl = new moodle_url('/local/unimas/index.php', array('courseid' => $courseid));
$PAGE->set_url($pageurl);

// Handle AJAX actions before any output.
// require_sesskey() validates the 'sesskey' POST param against the user's session
// token, protecting every action from Cross-Site Request Forgery (CSRF).
if ($action = optional_param('action', '', PARAM_ALPHANUMEXT)) {
    require_sesskey();
    \local_unimas\action_handler::handle($action, $courseid, $pageurl, true);
}

$PAGE->set_context($context);
$PAGE->set_title('UNI+ — Panel Docente');
$PAGE->set_heading($course->fullname);

$dashboard_data = \local_unimas\data_provider::get_dashboard_data($courseid);
$ai_result = \local_unimas\ai_agent::get_recommendations($courseid, $dashboard_data['semana'], $dashboard_data); // Default 'es' in PHP, but will hit cache!

// Smart Merge: Conservar los reportes individuales más recientes y poblar solo lo faltante desde el reporte global
if (empty($dashboard_data['ai']['global'])) {
    $dashboard_data['ai']['global'] = $ai_result['global'] ?? null;
}
if (!isset($dashboard_data['ai']['recommendations'])) {
    $dashboard_data['ai']['recommendations'] = [];
}
if (isset($ai_result['recommendations']) && is_array($ai_result['recommendations'])) {
    foreach ($ai_result['recommendations'] as $g_rec) {
        $exists = false;
        foreach ($dashboard_data['ai']['recommendations'] as $indR) {
            if (isset($indR['uid']) && isset($g_rec['uid']) && (int)$indR['uid'] === (int)$g_rec['uid']) {
                $exists = true; 
                break;
            }
        }
        if (!$exists) {
            $dashboard_data['ai']['recommendations'][] = $g_rec;
        }
    }
}

// Add current config for the settings modal
$config = get_config('local_unimas');
$dashboard_data['config'] = [
    'ai_provider' => get_config('local_unimas', 'ai_provider'),
    'ai_model'    => get_config('local_unimas', 'ai_model'),
    'ai_base_url' => get_config('local_unimas', 'ai_base_url')
];

// Merge AI recommendations into student actions if no manual action exists
if (!isset($ai_result['error']) && !empty($ai_result['recommendations'])) {
    $rec_map = [];
    foreach ($ai_result['recommendations'] as $rec) {
        $val = isset($rec['summary']) ? $rec['summary'] : (isset($rec['text']) ? $rec['text'] : '');
        $rec_map[$rec['uid']] = $val;
    }
    
    foreach ($dashboard_data['students'] as &$s) {
        if (($s['action'] === 'Sin registro' || empty($s['action'])) && isset($rec_map[$s['uid']])) {
            $s['action'] = $rec_map[$s['uid']];
        }
    }
    unset($s);
}

echo $OUTPUT->header();
?>
<!-- SheetJS loaded via local pix/xlsx.full.min.js to avoid external CDN dependency -->
<script src="<?php echo $CFG->wwwroot; ?>/local/unimas/pix/xlsx.full.min.js"></script>
<style>
/* ── UNI+ Design System (Zero Cache Consolidation) ── */
:root {
  --navy: #1B2A4A; --blue: #4f46e5; --lblue: #D6E8F7;
  --amb-bg: #FEF3C7; --amb-tx: #92400E;
  --grn-bg: #D1FAE5; --grn-tx: #065F46;
  --gry-bg: #F1F5F9; --gry-tx: #64748B;
  --pri-bg: #EEF2FF; --pri-tx: #3730A3;
  --conn-bg: #F0F9FF; --conn-tx: #0C4A6E;
  --border: #E2E8F0; --bg-sec: #F8FAFC;
  --text-pri: #1E293B; --text-sec: #64748B; --text-ter: #94A3B8;
  --radius-pill: 30px; --radius-card: 24px;
}

.panel-wrapper { padding: 24px; max-width: 1400px; margin: 0 auto; font-family: 'Inter', system-ui, sans-serif; }

/* Header */
.header-main { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.header-left { display: flex; align-items: center; gap: 18px; }
.logo-img { height: 42px; }
.header-divider { width: 1px; height: 35px; background: #CBD5E1; }
.header-title-group { display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
.header-title { font-size: 19px; font-weight: 800; color: #1E293B; line-height: 1.2; }
.course-pill { background: #F1F5F9; padding: 2px 12px; border-radius: 20px; border: 1px solid #E2E8F0; font-size: 11px; font-weight: 700; color: #64748B; width: fit-content; }

.header-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

@media (max-width: 768px) {
  .panel-wrapper { padding: 15px; }
  .header-main { flex-direction: column; align-items: stretch; gap: 15px; }
  .header-left { flex-direction: column; align-items: center; text-align: center; gap: 8px; }
  .header-divider { display: none; }
  .header-title { font-size: 16px; width: 100%; }
  .course-pill { font-size: 11px; max-width: 100%; }
  .header-actions { width: 100%; justify-content: center; gap: 8px; }
  .btn-analysis { padding: 6px 12px; font-size: 11px; width: 100%; justify-content: center; }
}
.lang-selector { position: relative; display: inline-block; }
.lang-menu { display: none; position: absolute; right: 0; top: 100%; background: white; min-width: 160px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); border-radius: 8px; z-index: 1000; border: 1px solid #e2e8f0; margin-top: 8px; overflow: hidden; }
.lang-menu.active { display: block; }
.lang-item { padding: 10px 16px; cursor: pointer; font-size: 14px; color: #475569; transition: all 0.2s; }
.lang-item:hover { background: #f1f5f9; color: #0f172a; }
.lang-item.active { background: #eff6ff; color: #2563eb; font-weight: 500; }

.btn-analysis { background: linear-gradient(135deg, #60a5fa 0%, #4f46e5 100%); color: #fff; padding: 8px 20px; border-radius: 20px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(96,165,250,0.3); }

/* Summary Bar & Seguimiento */
.summary-bar { background: #DEE6EF; border-radius: 24px; padding: 20px 30px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; margin-bottom: 24px; border: none; transition: all 0.3s ease; }
.summary-left { display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap; width: 100%; }
.nav-buttons { display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap; width: 100%; }

@media (max-width: 1200px) {
  .summary-bar { padding: 12px 20px; gap: 10px; }
  .summary-left { gap: 8px; }
}

@media (max-width: 768px) {
  .summary-bar { border-radius: 20px; padding: 15px; gap: 12px; justify-content: center; flex-wrap: wrap; }
  .summary-left { flex-direction: column; width: 100%; gap: 10px; }
  .summary-label { width: 100%; text-align: center; margin-bottom: 5px; font-size: 14px; }
  .summary-v-divider, .summary-divider { display: none; }
  .status-chip { font-size: 11px; padding: 8px 14px; width: 100%; justify-content: center; }
  .nav-buttons { width: 100%; display: flex; flex-direction: column; gap: 10px; margin-top: 5px; }
  .nav-buttons .btn-pill { width: 100%; text-align: center; font-size: 12px; padding: 12px; }
}
.summary-label { font-size: 13px; font-weight: 800; color: #1B2A4A; margin-right: 5px; }
.status-chip { padding: 6px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; cursor: pointer; transition: transform 0.2s; border: none; list-style: none; background-image: none; white-space: nowrap; flex-shrink: 0; }
.status-chip::before, .status-chip::after { content: none !important; }
.status-chip:hover { transform: translateY(-1px); }

.status-chip.pri { background: #004581; color: #fff; }
.status-chip.aten { background: #3E72A2; color: #fff; }
.status-chip.norm { background: #97B7D1; color: #1B2A4A; }
.status-chip.nodata { background: #B9CFE0; color: #1B2A4A; }

.summary-v-divider { width: 2px; height: 24px; background: #97B7D1; margin: 0 10px; border-radius: 2px; }

.btn-pill { padding: 8px 20px; border-radius: 25px; font-size: 12px; font-weight: 700; border: none; cursor: pointer; transition: all 0.2s; background: #004581; color: #fff; }
.btn-pill:hover { background: #003360; opacity: 0.9; }

/* Filters */
.filters-area { display: flex; justify-content: center; gap: 12px; margin-bottom: 24px; }
.filter-pill { background: #fff; border: 1px solid #E2E8F0; padding: 6px 20px; border-radius: 20px; font-size: 13px; font-weight: 600; color: #64748B; cursor: pointer; transition: all .2s; }
.filter-pill.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }

/* Modal & Upload Section */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(27, 42, 74, 0.4); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 10000; animation: fadeIn 0.3s ease; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal-card { background: #fff; width: 100%; max-width: 450px; border-radius: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); padding: 32px; text-align: center; overflow-y: auto; max-height: 90vh; }
.modal-title { font-size: 18px; font-weight: 800; color: #1B2A4A; margin-bottom: 20px; }
.upload-zone { border: 2px dashed #97B7D1; background: #F8FAFC; border-radius: 20px; padding: 40px 30px; margin-bottom: 24px; cursor: pointer; transition: all 0.2s; position: relative; }
.upload-zone:hover { background: #EEF2FF; border-color: #004581; }
.upload-icon { width: 48px; height: 48px; margin-bottom: 12px; color: #3E72A2; }
.upload-text { font-size: 13px; color: #64748B; font-weight: 600; display: block; }
.file-selected-name { font-size: 12px; font-weight: 700; color: #004581; margin-top: 10px; display: none; }
.modal-actions { display: flex; flex-direction: column; gap: 10px; }
.modal-btn { width: 100%; }

/* Match Preview Styles */
.match-report { margin-top: 15px; text-align: left; max-height: 200px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; font-size: 11px; display: none; }
.match-row { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #edf2f7; }
.match-row:last-child { border-bottom: none; }
.msg-ok { color: #059669; font-weight: 700; }
.msg-err { color: #dc2626; font-weight: 700; }

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Table Grid */
.uni-table-header { display: grid; grid-template-columns: 1.5fr 1fr 0.8fr 1.5fr 1fr 0.6fr; padding: 12px 24px; border-bottom: 2px solid #004581; margin-bottom: 15px; }

@media (max-width: 1024px) {
  .uni-table-header, .uni-student-row { grid-template-columns: 1.5fr 1fr 0.8fr 1.2fr 0.8fr 0.5fr; }
  /* Evolution is now visible but tighter on tablet */
}

@media (max-width: 768px) {
  .uni-table-header { display: none; }
  .uni-student-row { 
    display: flex; flex-direction: column; align-items: stretch; gap: 10px; 
    padding: 20px; border: 1px solid #E2E8F0; border-radius: 15px; margin-bottom: 15px; 
    position: relative; 
  }
  .cell-center { justify-content: flex-start; text-align: left; position: relative; padding-left: 110px; min-height: 24px; }
  .cell-center::before { 
    content: attr(data-label); position: absolute; left: 0; top: 50%; transform: translateY(-50%);
    font-size: 10px; font-weight: 800; color: #94A3B8; text-transform: uppercase; width: 100px;
  }
  .cell-center:nth-child(5) { display: flex; } /* Show back in card mode */
  .cell-center:last-child { border-top: 1px solid #F1F5F9; padding-top: 10px; padding-left: 0; justify-content: center; }
  .cell-center:last-child::before { display: none; }
}

.th-item { font-size: 12px; font-weight: 700; color: #64748B; text-align: center; display: flex; justify-content: center; align-items: center; gap: 5px; }
.th-item:first-child { justify-content: flex-start; }

.students-container { background: transparent; border-radius: 0; padding: 0; border: none; }
.uni-student-row { display: grid; grid-template-columns: 1.5fr 1fr 0.8fr 1.5fr 1fr 0.6fr; align-items: center; padding: 16px 24px; border-bottom: 1px solid #F1F5F9; transition: background 0.2s; background: #fff; }
.uni-student-row:hover { background: #F8FAFC; }
.cell-center { text-align: center; display: flex; justify-content: center; align-items: center; }

/* IS Bar */
.is-bar-wrap { width: 100%; display: flex; align-items: center; gap: 8px; justify-content: center; }
.is-bar { height: 6px; background: #E2E8F0; border-radius: 3px; flex-grow: 1; max-width: 60px; overflow: hidden; }
.is-fill { height: 100%; }
.is-val { font-size: 13px; font-weight: 700; min-width: 30px; text-align: right; }

/* Badges and Sparks */
.badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 12px; }
.badge.pri { background: #EEF2FF; color: #3730A3; }
.badge.aten { background: #FEF3C7; color: #92400E; }
.badge.norm { background: #D1FAE5; color: #065F46; }
.badge.gry { background: #F1F5F9; color: #64748B; }

.is-sparkline { display: flex; align-items: flex-end; gap: 2px; height: 20px; }
.spark-bar { width: 5px; border-radius: 2px 2px 0 0; }
.btn-detail { background: #E2E8F0; color: #1E293B; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; }

/* ── DETAIL BOX (Matching Reference v3) ── */
.full-width-cell { grid-column: 1 / -1; width: 100%; border-top: 1px solid #F1F5F9; }
.detail-box { padding: 32px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; background: #F8FAFC; border-bottom: 2px solid #E2E8F0; }
@media (max-width: 768px) { 
  .detail-box { grid-template-columns: 1fr; padding: 20px; gap: 20px; } 
  .save-btn { width: 100%; }
}
.detail-section { display: flex; flex-direction: column; gap: 15px; }
.detail-label { font-size: 11px; font-weight: 700; color: var(--text-ter); text-transform: uppercase; letter-spacing: .05em; }

.detail-row-item { display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-bottom: 8px; font-weight: 500; }
.comp-bar-wrap { display: flex; align-items: center; gap: 8px; }
.comp-bar { height: 5px; background: #E2E8F0; border-radius: 3px; width: 100px; overflow: hidden; }
.comp-fill { height: 100%; }

.action-form { display: flex; flex-direction: column; gap: 10px; }
.action-select, .action-note { width: 100%; padding: 10px; border-radius: 10px; border: 1px solid #E2E8F0; font-size: 13px; font-family: inherit; }
.save-btn { background: var(--navy); color: #fff; padding: 10px 20px; border-radius: 10px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; align-self: flex-start; }

.history-item { font-size: 12px; display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #E2E8F0; }
.history-date { color: var(--text-ter); }

/* Delta Icons */
.delta { display: flex; align-items: center; gap: 3px; font-size: 12px; font-weight: 700; }
.delta.up { color: var(--grn-tx); }
.delta.dn { color: var(--pri-tx); }

/* Settings Modal */
.config-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-top: 15px; }
.config-group { display: flex; flex-direction: column; gap: 5px; text-align: center; }
.config-label { font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; }
.config-input, .config-select { padding: 10px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; width: 100%; box-sizing: border-box; }
.config-help { font-size: 11px; color: #94A3B8; margin-top: 2px; }
.btn-config-gear { background: #F1F5F9; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
.btn-config-gear:hover { background: #E2E8F0; color: var(--blue); }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="panel-wrapper notranslate" id="dashboard-container" translate="no">
  <!-- Header -->
  <header class="header-main">
    <div class="header-left">
      <img src="data:image/jpeg;base64,<?php echo base64_encode(file_get_contents(__DIR__ . '/pix/logo.jpeg')); ?>" alt="UNI+" class="logo-img">
      <div class="header-divider"></div>
      <div class="header-title-group">
        <div class="header-title" data-i18n="header.title">Dashboard de Seguimiento Estudiantil (Uni+)</div>
        <div class="course-pill"><?php echo s($dashboard_data['name']); ?></div>
      </div>
    </div>
    <div class="header-actions">
       <div class="lang-selector">
          <button class="btn-config-gear" onclick="toggleLangMenu()" title="Cambiar idioma">🌐</button>
          <div class="lang-menu" id="langMenu">
            <div class="lang-item active" id="lang-es" onclick="changeLang('es')">Español</div>
            <div class="lang-item" id="lang-en" onclick="changeLang('en')">English</div>
            <div class="lang-item" id="lang-pt-br" onclick="changeLang('pt-br')">Português (BR)</div>
            <div class="lang-item" id="lang-gl" onclick="changeLang('gl')">Galego</div>
          </div>
       </div>
       <?php if (has_capability('moodle/course:manageactivities', $context)): ?>
       <button class="btn-config-gear" title="Configuración de IA" onclick="openConfigModal()">
          <svg viewBox="0 0 24 24" style="fill:currentColor; width:20px;height:20px;"><path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.35 19.43,11.03L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.47,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.53,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11.03C4.53,11.35 4.5,11.67 4.5,12C4.5,11.67 4.53,11.35 4.57,11.03L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.95C7.96,18.34 8.53,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.47,18.68 16.04,18.34 16.56,17.95L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/></svg>
       </button>
       <?php endif; ?>
       <button class="btn-analysis" id="btn-curso" onclick="refreshAI()">
          <span data-i18n="header.refresh_btn">Analizar y Refrescar Agente</span> <svg viewBox="0 0 24 24" style="fill:white; width:16px;height:16px; margin-left:5px;"><path d="M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z"/></svg>
       </button>
    </div>
  </header>

  <!-- Summary Bar -->
  <div class="summary-bar">
    <div class="summary-left">
      <span class="summary-label" data-i18n="filters.label">Seguimiento activo:</span>
      <div class="status-chip pri" onclick="setFilter('crit')" id="chip-pri"></div>
      <div class="status-chip aten" onclick="setFilter('aten')" id="chip-amb"></div>
      <div class="status-chip norm" onclick="setFilter('norm')" id="chip-grn"></div>
      <div class="status-chip nodata" onclick="setFilter('none')" id="chip-nod"></div>
    </div>
    


    <div class="nav-buttons">
      <button class="btn-pill" onclick="location.reload()" data-i18n="nav.dashboard">Dashboard</button>
      <button class="btn-pill" onclick="openSurveyModal()" data-i18n="nav.survey">Carga Formulario Estudiantil</button>
    </div>
  </div>
  
  <!-- Robust Filtering Area (New) -->
  <div class="filters-area" style="display:flex; justify-content:center; align-items:center; gap:20px; margin-bottom:20px; margin-top: -10px;">
    <div style="width: 100%; max-width: 500px; display:flex; align-items:center; background:white; border:2px solid #DEE6EF; border-radius:15px; padding:8px 16px; gap:10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
      <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#4f46e5;"><path d="M15.5,14H14.71L14.43,13.73C15.41,12.59 16,11.11 16,9.5A6.5,6.5 0 0,0 9.5,3A6.5,6.5 0 0,0 3,9.5A6.5,6.5 0 0,0 9.5,16C11.11,16 12.59,15.41 13.73,14.43L14,14.71V15.5L19,20.5L20.5,19L15.5,14M9.5,14C7.01,14 5,11.99 5,9.5C5,7.01 7.01,5 9.5,5C11.99,5 14,7.01 14,9.5C14,11.99 11.99,14 9.5,14Z"/></svg>
      <input type="text" id="studentSearch" data-i18n-attr="placeholder:search.placeholder" placeholder="Escribe el nombre del estudiante para filtrar rápida..." style="border:none; outline:none; font-size:14px; width:100%; color:#1B2A4A; font-weight: 500;" oninput="searchTerm=this.value; render();">
    </div>
  </div>


  <!-- Table Core -->
  <div class="table-container">
    <div class="uni-table-header">
      <div class="th-item" style="display:flex; align-items:center; gap:6px;">
        <span data-i18n="table.student">Estudiante</span> 
        <span style="font-size:10px; background:#4f46e5; color:white; padding:2px 6px; border-radius:4px; font-weight:800; text-transform:uppercase;" data-i18n="table.sort_az">Sort A-Z</span>
      </div>
      <div class="th-item" style="text-align:center;" data-i18n="table.is">Indicador Éxito (IS)</div>
      <div class="th-item" style="text-align:center;" data-i18n="table.level">Nivel</div>
      <div class="th-item" data-i18n="table.ai_rec">Recomendación Agente AI</div>
      <div class="th-item" style="text-align:center;" data-i18n="table.evolution">Evolución</div>
      <div class="th-item" style="text-align:center;" data-i18n="table.detail">Detalle</div>
    </div>
    <div class="students-container" id="tbody">
      <!-- Inyectado por JS -->
    </div>
  </div> <!-- End Table Container -->

  <!-- AI Configuration Modal -->
  <div class="modal-overlay" id="configModal">
    <div class="modal-card">
      <div class="config-modal-header">
        <h2 style="margin:0; font-size:18px; color:#1E293B;" data-i18n="config_modal.title">Configuración del Agente de IA</h2>
      </div>
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div class="config-group">
          <label class="config-label" data-i18n="config_modal.provider">Proveedor de IA</label>
          <select id="config-provider" class="config-select">
            <option value="gemini" <?php echo (isset($config) && $config->ai_provider === 'gemini') ? 'selected' : ''; ?>>Google Gemini</option>
            <option value="openai" <?php echo (isset($config) && $config->ai_provider === 'openai') ? 'selected' : ''; ?>>OpenAI</option>
            <option value="anthropic" <?php echo (isset($config) && $config->ai_provider === 'anthropic') ? 'selected' : ''; ?>>Anthropic Claude</option>
            <option value="deepseek" <?php echo (isset($config) && $config->ai_provider === 'deepseek') ? 'selected' : ''; ?>>DeepSeek</option>
            <option value="custom" <?php echo (isset($config) && $config->ai_provider === 'custom') ? 'selected' : ''; ?>>Custom</option>
          </select>
        </div>
        <div class="config-group">
          <label class="config-label" data-i18n="config_modal.key">API Key</label>
          <input type="password" id="config-key" class="config-input" placeholder="sk-..." value="">
        </div>
        <div class="config-group">
          <label class="config-label" data-i18n="config_modal.model">Modelo Específico</label>
          <input type="text" id="config-model" class="config-input" placeholder="gemini-1.5-pro" value="<?php echo isset($config) && isset($config->ai_model) ? s($config->ai_model) : ''; ?>">
        </div>
        <div class="config-group">
          <label class="config-label" data-i18n="config_modal.base_url">Base URL (Opcional)</label>
          <input type="text" id="config-baseurl" class="config-input" placeholder="https://..." value="<?php echo isset($config) && isset($config->ai_base_url) ? s($config->ai_base_url) : ''; ?>">
          <div class="config-help" data-i18n="config_modal.help_url">Solo para proveedores personalizados o modelos locales.</div>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn-pill modal-btn" onclick="saveAIConfig()" data-i18n="config_modal.save_btn">Guardar Configuración</button>
        <button class="btn-pill modal-btn" style="background:#F1F5F9; color:#1E293B;" onclick="closeConfigModal()" data-i18n="config_modal.close_btn">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Excel Upload Modal -->
  <div class="modal-overlay" id="surveyModal">
    <div class="modal-card">
      <div class="modal-title" data-i18n="survey_modal.title">Carga de Formulario Estudiantil</div>
      
      <div class="upload-zone" onclick="document.getElementById('excelInput').click()">
        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
          <polyline points="17 8 12 3 7 8"></polyline>
          <line x1="12" y1="3" x2="12" y2="15"></line>
        </svg>
        <span class="upload-text" data-i18n="survey_modal.upload_hint">Arrastra tu archivo Excel aquí o haz clic para buscarlo</span>
        <div id="fileNameDisplay" class="file-selected-name"></div>
        <input type="file" id="excelInput" style="display: none;" accept=".xlsx, .xls, .csv" onchange="handleFileSelect(event)">
      </div>

      <div id="matchReport" class="match-report"></div>

      <div class="modal-actions">
        <button id="uploadBtn" class="btn-pill modal-btn" onclick="processExcel()" data-i18n="survey_modal.process_btn">Previsualizar cambios</button>
        <button class="btn-pill modal-btn" style="background: #F1F5F9; color: #1E293B;" onclick="closeSurveyModal()" data-i18n="survey_modal.close_btn">Cerrar</button>
      </div>
    </div>
  </div>

</div> <!-- End Panel Wrapper -->

<script>
/* ── Lógica Consolidada (Zero Cache) ── */
const UNIMAS_DATA = <?php echo json_encode($dashboard_data); ?>;
let currentData = UNIMAS_DATA;
// --- UI State ---
let activeFilter = 'all';
let searchTerm = '';
let expandedId = null;
let generatingIds = new Set();

// --- i18n Engine Robustness ---
let i18nData = null;
let currentLang = localStorage.getItem('unimas_lang') || 'es';

// Emergency hardcoded fallback for Spanish (base)
const i18nFallbackES = {
  header: { title: "Dashboard de Seguimiento Estudiantil (Uni+)", refresh_btn: "Analizar y Refrescar Agente" },
  filters: { label: "Seguimiento activo:", crit: "Prioritarios", aten: "En atención", norm: "En seguimiento normal", nodata: "Sin actividad registrada" },
  nav: { dashboard: "Dashboard", survey: "Carga Formulario Estudiantil" },
  search: { placeholder: "Escribe el nombre del estudiante para filtrar rápida..." },
  table: { student: "Estudiante", is: "Indicador Éxito (IS)", detail: "Detalle", close: "Cerrar", no_info: "Sin información", sort_az: "Sort A-Z", level: "Nivel", ai_rec: "Recomendación Agente AI", evolution: "Evolución" },
  badges: { crit: "Prioritario", aten: "Atención", norm: "Normal", nodata: "Sin información" },
  detail_panel: { is_breakdown: "Desglose del IS — Sem.", no_perf_info: "No hay información suficiente...", no_context: "Sin contexto registrado.", ai_report_title: "INFORME PEDAGÓGICO DEL AGENTE UNI+", ai_analyzing: "Agente UNI+ analizando perfil...", ai_gen_btn: "Generar Informe UNI+", actions_title: "Registrar acción de seguimiento", history_title: "Historial de IS", comp_lms: "Actividad LMS", comp_grades: "Calificaciones", comp_submissions: "Entregas", context_title: "Contexto Estudiantil", regenerate_tooltip: "Regenerar informe" },
  followup_form: { select_action: "Seleccionar acción...", action_email: "Contactado por correo", action_other: "Otro", notes_placeholder: "Notas internas...", save_btn: "Guardar acción", success_msg: "¡Acción guardada!", week: "Semana" },
  config_modal: { title: "Configuración del Agente de IA", save_btn: "Guardar Configuración", close_btn: "Cerrar" },
  survey_modal: { title: "Carga de Formulario Estudiantil", upload_hint: "Arrastra tu archivo Excel...", process_btn: "Previsualizar cambios", close_btn: "Cerrar" },
  survey_questions: {
    q1: "¿Qué destacarías del diseño de esta materia?",
    q2: "¿Qué aspectos de esta materia son los que te resultan más difíciles de resolver?",
    q3: "¿Qué necesitas para avanzar en esta materia?",
    q4: "¿Quieres que el/la docente se ponga en contacto contigo?"
  }
};

async function loadTranslations() {
    try {
      // Cargar JSON desde PHP para esquivar bloqueos de NGINX a archivos estáticos
      const rawJson = <?php 
        $t_file = __DIR__ . '/translations.json';
        echo file_exists($t_file) ? json_encode(file_get_contents($t_file)) : '"{}"';
      ?>;
      i18nData = JSON.parse(rawJson);
      syncUI();
      render();
    } catch (e) { 
        console.error("Failed to load translations, using fallback", e);
        i18nData = { es: i18nFallbackES }; 
        syncUI();
        render();
    }
}

function syncUI() {
    // 1. Update text content
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        el.textContent = _t(key);
    });

    // 2. Update attributes (placeholders, etc)
    document.querySelectorAll('[data-i18n-attr]').forEach(el => {
        const parts = el.getAttribute('data-i18n-attr').split(':'); // e.g. "placeholder:search.placeholder"
        if (parts.length === 2) {
            el.setAttribute(parts[0], _t(parts[1]));
        }
    });

    // 3. Sync Lang Menu Active State
    document.querySelectorAll('.lang-item').forEach(el => {
        const langCode = el.id.replace('lang-', '');
        el.classList.toggle('active', langCode === currentLang);
    });
}

function _t(path) {
    const parts = path.split('.');
    let result;
    
    // 1. Try currently selected language
    if (i18nData && i18nData[currentLang]) {
        let obj = i18nData[currentLang];
        let found = true;
        for (const p of parts) {
            if (obj && typeof obj === 'object' && obj[p] !== undefined) obj = obj[p];
            else { found = false; break; }
        }
        if (found && typeof obj === 'string') return obj;
    }

    // 2. Fallback to Spanish in i18nData
    if (currentLang !== 'es' && i18nData && i18nData['es']) {
        let obj = i18nData['es'];
        let found = true;
        for (const p of parts) {
            if (obj && typeof obj === 'object' && obj[p] !== undefined) obj = obj[p];
            else { found = false; break; }
        }
        if (found && typeof obj === 'string') return obj;
    }

    // 3. Final Fallback: Hardcoded Spanish dictionary
    result = i18nFallbackES;
    for (const p of parts) {
        if (result && typeof result === 'object' && result[p] !== undefined) result = result[p];
        else return path; 
    }
    return (typeof result === 'string') ? result : path;
}

function toggleLangMenu() { document.getElementById('langMenu').classList.toggle('active'); }

function changeLang(lang) {
    console.log("Changing language to:", lang);
    currentLang = lang;
    localStorage.setItem('unimas_lang', lang);
    const menu = document.getElementById('langMenu');
    if (menu) menu.classList.remove('active');
    
    // Ensure we are rendering with the best possible data
    if (!i18nData || !i18nData[lang]) {
        loadTranslations(); // Will call syncUI() and render() inside
    } else {
        syncUI();
        render();
    }
}

function isColor(v) {
  if (v < 0.40) return '#6366F1';
  if (v < 0.65) return '#F59E0B';
  return '#10B981';
}

function levelBadge(level) {
  const map = { 
    crit:{cls:'pri',lbl:_t('badges.crit')}, 
    aten:{cls:'amb',lbl:_t('badges.aten')}, 
    norm:{cls:'grn',lbl:_t('badges.norm')},
    nodata:{cls:'gry',lbl:_t('badges.nodata')} 
  };
  const {cls,lbl} = map[level] || {cls:'gry',lbl:_t('badges.nodata')};
  return `<span class="badge ${cls}">${lbl}</span>`;
}

function sparkline(hist) {
  const max = Math.max(...hist, 0.1);
  return hist.map((v,i) => {
    const h = Math.round((v/max)*20);
    return `<div class="spark-bar" style="height:${h}px; background:${isColor(v)}; opacity:${0.4 + (i*0.2)}"></div>`;
  }).join('');
}

function render() {
  const tbody = document.getElementById('tbody');
  const rows = currentData.students
    .filter(s => (activeFilter==='all' || s.level===activeFilter))
    .filter(s => normalize(s.name).includes(normalize(searchTerm)))
    .sort((a,b) => a.name.localeCompare(b.name, 'es', { numeric: true, sensitivity: 'base' }));
  
  let html = '';
  rows.forEach(s => {
    const isExpanded = (expandedId === s.id);
    const deltaIcon = s.delta > 0 ? `<span class="delta up">▲ +${s.delta.toFixed(2)}</span>` : (s.delta < 0 ? `<span class="delta dn">▼ ${s.delta.toFixed(2)}</span>` : '<span class="delta">— 0.00</span>');
    const aiRec = (currentData.ai && currentData.ai.recommendations) ? currentData.ai.recommendations.find(r => String(r.uid) === String(s.uid)) : null;
    const aiSummary = aiRec ? aiRec.summary : '<span style="color:#94A3B8; font-style:italic;">Pendiente análisis</span>';

    html += `
    <div class="uni-student-row ${isExpanded ? 'active' : ''}">
      <div style="display:flex; flex-direction:column; justify-content:center;">
        <span style="font-weight:700; color:#1E293B;">${s.name}</span>
        <span style="font-size:11px; color:#94A3B8;">${s.id}</span>
      </div>
      <div class="cell-center" data-label="${_t('table.is')}">
        <div class="is-bar-wrap">
          ${s.no_info ? 
            `<span style="font-size:11px; color:#94A3B8; font-style:italic;">Sin información</span>` :
            `<div class="is-bar"><div class="is-fill" style="width:${s.is*100}%; background:${isColor(s.is)}"></div></div>
             <span class="is-val" style="color:${isColor(s.is)}">${s.is.toFixed(2)}</span>`
          }
        </div>
      </div>
      <div class="cell-center" data-label="${_t('table.level')}">${levelBadge(s.level)}</div>
      <div class="cell-center" data-label="${_t('table.ai_rec')}" style="font-size:11px; color:#475569; font-weight:500; text-align:left; padding:10px;">${aiSummary}</div>
      <div class="cell-center" data-label="${_t('table.evolution')}">
        <div style="display:flex; align-items:center; gap:12px;">
           ${s.no_info ? '<span class="delta">—</span>' : deltaIcon}
           <div class="is-sparkline">${sparkline(s.hist)}</div>
        </div>
      </div>
      <div class="cell-center">
        <button class="btn-detail" onclick="toggleDetail('${s.id}')">${isExpanded ? _t('table.close') : _t('table.detail')}</button>
      </div>
    </div>`;

    if (expandedId === s.id) {
      const compLabels = [_t('detail_panel.comp_lms'), _t('detail_panel.comp_grades'), _t('detail_panel.comp_submissions')];
      const compKeys = ['act','rend','ent'];
      html += `
      <div class="full-width-cell">
        <div class="detail-box">
          <div class="detail-section">
            <div class="detail-label">${_t('detail_panel.is_breakdown')} ${currentData.semana}</div>
            ${s.no_info ? 
              `<div style="font-size:12px; color:#94A3B8; margin:10px 0;">${_t('detail_panel.no_perf_info')}</div>` :
              compLabels.map((lbl, i) => {
                const v = s.comps[compKeys[i]];
                return `<div class="detail-row-item"><span>${lbl}</span><div class="comp-bar-wrap"><div class="comp-bar"><div class="comp-fill" style="width:${v*100}%; background:${isColor(v)}"></div></div><span style="font-size:12px; font-weight:700;">${v.toFixed(2)}</span></div></div>`;
              }).join('')
            }
            
            <div style="margin-top:15px; border-top: 1px solid #F1F5F9; padding-top:10px;">
              <div class="detail-label">${_t('detail_panel.context_title')}</div>
              <div style="font-size:12px; color:#475569; margin-top:5px; line-height:1.5;">
                ${(() => {
                  if (!s.ctx || s.ctx === _t('detail_panel.no_context')) return _t('detail_panel.no_context');
                  try {
                    const survey = JSON.parse(s.ctx);
                    const qMap = {
                      "¿Qué destacarías del diseño de esta materia?": "survey_questions.q1",
                      "¿Qué aspectos de esta materia son los que te resultan más difíciles de resolver?": "survey_questions.q2",
                      "¿Qué necesitas para avanzar en esta materia?": "survey_questions.q3",
                      "¿Quieres que el/la docente se ponga en contacto contigo?": "survey_questions.q4"
                    };
                    return `<div style="display:flex; flex-direction:column; gap:12px;">
                      ${Object.entries(survey).map(([q, a]) => {
                        const tKey = qMap[q.trim()];
                        const translatedQ = tKey ? _t(tKey) : q;
                        return `<div>
                          <div style="font-weight:800; color:#1B2A4A; margin-bottom:2px;">${translatedQ}</div>
                          <div style="background:#F8FAFC; padding:8px 12px; border-radius:12px; border-left:3px solid #004581;">${a}</div>
                        </div>`;
                      }).join('')}
                    </div>`;
                  } catch(e) {
                    return `<div style="display:flex; flex-direction:column; gap:6px;">
                      ${s.ctx.split(' | ').map(part => `
                        <div style="display:flex; align-items:flex-start; gap:8px;">
                          <span style="color:#004581; margin-top:3px;">●</span>
                          <span>${part}</span>
                        </div>`).join('')}
                    </div>`;
                  }
                })()}
              </div>
            </div>
          </div>
          
          <div class="detail-section">
            <div class="detail-label" style="display:flex; align-items:center; gap:8px; color:#4f46e5; margin-bottom:12px;">
              <div style="background:#4f46e5; color:white; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold;">AI</div>
              <span>${_t('detail_panel.ai_report_title')}</span>
              <div style="flex:1"></div>
              ${(aiRec && aiRec.full_report) ? `
                <button title="${_t('detail_panel.regenerate_tooltip')}" style="background:none; border:none; padding:5px; cursor:pointer; color:#94A3B8; display:flex; align-items:center; transition: color 0.2s;" 
                        onmouseover="this.style.color='#4f46e5'" onmouseout="this.style.color='#94A3B8'"
                        onclick="generateStudentAI('${s.uid}', true)">
                  <svg viewBox="0 0 24 24" style="width:18px; height:18px; fill:currentColor;"><path d="M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z"/></svg>
                </button>
              ` : ''}
            </div>
            
            <div style="background:#FFFFFF; border-radius:12px; border:1px solid #E2E8F0; padding:20px; font-size:13px; color:#334155; line-height:1.6; height:100%;">
              ${(() => {
                if (generatingIds.has(String(s.uid))) {
                  return `<div style="display:flex; flex-direction:column; align-items:center; gap:10px; padding:20px; color:#4f46e5;">
                    <div style="width:24px; height:24px; border:3px solid #E2E8F0; border-top-color:#4f46e5; border-radius:50%; animation: spin 1s linear infinite;"></div>
                    <div style="font-weight:600; font-size:12px;">${_t('detail_panel.ai_analyzing')}</div>
                  </div>`;
                }
                if (aiRec && aiRec.full_report) {
                  return aiRec.full_report.replace(/### (.*)/g, '<div style="font-weight:700; color:#1e293b; margin-top:12px; border-bottom:1px solid #E2E8F0; padding-bottom:4px; margin-bottom:8px;">$1</div>');
                }
                return `<div style="text-align:center; padding:10px;">
                  <p style="margin-bottom:15px; font-size:12px; color:#64748B;">${_t('detail_panel.ai_prompt_manual')}</p>
                  <button class="btn-pill" style="background:#4f46e5; font-size:11px;" onclick="generateStudentAI('${s.uid}')">${_t('detail_panel.ai_gen_btn')}</button>
                </div>`;
              })()}
            </div>
          </div>

          <div class="full-width-cell" style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 32px; padding-top: 24px; margin-top: 10px; border-top: 1px solid #F1F5F9;">
            <div class="detail-section">
              <div class="detail-label">${_t('detail_panel.history_title')}</div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                ${s.hist.map((v, i) => `
                  <div class="history-item">
                    <span class="history-date">${_t('followup_form.week')} ${i+1}</span>
                    <span style="font-weight:700;">${v.toFixed(2)}</span>
                  </div>`).join('')}
              </div>
            </div>

            <div class="detail-section">
              <div class="detail-label">${_t('detail_panel.actions_title')}</div>
              <div class="action-form">
                <select class="action-select">
                  <option value="">${_t('followup_form.select_action')}</option>
                  <option value="Contactado por correo" ${s.action === 'Contactado por correo' ? 'selected' : ''}>${_t('followup_form.action_email')}</option>
                  <option value="Cita presencial agendada" ${s.action === 'Cita presencial agendada' ? 'selected' : ''}>${_t('followup_form.action_meeting')}</option>
                  <option value="Derivado a bienestar" ${s.action === 'Derivado a bienestar' ? 'selected' : ''}>${_t('followup_form.action_welfare')}</option>
                  <option value="Otro" ${s.action === 'Otro' ? 'selected' : ''}>${_t('followup_form.action_other')}</option>
                </select>
                <textarea class="action-note" placeholder="${_t('followup_form.notes_placeholder')}">${s.note || ''}</textarea>
                <button class="save-btn" onclick="saveFollowup('${s.uid}', this)">${_t('followup_form.save_btn')}</button>
              </div>
            </div>
          </div>
        </div>
      </div>`;
    }
  });

  tbody.innerHTML = html;
  updateStats();
}

function updateStats() {
  document.getElementById('chip-pri').innerHTML = `<div class="chip-status"></div>${_t('filters.crit')} <span class="chip-count" id="count-pri">0</span>`;
  document.getElementById('chip-amb').innerHTML = `<div class="chip-status"></div>${_t('filters.aten')} <span class="chip-count" id="count-aten">0</span>`;
  document.getElementById('chip-grn').innerHTML = `<div class="chip-status"></div>${_t('filters.norm')} <span class="chip-count" id="count-norm">0</span>`;
  document.getElementById('chip-nod').innerHTML = `<div class="chip-status"></div>${_t('filters.nodata')} <span class="chip-count" id="count-nodata">0</span>`;

  const counts = currentData.counts || {pri:0, aten:0, norm:0, nodata:0};
  document.getElementById('count-pri').textContent = counts.pri;
  document.getElementById('count-aten').textContent = counts.aten;
  document.getElementById('count-norm').textContent = counts.norm;
  document.getElementById('count-nodata').textContent = counts.nodata;
}

function setFilter(f) {
    activeFilter = f;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.toggle('active', b.id === 'f-' + f));
    render();
}

function toggleDetail(id) {
    expandedId = (expandedId === id) ? null : id;
    render();
}

let pendingUpdates = null;

function normalize(str) {
    if (!str) return '';
    return str.toString().toLowerCase()
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "") // Remove accents
        .trim().replace(/\s+/g, ' '); // Remove extra spaces
}

function saveFollowup(uid, btn) {
    const section = btn.closest('.action-form');
    const actionVal = section.querySelector('.action-select').value;
    const note = section.querySelector('.action-note').value;

    if (!actionVal) {
        alert('Por favor, selecciona una acción primero.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Guardando...';

    const formData = new FormData();
    formData.append('action', 'save_followup');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('userid', uid);
    formData.append('action_val', actionVal);
    formData.append('note', note);
    formData.append('sesskey', '<?php echo sesskey(); ?>');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            const student = currentData.students.find(s => String(s.uid) === String(uid));
            if (student) {
                student.action = actionVal;
                student.note = note;
            }
            alert('¡Acción guardada con éxito!');
            expandedId = null;
            render();
        } else {
            alert('Error: ' + (res.message || 'Error desconocido'));
            btn.disabled = false;
            btn.textContent = 'Guardar acción';
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error de conexión al guardar.');
        btn.disabled = false;
        btn.textContent = 'Guardar acción';
    });
}

function openSurveyModal() { 
    document.getElementById('surveyModal').classList.add('active');
}

function closeSurveyModal() {
    document.getElementById('surveyModal').classList.remove('active');
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('excelInput').value = '';
    const matchReport = document.getElementById('matchReport');
    if (matchReport) {
        matchReport.innerHTML = '';
        matchReport.style.display = 'none';
    }
    const uploadBtn = document.getElementById('uploadBtn');
    if (uploadBtn) {
        uploadBtn.textContent = 'Previsualizar cambios';
        uploadBtn.style.background = '#004581';
    }
    pendingUpdates = null;
}

/* AI Configuration Functions */
function openConfigModal() {
    document.getElementById('config-provider').value = currentData.config.ai_provider || 'gemini';
    document.getElementById('config-model').value = currentData.config.ai_model || 'gemini-1.5-flash';
    document.getElementById('config-baseurl').value = currentData.config.ai_base_url || '';
    document.getElementById('config-key').value = ''; // Don't show existing key for security
    document.getElementById('configModal').style.display = 'flex';
}

function closeConfigModal() {
    document.getElementById('configModal').style.display = 'none';
}

async function generateStudentAI(uid, refresh = false) {
    if (generatingIds.has(String(uid))) return;
    generatingIds.add(String(uid));
    render();

    const formData = new FormData();
    formData.append('action', 'generate_student_ai');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('userid', uid);
    if (refresh) formData.append('refresh', '1');
    formData.append('lang', currentLang);
    formData.append('sesskey', '<?php echo sesskey(); ?>');

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.error) {
            alert("Error del Agente: " + result.error);
        } else {
            // Update local data with the individual recommendation
            if (!currentData.ai) currentData.ai = { recommendations: [] };
            if (!currentData.ai.recommendations) currentData.ai.recommendations = [];
            
            // Remove old if exists
            currentData.ai.recommendations = currentData.ai.recommendations.filter(r => String(r.uid) !== String(uid));
            // Add new with UID
            result.uid = uid;
            currentData.ai.recommendations.push(result);
        }
    } catch (e) {
        console.error(e);
        alert("Error de conexión al generar el análisis.");
    } finally {
        generatingIds.delete(String(uid));
        render();
    }
}

async function refreshAI() {
    console.log("refreshAI triggered");
    const btn = document.getElementById('btn-curso');
    if (!btn) return;
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Analizando...';
    
    const formData = new FormData();
    formData.append('action', 'refresh_ai');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('lang', currentLang);

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        location.reload();
    } catch (e) {
        console.error(e);
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }
}

async function saveAIConfig() {
    const provider = document.getElementById('config-provider').value;
    const key = document.getElementById('config-key').value;
    const model = document.getElementById('config-model').value;
    const baseurl = document.getElementById('config-baseurl').value;

    const formData = new FormData();
    formData.append('action', 'save_ai_config');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('ai_provider', provider);
    formData.append('api_key', key);
    formData.append('ai_model', model);
    formData.append('ai_base_url', baseurl);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const raw = await response.text();
        let result = null;
        try {
            result = JSON.parse(raw);
        } catch (_) {
            const msg = (raw || '').slice(0, 300).replace(/\s+/g, ' ').trim();
            throw new Error(msg || `Respuesta no JSON (HTTP ${response.status})`);
        }
        if (result.status === 'success') {
            alert('Configuración guardada correctamente. Recargando panel...');
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (e) {
        console.error('saveAIConfig failed:', e);
        alert('Error al guardar la configuración. ' + (e?.message ? `Detalle: ${e.message}` : ''));
    }
}

function handleFileSelect(e) {
    const file = e.target.files[0];
    if (file) {
        const display = document.getElementById('fileNameDisplay');
        display.textContent = `Archivo seleccionado: ${file.name}`;
        display.style.display = 'block';
        // Reset preview if new file selected
        const reportDiv = document.getElementById('matchReport');
        if (reportDiv) reportDiv.style.display = 'none';
        const uploadBtn = document.getElementById('uploadBtn');
        if (uploadBtn) {
            uploadBtn.textContent = 'Previsualizar cambios';
            uploadBtn.style.background = '#004581';
        }
        pendingUpdates = null;
    }
}

function processExcel() {
    const fileInput = document.getElementById('excelInput');
    const file = fileInput.files[0];
    const reportDiv = document.getElementById('matchReport');
    const uploadBtn = document.getElementById('uploadBtn');

    if (!file) {
        alert('Por favor, selecciona un archivo primero.');
        return;
    }

    if (pendingUpdates) {
        confirmAndUpload();
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];
        const jsonData = XLSX.utils.sheet_to_json(worksheet, {header: 1});

        const matchedUpdates = [];
        const students = currentData.students;
        let previewHtml = '<strong>Resumen de emparejamiento:</strong><br>';
        
        const questions = [
            "¿Qué destacarías del diseño de esta materia?",
            "¿Qué aspectos de esta materia son los que te resultan más difíciles de resolver?",
            "¿Qué necesitas para avanzar en esta materia?",
            "¿Quieres que el/la docente se ponga en contacto contigo?"
        ];

        for (let i = 1; i < jsonData.length; i++) {
            const row = jsonData[i];
            if (!row || row.length < 3) continue;

            const excelName = normalize(row[1]);
            const excelEmail = normalize(row[2]);
            
            const surveyData = {};
            questions.forEach((q, idx) => {
                const answer = String(row[idx + 3] || '').trim();
                if (answer) surveyData[q] = answer;
            });

            if (Object.keys(surveyData).length === 0) continue;

            let foundStudent = students.find(s => normalize(s.name) === excelName);
            if (!foundStudent) {
                foundStudent = students.find(s => s.email && normalize(s.email) === excelEmail);
            }

            if (foundStudent) {
                matchedUpdates.push({ userid: foundStudent.uid, context: JSON.stringify(surveyData) });
                previewHtml += `<div class="match-row"><span>${row[1]}</span> <span class="msg-ok">✔ Match</span></div>`;
            } else {
                previewHtml += `<div class="match-row"><span>${row[1]}</span> <span class="msg-err">✘ No encontrado</span></div>`;
            }
        }

        reportDiv.innerHTML = previewHtml;
        reportDiv.style.display = 'block';

        if (matchedUpdates.length > 0) {
            pendingUpdates = matchedUpdates;
            uploadBtn.textContent = `Confirmar carga de ${matchedUpdates.length} estudiantes`;
            uploadBtn.style.background = '#059669';
        } else {
            alert('No se encontraron coincidencias. Revisa si los nombres o correos coinciden con Moodle.');
        }
    };
    reader.readAsArrayBuffer(file);
}

function confirmAndUpload() {
    if (!pendingUpdates) return;

    const formData = new FormData();
    formData.append('action', 'bulk_context');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('data', JSON.stringify(pendingUpdates));
    formData.append('sesskey', M.cfg.sesskey);

    const uploadBtn = document.getElementById('uploadBtn');
    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Procesando...';

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(res => {
        if (res.status === 'success') {
            alert(`¡Éxito! Se actualizaron ${pendingUpdates.length} estudiantes.`);
            location.reload();
        } else {
            alert('Error al guardar: ' + (res.message || 'Error desconocido'));
            uploadBtn.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error en la comunicación con el servidor.');
        uploadBtn.disabled = false;
    });
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  loadTranslations(); // Load JSON on startup (calls syncUI + render internally)
});
</script>

<?php
echo $OUTPUT->footer();
