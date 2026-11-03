<?= $this->extend('layout') ?>
<?= $this->section('content') ?>

<h1><?= lang('Ui.demoTitle') ?></h1>
<p class="lead"><?= lang('Ui.demoLead') ?></p>

<div class="grid">
    <div class="card">
        <?php if (session('error')): ?>
            <div class="alert"><?= esc(session('error')) ?></div>
        <?php endif ?>

        <?= form_open_multipart('demo', ['id' => 'upload-form']) ?>
            <?= csrf_field() ?>
            <div class="stack">
                <div>
                    <label for="dokumen"><?= lang('Ui.document') ?></label>
                    <input type="file" id="dokumen" name="dokumen" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                    <p class="hint"><?= lang('Ui.documentHint') ?></p>
                </div>
                <div>
                    <label for="provider"><?= lang('Ui.model') ?></label>
                    <select id="provider" name="provider">
                        <option value=""><?= lang('Ui.modelDefault', [esc($defaultProvider)]) ?></option>
                        <option value="meta" <?= set_select('provider', 'meta') ?>>Muse Spark — Meta Model API</option>
                        <option value="baseten" <?= set_select('provider', 'baseten') ?>>Muse Spark Open — Baseten (open-weight)</option>
                    </select>
                    <p class="hint"><?= lang('Ui.modelHint') ?></p>
                </div>
                <div>
                    <button type="submit" id="submit"><?= lang('Ui.read') ?></button>
                </div>
            </div>
        <?= form_close() ?>
    </div>

    <div class="card">
        <h2><?= lang('Ui.needDocument') ?></h2>
        <div class="stack">
            <div>
                <p style="margin: 0 0 8px"><?= lang('Ui.sampleIntro') ?></p>
                <a class="button" href="<?= site_url('demo/sample') ?>" download><?= lang('Ui.sampleDownload') ?></a>
            </div>
            <div>
                <p style="margin: 0 0 8px"><?= lang('Ui.directoryIntro') ?></p>
                <ol class="hint" style="margin: 0; padding-left: 20px">
                    <li><?= lang('Ui.directoryStep1') ?></li>
                    <li><?= lang('Ui.directoryStep2', [esc(implode(', ', $courts))]) ?></li>
                    <li><?= lang('Ui.directoryStep3') ?></li>
                </ol>
            </div>
            <p class="hint" style="margin: 0"><?= lang('Ui.earlierUploads') ?></p>
        </div>
    </div>
</div>

<script>
    // Reading a document takes 5–30 seconds; show that something is happening.
    document.getElementById('upload-form').addEventListener('submit', function () {
        var button = document.getElementById('submit');
        button.disabled = true;
        button.textContent = <?= json_encode(lang('Ui.reading')) ?>;
    });
</script>

<?= $this->endSection() ?>
