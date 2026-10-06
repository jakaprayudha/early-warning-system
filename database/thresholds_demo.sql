-- Data demo Parameter & Ambang (FR-05). Idempoten; kompatibel SQLite/MySQL.
INSERT INTO parameters (code, name, hazard_code, unit, aggregation, aggregation_minutes, description, is_active, created_at, updated_at)
SELECT 'rain_1h', 'Curah hujan 1 jam', 'weather', 'mm', 'sum', 60, 'Akumulasi hujan per jam', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM parameters WHERE code = 'rain_1h');
INSERT INTO parameters (code, name, hazard_code, unit, aggregation, aggregation_minutes, description, is_active, created_at, updated_at)
SELECT 'wind_speed', 'Kecepatan angin', 'tornado', 'km/jam', 'max', 10, 'Angin maksimum 10 menit', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM parameters WHERE code = 'wind_speed');
INSERT INTO parameters (code, name, hazard_code, unit, aggregation, aggregation_minutes, description, is_active, created_at, updated_at)
SELECT 'water_level', 'Tinggi muka air', 'river_flood', 'cm', 'instant', 0, 'Tinggi muka air sungai', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM parameters WHERE code = 'water_level');
INSERT INTO parameters (code, name, hazard_code, unit, aggregation, aggregation_minutes, description, is_active, created_at, updated_at)
SELECT 'tide_height', 'Tinggi pasang', 'coastal_tide', 'cm', 'max', 60, 'Tinggi pasang maksimum', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM parameters WHERE code = 'tide_height');

-- Ambang approved (2), pending (1), draft (1). Hanya diisi bila tabel ambang masih kosong.
INSERT INTO thresholds (parameter_id, region_id, severity, operator, value, reset_value, persistence_minutes, priority, valid_from, approval_status, created_by, decided_by, decided_at, decision_reason, created_at, updated_at)
SELECT (SELECT id FROM parameters WHERE code='water_level'), 2, 'watch', '>=', 150, 140, 10, 40, '2025-01-01', 'approved',
       (SELECT id FROM users WHERE email='admin@ews.local'), (SELECT id FROM users WHERE email='admin@example.test'), strftime('%s','now'), 'Seed demo', strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM thresholds);
INSERT INTO thresholds (parameter_id, region_id, severity, operator, value, reset_value, persistence_minutes, priority, valid_from, approval_status, created_by, decided_by, decided_at, decision_reason, created_at, updated_at)
SELECT (SELECT id FROM parameters WHERE code='water_level'), 2, 'warning', '>=', 250, 230, 5, 80, '2025-01-01', 'approved',
       (SELECT id FROM users WHERE email='admin@ews.local'), (SELECT id FROM users WHERE email='admin@example.test'), strftime('%s','now'), 'Seed demo', strftime('%s','now'), strftime('%s','now')
WHERE (SELECT COUNT(*) FROM thresholds) = 1;
INSERT INTO thresholds (parameter_id, region_id, severity, operator, value, persistence_minutes, priority, valid_from, approval_status, created_by, created_at, updated_at)
SELECT (SELECT id FROM parameters WHERE code='rain_1h'), 3, 'alert', '>=', 50, 0, 60, '2025-01-01', 'pending',
       (SELECT id FROM users WHERE email='admin@ews.local'), strftime('%s','now'), strftime('%s','now')
WHERE (SELECT COUNT(*) FROM thresholds) = 2;
INSERT INTO thresholds (parameter_id, region_id, severity, operator, value, persistence_minutes, priority, valid_from, approval_status, created_by, created_at, updated_at)
SELECT (SELECT id FROM parameters WHERE code='tide_height'), 6, 'watch', '>', 120, 15, 50, '2025-01-01', 'draft',
       (SELECT id FROM users WHERE email='admin@example.test'), strftime('%s','now'), strftime('%s','now')
WHERE (SELECT COUNT(*) FROM thresholds) = 3;
