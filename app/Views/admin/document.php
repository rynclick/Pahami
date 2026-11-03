<?= $this->extend('layout') ?>
<?= $this->section('content') ?>

<?php
use App\Controllers\Admin;

$json         = $doc['analysis_json'] ?? [];
$analysis     = $json['analysis'] ?? null;
$verification = $json['verification'] ?? null;
$from         = Admin::sourceOf($doc);
$labels       = lang('Ui.fields');
$reasons      = lang('Ui.reasons');
$levels       = lang('Ui.levels');
$statuses     = lang('Ui.reminderStatus');
$kinds        = lang('Ui.kinds');
$purposes     = lang('Ui.purposes');
$perkara      = config('Perkara')->jenis;
?>

<p><a href="<?= site_url('admin') ?>?source=<?= $from ?>"><?= lang('Ui.backAdmin') ?></a></p>
<h1><?= lang('Ui.documentNo', [$doc['id']]) ?> <span class="badge <?= esc($doc['status'], 'attr') ?>"><?= esc($doc['status']) ?></span></h1>
<p class="lead">
    <?= esc(lang('Ui.sources')[$from]) ?> · <?= esc(Admin::maskPhone($doc['phone'])) ?> · <?= esc($doc['created_at']) ?> · <?= esc($doc['provider'] ?? '—') ?> <?= esc($doc['model'] ?? '') ?>
    <?php if ($doc['file_path'] !== null && $doc['media_purged_at'] === null): ?>
        · <a href="<?= site_url('admin/document/' . $doc['id'] . '/file') ?>" target="_blank" rel="noopener"><?= lang('Ui.openFile') ?></a>
    <?php elseif ($doc['media_purged_at'] !== null): ?>
        <br><?= lang('Ui.purged', [esc($doc['media_purged_at'])]) ?>
    <?php endif ?>
</p>

<div class="grid">
    <div class="stack">
        <div class="card">
            <h2><?= lang('Ui.conversation') ?></h2>
            <?php if ($messages === []): ?>
                <p class="hint"><?= lang('Ui.noMessages') ?></p>
            <?php else: ?>
                <div class="chat">
                    <?php foreach ($messages as $m): ?>
                        <?php
                        $text = match ($m['type']) {
                            'image' => '🖼️ ' . ($m['media_filename'] ?? 'Foto'),
                            'pdf'   => '📄 ' . ($m['media_filename'] ?? 'PDF'),
                            'audio' => '🎙️ ' . ($m['transcript'] !== null ? '"' . $m['transcript'] . '"' : ''),
                            default => (string) $m['content'],
                        };
                        ?>
                        <div class="bubble <?= $m['direction'] === 'out' ? 'in' : 'out' ?>" data-translate="<?= site_url('admin/message/' . $m['id'] . '/translate') ?>"<?= $m['escalated'] ? ' style="box-shadow: inset 3px 0 0 var(--warn)"' : '' ?>>
                            <?= wa_to_html($text) ?>
                            <div class="hint" style="font-size: 11px; margin: 4px 0 0"><?= esc(substr($m['created_at'], 0, 16)) ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <div class="stack">
        <?php if ($analysis !== null): ?>
            <div class="card">
                <h2><?= lang('Ui.verifier') ?></h2>
                <dl class="meta">
                    <dt><?= lang('Ui.docType') ?></dt><dd><?= esc(str_replace('_', ' ', $analysis['jenis_dokumen'])) ?></dd>
                    <dt><?= lang('Ui.caseType') ?></dt><dd><?= esc($perkara[$analysis['jenis_perkara']]['label'] ?? $analysis['jenis_perkara']) ?><?= ($analysis['jenis_perkara_teks'] ?? null) ? ' (' . esc($analysis['jenis_perkara_teks']) . ')' : '' ?></dd>
                    <?php foreach (['nomor_perkara' => $analysis['nomor_perkara'], 'pengadilan' => $analysis['pengadilan'], 'sidang_tanggal' => $analysis['sidang']['tanggal'], 'sidang_jam' => $analysis['sidang']['jam'], 'penerima_adalah' => $analysis['penerima_adalah']] as $field => $value): ?>
                        <?php $check = $verification['fields'][$field] ?? null; ?>
                        <dt><?= esc($labels[$field]) ?></dt>
                        <dd>
                            <?= esc($value ?? '—') ?>
                            <?php if ($check !== null): ?>
                                <span class="badge <?= esc($check['status'], 'attr') ?>"><?= esc(lang('Ui.statuses')[$check['status']] ?? $check['status']) ?></span>
                                <?php if ($check['alasan'] !== null): ?><span class="hint"><?= esc($reasons[$check['alasan']] ?? $check['alasan']) ?></span><?php endif ?>
                            <?php endif ?>
                        </dd>
                    <?php endforeach ?>
                    <?php if ($verification !== null): ?>
                        <dt><?= lang('Ui.confidence') ?></dt>
                        <dd><span class="badge <?= esc($verification['tingkat_keyakinan'], 'attr') ?>"><?= esc($levels[$verification['tingkat_keyakinan']] ?? $verification['tingkat_keyakinan']) ?></span></dd>
                    <?php endif ?>
                    <dt><?= lang('Ui.summary') ?></dt><dd><?= esc($analysis['ringkasan_awam']) ?></dd>
                </dl>
            </div>
        <?php elseif (isset($json['error'])): ?>
            <div class="alert"><?= lang('Ui.analysisFailed', [esc($json['error'])]) ?></div>
        <?php endif ?>

        <div class="card">
            <h2><?= lang('Ui.docReminders') ?></h2>
            <?php if ($reminders === []): ?>
                <p class="hint">—</p>
            <?php else: ?>
                <div class="table-scroll"><table>
                    <thead><tr><th><?= lang('Ui.kind') ?></th><th><?= lang('Ui.sendAt') ?></th><th><?= lang('Ui.status') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($reminders as $r): ?>
                        <tr><td><?= esc($kinds[$r['kind']] ?? $r['kind']) ?></td><td><?= esc(substr($r['send_at'], 0, 16)) ?></td><td><span class="badge <?= esc($r['status'], 'attr') ?>"><?= esc($statuses[$r['status']] ?? $r['status']) ?></span></td></tr>
                    <?php endforeach ?>
                    </tbody>
                </table></div>
            <?php endif ?>
        </div>

        <div class="card">
            <h2><?= lang('Ui.modelCalls') ?></h2>
            <?php if ($calls === []): ?>
                <p class="hint"><?= lang('Ui.noCalls') ?></p>
            <?php else: ?>
                <div class="table-scroll"><table>
                    <thead><tr><th><?= lang('Ui.time') ?></th><th><?= lang('Ui.purpose') ?></th><th><?= lang('Ui.model') ?></th><th><?= lang('Ui.tokensIn') ?></th><th><?= lang('Ui.tokensOut') ?></th><th><?= lang('Ui.latency') ?></th><th><?= lang('Ui.error') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($calls as $c): ?>
                        <tr>
                            <td><?= esc(substr($c['created_at'], 11, 8)) ?></td>
                            <td><?= esc($purposes[$c['purpose']] ?? $c['purpose']) ?></td>
                            <td><?= esc($c['provider'] . ' · ' . $c['model']) ?></td>
                            <td><?= number_format((int) $c['prompt_tokens'], 0, ',', '.') ?></td>
                            <td><?= number_format((int) $c['completion_tokens'], 0, ',', '.') ?></td>
                            <td><?= number_format((int) $c['latency_ms'] / 1000, 1, ',', '.') ?> s</td>
                            <td><?= esc($c['error'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table></div>
            <?php endif ?>
        </div>

        <?php if ($analysis !== null): ?>
            <div class="card">
                <details>
                    <summary><?= lang('Ui.json') ?></summary>
                    <pre><?= esc(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            </div>
        <?php endif ?>
    </div>
</div>

<?= $this->endSection() ?>
