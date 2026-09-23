# How the `sa-vat-research` skill works

This document explains the skill in `SKILL.md` in the same folder — what it does, why it's built this way, and how to use it across future business projects. `SKILL.md` itself is deliberately terse (it's the instructions Claude reads at run time); this file is the longer explanation for you.

## What it's for

Any time a business decision — pricing, an invoice template, a registration choice, what gets shown on a customer-facing document — depends on a fact about South African VAT or tax law, this skill gets a proper, source-cited answer instead of a guess. It was built after a real bug: an invoice template was showing a "Tax" line on every invoice regardless of whether the issuing company was actually registered for VAT. That turned out to not just be a display quirk — it's a criminal offence under the VAT Act for a non-vendor to represent an amount as tax on an invoice. The skill exists so that class of mistake gets caught by research *before* it ships, not after.

## Where it lives

Two copies, both kept in sync by hand (skills don't auto-sync across locations):

- **Global**: `~/.claude/skills/sa-vat-research/` — available in every project on this machine, so it fires automatically ("future business building projects" per your request) without you needing to remember to bring it along.
- **Project-local**: `<project>/.claude/skills/sa-vat-research/` — a copy checked into the specific repo it was built for (currently `invoiceshelf-fork`), so the skill travels with the repo itself if it's ever cloned elsewhere, shared, or opened without the global `~/.claude` directory present.

If you edit the skill's behaviour later, update both copies (or copy the global one over the project one — that's the source of truth going forward).

## How it triggers

It's a **model-invoked** skill: Claude reads its `description` field every turn and fires it automatically when your question matches — you don't have to type a slash command. It's written to catch questions like:

- "Can I charge VAT if I'm not registered?"
- "What's the current VAT registration threshold?"
- "Does my invoice need to say 'Tax Invoice'?"
- "What happens if I show a Tax line and I'm not a vendor?"
- Any other South African tax-compliance question that would change how a product, invoice, or pricing system behaves.

You can also invoke it explicitly by name if you want to force it for a borderline question.

## What it actually does, step by step

1. **Launches a background agent.** Research like this involves reading long PDFs (consolidated Act text runs to hundreds of pages) and cross-referencing several sources — that's slow. Running it in the background means the main conversation keeps moving instead of sitting idle for 2-3 minutes while the agent reads.

2. **Follows a strict source hierarchy, primary sources only:**
   - **Tier 1 — the Act text itself.** VAT Act 89 of 1991, Tax Administration Act 28 of 2011, Income Tax Act 58 of 1962 where relevant. The agent pulls the actual consolidated PDF (from acts.co.za, the government gazette, or SAFLII), converts it to text, and quotes the exact operative section — not a paraphrase reconstructed from training data, which is exactly the kind of thing that goes subtly wrong with legal text (off-by-one section numbers, superseded wording, amendments the model wasn't trained past).
   - **Tier 2 — SARS's own website.** Anything that isn't fixed in the Act itself and changes over time — registration thresholds, current guide documents, interpretation notes — comes from sars.gov.za directly, with the effective date of whatever figure is cited. This matters because thresholds move with the annual Budget (they did, mid-2026, from R1m/R50k to R2.3m/R120k) and a stale cached figure is worse than no figure.
   - **Tier 3 — secondary commentary** (law firm write-ups, accounting blogs) — used only to sanity-check the agent's own reading of the primary text or explain jargon, never cited as the actual authority for a claim. If a blog post says something the Act doesn't actually say, that's treated as a reason to re-read the Act, not as a source.

3. **Cites everything inline.** Every claim in the output doc carries its section number or URL right next to it. A claim with nothing backing it doesn't make it into the document — this is the main defense against hallucinated legal detail.

4. **Writes one Markdown file** to `docs/research/<topic-kebab-case>.md` in whichever project you're working in. Same location convention as the general-purpose `research` skill, so all your research output — legal or otherwise — ends up in one predictable place per project.

5. **Always ends with a `## Verdict` section**, structured the same way every time:
   - The direct answer — yes, no, or "it depends on X."
   - What concretely should change in the system as a result — a specific, actionable line, e.g. "gate the totals-box Tax row on `company.vat_id` being non-empty, not on the tax amount being non-zero."
   - What would flip the verdict later — the condition under which you'd need to re-run this or reverse the code change (e.g. crossing a registration threshold), so a future you (or future Claude) knows not to just toggle a flag without re-checking the law.

6. **Ends with a one-line disclaimer** that this is engineering/business research, not a substitute for your accountant or tax practitioner's sign-off before anything gets filed or relied on in a dispute. The skill is good for "should this code branch exist," not for "should I file this return this way" — that's a professional's call.

## Why the verdict format is non-negotiable

A skill like the general `research` one is fine leaving the reader to draw their own conclusion — it's usually informing a conversation. This skill's output usually gates a code change or a business decision directly, so a vague or hedged finding just produces a vague fix, or worse, no fix. Forcing the same three things every single run — the answer, what changes, and what would reverse it — means the output is always immediately actionable, not just informative.

## Worked example (the run that produced this skill)

Question asked: *"Can a South African company that is NOT registered for VAT legally show a Tax/VAT line on a customer invoice?"*

What the agent found, tier by tier:
- **Tier 1 (VAT Act text)**: s.1 defines "vendor"; s.7(1)(a) says VAT is levied "on the supply by any vendor"; s.58(1)(a)(i)/(ii) makes it an offence (fine or up to 2 years imprisonment) for anyone to declare tax has been included/added, or to actually add a tax amount, "where in fact no tax is payable" — which is the case for a non-vendor by definition. s.20(1) ties the legal term "tax invoice" to "a supplier, being a registered vendor."
- **Tier 2 (SARS site + Budget)**: confirmed current registration thresholds (R2.3m compulsory / R120k voluntary, effective 1 April 2026) and that no statute mandates specific wording like "VAT not applicable" for non-vendors — that's industry convention, not law.
- **Verdict produced**: gate the entire Tax/VAT block (totals row and any per-item tax column) on the company having a VAT number, not on the computed tax amount — a R0,00 row is lower-risk but still pointless to show; hide it outright. Reverses automatically once/if the company registers and gets a `vat_id`.

That verdict was then applied directly to the invoice PDF template's Blade code (`table.blade.php`): both the totals-box tax row and the optional per-item tax column now check `company.vat_id` before rendering anything tax-related, closing the compliance gap the research surfaced.

Full output of that run: `docs/research/sa-vat-non-vendor-invoicing.md` in the `invoiceshelf-fork` project.

## Reusing it in a new business project

Nothing to set up — because the skill is global, it's already active. Just ask a VAT/tax-law question in the context of whatever new project you're building, and it will fire, write its findings to that project's own `docs/research/` folder, and give you the same three-part verdict. If you want it available even without `~/.claude` present (e.g. handing the repo to someone else, or a CI environment), copy `SKILL.md` from this folder into that project's own `.claude/skills/sa-vat-research/`.
