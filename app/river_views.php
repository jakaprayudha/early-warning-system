<?php

declare(strict_types=1);


function render_river_page(array $user): void {
    $stations = river_stations($user);
    $sourceLabel = 'Sumber: API sensor';
    try {
        $feed = river_build_feed($stations, false);
    } catch (Throwable $e) {
        error_log('[RIVER PAGE] ' . $e->getMessage());
        $feed = [
            'source' => 'error',
            'stations' => [],
            'generated_at' => date(DATE_ATOM)
        ];
        $sourceLabel = 'Sensor tidak tersedia';
    }
    $readings = [];
    foreach ($feed['stations'] as $r) {
        $readings[(string) $r['code']] = $r;
    }
    $fmt = static fn(float $n): string => number_format($n, 1, ',', '.');
    $cards = [];
    $alerting = 0;
    $rising = 0;
    $maxLevel = null;

    foreach ($stations as $station) {
        $r = $readings[(string) $station['code']] ?? null;

        $cm = isset($r['water_level_cm'])
            ? (float) $r['water_level_cm']
            : null;

        [$label, $level] = river_ui_level(
            $cm,
            $station['thresholds'],
            $r['observed_at'] ?? null
        );

        if (in_array(
            $level,
            ['watch', 'alert', 'warning'],
            true
        )) {
            $alerting++;
        }

        if (($r['trend'] ?? '') === 'NAIK') {
            $rising++;
        }

        if ($cm !== null) {
            $maxLevel = max($maxLevel ?? $cm, $cm);
        }

        $cards[] = [
            $station, $r, $cm, $label, $level
        ];
    }

    $clock = (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    ))->format('H:i:s');
    ?>

    <section
        class="hazard-types-section weather-page river-page"
        data-river-page
        data-feed-url="/?page=api-river-feed"
    >
        <div class="section-heading">
            <div>
                <p class="eyebrow">EWS SUNGAI</p>

                <h2>
                    Monitoring sungai
                    <span><?= count($stations) ?></span>
                </h2>

                <p class="section-note">
                    Tinggi muka air sungai.
                    Diperbarui setiap 5 menit.
                </p>
            </div>

            <span
                class="weather-source <?= $feed['source'] === 'live' ? 'live' : 'error' ?>"
                data-river-source
            >
                <?= e($sourceLabel) ?>
            </span>
        </div>

        <div class="weather-summary">
            <article>
                <span>Pos sungai</span>
                <strong><?= count($stations) ?></strong>
            </article>

            <article>
                <span>Status waspada ke atas</span>
                <strong data-river-alerting><?= $alerting ?></strong>
            </article>

            <article>
                <span>Muka air tertinggi</span>
                <strong>
                    <span data-river-max>
                        <?= $maxLevel === null ? '—' : e($fmt($maxLevel)) ?>
                    </span>
                    <small> cm</small>
                </strong>
            </article>

            <article>
                <span>Sedang naik</span>
                <strong data-river-rising><?= $rising ?></strong>
            </article>

            <article>
                <span>Pembaruan terakhir</span>
                <strong
                    class="weather-clock"
                    data-river-updated
                ><?= e($clock) ?></strong>
            </article>
        </div>

        <?php if (!$stations): ?>

            <div class="panel hazard-empty">
                <strong>Belum ada pos sungai</strong>
            </div>

        <?php else: ?>

            <div class="weather-grid">

                <?php foreach ($cards as [
                    $station, $r, $cm, $label, $level
                ]):

                    $trend = $r['trend'] ?? '';

                    $top = max(
                        array_map(
                            static fn($t) => (float) $t['value'],
                            $station['thresholds']
                        ) ?: [300.0]
                    ) * 1.25;

                    $pct = $cm === null
                        ? 0
                        : (int) min(
                            100,
                            max(
                                0,
                                round($cm / $top * 100 / 5) * 5
                            )
                        );
                ?>

                    <article
                        class="panel weather-card river-card"
                        data-river-station="<?= e((string) $station['code']) ?>"
                    >
                        <header>
                            <div>
                                <h3><?= e($station['name']) ?></h3>
                                <p><?= e($station['region_label']) ?></p>
                            </div>

                            <span
                                class="weather-level <?= $level ? 'river-' . e($level) : '' ?>"
                                data-field="level"
                            ><?= e($label) ?></span>
                        </header>

                        <div class="weather-rain">
                            <strong data-field="level_cm">
                                <?= $cm === null ? '—' : e($fmt($cm)) ?>
                            </strong>
                            <span>cm</span>

                            <em class="river-trend" data-field="trend">
                                <?= e([
                                    'NAIK' => '▲ Naik',
                                    'TURUN' => '▼ Turun',
                                    'STABIL' => '■ Stabil'
                                ][$trend] ?? '—') ?>
                            </em>
                        </div>

                        <div class="river-gauge" aria-hidden="true">
                            <i
                                class="river-fill <?= $level ? 'river-' . e($level) : '' ?> bar-w-<?= $pct ?>"
                                data-field="gauge"
                            ></i>
                        </div>

                        <dl class="weather-metrics">
                            <div>
                                <dt>Perubahan 1 jam</dt>
                                <dd>
                                    <span data-field="change">
                                        <?= $r !== null && $r['change_1h_cm'] !== null
                                            ? e($fmt((float) $r['change_1h_cm']))
                                            : '—' ?>
                                    </span> cm
                                </dd>
                            </div>

                            <div>
                                <dt>Jarak radar ke air</dt>
                                <dd>
                                    <span data-field="distance">
                                        <?= isset($r['sensor_distance_cm'])
                                            ? e($fmt((float) $r['sensor_distance_cm']))
                                            : '—' ?>
                                    </span> cm
                                </dd>
                            </div>
                        </dl>

                        <a
                            class="weather-detail-link"
                            href="/?page=river-monitor&amp;code=<?= e(rawurlencode((string) $station['code'])) ?>"
                        >
                            Buka monitoring penuh
                            <span aria-hidden="true">↗</span>
                        </a>

                        <footer>
                            <span>
                                <code><?= e($station['code']) ?></code>
                            </span>

                            <span>
                                Diamati
                                <b data-field="time">
                                    <?= e(($r['observed_at'] ?? null)
                                        ? substr((string) $r['observed_at'], 11, 8) . ' WIB'
                                        : '—') ?>
                                </b>
                            </span>
                        </footer>
                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <p class="scope-hint">
            Status dihitung dari ambang yang disetujui.
            Tanpa ambang lengkap, kondisi tidak disimpulkan sebagai Normal.
        </p>
    </section>
    <?php
}



function render_river_monitor_page(array $station): void {
    $charts = [
        ['water_level_cm', 'Tinggi muka air', 'cm', '#2f80c9', 1, true],
        ['sensor_distance_cm', 'Jarak radar ke air', 'cm', '#17806b', 1, false],
        ['change_1h_cm', 'Perubahan 1 jam', 'cm', '#d9822b', 1, false]
    ];

    $url = '/?page=api-river-feed&code='
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
        <script src="/assets/app.js" defer></script>
    </head>

    <body class="monitor-body">
    <main
        class="monitor-page"
        data-river-monitor
        data-feed-url="<?= e($url) ?>"
    >
        <?php render_ews_bar('river', (string) $station['code']); ?>

        <header class="monitor-head">
            <div>
                <p class="eyebrow">
                    MONITORING SUNGAI · HARI INI
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
                <span
                    class="weather-source dummy"
                    data-monitor-source
                >Memuat…</span>

                <strong
                    class="weather-clock"
                    data-monitor-clock
                >--:--:--</strong>

                <a
                    class="button secondary"
                    href="/?page=dashboard&amp;section=river"
                >← Kembali</a>
            </div>
        </header>

        <section class="monitor-now monitor-now-5">
            <article class="monitor-main">
                <span>Tinggi muka air</span>
                <strong>
                    <span data-now="water_level_cm">—</span>
                    <small> cm</small>
                </strong>
                <em class="weather-level" data-monitor-level>Memuat…</em>
            </article>

            <article>
                <span>Jarak radar ke air</span>
                <strong>
                    <span data-now="sensor_distance_cm">—</span>
                    <small> cm</small>
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
                    <small> cm</small>
                </strong>
            </article>

            <article>
                <span>Diamati</span>
                <strong
                    class="weather-clock"
                    data-now="observed_at"
                >—</strong>
            </article>
        </section>

        <section class="monitor-charts">
            <?php foreach (
                $charts as [$key, $title, $unit, $color, $digits, $wide]
            ): ?>
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
                        aria-label="Grafik <?= e($title) ?>"
                    ></canvas>
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
                            <th>Waktu</th>
                            <th>Muka air (cm)</th>
                            <th>Jarak radar (cm)</th>
                            <th>Perubahan 1 jam (cm)</th>
                            <th>Tren</th>
                        </tr>
                    </thead>

                    <tbody data-monitor-rows>
                        <tr>
                            <td colspan="5">Memuat…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                class="monitor-pagination"
                aria-label="Halaman data sensor"
                style="display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:20px;"
            >
                <button
                    type="button"
                    class="button secondary"
                    data-page-prev
                >Sebelumnya</button>

                <span
                    data-page-numbers
                    style="display:flex;gap:5px;flex-wrap:wrap;"
                ></span>

                <button
                    type="button"
                    class="button secondary"
                    data-page-next
                >Berikutnya</button>

                <small data-page-info>Halaman 1 dari 1</small>
            </nav>
        </section>
    </main>
    </body>
    </html>
    <?php
}

