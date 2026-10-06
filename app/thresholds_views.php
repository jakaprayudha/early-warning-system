<?php

declare(strict_types=1);

function render_notices(?string $message, ?string $error): void
{
    if ($message !== null) {
        echo '<div class="notice" role="status">' . e($message) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="admin-error" role="alert">' . e($error) . '</div>';
    }
}

function render_select(string $name, array $options, string $selected, bool $required = true): void
{
    echo '<select name="' . e($name) . '"' . ($required ? ' required' : '') . '>';
    foreach ($options as $key => $label) {
        echo '<option value="' . e((string) $key) . '"' . ($selected === (string) $key ? ' selected' : '') . '>' . e((string) $label) . '</option>';
    }
    echo '</select>';
}

function render_parameter_fields(?array $parameter, array $hazards): void
{
    $value = static fn(string $key, string $default = ''): string => $parameter === null
        ? $default
        : (string) ($parameter[$key] ?? $default);
    ?>
    <label>Kode<input name="code" maxlength="40" pattern="[A-Za-z][A-Za-z0-9_\x2D]{1,39}" value="<?= e($value('code')) ?>" placeholder="contoh: rain_1h" <?= $parameter === null ? 'required' : 'disabled' ?>></label>
    <label>Nama parameter<input name="name" maxlength="100" value="<?= e($value('name')) ?>" required></label>
    <label>Jenis bahaya<?php render_select('hazard_code', $hazards, strtolower($value('hazard_code'))); ?></label>
    <label>Satuan<input name="unit" maxlength="24" value="<?= e($value('unit')) ?>" placeholder="mm"></label>
    <label>Agregasi<?php render_select('aggregation', parameter_aggregations(), $value('aggregation', 'instant')); ?></label>
    <label>Jendela agregasi (menit)<input name="aggregation_minutes" type="number" min="0" max="10080" value="<?= e($value('aggregation_minutes', '0')) ?>"></label>
    <label class="hazard-description">Deskripsi<textarea name="description" rows="2" maxlength="500"><?= e($value('description')) ?></textarea></label>
    <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $parameter === null || (int) $parameter['is_active'] === 1 ? 'checked' : '' ?>> Aktif</label>
    <?php
}

function render_parameters_page(array $user, ?string $message, ?string $error): void
{
    $hazards = array_change_key_case(alert_hazards(true), CASE_LOWER);
    $allHazards = array_change_key_case(alert_hazards(), CASE_LOWER);
    $filter = is_string($_GET['hazard'] ?? null) ? strtolower($_GET['hazard']) : '';
    $parameters = array_values(array_filter(
        list_parameters(),
        static fn(array $p): bool => $filter === '' || strtolower((string) $p['hazard_code']) === $filter
    ));
    $action = '/?page=dashboard&amp;section=parameters';
    $csrf = e(csrf_token());
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Parameter adalah variabel terukur (curah hujan, tinggi muka air, dsb.) beserta satuan dan cara agregasinya. Ambang dan aturan merujuk ke parameter ini.</p>
        <?php render_notices($message, $error); ?>
        <?php
        $activeCount = count(array_filter($parameters, static fn(array $p): bool => (int) $p['is_active'] === 1));
        $usedCount = count(array_filter($parameters, static fn(array $p): bool => (int) $p['threshold_count'] > 0));
        ?>
        <div class="sensor-summary threshold-summary">
            <div class="sensor-summary-item approval-tile"><strong><?= count($parameters) ?></strong><span>Parameter</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-approved"><strong><?= $activeCount ?></strong><span>Aktif</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-pending"><strong><?= $usedCount ?></strong><span>Punya ambang</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-rejected"><strong><?= count($parameters) - $activeCount ?></strong><span>Nonaktif</span></div>
        </div>
        <section class="panel hazard-create-panel">
            <details class="create-details">
            <summary><span>+ Tambah parameter</span></summary>
            <form class="hazard-form" method="post" action="<?= $action ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_parameter">
                <?php render_parameter_fields(null, $hazards); ?>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penambahan parameter"></label>
                <button class="save-button" type="submit">Tambah parameter</button>
            </form>
            </details>
        </section>
        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">KATALOG</p><h2>Parameter <span><?= count($parameters) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="parameters">
                <select name="hazard" aria-label="Jenis bahaya"><option value="">Semua bahaya</option><?php foreach ($allHazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= $filter === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($parameters === []): ?><p class="scope-hint">Belum ada parameter.</p><?php endif; ?>
            <div class="parameter-grid">
            <?php foreach ($parameters as $parameter): $on = (int) $parameter['is_active'] === 1; ?>
                <article class="parameter-card<?= $on ? '' : ' is-off' ?>">
                    <header class="parameter-head">
                        <span class="parameter-icon"><?= e(mb_strtoupper(mb_substr((string) $parameter['name'], 0, 1))) ?></span>
                        <div>
                            <span class="threshold-hazard"><?= e($parameter['hazard_name']) ?></span>
                            <h3><?= e($parameter['name']) ?></h3>
                            <code><?= e($parameter['code']) ?></code>
                        </div>
                        <span class="status-pill <?= $on ? 'approval-approved' : 'approval-draft' ?>"><?= $on ? 'Aktif' : 'Nonaktif' ?></span>
                    </header>
                    <div class="parameter-chips">
                        <span class="chip-unit"><?= e($parameter['unit'] !== '' ? $parameter['unit'] : 'tanpa satuan') ?></span>
                        <span><?= e(parameter_aggregations()[$parameter['aggregation']]) ?><?= (int) $parameter['aggregation_minutes'] > 0 ? ' · ' . (int) $parameter['aggregation_minutes'] . ' mnt' : '' ?></span>
                        <span><?= (int) $parameter['threshold_count'] ?> ambang</span>
                    </div>
                    <?php if ($parameter['description'] !== ''): ?><p class="parameter-desc"><?= e($parameter['description']) ?></p><?php endif; ?>
                    <details class="edit-details">
                        <summary>Ubah parameter</summary>
                        <form id="parameter-<?= (int) $parameter['id'] ?>" class="hazard-form" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_parameter">
                            <input type="hidden" name="parameter_id" value="<?= (int) $parameter['id'] ?>">
                            <?php render_parameter_fields($parameter, $allHazards); ?>
                            <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                        </form>
                        <div class="hazard-form-actions">
                            <button class="save-button" type="submit" form="parameter-<?= (int) $parameter['id'] ?>">Simpan perubahan</button>
                            <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete_parameter">
                                <input type="hidden" name="parameter_id" value="<?= (int) $parameter['id'] ?>">
                                <input name="reason" maxlength="500" required placeholder="Alasan hapus" aria-label="Alasan hapus">
                                <button class="danger-button" type="submit">Hapus</button>
                            </form>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
    </div>
    <?php
}

function render_threshold_fields(?array $t, array $parameters, array $labels, array $locations): void
{
    $value = static fn(string $key, string $default = ''): string => $t === null ? $default : (string) ($t[$key] ?? $default);
    $paramOptions = [];
    foreach ($parameters as $p) {
        if ((int) $p['is_active'] === 1 || ($t !== null && (int) $t['parameter_id'] === (int) $p['id'])) {
            $paramOptions[$p['id']] = $p['hazard_name'] . ' — ' . $p['name'] . ($p['unit'] !== '' ? ' (' . $p['unit'] . ')' : '');
        }
    }
    $locOptions = ['' => 'Seluruh wilayah'];
    foreach ($locations as $l) {
        $locOptions[$l['id']] = $l['name'];
    }
    ?>
    <label>Parameter<?php render_select('parameter_id', $paramOptions, $value('parameter_id')); ?></label>
    <label>Wilayah<select name="region_id" required><option value="">Pilih wilayah</option><?php render_region_options($labels, $t === null ? null : (int) $t['region_id']); ?></select></label>
    <label>Lokasi (opsional)<?php render_select('location_id', $locOptions, $value('location_id'), false); ?></label>
    <label>Tingkat<?php render_select('severity', alert_severities(), $value('severity', 'watch')); ?></label>
    <label>Operator<?php render_select('operator', threshold_operators(), $value('operator', '>=')); ?></label>
    <label>Nilai ambang<input name="value" type="number" step="any" value="<?= e($value('value')) ?>" required></label>
    <label>Nilai reset (histeresis)<input name="reset_value" type="number" step="any" value="<?= e($value('reset_value')) ?>" placeholder="opsional"></label>
    <label>Persistensi (menit)<input name="persistence_minutes" type="number" min="0" max="10080" value="<?= e($value('persistence_minutes', '0')) ?>"></label>
    <label>Prioritas (1–100)<input name="priority" type="number" min="1" max="100" value="<?= e($value('priority', '50')) ?>"></label>
    <label>Berlaku mulai<input name="valid_from" type="date" value="<?= e($value('valid_from', date('Y-m-d'))) ?>" required></label>
    <label>Berlaku sampai<input name="valid_until" type="date" value="<?= e($value('valid_until')) ?>"></label>
    <label class="hazard-description">Catatan<textarea name="notes" rows="2" maxlength="500"><?= e($value('notes')) ?></textarea></label>
    <?php
}

function render_threshold_state_form(string $action, int $id, string $label, string $class, string $csrf, string $target): void
{
    ?>
    <form class="threshold-action" method="post" action="<?= $target ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="threshold_id" value="<?= $id ?>">
        <input name="reason" maxlength="500" required placeholder="Alasan / catatan" aria-label="Alasan">
        <button class="<?= e($class) ?>" type="submit"><?= e($label) ?></button>
    </form>
    <?php
}

function render_thresholds_page(array $user, ?string $message, ?string $error): void
{
    $parameters = list_parameters();
    $labels = region_path_labels(user_regions($user));
    $locations = list_monitoring_locations($user);
    $filters = [
        'parameter_id' => is_string($_GET['parameter_id'] ?? null) ? (int) $_GET['parameter_id'] : 0,
        'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    ];
    $thresholds = list_thresholds($user, $filters);
    $statuses = approval_statuses();
    $counts = array_fill_keys(array_keys($statuses), 0);
    foreach (list_thresholds($user) as $item) {
        $counts[$item['approval_status']]++;
    }
    $action = '/?page=dashboard&amp;section=thresholds';
    $csrf = e(csrf_token());
    $severities = alert_severities();
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Ambang berversi dengan masa berlaku, persistensi, dan histeresis. Ambang baru harus diajukan lalu disetujui oleh pengguna <strong>lain</strong> (persetujuan dua pihak). Ambang yang disetujui tidak diubah langsung: buat versi baru.</p>
        <?php render_notices($message, $error); ?>
        <div class="sensor-summary threshold-summary">
            <?php foreach (['draft', 'pending', 'approved', 'rejected'] as $key): ?>
                <a class="sensor-summary-item approval-tile approval-tile-<?= e($key) ?>" href="/?page=dashboard&amp;section=thresholds&amp;status=<?= e($key) ?>"><strong><?= (int) $counts[$key] ?></strong><span><?= e($statuses[$key]) ?></span></a>
            <?php endforeach; ?>
        </div>
        <section class="panel hazard-create-panel">
            <details class="create-details">
            <summary><span>+ Tambah ambang (draf)</span></summary>
            <?php if ($parameters === []): ?>
                <p class="scope-hint">Buat parameter terlebih dahulu.</p>
            <?php else: ?>
            <form class="hazard-form" method="post" action="<?= $action ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_threshold">
                <?php render_threshold_fields(null, $parameters, $labels, $locations); ?>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penetapan ambang"></label>
                <button class="save-button" type="submit">Simpan draf</button>
            </form>
            <?php endif; ?>
            </details>
        </section>
        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">DAFTAR</p><h2>Ambang <span><?= count($thresholds) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="thresholds">
                <select name="parameter_id" aria-label="Parameter"><option value="">Semua parameter</option><?php foreach ($parameters as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filters['parameter_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
                <select name="status" aria-label="Status"><option value="">Semua status</option><?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($thresholds === []): ?><p class="scope-hint">Tidak ada ambang.</p><?php endif; ?>
            <?php foreach ($thresholds as $t): $status = $t['approval_status']; $own = (int) $t['created_by'] === (int) $user['id']; ?>
                <article class="threshold-card severity-border-<?= e($t['severity']) ?>">
                    <header class="threshold-head">
                        <div class="threshold-rule">
                            <span class="threshold-hazard"><?= e($t['hazard_name']) ?></span>
                            <h3><?= e($t['parameter_name']) ?></h3>
                            <p class="threshold-condition"><span class="op"><?= e($t['operator']) ?></span> <strong><?= e((string) $t['value']) ?></strong> <?= e($t['parameter_unit']) ?></p>
                        </div>
                        <div class="threshold-badges">
                            <span class="severity-chip severity-<?= e($t['severity']) ?>"><?= e($severities[$t['severity']]) ?></span>
                            <span class="status-pill approval-<?= e($status) ?>"><?= e($statuses[$status]) ?></span>
                            <span class="version-chip">v<?= (int) $t['version'] ?></span>
                        </div>
                    </header>
                    <dl class="threshold-meta">
                        <div><dt>Wilayah</dt><dd><?= e($t['region_name']) ?><?= $t['location_name'] !== null ? ' · ' . e($t['location_name']) : '' ?></dd></div>
                        <div><dt>Persistensi</dt><dd><?= (int) $t['persistence_minutes'] ?> menit</dd></div>
                        <div><dt>Nilai reset</dt><dd><?= $t['reset_value'] === null ? '—' : e((string) $t['reset_value']) ?></dd></div>
                        <div><dt>Prioritas</dt><dd><?= (int) $t['priority'] ?></dd></div>
                        <div><dt>Berlaku</dt><dd><?= e($t['valid_from']) ?> → <?= e($t['valid_until'] ?? 'tanpa batas') ?></dd></div>
                        <div><dt>Dibuat oleh</dt><dd><?= e($t['creator_name'] ?? '—') ?><?= $t['decider_name'] !== null ? ' · putusan: ' . e($t['decider_name']) : '' ?></dd></div>
                    </dl>
                    <?php if ($t['decision_reason'] !== ''): ?><p class="threshold-note">Catatan keputusan: <?= e($t['decision_reason']) ?></p><?php endif; ?>
                    <div class="threshold-actions">
                        <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                            <?php render_threshold_state_form('submit_threshold', (int) $t['id'], 'Ajukan persetujuan', 'save-button', $csrf, $action); ?>
                            <?php render_threshold_state_form('delete_threshold', (int) $t['id'], 'Hapus', 'danger-button', $csrf, $action); ?>
                        <?php elseif ($status === 'pending'): ?>
                            <?php if ($own): ?>
                                <p class="scope-hint">Menunggu pengguna lain menyetujui (Anda pembuatnya).</p>
                            <?php else: ?>
                                <?php render_threshold_state_form('approve_threshold', (int) $t['id'], 'Setujui', 'save-button', $csrf, $action); ?>
                                <?php render_threshold_state_form('reject_threshold', (int) $t['id'], 'Tolak', 'danger-button', $csrf, $action); ?>
                            <?php endif; ?>
                        <?php elseif ($status === 'approved'): ?>
                            <?php render_threshold_state_form('new_version', (int) $t['id'], 'Buat versi baru', 'save-button', $csrf, $action); ?>
                            <?php render_threshold_state_form('retire_threshold', (int) $t['id'], 'Nonaktifkan', 'danger-button', $csrf, $action); ?>
                        <?php endif; ?>
                    </div>
                    <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                    <details class="edit-details">
                        <summary>Ubah ambang</summary>
                        <form id="threshold-<?= (int) $t['id'] ?>" class="hazard-form" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_threshold">
                            <input type="hidden" name="threshold_id" value="<?= (int) $t['id'] ?>">
                            <?php render_threshold_fields($t, $parameters, $labels, $locations); ?>
                            <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                        </form>
                        <div class="hazard-form-actions"><button class="save-button" type="submit" form="threshold-<?= (int) $t['id'] ?>">Simpan perubahan</button></div>
                    </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
    <?php
}
