<?php
use App\Filters\UiLocale;

$locale = UiLocale::current();
$here   = '/' . trim(service('request')->getUri()->getPath(), '/');
?>
<!doctype html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Pahami') ?></title>
    <style>
        :root {
            --bg: #f6f7f5; --surface: #ffffff; --text: #1c1f1d; --muted: #5f6661; --border: #dfe3df;
            --accent: #0f7a5c; --accent-text: #ffffff;
            --ok: #1d7a3a; --ok-bg: #e3f4e8; --warn: #8a5a00; --warn-bg: #fdf1d8; --empty: #5f6661; --empty-bg: #eef0ee;
            --error: #a4262c; --error-bg: #fbe5e6;
            --chat-bg: #efeae2; --bubble-in: #ffffff; --bubble-out: #d9fdd3; --chat-text: #111b21;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #121513; --surface: #1b1f1c; --text: #e7ebe8; --muted: #9aa39d; --border: #2e3430;
                --accent: #3fbf93; --accent-text: #08110d;
                --ok: #7fdc9a; --ok-bg: #173523; --warn: #f3c46b; --warn-bg: #3a2e12; --empty: #9aa39d; --empty-bg: #262b27;
                --error: #ff9a9f; --error-bg: #3d1a1c;
                --chat-bg: #0b141a; --bubble-in: #202c33; --bubble-out: #005c4b; --chat-text: #e9edef;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        header.site { background: var(--surface); border-bottom: 1px solid var(--border); }
        header.site .wrap { display: flex; align-items: baseline; gap: 12px; padding-top: 14px; padding-bottom: 14px; }
        header.site a.brand { font-weight: 700; font-size: 20px; color: var(--accent); text-decoration: none; }
        header.site span { color: var(--muted); font-size: 14px; }
        header.site nav { margin-left: auto; font-size: 14px; white-space: nowrap; }
        @media (max-width: 640px) { header.site .wrap { flex-wrap: wrap; } header.site nav { margin-left: 0; } }
        header.site nav .lang { margin-left: 14px; }
        header.site nav .lang strong { color: var(--text); }
        dialog.lang-picker { border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--text); max-width: 440px; width: calc(100% - 32px); padding: 24px; }
        dialog.lang-picker::backdrop { background: rgba(0, 0, 0, .55); }
        dialog.lang-picker .choices { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        dialog.lang-picker .choices a { flex: 1; text-align: center; text-decoration: none; background: var(--accent); color: var(--accent-text); border-radius: 8px; padding: 12px 16px; font-weight: 600; }
        dialog.lang-picker .choices a.secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .tr { margin-top: 6px; padding-top: 6px; border-top: 1px dashed currentColor; opacity: .8; font-size: 14px; }
        .tr::before { content: "EN · "; font-weight: 700; font-size: 11px; letter-spacing: .04em; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 16px; }
        main.wrap { padding-top: 24px; padding-bottom: 48px; }
        h1 { font-size: 26px; margin: 0 0 6px; }
        h2 { font-size: 18px; margin: 0 0 12px; }
        p.lead { color: var(--muted); margin: 0 0 20px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        .grid { display: grid; gap: 20px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }
        @media (max-width: 860px) { .grid { grid-template-columns: minmax(0, 1fr); } }
        .stack > * + * { margin-top: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 6px; }
        input[type=file], select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
        .hint { color: var(--muted); font-size: 14px; margin: 6px 0 0; }
        button, a.button { background: var(--accent); color: var(--accent-text); border: 0; border-radius: 8px; padding: 12px 20px; font: inherit; font-weight: 600; cursor: pointer; }
        button[disabled] { opacity: .6; cursor: progress; }
        a.button { display: inline-block; text-decoration: none; }
        .alert { padding: 12px 14px; border-radius: 8px; background: var(--error-bg); color: var(--error); margin-bottom: 16px; }
        a { color: var(--accent); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        td.quote { color: var(--muted); font-style: italic; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .badge.ok { background: var(--ok-bg); color: var(--ok); }
        .badge.tidak_pasti, .badge.sedang { background: var(--warn-bg); color: var(--warn); }
        .badge.kosong { background: var(--empty-bg); color: var(--empty); }
        .badge.tinggi { background: var(--ok-bg); color: var(--ok); }
        .badge.rendah, .badge.failed { background: var(--error-bg); color: var(--error); }
        .badge.done { background: var(--ok-bg); color: var(--ok); }
        .badge.processing, .badge.received { background: var(--empty-bg); color: var(--empty); }
        .table-scroll { overflow-x: auto; }
        pre { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; overflow: auto; font-size: 13px; max-height: 480px; margin: 12px 0 0; }
        details summary { cursor: pointer; font-weight: 600; }
        .chat { background: var(--chat-bg); border-radius: 12px; padding: 16px 12px; display: flex; flex-direction: column; gap: 8px; }
        .chat-head { display: flex; align-items: center; gap: 10px; color: var(--chat-text); font-weight: 600; margin-bottom: 4px; }
        .chat-head .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--accent); color: var(--accent-text); display: grid; place-items: center; font-weight: 700; }
        .bubble { max-width: 88%; padding: 8px 10px; border-radius: 8px; color: var(--chat-text); font-size: 15px; line-height: 1.45; box-shadow: 0 1px 0.5px rgba(0,0,0,.13); overflow-wrap: anywhere; }
        .bubble.in { background: var(--bubble-in); align-self: flex-start; border-top-left-radius: 0; }
        .bubble.out { background: var(--bubble-out); align-self: flex-end; border-top-right-radius: 0; }
        .preview { width: 100%; border-radius: 8px; border: 1px solid var(--border); display: block; }
        iframe.preview { height: 520px; }
        dl.meta { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: 14px; }
        dl.meta dt { color: var(--muted); }
        dl.meta dd { margin: 0; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<header class="site">
    <div class="wrap">
        <a class="brand" href="<?= site_url('demo') ?>">Pahami</a>
        <span><?= lang('Ui.tagline') ?></span>
        <nav>
            <a href="<?= site_url('demo') ?>"><?= lang('Ui.navUpload') ?></a> · <a href="<?= site_url('demo/chat') ?>"><?= lang('Ui.navChat') ?></a> · <a href="<?= site_url('admin') ?>"><?= lang('Ui.navAdmin') ?></a>
            <span class="lang" aria-label="<?= lang('Ui.langSwitch') ?>">
                <?php foreach (UiLocale::LOCALES as $i => $code): ?><?= $i > 0 ? ' · ' : '' ?><?php if ($code === $locale): ?><strong><?= strtoupper($code) ?></strong><?php else: ?><a href="<?= site_url('lang/' . $code) ?>?r=<?= esc(rawurlencode($here), 'attr') ?>" hreflang="<?= $code ?>"><?= strtoupper($code) ?></a><?php endif ?><?php endforeach ?>
            </span>
        </nav>
    </div>
</header>
<main class="wrap">
    <?= $this->renderSection('content') ?>
</main>

<?php if (UiLocale::chosen() === null): ?>
    <!-- First visit: the page language (Indonesian, or English for hackathon judges). -->
    <dialog class="lang-picker" id="lang-picker" aria-labelledby="lang-title">
        <h2 id="lang-title">Pilih bahasa · Choose a language</h2>
        <p class="hint">Pahami melayani pencari keadilan di Pengadilan Agama Indonesia; balasan WhatsApp-nya selalu berbahasa Indonesia.</p>
        <p class="hint">Pahami serves litigants at Indonesia’s Religious Courts, so its WhatsApp replies are in Indonesian. In English, the pages show a translation under every reply.</p>
        <div class="choices">
            <a href="<?= site_url('lang/id') ?>?r=<?= esc(rawurlencode($here), 'attr') ?>" class="secondary" hreflang="id">🇮🇩 <?= lang('Ui.langId') ?></a>
            <a href="<?= site_url('lang/en') ?>?r=<?= esc(rawurlencode($here), 'attr') ?>" hreflang="en">🇬🇧 <?= lang('Ui.langEn') ?></a>
        </div>
    </dialog>
    <script>document.getElementById('lang-picker').showModal();</script>
<?php endif ?>

<?php if ($locale === 'en'): ?>
<script>
    // English mode: every element with data-translate gets an English translation under it
    // (GET, cached on the server; the original Indonesian stays as it is).
    window.pahamiTranslate = (() => {
        const done = new Map();
        return (root) => (root || document).querySelectorAll('[data-translate]').forEach(async (el) => {
            if (el.querySelector('.tr')) return;
            const url = el.dataset.translate;
            const box = document.createElement('div');
            box.className = 'tr';
            box.textContent = <?= json_encode(lang('Ui.translating')) ?>;
            el.appendChild(box);
            try {
                if (!done.has(url)) done.set(url, fetch(url).then((r) => r.ok ? r.json() : Promise.reject(r.status)));
                box.innerHTML = (await done.get(url)).html;
            } catch (e) {
                done.delete(url);
                box.textContent = <?= json_encode(lang('Ui.translateFail')) ?>;
            }
        });
    })();
    document.addEventListener('DOMContentLoaded', () => window.pahamiTranslate());
</script>
<?php endif ?>
</body>
</html>
