<?php

declare(strict_types=1);

function render_bar_list(array $items, array $labels = []): void
{
    $max = $items === [] ? 1 : max($items);
    echo '<ul class="bar-list">';
    foreach ($items as $key => $count) {
        $width = max(4, (int) round($count / max(1, $max) * 100));
        echo '<li><span class="bar-label">' . e((string) ($labels[$key] ?? $key)) . '</span>'
            . '<span class="bar-track"><span class="bar-fill bar-w-' . (int) (ceil($width / 5) * 5) . '"></span></span>'
            . '<strong>' . (int) $count . '</strong></li>';
    }
    if ($items === []) {
        echo '<li class="scope-hint">Tidak ada data pada periode ini.</li>';
    }
    echo '</ul>';
}

function render_reports_page(array $user): void
{
    $filters = report_filters($_GET);
    $labels = region_path_labels(user_regions($user));
    $hazards = alert_hazards();
    $events = report_events($user, $filters);
    $stats = report_event_stats($events);
    $quality = report_data_quality($user, $filters);
    $query = ['page' => 'dashboard', 'section' => 'reports'] + array_filter($filters, static fn(string $v): bool => $v !== '');
    $exportBase = http_build_query($query);
    $severities = alert_severities();
    $bySeverity = [];
    foreach (array_keys($severities) as $key) {
        $bySeverity[$key] = $stats['by_severity'][$key] ?? 0;
    }
    $byHazard = [];
    foreach ($stats['by_hazard'] as $code => $count) {
        $byHazard[$code] = $count;
    }
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Rekap kejadian dan kualitas data untuk periode terpilih, terbatas pada cakupan wilayah Anda. Waktu pada ekspor memakai UTC. Setiap unduhan dicatat di audit.</p>
        <form class="location-filter report-filter" method="get" action="/">
            <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="reports">
            <label>Dari<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
            <label>Sampai<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
            <label>Wilayah<select name="region_id"><option value="">Semua wilayah</option><?php render_region_options($labels, $filters['region_id'] === '' ? null : (int) $filters['region_id']); ?></select></label>
            <label>Bahaya<select name="hazard"><option value="">Semua bahaya</option><?php foreach ($hazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= $filters['hazard'] === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
            <button class="save-button" type="submit">Terapkan</button>
        </form>

        <div class="sensor-summary threshold-summary">
            <div class="sensor-summary-item approval-tile"><strong><?= $stats['total'] ?></strong><span>Kejadian</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-pending"><strong><?= $stats['open'] ?></strong><span>Masih aktif</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-approved"><strong><?= $stats['closed'] ?></strong><span>Selesai</span></div>
            <div class="sensor-summary-item approval-tile"><strong><?= e(format_duration($stats['mtta'])) ?></strong><span>Rata-rata waktu pengakuan</span></div>
            <div class="sensor-summary-item approval-tile"><strong><?= e(format_duration($stats['mttr'])) ?></strong><span>Rata-rata waktu penyelesaian</span></div>
        </div>

        <div class="report-grid">
            <section class="panel report-card"><h3>Per tingkat</h3><?php render_bar_list($bySeverity, $severities); ?></section>
            <section class="panel report-card"><h3>Per jenis bahaya</h3><?php render_bar_list($byHazard, $hazards); ?></section>
            <section class="panel report-card"><h3>Per wilayah</h3><?php render_bar_list($stats['by_region']); ?></section>
            <section class="panel report-card"><h3>Per hari</h3><?php render_bar_list($stats['by_day']); ?></section>
        </div>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">KUALITAS DATA</p><h2>Pembacaan per sensor <span><?= count($quality) ?></span></h2></div></div>
            <div class="reading-table-wrap">
                <table class="reading-table">
                    <thead><tr><th>Sensor</th><th>Total</th><th>Diterima</th><th>Terlambat</th><th>Duplikat</th><th>Di luar rentang</th><th>Tidak valid</th></tr></thead>
                    <tbody>
                    <?php if ($quality === []): ?><tr><td colspan="7">Tidak ada pembacaan pada periode ini.</td></tr><?php endif; ?>
                    <?php foreach ($quality as $q): ?>
                        <tr><td><strong><?= e($q['code']) ?></strong><small><?= e($q['name']) ?></small></td><td><?= $q['total'] ?></td><td><?= $q['accepted'] ?></td><td><?= $q['late'] ?></td><td><?= $q['duplicate'] ?></td><td><?= $q['out_of_range'] ?></td><td><?= $q['invalid'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">EKSPOR</p><h2>Unduh CSV</h2></div></div>
            <div class="export-grid">
                <a class="export-link" href="/?<?= e($exportBase) ?>&amp;export=events">Rekap kejadian <span aria-hidden="true">↓</span></a>
                <a class="export-link" href="/?<?= e($exportBase) ?>&amp;export=quality">Kualitas data per sensor <span aria-hidden="true">↓</span></a>
                <a class="export-link" href="/?<?= e($exportBase) ?>&amp;export=readings">Pembacaan sensor <span aria-hidden="true">↓</span></a>
            </div>
            <p class="scope-hint">Maksimal <?= number_format(REPORT_MAX_EXPORT_ROWS, 0, ',', '.') ?> baris per berkas. Nilai berawalan = + - @ diberi tanda kutip agar aman dibuka di spreadsheet.</p>
        </section>
    </div>
    <?php
}
