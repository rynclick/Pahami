<?= $this->extend('layout') ?>
<?= $this->section('content') ?>

<?php
$labels  = lang('Ui.fields');
$reasons = lang('Ui.reasons');
$values  = $analysis === null ? [] : [
    'nomor_perkara'   => $analysis['nomor_perkara'],
    'pengadilan'      => $analysis['pengadilan'],
    'sidang_tanggal'  => $analysis['sidang']['tanggal'],
    'sidang_jam'      => $analysis['sidang']['jam'],
    'penerima_adalah' => $analysis['penerima_adalah'],
];
$isImage = in_array($doc['mime'], ['image/jpeg', 'image/png'], true);
$yesNo   = static fn (bool $v) => lang($v ? 'Ui.yes' : 'Ui.no');
?>

<p><a href="<?= site_url('demo') ?>"><?= lang('Ui.back') ?></a></p>
<h1><?= lang('Ui.documentNo', [$doc['id']]) ?> <span class="badge <?= esc($doc['status'], 'attr') ?>"><?= esc($doc['status']) ?></span></h1>
<p class="lead">
    <?= esc($doc['provider'] ?? '—') ?> · <?= esc($doc['model'] ?? '—') ?> · <?= esc($doc['created_at']) ?>
    <?php if (isset($input['halaman'])): ?>
        <br><?= lang('Ui.sentToModel') ?><?= $input['jenis'] === 'pdf_sebagai_gambar' ? lang('Ui.asImages') : '' ?>:
        <?= count($input['halaman']) === $input['total_halaman']
            ? lang('Ui.allPages', [$input['total_halaman']])
            : lang('Ui.somePages', [esc(implode(', ', $input['halaman'])), $input['total_halaman']]) ?>
    <?php endif ?>
</p>

<?php if ($error !== null): ?>
    <div class="alert"><?= lang('Ui.analysisFailed', [esc($error)]) ?></div>
<?php endif ?>

<div class="grid">
    <div class="stack">
        <div class="chat" aria-label="<?= lang('Ui.waReply') ?>">
            <div class="chat-head"><span class="avatar">P</span> Pahami</div>
            <div class="bubble out"><?= lang($isImage ? 'Ui.sentPhoto' : 'Ui.sentDocument') ?></div>
            <?php foreach ($replies as $i => $reply): ?>
                <div class="bubble in" data-translate="<?= site_url('demo/' . $doc['id'] . '/translate/' . $i) ?>"><?= wa_to_html($reply) ?></div>
            <?php endforeach ?>
        </div>

        <div class="card">
            <h2><?= lang('Ui.original') ?></h2>
            <?php if ($doc['media_purged_at'] !== null): ?>
                <p class="hint"><?= lang('Ui.purged', [esc($doc['media_purged_at'])]) ?></p>
            <?php elseif ($isImage): ?>
                <img class="preview" src="<?= site_url('demo/' . $doc['id'] . '/file') ?>" alt="<?= lang('Ui.uploadedAlt') ?>">
            <?php else: ?>
                <iframe class="preview" src="<?= site_url('demo/' . $doc['id'] . '/file') ?>" title="<?= lang('Ui.uploadedAlt') ?>"></iframe>
            <?php endif ?>
        </div>
    </div>

    <div class="stack">
        <?php if ($verification !== null): ?>
            <div class="card">
                <h2><?= lang('Ui.verifier') ?></h2>
                <p class="hint" style="margin: 0 0 12px"><?= lang('Ui.verifierHint') ?></p>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th><?= lang('Ui.field') ?></th><th><?= lang('Ui.value') ?></th><th><?= lang('Ui.quote') ?></th><th><?= lang('Ui.status') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($keyFields as $field): $check = $verification['fields'][$field]; ?>
                            <tr>
                                <td><?= esc($labels[$field]) ?></td>
                                <td><?= esc($values[$field] ?? '—') ?></td>
                                <td class="quote"><?= esc($analysis['kutipan'][$field] ?? '—') ?></td>
                                <td>
                                    <span class="badge <?= esc($check['status'], 'attr') ?>"><?= esc(lang('Ui.statuses')[$check['status']] ?? $check['status']) ?></span>
                                    <?php if ($check['alasan'] !== null): ?><div class="hint"><?= esc($reasons[$check['alasan']] ?? $check['alasan']) ?></div><?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <dl class="meta" style="margin-top: 14px">
                    <dt><?= lang('Ui.confidence') ?></dt>
                    <dd><span class="badge <?= esc($verification['tingkat_keyakinan'], 'attr') ?>"><?= esc(lang('Ui.levels')[$verification['tingkat_keyakinan']] ?? $verification['tingkat_keyakinan']) ?></span> <?= lang('Ui.confidenceHint') ?></dd>
                    <dt><?= lang('Ui.courtCode') ?></dt>
                    <dd><?= esc($verification['pengadilan_kode'] ?? '—') ?></dd>
                    <dt><?= lang('Ui.hearingPassed') ?></dt>
                    <dd><?= $verification['sidang_sudah_lewat'] === null ? '—' : $yesNo($verification['sidang_sudah_lewat']) ?></dd>
                    <dt><?= lang('Ui.needsConfirm') ?></dt>
                    <dd><?= $verification['perlu_konfirmasi_jadwal'] ? lang('Ui.confirmYes') : lang('Ui.no') ?></dd>
                    <dt><?= lang('Ui.pdfChecked') ?></dt>
                    <dd><?= ($verification['teks_sumber_dicek'] ?? false) ? lang('Ui.pdfCheckedYes') : lang('Ui.pdfCheckedNo') ?></dd>
                    <?php if (($verification['kutipan_amar_di_teks'] ?? null) !== null): ?>
                        <dt><?= lang('Ui.amarInPdf') ?></dt>
                        <dd><span class="badge <?= $verification['kutipan_amar_di_teks'] ? 'ok' : 'tidak_pasti' ?>"><?= lang($verification['kutipan_amar_di_teks'] ? 'Ui.found' : 'Ui.notFound') ?></span></dd>
                    <?php endif ?>
                </dl>
            </div>
        <?php endif ?>

        <?php $bagian = $analysis['bagian_putusan'] ?? null; ?>
        <?php if ($bagian !== null): ?>
            <div class="card">
                <h2><?= lang('Ui.verdictParts') ?></h2>
                <dl class="meta">
                    <dt><?= lang('Ui.dudukPerkara') ?></dt>
                    <dd><?= esc($bagian['duduk_perkara'] ?? '—') ?></dd>
                    <?php foreach (['petitum' => 'Ui.petitum', 'pertimbangan_utama' => 'Ui.pertimbangan', 'amar' => 'Ui.amar'] as $key => $label): ?>
                        <dt><?= lang($label) ?></dt>
                        <dd>
                            <?php if ($bagian[$key] === []): ?>—<?php else: ?>
                                <ol style="margin: 0; padding-left: 18px">
                                    <?php foreach ($bagian[$key] as $item): ?><li><?= esc($item) ?></li><?php endforeach ?>
                                </ol>
                            <?php endif ?>
                        </dd>
                    <?php endforeach ?>
                    <dt><?= lang('Ui.amarQuote') ?></dt>
                    <dd class="quote" style="font-style: italic"><?= esc($bagian['kutipan_amar'] ?? '—') ?></dd>
                    <dt><?= lang('Ui.rekonvensi') ?></dt>
                    <dd><?= esc($bagian['rekonvensi'] ?? lang('Ui.none')) ?></dd>
                </dl>
            </div>
        <?php endif ?>

        <?php if (($analysis['istilah_hukum'] ?? []) !== []): ?>
            <div class="card">
                <h2><?= lang('Ui.legalTerms') ?></h2>
                <dl class="meta">
                    <?php foreach ($analysis['istilah_hukum'] as $term): ?>
                        <dt><?= esc($term['istilah']) ?></dt>
                        <dd><?= esc($term['arti']) ?></dd>
                    <?php endforeach ?>
                </dl>
            </div>
        <?php endif ?>

        <div class="card">
            <h2><?= lang('Ui.modelCalls') ?></h2>
            <?php if ($calls === []): ?>
                <p class="hint"><?= lang('Ui.noCalls') ?></p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th><?= lang('Ui.provider') ?></th><th><?= lang('Ui.tokensIn') ?></th><th><?= lang('Ui.tokensOut') ?></th><th><?= lang('Ui.time') ?></th><th><?= lang('Ui.error') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($calls as $call): ?>
                            <tr>
                                <td><?= esc($call['provider']) ?></td>
                                <td><?= esc(number_format((int) $call['prompt_tokens'], 0, ',', '.')) ?></td>
                                <td><?= esc(number_format((int) $call['completion_tokens'], 0, ',', '.')) ?></td>
                                <td><?= esc(number_format($call['latency_ms'] / 1000, 1, ',', '.')) ?> s</td>
                                <td><?= esc($call['error'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>

        <?php if ($analysis !== null): ?>
            <div class="card">
                <details>
                    <summary><?= lang('Ui.json') ?></summary>
                    <pre><?= esc(json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            </div>
        <?php endif ?>
    </div>
</div>

<?= $this->endSection() ?>
