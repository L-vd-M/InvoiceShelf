---
name: sa-vat-research
description: Research a South African VAT/tax-law question against primary sources (SARS, the VAT Act, the Tax Administration Act) before a business, invoicing, pricing, or registration decision is made. Use when the user asks about VAT registration thresholds, VAT vendor obligations, what a non-vendor may or may not charge/invoice as tax, tax invoice requirements, SARS penalties, or any other South African tax-compliance question that will shape how a business's product, invoice template, or pricing logic behaves.
---

Spin up a **background agent** to do the research, so you keep working while it reads. Same shape as the `research` skill, with a legal source hierarchy and a mandatory verdict format bolted on — because the answer here gates a code or business decision, not just curiosity.

## Its job

1. **Source hierarchy — primary only, in this order:**
   - **The actual Act text.** VAT Act 89 of 1991 (as amended), Tax Administration Act 28 of 2011, Income Tax Act 58 of 1962 where relevant. Pull the consolidated PDF (acts.co.za, gov.za gazette, or SAFLII), convert to text, and quote the operative section verbatim — don't paraphrase from memory of what a section "probably says."
   - **SARS's own site** (sars.gov.za) for anything that changes over time and isn't in the Act itself: registration thresholds, current forms, interpretation notes, official guides. Thresholds move with each Budget — always state the effective date of whatever figure you cite, and check whether today's date is past or before it.
   - **Secondary commentary** (law firm articles, accounting-practice blogs) only to explain jargon or sanity-check your own reading — never as the source of a claim. If a secondary source says something the Act doesn't, that's a flag to re-read the Act, not a citation.
2. **Follow every claim back to its section number or URL.** A claim with no pinned source doesn't go in the doc.
3. **Write findings to `docs/research/<topic-kebab-case>.md`** in the current project (create `docs/research/` if it doesn't exist — match the convention of the `research` skill so both land in the same place).
4. **End with a `## Verdict` section**, plain English:
   - The direct yes/no/it-depends answer to what was asked.
   - What concretely changes in the business/system as a result (e.g., "hide the Tax row when `vat_id` is empty," "this invoice template needs X wording").
   - What would flip the verdict (e.g., "crossing the R2.3m compulsory threshold reverses this — re-run this research then, don't just flip a flag").
5. **Close with a one-line disclaimer**: this is research to inform an engineering/business decision, not a substitute for sign-off from a registered tax practitioner or accountant before anything gets filed with SARS or relied on in a dispute.

## Why the verdict format is mandatory here

Ordinary research informs a conversation. This research gates code — a hidden totals row, a label, a registration decision. A vague finding produces a vague fix. Force the same three things every run: the answer, what changes, and what would reverse it.
