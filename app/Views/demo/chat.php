<?= $this->extend('layout') ?>
<?= $this->section('content') ?>

<?php
// Texts the page script needs.
$texts = [];
foreach (['chatEmpty', 'typing', 'noReminders', 'noCallsYet', 'requestFailed', 'noRecording', 'micFailed', 'kinds', 'purposes', 'reminderStatus'] as $key) {
    $texts[$key] = lang('Ui.' . $key);
}
?>

<style>
    .sim { display: grid; gap: 20px; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); align-items: start; }
    @media (max-width: 860px) { .sim { grid-template-columns: minmax(0, 1fr); } }
    .phone { background: var(--chat-bg); border-radius: 14px; border: 1px solid var(--border); display: flex; flex-direction: column; height: min(78vh, 760px); overflow: hidden; }
    .phone .chat-head { background: var(--surface); color: var(--text); padding: 10px 14px; margin: 0; border-bottom: 1px solid var(--border); }
    .phone .chat-head small { display: block; color: var(--muted); font-weight: 400; font-size: 12px; }
    #thread { flex: 1; overflow-y: auto; padding: 14px 12px; display: flex; flex-direction: column; gap: 8px; }
    #thread .bubble { position: relative; padding-bottom: 16px; min-width: 64px; }
    #thread .bubble time { position: absolute; right: 8px; bottom: 2px; font-size: 11px; opacity: .6; }
    #thread .bubble.escalated { box-shadow: inset 3px 0 0 var(--warn); }
    #thread .empty { color: var(--chat-text); opacity: .75; text-align: center; margin: auto; max-width: 340px; font-size: 14px; }
    .typing { align-self: flex-start; color: var(--chat-text); opacity: .7; font-size: 14px; font-style: italic; padding: 4px 6px; }
    .composer { background: var(--surface); border-top: 1px solid var(--border); padding: 8px; display: flex; gap: 6px; align-items: center; }
    .composer input[type=text] { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--bg); color: var(--text); font: inherit; }
    .icon-btn { background: transparent; color: var(--text); border: 1px solid var(--border); border-radius: 999px; padding: 0; width: 42px; height: 42px; flex: none; font-size: 18px; }
    .icon-btn.recording { background: var(--error-bg); color: var(--error); border-color: var(--error); }
    .composer button.send { border-radius: 999px; padding: 10px 16px; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; background: var(--surface); border-top: 1px solid var(--border); }
    .chips button { background: var(--bg); color: var(--text); border: 1px solid var(--border); padding: 4px 10px; font-weight: 500; font-size: 13px; border-radius: 999px; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
    .toolbar select { width: auto; }
    button.secondary { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
    .panel h2 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
    .panel h2 small { color: var(--muted); font-weight: 400; font-size: 13px; }
    .badge.pending { background: var(--warn-bg); color: var(--warn); }
    .badge.sent { background: var(--ok-bg); color: var(--ok); }
    .badge.cancelled { background: var(--empty-bg); color: var(--empty); }
    td.err { color: var(--error); }
    #error:empty { display: none; }
</style>

<h1><?= lang('Ui.chatTitle') ?></h1>
<p class="lead"><?= lang('Ui.chatLead') ?></p>

<div class="toolbar">
    <button type="button" id="sample"><?= lang('Ui.sample') ?></button>
    <label for="provider" style="margin: 0"><?= lang('Ui.model') ?></label>
    <select id="provider">
        <option value=""><?= lang('Ui.modelDefault', [esc($defaultProvider)]) ?></option>
        <option value="meta" <?= $provider === 'meta' ? 'selected' : '' ?>>Muse Spark — Meta Model API</option>
        <option value="baseten" <?= $provider === 'baseten' ? 'selected' : '' ?>>Muse Spark Open — Baseten</option>
    </select>
    <button type="button" class="secondary" id="reset"><?= lang('Ui.reset') ?></button>
</div>
<div class="alert" id="error" role="alert"></div>

<div class="sim">
    <div class="phone">
        <div class="chat-head"><span class="avatar">P</span><div>Pahami<small><?= lang('Ui.chatSub') ?></small></div></div>
        <div id="thread" aria-live="polite"></div>
        <div class="chips" id="chips">
            <?php $hints = lang('Ui.chipHints'); ?>
            <?php foreach (['halo', 'jadwal', 'checklist', 'pengadilan', 'Apa itu relaas?', 'Apakah saya akan menang?', 'ringkasan', 'stop'] as $chip): ?>
                <button type="button" data-text="<?= esc($chip, 'attr') ?>"><?= esc($chip) ?><?= is_array($hints) ? ' <span class="hint">· ' . esc($hints[$chip]) . '</span>' : '' ?></button>
            <?php endforeach ?>
        </div>
        <form class="composer" id="composer">
            <input type="file" id="file" accept=".jpg,.jpeg,.png,.pdf,audio/*" hidden>
            <button type="button" class="icon-btn" id="attach" title="<?= lang('Ui.attach') ?>" aria-label="<?= lang('Ui.attach') ?>">📎</button>
            <input type="text" id="text" placeholder="<?= lang('Ui.typeMessage') ?>" autocomplete="off" aria-label="<?= lang('Ui.message') ?>">
            <button type="button" class="icon-btn" id="record" title="<?= lang('Ui.record') ?>" aria-label="<?= lang('Ui.record') ?>">🎙️</button>
            <button type="submit" class="send"><?= lang('Ui.send') ?></button>
        </form>
    </div>

    <div class="stack panel">
        <div class="card">
            <h2><?= lang('Ui.remindersTitle') ?> <small>H-3 09.00 · H-1 16.00 · H-0 06.00</small></h2>
            <div class="table-scroll"><table>
                <thead><tr><th><?= lang('Ui.kind') ?></th><th><?= lang('Ui.sendAt') ?></th><th><?= lang('Ui.status') ?></th></tr></thead>
                <tbody id="reminders"></tbody>
            </table></div>
            <p class="hint"><?= lang('Ui.remindersHint') ?></p>
            <p style="margin: 10px 0 0"><button type="button" class="secondary" id="reminder"><?= lang('Ui.sendReminder') ?></button></p>
        </div>
        <div class="card">
            <h2><?= lang('Ui.modelCalls') ?> <small><?= lang('Ui.callsLive') ?></small></h2>
            <div class="table-scroll"><table>
                <thead><tr><th><?= lang('Ui.time') ?></th><th><?= lang('Ui.purpose') ?></th><th><?= lang('Ui.model') ?></th><th><?= lang('Ui.tokens') ?></th><th><?= lang('Ui.latency') ?></th></tr></thead>
                <tbody id="calls"></tbody>
            </table></div>
        </div>
    </div>
</div>

<script>
(() => {
    const base = <?= json_encode(site_url('demo/chat')) ?>;
    const csrfName = <?= json_encode(csrf_token()) ?>;
    let csrf = <?= json_encode(csrf_hash()) ?>;
    let state = <?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    let busy = false;

    const $ = (id) => document.getElementById(id);
    const thread = $('thread');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = <?= json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const fill = (s, v) => s.replace('{0}', v);

    function render(pending) {
        thread.innerHTML = state.messages.length || pending
            ? state.messages.map((m) => `<div class="bubble ${m.out ? 'in' : 'out'}${m.escalated ? ' escalated' : ''}" data-translate="${esc(m.translate)}">${m.html}<time>${esc(m.time)}</time></div>`).join('')
            : `<p class="empty">${t.chatEmpty}</p>`;
        if (pending) {
            thread.insertAdjacentHTML('beforeend', `<div class="bubble out">${esc(pending)}</div><div class="typing">${esc(t.typing)}</div>`);
        }
        thread.scrollTop = thread.scrollHeight;
        if (!pending && window.pahamiTranslate) window.pahamiTranslate(thread);

        $('reminders').innerHTML = state.reminders.length
            ? state.reminders.map((r) => `<tr><td>${esc(t.kinds[r.kind] ?? r.kind)}</td><td>${esc(r.send_at)}</td><td><span class="badge ${esc(r.status)}">${esc(t.reminderStatus[r.status] ?? r.status)}</span></td></tr>`).join('')
            : `<tr><td colspan="3" class="hint">${esc(t.noReminders)}</td></tr>`;
        $('calls').innerHTML = state.calls.length
            ? state.calls.map((c) => `<tr><td>${esc(c.time)}</td><td>${esc(t.purposes[c.purpose] ?? c.purpose)}</td><td>${esc(c.model)}</td>` +
                (c.error ? `<td colspan="2" class="err">${esc(c.error)}</td>` : `<td>${esc(c.tokens)}</td><td>${esc(c.latency)}</td>`) + '</tr>').join('')
            : `<tr><td colspan="5" class="hint">${esc(t.noCallsYet)}</td></tr>`;
    }

    async function post(action, fields, pending) {
        if (busy) return;
        busy = true;
        document.querySelectorAll('button, input').forEach((el) => el.disabled = true);
        $('error').textContent = '';
        render(pending);

        const body = new FormData();
        body.append(csrfName, csrf);
        body.append('provider', $('provider').value);
        for (const [k, v] of Object.entries(fields)) body.append(k, ...(Array.isArray(v) ? v : [v]));

        try {
            const res = await fetch(`${base}/${action}`, {method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'}});
            const data = await res.json();
            csrf = data.csrf ?? csrf;
            state = data;
            if (data.error) $('error').textContent = data.error;
        } catch (e) {
            $('error').textContent = fill(t.requestFailed, e.message);
        } finally {
            busy = false;
            document.querySelectorAll('button, input').forEach((el) => el.disabled = false);
            render();
        }
    }

    $('composer').addEventListener('submit', (e) => {
        e.preventDefault();
        const text = $('text').value.trim();
        if (!text) return;
        $('text').value = '';
        post('send', {text}, text);
    });
    $('chips').addEventListener('click', (e) => {
        const text = e.target.dataset?.text;
        if (text) post('send', {text}, text);
    });
    $('attach').addEventListener('click', () => $('file').click());
    $('file').addEventListener('change', () => {
        const file = $('file').files[0];
        $('file').value = '';
        if (file) post('send', {file: [file, file.name], text: $('text').value.trim()}, '📎 ' + file.name);
    });
    $('sample').addEventListener('click', () => post('sample', {}, '🖼️ relaas_contoh_sintetis.png'));
    $('reminder').addEventListener('click', () => post('reminder', {}));
    $('reset').addEventListener('click', () => post('reset', {}));

    // Voice notes recorded in the browser (Ogg/Opus or WebM/Opus), converted on the server like WhatsApp's.
    let recorder = null;
    $('record').addEventListener('click', async () => {
        if (recorder) { recorder.stop(); return; }
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            $('error').textContent = t.noRecording;
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({audio: true});
            const type = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus'].find((t) => MediaRecorder.isTypeSupported(t)) ?? '';
            const chunks = [];
            recorder = new MediaRecorder(stream, type ? {mimeType: type} : {});
            recorder.ondataavailable = (e) => chunks.push(e.data);
            recorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                $('record').classList.remove('recording');
                $('record').textContent = '🎙️';
                const blob = new Blob(chunks, {type: recorder.mimeType});
                recorder = null;
                post('send', {file: [blob, blob.type.includes('ogg') ? 'suara.ogg' : 'suara.webm']}, '🎙️ Pesan suara');
            };
            recorder.start();
            $('record').classList.add('recording');
            $('record').textContent = '⏹';
        } catch (e) {
            $('error').textContent = fill(t.micFailed, e.message);
        }
    });

    render();
})();
</script>

<?= $this->endSection() ?>
