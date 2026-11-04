# Changelog

All work below was written during the Meta Global AI Developer Hackathon building period (Oct 27 – Nov 4, 2026).

## 2026-10-28
- CodeIgniter 4.7 skeleton, Docker Compose stack (PHP 8.3 + Apache, MariaDB 10.11), `env.example`.
- Database migrations for `users`, `documents`, `messages`, `reminders`, `model_calls`, with CI4 models and schema tests.
- `ModelClient` with `MetaProvider` and `BasetenProvider` (shared OpenAI-compatible path, 60s timeout, one retry on 429/5xx with `Retry-After`), `MODEL_PROVIDER` / `MODEL_VISION_PROVIDER` switch, every call logged to `model_calls` (incl. errors), `php spark model:ping`.
- Verified: Baseten `muse-spark-open-1026` accepts images, `reasoning_effort` and `json_schema` output.
- `DocumentAnalyzer`: prompt in `app/Prompts/document_analyzer.txt`, today's date (Asia/Jakarta) injected, strict schema (`DocumentSchema`, also sent as `response_format`), one retry with errors appended, putusan fields.
- `Verifier`: deterministic checks of date, time, case number and court against the model's quotes; confidence derived from the checks. Minimal `CourtDirectory` (six DIY courts, names and codes).
- `php spark document:analyze`; verified on the relaas photo (Meta + Baseten) and a 14-page verdict PDF (Meta). Baseten rejects PDF file parts (HTTP 400).
- `WhatsAppFormatter` (flow 2: explanation, checklist with reminder promise or date confirmation question, follow-up; "tidak terbaca" on low confidence) with tests.
- `/demo` page: upload (CSRF, type/size validation), model choice, WhatsApp-style reply, Verifier table, model call log, JSON; stored as `documents` with `source=web`. Feature tests.
- `PdfPageSelector`: long PDFs cut to opening page, duduk perkara, petitum, rekonvensi, pertimbangan hukum and amar (fixes the amar of a 68-page verdict being past Meta's 50-page render limit; 132,760 → 31,112 tokens). PDF → PNG fallback for Baseten with its 8-image cap and page priority.
- Schema: `bagian_putusan` (duduk perkara, petitum, pertimbangan utama, amar, kutipan amar, rekonvensi) and `istilah_hukum` glossary; shown on `/demo`, up to 3 terms in the WhatsApp checklist.
- `CourtDirectory`: address, phone, email for the six DIY courts, PTSP Online link; phone in the disclaimer.
- Fonnte webhook (`POST /webhook/fonnte?key=…`): stores the message, dedupes by inboxid, ignores groups, dispatches `php spark fonnte:process` in the background and answers at once. `InboundHandler`: intro for text, "Sedang saya baca… ⏳" → analysis → explanation, checklist, follow-up for photos/PDFs; outage vs. unreadable replies kept apart. `FonnteClient` (send, media download). Tests.
- Cloudflare Tunnel as an optional Docker Compose service for local webhook testing. Basic auth on `/demo` (`DEMO_PASSWORD`).
- Fix: the test database connection now uses utf8mb4 (emoji in WhatsApp replies failed to store in tests).

## 2026-10-29
- First live WhatsApp run on the Fonnte Super device: text, relaas photo and verdict PDF answered end to end.
- Webhook accepts GET as well as POST (Fonnte requires both; with POST only, Fonnte sent nothing).
- Fix: upload folders are created world-writable at container start; a folder first created by a root-run test blocked the www-data worker ("Permission denied").
- `ReminderPlanner` (H-3 09.00, H-1 16.00, H-0 06.00; only future times; a newer summons for the same case replaces older reminders), `ReminderSender` + `php spark reminders:send` (cancels reminders whose hearing has started; failed sends retried), `scheduler` container running it every 5 minutes.
- The reply now promises exactly the reminders that were scheduled. Fix: an uncertain hearing time no longer lets the date through to the reminders before the user confirms.
- Schedule confirmation: "ya" or a corrected date ("17 November 2026 jam 13.30") sets the hearing and schedules reminders; recorded in `analysis_json.konfirmasi`.
- `ChecklistBuilder`: base checklist per case type and party role, merged with items read from the document.
- Commands `checklist`, `jadwal`, `stop`, `pengadilan`, `bantuan`; greetings → intro; other text → command list (until follow-up Q&A).
- Synthetic summons generator `fixtures/documents/make_relaas_sintetis.php` (fictional parties, configurable hearing date). GD built with FreeType.

## 2026-10-29 (Oct 30 milestone)
- `ConversationAgent` + `app/Prompts/conversation_agent.txt`: follow-up Q&A with the latest document (7 days) and last 10 messages as context, `reasoning_effort: medium`, JSON output with an escalation flag. Escalated answers add the PTSP contact and are logged (`messages.escalated`). New command `ringkasan`: a summary to show at the service desk. `php spark agent:ask`.
- `Transcriber` (Muse Voice Transcribe, `POST /v1/asr/transcribe`, multipart, WAV via ffmpeg, Indonesian language bias, court keywords); voice notes are echoed ("Saya dengar: …") and handled like text. Logged to `model_calls`. `php spark voice:transcribe`. ffmpeg added to the image.
- Case types moved to `app/Config/Perkara.php` (20 Religious Court case types with labels and checklists); the schema enum is built from it; new field `jenis_perkara_teks` (case type as printed).
- Prompt: for a verstek verdict the defendant's remedy is verzet (14 days after notification), not banding.
- The disclaimer and the "hearing date has passed" warning also point to PTSP Online (ptsp.pta-yogyakarta.go.id); an escalated answer gives the contact once.

## 2026-10-29 (Oct 31 milestone)
- `Verifier` checks each quote against the PDF text layer of the pages sent to the model (3-word sequences, threshold 0.6, measured on real verdicts); `kutipan_amar_di_teks` flags a verdict's quoted amar that is not in the text, shown on `/demo` and noted in the WhatsApp reply. Photos and scans skip the check.
- Fix: appeal case numbers of the high court (`99/Pdt.G/2026/PTA.Yk`) were marked uncertain.
- `php spark media:purge --days=7 [--dry-run]` (`MediaPurger`): deletes uploads and Fonnte media links past the retention period, records `documents.media_purged_at`, sweeps orphan files; the scheduler runs it hourly. `/demo` says when a file was purged.
- Tests for the real `Transcriber` request path (ffmpeg → mono 16 kHz WAV, retry, logging) and `FonnteClient`.

## 2026-11-01
- Setup verified from a clean clone (only the files git would commit): `cp env.example .env` + `docker compose up -d --build` is enough. The app container now runs `composer install` and the migrations on first start (`docker/start.sh`); the test database is created by `docker/db-init/`; the database has a healthcheck and the app and scheduler wait for it.
- Fix: a fresh clone had no `pahami_test` database (80 tests failed); the scheduler errored until vendor/ existed.
- Ports configurable (`PAHAMI_HTTP_PORT`, `PAHAMI_DB_PORT`); the database is published on 127.0.0.1 only.
- `.gitattributes` (LF line endings). Real case numbers and a real WhatsApp number removed from tests.
- WhatsApp simulator `/demo/chat` (`DemoChat`, `SimulatorClient`): the full agent in the browser through the same `InboundHandler` as WhatsApp; one simulated user per browser session; sample button with a synthetic summons dated 10 days ahead (`SyntheticSummons`, also used by the fixture script); file upload and browser voice recording (WebM/Ogg Opus); side panel with the scheduled reminders, "deliver next reminder now" (`ReminderSender::send`) and the live model calls. Reminders of simulator users are delivered into the web chat, never to WhatsApp. Tests.
- README: the reminder list and court routing now describe what is built (no consultation booking yet).
- Web pages in Indonesian or English (`app/Language/{id,en}/Ui.php`, `UiLocale` filter, `/lang/{id|en}`): language picker on the first visit, ID · EN switch in the header. In English mode every WhatsApp reply on `/demo`, `/demo/chat` and `/admin` gets an English translation (`Translator`, Muse Spark, cached; only stored messages can be translated). WhatsApp itself stays Indonesian.
- `/admin` (`Admin`): tabs for real WhatsApp users, simulator sessions and web uploads; documents with confidence and hearing, escalated questions with the question asked, reminder and model-usage totals; document detail with the conversation, reminders, model calls and the original file while it is kept. Phone numbers masked. Tests.
- Open-weight path verified end to end in the simulator on Baseten `muse-spark-open-1026`: summons photo (all key fields verified, reminders scheduled), follow-up Q&A, escalation, `jadwal`, early reminder, 15-page verdict PDF as page images. Results in the README.
- Default model timeout raised from 60 s to 120 s (`MODEL_TIMEOUT`; a verdict PDF on Baseten took 53 s); `/demo` request limit 300 s like the simulator.
- Repo cleanup: CodeIgniter starter leftovers removed (welcome page, example tests/seed/model, `env` template, `builds` script); `/` redirects to the simulator; `composer.json` named `pahami/pahami`; `LICENSE` is MIT for Pahami; `tests/README.md` describes the suite. README rewritten around what works today.
- `/demo`: the list of earlier uploads is gone (they are on `/admin`, Web uploads); instead a card with a synthetic summons download (`/demo/sample`) and how to find published verdicts of the six Yogyakarta courts in the Supreme Court directory. README: "Documents to try". Tests.
- `php spark demo:reset` (`DemoDataReset`): deletes all conversation data and uploaded files to start a demo from scratch; shows the counts and asks first (`--force` skips the question). Tests.

## 2026-11-03
- README: new section "Muse Spark vs Muse Spark Open in Pahami" (hosting and data, PDF vs page images, voice, measured speed and quality, how to switch) and a step-by-step "Install and try it" guide with a 10-step walkthrough for judges and troubleshooting (verified on the running stack: 186 tests pass, Meta and Baseten answer); verdicts to try come from the PTA Yogyakarta page of the Supreme Court directory; repeated sections merged.
- README banner (`.github/assets/banner.svg` source, `banner.png` rendered at 2×).

## 2026-11-04
- README: badges under the title (tests, license, PHP, CodeIgniter, Docker, Meta Model API, WhatsApp) and an architecture diagram (`.github/assets/architecture.svg` source, `architecture.png` rendered at 2×); the text diagram stays in a collapsible section.
