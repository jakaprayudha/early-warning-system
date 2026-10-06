<?php

declare(strict_types=1);

function render_region_options(array $labels, ?int $selected, ?int $excludeId = null, array $blocked = []): void
{
    asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($labels as $id => $label) {
        if ($id === $excludeId || in_array($id, $blocked, true)) {
            continue;
        }
        echo '<option value="' . (int) $id . '"' . ($selected === $id ? ' selected' : '') . '>' . e($label) . '</option>';
    }
}

function render_location_fields(?array $location, array $labels, array $hazards): void
{
    $value = static fn(string $key, string $default = ''): string => $location === null
        ? $default
        : (string) ($location[$key] ?? $default);
    $selectedHazards = $location['hazards'] ?? [];
    ?>
    <label>Kode lokasi<input name="code" maxlength="32" pattern="[A-Za-z0-9_\x2D]{2,32}" value="<?= e($value('code')) ?>" placeholder="contoh: POS-CITARUM-01" <?= $location === null ? 'required' : 'disabled' ?>></label>
    <label>Nama lokasi<input name="name" maxlength="120" value="<?= e($value('name')) ?>" required></label>
    <label>Wilayah
        <select name="region_id" required>
            <option value="">Pilih wilayah</option>
            <?php render_region_options($labels, $location === null ? null : (int) $location['region_id']); ?>
        </select>
    </label>
    <label>Tipe lokasi
        <select name="location_type" required>
            <?php foreach (location_types() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $value('location_type', 'station') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Latitude<input name="latitude" type="number" step="any" min="-90" max="90" value="<?= e($value('latitude')) ?>" placeholder="-6.2383" required></label>
    <label>Longitude<input name="longitude" type="number" step="any" min="-180" max="180" value="<?= e($value('longitude')) ?>" placeholder="106.9756" required></label>
    <label>Elevasi (m, opsional)<input name="elevation_m" type="number" step="any" value="<?= e($value('elevation_m')) ?>"></label>
    <label>Datum vertikal<input name="vertical_datum" maxlength="40" value="<?= e($value('vertical_datum')) ?>" placeholder="contoh: MSL, LWS"></label>
    <label>Pengelola<input name="managed_by" maxlength="120" value="<?= e($value('managed_by')) ?>" placeholder="Instansi/unit pengelola"></label>
    <fieldset class="location-hazards">
        <legend>Jenis bahaya yang dipantau</legend>
        <?php foreach ($hazards as $code => $name): ?>
            <label class="check-chip"><input type="checkbox" name="hazards[]" value="<?= e((string) $code) ?>" <?= in_array($code, $selectedHazards, true) ? 'checked' : '' ?>> <?= e($name) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="hazard-description">Geometri GeoJSON (opsional)<textarea name="geometry_geojson" rows="2" maxlength="20000" placeholder='{"type":"Polygon","coordinates":[...]}'><?= e($value('geometry_geojson')) ?></textarea></label>
    <label class="hazard-description">Catatan<textarea name="notes" rows="2" maxlength="500"><?= e($value('notes')) ?></textarea></label>
    <label class="hazard-active"><input type="checkbox" name="is_active" value="1" <?= $location === null || (int) $location['is_active'] === 1 ? 'checked' : '' ?>> Aktif dipantau</label>
    <?php
}

function render_locations_page(array $user, ?string $message, ?string $error): void
{
    $requestedTab = $_GET['tab'] ?? 'locations';
    $tab = in_array($requestedTab, ['regions', 'maps'], true) ? $requestedTab : 'locations';
    $regions = list_managed_regions($user);
    $labels = region_path_labels($regions);
    $hazards = alert_hazards();
    $action = '/?page=dashboard&amp;section=locations';
    $csrf = e(csrf_token());
    $levels = region_admin_levels();
    $zones = region_timezones();
    ?>
    <div class="hazard-admin location-admin">
        <p class="dashboard-message">Kelola hierarki wilayah dan lokasi pantau beserta koordinat, elevasi, serta jenis bahaya yang dipantau. Hanya wilayah dalam cakupan akses Anda yang tampil. Setiap perubahan dicatat pada audit.</p>
        <?php if ($message !== null): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="admin-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <nav class="tab-bar" aria-label="Master wilayah">
            <a href="/?page=dashboard&amp;section=locations&amp;tab=locations" <?= $tab === 'locations' ? 'class="active" aria-current="page"' : '' ?>>Lokasi pantau</a>
            <a href="/?page=dashboard&amp;section=locations&amp;tab=regions" <?= $tab === 'regions' ? 'class="active" aria-current="page"' : '' ?>>Hierarki wilayah</a>
            <a href="/?page=dashboard&amp;section=locations&amp;tab=maps" <?= $tab === 'maps' ? 'class="active" aria-current="page"' : '' ?>>Peta</a>
        </nav>

    <?php if ($tab === 'regions'): ?>
        <section class="panel hazard-create-panel">
            <div class="panel-heading"><div><h2>Tambah wilayah</h2><p>Kode bersifat tetap setelah wilayah dibuat.</p></div></div>
            <form class="hazard-form" method="post" action="<?= $action ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_region">
                <input type="hidden" name="tab" value="regions">
                <label>Kode wilayah<input name="code" maxlength="32" pattern="[A-Za-z0-9_\x2D]{2,32}" placeholder="contoh: KAB-BEKASI" required></label>
                <label>Nama wilayah<input name="name" maxlength="120" required></label>
                <label>Wilayah induk
                    <select name="parent_id">
                        <?php if ($user['role'] === 'system_admin'): ?><option value="">— Tingkat teratas —</option><?php endif; ?>
                        <?php render_region_options($labels, null); ?>
                    </select>
                </label>
                <label>Tingkat administrasi
                    <select name="admin_level"><?php foreach ($levels as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                </label>
                <label>Zona waktu
                    <select name="timezone"><?php foreach ($zones as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                </label>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penambahan wilayah"></label>
                <button class="save-button" type="submit">Tambah wilayah</button>
            </form>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">HIERARKI</p><h2>Wilayah <span><?= count($regions) ?></span></h2></div></div>
            <?php if ($regions === []): ?>
                <div class="panel empty-alerts"><h2>Belum ada wilayah</h2><p>Tambahkan wilayah pertama atau minta cakupan akses.</p></div>
            <?php else: ?>
            <div class="hazard-type-list">
                <?php foreach ($regions as $region): ?>
                    <?php
                    $id = (int) $region['id'];
                    $blocked = region_descendant_ids($id);
                    $used = (int) $region['child_count'] + (int) $region['location_count'] + (int) $region['event_count'] + (int) $region['user_count'];
                    ?>
                    <article class="panel hazard-type-card">
                        <div class="hazard-type-heading">
                            <span class="hazard-icon" aria-hidden="true">⌖</span>
                            <div><h3><?= e($region['name']) ?></h3><p><code><?= e($region['code']) ?></code> · <?= e($levels[$region['admin_level']] ?? 'Lainnya') ?> · <?= e($labels[$id] ?? '') ?></p></div>
                            <span class="status-badge status-active"><?= (int) $region['location_count'] ?> lokasi</span>
                        </div>
                        <details class="edit-details">
                            <summary>Ubah wilayah</summary>
                            <form id="region-update-<?= $id ?>" class="hazard-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="update_region">
                                <input type="hidden" name="tab" value="regions">
                                <input type="hidden" name="region_id" value="<?= $id ?>">
                                <label>Kode wilayah<input value="<?= e($region['code']) ?>" disabled></label>
                                <label>Nama wilayah<input name="name" maxlength="120" value="<?= e($region['name']) ?>" required></label>
                                <label>Wilayah induk
                                    <select name="parent_id">
                                        <?php if ($user['role'] === 'system_admin'): ?><option value="">— Tingkat teratas —</option><?php endif; ?>
                                        <?php render_region_options($labels, $region['parent_id'] === null ? null : (int) $region['parent_id'], $id, $blocked); ?>
                                    </select>
                                </label>
                                <label>Tingkat administrasi
                                    <select name="admin_level"><?php foreach ($levels as $key => $label): ?><option value="<?= e($key) ?>" <?= $region['admin_level'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                                </label>
                                <label>Zona waktu
                                    <select name="timezone"><?php foreach ($zones as $key => $label): ?><option value="<?= e($key) ?>" <?= $region['timezone'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                                </label>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                            </form>
                            <div class="hazard-form-actions">
                                <button class="save-button" type="submit" form="region-update-<?= $id ?>">Simpan perubahan</button>
                                <?php if ($used === 0): ?>
                                    <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete_region">
                                        <input type="hidden" name="tab" value="regions">
                                        <input type="hidden" name="region_id" value="<?= $id ?>">
                                        <label>Alasan penghapusan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                                        <button class="hazard-delete-button" type="submit">Hapus wilayah</button>
                                    </form>
                                <?php else: ?>
                                    <p class="hazard-delete-hint">Tidak dapat dihapus: <?= (int) $region['child_count'] ?> turunan, <?= (int) $region['location_count'] ?> lokasi, <?= (int) $region['event_count'] ?> kejadian, <?= (int) $region['user_count'] ?> akses pengguna.</p>
                                <?php endif; ?>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'maps'): ?>
        <?php render_locations_map($user, $labels, $hazards); ?>
    <?php else: ?>
        <?php
        $filters = [
            'q' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
            'region_id' => is_string($_GET['region_id'] ?? null) ? (int) $_GET['region_id'] : 0,
            'hazard' => is_string($_GET['hazard'] ?? null) ? $_GET['hazard'] : '',
            'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
        ];
        $locations = list_monitoring_locations($user, $filters);
        ?>
        <section class="panel hazard-create-panel">
            <div class="panel-heading"><div><h2>Tambah lokasi pantau</h2><p>Kode bersifat tetap setelah lokasi dibuat.</p></div></div>
            <?php if ($regions === []): ?>
                <p class="scope-hint">Belum ada wilayah dalam cakupan Anda. Buat wilayah terlebih dahulu pada tab Hierarki wilayah.</p>
            <?php else: ?>
            <form class="hazard-form" method="post" action="<?= $action ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_location">
                <input type="hidden" name="tab" value="locations">
                <?php render_location_fields(null, $labels, $hazards); ?>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penambahan lokasi"></label>
                <button class="save-button" type="submit">Tambah lokasi</button>
            </form>
            <?php endif; ?>
        </section>

        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">KATALOG</p><h2>Lokasi pantau <span><?= count($locations) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard">
                <input type="hidden" name="section" value="locations">
                <input type="hidden" name="tab" value="locations">
                <input name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama/kode" aria-label="Cari lokasi">
                <select name="region_id" aria-label="Wilayah"><option value="">Semua wilayah</option><?php render_region_options($labels, $filters['region_id'] ?: null); ?></select>
                <select name="hazard" aria-label="Jenis bahaya"><option value="">Semua bahaya</option><?php foreach ($hazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= $filters['hazard'] === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
                <select name="status" aria-label="Status"><option value="">Semua status</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($locations === []): ?>
                <div class="panel empty-alerts"><h2>Tidak ada lokasi</h2><p>Tambahkan lokasi pantau atau ubah filter pencarian.</p></div>
            <?php else: ?>
            <div class="hazard-type-list">
                <?php foreach ($locations as $location): ?>
                    <?php $id = (int) $location['id']; ?>
                    <article class="panel hazard-type-card">
                        <div class="hazard-type-heading">
                            <span class="hazard-icon" aria-hidden="true">⌖</span>
                            <div>
                                <h3><?= e($location['name']) ?></h3>
                                <p><code><?= e($location['code']) ?></code> · <?= e($labels[(int) $location['region_id']] ?? $location['region_name']) ?> · <?= e(location_types()[$location['location_type']] ?? 'Lainnya') ?></p>
                                <p class="location-coords"><?= e(number_format((float) $location['latitude'], 5, '.', '')) ?>, <?= e(number_format((float) $location['longitude'], 5, '.', '')) ?><?= $location['elevation_m'] === null ? '' : ' · elevasi ' . e((string) $location['elevation_m']) . ' m' ?><?= $location['vertical_datum'] !== '' ? ' (' . e($location['vertical_datum']) . ')' : '' ?></p>
                                <p class="location-hazard-tags"><?php foreach ($location['hazards'] as $code): ?><span><?= e($hazards[$code] ?? (string) $code) ?></span><?php endforeach; ?></p>
                            </div>
                            <span class="status-badge <?= (int) $location['is_active'] === 1 ? 'status-active' : 'status-suspended' ?>"><?= (int) $location['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                        </div>
                        <details class="edit-details">
                            <summary>Ubah lokasi</summary>
                            <form id="location-update-<?= $id ?>" class="hazard-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="update_location">
                                <input type="hidden" name="tab" value="locations">
                                <input type="hidden" name="location_id" value="<?= $id ?>">
                                <?php render_location_fields($location, $labels, $hazards); ?>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                            </form>
                            <div class="hazard-form-actions">
                                <button class="save-button" type="submit" form="location-update-<?= $id ?>">Simpan perubahan</button>
                                <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_location">
                                    <input type="hidden" name="tab" value="locations">
                                    <input type="hidden" name="location_id" value="<?= $id ?>">
                                    <label>Alasan penghapusan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                                    <button class="hazard-delete-button" type="submit">Hapus lokasi</button>
                                </form>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    </div>
    <?php
}

function location_map_styles(): array
{
    return [
        'station' => ['label' => 'Stasiun pengamatan', 'color' => '#2F7FC1'],
        'river_post' => ['label' => 'Pos sungai', 'color' => '#D18A32'],
        'coastal_post' => ['label' => 'Pos pantai/muara', 'color' => '#1F9A9C'],
        'weather_station' => ['label' => 'Stasiun cuaca', 'color' => '#8C63B8'],
        'village' => ['label' => 'Permukiman/desa', 'color' => '#4C9A5B'],
        'other' => ['label' => 'Lainnya', 'color' => '#6B7C85'],
    ];
}

function render_locations_map(array $user, array $labels, array $hazards): void
{
    $filters = [
        'region_id' => is_string($_GET['region_id'] ?? null) ? (int) $_GET['region_id'] : 0,
        'hazard' => is_string($_GET['hazard'] ?? null) ? $_GET['hazard'] : '',
        'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
        'sensor' => is_string($_GET['sensor'] ?? null) ? $_GET['sensor'] : '',
    ];
    $healthLabels = sensor_health_labels();
    $sensorsByLocation = [];
    foreach (list_sensors($user) as $sensor) {
        $sensorsByLocation[(int) $sensor['location_id']][] = [
            'name' => $sensor['name'],
            'parameter' => $sensor['parameter'],
            'health' => $sensor['health'],
            'label' => $healthLabels[$sensor['health']] ?? $sensor['health'],
            'last' => sensor_age_label($sensor['last_data_at'] === null ? null : (int) $sensor['last_data_at']),
        ];
    }
    $rank = ['delayed' => 4, 'unknown' => 3, 'maintenance' => 2, 'healthy' => 1, 'inactive' => 0];
    $locations = list_monitoring_locations($user, $filters);
    $points = [];
    foreach ($locations as $location) {
        $locationSensors = $sensorsByLocation[(int) $location['id']] ?? [];
        $worst = 'none';
        $best = -1;
        foreach ($locationSensors as $item) {
            if (($rank[$item['health']] ?? 0) > $best) {
                $best = $rank[$item['health']] ?? 0;
                $worst = $item['health'];
            }
        }
        if ($filters['sensor'] !== '' && $filters['sensor'] !== $worst) {
            continue;
        }
        $points[] = [
            'sensorHealth' => $worst,
            'sensors' => $locationSensors,
            'id' => (int) $location['id'],
            'code' => $location['code'],
            'name' => $location['name'],
            'lat' => (float) $location['latitude'],
            'lng' => (float) $location['longitude'],
            'type' => isset(location_map_styles()[$location['location_type']]) ? $location['location_type'] : 'other',
            'active' => (int) $location['is_active'] === 1,
            'region' => $labels[(int) $location['region_id']] ?? $location['region_name'],
            'elevation' => $location['elevation_m'],
            'datum' => $location['vertical_datum'],
            'hazards' => array_map(static fn($code) => $hazards[$code] ?? (string) $code, $location['hazards']),
        ];
    }
    $styles = location_map_styles();
    ?>
    <section class="hazard-types-section">
        <div class="section-heading"><div><p class="eyebrow">PETA</p><h2>Sebaran lokasi <span><?= count($points) ?></span></h2></div></div>
        <form class="location-filter map-filter" method="get" action="/">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="section" value="locations">
            <input type="hidden" name="tab" value="maps">
            <select name="region_id" aria-label="Wilayah"><option value="">Semua wilayah</option><?php render_region_options($labels, $filters['region_id'] ?: null); ?></select>
            <select name="hazard" aria-label="Jenis bahaya"><option value="">Semua bahaya</option><?php foreach ($hazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= $filters['hazard'] === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
            <select name="status" aria-label="Status"><option value="">Semua status</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select>
            <select name="sensor" aria-label="Status sensor"><option value="">Semua status sensor</option><?php foreach ($healthLabels + ['none' => 'Tanpa sensor'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['sensor'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <button class="save-button" type="submit">Terapkan</button>
        </form>
        <div class="location-map-layout">
            <div class="panel location-map-panel">
                <div class="location-map" data-location-map data-points="<?= e(json_encode($points, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>" data-styles="<?= e(json_encode($styles, JSON_THROW_ON_ERROR)) ?>" role="application" aria-label="Peta lokasi pantau" tabindex="0">
                    <div class="map-tiles" data-map-tiles></div>
                    <div class="map-markers" data-map-markers></div>
                    <div class="map-controls">
                        <button type="button" data-map-zoom="1" aria-label="Perbesar">+</button>
                        <button type="button" data-map-zoom="-1" aria-label="Perkecil">−</button>
                        <button type="button" data-map-fit aria-label="Tampilkan semua lokasi">⤢</button>
                    </div>
                    <div class="map-attribution">© OpenStreetMap contributors</div>
                    <div class="map-coords" data-map-coords>Arahkan kursor ke peta</div>
                </div>
                <div class="map-legend" aria-label="Legenda ikon">
                    <?php foreach ($styles as $key => $style): ?>
                        <span><i class="map-pin-sample" data-map-legend="<?= e($key) ?>"></i><?= e($style['label']) ?></span>
                    <?php endforeach; ?>
                    <span><i class="map-pin-sample inactive"></i>Nonaktif</span>
                </div>
                <div class="map-legend map-health-legend" aria-label="Legenda status sensor">
                    <strong>Status sensor:</strong>
                    <?php foreach ($healthLabels + ['none' => 'Tanpa sensor'] as $key => $label): ?>
                        <span><i class="health-dot health-<?= e($key) ?>"></i><?= e($label) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="panel location-map-list" aria-label="Daftar lokasi">
                <?php if ($points === []): ?>
                    <p class="scope-hint">Tidak ada lokasi untuk ditampilkan.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($points as $point): ?>
                            <li><button type="button" data-map-focus="<?= (int) $point['id'] ?>">
                                <span class="map-list-icon" data-map-legend="<?= e($point['type']) ?>"></span>
                                <span><strong><?= e($point['name']) ?></strong><small><?= e(number_format($point['lat'], 5, '.', '')) ?>, <?= e(number_format($point['lng'], 5, '.', '')) ?></small></span>
                                <i class="health-dot health-<?= e($point['sensorHealth']) ?>" title="<?= e($healthLabels[$point['sensorHealth']] ?? 'Tanpa sensor') ?>"></i>
                            </button></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </aside>
        </div>
    </section>
    <?php
}
