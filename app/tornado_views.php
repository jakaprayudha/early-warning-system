<?php

declare(strict_types=1);


function render_tornado_page(array $user): void
{
    $stations = tornado_stations($user);
    $sourceState = 'live';
    try {
        $feed = tornado_fetch_feed(array_map(
            static fn(array $s): string => (string) $s['code'],
            $stations
        ));
    } catch (Throwable $e) {
        error_log('[TORNADO PAGE] ' . $e->getMessage());

        $feed = [
            'source' => 'error',
            'stations' => []
        ];

        $sourceState = 'error';
    }

    $readings = [];

    foreach ($feed['stations'] as $row) {
        $readings[(string) $row['code']] = $row;
    }

    $levels = tornado_levels();

    $trends = [
        'rising' => ['Menguat', '▲'],
        'falling' => ['Melemah', '▼'],
        'steady' => ['Stabil', '■']
    ];

    $fmt = static fn(float $v): string =>
    number_format($v, 1, ',', '.');

    $alerting = 0;
    $rising = 0;
    $maxLevel = null;
    $cards = [];

    foreach ($stations as $station) {
        $r = $readings[(string) $station['code']] ?? null;

        $speed = isset($r['wind_kmh'])
            ? (float) $r['wind_kmh']
            : null;

        $ts = isset($r['observed_at'])
            ? strtotime((string) $r['observed_at'])
            : false;

        $fresh = $ts !== false
            && time() - $ts >= -60
            && time() - $ts <= 900;

        $rules = $station['thresholds'];

        $w = $rules['watch']['value'] ?? null;
        $a = $rules['alert']['value'] ?? null;
        $d = $rules['warning']['value'] ?? null;

        $configured =
            is_numeric($w) &&
            is_numeric($a) &&
            is_numeric($d) &&
            $w < $a &&
            $a < $d;

        $level = $speed !== null && $fresh && $configured
            ? tornado_level($speed, $rules)
            : null;

        $label = $speed === null
            ? 'DATA TIDAK TERSEDIA'
            : (!$fresh
                ? 'DATA TERLAMBAT'
                : (!$configured
                    ? 'AMBANG BELUM DIATUR'
                    : $levels[$level]));

        if ($level !== null && $level !== 'normal') {
            $alerting++;
        }

        if ($fresh && ($r['trend'] ?? '') === 'rising') {
            $rising++;
        }

        if ($speed !== null && $fresh) {
            $maxLevel = max($maxLevel ?? $speed, $speed);
        }

        $cards[] = [
            $station,
            $r,
            $speed,
            $level,
            $label
        ];
    }

    $sourceLabel = $sourceState === 'live'
        ? 'Sumber: API sensor'
        : 'Sensor tidak tersedia';

    $nowWib = (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    ))->format('H:i:s');
?>

    <section
        class="hazard-types-section weather-page tornado-page"
        data-tornado-page
        data-feed-url="/?page=api-tornado-feed"
        data-levels="<?= e(json_encode(
                            $levels,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        )) ?>">
        <div class="section-heading">
            <div>
                <p class="eyebrow">EWS TORNADO</p>

                <h2>
                    Monitoring tornado
                    <span><?= count($stations) ?></span>
                </h2>

                <p class="section-note">
                    Pengukuran angin dan cuaca terbaru.
                    Diperbarui otomatis setiap 5 menit.
                </p>
            </div>

            <span
                class="weather-source <?= $sourceState ?>"
                data-tornado-source><?= e($sourceLabel) ?></span>
        </div>

        <div class="weather-summary">
            <article>
                <span>Titik rawan tornado</span>
                <strong><?= count($stations) ?></strong>
            </article>

            <article>
                <span>Status Waspada ke atas</span>
                <strong data-tornado-alerting><?= $alerting ?></strong>
            </article>

            <article>
                <span>Angin tertinggi</span>
                <strong>
                    <span data-tornado-max>
                        <?= $maxLevel === null
                            ? '—'
                            : e($fmt($maxLevel)) ?>
                    </span>
                    <small> km/jam</small>
                </strong>
            </article>

            <article>
                <span>Angin sedang menguat</span>
                <strong data-tornado-rising><?= $rising ?></strong>
            </article>

            <article>
                <span>Pembaruan terakhir</span>
                <strong
                    class="weather-clock"
                    data-tornado-updated><?= e($nowWib) ?></strong>
            </article>
        </div>

        <?php if ($stations === []): ?>

            <div class="panel hazard-empty">
                <strong>Belum ada titik rawan tornado</strong>
                <p>
                    Tambahkan lokasi dengan jenis bahaya Tornado
                    di Wilayah &amp; lokasi.
                </p>
            </div>

        <?php else: ?>

            <div class="weather-grid">
                <?php foreach (
                    $cards as [$station, $r, $speed, $level, $label]
                ):

                    $change = $r['change_1h_kmh'] ?? null;
                    $trend = $r['trend'] ?? 'TIDAK_ADA_DATA';

                    $top = max(array_map(
                        static fn(array $t): float =>
                        (float) $t['value'],
                        $station['thresholds']
                    ) ?: [60.0]) * 1.25;

                    $pct = $speed === null
                        ? 0
                        : (int) max(
                            0,
                            min(100, round($speed / $top * 20) * 5)
                        );
                ?>

                    <article
                        class="panel weather-card tornado-card"
                        data-tornado-station="<?= e((string) $station['code']) ?>"
                        data-thresholds="<?= e(json_encode(
                                                $station['thresholds'],
                                                JSON_THROW_ON_ERROR
                                            )) ?>">
                        <header>
                            <div>
                                <h3><?= e($station['name']) ?></h3>
                                <p><?= e($station['region_label']) ?></p>
                            </div>

                            <span
                                class="weather-level <?= $level === null
                                                            ? ''
                                                            : 'river-' . e($level) ?>"
                                data-field="level"><?= e($label) ?></span>
                        </header>

                        <div class="weather-rain">
                            <strong data-field="level_cm">
                                <?= $speed === null
                                    ? '—'
                                    : e($fmt($speed)) ?>
                            </strong>
                            <span>km/jam</span>

                            <em
                                class="river-trend"
                                data-field="trend"><?= e(isset($trends[$trend])
                                                        ? $trends[$trend][1] . ' ' . $trends[$trend][0]
                                                        : '—') ?></em>
                        </div>

                        <div class="river-gauge" aria-hidden="true">
                            <i
                                class="river-fill <?= $level === null
                                                        ? ''
                                                        : 'river-' . e($level) ?> bar-w-<?= $pct ?>"
                                data-field="gauge"></i>
                        </div>

                        <dl class="weather-metrics">
                            <div>
                                <dt>Perubahan 1 jam</dt>
                                <dd>
                                    <span data-field="change">
                                        <?= $change === null
                                            ? '—'
                                            : e($fmt((float) $change)) ?>
                                    </span> km/jam
                                </dd>
                            </div>

                            <div>
                                <dt>Suhu udara</dt>
                                <dd>
                                    <span data-field="temperature">
                                        <?= isset($r['temperature_c'])
                                            ? e($fmt((float) $r['temperature_c']))
                                            : '—' ?>
                                    </span> °C
                                </dd>
                            </div>

                            <div>
                                <dt>Tekanan udara</dt>
                                <dd>
                                    <span data-field="pressure">
                                        <?= isset($r['pressure_hpa'])
                                            ? e($fmt((float) $r['pressure_hpa']))
                                            : '—' ?>
                                    </span> hPa
                                </dd>
                            </div>

                            <div>
                                <dt>Kelembapan</dt>
                                <dd>
                                    <span data-field="humidity">
                                        <?= isset($r['humidity_pct'])
                                            ? e($fmt((float) $r['humidity_pct']))
                                            : '—' ?>
                                    </span> %
                                </dd>
                            </div>

                            <div>
                                <dt>Curah hujan</dt>
                                <dd>
                                    <span data-field="rain">
                                        <?= isset($r['rain_mm'])
                                            ? e($fmt((float) $r['rain_mm']))
                                            : '—' ?>
                                    </span> mm
                                </dd>
                            </div>

                            <div>
                                <dt>Arah angin</dt>
                                <dd>
                                    <span data-field="direction">
                                        <?= e((string) (
                                            $r['wind_direction'] ?? '—'
                                        )) ?>
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt>Jumlah petir</dt>
                                <dd>
                                    <span data-field="lightning">
                                        <?= isset($r['lightning_count'])
                                            ? e($fmt((float) $r['lightning_count']))
                                            : '—' ?>
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt>Ambang</dt>
                                <dd class="river-thresholds">
                                    <?php
                                    $parts = [];

                                    foreach (
                                        $station['thresholds'] as $sev => $t
                                    ) {
                                        $parts[] =
                                            ($levels[$sev] ?? $sev)
                                            . ' '
                                            . ($t['op'] === '>' ? '>' : '≥')
                                            . ' '
                                            . $fmt((float) $t['value']);
                                    }

                                    echo $parts === []
                                        ? 'Belum ada'
                                        : e(implode(' · ', $parts));
                                    ?>
                                </dd>
                            </div>
                        </dl>

                        <a
                            class="weather-detail-link"
                            href="/?page=tornado-monitor&amp;code=<?= e(rawurlencode(
                                                                        (string) $station['code']
                                                                    )) ?>">
                            Buka monitoring penuh
                            <span aria-hidden="true">↗</span>
                        </a>
                        <footer>
                            <span>
                                <code><?= e($station['code']) ?></code>
                                · <?= e(number_format(
                                        (float) $station['latitude'],
                                        5,
                                        '.',
                                        ''
                                    )) ?>,
                                <?= e(number_format(
                                    (float) $station['longitude'],
                                    5,
                                    '.',
                                    ''
                                )) ?>
                            </span>
                            <span>
                                Diamati
                                <b data-field="time">
                                    <?= e(isset($r['observed_at'])
                                        ? substr(
                                            (string) $r['observed_at'],
                                            11,
                                            8
                                        ) . ' WIB'
                                        : '—') ?>
                                </b>
                            </span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="scope-hint">
            Status hanya ditampilkan bila data masih segar
            dan ambang kecepatan angin lengkap serta disetujui.
            Kecepatan angin saja tidak membuktikan terjadinya tornado.
        </p>
    </section>
<?php
}


function render_tornado_monitor_page(array $station): void
{
    $levels = tornado_levels();
    $charts = [
        ['wind_kmh', 'Kecepatan angin', 'km/jam', '#2f80c9', 1, true],
        ['change_1h_kmh', 'Perubahan 1 jam', 'km/jam', '#7a5bc2', 1, false],
        ['temperature_c', 'Suhu udara', '°C', '#d9822b', 1, false],
        ['pressure_hpa', 'Tekanan udara', 'hPa', '#b53a31', 1, false],
        ['humidity_pct', 'Kelembapan', '%', '#17806b', 1, false],
        ['rain_mm', 'Curah hujan', 'mm', '#2f80c9', 1, false],
    ];

    $url = '/?page=api-tornado-feed&code='
        . rawurlencode((string) $station['code']);
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
            data-tornado-monitor
            data-feed-url="<?= e($url . '&history=1') ?>"
            data-levels="<?= e(json_encode(
                                $levels,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                            )) ?>">
            <?php render_ews_bar('tornado', (string) $station['code']); ?>
            <header class="monitor-head">
                <div>
                    <p class="eyebrow">
                        MONITORING TORNADO · DATA HARI INI
                    </p>
                    <h1><?= e($station['name']) ?></h1>
                    <p>
                        <?= e($station['region_label']) ?>
                        · <code><?= e($station['code']) ?></code>
                        · <?= e(number_format(
                                (float) $station['latitude'],
                                5,
                                '.',
                                ''
                            )) ?>,
                        <?= e(number_format(
                            (float) $station['longitude'],
                            5,
                            '.',
                            ''
                        )) ?>
                    </p>
                    <p>
                        Tanggal grafik:
                        <strong data-monitor-date>—</strong> (WIB)
                    </p>
                </div>
                <div class="monitor-actions">
                    <span
                        class="weather-source dummy"
                        data-monitor-source>Memuat…</span>
                    <strong
                        class="weather-clock"
                        data-monitor-clock>--:--:--</strong>
                    <a class="button secondary" href="/?page=dashboard&amp;section=tornado">← Kembali</a>
                </div>
            </header>
            <section class="monitor-now monitor-now-5">
                <article class="monitor-main">
                    <span>Kecepatan angin</span>
                    <strong>
                        <span data-now="wind_kmh">—</span>
                        <small> km/jam</small>
                    </strong>
                    <em
                        class="weather-level"
                        data-monitor-level>Memuat…</em>
                </article>
                <article>
                    <span>Tren</span>
                    <strong class="river-trend" data-now="trend">—</strong>
                </article>
                <article>
                    <span>Perubahan 1 jam</span>
                    <strong>
                        <span data-now="change_1h_kmh">—</span>
                        <small> km/jam</small>
                    </strong>
                </article>
                <article>
                    <span>Suhu udara</span>
                    <strong>
                        <span data-now="temperature_c">—</span>
                        <small> °C</small>
                    </strong>
                </article>
                <article>
                    <span>Tekanan udara</span>
                    <strong>
                        <span data-now="pressure_hpa">—</span>
                        <small> hPa</small>
                    </strong>
                </article>
                <article>
                    <span>Diamati</span>
                    <strong
                        class="weather-clock"
                        data-now="observed_at">—</strong>
                </article>
            </section>
            <section class="monitor-now monitor-now-5">
                <article>
                    <span>Kelembapan</span>
                    <strong>
                        <span data-now="humidity_pct">—</span>
                        <small> %</small>
                    </strong>
                </article>
                <article>
                    <span>Curah hujan</span>
                    <strong>
                        <span data-now="rain_mm">—</span>
                        <small> mm</small>
                    </strong>
                </article>
                <article>
                    <span>Arah angin</span>
                    <strong data-now="wind_direction">—</strong>
                    <small><span data-now="wind_deg">—</span>°</small>
                </article>
                <article>
                    <span>Jumlah petir</span>
                    <strong data-now="lightning_count">—</strong>
                </article>
                <article>
                    <span>Jarak petir (nilai sensor)</span>
                    <strong data-now="lightning_distance">—</strong>
                </article>
                <article>
                    <span>Noise / Disturber</span>
                    <strong>
                        <span data-now="noise">—</span>
                        /
                        <span data-now="disturber">—</span>
                    </strong>
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
                            <?= $wide ? 'data-thresholds="1"' : '' ?>
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
                                <th>Waktu</th>
                                <th>Angin (km/jam)</th>
                                <th>Perubahan 1 jam</th>
                                <th>Arah angin</th>
                                <th>Derajat</th>
                                <th>Suhu (°C)</th>
                                <th>Kelembapan (%)</th>
                                <th>Tekanan (hPa)</th>
                                <th>Hujan (mm)</th>
                                <th>Jumlah petir</th>
                                <th>Jarak petir (nilai sensor)</th>
                                <th>Noise</th>
                                <th>Disturber</th>
                            </tr>
                        </thead>
                        <tbody data-monitor-rows>
                            <tr>
                                <td colspan="13">Memuat…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <nav
                    class="monitor-pagination" aria-label="Halaman data sensor" style="display:flex;gap:8px;justify-content:center; align-items:center;flex-wrap:wrap;margin-top:20px;">
                    <button type="button" class="button secondary" data-page-prev>Sebelumnya</button>
                    <span data-page-numbers style="display:flex;gap:5px;flex-wrap:wrap;"></span>
                    <button type="button" class="button secondary" data-page-next>Berikutnya</button>
                    <small data-page-info>Halaman 1 dari 1</small>
                </nav>
            </section>
        </main>
    </body>

    </html>
<?php
}
