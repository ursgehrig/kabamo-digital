# Kabamo Digital — USSD demand-register demonstrator

A low-infrastructure, **USSD-first** prototype that builds and refreshes a **live
household demand register** — the denominator a health supply chain assumes but
rarely has. It demonstrates, on a working end-to-end stack, how the demand signal
for a bed-net (or comparable) campaign can be captured near-anonymously on the
simplest feature phone, kept current with a periodic re-survey, and used to drive
allocation that respects budget and equity.

> **Not a product, not a claim of invention.** This is a method demonstrator. It
> is a *consequent implementation* of an established field approach
> (sleeping-space / mattress enumeration, the "Senegal approach"). The value is in
> understanding and digitizing the process; the code is open and offered for reuse.

Built on a group case study ("Kabamo", a fictional country) for the Supply Chain
Management in Health Care module, MBA International Health, Swiss TPH.

---

## What it does

Dial a USSD short code → choose one of five **value chains** → answer in single
digits. Chain 1 (**Register the demand**) is the flagship: it records household
size, under-5s, pregnancy and — crucially — **sleeping spaces (mattresses)**, the
real unit of net demand. It derives a capped net entitlement, timestamps the
record for re-survey, and writes to SQLite. A read-only dashboard shows
**anonymised aggregates** only, with small-count suppression (k-anonymity).

```
Phone → USSD aggregator → POST → ussd.php → SQLite (kabamo.db)
                                         ↘ "CON …" (continue) / "END …" (end)
Browser → dashboard.php (JSON, aggregates only) → dashboard.html
```

The five chains map to the WHO/MSH management cycle: Register & Quantify →
Selection & Quantification; Supply → Procurement; Last-mile & resistance →
Storage & Distribution; Protection & monitoring → Use (which loops back to refresh
the register).

## Files

| File | Purpose |
|------|---------|
| `ussd.php` | USSD webhook endpoint (Africa's Talking contract: `sessionId`, `phoneNumber`, `text`; replies `CON …` / `END …`). |
| `dashboard.php` | Read-only JSON aggregates. **No row-level PII.** k-anonymity suppression. Auto-seeds synthetic demo data when near-empty. |
| `seed.php` | Creates the 6-district reference table and clearly-flagged synthetic demo households. |
| `schema.sql` | SQLite schema (households / sessions / reports / chain_def + views). |
| `kabamo.db` | Pre-built empty SQLite database (tables + views + chain definitions). |
| `index.html` | Demo page: value chains, process workflow, live phone widget, KPI teaser. |
| `dashboard.html` | Full dashboard: headline, district coverage, distribution, freshness, recent activity. |
| `manual.html` | How it works — and why (problem, reframe, cap-as-balancing-metric, caveats). |
| `repo.html` | This repository / usage page rendered in the site. |
| `test_local.php` | Simulate USSD POSTs locally without an aggregator. |

## Run locally

```bash
sqlite3 kabamo.db < schema.sql      # only if you don't use the bundled kabamo.db
php -S localhost:8000 -t .          # serve this folder
# open http://localhost:8000/index.html
```

Or test the endpoint directly (this is exactly what an aggregator sends):

```bash
curl -s -X POST http://localhost:8000/ussd.php \
  -d 'sessionId=t1&phoneNumber=+255700000001&text=1*6*4*2*1'
```

The web user must have **write** permission on `kabamo.db` *and* its directory
(SQLite WAL writes sidecar files). Change `HASH_SALT` in `ussd.php` before any
real use; phone numbers are stored hashed, never raw.

## Wire to a USSD aggregator (sandbox)

1. Create an account with a USSD aggregator that covers your target market
   (this prototype was tested against the Africa's Talking sandbox).
2. Create a USSD channel and set its callback URL to your deployed `ussd.php`.
3. Use the aggregator's simulator to dial the channel and walk the menu.

The sandbox short code only works in the aggregator's simulator, not on real
phones. Reaching real handsets requires a **live short code in a covered country**
(provisioning + recurring cost), and is a deployment decision, not a prototype
step.

## Honest limitations

- **Coverage bias** — phone self-report under-counts the unphoned poorest.
  Mitigation: CHW-assisted entry; USSD is the default channel, not the only one.
- **Data protection** — household configuration against a phone number (even
  hashed) is sensitive personal data. A real deployment needs a named data
  custodian, consent and purpose limitation (e.g. Tanzania PDPA 2022, GDPR-derived
  obligations). The demo uses synthetic / consented data only.
- **Self-report accuracy** — controlled by the entitlement cap and verification at
  collection.
- **Not an outbreak tool** — this is for planned, recurring supply to a known
  population, not acute outbreak response (a different problem).
- **Decomposition is a choice** — the five chains are a reasonable cut of the
  process, not a fact of the case.

## License

Licensed under the **Apache License, Version 2.0** (`Apache-2.0`).
See [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).

Copyright © 2026 Urs Gehrig — https://github.com/ursgehrig

You may use, modify and redistribute this code, including in closed-source and
commercial contexts, provided you retain the copyright and license notices. The
license includes an explicit patent grant. It does **not** require you to
open-source your changes.

## Status

Prototype / demonstrator. Issues and forks welcome.
