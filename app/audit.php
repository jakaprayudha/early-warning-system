<?php

declare(strict_types=1);

const AUDIT_PAGE_SIZE = 25;
const AUDIT_EXPORT_MAX = 10000;

function audit_sources(): array
{
    return ['access' => 'Konfigurasi & akses', 'hazard' => 'Jenis bahaya', 'event' => 'Penanganan kejadian'];
}

function audit_filters(array $source): array
{
    $date = static fn(mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v . ' UTC') !== false ? $v : '';
    $src = is_string($source['source'] ?? null) && isset(audit_sources()[$source['source']]) ? $source['source'] : '';
    $q = is_string($source['q'] ?? null) ? mb_substr(trim($source['q']), 0, 100) : '';
    $action = is_string($source['action'] ?? null) ? mb_substr(trim($source['action']), 0, 60) : '';

    return [
        'source' => $src, 'actor' => (int) filter_var($source['actor'] ?? 0, FILTER_VALIDATE_INT),
        'action' => $action, 'q' => $q, 'from' => $date($source['from'] ?? ''), 'to' => $date($source['to'] ?? ''),
        'page' => max(1, (int) filter_var($source['p'] ?? 1, FILTER_VALIDATE_INT)),
    ];
}

function audit_union_sql(): string
{
    return 'SELECT "access" AS source, l.id, l.created_at, l.actor_id, l.action, l.details, l.reason, l.target_user_id AS ref
            FROM access_audit_log l
            UNION ALL
            SELECT "hazard", h.id, h.created_at, h.actor_id, "hazard." || h.action,
                   json_object("code", h.hazard_code, "before", h.old_values, "after", h.new_values), h.reason, NULL
            FROM hazard_type_audit_log h
            UNION ALL
            SELECT "event", e.id, e.created_at, e.actor_id, "event." || e.action, e.details, "", e.event_id
            FROM alert_event_log e';
}

function audit_where(array $filters, array &$params): string
{
    $where = [];
    if ($filters['source'] !== '') {
        $where[] = 'a.source = ?';
        $params[] = $filters['source'];
    }
    if ($filters['actor'] > 0) {
        $where[] = 'a.actor_id = ?';
        $params[] = $filters['actor'];
    }
    if ($filters['action'] !== '') {
        $where[] = 'a.action LIKE ? ESCAPE "\\"';
        $params[] = addcslashes($filters['action'], '%_\\') . '%';
    }
    if ($filters['from'] !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = strtotime($filters['from'] . ' 00:00:00 UTC');
    }
    if ($filters['to'] !== '') {
        $where[] = 'a.created_at < ?';
        $params[] = strtotime($filters['to'] . ' +1 day 00:00:00 UTC');
    }
    if ($filters['q'] !== '') {
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where[] = '(a.details LIKE ? ESCAPE "\\" OR a.reason LIKE ? ESCAPE "\\" OR a.action LIKE ? ESCAPE "\\" OR u.name LIKE ? ESCAPE "\\")';
        array_push($params, $like, $like, $like, $like);
    }

    return $where === [] ? '1' : implode(' AND ', $where);
}

function audit_count(array $filters): int
{
    $params = [];
    $where = audit_where($filters, $params);
    $statement = db()->prepare('SELECT COUNT(*) FROM (' . audit_union_sql() . ') a LEFT JOIN users u ON u.id = a.actor_id WHERE ' . $where);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

function audit_rows(array $filters, int $limit, int $offset): array
{
    $params = [];
    $where = audit_where($filters, $params);
    $statement = db()->prepare(
        'SELECT a.*, u.name AS actor_name, u.email AS actor_email FROM (' . audit_union_sql() . ') a
         LEFT JOIN users u ON u.id = a.actor_id WHERE ' . $where . '
         ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function audit_actions(): array
{
    return db()->query('SELECT DISTINCT action FROM (' . audit_union_sql() . ') ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
}

function audit_actors(): array
{
    return db()->query(
        'SELECT DISTINCT u.id, u.name FROM users u WHERE u.id IN (
            SELECT actor_id FROM access_audit_log UNION SELECT actor_id FROM hazard_type_audit_log UNION SELECT actor_id FROM alert_event_log
         ) ORDER BY u.name COLLATE NOCASE'
    )->fetchAll();
}

function audit_action_label(string $action): string
{
    [$group, $name] = array_pad(explode('.', $action, 2), 2, '');
    $groups = [
        'user' => 'Pengguna', 'region' => 'Wilayah', 'location' => 'Lokasi', 'sensor' => 'Sensor', 'parameter' => 'Parameter',
        'threshold' => 'Ambang', 'rule' => 'Aturan', 'recipient_group' => 'Kelompok penerima', 'recipient_member' => 'Anggota penerima',
        'integration_token' => 'Token integrasi', 'ingest' => 'Ingest data', 'report' => 'Laporan', 'hazard' => 'Jenis bahaya', 'event' => 'Kejadian',
    ];
    $verbs = [
        'created' => 'dibuat', 'updated' => 'diubah', 'deleted' => 'dihapus', 'approved' => 'disetujui', 'pending' => 'diajukan',
        'rejected' => 'ditolak', 'access_updated' => 'akses diubah', 'initial_admin_created' => 'admin awal dibuat',
        'version_created' => 'versi baru', 'step_added' => 'langkah eskalasi ditambah', 'data_recorded' => 'data dicatat',
        'ingest_config' => 'rentang valid diubah', 'manual' => 'input manual', 'csv' => 'impor CSV', 'exported' => 'diekspor',
        'disable_token' => 'dinonaktifkan', 'enable_token' => 'diaktifkan', 'delete_token' => 'dihapus', 'reset_requested' => 'reset password diminta',
    ];

    return ($groups[$group] ?? ucfirst($group)) . ($name !== '' ? ' ' . ($verbs[$name] ?? str_replace('_', ' ', $name)) : '');
}

function audit_action_tone(string $action): string
{
    return match (true) {
        str_contains($action, 'deleted') || str_contains($action, 'delete_token') || str_contains($action, 'rejected') => 'danger',
        str_contains($action, 'created') || str_contains($action, 'approved') => 'good',
        str_contains($action, 'access') || str_contains($action, 'reset') || str_contains($action, 'token') || str_contains($action, 'exported') => 'warn',
        default => 'info',
    };
}

function audit_details_text(string $details): string
{
    $decoded = json_decode($details, true);
    if (!is_array($decoded)) {
        return $details;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $details;
}

function handle_audit_export(array $user, array $filters): never
{
    $rows = audit_rows($filters, AUDIT_EXPORT_MAX, 0);
    location_audit((int) $user['id'], 'report.exported', ['type' => 'audit', 'rows' => count($rows)] + array_filter($filters, static fn(mixed $v): bool => $v !== '' && $v !== 0), 'Ekspor audit');
    $generator = (static function () use ($rows): Generator {
        foreach ($rows as $r) {
            yield [
                gmdate('Y-m-d H:i:s', (int) $r['created_at']), audit_sources()[$r['source']], $r['actor_name'] ?? 'Sistem',
                $r['action'], audit_action_label($r['action']), $r['reason'], preg_replace('/\s+/', ' ', $r['details']),
            ];
        }
    })();
    stream_csv('audit-aktivitas', ['Waktu (UTC)', 'Sumber', 'Pelaku', 'Aksi', 'Deskripsi', 'Alasan', 'Detail'], $generator);
}
