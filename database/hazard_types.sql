-- Create and seed the EWS hazard catalog in SQLite.
-- Application startup applies the corresponding schema migration to alert_events.
BEGIN IMMEDIATE;

CREATE TABLE IF NOT EXISTS hazard_types (
    code TEXT PRIMARY KEY COLLATE NOCASE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    icon TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#27856E',
    default_unit TEXT NOT NULL DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS hazard_type_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hazard_code TEXT NOT NULL,
    actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL CHECK (action IN ('created', 'updated', 'deleted')),
    old_values TEXT NOT NULL DEFAULT '',
    new_values TEXT NOT NULL DEFAULT '',
    reason TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

INSERT INTO hazard_types
    (code, name, description, icon, color, default_unit, is_active, created_at, updated_at)
VALUES
    ('weather', 'Cuaca', 'Cuaca ekstrem dan kondisi meteorologi.', '☁', '#3986C6', 'mm/jam', 1, CAST(strftime('%s', 'now') AS INTEGER), CAST(strftime('%s', 'now') AS INTEGER)),
    ('tornado', 'Tornado', 'Angin puting beliung dan pusaran angin.', '↻', '#8C63B8', 'km/jam', 1, CAST(strftime('%s', 'now') AS INTEGER), CAST(strftime('%s', 'now') AS INTEGER)),
    ('river_flood', 'Banjir sungai', 'Kenaikan muka air dan luapan sungai.', '≋', '#D18A32', 'cm', 1, CAST(strftime('%s', 'now') AS INTEGER), CAST(strftime('%s', 'now') AS INTEGER)),
    ('coastal_tide', 'Pasang surut pantai/muara', 'Pasang tinggi dan genangan pesisir atau muara.', '≈', '#278F91', 'm', 1, CAST(strftime('%s', 'now') AS INTEGER), CAST(strftime('%s', 'now') AS INTEGER))
ON CONFLICT(code) DO NOTHING;

COMMIT;
