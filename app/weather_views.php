<?php

declare(strict_types=1);

function render_weather_page(array $user): void
{
    $stations = weather_stations($user);
    $codes = array_map(static fn(array $s): string => (string) $s['code'], $stations);
    $feed = weather_fetch_feed($codes);
    $readings = [];
    foreach ($feed['stations'] as $row) {
        $readings[(string) $row['code']] = $row;
    }
    $levels = weather_rain_levels();
    $values = array_map(static fn(array $r): float => (float) ($r['rain_mm_h'] ?? 0), $readings);
    $rainingNow = count(array_filter($values, static fn(float $v): bool => $v >= 0.1));
    $maxRain = $values === [] ? 0.0 : max($values);
    $avgRain = $values === [] ? 0.0 : array_sum($values) / count($values);
    $sourceLabel = $feed['source'] === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
    $local = static fn(?string $iso): string => (new DateTimeImmutable($iso ?? 'now'))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s');
    $fmt = static fn(float $v): string => number_format($v, 1, ',', '.');
    ?>
    <section class="hazard-types-section weather-page" data-weather-page data-feed-url="/?page=api-weather-feed" data-levels="<?= e(json_encode(array_map(static fn(array $l): array => ['label' => $l[0], 'min' => $l[1]], $levels), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="section-heading">
            <div><p class="eyebrow">EWS CUACA</p><h2>Monitoring cuaca <span><?= count($stations) ?></span></h2>
            <p class="section-note">Stasiun berbahaya cuaca beserta pembacaan sensor terbaru. Diperbarui otomatis tiap 15 detik.</p></div>
            <span class="weather-source <?= $feed['source'] === 'live' ? 'live' : 'dummy' ?>" data-weather-source><i></i><?= e($sourceLabel) ?></span>
        </div>
        <div class="weather-summary">
            <article><span>Stasiun cuaca</span><strong><?= count($stations) ?></strong></article>
            <article><span>Sedang hujan</span><strong data-weather-raining><?= $rainingNow ?></strong></article>
            <article><span>Hujan tertinggi</span><strong><span data-weather-max><?= e($fmt($maxRain)) ?></span><small> mm/jam</small></strong></article>
            <article><span>Rata-rata hujan</span><strong><span data-weather-avg><?= e($fmt($avgRain)) ?></span><small> mm/jam</small></strong></article>
            <article><span>Pembaruan terakhir</span><strong class="weather-clock" data-weather-updated><?= e($local(null)) ?></strong></article>
        </div>
        <?php if ($stations === []): ?>
            <div class="panel hazard-empty"><strong>Belum ada stasiun cuaca</strong><p>Tambahkan lokasi dengan jenis bahaya Cuaca di Wilayah &amp; lokasi.</p></div>
        <?php else: ?>
            <div class="weather-grid">
                <?php foreach ($stations as $station):
                    $r = $readings[(string) $station['code']] ?? null;
                    $rain = $r === null ? 0.0 : (float) $r['rain_mm_h'];
                    $level = weather_rain_level($rain);
                    ?>
                    <article class="panel weather-card" data-weather-station="<?= e($station['code']) ?>">
                        <header>
                            <div><h3><?= e($station['name']) ?></h3><p><?= e($station['region_label']) ?></p></div>
                            <span class="weather-level level-<?= e($level) ?>" data-field="level"><?= e($levels[$level][0]) ?></span>
                        </header>
                        <div class="weather-rain"><strong data-field="rain"><?= $r === null ? '—' : e($fmt($rain)) ?></strong><span>mm/jam</span></div>
                        <dl class="weather-metrics">
                            <div><dt>Suhu</dt><dd><span data-field="temp"><?= $r === null ? '—' : e($fmt((float) $r['temperature_c'])) ?></span> °C</dd></div>
                            <div><dt>Kelembapan</dt><dd><span data-field="hum"><?= $r === null ? '—' : (int) $r['humidity_pct'] ?></span> %</dd></div>
                            <div><dt>Angin</dt><dd><span data-field="wind"><?= $r === null ? '—' : e($fmt((float) $r['wind_kmh'])) ?></span> km/jam <small data-field="dir"><?= $r === null ? '' : e(weather_wind_direction((float) $r['wind_deg'])) ?></small></dd></div>
                            <div><dt>Tekanan</dt><dd><span data-field="pres"><?= $r === null ? '—' : e($fmt((float) $r['pressure_hpa'])) ?></span> hPa</dd></div>
                        </dl>
                        <footer>
                            <span><code><?= e($station['code']) ?></code> · <?= e(number_format((float) $station['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $station['longitude'], 5, '.', '')) ?></span>
                            <span>Diamati <b data-field="time"><?= $r === null ? '—' : e($local((string) $r['observed_at'])) ?></b></span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="scope-hint">Data simulasi hanya untuk tampilan dan tidak disimpan. Atur <code>APP_WEATHER_FEED_URL</code> ke API JSON sensor (skema: <code>stations[].code, rain_mm_h, temperature_c, humidity_pct, wind_kmh, wind_deg, pressure_hpa, observed_at</code>) untuk memakai data asli.</p>
    </section>
    <?php
}
