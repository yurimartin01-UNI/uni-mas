<?php
/**
 * Purge Data Script for Uni+ (Uni+)
 * This script wipes all student monitoring data, AI recommendations, and teacher actions.
 * Use this before exporting the plugin or when starting a new academic period.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

global $DB, $OUTPUT;

echo "--- Uni+ Data Purge Tool ---\n";
echo "Purging local_unimas tables...\n";

try {
    // 1. Purge AI Cache (Recommendations)
    $DB->delete_records('local_unimas_ai_cache');
    echo "[OK] AI Cache cleared.\n";

    // 2. Purge Teacher Actions (Interventions)
    $DB->delete_records('local_unimas_actions');
    echo "[OK] Teacher actions cleared.\n";

    // 3. Purge Student Context (Qualitative data)
    $DB->delete_records('local_unimas_context');
    echo "[OK] Student context cleared.\n";

    // 4. Purge Success Indicators (Statistics)
    $DB->delete_records('local_unimas_indicators');
    echo "[OK] Success indicators cleared.\n";

    echo "\nAll Uni+ data has been successfully deleted. The plugin is now a clean slate.\n";
} catch (Exception $e) {
    echo "\n[ERROR] Failed to purge data: " . $e->getMessage() . "\n";
    exit(1);
}
