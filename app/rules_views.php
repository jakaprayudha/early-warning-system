<?php

declare(strict_types=1);

function render_channel_checks(array $selected, string $name = 'channels[]'): void
{
    echo '<div class="channel-checks">';
    foreach (rule_channels() as $key => $label) {
        echo '<label class="channel-check"><input type="checkbox" name="' . e($name) . '" value="' . e($key) . '"' . (in_array($key, $selected, true) ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    echo '</div>';
}

function render_rule_fields(?array $rule, array $hazards, array $labels, array $approved): void
{
    $value = static fn(string $key, string $default = ''): string => $rule === null ? $default : (string) ($rule[$key] ?? $default);
    $selectedConditions = $rule === null ? [] : array_map(static fn(array $c): int => (int) $c['id'], $rule['conditions']);
    ?>
    <label class="hazard-description">Nama aturan<input name="name" maxlength="120" value="<?= e($value('name')) ?>" placeholder="contoh: Banjir Bekasi - Siaga" required></label>
    <label>Jenis bahaya<?php render_select('hazard_code', $hazards, strtolower($value('hazard_code'))); ?></label>
    <label>Wilayah<select name="region_id" required><option value="">Pilih wilayah</option><?php render_region_options($labels, $rule === null ? null : (int) $rule['region_id']); ?></select></label>
    <label>Tingkat yang dihasilkan<?php render_select('severity', alert_severities(), $value('severity', 'watch')); ?></label>
    <label>Kombinasi indikator<?php render_select('combine_mode', ['all' => 'Semua indikator terpenuhi (DAN)', 'any' => 'Salah satu terpenuhi (ATAU)'], $value('combine_mode', 'all')); ?></label>
    <label>Kelompok penerima awal<input name="recipient_group" maxlength="100" value="<?= e($value('recipient_group')) ?>" placeholder="contoh: Operator BPBD" required></label>
    <label>Jeda pengulangan (menit, 0 = tanpa)<input name="repeat_interval_minutes" type="number" min="0" max="10080" value="<?= e($value('repeat_interval_minutes', '30')) ?>"></label>
    <label>Batas pengakuan (menit)<input name="ack_timeout_minutes" type="number" min="1" max="10080" value="<?= e($value('ack_timeout_minutes', '30')) ?>" required></label>
    <label>Jam aktif mulai<input name="active_from" type="time" value="<?= e($value('active_from')) ?>"></label>
    <label>Jam aktif selesai<input name="active_until" type="time" value="<?= e($value('active_until')) ?>"></label>
    <fieldset class="rule-fieldset hazard-description"><legend>Kanal notifikasi</legend><?php render_channel_checks($rule === null ? ['dashboard'] : rule_channel_list((string) $rule['channels'])); ?></fieldset>
    <fieldset class="rule-fieldset hazard-description">
        <legend>Indikator (ambang disetujui)</legend>
        <?php if ($approved === []): ?><p class="scope-hint">Belum ada ambang yang disetujui.</p><?php endif; ?>
        <?php foreach ($approved as $t): ?>
            <label class="condition-check"><input type="checkbox" name="conditions[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $selectedConditions, true) ? 'checked' : '' ?>>
                <span><strong><?= e($t['hazard_name']) ?> — <?= e($t['parameter_name']) ?> <?= e($t['operator']) ?> <?= e((string) $t['value']) ?> <?= e($t['parameter_unit']) ?></strong> <small><?= e($t['region_name']) ?> · <?= e(alert_severities()[$t['severity']]) ?></small></span></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="hazard-description">Catatan<textarea name="notes" rows="2" maxlength="500"><?= e($value('notes')) ?></textarea></label>
    <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $rule === null || (int) $rule['is_active'] === 1 ? 'checked' : '' ?>> Aturan aktif</label>
    <?php
}

function render_rules_page(array $user, ?string $message, ?string $error): void
{
    $allHazards = array_change_key_case(alert_hazards(), CASE_LOWER);
    $labels = region_path_labels(user_regions($user));
    $approved = list_thresholds($user, ['status' => 'approved']);
    $filters = [
        'hazard' => is_string($_GET['hazard'] ?? null) ? $_GET['hazard'] : '',
        'severity' => is_string($_GET['severity'] ?? null) ? $_GET['severity'] : '',
    ];
    $rules = list_alert_rules($user, $filters);
    $everything = list_alert_rules($user);
    $severities = alert_severities();
    $total = count($everything);
    $active = count(array_filter($everything, static fn(array $r): bool => (int) $r['is_active'] === 1));
    $withEscalation = count(array_filter($everything, static fn(array $r): bool => $r['steps'] !== []));
    $action = '/?page=dashboard&amp;section=rules';
    $csrf = e(csrf_token());
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Aturan menggabungkan indikator (ambang yang sudah disetujui) menjadi tingkat peringatan, lalu menentukan penerima, kanal, jeda pengulangan, dan langkah eskalasi bila peringatan belum diakui.</p>
        <?php render_notices($message, $error); ?>
        <div class="sensor-summary threshold-summary">
            <div class="sensor-summary-item approval-tile"><strong><?= $total ?></strong><span>Aturan</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-approved"><strong><?= $active ?></strong><span>Aktif</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-pending"><strong><?= $withEscalation ?></strong><span>Punya eskalasi</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-rejected"><strong><?= $total - $active ?></strong><span>Nonaktif</span></div>
        </div>
        <section class="panel hazard-create-panel">
            <details class="create-details">
                <summary><span>+ Tambah aturan</span></summary>
                <form class="hazard-form" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_rule">
                    <?php render_rule_fields(null, $allHazards, $labels, $approved); ?>
                    <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar pembuatan aturan"></label>
                    <button class="save-button" type="submit">Simpan aturan</button>
                </form>
            </details>
        </section>
        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">DAFTAR</p><h2>Aturan <span><?= count($rules) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="rules">
                <select name="hazard" aria-label="Bahaya"><option value="">Semua bahaya</option><?php foreach ($allHazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= strcasecmp($filters['hazard'], (string) $code) === 0 ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
                <select name="severity" aria-label="Tingkat"><option value="">Semua tingkat</option><?php foreach ($severities as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['severity'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($rules === []): ?><p class="scope-hint">Belum ada aturan.</p><?php endif; ?>
            <?php foreach ($rules as $rule): $on = (int) $rule['is_active'] === 1; ?>
                <article class="threshold-card rule-card severity-border-<?= e($rule['severity']) ?><?= $on ? '' : ' is-off' ?>">
                    <header class="threshold-head">
                        <div class="threshold-rule">
                            <span class="threshold-hazard"><?= e($rule['hazard_name']) ?> · <?= e($rule['region_name']) ?></span>
                            <h3><?= e($rule['name']) ?></h3>
                        </div>
                        <div class="threshold-badges">
                            <span class="severity-chip severity-<?= e($rule['severity']) ?>"><?= e($severities[$rule['severity']]) ?></span>
                            <span class="status-pill <?= $on ? 'approval-approved' : 'approval-draft' ?>"><?= $on ? 'Aktif' : 'Nonaktif' ?></span>
                        </div>
                    </header>
                    <div class="rule-flow">
                        <div class="rule-step-box">
                            <span class="rule-step-label">JIKA <?= $rule['combine_mode'] === 'all' ? 'SEMUA' : 'SALAH SATU' ?></span>
                            <?php foreach ($rule['conditions'] as $c): ?>
                                <span class="condition-pill"><?= e($c['parameter_name']) ?> <?= e($c['operator']) ?> <?= e((string) $c['value']) ?> <?= e($c['parameter_unit']) ?><?= $c['approval_status'] !== 'approved' ? ' ⚠ ' . e(approval_statuses()[$c['approval_status']]) : '' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <span class="rule-arrow">→</span>
                        <div class="rule-step-box">
                            <span class="rule-step-label">KIRIM KE</span>
                            <strong><?= e($rule['recipient_group']) ?></strong>
                            <span class="rule-channels"><?= e(implode(' · ', array_map(static fn(string $c): string => rule_channels()[$c], rule_channel_list((string) $rule['channels'])))) ?></span>
                        </div>
                    </div>
                    <dl class="threshold-meta">
                        <div><dt>Pengulangan</dt><dd><?= (int) $rule['repeat_interval_minutes'] > 0 ? 'tiap ' . (int) $rule['repeat_interval_minutes'] . ' menit' : 'tanpa pengulangan' ?></dd></div>
                        <div><dt>Batas pengakuan</dt><dd><?= (int) $rule['ack_timeout_minutes'] ?> menit</dd></div>
                        <div><dt>Jam aktif</dt><dd><?= $rule['active_from'] !== null ? e($rule['active_from']) . ' – ' . e($rule['active_until']) : '24 jam' ?></dd></div>
                    </dl>
                    <div class="escalation-timeline">
                        <strong>Eskalasi bila belum diakui</strong>
                        <?php if ($rule['steps'] === []): ?><p class="scope-hint">Belum ada langkah eskalasi.</p><?php endif; ?>
                        <ol>
                            <?php foreach ($rule['steps'] as $i => $step): ?>
                                <li>
                                    <span class="step-time">+<?= (int) $step['after_minutes'] ?> mnt</span>
                                    <span><strong><?= e($step['recipient_group']) ?></strong> <small><?= e(implode(' · ', array_map(static fn(string $c): string => rule_channels()[$c], rule_channel_list((string) $step['channels'])))) ?></small></span>
                                    <form class="threshold-action" method="post" action="<?= $action ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                                        <input name="reason" maxlength="500" required placeholder="Alasan hapus" aria-label="Alasan hapus langkah">
                                        <button class="danger-button" type="submit">Hapus</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <details class="edit-details">
                            <summary>+ Tambah langkah eskalasi</summary>
                            <form class="hazard-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="add_step">
                                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                <label>Setelah (menit sejak peringatan)<input name="after_minutes" type="number" min="1" max="10080" required></label>
                                <label>Penerima<input name="recipient_group" maxlength="100" required placeholder="contoh: Kepala Pelaksana"></label>
                                <fieldset class="rule-fieldset hazard-description"><legend>Kanal</legend><?php render_channel_checks(['dashboard']); ?></fieldset>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                                <button class="save-button" type="submit">Tambah langkah</button>
                            </form>
                        </details>
                    </div>
                    <details class="edit-details">
                        <summary>Ubah aturan</summary>
                        <form id="rule-<?= (int) $rule['id'] ?>" class="hazard-form" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_rule">
                            <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                            <?php render_rule_fields($rule, $allHazards, $labels, $approved); ?>
                            <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                        </form>
                        <div class="hazard-form-actions">
                            <button class="save-button" type="submit" form="rule-<?= (int) $rule['id'] ?>">Simpan perubahan</button>
                            <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                <input name="reason" maxlength="500" required placeholder="Alasan hapus" aria-label="Alasan hapus">
                                <button class="danger-button" type="submit">Hapus</button>
                            </form>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
    <?php
}
