<?php

declare(strict_types=1);

function render_tide_page(array $user): void{
    $stations = tide_stations($user);
    $feed = tide_fetch_feed(array_map(static fn(array $s): string => (string) $s['code'], $stations));
    $readings = [];
    foreach ($feed['stations'] as $row) {
        $readings[(string) $row['code']] = $row;
    }
    $levels = tide_levels();
    $trends = ['rising' => ['Naik', '▲'], 'falling' => ['Turun', '▼'], 'steady' => ['Stabil', '■']];
    $fmt = static fn(float $v): string => number_format($v, 1, ',', '.');
    $fmt2 = static fn(float $v): string => number_format($v, 2, ',', '.');
    $local = static fn(?string $iso): string => (new DateTimeImmutable($iso ?? 'now'))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s');
    $counts = array_fill_keys(array_keys($levels), 0);
    $rising = 0;
    $maxLevel = 0.0;
    $cards = [];
    foreach ($stations as $station) {
        $r = $readings[(string) $station['code']] ?? null;
        $cm = $r === null ? null : (float) $r['tide_level_m'];
        $level = $cm === null ? 'normal' : tide_level($cm, $station['thresholds']);
        $counts[$level]++;
        if ($cm !== null) {
            $maxLevel = max($maxLevel, $cm);
            $rising += tide_trend((float) $r['change_1h_m']) === 'rising' ? 1 : 0;
        }
        $cards[] = [$station, $r, $cm, $level];
    }
    $sourceLabel = $feed['source'] === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
    ?>
    <section class="hazard-types-section weather-page tide-page" data-tide-page data-feed-url="/?page=api-tide-feed" data-levels="<?= e(json_encode($levels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="section-heading">
            <div>
                <p class="eyebrow">EWS PASANG SURUT</p>
                <h2>Monitoring pasang surut <span><?= count($stations) ?></span></h2>
                <p class="section-note">Pos pantai/muara beserta tinggi pasang terbaru terhadap ambang yang disetujui. Diperbarui otomatis tiap 15 detik.</p>
            </div>
            <span class="weather-source <?= $feed['source'] === 'live' ? 'live' : 'dummy' ?>" data-tide-source><i></i><?= e($sourceLabel) ?></span>
        </div>
        <div class="weather-summary">
            <article><span>Pos pantai/muara</span><strong><?= count($stations) ?></strong></article>
            <article><span>Status Waspada ke atas</span><strong data-tide-alerting><?= count($stations) - $counts['normal'] ?></strong></article>
            <article><span>Pasang tertinggi</span><strong><span data-tide-max><?= e($fmt2($maxLevel)) ?></span><small> cm</small></strong></article>
            <article><span>Sedang pasang naik</span><strong data-tide-rising><?= $rising ?></strong></article>
            <article><span>Pembaruan terakhir</span><strong class="weather-clock" data-tide-updated><?= e($local(null)) ?></strong></article>
        </div>

        <?php if ($stations === []): ?>
            <div class="panel hazard-empty"><strong>Belum ada pos pantai/muara</strong>
                <p>Tambahkan lokasi dengan jenis bahaya Pasang surut pantai/muara di Wilayah &amp; lokasi.</p>
            </div>
        <?php else: ?>
            <div class="weather-grid">
                <?php foreach ($cards as [$station, $r, $cm, $level]):
                    $change = $r === null ? 0.0 : (float) $r['change_1h_m'];
                    $trend = tide_trend($change);
                    $top = max(array_map(static fn(array $t): float => $t['value'], $station['thresholds']) ?: [2.0]) * 1.25;
                    $pct = $cm === null ? 0 : (int) min(100, round($cm / $top * 100 / 5) * 5);
                ?>
                    <article class="panel weather-card tide-card" data-tide-station="<?= e($station['code']) ?>" data-thresholds="<?= e(json_encode($station['thresholds'], JSON_THROW_ON_ERROR)) ?>">
                        <header>
                            <div>
                                <h3><?= e($station['name']) ?></h3>
                                <p><?= e($station['region_label']) ?></p>
                            </div>
                            <span class="weather-level river-<?= e($level) ?>" data-field="level"><?= e($levels[$level]) ?></span>
                        </header>
                        <div class="weather-rain"><strong data-field="level_cm"><?= $cm === null ? '—' : e($fmt2($cm)) ?></strong><span>cm</span>
                            <em class="river-trend trend-<?= e($trend) ?>" data-field="trend"><?= e($trends[$trend][1] . ' ' . $trends[$trend][0]) ?></em>
                        </div>
                        <div class="river-gauge" aria-hidden="true"><i class="river-fill river-<?= e($level) ?> bar-w-<?= $pct ?>" data-field="gauge"></i></div>
                        <dl class="weather-metrics">
                            <div>
                                <dt>Perubahan 1 jam</dt>
                                <dd><span data-field="change"><?= $r === null ? '—' : e(($change > 0 ? '+' : '') . $fmt2($change)) ?></span> m</dd>
                            </div>
                            <div>
                                <dt>Tinggi gelombang</dt>
                                <dd><span data-field="wave"><?= $r === null ? '—' : e($fmt((float) $r['wave_height_m'])) ?></span> m</dd>
                            </div>
                            <div>
                                <dt>Angin</dt>
                                <dd><span data-field="wind"><?= $r === null ? '—' : e($fmt((float) $r['wind_kmh'])) ?></span> km/jam</dd>
                            </div>
                            <div>
                                <dt>Ambang</dt>
                                <dd class="river-thresholds"><?php
                                                                $parts = [];
                                                                foreach ($station['thresholds'] as $sev => $t) {
                                                                    $parts[] = $levels[$sev] . ' ' . ($t['op'] === '>' ? '>' : '≥') . ' ' . $fmt2($t['value']);
                                                                }
                                                                echo $parts === [] ? 'Belum ada' : e(implode(' · ', $parts));
                                                                ?></dd>
                            </div>
                        </dl>
                        <a class="weather-detail-link" href="/?page=tide-monitor&amp;code=<?= e(rawurlencode((string) $station['code'])) ?>">Buka monitoring penuh <span aria-hidden="true">↗</span></a>
                        <footer>
                            <span><code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></span>
                            <span>Diamati <b data-field="time"><?= $r === null ? '—' : e($local((string) $r['observed_at'])) ?></b></span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="scope-hint">Status dihitung dari ambang tinggi pasang yang disetujui (cm di Parameter dikonversi ke meter) di Ambang &amp; persetujuan. Data simulasi tidak disimpan. Atur <code>APP_TIDE_FEED_URL</code> ke API JSON sensor (skema: <code>stations[].code, tide_level_m, change_1h_m, wave_height_m, wind_kmh, observed_at</code>) untuk memakai data asli.</p>
    </section>
    <?php
}


function render_tide_monitor_page(array $station): void{
    $charts = [
        ['tide_level_cm', 'Tinggi pasang', 'cm', '#2f80c9', 1, true],
        ['sensor_distance_cm', 'Jarak radar ke air', 'cm', '#17806b', 1, false],
        ['change_1h_cm', 'Perubahan 1 jam', 'cm', '#d9822b', 1, false],
    ];
    $url = '/?page=api-tide-feed&code='
        . rawurlencode((string) $station['code'])
        . '&history=1';
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Monitoring <?= e($station['name']) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js" defer></script>
        <script src="/assets/app.js" defer></script>
    </head>
    <body class="monitor-body">
        <main
            class="monitor-page"
            data-tide-live-monitor
            data-feed-url="<?= e($url . '&history=1') ?>">
            <?php render_ews_bar('tide', (string) $station['code']); ?>
            <header class="monitor-head">
                <div>
                    <p class="eyebrow">
                        MONITORING PASANG SURUT · DATA HARI INI
                    </p>
                    <h1><?= e($station['name']) ?></h1>
                    <p>
                        <?= e($station['region_label']) ?>
                        · <code><?= e($station['code']) ?></code>
                        · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>,
                        <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?>
                    </p>
                    <p>
                        Tanggal grafik:
                        <strong data-monitor-date>—</strong> (WIB)
                    </p>
                </div>
                <div class="monitor-actions">
                    <span class="weather-source dummy" data-monitor-source>
                        <i></i>Memuat…
                    </span>
                    <strong class="weather-clock" data-monitor-clock>
                        --:--:--
                    </strong>
                    <a
                        class="button secondary"
                        href="/?page=dashboard&amp;section=tide">
                        ← Kembali
                    </a>
                </div>
            </header>
            <section class="monitor-now monitor-now-5">
                <article class="monitor-main">
                    <span>Tinggi pasang</span>
                    <strong>
                        <span data-now="tide_level_cm">—</span>
                        <small>cm</small>
                    </strong>
                    <em class="weather-level" data-monitor-level>Memuat…</em>
                </article>
                <article>
                    <span>Jarak radar ke air</span>
                    <strong>
                        <span data-now="sensor_distance_cm">—</span>
                        <small>cm</small>
                    </strong>
                </article>
                <article>
                    <span>Tren</span>
                    <strong class="river-trend" data-now="trend">—</strong>
                </article>
                <article>
                    <span>Perubahan 1 jam</span>
                    <strong>
                        <span data-now="change_1h_cm">—</span>
                        <small>cm</small>
                    </strong>
                </article>
                <article>
                    <span>Diamati</span>
                    <strong class="weather-clock" data-now="observed_at">—</strong>
                </article>
            </section>
            <section class="monitor-charts">
                <?php foreach ($charts as [$key, $title, $unit, $color, $digits, $wide]): ?>
                    <article class="monitor-chart <?= $wide ? 'wide' : '' ?>">
                        <header>
                            <h2><?= e($title) ?></h2>
                            <span><?= e($unit) ?></span>
                        </header>
                        <canvas
                            data-chart="<?= e($key) ?>"
                            data-color="<?= e($color) ?>"
                            data-digits="<?= (int) $digits ?>"
                            style="display:block;width:100%;height:260px;"
                            role="img"
                            aria-label="Grafik <?= e($title) ?>"></canvas>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="panel monitor-table">
                <h2>
                    Data pengukuran hari ini
                    (<span data-monitor-count>0</span> data)
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Waktu</th>
                                <th>Jarak radar (cm)</th>
                                <th>Tinggi air (cm)</th>
                                <th>Perubahan 1 jam (cm)</th>
                                <th>Tren</th>
                            </tr>
                        </thead>
                        <tbody data-monitor-rows>
                            <tr>
                                <td colspan="6">Memuat…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <nav
                    class="monitor-pagination"
                    aria-label="Navigasi halaman data sensor"
                    style="display:flex;gap:8px;align-items:center;
               justify-content:center;flex-wrap:wrap;
               margin-top:20px;">
                    <button type="button" class="button secondary" data-page-prev>
                        Sebelumnya
                    </button>
                    <span data-page-numbers
                        style="display:flex;gap:5px;flex-wrap:wrap;"></span>
                    <button type="button" class="button secondary" data-page-next>
                        Berikutnya
                    </button>
                    <small data-page-info>Halaman 1 dari 1</small>
                </nav>
            </section>
        </main>
    </body>
    </html>
<?php
}
