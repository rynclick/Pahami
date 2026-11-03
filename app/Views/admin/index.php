<?= $this->extend('layout') ?>
<?= $this->section('content') ?>

<?php
use App\Controllers\Admin;

$sources  = lang('Ui.sources');
$levels   = lang('Ui.levels');
$statuses = lang('Ui.reminderStatus');
$purposes = lang('Ui.purposes');
$perkara  = config('Perkara')->jenis;
?>

<style>
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 20px; }
    .tabs a { text-decoration: none; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--border); color: var(--text); background: var(--surface); font-size: 14px; }
    .tabs a[aria-current] { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
    .stats { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 20px; }
    .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
    .stat b { display: block; font-size: 26px; line-height: 1.2; }
    .stat span { color: var(--muted); font-size: 13px; }
    .badge.whatsapp { background: var(--ok-bg); color: var(--ok); }
    .badge.simulator { background: var(--warn-bg); color: var(--warn); }
    .badge.web { background: var(--empty-bg); color: var(--empty); }
    td.num { text-align: right; white-space: nowrap; }
    .stack-lg > * + * { margin-top: 20px; }
</style>

<h1><?= lang('Ui.adminTitle') ?></h1>
<p class="lead"><?= lang('Ui.adminLead') ?></p>

<nav class="tabs" aria-label="<?= lang('Ui.source') ?>">
    <?php foreach (Admin::SOURCES as $s): ?>
        <a href="<?= site_url('admin') ?>?source=<?= $s ?>"<?= $s === $source ? ' aria-current="page"' : '' ?>><?= esc($sources[$s]) ?> · <?= $counts[$s] ?></a>
    <?php endforeach ?>
</nav>

<div class="stats">
    <div class="stat"><b><?= $stats['done'] ?></b><span><?= lang('Ui.statDocs') ?></span></div>
    <div class="stat"><b><?= $stats['failed'] ?></b><span><?= lang('Ui.statFailed') ?></span></div>
    <div class="stat"><b><?= $stats['users'] ?></b><span><?= lang('Ui.statUsers') ?></span></div>
    <div class="stat"><b><?= $stats['escalated'] ?></b><span><?= lang('Ui.statEscalated') ?></span></div>
    <div class="stat"><b><?= $stats['reminders']['sent'] ?> / <?= $stats['reminders']['pending'] ?></b><span><?= lang('Ui.statReminders') ?>: <?= esc($statuses['sent']) ?> / <?= esc($statuses['pending']) ?></span></div>
</div>

<div class="stack-lg">
    <div class="card">
        <h2><?= lang('Ui.documentsTitle') ?></h2>
        <?php if ($documents === []): ?>
            <p class="hint"><?= lang('Ui.noDocuments') ?></p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr>
                    <th>#</th><th><?= lang('Ui.received') ?></th><th><?= lang('Ui.source') ?></th><th><?= lang('Ui.user') ?></th>
                    <th><?= lang('Ui.docType') ?></th><th><?= lang('Ui.caseType') ?></th><th><?= lang('Ui.caseNumber') ?></th>
                    <th><?= lang('Ui.court') ?></th><th><?= lang('Ui.hearing') ?></th><th><?= lang('Ui.confidence') ?></th><th><?= lang('Ui.status') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($documents as $d): $from = Admin::sourceOf($d); $level = $d['analysis_json'] === null ? null : (json_decode($d['analysis_json'], true)['verification']['tingkat_keyakinan'] ?? null); ?>
                    <tr>
                        <td><a href="<?= site_url('admin/document/' . $d['id']) ?>"><?= $d['id'] ?></a></td>
                        <td><?= esc(substr($d['created_at'], 0, 16)) ?></td>
                        <td><span class="badge <?= $from ?>"><?= esc($sources[$from]) ?></span></td>
                        <td><?= esc(Admin::maskPhone($d['phone'])) ?></td>
                        <td><?= esc(str_replace('_', ' ', $d['jenis_dokumen'] ?? '—')) ?></td>
                        <td><?= esc($perkara[$d['jenis_perkara']]['label'] ?? $d['jenis_perkara'] ?? '—') ?></td>
                        <td><?= esc($d['nomor_perkara'] ?? '—') ?></td>
                        <td><?= esc(strtoupper($d['pengadilan_kode'] ?? '—')) ?></td>
                        <td><?= esc(trim(($d['sidang_tanggal'] ?? '—') . ' ' . ($d['sidang_jam'] ?? ''))) ?></td>
                        <td><?php if ($level !== null): ?><span class="badge <?= esc($level, 'attr') ?>"><?= esc($levels[$level] ?? $level) ?></span><?php else: ?>—<?php endif ?></td>
                        <td><span class="badge <?= esc($d['status'], 'attr') ?>"><?= esc($d['status']) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>

    <div class="card">
        <h2><?= lang('Ui.escalationsTitle') ?></h2>
        <p class="hint" style="margin: 0 0 12px"><?= lang('Ui.escalationsHint') ?></p>
        <?php if ($escalations === []): ?>
            <p class="hint"><?= lang('Ui.noEscalations') ?></p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th><?= lang('Ui.time') ?></th><th><?= lang('Ui.user') ?></th><th><?= lang('Ui.question') ?></th><th><?= lang('Ui.answer') ?></th></tr></thead>
                <tbody>
                <?php foreach ($escalations as $e): ?>
                    <tr>
                        <td><?= esc(substr($e['created_at'], 0, 16)) ?></td>
                        <td><?= esc(Admin::maskPhone($e['phone'])) ?></td>
                        <td><?= esc($e['question'] ?? '—') ?></td>
                        <td data-translate="<?= site_url('admin/message/' . $e['id'] . '/translate') ?>"><?= wa_to_html(strtok((string) $e['content'], "\n")) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>

    <div class="card">
        <h2><?= lang('Ui.usageTitle') ?></h2>
        <p class="hint" style="margin: 0 0 12px"><?= lang('Ui.usageHint') ?></p>
        <?php if ($usage === []): ?>
            <p class="hint"><?= lang('Ui.noUsage') ?></p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th><?= lang('Ui.purpose') ?></th><th><?= lang('Ui.model') ?></th><th class="num"><?= lang('Ui.calls') ?></th><th class="num"><?= lang('Ui.tokensIn') ?></th><th class="num"><?= lang('Ui.tokensOut') ?></th><th class="num"><?= lang('Ui.avgLatency') ?></th><th class="num"><?= lang('Ui.errors') ?></th></tr></thead>
                <tbody>
                <?php foreach ($usage as $u): ?>
                    <tr>
                        <td><?= esc($purposes[$u['purpose']] ?? $u['purpose']) ?></td>
                        <td><?= esc($u['provider'] . ' · ' . $u['model']) ?></td>
                        <td class="num"><?= (int) $u['calls'] ?></td>
                        <td class="num"><?= number_format((int) $u['prompt_tokens'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format((int) $u['completion_tokens'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format((float) $u['latency'] / 1000, 1, ',', '.') ?> s</td>
                        <td class="num"><?= (int) $u['errors'] ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>
</div>

<?= $this->endSection() ?>
