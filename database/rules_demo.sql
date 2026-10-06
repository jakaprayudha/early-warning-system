-- Data demo Aturan & eskalasi (FR-06). Idempoten; butuh thresholds_demo.sql terlebih dahulu.
INSERT INTO alert_rules (name, hazard_code, region_id, severity, combine_mode, recipient_group, channels, repeat_interval_minutes, ack_timeout_minutes, is_active, notes, created_at, updated_at)
SELECT 'Banjir Bekasi - Waspada', 'river_flood', 2, 'watch', 'all', 'Operator BPBD', 'dashboard,email', 30, 15, 1, 'Seed demo', strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM alert_rules WHERE name = 'Banjir Bekasi - Waspada');
INSERT INTO alert_rules (name, hazard_code, region_id, severity, combine_mode, recipient_group, channels, repeat_interval_minutes, ack_timeout_minutes, is_active, notes, created_at, updated_at)
SELECT 'Banjir Bekasi - Awas', 'river_flood', 2, 'warning', 'any', 'Kepala Pelaksana', 'dashboard,sms,whatsapp', 10, 10, 1, 'Seed demo', strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM alert_rules WHERE name = 'Banjir Bekasi - Awas');
INSERT OR IGNORE INTO alert_rule_conditions (rule_id, threshold_id)
SELECT r.id, t.id FROM alert_rules r, thresholds t WHERE r.name = 'Banjir Bekasi - Waspada' AND t.value = 150 AND t.approval_status = 'approved';
INSERT OR IGNORE INTO alert_rule_conditions (rule_id, threshold_id)
SELECT r.id, t.id FROM alert_rules r, thresholds t WHERE r.name = 'Banjir Bekasi - Awas' AND t.value = 250 AND t.approval_status = 'approved';
INSERT INTO alert_rule_escalations (rule_id, after_minutes, recipient_group, channels)
SELECT id, 30, 'Koordinator Lapangan', 'sms' FROM alert_rules WHERE name = 'Banjir Bekasi - Awas'
AND NOT EXISTS (SELECT 1 FROM alert_rule_escalations WHERE rule_id = alert_rules.id);
INSERT INTO alert_rule_escalations (rule_id, after_minutes, recipient_group, channels)
SELECT id, 60, 'Kepala Pelaksana BPBD', 'sms,whatsapp' FROM alert_rules WHERE name = 'Banjir Bekasi - Awas'
AND (SELECT COUNT(*) FROM alert_rule_escalations WHERE rule_id = alert_rules.id) = 1;
