<?php

declare(strict_types=1);


function render_weather_page(array $user): void
{
    $stations = weather_stations($user);

    $codes = array_map(
        static fn(array $s): string => (string) $s['code'],
        $stations
    );

    $sourceState = 'live';

    try {
        $feed = weather_fetch_feed($codes);
    } catch (Throwable $e) {
        error_log('[WEATHER PAGE] ' . $e->getMessage());

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

    $levels = weather_rain_levels();

    $fmt = static fn(?float $v): string =>
    $v === null ? '—' : number_format($v, 1, ',', '.');

    // Ringkasan hanya memakai intensitas dengan data segar.
    $validRains = [];

    foreach ($readings as $row) {
        $ts = isset($row['observed_at'])
            ? strtotime((string) $row['observed_at'])
            : false;

        $fresh = $ts !== false
            && time() - $ts >= -60
            && time() - $ts <= 900;

        if (
            $fresh &&
            isset($row['rain_mm_h']) &&
            is_numeric($row['rain_mm_h'])
        ) {
            $validRains[] = (float) $row['rain_mm_h'];
        }
    }

    $rainingNow = $validRains === []
        ? null
        : count(array_filter(
            $validRains,
            static fn(float $v): bool => $v >= 0.1
        ));

    $maxRain = $validRains === []
        ? null : max($validRains);

    $avgRain = $validRains === []
        ? null
        : array_sum($validRains) / count($validRains);

    $nowWib = (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    ))->format('H:i:s');
?>

    <section
        class="hazard-types-section weather-page"
        data-weather-page
        data-feed-url="/?page=api-weather-feed"
        data-levels="<?= e(json_encode(
                            array_map(
                                static fn(array $l): array => [
                                    'label' => $l[0],
                                    'min' => $l[1]
                                ],
                                $levels
                            ),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        )) ?>">
        <div class="section-heading">
            <div>
                <p class="eyebrow">EWS CUACA</p>

                <h2>
                    Monitoring cuaca
                    <span><?= count($stations) ?></span>
                </h2>

                <p class="section-note">
                    Stasiun cuaca dengan intensitas hujan terbaru.
                    Diperbarui otomatis tiap 5 menit.
                </p>
            </div>

            <span
                class="weather-source <?= e($sourceState) ?>"
                data-weather-source><?= $sourceState === 'live'
                                        ? 'Sumber: API sensor'
                                        : 'Koneksi sensor bermasalah' ?></span>
        </div>

        <div class="weather-summary">
            <article>
                <span>Stasiun cuaca</span>
                <strong><?= count($stations) ?></strong>
            </article>

            <article>
                <span>Sedang hujan</span>
                <strong data-weather-raining>
                    <?= $rainingNow === null ? '—' : $rainingNow ?>
                </strong>
            </article>

            <article>
                <span>Hujan tertinggi</span>
                <strong>
                    <span data-weather-max><?= e($fmt($maxRain)) ?></span>
                    <small> mm/jam</small>
                </strong>
            </article>

            <article>
                <span>Rata-rata hujan</span>
                <strong>
                    <span data-weather-avg><?= e($fmt($avgRain)) ?></span>
                    <small> mm/jam</small>
                </strong>
            </article>

            <article>
                <span>Pembaruan terakhir</span>
                <strong
                    class="weather-clock"
                    data-weather-updated><?= e($nowWib) ?></strong>
            </article>
        </div>

        <?php if ($stations === []): ?>

            <div class="panel hazard-empty">
                <strong>Belum ada stasiun cuaca</strong>
                <p>
                    Tambahkan lokasi dengan jenis bahaya Cuaca
                    di Wilayah &amp; lokasi.
                </p>
            </div>

        <?php else: ?>

            <div class="weather-grid">
                <?php foreach ($stations as $station):

                    $r = $readings[(string) $station['code']] ?? null;

                    $rain = isset($r['rain_mm_h'])
                        ? (float) $r['rain_mm_h']
                        : null;

                    $ts = isset($r['observed_at'])
                        ? strtotime((string) $r['observed_at'])
                        : false;

                    $fresh = $ts !== false
                        && time() - $ts >= -60
                        && time() - $ts <= 900;

                    $level = $fresh && $rain !== null
                        ? weather_rain_level($rain)
                        : null;

                    $status = $r === null || !isset($r['observed_at'])
                        ? 'BELUM ADA DATA'
                        : (!$fresh
                            ? 'DATA TERLAMBAT'
                            : ($rain === null ? 'INTENSITAS BELUM TERSEDIA' : $levels[$level][0]));
                    $value = static fn(string $key): ?float =>
                    isset($r[$key]) && is_numeric($r[$key])
                        ? (float) $r[$key]
                        : null;

                    $direction = (string) (
                        $r['wind_direction'] ?? '—'
                    );
                ?>

                    <article
                        class="panel weather-card"
                        data-weather-station="<?= e($station['code']) ?>">
                        <header>
                            <div>
                                <h3><?= e($station['name']) ?></h3>
                                <p><?= e($station['region_label']) ?></p>
                            </div>

                            <span
                                class="weather-level<?= $level !== null
                                                        ? ' level-' . e($level)
                                                        : '' ?>"
                                data-field="level"><?= e($status) ?></span>
                        </header>

                        <div class="weather-rain">
                            <strong data-field="rain">
                                <?= e($fmt($rain)) ?>
                            </strong>
                            <span>mm/jam</span>
                        </div>

                        <dl class="weather-metrics">
                            <div>
                                <dt>Akumulasi hujan</dt>
                                <dd>
                                    <span data-field="rain-total">
                                        <?= e($fmt(
                                            $value('rain_total_mm')
                                        )) ?>
                                    </span> mm
                                </dd>
                            </div>

                            <div>
                                <dt>Suhu</dt>
                                <dd>
                                    <span data-field="temp">
                                        <?= e($fmt(
                                            $value('temperature_c')
                                        )) ?>
                                    </span> °C
                                </dd>
                            </div>

                            <div>
                                <dt>Kelembapan</dt>
                                <dd>
                                    <span data-field="hum">
                                        <?= e($fmt(
                                            $value('humidity_pct')
                                        )) ?>
                                    </span> %
                                </dd>
                            </div>

                            <div>
                                <dt>Angin</dt>
                                <dd>
                                    <span data-field="wind">
                                        <?= e($fmt(
                                            $value('wind_kmh')
                                        )) ?>
                                    </span> km/jam
                                    <small data-field="dir">
                                        <?= e($direction) ?>
                                    </small>
                                </dd>
                            </div>

                            <div>
                                <dt>Tekanan</dt>
                                <dd>
                                    <span data-field="pres">
                                        <?= e($fmt(
                                            $value('pressure_hpa')
                                        )) ?>
                                    </span> hPa
                                </dd>
                            </div>
                        </dl>

                        <a
                            class="weather-detail-link"
                            href="/?page=weather-monitor&amp;code=<?= e(
                                                                        rawurlencode((string) $station['code'])
                                                                    ) ?>">
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
                                    <?= isset($r['observed_at'])
                                        ? e(substr(
                                            (string) $r['observed_at'],
                                            11,
                                            8
                                        ) . ' WIB')
                                        : '—' ?>
                                </b>
                            </span>
                        </footer>
                    </article>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>

        <p class="scope-hint">
            Intensitas hujan (mm/jam) dihitung dari perubahan
            akumulasi sensor (mm) per interval pengukuran.
            Data yang tidak tersedia atau terlambat tidak
            dianggap sebagai kondisi tidak hujan.
        </p>
    </section>
<?php
}



function render_weather_monitor_page(array $station): void
{
    $levels = [];

    foreach (weather_rain_levels() as $key => [$label, $min]) {
        $levels[$key] = [
            'label' => $label,
            'min' => $min
        ];
    }

    $charts = [
        ['rain_mm_h', 'Intensitas hujan', 'mm/jam', '#2f80c9', 1],
        ['rain_total_mm', 'Akumulasi hujan', 'mm', '#17806b', 1],
        ['temperature_c', 'Suhu', '°C', '#d9822b', 1],
        ['humidity_pct', 'Kelembapan', '%', '#17806b', 1],
        ['wind_kmh', 'Kecepatan angin', 'km/jam', '#7a5bc2', 1],
        ['pressure_hpa', 'Tekanan udara', 'hPa', '#b53a31', 1],
    ];
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
            data-weather-monitor
            data-feed-url="/?page=api-weather-feed&amp;code=<?= e(
                                                                rawurlencode((string) $station['code'])
                                                            ) ?>&amp;history=1"
            data-levels="<?= e(json_encode(
                                $levels,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                            )) ?>">
            <?php render_ews_bar('weather', (string) $station['code']); ?>

            <header class="monitor-head">
                <div>
                    <p class="eyebrow">
                        MONITORING CUACA · DATA HARI INI
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
                        <strong data-monitor-date>—</strong> WIB
                    </p>
                </div>

                <div class="monitor-actions">
                    <span
                        class="weather-source dummy"
                        data-monitor-source>Memuat…</span>

                    <strong
                        class="weather-clock"
                        data-monitor-clock>--:--:--</strong>

                    <a
                        class="button secondary"
                        href="/?page=dashboard&amp;section=weather">← Kembali</a>
                </div>
            </header>

            <section class="monitor-now">
                <article class="monitor-main">
                    <span>Intensitas hujan</span>

                    <strong>
                        <span data-now="rain_mm_h">—</span>
                        <small> mm/jam</small>
                    </strong>

                    <em
                        class="weather-level"
                        data-monitor-level>—</em>
                </article>

                <article>
                    <span>Akumulasi hujan</span>
                    <strong>
                        <span data-now="rain_total_mm">—</span>
                        <small> mm</small>
                    </strong>
                </article>

                <article>
                    <span>Suhu</span>
                    <strong>
                        <span data-now="temperature_c">—</span>
                        <small> °C</small>
                    </strong>
                </article>

                <article>
                    <span>Kelembapan</span>
                    <strong>
                        <span data-now="humidity_pct">—</span>
                        <small> %</small>
                    </strong>
                </article>

                <article>
                    <span>Angin</span>
                    <strong>
                        <span data-now="wind_kmh">—</span>
                        <small> km/jam</small>
                    </strong>
                    <small data-now="dir">—</small>
                </article>

                <article>
                    <span>Tekanan</span>
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

            <section class="monitor-charts">
                <?php foreach (
                    $charts as [$key, $title, $unit, $color, $digits]
                ): ?>

                    <article
                        class="monitor-chart <?= $key === 'rain_mm_h'
                                                    ? 'wide'
                                                    : '' ?>">
                        <header>
                            <h2><?= e($title) ?></h2>
                            <span><?= e($unit) ?></span>
                        </header>

                        <canvas
                            data-chart="<?= e($key) ?>"
                            data-color="<?= e($color) ?>"
                            data-digits="<?= $digits ?>"
                            style="display:block;width:100%;height:260px"
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

                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Intensitas (mm/jam)</th>
                                <th>Akumulasi (mm)</th>
                                <th>Suhu (°C)</th>
                                <th>Lembap (%)</th>
                                <th>Angin (km/jam)</th>
                                <th>Arah angin</th>
                                <th>Tekanan (hPa)</th>
                            </tr>
                        </thead>

                        <tbody data-monitor-rows>
                            <tr>
                                <td colspan="8">Memuat…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav
                    class="monitor-pagination"
                    aria-label="Halaman data sensor"
                    style="display:flex;gap:8px;align-items:center;
                       justify-content:center;flex-wrap:wrap;
                       margin-top:20px">
                    <button
                        type="button"
                        class="button secondary"
                        data-page-prev>Sebelumnya</button>

                    <span
                        data-page-numbers
                        style="display:flex;gap:5px;flex-wrap:wrap"></span>

                    <button
                        type="button"
                        class="button secondary"
                        data-page-next>Berikutnya</button>

                    <small data-page-info>Halaman 1 dari 1</small>
                </nav>
            </section>
        </main>
    </body>

    </html>
<?php
}
