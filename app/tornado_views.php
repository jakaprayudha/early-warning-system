<?php

declare(strict_types=1);

function render_tornado_page(array $user): void
{
    $stations = tornado_stations($user);
    $feed = tornado_fetch_feed(array_map(static fn(array $s): string => (string) $s['code'], $stations));
    $readings = [];
    foreach ($feed['stations'] as $row) {
        $readings[(string) $row['code']] = $row;
    }
    $levels = tornado_levels();
    $trends = ['rising' => ['Menguat', '▲'], 'falling' => ['Melemah', '▼'], 'steady' => ['Stabil', '■']];
    $fmt = static fn(float $v): string => number_format($v, 1, ',', '.');
    $fmt2 = static fn(float $v): string => number_format($v, 2, ',', '.');
    $local = static fn(?string $iso): string => (new DateTimeImmutable($iso ?? 'now'))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s');
    $counts = array_fill_keys(array_keys($levels), 0);
    $rising = 0;
    $maxLevel = 0.0;
    $cards = [];
    foreach ($stations as $station) {
        $r = $readings[(string) $station['code']] ?? null;
        $cm = $r === null ? null : (float) $r['wind_kmh'];
        $level = $cm === null ? 'normal' : tornado_level($cm, $station['thresholds']);
        $counts[$level]++;
        if ($cm !== null) {
            $maxLevel = max($maxLevel, $cm);
            $rising += tornado_trend((float) $r['change_1h_kmh']) === 'rising' ? 1 : 0;
        }
        $cards[] = [$station, $r, $cm, $level];
    }
    $sourceLabel = $feed['source'] === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
    ?>
    <section class="hazard-types-section weather-page tornado-page" data-tornado-page data-feed-url="/?page=api-tornado-feed" data-levels="<?= e(json_encode($levels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="section-heading">
            <div><p class="eyebrow">EWS TORNADO</p><h2>Monitoring tornado <span><?= count($stations) ?></span></h2>
            <p class="section-note">Titik rawan tornado beserta kecepatan angin terbaru terhadap ambang yang disetujui. Diperbarui otomatis tiap 15 detik.</p></div>
            <span class="weather-source <?= $feed['source'] === 'live' ? 'live' : 'dummy' ?>" data-tornado-source><i></i><?= e($sourceLabel) ?></span>
        </div>
        <div class="weather-summary">
            <article><span>Titik rawan tornado</span><strong><?= count($stations) ?></strong></article>
            <article><span>Status Waspada ke atas</span><strong data-tornado-alerting><?= count($stations) - $counts['normal'] ?></strong></article>
            <article><span>Angin tertinggi</span><strong><span data-tornado-max><?= e($fmt($maxLevel)) ?></span><small> km/jam</small></strong></article>
            <article><span>Angin sedang menguat</span><strong data-tornado-rising><?= $rising ?></strong></article>
            <article><span>Pembaruan terakhir</span><strong class="weather-clock" data-tornado-updated><?= e($local(null)) ?></strong></article>
        </div>
        <?php if ($stations === []): ?>
            <div class="panel hazard-empty"><strong>Belum ada titik rawan tornado</strong><p>Tambahkan lokasi dengan jenis bahaya Tornado di Wilayah &amp; lokasi.</p></div>
        <?php else: ?>
            <div class="weather-grid">
                <?php foreach ($cards as [$station, $r, $cm, $level]):
                    $change = $r === null ? 0.0 : (float) $r['change_1h_kmh'];
                    $trend = tornado_trend($change);
                    $top = max(array_map(static fn(array $t): float => $t['value'], $station['thresholds']) ?: [60.0]) * 1.25;
                    $pct = $cm === null ? 0 : (int) min(100, round($cm / $top * 100 / 5) * 5);
                    ?>
                    <article class="panel weather-card tornado-card" data-tornado-station="<?= e($station['code']) ?>" data-thresholds="<?= e(json_encode($station['thresholds'], JSON_THROW_ON_ERROR)) ?>">
                        <header>
                            <div><h3><?= e($station['name']) ?></h3><p><?= e($station['region_label']) ?></p></div>
                            <span class="weather-level river-<?= e($level) ?>" data-field="level"><?= e($levels[$level]) ?></span>
                        </header>
                        <div class="weather-rain"><strong data-field="level_cm"><?= $cm === null ? '—' : e($fmt($cm)) ?></strong><span>km/jam</span>
                            <em class="river-trend trend-<?= e($trend) ?>" data-field="trend"><?= e($trends[$trend][1] . ' ' . $trends[$trend][0]) ?></em></div>
                        <div class="river-gauge" aria-hidden="true"><i class="river-fill river-<?= e($level) ?> bar-w-<?= $pct ?>" data-field="gauge"></i></div>
                        <dl class="weather-metrics">
                            <div><dt>Perubahan 1 jam</dt><dd><span data-field="change"><?= $r === null ? '—' : e(($change > 0 ? '+' : '') . $fmt($change)) ?></span> km/jam</dd></div>
                            <div><dt>Hembusan maks.</dt><dd><span data-field="wave"><?= $r === null ? '—' : e($fmt((float) $r['gust_kmh'])) ?></span> km/jam</dd></div>
                            <div><dt>Tekanan udara</dt><dd><span data-field="wind"><?= $r === null ? '—' : e($fmt((float) $r['pressure_hpa'])) ?></span> hPa</dd></div>
                            <div><dt>Ambang</dt><dd class="river-thresholds"><?php
                                $parts = [];
                                foreach ($station['thresholds'] as $sev => $t) {
                                    $parts[] = $levels[$sev] . ' ' . ($t['op'] === '>' ? '>' : '≥') . ' ' . $fmt($t['value']);
                                }
                                echo $parts === [] ? 'Belum ada' : e(implode(' · ', $parts));
                                ?></dd></div>
                        </dl>
                        <a class="weather-detail-link" href="/?page=tornado-monitor&amp;code=<?= e(rawurlencode((string) $station['code'])) ?>">Buka monitoring penuh <span aria-hidden="true">↗</span></a>
                        <footer>
                            <span><code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></span>
                            <span>Diamati <b data-field="time"><?= $r === null ? '—' : e($local((string) $r['observed_at'])) ?></b></span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="scope-hint">Status dihitung dari ambang kecepatan angin (km/jam) yang disetujui di Ambang &amp; persetujuan. Data simulasi tidak disimpan. Atur <code>APP_TORNADO_FEED_URL</code> ke API JSON sensor (skema: <code>stations[].code, wind_kmh, change_1h_kmh, gust_kmh, pressure_hpa, observed_at</code>) untuk memakai data asli.</p>
    </section>
    <?php
}

function render_tornado_monitor_page(array $station): void
{
    $levels = tornado_levels();
    $charts = [
        ['wind_kmh', 'Kecepatan angin', 'km/jam', '#2f80c9', 1, true],
        ['gust_kmh', 'Hembusan maksimum', 'km/jam', '#d9822b', 1, false],
        ['pressure_hpa', 'Tekanan udara', 'hPa', '#b53a31', 1, false],
        ['change_1h_kmh', 'Perubahan 1 jam', 'km/jam', '#7a5bc2', 1, false],
    ];
    $url = '/?page=api-tornado-feed&code=' . rawurlencode((string) $station['code']);
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
    <main class="monitor-page" data-tornado-monitor data-feed-url="<?= e($url . '&history=1') ?>" data-levels="<?= e(json_encode($levels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <?php render_ews_bar('tornado', (string) $station['code']); ?>
        <header class="monitor-head">
            <div>
                <p class="eyebrow">MONITORING TORNADO · REALTIME</p>
                <h1><?= e($station['name']) ?></h1>
                <p><?= e($station['region_label']) ?> · <code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></p>
            </div>
            <div class="monitor-actions">
                <span class="weather-source dummy" data-monitor-source><i></i>Memuat…</span>
                <strong class="weather-clock" data-monitor-clock>--:--:--</strong>
                <a class="button secondary" href="/?page=dashboard&amp;section=tornado">← Kembali</a>
            </div>
        </header>
        <section class="monitor-now monitor-now-5">
            <article class="monitor-main"><span>Kecepatan angin</span><strong><span data-now="wind_kmh">—</span><small> km/jam</small></strong><em class="weather-level river-normal" data-monitor-level>—</em></article>
            <article><span>Tren</span><strong class="river-trend" data-now="trend">—</strong></article>
            <article><span>Perubahan 1 jam</span><strong><span data-now="change_1h_kmh">—</span><small> km/jam</small></strong></article>
            <article><span>Hembusan maks.</span><strong><span data-now="gust_kmh">—</span><small> km/jam</small></strong></article>
            <article><span>Tekanan udara</span><strong><span data-now="pressure_hpa">—</span><small> hPa</small></strong></article>
            <article><span>Diamati</span><strong class="weather-clock" data-now="observed_at">—</strong></article>
        </section>
        <section class="monitor-charts">
            <?php foreach ($charts as [$key, $title, $unit, $color, $digits, $wide]): ?>
                <article class="monitor-chart <?= $wide ? 'wide' : '' ?>">
                    <header><h2><?= e($title) ?></h2><span><?= e($unit) ?></span></header>
                    <canvas data-chart="<?= e($key) ?>" data-color="<?= e($color) ?>" data-digits="<?= $digits ?>" <?= $wide ? 'data-thresholds="1"' : '' ?> role="img" aria-label="Grafik <?= e($title) ?>"></canvas>
                </article>
            <?php endforeach; ?>
        </section>
        <section class="panel monitor-table">
            <h2>Data realtime terbaru</h2>
            <table>
                <thead><tr><th>Waktu</th><th>Angin (km/jam)</th><th>Perubahan 1 jam</th><th>Hembusan (km/jam)</th><th>Tekanan (hPa)</th></tr></thead>
                <tbody data-monitor-rows><tr><td colspan="5">Memuat…</td></tr></tbody>
            </table>
        </section>
    </main>
    </body>
    </html>
    <?php
}
