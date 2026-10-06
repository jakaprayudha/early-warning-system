<?php

declare(strict_types=1);

function render_group_fields(?array $g, array $hazards, array $labels): void
{
    $value = static fn(string $key, string $default = ''): string => $g === null ? $default : (string) ($g[$key] ?? $default);
    ?>
    <label>Nama kelompok<input name="name" maxlength="100" value="<?= e($value('name')) ?>" placeholder="contoh: Operator BPBD" <?= $g === null ? 'required' : 'disabled' ?>></label>
    <label>Wilayah<select name="region_id" required><option value="">Pilih wilayah</option><?php render_region_options($labels, $g === null ? null : (int) $g['region_id']); ?></select></label>
    <label>Jenis bahaya (opsional)<?php render_select('hazard_code', ['' => 'Semua bahaya'] + $hazards, strtolower($value('hazard_code')), false); ?></label>
    <label>Jam aktif mulai<input name="active_from" type="time" value="<?= e($value('active_from')) ?>"></label>
    <label>Jam aktif selesai<input name="active_until" type="time" value="<?= e($value('active_until')) ?>"></label>
    <fieldset class="rule-fieldset hazard-description"><legend>Kanal yang diizinkan</legend><?php render_channel_checks($g === null ? ['dashboard', 'email'] : rule_channel_list((string) $g['channels'])); ?></fieldset>
    <label class="hazard-description">Deskripsi<textarea name="description" rows="2" maxlength="500"><?= e($value('description')) ?></textarea></label>
    <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $g === null || (int) $g['is_active'] === 1 ? 'checked' : '' ?>> Kelompok aktif</label>
    <?php
}

function render_member_fields(?array $m): void
{
    $value = static fn(string $key): string => $m === null ? '' : (string) ($m[$key] ?? '');
    ?>
    <label>Nama<input name="member_name" maxlength="100" value="<?= e($value('name')) ?>" required></label>
    <label>Jabatan<input name="position" maxlength="100" value="<?= e($value('position')) ?>" placeholder="contoh: Kepala Seksi"></label>
    <label>Email<input name="email" type="email" maxlength="160" value="<?= e($value('email')) ?>" autocomplete="off"></label>
    <label>Telepon / WhatsApp<input name="phone" maxlength="20" value="<?= e($value('phone')) ?>" placeholder="+62812xxxxxxx" autocomplete="off"></label>
    <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $m === null || (int) $m['is_active'] === 1 ? 'checked' : '' ?>> Anggota aktif</label>
    <?php
}

function render_recipients_page(array $user, ?string $message, ?string $error): void
{
    $allHazards = array_change_key_case(alert_hazards(), CASE_LOWER);
    $labels = region_path_labels(user_regions($user));
    $filter = is_string($_GET['hazard'] ?? null) ? $_GET['hazard'] : '';
    $groups = list_recipient_groups($user, ['hazard' => $filter]);
    $all = list_recipient_groups($user);
    $memberTotal = array_sum(array_map(static fn(array $g): int => count($g['members']), $all));
    $activeGroups = count(array_filter($all, static fn(array $g): bool => (int) $g['is_active'] === 1));
    $empty = count(array_filter($all, static fn(array $g): bool => array_filter($g['members'], static fn(array $m): bool => (int) $m['is_active'] === 1) === []));
    $action = '/?page=dashboard&amp;section=recipients';
    $csrf = e(csrf_token());
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Kelompok penerima menentukan siapa dihubungi untuk wilayah/bahaya tertentu, lewat kanal dan jam aktif apa. Nama kelompok dipakai pada aturan &amp; eskalasi. Kontak pribadi disamarkan di daftar dan tidak masuk audit.</p>
        <?php render_notices($message, $error); ?>
        <div class="sensor-summary threshold-summary">
            <div class="sensor-summary-item approval-tile"><strong><?= count($all) ?></strong><span>Kelompok</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-approved"><strong><?= $activeGroups ?></strong><span>Aktif</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-pending"><strong><?= $memberTotal ?></strong><span>Anggota</span></div>
            <div class="sensor-summary-item approval-tile approval-tile-rejected"><strong><?= $empty ?></strong><span>Tanpa anggota aktif</span></div>
        </div>
        <section class="panel hazard-create-panel">
            <details class="create-details">
                <summary><span>+ Tambah kelompok penerima</span></summary>
                <form class="hazard-form" method="post" action="<?= $action ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_group">
                    <?php render_group_fields(null, $allHazards, $labels); ?>
                    <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                    <button class="save-button" type="submit">Simpan kelompok</button>
                </form>
            </details>
        </section>
        <section class="hazard-types-section">
            <div class="section-heading"><div><p class="eyebrow">DAFTAR</p><h2>Kelompok <span><?= count($groups) ?></span></h2></div></div>
            <form class="location-filter" method="get" action="/">
                <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="recipients">
                <select name="hazard" aria-label="Bahaya"><option value="">Semua bahaya</option><?php foreach ($allHazards as $code => $name): ?><option value="<?= e((string) $code) ?>" <?= strcasecmp($filter, (string) $code) === 0 ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
                <button class="save-button" type="submit">Terapkan</button>
            </form>
            <?php if ($groups === []): ?><p class="scope-hint">Belum ada kelompok penerima.</p><?php endif; ?>
            <div class="parameter-grid">
            <?php foreach ($groups as $g): $on = (int) $g['is_active'] === 1; $channels = rule_channel_list((string) $g['channels']); ?>
                <article class="parameter-card<?= $on ? '' : ' is-off' ?>">
                    <header class="parameter-head">
                        <span class="parameter-icon">👥</span>
                        <div>
                            <span class="threshold-hazard"><?= e($g['hazard_name'] ?? 'Semua bahaya') ?> · <?= e($g['region_name']) ?></span>
                            <h3><?= e($g['name']) ?></h3>
                        </div>
                        <span class="status-pill <?= $on ? 'approval-approved' : 'approval-draft' ?>"><?= $on ? 'Aktif' : 'Nonaktif' ?></span>
                    </header>
                    <div class="parameter-chips">
                        <?php foreach ($channels as $c): ?><span class="chip-unit"><?= e(rule_channels()[$c]) ?></span><?php endforeach; ?>
                        <span><?= $g['active_from'] !== null ? e($g['active_from']) . '–' . e($g['active_until']) : '24 jam' ?></span>
                        <span>dipakai <?= (int) $g['usage'] ?> aturan</span>
                    </div>
                    <?php if ($g['description'] !== ''): ?><p class="parameter-desc"><?= e($g['description']) ?></p><?php endif; ?>
                    <ul class="member-list">
                        <?php if ($g['members'] === []): ?><li class="scope-hint">Belum ada anggota.</li><?php endif; ?>
                        <?php foreach ($g['members'] as $m): $mOn = (int) $m['is_active'] === 1; ?>
                            <li class="<?= $mOn ? '' : 'is-off' ?>">
                                <span class="member-avatar"><?= e(mb_strtoupper(mb_substr((string) $m['name'], 0, 1))) ?></span>
                                <div class="member-info">
                                    <strong><?= e($m['name']) ?></strong><small><?= e($m['position'] !== '' ? $m['position'] : 'tanpa jabatan') ?><?= $mOn ? '' : ' · nonaktif' ?></small>
                                    <small>✉ <?= e(mask_email((string) $m['email'])) ?> · ☎ <?= e(mask_phone((string) $m['phone'])) ?>
                                    <?php foreach (['email' => 'email', 'sms' => 'phone', 'whatsapp' => 'phone'] as $ch => $field): if (in_array($ch, $channels, true) && $m[$field] === ''): ?><span class="warn-chip">tanpa <?= e($field === 'email' ? 'email' : 'telepon') ?> untuk <?= e(rule_channels()[$ch]) ?></span><?php endif; endforeach; ?></small>
                                </div>
                                <details class="member-edit">
                                    <summary>Ubah</summary>
                                    <form id="member-<?= (int) $m['id'] ?>" class="hazard-form" method="post" action="<?= $action ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="update_member">
                                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                        <?php render_member_fields($m); ?>
                                        <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                                    </form>
                                    <div class="hazard-form-actions">
                                        <button class="save-button" type="submit" form="member-<?= (int) $m['id'] ?>">Simpan</button>
                                        <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_member">
                                            <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                            <input name="reason" maxlength="500" required placeholder="Alasan hapus" aria-label="Alasan hapus">
                                            <button class="danger-button" type="submit">Hapus</button>
                                        </form>
                                    </div>
                                </details>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <details class="edit-details">
                        <summary>+ Tambah anggota</summary>
                        <form class="hazard-form" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="add_member">
                            <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                            <?php render_member_fields(null); ?>
                            <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                            <button class="save-button" type="submit">Tambah anggota</button>
                        </form>
                    </details>
                    <details class="edit-details">
                        <summary>Ubah kelompok</summary>
                        <form id="group-<?= (int) $g['id'] ?>" class="hazard-form" method="post" action="<?= $action ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_group">
                            <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                            <?php render_group_fields($g, $allHazards, $labels); ?>
                            <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required></label>
                        </form>
                        <div class="hazard-form-actions">
                            <button class="save-button" type="submit" form="group-<?= (int) $g['id'] ?>">Simpan perubahan</button>
                            <form class="hazard-delete-form" method="post" action="<?= $action ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete_group">
                                <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
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
