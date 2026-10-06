<?php
declare(strict_types=1);

const NOTIFY_MAX_ATTEMPTS = 3;

function notification_severity_label(string $severity): string
{
    return ['watch' => 'WASPADA', 'warning' => 'AWAS', 'danger' => 'BAHAYA', 'emergency' => 'DARURAT'][$severity] ?? strtoupper($severity);
}

// Dijalankan di dalam transaksi evaluator: hanya mengantre; pengiriman dilakukan setelah commit.
function queue_notifications(int $eventId, string $groupName, string $channels, string $stage, int $now): array
{
    $group = db()->prepare('SELECT id FROM recipient_groups WHERE name = ? COLLATE NOCASE AND is_active = 1');
    $group->execute([$groupName]);
    $groupId = $group->fetchColumn();
    $counts = ['email' => 0, 'skipped' => 0];
    if ($groupId === false) {
        return $counts;
    }
    $members = db()->prepare('SELECT name, email, phone FROM recipient_members WHERE group_id = ? AND is_active = 1');
    $members->execute([$groupId]);
    $insert = db()->prepare(
        'INSERT OR IGNORE INTO notification_log (event_id, stage, channel, recipient_name, address, status, error, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($members->fetchAll() as $member) {
        foreach (rule_channel_list($channels) as $channel) {
            if ($channel === 'dashboard') {
                continue;
            }
            if ($channel === 'email' && filter_var($member['email'], FILTER_VALIDATE_EMAIL) !== false) {
                $insert->execute([$eventId, $stage, 'email', $member['name'], $member['email'], 'pending', '', $now, $now]);
                $counts['email'] += $insert->rowCount();
            } elseif ($channel !== 'email') {
                $address = $member['phone'];
                $insert->execute([$eventId, $stage, $channel, $member['name'], $address, 'skipped', 'Kanal belum didukung', $now, $now]);
                $counts['skipped'] += $insert->rowCount();
            }
        }
    }

    return $counts;
}

function notification_email_body(array $event, string $stage): string
{
    $base = env_value('APP_BASE_URL');
    $lines = [
        $stage === 'created' ? 'PERINGATAN BARU' : 'ESKALASI - peringatan belum diakui',
        '',
        'Tingkat    : ' . notification_severity_label((string) $event['severity']),
        'Bahaya     : ' . $event['hazard_type'],
        'Lokasi     : ' . $event['location_name'],
        'Indikator  : ' . $event['trigger_indicator'],
        'Nilai      : ' . $event['trigger_value'] . ' (ambang ' . $event['threshold_value'] . ')',
        'Waktu      : ' . date('d M Y H:i', (int) $event['started_at']),
        'Sumber     : ' . $event['source_label'],
    ];
    if ($base !== null) {
        $lines[] = '';
        $lines[] = 'Buka kejadian: ' . rtrim($base, '/') . '/?page=alerts';
    }
    $lines[] = '';
    $lines[] = 'Pesan otomatis dari Early Warning System. Segera akui peringatan ini di dashboard.';

    return implode("\n", $lines) . "\n";
}

function deliver_pending_notifications(int $limit = 50): array
{
    $result = ['sent' => 0, 'failed' => 0];
    if (smtp_config() === null) {
        return $result;
    }
    $rows = db()->prepare(
        'SELECT n.*, e.severity, e.hazard_type, e.location_name, e.trigger_indicator, e.trigger_value, e.threshold_value,
                e.started_at, e.source_label
         FROM notification_log n JOIN alert_events e ON e.id = n.event_id
         WHERE n.status = "pending" AND n.channel = "email" ORDER BY n.id LIMIT ' . $limit
    );
    $rows->execute();
    $update = db()->prepare('UPDATE notification_log SET status = ?, error = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?');
    foreach ($rows->fetchAll() as $row) {
        $subject = '[EWS] ' . ($row['stage'] === 'created' ? 'Peringatan ' : 'ESKALASI ') . notification_severity_label((string) $row['severity'])
            . ' - ' . $row['location_name'];
        try {
            send_smtp_mail($row['address'], $subject, notification_email_body($row, (string) $row['stage']));
            $update->execute(['sent', '', time(), $row['id']]);
            $result['sent']++;
        } catch (Throwable $error) {
            $final = (int) $row['attempts'] + 1 >= NOTIFY_MAX_ATTEMPTS;
            $update->execute([$final ? 'failed' : 'pending', substr($error->getMessage(), 0, 250), time(), $row['id']]);
            $result['failed'] += $final ? 1 : 0;
        }
    }

    return $result;
}
