<?php
declare(strict_types=1);

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_name('ews_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('Unable to start the session.');
    }
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $databasePath = env_value(
        'EWS_DB_PATH',
        dirname(__DIR__) . '/storage/db_ews.sqlite'
    );
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the database directory.');
    }

    $connection = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            role TEXT NOT NULL DEFAULT "observer"
                CHECK (role IN ("system_admin", "master_data_manager", "operator", "observer", "field_officer")),
            status TEXT NOT NULL DEFAULT "pending"
                CHECK (status IN ("pending", "active", "suspended"))
        )'
    );
    $userColumns = $connection->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('role', $userColumns, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT "observer"');
    }
    if (!in_array('status', $userColumns, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT "active"');
    }
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            parent_id INTEGER REFERENCES regions(id) ON DELETE RESTRICT,
            created_at INTEGER NOT NULL,
            CHECK (parent_id IS NULL OR parent_id != id)
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS user_regions (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE CASCADE,
            PRIMARY KEY (user_id, region_id)
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS hazard_types (
            code TEXT PRIMARY KEY COLLATE NOCASE,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            icon TEXT NOT NULL DEFAULT "",
            color TEXT NOT NULL DEFAULT "#27856E",
            default_unit TEXT NOT NULL DEFAULT "",
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS hazard_type_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hazard_code TEXT NOT NULL,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL CHECK (action IN ("created", "updated", "deleted")),
            old_values TEXT NOT NULL DEFAULT "",
            new_values TEXT NOT NULL DEFAULT "",
            reason TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'INSERT INTO hazard_types
         (code, name, description, icon, color, default_unit, is_active, created_at, updated_at)
         VALUES
         ("weather", "Cuaca", "Cuaca ekstrem dan kondisi meteorologi.", "☁", "#3986C6", "mm/jam", 1, strftime("%s", "now"), strftime("%s", "now")),
         ("tornado", "Tornado", "Angin puting beliung dan pusaran angin.", "↻", "#8C63B8", "km/jam", 1, strftime("%s", "now"), strftime("%s", "now")),
         ("river_flood", "Banjir sungai", "Kenaikan muka air dan luapan sungai.", "≋", "#D18A32", "cm", 1, strftime("%s", "now"), strftime("%s", "now")),
         ("coastal_tide", "Pasang surut pantai/muara", "Pasang tinggi dan genangan pesisir atau muara.", "≈", "#278F91", "m", 1, strftime("%s", "now"), strftime("%s", "now"))
         ON CONFLICT(code) DO NOTHING'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS access_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            target_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL,
            reason TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $regionColumns = $connection->query('PRAGMA table_info(regions)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('admin_level', $regionColumns, true)) {
        $connection->exec('ALTER TABLE regions ADD COLUMN admin_level TEXT NOT NULL DEFAULT "other"');
    }
    if (!in_array('timezone', $regionColumns, true)) {
        $connection->exec('ALTER TABLE regions ADD COLUMN timezone TEXT NOT NULL DEFAULT "Asia/Jakarta"');
    }
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS monitoring_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
            location_type TEXT NOT NULL DEFAULT "station",
            latitude REAL NOT NULL CHECK (latitude BETWEEN -90 AND 90),
            longitude REAL NOT NULL CHECK (longitude BETWEEN -180 AND 180),
            elevation_m REAL,
            vertical_datum TEXT NOT NULL DEFAULT "",
            geometry_geojson TEXT NOT NULL DEFAULT "",
            managed_by TEXT NOT NULL DEFAULT "",
            notes TEXT NOT NULL DEFAULT "",
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS location_hazards (
            location_id INTEGER NOT NULL REFERENCES monitoring_locations(id) ON DELETE CASCADE,
            hazard_code TEXT NOT NULL COLLATE NOCASE REFERENCES hazard_types(code)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            PRIMARY KEY (location_id, hazard_code)
        )'
    );
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS monitoring_locations_region
         ON monitoring_locations(region_id)'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS sensors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            sensor_type TEXT NOT NULL,
            location_id INTEGER NOT NULL REFERENCES monitoring_locations(id) ON DELETE RESTRICT,
            parameter TEXT NOT NULL,
            unit TEXT NOT NULL DEFAULT "",
            protocol TEXT NOT NULL DEFAULT "manual",
            endpoint TEXT NOT NULL DEFAULT "",
            technical_contact TEXT NOT NULL DEFAULT "",
            expected_interval_minutes INTEGER NOT NULL DEFAULT 15 CHECK (expected_interval_minutes BETWEEN 1 AND 10080),
            status TEXT NOT NULL DEFAULT "active" CHECK (status IN ("active", "maintenance", "inactive")),
            last_heartbeat_at INTEGER,
            last_data_at INTEGER,
            last_value TEXT NOT NULL DEFAULT "",
            notes TEXT NOT NULL DEFAULT "",
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS sensors_location ON sensors(location_id)');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS parameters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            hazard_code TEXT NOT NULL COLLATE NOCASE REFERENCES hazard_types(code)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            unit TEXT NOT NULL DEFAULT "",
            aggregation TEXT NOT NULL DEFAULT "instant"
                CHECK (aggregation IN ("instant", "avg", "sum", "max", "min", "rate")),
            aggregation_minutes INTEGER NOT NULL DEFAULT 0 CHECK (aggregation_minutes BETWEEN 0 AND 10080),
            description TEXT NOT NULL DEFAULT "",
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS thresholds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parameter_id INTEGER NOT NULL REFERENCES parameters(id) ON DELETE RESTRICT,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
            location_id INTEGER REFERENCES monitoring_locations(id) ON DELETE RESTRICT,
            severity TEXT NOT NULL CHECK (severity IN ("watch", "alert", "warning")),
            operator TEXT NOT NULL CHECK (operator IN (">", ">=", "<", "<=")),
            value REAL NOT NULL,
            reset_value REAL,
            persistence_minutes INTEGER NOT NULL DEFAULT 0 CHECK (persistence_minutes BETWEEN 0 AND 10080),
            priority INTEGER NOT NULL DEFAULT 50 CHECK (priority BETWEEN 1 AND 100),
            valid_from TEXT NOT NULL,
            valid_until TEXT,
            version INTEGER NOT NULL DEFAULT 1,
            supersedes_id INTEGER REFERENCES thresholds(id) ON DELETE SET NULL,
            approval_status TEXT NOT NULL DEFAULT "draft"
                CHECK (approval_status IN ("draft", "pending", "approved", "rejected", "superseded", "retired")),
            notes TEXT NOT NULL DEFAULT "",
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            decided_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            decided_at INTEGER,
            decision_reason TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS thresholds_parameter ON thresholds(parameter_id)');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS alert_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            hazard_code TEXT NOT NULL COLLATE NOCASE REFERENCES hazard_types(code)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
            severity TEXT NOT NULL CHECK (severity IN ("watch", "alert", "warning")),
            combine_mode TEXT NOT NULL DEFAULT "all" CHECK (combine_mode IN ("all", "any")),
            recipient_group TEXT NOT NULL,
            channels TEXT NOT NULL,
            repeat_interval_minutes INTEGER NOT NULL DEFAULT 0 CHECK (repeat_interval_minutes BETWEEN 0 AND 10080),
            ack_timeout_minutes INTEGER NOT NULL DEFAULT 30 CHECK (ack_timeout_minutes BETWEEN 1 AND 10080),
            active_from TEXT,
            active_until TEXT,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            notes TEXT NOT NULL DEFAULT "",
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS alert_rule_conditions (
            rule_id INTEGER NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
            threshold_id INTEGER NOT NULL REFERENCES thresholds(id) ON DELETE RESTRICT,
            PRIMARY KEY (rule_id, threshold_id)
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS alert_rule_escalations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rule_id INTEGER NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
            after_minutes INTEGER NOT NULL CHECK (after_minutes BETWEEN 1 AND 10080),
            recipient_group TEXT NOT NULL,
            channels TEXT NOT NULL
        )'
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS alert_rule_escalations_rule ON alert_rule_escalations(rule_id)');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS password_reset_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS password_reset_tokens_user_id
         ON password_reset_tokens(user_id)'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS alert_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hazard_type TEXT NOT NULL REFERENCES hazard_types(code)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
            location_name TEXT NOT NULL,
            severity TEXT NOT NULL CHECK (severity IN ("watch", "alert", "warning")),
            trigger_indicator TEXT NOT NULL,
            trigger_value TEXT NOT NULL,
            threshold_value TEXT NOT NULL DEFAULT "",
            source_label TEXT NOT NULL DEFAULT "",
            handling_status TEXT NOT NULL DEFAULT "open"
                CHECK (handling_status IN ("open", "closed")),
            acknowledged_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            acknowledged_at INTEGER,
            assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            started_at INTEGER NOT NULL,
            closed_at INTEGER,
            close_reason TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $eventTableSql = $connection->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'alert_events'"
    )->fetchColumn();
    if (is_string($eventTableSql) && str_contains($eventTableSql, 'CHECK (hazard_type IN')) {
        $connection->exec('PRAGMA foreign_keys = OFF');
        $connection->beginTransaction();
        try {
            $connection->exec(
                'CREATE TABLE alert_events_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hazard_type TEXT NOT NULL REFERENCES hazard_types(code)
                        ON UPDATE CASCADE ON DELETE RESTRICT,
                    region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
                    location_name TEXT NOT NULL,
                    severity TEXT NOT NULL CHECK (severity IN ("watch", "alert", "warning")),
                    trigger_indicator TEXT NOT NULL,
                    trigger_value TEXT NOT NULL,
                    threshold_value TEXT NOT NULL DEFAULT "",
                    source_label TEXT NOT NULL DEFAULT "",
                    handling_status TEXT NOT NULL DEFAULT "open"
                        CHECK (handling_status IN ("open", "closed")),
                    acknowledged_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    acknowledged_at INTEGER,
                    assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    started_at INTEGER NOT NULL,
                    closed_at INTEGER,
                    close_reason TEXT NOT NULL DEFAULT "",
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )'
            );
            $connection->exec(
                'INSERT INTO alert_events_new
                 (id, hazard_type, region_id, location_name, severity, trigger_indicator,
                  trigger_value, threshold_value, source_label, handling_status,
                  acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
                  closed_at, close_reason, created_at, updated_at)
                 SELECT id, hazard_type, region_id, location_name, severity, trigger_indicator,
                        trigger_value, threshold_value, source_label, handling_status,
                        acknowledged_by, acknowledged_at, assigned_to, created_by, started_at,
                        closed_at, close_reason, created_at, updated_at
                 FROM alert_events'
            );
            $connection->exec('DROP TABLE alert_events');
            $connection->exec('ALTER TABLE alert_events_new RENAME TO alert_events');
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        } finally {
            $connection->exec('PRAGMA foreign_keys = ON');
        }
    }
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS alert_events_status_region
         ON alert_events(handling_status, region_id, started_at)'
    );
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS alert_events_hazard_severity
         ON alert_events(hazard_type, severity, started_at)'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS alert_event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL REFERENCES alert_events(id) ON DELETE CASCADE,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS alert_event_log_event_time
         ON alert_event_log(event_id, created_at)'
    );

    return $connection;
}
