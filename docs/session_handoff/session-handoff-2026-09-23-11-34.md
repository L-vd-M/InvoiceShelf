# Session Handoff — 2026-09-23 11:34

Project: InvoiceShelf fork for TARCZA (Pty) Ltd
Branch: `feature/invoice-layout-presets` (working tree clean, all commits pushed to `origin`)
Deploy target: Docker container `invoiceshelf` on Proxmox CT115 (`pct exec 115`), reachable via `ssh proxmox`

---

## 1. Work completed (commits, oldest → newest this session)

This session picked up mid-way through prior work (PDF template redesign, Customer Company feature). Commits made in *this* session, in order:

1. **`29117c1b` fix(invoices): extract inclusive tax correctly for invoice-level tax** — Global (non-per-item) tax always used add-on math (`subtotal * pct/100`) even under `tax_included`, unlike the per-item path which already extracted correctly (`total - total/(1+pct/100)`). Toggling `tax_included` never actually changed the computed tax figure, only whether it was added to the total. Fixed both call sites in `DocumentTotals.vue` to use the shared `calcTaxAmount` helper, and added a `watch()` on `tax_included` to trigger recalculation (previously nothing re-triggered on toggle at all). Also fixed a billing-address heading bug (`CustomerCompany::applyTo()` / `CustomerCreateView.vue` sourced the Bill To heading from the wrong field).

2. **`e286f912` docs: add SA VAT law research skill + non-vendor invoicing findings** — Built a reusable Claude Code skill (`sa-vat-research`, both global at `~/.claude/skills/` and project-local at `.claude/skills/`) for South African tax-law research against primary sources (VAT Act text, SARS site), with a mandatory Verdict format. First research round: **South Africa's VAT Act 89 of 1991 s.58 makes it a criminal offence (fine or up to 2 years) for a non-VAT-registered business to declare or charge any tax amount on an invoice.** TARCZA is not VAT-registered. This directly drove the next commit.

3. **`744341da` docs(research): SA Tax-vs-VAT invoice labeling verdict; label VAT-registered per-item tax column** — Second research round, verifying industry-standard terminology. Verdict: **"VAT" is correct SA convention (SARS's own VAT 404 Guide labels every real amount "VAT," never generic "Tax") — but only when the company IS VAT-registered.** Rejected the user's initial "rename all Tax to VAT" request as stated (would have re-broken the just-fixed compliance issue for TARCZA's actual non-registered state). Applied the correct scoped fix: renamed the one remaining "Tax" column header to "VAT," gated on `company.vat_id` exactly as the totals row already was.

4. **`651090bd` feat(customers): add distinct VAT number field, correct Bill To label** — User flagged "Tax No:" still showing on the Bill To block. Investigation found this is the *customer's* generic `tax_id` field, structurally different from a VAT number (the app already splits `tax_id`/`vat_id` on `Company` and `CustomerCompany`, just not on `Customer`). Rather than mislabel a generic field, added a real `vat_id` column to `customers` (migration, request validation, API resource, both customer forms, `CustomerCompany` sync). Bill To now shows "VAT No:" when set, falling back to "Tax No:" otherwise. **Backfilled existing customer data**: 3 customers' `tax_id` values matched the SA VAT-number format (10 digits, leading 4) and were non-destructively copied into the new `vat_id` column (original `tax_id` left untouched). This commit required a full image rebuild + redeploy (see §3).

5. **`47c2e053` docs(research): VAT vs Tax conceptual explainer** — Third research round, a broader conceptual explainer (not gated on a code change) on why "Tax" exists as a generic label at all (jurisdiction-agnostic software design) and why South Africa specifically has no ambiguity for it to resolve (VAT is SA's *only* transaction consumption tax — checked exhaustively against PAYE, withholding tax, SDL, UIF, customs/excise, Turnover Tax, income tax; none of them is ever a customer-invoice line item). Confirmatory — no code change.

6. **`2753e570` docs: consolidate all three VAT research rounds into one formatted PDF** — User asked for full detailed documentation. Built `docs/typst/sa-vat-invoicing-research.typ` (compiles to `.pdf` via the `typst-compile` fish function), an 8-page consolidated report merging all three research rounds plus a "Code changes implemented" section.

7. **`67e5e32b` docs(research): multi-country tax terminology — design foundation only** — User asked (separately) whether tax terminology could become dynamic by company/customer country. Explicitly scoped as **research-only, no implementation** — user wants to keep sending real SA invoices unaffected while this gets researched and verified first. Findings: VAT/GST is origin/supplier-registration-based everywhere checked (UK, EU, Australia, NZ, Canada, India) — customer's country only matters for genuine cross-border supplies (zero-rating/reverse-charge), never as an independent label selector. Confirmed analogous "non-vendor may not charge/show tax" restrictions in UK (civil penalty), Australia, India; explicitly left Canada and per-EU-member-state as open unconfirmed gaps rather than guessing; US has no federal VAT/sales-tax layer at all (state/county-level, nexus-based since *Wayfair*). Codebase check: neither `Company` nor `Customer` has a `country` column today (only via optional `Address` relation); `vat_id` doesn't record which country issued it. Recommends an allowlist approach (SA only until a real second-jurisdiction need arises) with **no tax line at all** as the fallback for anything unresearched.

8. **`ddd6879d` docs: formatted PDF for multi-country tax terminology research** — Same Typst treatment as commit 6, for the multi-country research. 6-page PDF at `docs/typst/multi-country-invoice-tax-terminology.pdf`.

All commits end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>` per the session's attribution instruction.

## 2. Decisions made and rationale

- **Never blindly comply with a terminology-rename request without checking the legal implication first.** The user twice asked for changes ("rename Tax to VAT everywhere," implicitly "make VAT No consistent everywhere") that would have been *wrong* if applied literally, given TARCZA's actual non-VAT-registered status. Both times, research was done first and the request was applied only in the scope that was actually correct — explained clearly why the blanket version was rejected. This paid off; the user did not push back on either partial-compliance decision.
- **Gate all tax-related display on `company.vat_id` presence, not on the tax amount being non-zero.** An R0,00 "Tax" row is lower legal risk than a nonzero one but still bad practice (implies registered status) and was replaced with full row suppression instead.
- **Split `vat_id` from `tax_id` on `Customer`, don't relabel the existing field.** The generic `tax_id` field is used app-wide for non-VAT-specific tax references (consistent with `Company`'s own existing split); relabeling it "VAT No" without a data-model change would have mislabeled data for any customer whose number isn't actually VAT.
- **Backfill existing customer VAT numbers non-destructively.** Matched the SA VAT-number format (`^4\d{9}$`) rather than guessing; left the original `tax_id` field untouched in case it's needed for another purpose later.
- **Multi-country tax terminology: research only, explicitly no code yet.** User was explicit about this — wants to keep sending real South African invoices on the current, already-verified logic while the broader research gets built and reviewed. Did not implement any country-detection logic this session.
- **`docs/research/*.md` for raw findings, `docs/typst/*.typ`+`.pdf` for polished consolidated documentation** — established as the pattern this session; likely to continue if more research rounds happen.

## 3. Current system state

- **Live container**: `invoiceshelf` on CT115, image `invoiceshelf:custom`, up and healthy at time of writing. Built from commit `651090bd` (the last commit requiring a rebuild — all commits after that were docs-only, no redeploy needed).
- **What's verified working, with real rendered PDFs checked at each step**:
  - `$headerHeight = 400` fix (from before this session's start) — confirmed no overlap even on a 3-page invoice with both company VAT and customer tax number present.
  - Non-VAT-registered company (TARCZA's real state): single "Amount" column (no Excl/Incl split), totals box shows Sub-Total → Discount → Total with **no Tax/VAT row at all**.
  - VAT-registered path (`isVatRegistered` branch): would show dual "Amount (Excl)"/"Amount (Incl)" columns and a "VAT" (not "Tax") label — **this branch is logically complete and code-reviewed but has not been exercised against a real VAT-registered test company/invoice this session**, since TARCZA itself has no VAT number. Low risk (unchanged from earlier-session logic, just relabeled) but worth a real render check if/when TARCZA (or a test fixture) actually has a `vat_id`.
  - Customer VAT No / Tax No fallback: confirmed live against invoice #5 (NWU) — renders "VAT No: 4500209301" correctly after the backfill.
- **Database**: `vat_id` column added to `customers` table (migration `2026_09_23_090000_add_vat_id_field_to_customers_table`), applied via the container's `AUTORUN_ENABLED=true` migration-on-start behavior. 3 customer records backfilled (`Megan Louise Zwarts` id 1, `YKNOT BLOCKCHAIN SOLUTIONS (PTY) LTD` id 2, `Herman Fourie` id 5 — note ids 1 and 5 share the same underlying person/number, this is pre-existing test data, not something this session created).
- **Deploy mechanics reminder** (established pattern this whole session, see `~/.claude/shared/vue-laravel-pdf-lessons.md`): Blade templates are hot-swappable via `docker cp` (no rebuild); PHP/Vue/migrations require a full `docker build` → `save`/`load` → container recreate. Composer auth secret needed for build: `~/.github_composer_auth_token`.

## 4. Pending tasks

1. **Multi-country tax terminology — NOT implemented, by design.** Research is done and documented (`docs/research/multi-country-invoice-tax-terminology.md`, `docs/typst/multi-country-invoice-tax-terminology.pdf`). Next step, when the user is ready: review the doc together, decide whether/when to build the data-model prerequisites (a real `country` field on `Company`/`Customer`, a way to know which country issued a given `vat_id`), and get sign-off from a locally-qualified tax advisor before shipping anything for a non-SA jurisdiction.
2. **Multi-page landscape invoices** — flagged earlier in the broader session (before this handoff's scope) as splitting the totals box awkwardly across pages. Not fixed, not re-verified this session.
3. **Google Maps/Places address-autocomplete feature** — scoped earlier (Google Places, customer-facing forms only), blocked on the user obtaining/providing a Google Cloud API key. Not started.
4. **VAT-registered rendering path** — logically complete (dual Excl/Incl columns, "VAT" label) but not exercised against a real live invoice this session, since no test company/invoice has a `vat_id` set. Worth a real render check before TARCZA (or any future VAT-registered test case) relies on it.
5. **Small inline "+ Add new customer" quick-add modal now has the VAT field** (added this session, `CustomerModal.vue`) — but double-check it still lacks the Company picker (`BaseCustomerCompanySelectInput`) that the main Customers page form has, if that's still wanted there; this was flagged out-of-scope in an earlier part of the broader session and not revisited.

## 5. Known issues

| Issue | Status | Workaround |
|---|---|---|
| VAT-registered code path unexercised against a real invoice | Not a bug, just unverified | Manually set a `vat_id` on a test company and render a PDF before relying on it |
| Multi-page landscape totals box splits awkwardly | Known, not fixed | Avoid very long landscape invoices, or manually check the render |
| Google Places autocomplete | Not started | Waiting on user's Google Cloud API key |
| `docs/typst/*.pdf` committed as binary shows a CRLF git warning | Cosmetic only | Harmless — Typst's PDF output line-ending detection false-positive, not a text-modification issue |

## 6. Next chat starter block

```
Project: InvoiceShelf fork for TARCZA (Pty) Ltd
Location: ~/Documents/Development/invoiceshelf-fork/invoiceshelf-fork/
Branch: feature/invoice-layout-presets (clean, pushed)
Deploy: Docker container "invoiceshelf" on Proxmox CT115 (ssh proxmox, then pct exec 115 -- docker ...)
Live state: healthy, running image built from commit 651090bd; all commits since are docs-only (no redeploy needed)

Read first if resuming VAT/tax work:
- docs/research/sa-vat-non-vendor-invoicing.md
- docs/research/sa-invoice-tax-vs-vat-labeling.md
- docs/research/vat-vs-tax-invoice-terminology.md
- docs/research/multi-country-invoice-tax-terminology.md
- docs/typst/sa-vat-invoicing-research.pdf (consolidated, human-readable)
- docs/typst/multi-country-invoice-tax-terminology.pdf (consolidated, human-readable)
- ~/.claude/skills/sa-vat-research/ (reusable research skill, also copied into this repo's .claude/skills/)

Immediate next steps (pick up from here):
1. Multi-country tax terminology is researched but NOT implemented — review docs/typst/multi-country-invoice-tax-terminology.pdf with the user, decide whether/when to build the country-field data-model prerequisites, before any code changes for a non-SA jurisdiction.
2. Multi-page landscape invoice totals-box pagination bug — not fixed.
3. Google Places autocomplete — blocked on user's API key.
4. VAT-registered rendering path (dual Excl/Incl columns) is logically complete but has never been exercised against a real live invoice — worth a render check with a test vat_id before trusting it fully.

Current SA-only, non-VAT-registered invoice logic is fully shipped, verified, and working — TARCZA can keep sending real invoices on it right now with no known issues.
```
