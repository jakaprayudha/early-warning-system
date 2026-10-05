USE db_ews;

CREATE TABLE IF NOT EXISTS alert_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hazard_type VARCHAR(32) NOT NULL,
    region_id BIGINT UNSIGNED NOT NULL,
    location_name VARCHAR(255) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    trigger_indicator VARCHAR(255) NOT NULL,
    trigger_value VARCHAR(255) NOT NULL,
    threshold_value VARCHAR(255) NOT NULL DEFAULT '',
    source_label VARCHAR(255) NOT NULL DEFAULT '',
    handling_status VARCHAR(20) NOT NULL DEFAULT 'open',
    acknowledged_by BIGINT UNSIGNED NULL,
    acknowledged_at BIGINT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at BIGINT NOT NULL,
    closed_at BIGINT NULL,
    close_reason VARCHAR(1000) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY alert_events_status_region (handling_status, region_id, started_at),
    KEY alert_events_hazard_severity (hazard_type, severity, started_at),
    CONSTRAINT fk_alert_events_region
        FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_alert_events_acknowledged_by
        FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_events_assigned_to
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_events_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_event_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    details VARCHAR(1000) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY alert_event_log_event_time (event_id, created_at),
    CONSTRAINT fk_alert_event_log_event
        FOREIGN KEY (event_id) REFERENCES alert_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_alert_event_log_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
VALUES ('DEMO-REGION-JABAR', 'Jawa Barat (Demo)', NULL, UNIX_TIMESTAMP());

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
SELECT 'DEMO-REGION-BEKASI', 'Kabupaten Bekasi (Demo)', id, UNIX_TIMESTAMP()
FROM regions WHERE code = 'DEMO-REGION-JABAR';

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
SELECT 'DEMO-REGION-BANDUNG', 'Kota Bandung (Demo)', id, UNIX_TIMESTAMP()
FROM regions WHERE code = 'DEMO-REGION-JABAR';

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
SELECT 'DEMO-REGION-CIREBON', 'Kabupaten Cirebon (Demo)', id, UNIX_TIMESTAMP()
FROM regions WHERE code = 'DEMO-REGION-JABAR';

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
VALUES ('DEMO-REGION-DKI', 'DKI Jakarta (Demo)', NULL, UNIX_TIMESTAMP());

INSERT IGNORE INTO regions (code, name, parent_id, created_at)
SELECT 'DEMO-REGION-JAKUT', 'Jakarta Utara (Demo)', id, UNIX_TIMESTAMP()
FROM regions WHERE code = 'DEMO-REGION-DKI';

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'river_flood', regions.id, 'Pos Pantau Sungai Citarum - Bekasi', 'watch',
    'Tinggi muka air', '82 cm', '75 cm', 'DEMO-SEED: laporan simulasi 001',
    'open', NULL, NULL, NULL,
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR), NULL, '',
    UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR),
    UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR)
FROM regions
WHERE regions.code = 'DEMO-REGION-BEKASI'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 001'
  );

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'weather', regions.id, 'Stasiun Hujan Dago - Bandung', 'alert',
    'Intensitas hujan', '68 mm/jam', '50 mm/jam',
    'DEMO-SEED: laporan simulasi 002', 'open',
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 25 MINUTE),
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 40 MINUTE), NULL, '',
    UNIX_TIMESTAMP(NOW() - INTERVAL 40 MINUTE),
    UNIX_TIMESTAMP(NOW() - INTERVAL 25 MINUTE)
FROM regions
WHERE regions.code = 'DEMO-REGION-BANDUNG'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 002'
  );

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'coastal_tide', regions.id, 'Pesisir Muara Angke - Jakarta Utara', 'alert',
    'Tinggi pasang', '1,4 m', '1,2 m',
    'DEMO-SEED: laporan simulasi 003', 'closed',
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 47 HOUR), NULL,
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 2 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 45 HOUR),
    'Pasang kembali normal dan pemantauan lapangan selesai.',
    UNIX_TIMESTAMP(NOW() - INTERVAL 2 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 45 HOUR)
FROM regions
WHERE regions.code = 'DEMO-REGION-JAKUT'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 003'
  );

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'tornado', regions.id, 'Kecamatan Lembang - Bandung', 'warning',
    'Kecepatan angin', '72 km/jam', '60 km/jam',
    'DEMO-SEED: laporan simulasi 004', 'closed',
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 4 DAY - INTERVAL 22 HOUR), NULL,
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 5 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 4 DAY - INTERVAL 20 HOUR),
    'Kondisi angin mereda dan tidak ada laporan dampak lanjutan.',
    UNIX_TIMESTAMP(NOW() - INTERVAL 5 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 4 DAY - INTERVAL 20 HOUR)
FROM regions
WHERE regions.code = 'DEMO-REGION-BANDUNG'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 004'
  );

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'weather', regions.id, 'Pos Pengamatan Cirebon', 'watch',
    'Curah hujan harian', '91 mm', '80 mm',
    'DEMO-SEED: laporan simulasi 005', 'closed',
    NULL, NULL, NULL,
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 8 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY - INTERVAL 20 HOUR),
    'Curah hujan turun di bawah ambang pemantauan.',
    UNIX_TIMESTAMP(NOW() - INTERVAL 8 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY - INTERVAL 20 HOUR)
FROM regions
WHERE regions.code = 'DEMO-REGION-CIREBON'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 005'
  );

INSERT INTO alert_events (
    hazard_type, region_id, location_name, severity, trigger_indicator,
    trigger_value, threshold_value, source_label, handling_status,
    acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
    closed_at, close_reason, created_at, updated_at
)
SELECT
    'river_flood', regions.id, 'Pos Pantau Kali Bekasi', 'alert',
    'Tinggi muka air', '145 cm', '120 cm',
    'DEMO-SEED: laporan simulasi 006', 'closed',
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 13 DAY - INTERVAL 1 HOUR), NULL,
    (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
    UNIX_TIMESTAMP(NOW() - INTERVAL 13 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 12 DAY - INTERVAL 20 HOUR),
    'Muka air sungai stabil dan status diturunkan setelah verifikasi.',
    UNIX_TIMESTAMP(NOW() - INTERVAL 13 DAY),
    UNIX_TIMESTAMP(NOW() - INTERVAL 12 DAY - INTERVAL 20 HOUR)
FROM regions
WHERE regions.code = 'DEMO-REGION-BEKASI'
  AND NOT EXISTS (
      SELECT 1 FROM alert_events
      WHERE source_label = 'DEMO-SEED: laporan simulasi 006'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT events.id,
       (SELECT id FROM users WHERE role = 'system_admin' AND status = 'active' ORDER BY id LIMIT 1),
       'created', 'Peringatan demo dicatat secara manual.', events.started_at
FROM alert_events AS events
WHERE events.source_label LIKE 'DEMO-SEED:%'
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'created'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT id, acknowledged_by, 'acknowledge',
       'Kejadian diakui oleh petugas.', acknowledged_at
FROM alert_events AS events
WHERE source_label LIKE 'DEMO-SEED:%'
  AND acknowledged_at IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'acknowledge'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT id, assigned_to, 'assign',
       'Penanggung jawab ditetapkan untuk penanganan demo.', updated_at
FROM alert_events AS events
WHERE source_label = 'DEMO-SEED: laporan simulasi 002'
  AND assigned_to IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'assign'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT id, created_by, 'escalate',
       'Tingkat peringatan dinaikkan menjadi Awas.', closed_at
FROM alert_events AS events
WHERE source_label = 'DEMO-SEED: laporan simulasi 004'
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'escalate'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT id, created_by, 'note',
       'Petugas melakukan verifikasi kondisi di lapangan.', updated_at
FROM alert_events AS events
WHERE source_label = 'DEMO-SEED: laporan simulasi 002'
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'note'
  );

INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
SELECT id, created_by, 'close', close_reason, closed_at
FROM alert_events AS events
WHERE source_label LIKE 'DEMO-SEED:%'
  AND handling_status = 'closed'
  AND NOT EXISTS (
      SELECT 1 FROM alert_event_log
      WHERE event_id = events.id AND action = 'close'
  );

COMMIT;