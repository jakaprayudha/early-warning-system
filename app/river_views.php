<?php

declare(strict_types=1);

function render_river_page(array $user): void
{
    $stations = river_stations($user);
    $feed = river_fetch_feed(array_map(static fn(array $s): string => (string) $s['code'], $stations));
    $readings = [];
    foreach ($feed['stations'] as $row) {
        $readings[(string) $row['code']] = $row;
    }
    $levels = river_levels();
    $trends = ['rising' => ['Naik', '▲'], 'falling' => ['Turun', '▼'], 'steady' => ['Stabil', '■']];
    $fmt = static fn(float $v): string => number_format($v, 1, ',', '.');
    $local = static fn(?string $iso): string => (new DateTimeImmutable($iso ?? 'now'))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s');
    $counts = array_fill_keys(array_keys($levels), 0);
    $rising = 0;
    $maxLevel = 0.0;
    $cards = [];
    foreach ($stations as $station) {
        $r = $readings[(string) $station['code']] ?? null;
        $cm = $r === null ? null : (float) $r['water_level_cm'];
        $level = $cm === null ? 'normal' : river_level($cm, $station['thresholds']);
        $counts[$level]++;
        if ($cm !== null) {
            $maxLevel = max($maxLevel, $cm);
            $rising += river_trend((float) $r['change_1h_cm']) === 'rising' ? 1 : 0;
        }
        $cards[] = [$station, $r, $cm, $level];
    }
    $sourceLabel = $feed['source'] === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
    ?>
    <section class="hazard-types-section weather-page river-page" data-river-page data-feed-url="/?page=api-river-feed" data-levels="<?= e(json_encode($levels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="section-heading">
            <div><p class="eyebrow">EWS SUNGAI</p><h2>Monitoring sungai <span><?= count($stations) ?></span></h2>
            <p class="section-note">Pos pantau sungai beserta tinggi muka air terbaru terhadap ambang yang disetujui. Diperbarui otomatis tiap 15 detik.</p></div>
            <span class="weather-source <?= $feed['source'] === 'live' ? 'live' : 'dummy' ?>" data-river-source><i></i><?= e($sourceLabel) ?></span>
        </div>
        <div class="weather-summary">
            <article><span>Pos sungai</span><strong><?= count($stations) ?></strong></article>
            <article><span>Status Waspada ke atas</span><strong data-river-alerting><?= count($stations) - $counts['normal'] ?></strong></article>
            <article><span>Muka air tertinggi</span><strong><span data-river-max><?= e($fmt($maxLevel)) ?></span><small> cm</small></strong></article>
            <article><span>Sedang naik</span><strong data-river-rising><?= $rising ?></strong></article>
            <article><span>Pembaruan terakhir</span><strong class="weather-clock" data-river-updated><?= e($local(null)) ?></strong></article>
        </div>
        <?php if ($stations === []): ?>
            <div class="panel hazard-empty"><strong>Belum ada pos sungai</strong><p>Tambahkan lokasi dengan jenis bahaya Banjir sungai di Wilayah &amp; lokasi.</p></div>
        <?php else: ?>
            <div class="weather-grid">
                <?php foreach ($cards as [$station, $r, $cm, $level]):
                    $change = $r === null ? 0.0 : (float) $r['change_1h_cm'];
                    $trend = river_trend($change);
                    $top = max(array_map(static fn(array $t): float => $t['value'], $station['thresholds']) ?: [300.0]) * 1.25;
                    $pct = $cm === null ? 0 : (int) min(100, round($cm / $top * 100 / 5) * 5);
                    ?>
                    <article class="panel weather-card river-card" data-river-station="<?= e($station['code']) ?>" data-thresholds="<?= e(json_encode($station['thresholds'], JSON_THROW_ON_ERROR)) ?>">
                        <header>
                            <div><h3><?= e($station['name']) ?></h3><p><?= e($station['region_label']) ?></p></div>
                            <span class="weather-level river-<?= e($level) ?>" data-field="level"><?= e($levels[$level]) ?></span>
                        </header>
                        <div class="weather-rain"><strong data-field="level_cm"><?= $cm === null ? '—' : e($fmt($cm)) ?></strong><span>cm</span>
                            <em class="river-trend trend-<?= e($trend) ?>" data-field="trend"><?= e($trends[$trend][1] . ' ' . $trends[$trend][0]) ?></em></div>
                        <div class="river-gauge" aria-hidden="true"><i class="river-fill river-<?= e($level) ?> bar-w-<?= $pct ?>" data-field="gauge"></i></div>
                        <dl class="weather-metrics">
                            <div><dt>Perubahan 1 jam</dt><dd><span data-field="change"><?= $r === null ? '—' : e(($change > 0 ? '+' : '') . $fmt($change)) ?></span> cm</dd></div>
                            <div><dt>Hujan hulu</dt><dd><span data-field="rain"><?= $r === null ? '—' : e($fmt((float) $r['rain_upstream_mm_h'])) ?></span> mm/jam</dd></div>
                            <div><dt>Debit</dt><dd><span data-field="flow"><?= $r === null ? '—' : e($fmt((float) $r['flow_m3s'])) ?></span> m³/dtk</dd></div>
                            <div><dt>Ambang</dt><dd class="river-thresholds"><?php
                                $parts = [];
                                foreach ($station['thresholds'] as $sev => $t) {
                                    $parts[] = $levels[$sev] . ' ' . ($t['op'] === '>' ? '>' : '≥') . ' ' . $fmt($t['value']);
                                }
                                echo $parts === [] ? 'Belum ada' : e(implode(' · ', $parts));
                                ?></dd></div>
                        </dl>
                        <a class="weather-detail-link" href="/?page=river-monitor&amp;code=<?= e(rawurlencode((string) $station['code'])) ?>">Buka monitoring penuh <span aria-hidden="true">↗</span></a>
                        <footer>
                            <span><code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></span>
                            <span>Diamati <b data-field="time"><?= $r === null ? '—' : e($local((string) $r['observed_at'])) ?></b></span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="scope-hint">Status dihitung dari ambang tinggi muka air yang disetujui di Ambang &amp; persetujuan. Data simulasi tidak disimpan. Atur <code>APP_RIVER_FEED_URL</code> ke API JSON sensor (skema: <code>stations[].code, water_level_cm, change_1h_cm, rain_upstream_mm_h, flow_m3s, observed_at</code>) untuk memakai data asli.</p>
    </section>
    <?php
}

function render_river_monitor_page(array $station): void
{
    $levels = river_levels();
    $charts = [
        ['water_level_cm', 'Tinggi muka air', 'cm', '#2f80c9', 1, true],
        ['change_1h_cm', 'Perubahan 1 jam', 'cm', '#d9822b', 1, false],
        ['rain_upstream_mm_h', 'Hujan hulu', 'mm/jam', '#17806b', 1, false],
        ['flow_m3s', 'Debit', 'm³/s', '#7a5bc2', 1, false],
    ];
    $url = '/?page=api-river-feed&code=' . rawurlencode((string) $station['code']);
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Monitoring <?= e($station['name']) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
        <script src="/assets/app.js" defer></script>
    </head>
    <body class="monitor-body">
    <main class="monitor-page" data-river-monitor data-feed-url="<?= e($url . '&history=1') ?>" data-levels="<?= e(json_encode($levels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <?php render_ews_bar('river', (string) $station['code']); ?>
        <header class="monitor-head">
            <div>
                <p class="eyebrow">MONITORING SUNGAI · REALTIME</p>
                <h1><?= e($station['name']) ?></h1>
                <p><?= e($station['region_label']) ?> · <code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></p>
            </div>
            <div class="monitor-actions">
                <span class="weather-source dummy" data-monitor-source><i></i>Memuat…</span>
                <strong class="weather-clock" data-monitor-clock>--:--:--</strong>
                <a class="button secondary" href="/?page=dashboard&amp;section=river">← Kembali</a>
            </div>
        </header>
        <section class="monitor-now monitor-now-5">
            <article class="monitor-main"><span>Tinggi muka air</span><strong><span data-now="water_level_cm">—</span><small> cm</small></strong><em class="weather-level river-normal" data-monitor-level>—</em></article>
            <article><span>Tren</span><strong class="river-trend" data-now="trend">—</strong></article>
            <article><span>Perubahan 1 jam</span><strong><span data-now="change_1h_cm">—</span><small> cm</small></strong></article>
            <article><span>Hujan hulu</span><strong><span data-now="rain_upstream_mm_h">—</span><small> mm/jam</small></strong></article>
            <article><span>Debit</span><strong><span data-now="flow_m3s">—</span><small> m³/s</small></strong></article>
            <article><span>Diamati</span><strong class="weather-clock" data-now="observed_at">—</strong></article>
        </section>
        <section class="monitor-charts">
            <?php foreach ($charts as [$key, $title, $unit, $color, $digits, $wide]): ?>
                <article class="monitor-chart <?= $wide ? 'wide' : '' ?>">
                    <header><h2><?= e($title) ?></h2><span><?= e($unit) ?></span></header>
                    <canvas data-chart="<?= e($key) ?>" data-color="<?= e($color) ?>" data-digits="<?= $digits ?>" <?= $wide ? 'data-thresholds="' . e(json_encode($station['thresholds'], JSON_THROW_ON_ERROR)) . '"' : '' ?> role="img" aria-label="Grafik <?= e($title) ?>"></canvas>
                </article>
            <?php endforeach; ?>
        </section>
        <section class="panel monitor-table">
            <h2>Data realtime terbaru</h2>
            <table>
                <thead><tr><th>Waktu</th><th>Muka air (cm)</th><th>Perubahan 1 jam (cm)</th><th>Hujan hulu (mm/jam)</th><th>Debit (m³/s)</th></tr></thead>
                <tbody data-monitor-rows><tr><td colspan="5">Memuat…</td></tr></tbody>
            </table>
        </section>
    </main>
    </body>
    </html>
    <?php
}
