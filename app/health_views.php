<?php

declare(strict_types=1);

function render_health_page(array $user): void
{
    $sensors = health_sensor_rows($user);
    $checks = health_service_checks();
    $ingest = health_ingest_stats($user);
    $overview = health_overview($sensors, $checks);
    $counts = $overview['counts'];
    $labels = sensor_health_labels();
    $banner = [
        'ok' => ['Semua sistem normal', 'Seluruh sumber data dan layanan berjalan sesuai harapan.'],
        'warn' => ['Perlu perhatian', 'Ada peringatan ringan pada layanan atau sumber yang belum mengirim data.'],
        'bad' => ['Ada gangguan', 'Ada sumber data terlambat atau pemeriksaan layanan yang gagal.'],
    ][$overview['level']];
    $tile = ['healthy' => ' approval-tile-approved', 'delayed' => ' approval-tile-rejected', 'unknown' => ' approval-tile-pending', 'maintenance' => ' approval-tile-pending', 'inactive' => ''];
    $ago = static function (?int $ts): string {
        if ($ts === null) {
            return 'belum pernah';
        }
        $diff = max(0, time() - $ts);

        return $diff < 60 ? 'baru saja' : format_duration($diff) . ' lalu';
    };
    ?>
    <div class="hazard-admin">
        <div class="health-banner health-<?= e($overview['level']) ?>" role="status">
            <span class="health-dot" aria-hidden="true"></span>
            <div><strong><?= e($banner[0]) ?></strong><small><?= e($banner[1]) ?> Diperbarui <?= e(date('d M Y H:i:s')) ?>.</small></div>
            <a class="export-link" href="/?page=dashboard&amp;section=health">Muat ulang ↻</a>
        </div>

        <div class="sensor-summary threshold-summary">
            <?php foreach ($labels as $key => $label): ?>
                <div class="sensor-summary-item approval-tile<?= $tile[$key] ?>"><strong><?= (int) $counts[$key] ?></strong><span><?= e($label) ?></span></div>
            <?php endforeach; ?>
        </div>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">LAYANAN</p><h2>Pemeriksaan sistem</h2></div></div>
            <ul class="check-list">
                <?php foreach ($checks as [$name, $state, $detail]): ?>
                    <li><span class="check-state check-<?= e($state) ?>"><?= $state === 'ok' ? '✓' : ($state === 'warn' ? '!' : '✕') ?></span><strong><?= e($name) ?></strong><small><?= e($detail) ?></small></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">PENERIMAAN DATA</p><h2>Aktivitas ingest</h2></div></div>
            <div class="report-grid">
                <?php foreach ($ingest as $label => $s): $pct = $s['total'] > 0 ? (int) round($s['good'] / $s['total'] * 100) : null; ?>
                    <section class="panel report-card">
                        <h3><?= e($label) ?> terakhir</h3>
                        <p class="health-figure"><strong><?= $s['total'] ?></strong> pembacaan<?= $pct !== null ? ' · ' . $pct . '% layak pakai' : '' ?></p>
                        <small class="scope-hint">Terakhir diterima: <?= e($ago($s['latest'])) ?></small>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">SUMBER DATA</p><h2>Status sumber <span><?= count($sensors) ?></span></h2></div></div>
            <div class="reading-table-wrap">
                <table class="reading-table">
                    <thead><tr><th>Sumber</th><th>Lokasi</th><th>Koneksi</th><th>Interval</th><th>Terakhir terlihat</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($sensors === []): ?><tr><td colspan="6">Belum ada sensor.</td></tr><?php endif; ?>
                    <?php foreach ($sensors as $s): ?>
                        <tr>
                            <td><strong><?= e($s['code']) ?></strong><small><?= e($s['name']) ?></small></td>
                            <td><?= e($s['location_name']) ?></td>
                            <td><?= e(sensor_protocols()[$s['protocol']] ?? $s['protocol']) ?></td>
                            <td><?= (int) $s['expected_interval_minutes'] ?> mnt</td>
                            <td><?= e($ago($s['last_seen'])) ?><?= $s['overdue_seconds'] > 0 ? '<small>terlambat ' . e(format_duration($s['overdue_seconds'])) . '</small>' : '' ?></td>
                            <td><span class="ingest-badge health-badge-<?= e($s['health']) ?>"><?= e($labels[$s['health']]) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php
}
