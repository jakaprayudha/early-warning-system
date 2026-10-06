<?php

declare(strict_types=1);

function render_sensor_fields(?array $sensor, array $locations): void
{
    $value = static fn(string $key, string $default = ''): string => $sensor === null
        ? $default
        : (string) ($sensor[$key] ?? $default);
    $select = static function (string $name, array $options, string $selected): void {
        echo '<select name="' . e($name) . '" required>';
        foreach ($options as $key => $label) {
            echo '<option value="' . e((string) $key) . '"' . ($selected === (string) $key ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select>';
    };
    ?>
    <label>ID sensor<input name="code" maxlength="40" pattern="[A-Za-z0-9_\x2D]{2,40}" value="<?= e($value('code')) ?>" placeholder="contoh: SN-CITARUM-01" <?= $sensor === null ? 'required' : 'disabled' ?>></label>
    <label>Nama sensor<input name="name" maxlength="120" value="<?= e($value('name')) ?>" required></label>
    <label>Tipe<?php $select('sensor_type', sensor_types(), $value('sensor_type', 'rain_gauge')); ?></label>
    <label>Lokasi
        <select name="location_id" required>
            <option value="">Pilih lokasi</option>
            <?php foreach ($locations as $location): ?>
                <option value="<?= (int) $location['id'] ?>" <?= $sensor !== null && (int) $sensor['location_id'] === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Parameter<input name="parameter" maxlength="60" value="<?= e($value('parameter')) ?>" placeholder="contoh: Tinggi muka air" required></label>
    <label>Parameter operasional (untuk aturan peringatan)
        <select name="parameter_id">
            <option value="">Tidak dipakai evaluasi</option>
            <?php foreach (db()->query('SELECT id, name, unit FROM parameters WHERE is_active = 1 ORDER BY name COLLATE NOCASE')->fetchAll() as $param): ?>
                <option value="<?= (int) $param['id'] ?>" <?= $sensor !== null && (int) ($sensor['parameter_id'] ?? 0) === (int) $param['id'] ? 'selected' : '' ?>><?= e($param['name'] . ($param['unit'] !== '' ? ' (' . $param['unit'] . ')' : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Satuan<input name="unit" maxlength="24" value="<?= e($value('unit')) ?>" placeholder="cm"></label>
    <label>Protokol koneksi<?php $select('protocol', sensor_protocols(), $value('protocol', 'manual')); ?></label>
    <label>Interval data (menit)<input name="expected_interval_minutes" type="number" min="1" max="10080" value="<?= e($value('expected_interval_minutes', '15')) ?>" required></label>
    <label>Status<?php $select('status', sensor_statuses(), $value('status', 'active')); ?></label>
    <label class="hazard-description">Endpoint/topik (tanpa kredensial)<input name="endpoint" maxlength="300" value="<?= e($value('endpoint')) ?>" placeholder="https://api.contoh.go.id/sensor/123"></label>
    <label>Kontak teknis<input name="technical_contact" maxlength="120" value="<?= e($value('technical_contact')) ?>" placeholder="Nama / email / telepon"></label>
    <label class="hazard-description">Catatan<textarea name="notes" rows="2" maxlength="500"><?= e($value('notes')) ?></textarea></label>
    <?php
}

function sensor_age_label(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return 'Belum ada';
    }
    $seconds = max(0, time() - $timestamp);
    if ($seconds < 60) {
        return 'Baru saja';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' menit lalu';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' jam lalu';
    }

    return intdiv($seconds, 86400) . ' hari lalu';
}

function render_sensors_page(array $user, ?string $message, ?string $error): void
{
    $locations = sensor_formable_locations($user);
    $filters = [
        'q' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
        'type' => is_string($_GET['type'] ?? null) ? $_GET['type'] : '',
        'health' => is_string($_GET['health'] ?? null) ? $_GET['health'] : '',
        'location_id' => is_string($_GET['location_id'] ?? null) ? (int) $_GET['location_id'] : 0,
    ];
    $sensors = list_sensors($user, $filters);
    $specs = get_sensor_specs(array_map(static fn(array $row): int => (int) $row['id'], $sensors));
    $all = $filters['q'] === '' && $filters['type'] === '' && $filters['health'] === '' && $filters['location_id'] === 0
        ? $sensors
        : list_sensors($user);
    $summary = array_fill_keys(array_keys(sensor_health_labels()), 0);
    foreach ($all as $sensor) {
        $summary[$sensor['health']]++;
    }
    $action = '/?page=dashboard&amp;section=sensors';
    $csrf = e(csrf_token());
    $labels = sensor_health_labels();
    $latest = 0;
    foreach ($all as $sensor) {
        $latest = max($latest, (int) $sensor['last_data_at']);
    }
    ?>
    <div class="hazard-admin sensor-admin">
        <p class="dashboard-message">Kelola identitas sensor/sumber data, tipe, parameter, satuan, lokasi, metode koneksi, dan kontak teknis. Status kesehatan dihitung dari data terakhir terhadap interval yang diharapkan (terlambat bila lebih dari 2× interval).</p>
        <?php if ($message !== null): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="admin-error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="sensor-summary">
            <?php foreach ($labels as $key => $label): ?>
                <a class="sensor-summary-item health-<?= e($key) ?>" href="/?page=dashboard&amp;section=sensors&amp;health=<?= e($key) ?>"><strong><?= (int) $summary[$key] ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <div class="sensor-summary-item"><strong class="sensor-latest"><?= e(sensor_age_label($latest ?: null)) ?></strong><span>Data terbaru</span></div>
        </div>

        <?php if ($sensors !== []): ?><p class="sensor-qr-actions"><a class="save-button" href="/?page=sensor-label">Cetak semua label QR</a></p><?php endif; ?>
        <section class="panel hazard-create-panel">
            <div class="panel-heading"><div><h2>Tambah sensor / sumber data</h2><p>ID bersifat tetap setelah sensor dibuat.</p></div></div>
            <?php if ($locations === []): ?>
                <p class="scope-hint">Belum ada lokasi aktif dalam cakupan Anda. Tambahkan lokasi pantau terlebih dahulu.</p>
            <?php else: ?>
            <form class="hazard-form" method="post" action="<?= $action ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_sensor">
                <?php render_sensor_fields(null, $locations); ?>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penambahan sensor"></label>
                <button class="save-button" type="submit">Tambah sensor</button>
            </form>
            <?php endif; ?>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">KATALOG</p><h2>Sensor &amp; sumber <span><?= count($sensors) ?></span></h2></div></div>
            <form class="location-filter sensor-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard">
                <input type="hidden" name="section" value="sensors">
                <input name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama/ID" aria-label="Cari sensor">
                <select name="type" aria-label="Tipe"><option value="">Semua tipe</option><?php foreach (sensor_types() as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <select name="health" aria-label="Kesehatan"><option value="">Semua kesehatan</option><?php foreach ($labels as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['health'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <select name="location_id" aria-label="Lokasi"><option value="">Semua lokasi</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $filters['location_id'] === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($sensors === []): ?>
                <div class="panel empty-alerts"><h2>Tidak ada sensor</h2><p>Tambahkan sensor atau ubah filter pencarian.</p></div>
            <?php else: ?>
            <div class="hazard-type-list">
                <?php foreach ($sensors as $sensor): ?>
                    <?php $id = (int) $sensor['id']; ?>
                    <article class="panel hazard-type-card sensor-card sensor-card-<?= e($sensor['health']) ?>">
                        <div class="hazard-type-heading sensor-heading">
                            <span class="hazard-icon" aria-hidden="true">⌁</span>
                            <div>
                                <h3><?= e($sensor['name']) ?></h3>
                                <p><code><?= e($sensor['code']) ?></code> · <?= e(sensor_types()[$sensor['sensor_type']] ?? 'Lainnya') ?> · <?= e($sensor['location_name']) ?></p>
                                <p class="location-coords"><?= e($sensor['parameter']) ?><?= $sensor['unit'] !== '' ? ' (' . e($sensor['unit']) . ')' : '' ?> · <?= e(sensor_protocols()[$sensor['protocol']] ?? 'Lainnya') ?> · tiap <?= (int) $sensor['expected_interval_minutes'] ?> menit</p>
                                <p class="location-coords">Data terakhir: <?= e(sensor_age_label($sensor['last_data_at'] === null ? null : (int) $sensor['last_data_at'])) ?><?= $sensor['last_value'] !== '' ? ' · ' . e($sensor['last_value']) : '' ?></p>
                            </div>
                            <div class="sensor-card-side">
                                <span class="health-badge health-<?= e($sensor['health']) ?>"><i class="health-dot health-<?= e($sensor['health']) ?>"></i><?= e($labels[$sensor['health']]) ?></span>
                                <a class="qr-canvas" data-qr-path="<?= e(sensor_detail_path($sensor)) ?>" href="<?= e(sensor_detail_path($sensor)) ?>" title="Buka detail sensor" aria-label="QR code sensor <?= e($sensor['code']) ?>"></a>
                                <a class="qr-print-link" href="/?page=sensor-label&amp;code=<?= e(rawurlencode((string) $sensor['code'])) ?>&amp;print=1">⎙ Cetak QR</a>
                            </div>
                        </div>
                        <?php $spec = $specs[$id] ?? null; ?>
                        <details class="edit-details sensor-spec">
                            <summary>Spesifikasi<?= $spec === null ? ' (belum diisi)' : '' ?></summary>
                            <?php if ($spec !== null): ?>
                                <dl class="spec-list">
                                    <?php foreach (sensor_spec_fields() as $key => [$label]): if ($spec[$key] !== ''): ?>
                                        <div><dt><?= e($label) ?></dt><dd><?= e($spec[$key]) ?></dd></div>
                                    <?php endif; endforeach; ?>
                                    <?php if ($spec['notes'] !== ''): ?><div class="spec-notes"><dt>Catatan</dt><dd><?= e($spec['notes']) ?></dd></div><?php endif; ?>
                                </dl>
                            <?php endif; ?>
                            <form class="hazard-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="save_sensor_specs">
                                <input type="hidden" name="sensor_id" value="<?= $id ?>">
                                <?php foreach (sensor_spec_fields() as $key => [$label, $max, $type]): ?>
                                    <label><?= e($label) ?><input name="<?= e($key) ?>" type="<?= e($type) ?>" <?= $type === 'text' ? 'maxlength="' . (int) $max . '"' : '' ?> value="<?= e((string) ($spec[$key] ?? '')) ?>"></label>
                                <?php endforeach; ?>
                                <label class="hazard-description">Catatan spesifikasi<textarea name="spec_notes" maxlength="1000" rows="3"><?= e((string) ($spec['notes'] ?? '')) ?></textarea></label>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                                <button class="save-button" type="submit">Simpan spesifikasi</button>
                            </form>
                        </details>
                        <details class="edit-details">
                            <summary>Ubah sensor</summary>
                            <form id="sensor-update-<?= $id ?>" class="hazard-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="update_sensor">
                                <input type="hidden" name="sensor_id" value="<?= $id ?>">
                                <?php render_sensor_fields($sensor, $locations); ?>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                            </form>
                            <div class="hazard-form-actions">
                                <button class="save-button" type="submit" form="sensor-update-<?= $id ?>">Simpan perubahan</button>
                                <?php if ($sensor['protocol'] === 'manual'): ?>
                                    <form class="hazard-delete-form sensor-record-form" method="post" action="<?= $action ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="record_sensor">
                                        <input type="hidden" name="sensor_id" value="<?= $id ?>">
                                        <label>Nilai terakhir<input name="value" maxlength="60" placeholder="contoh: 120 cm"></label>
                                        <label>Alasan<input name="reason" maxlength="500" required placeholder="contoh: pembacaan lapangan"></label>
                                        <button class="record-button" type="submit">Catat data terakhir</button>
                                    </form>
                                <?php endif; ?>
                                <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_sensor">
                                    <input type="hidden" name="sensor_id" value="<?= $id ?>">
                                    <label>Alasan penghapusan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                                    <button class="hazard-delete-button" type="submit">Hapus sensor</button>
                                </form>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

function sensor_page_head(string $title): void
{
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
        <script src="/assets/qrcode.js" defer></script>
        <script src="/assets/app.js" defer></script>
    </head>
    <?php
}

function sensor_detail_path(array $sensor): string
{
    return '/?page=sensor-detail&code=' . rawurlencode((string) $sensor['code']);
}

function render_sensor_label_block(array $sensor): void
{
    ?>
    <figure class="qr-label">
        <div class="qr-canvas" data-qr-path="<?= e(sensor_detail_path($sensor)) ?>" role="img" aria-label="QR code sensor <?= e($sensor['code']) ?>"></div>
        <figcaption>
            <strong><?= e($sensor['name']) ?></strong>
            <code><?= e($sensor['code']) ?></code>
            <span><?= e($sensor['location_name']) ?></span>
        </figcaption>
    </figure>
    <?php
}

function render_sensor_label_page(array $sensors, bool $single): void
{
    sensor_page_head($single ? 'Label ' . $sensors[0]['code'] : 'Label QR sensor');
    ?>
    <body class="monitor-body label-body"<?= isset($_GET['print']) ? ' data-autoprint' : '' ?>>
    <main class="monitor-page">
        <div class="label-toolbar">
            <a href="/?page=dashboard&amp;section=sensors">← Kembali</a>
            <span><?= count($sensors) ?> label · tempel pada alat, scan untuk membuka detail sensor</span>
            <button type="button" class="save-button" data-print>Cetak</button>
        </div>
        <div class="label-grid">
            <?php foreach ($sensors as $sensor): render_sensor_label_block($sensor); endforeach; ?>
        </div>
    </main>
    </body>
    </html>
    <?php
}

function render_sensor_history(array $sensor): void
{
    $range = sensor_history_range();
    $rows = sensor_history((int) $sensor['id'], $range['start'], $range['end']);
    $points = [];
    $values = [];
    foreach ($rows as $row) {
        if ($row['value'] !== null && in_array($row['status'], ['accepted', 'late'], true)) {
            $points[] = [(int) $row['at'], (float) $row['value']];
            $values[] = (float) $row['value'];
        }
    }
    if (count($points) > 600) {
        $step = (int) ceil(count($points) / 600);
        $points = array_values(array_filter($points, static fn(array $p, int $i): bool => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
    }
    $code = rawurlencode((string) $sensor['code']);
    $query = 'code=' . $code . '&amp;from=' . e($range['from']) . '&amp;to=' . e($range['to']);
    $statuses = ['accepted' => 'Diterima', 'late' => 'Terlambat', 'duplicate' => 'Duplikat', 'invalid' => 'Tidak valid', 'out_of_range' => 'Di luar rentang'];
    $shown = array_slice(array_reverse($rows), 0, 200);
    ?>
    <section class="panel monitor-table sensor-history">
        <h2>Data historis</h2>
        <form class="history-range" method="get" action="/">
            <input type="hidden" name="page" value="sensor-detail">
            <input type="hidden" name="code" value="<?= e($sensor['code']) ?>">
            <label>Dari<input type="date" name="from" value="<?= e($range['from']) ?>"></label>
            <label>Sampai<input type="date" name="to" value="<?= e($range['to']) ?>"></label>
            <button class="save-button" type="submit">Tampilkan</button>
            <a class="ghost-link" href="/?page=sensor-export&amp;<?= $query ?>">⇩ Ekspor CSV</a>
        </form>
        <?php if ($values === []): ?>
            <p class="scope-hint">Belum ada data pada rentang ini.</p>
        <?php else: ?>
            <div class="sensor-summary">
                <div class="sensor-summary-item"><strong><?= count($values) ?></strong><span>Data valid</span></div>
                <div class="sensor-summary-item"><strong><?= e(rtrim(rtrim(number_format(min($values), 2, '.', ''), '0'), '.')) ?></strong><span>Minimum <?= e($sensor['unit']) ?></span></div>
                <div class="sensor-summary-item"><strong><?= e(rtrim(rtrim(number_format(max($values), 2, '.', ''), '0'), '.')) ?></strong><span>Maksimum <?= e($sensor['unit']) ?></span></div>
                <div class="sensor-summary-item"><strong><?= e(rtrim(rtrim(number_format(array_sum($values) / count($values), 2, '.', ''), '0'), '.')) ?></strong><span>Rata-rata <?= e($sensor['unit']) ?></span></div>
            </div>
            <canvas class="history-chart" data-history="<?= e(json_encode($points)) ?>" data-unit="<?= e($sensor['unit']) ?>" height="260"></canvas>
            <div class="table-scroll">
                <table class="history-table">
                    <thead><tr><th>Waktu</th><th>Nilai</th><th>Status</th><th>Kanal</th></tr></thead>
                    <tbody>
                    <?php foreach ($shown as $row): ?>
                        <tr><td><?= e(date('d M Y H:i', (int) $row['at'])) ?></td><td><?= e($row['value'] === null ? '-' : (string) $row['value']) ?> <?= e($sensor['unit']) ?></td><td><?= e($statuses[$row['status']] ?? $row['status']) ?></td><td><?= e($row['channel']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($rows) > 200): ?><p class="scope-hint">Menampilkan 200 data terbaru dari <?= count($rows) ?>. Ekspor CSV untuk data lengkap.</p><?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_sensor_detail_page(array $sensor, ?array $spec, bool $canManage): void
{
    $labels = sensor_health_labels();
    $rows = [
        'ID sensor' => $sensor['code'],
        'Tipe' => sensor_types()[$sensor['sensor_type']] ?? 'Lainnya',
        'Lokasi' => $sensor['location_name'],
        'Parameter' => $sensor['parameter'] . ($sensor['unit'] !== '' ? ' (' . $sensor['unit'] . ')' : ''),
        'Koneksi' => sensor_protocols()[$sensor['protocol']] ?? 'Lainnya',
        'Interval data' => 'Tiap ' . (int) $sensor['expected_interval_minutes'] . ' menit',
        'Status' => sensor_statuses()[$sensor['status']] ?? $sensor['status'],
        'Data terakhir' => sensor_age_label($sensor['last_data_at'] === null ? null : (int) $sensor['last_data_at'])
            . ($sensor['last_value'] !== '' ? ' · ' . $sensor['last_value'] : ''),
        'Kontak teknis' => $sensor['technical_contact'],
        'Catatan' => $sensor['notes'],
    ];
    sensor_page_head($sensor['name']);
    ?>
    <body class="monitor-body">
    <main class="monitor-page sensor-detail">
        <div class="label-toolbar">
            <a href="/?page=dashboard">← Dashboard</a>
            <span></span>
            <?php if ($canManage): ?><a class="save-button" href="/?page=sensor-label&amp;code=<?= e(rawurlencode((string) $sensor['code'])) ?>">Cetak label QR</a><?php endif; ?>
        </div>
        <header class="monitor-head">
            <div>
                <p class="eyebrow">DETAIL SENSOR</p>
                <h1><?= e($sensor['name']) ?></h1>
                <p><code><?= e($sensor['code']) ?></code> · <?= e($sensor['location_name']) ?></p>
            </div>
            <span class="health-badge health-<?= e($sensor['health']) ?>"><?= e($labels[$sensor['health']]) ?></span>
        </header>
        <section class="panel monitor-table">
            <h2>Informasi umum</h2>
            <dl class="spec-list">
                <?php foreach ($rows as $label => $value): if ($value !== ''): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e((string) $value) ?></dd></div>
                <?php endif; endforeach; ?>
            </dl>
        </section>
        <section class="panel monitor-table">
            <h2>Spesifikasi</h2>
            <?php $filled = false; ?>
            <dl class="spec-list">
                <?php if ($spec !== null): foreach (sensor_spec_fields() as $key => [$label]): if ($spec[$key] !== ''): $filled = true; ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($spec[$key]) ?></dd></div>
                <?php endif; endforeach; if ($spec['notes'] !== ''): $filled = true; ?><div class="spec-notes"><dt>Catatan</dt><dd><?= e($spec['notes']) ?></dd></div><?php endif; endif; ?>
            </dl>
            <?php if (!$filled): ?><p class="scope-hint">Spesifikasi belum diisi.</p><?php endif; ?>
        </section>
        <?php render_sensor_history($sensor); ?>
    </main>
    </body>
    </html>
    <?php
}
