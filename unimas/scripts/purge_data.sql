-- Uni+ (AreteIA) Data Purge SQL
-- Run these commands in your Moodle database (e.g., via phpMyAdmin) 
-- to completely wipe student data, AI feedback, and interventions.
-- Note: Replace 'mdl_' with your actual Moodle database prefix if it differs.

TRUNCATE TABLE mdl_local_unimas_ai_cache;
TRUNCATE TABLE mdl_local_unimas_actions;
TRUNCATE TABLE mdl_local_unimas_context;
TRUNCATE TABLE mdl_local_unimas_indicators;

-- If you want to confirm they are empty:
-- SELECT COUNT(*) FROM mdl_local_unimas_ai_cache;
-- SELECT COUNT(*) FROM mdl_local_unimas_actions;
