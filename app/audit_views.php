<?php

declare(strict_types=1);

function render_audit_page(array $user): void
{
    $filters = audit_filters($_GET);
    $total = audit_count($filters);
    $pages = max(1, (int) ceil($total / AUDIT_PAGE_SIZE));
    $filters['page'] = min($filters['page'], $pages);
    $rows = audit_rows($filters, AUDIT_PAGE_SIZE, ($filters['page'] - 1) * AUDIT_PAGE_SIZE);
    $actors = audit_actors();
    $actions = audit_actions();
    $base = ['page' => 'dashboard', 'section' => 'audit'] + array_filter([
        'source' => $filters['source'], 'actor' => $filters['actor'] ?: '', 'action' => $filters['action'],
        'q' => $filters['q'], 'from' => $filters['from'], 'to' => $filters['to'],
    ], static fn(mixed $v): bool => $v !== '');
    $link = static fn(array $extra): string => '/?' . e(http_build_query($base + $extra));
    $today = strtotime('today UTC');
    $stats = [
        'Hari ini' => audit_count(['source' => '', 'actor' => 0, 'action' => '', 'q' => '', 'from' => gmdate('Y-m-d', $today), 'to' => '']),
        '7 hari' => audit_count(['source' => '', 'actor' => 0, 'action' => '', 'q' => '', 'from' => gmdate('Y-m-d', $today - 6 * 86400), 'to' => '']),
        'Sesuai filter' => $total,
    ];
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Catatan permanen perubahan konfigurasi, akses pengguna, dan penanganan kejadian. Hanya dapat dibaca: tidak ada fitur ubah atau hapus. Waktu ditampilkan dalam UTC.</p>
        <div class="sensor-summary threshold-summary">
            <?php foreach ($stats as $label => $count): ?>
                <div class="sensor-summary-item approval-tile"><strong><?= number_format($count, 0, ',', '.') ?></strong><span><?= e($label) ?></span></div>
            <?php endforeach; ?>
        </div>

        <form class="audit-filter" method="get" action="/">
            <input type="hidden" name="page" value="dashboard"><input type="hidden" name="section" value="audit">
            <label>Cari<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Alasan, detail, pelaku"></label>
            <label>Sumber<select name="source"><option value="">Semua</option><?php foreach (audit_sources() as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['source'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
            <label>Pelaku<select name="actor"><option value="0">Semua</option><?php foreach ($actors as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $filters['actor'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
            <label>Aksi<select name="action"><option value="">Semua</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= e(audit_action_label($a)) ?></option><?php endforeach; ?></select></label>
            <label>Dari<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
            <label>Sampai<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
            <button class="save-button" type="submit">Terapkan</button>
        </form>

        <div class="audit-toolbar">
            <span><strong><?= number_format($total, 0, ',', '.') ?></strong> catatan · halaman <?= $filters['page'] ?> dari <?= $pages ?></span>
            <a class="export-link" href="<?= $link(['export' => 'csv']) ?>">Unduh CSV <span aria-hidden="true">↓</span></a>
        </div>

        <?php if ($rows === []): ?><p class="scope-hint">Tidak ada catatan yang cocok.</p><?php endif; ?>
        <ol class="audit-timeline">
            <?php foreach ($rows as $r): $tone = audit_action_tone($r['action']); ?>
                <li class="audit-item audit-<?= e($tone) ?>">
                    <div class="audit-head">
                        <span class="audit-badge"><?= e(audit_action_label($r['action'])) ?></span>
                        <span class="audit-source"><?= e(audit_sources()[$r['source']]) ?></span>
                        <time><?= e(gmdate('d M Y H:i:s', (int) $r['created_at'])) ?></time>
                    </div>
                    <p class="audit-actor"><strong><?= e($r['actor_name'] ?? 'Sistem') ?></strong><?= $r['actor_email'] !== null ? ' <small>' . e($r['actor_email']) . '</small>' : '' ?></p>
                    <?php if ($r['reason'] !== ''): ?><p class="audit-reason">“<?= e($r['reason']) ?>”</p><?php endif; ?>
                    <details class="audit-details"><summary>Detail</summary><pre><?= e(audit_details_text((string) $r['details'])) ?></pre></details>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($pages > 1): ?>
            <nav class="audit-pager" aria-label="Halaman">
                <?php if ($filters['page'] > 1): ?><a href="<?= $link(['p' => $filters['page'] - 1]) ?>">← Sebelumnya</a><?php endif; ?>
                <?php if ($filters['page'] < $pages): ?><a href="<?= $link(['p' => $filters['page'] + 1]) ?>">Berikutnya →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php
}
