<?php

declare(strict_types=1);

function ingest_status_class(string $status): string
{
    return 'ingest-' . str_replace('_', '-', $status);
}

function render_integrations_page(array $user, ?string $message, ?string $error, ?string $newToken): void
{
    $labels = region_path_labels(user_regions($user));
    $sensors = list_sensors($user);
    $summary = ingest_summary($user);
    $tokens = list_integration_tokens($user);
    $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
    $sensorFilter = (int) filter_var($_GET['sensor'] ?? 0, FILTER_VALIDATE_INT);
    $readings = list_readings($user, $statusFilter, $sensorFilter);
    $action = '/?page=dashboard&amp;section=integrations';
    $csrf = e(csrf_token());
    $endpoint = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '/?page=api-ingest';
    $tileClass = ['accepted' => ' approval-tile-approved', 'late' => ' approval-tile-pending', 'duplicate' => '', 'out_of_range' => ' approval-tile-pending', 'invalid' => ' approval-tile-rejected'];
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Data sensor masuk lewat API bertoken, input manual terkontrol, atau impor CSV. Setiap pembacaan dicatat dengan waktu sumber dan waktu penerimaan, lalu ditandai bila duplikat, terlambat, tidak valid, atau di luar rentang. Waktu tanpa zona dibaca sebagai UTC.</p>
        <?php render_notices($message, $error); ?>
        <?php if ($newToken !== null): ?>
            <div class="token-reveal" role="status"><strong>Token baru — salin sekarang, tidak akan ditampilkan lagi.</strong><code><?= e($newToken) ?></code></div>
        <?php endif; ?>
        <div class="sensor-summary threshold-summary">
            <?php foreach (ingest_statuses() as $key => $label): ?>
                <div class="sensor-summary-item approval-tile<?= $tileClass[$key] ?>"><strong><?= (int) $summary[$key] ?></strong><span><?= e($label) ?> · 24 jam</span></div>
            <?php endforeach; ?>
        </div>

        <section class="panel hazard-create-panel">
            <details class="create-details">
                <summary><span>+ Input manual</span></summary>
                <form class="hazard-form" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="manual_reading">
                    <label>Sensor<select name="sensor_id" required><option value="">Pilih sensor</option><?php foreach ($sensors as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['code']) ?> — <?= e($s['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Nilai<input name="value" maxlength="60" required inputmode="decimal"></label>
                    <label>Waktu sumber (opsional)<input name="source_time" maxlength="40" placeholder="2026-10-06T08:00:00+07:00"></label>
                    <label class="hazard-reason">Alasan<input name="reason" maxlength="500" required></label>
                    <button class="save-button" type="submit">Catat pembacaan</button>
                </form>
            </details>
            <details class="create-details">
                <summary><span>+ Impor CSV</span></summary>
                <form class="hazard-form" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="csv_import">
                    <label class="hazard-description">Data (sensor_code,value,timestamp — maks. <?= INGEST_MAX_CSV_LINES ?> baris)<textarea name="csv" rows="6" maxlength="60000" required placeholder="SN-CITARUM-01,182.5,2026-10-06T08:00:00+07:00"></textarea></label>
                    <label class="hazard-reason">Alasan<input name="reason" maxlength="500" required></label>
                    <button class="save-button" type="submit">Impor</button>
                </form>
            </details>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">API</p><h2>Token integrasi <span><?= count($tokens) ?></span></h2></div></div>
            <details class="create-details">
                <summary><span>+ Buat token</span></summary>
                <form class="hazard-form" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_token">
                    <label>Nama token<input name="name" maxlength="100" required placeholder="contoh: Gateway Citarum"></label>
                    <label>Cakupan wilayah<select name="region_id" required><option value="">Pilih wilayah</option><?php render_region_options($labels, null); ?></select></label>
                    <label class="hazard-reason">Alasan<input name="reason" maxlength="500" required></label>
                    <button class="save-button" type="submit">Buat token</button>
                </form>
            </details>
            <?php if ($tokens === []): ?><p class="scope-hint">Belum ada token.</p><?php endif; ?>
            <ul class="token-list">
                <?php foreach ($tokens as $t): $on = (int) $t['is_active'] === 1; ?>
                    <li class="<?= $on ? '' : 'is-off' ?>">
                        <span class="parameter-icon">🔑</span>
                        <div class="member-info"><strong><?= e($t['name']) ?></strong>
                            <small><code><?= e($t['token_prefix']) ?>…</code> · <?= e($t['region_name']) ?> · terakhir dipakai <?= $t['last_used_at'] === null ? 'belum pernah' : e(date('d M Y H:i', (int) $t['last_used_at'])) ?></small></div>
                        <span class="status-pill <?= $on ? 'approval-approved' : 'approval-draft' ?>"><?= $on ? 'Aktif' : 'Nonaktif' ?></span>
                        <form class="token-actions" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                            <input name="reason" maxlength="500" required placeholder="Alasan" aria-label="Alasan">
                            <button class="save-button" type="submit" name="action" value="<?= $on ? 'disable_token' : 'enable_token' ?>"><?= $on ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                            <button class="danger-button" type="submit" name="action" value="delete_token">Hapus</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <details class="edit-details">
                <summary>Dokumentasi endpoint</summary>
                <pre class="api-doc">POST <?= e($endpoint) ?>
Authorization: Bearer &lt;token&gt;
Content-Type: application/json

{"readings":[
  {"sensor_code":"SN-CITARUM-01","value":182.5,"timestamp":"2026-10-06T08:00:00+07:00"}
]}

Maks. <?= INGEST_MAX_BATCH ?> pembacaan/permintaan. Timestamp: ISO 8601 atau epoch detik/milidetik (kosong = waktu terima).
Status per pembacaan: accepted, late, duplicate, out_of_range, invalid, unknown_sensor.</pre>
            </details>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">VALIDASI</p><h2>Rentang valid sensor</h2></div></div>
            <?php foreach ($sensors as $s): $c = ingest_config((int) $s['id']); ?>
                <form class="config-row" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_config"><input type="hidden" name="sensor_id" value="<?= (int) $s['id'] ?>">
                    <div class="member-info"><strong><?= e($s['code']) ?></strong><small><?= e($s['name']) ?><?= $s['unit'] !== '' ? ' · ' . e($s['unit']) : '' ?></small></div>
                    <input name="valid_min" value="<?= e((string) ($c['valid_min'] ?? '')) ?>" placeholder="min" aria-label="Batas bawah" inputmode="decimal">
                    <input name="valid_max" value="<?= e((string) ($c['valid_max'] ?? '')) ?>" placeholder="maks" aria-label="Batas atas" inputmode="decimal">
                    <input name="late_after" value="<?= (int) $c['late_after_minutes'] ?>" aria-label="Terlambat setelah (menit)" title="Terlambat setelah (menit)" inputmode="numeric">
                    <input name="reason" maxlength="500" required placeholder="Alasan" aria-label="Alasan">
                    <button class="save-button" type="submit">Simpan</button>
                </form>
            <?php endforeach; ?>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">RIWAYAT</p><h2>Pembacaan terbaru <span><?= count($readings) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="integrations">
                <select name="status" aria-label="Status"><option value="">Semua status</option><?php foreach (ingest_statuses() as $k => $l): ?><option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select name="sensor" aria-label="Sensor"><option value="0">Semua sensor</option><?php foreach ($sensors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sensorFilter === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code']) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <div class="reading-table-wrap">
                <table class="reading-table">
                    <thead><tr><th>Sensor</th><th>Nilai</th><th>Waktu sumber</th><th>Diterima</th><th>Kanal</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($readings === []): ?><tr><td colspan="6">Belum ada pembacaan.</td></tr><?php endif; ?>
                    <?php foreach ($readings as $r): ?>
                        <tr>
                            <td><strong><?= e($r['sensor_code']) ?></strong><small><?= e($r['sensor_name']) ?></small></td>
                            <td><?= e($r['raw_value']) ?> <?= e($r['unit']) ?></td>
                            <td><?= $r['source_ts'] === null ? '—' : e(date('d M H:i:s', (int) $r['source_ts'])) ?></td>
                            <td><?= e(date('d M H:i:s', (int) $r['received_at'])) ?></td>
                            <td><?= e(ingest_channels()[$r['channel']]) ?><?= $r['token_name'] !== null ? '<small>' . e($r['token_name']) . '</small>' : '' ?></td>
                            <td><span class="ingest-badge <?= e(ingest_status_class($r['status'])) ?>" title="<?= e($r['note']) ?>"><?= e(ingest_statuses()[$r['status']]) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php
}
