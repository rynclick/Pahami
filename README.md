# Pahami

**A WhatsApp AI agent that helps Indonesian court litigants understand summons and verdicts, track hearing deadlines, and prepare the right documents.**

Built for the [Meta Global AI Developer Hackathon](https://cerebralvalley.ai/e/meta-ai-developer-hackathon) (Oct 26 – Nov 4, 2026) · Track: Indie Developer · Theme: *Build for Your World*

> ⚠️ **Hackathon disclosure:** Everything in this repository was built from scratch during the hackathon building period (Oct 27 – Nov 4, 2026). It is not derived from any existing codebase. See [What was built during the hackathon](#what-was-built-during-the-hackathon).

---

## The problem

Every day at the Religious Courts in Yogyakarta, people walk into the service desk holding a summons, a lawsuit, or a verdict they do not understand. Most of them are handling a divorce, inheritance, or marriage case **without a lawyer**. They do not know what the document means, what they must do next, when the hearing is, or which papers to bring. Missing a single hearing can cost them their case.

I work at the Religious High Court of Yogyakarta and see this firsthand. Pahami (Indonesian for *“understand”*) is my attempt to fix it.

## What Pahami does

A litigant sends a **photo, PDF, or voice note** of a court document to Pahami on WhatsApp. Pahami then:

1. **Reads the document**, including phone photos of scanned paper
2. **Explains it in plain Indonesian**: what it is, what it means for you, what happens if you ignore it
3. **Extracts the hearing date** and sets **automatic WhatsApp reminders** (H-3, H-1)
4. **Builds a document checklist** for your specific case type and hearing stage
5. **Answers follow-up questions** in a conversation, with the document as context
6. **Routes you to the right court** out of the six Religious Courts in Yogyakarta, and can schedule a consultation via the court’s existing one-stop service

No app to install, no account to create. Just WhatsApp.

Every answer ends with a reminder that Pahami helps you understand, it is not legal advice, and points to the court service desk for anything binding.

## How it uses Meta’s models

| Task | Model |
|---|---|
| Document classification, multimodal reading of photographed/scanned documents, multi-turn agent reasoning | **Muse Spark Open** (`muse-spark-open-1026`) via Baseten, with **Muse Spark** via Meta Model API as fallback |
| Voice notes from WhatsApp | **Muse Voice Transcribe** via Meta Model API |
| Stamp / signature / table detection on scans (stretch goal) | **SAM 3.1** via Meta Model API |

All model calls go through a single `ModelClient` class, so the same agent can be pointed at a self-hosted **Muse Glimmer** inside a court network. Court data is sensitive, which is why the project is built on open-weight models from the start.

## Architecture

```
WhatsApp (photo / PDF / voice)  ──Fonnte webhook──▶  Pahami (CodeIgniter 4, MySQL)
Web upload (demo & staff view)  ──────────────────▶        │
                                                            ▼
                         1. Store document + conversation state
                         2. Extract text (PDF text / OCR / multimodal model)
                         3. Classify: summons · lawsuit · verdict · ruling
                         4. Agent loop (Muse Spark Open):
                              explain → extract dates → build checklist
                              → schedule reminders → answer follow-ups
                         5. Reply via WhatsApp (Fonnte) or web
```

## Tech stack

- PHP · CodeIgniter 4 · MySQL/MariaDB
- Fonnte API (WhatsApp inbound/outbound)
- Meta Model API · Baseten (Muse Spark Open)
- Tesseract OCR (fallback for low-quality scans)

## Running locally

```bash
git clone https://github.com/rynclick/pahami
cd pahami
composer install
cp env .env        # fill in DB, META_API_KEY, BASETEN_API_KEY, FONNTE_TOKEN
php spark migrate
php spark serve
```

Point your Fonnte webhook to `https://<your-host>/webhook/whatsapp`.

## Demo

- 🎥 Demo video: *(coming at submission)*
- 🌐 Live demo: *(coming at submission)*

All demo documents are **publicly published verdicts** from the Indonesian Supreme Court’s decision directory, or synthetic documents with fictional parties. No real case files or personal data are used.

## What was built during the hackathon

Everything here: the WhatsApp agent, document pipeline, prompts, reminder scheduler, checklist logic, web UI, and this README.

For context only: I previously built [PTSP Online](https://ptsp.pta-yogyakarta.go.id), the one-stop service portal for the six Religious Courts in Yogyakarta (CodeIgniter 4, ~500–1,000 users/month). Pahami is a **separate, new codebase**. The only link is that Pahami can hand a user over to that existing service desk. No code is shared.

## Author

**Ryan Taufik** — Religious High Court of Yogyakarta, Indonesia · [GitHub](https://github.com/rynclick) · [LinkedIn](https://linkedin.com/in/ryan-taufik-7462a348)

## License

MIT
