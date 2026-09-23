# "Tax" vs "VAT" — What Should the Tax-Line Label Actually Say on a South African Invoice?

Research date: 2026-09-23. Follow-up to `docs/research/sa-vat-non-vendor-invoicing.md` in this repo (read first — this file assumes that research as established fact and does not re-litigate the s.58/s.20 offence analysis). Scope here is narrower: given that the prior research settled *whether* a tax line may appear, this file settles *what word* should be used to label it, for both VAT-registered and non-VAT-registered issuers. Primary sources only; every claim is cited to the statute section or SARS/professional-body document it comes from.

Sources used:

- VAT Act 89 of 1991, consolidated text (same source and version as the prior research; text extracted directly from the PDF with `pdftotext -layout` and grepped for exact statutory wording): https://disasterlaw.ifrc.org/sites/default/files/media/disaster_law/2021-10/89%20of%201991%20VALUE-ADDED%20TAX%20ACT_2021.08.01%20-%20to%20date.pdf
- SARS — Tax Invoices (official requirements page): https://www.sars.gov.za/businesses-and-employers/government/tax-invoices/
- SARS — Checklist: Value-Added Tax (VAT) Invoices (official PDF checklist, Version 2, 29 March 2016): https://www.sars.gov.za/wp-content/uploads/Docs/Government/Tax-Invoice-Checklist-Version-2-29032016.pdf
- SARS — VAT 404 Guide for Vendors, Issue 15 (official guide, content current to 17 December 2024): https://www.sars.gov.za/wp-content/uploads/Ops/Guides/Legal-Pub-Guide-VAT404-VAT-404-Guide-for-Vendors.pdf
- SARS — VAT Connect Issue 15 (December 2022): https://www.sars.gov.za/businesses-and-employers/my-business-and-tax/newsletters/vat-connect-issue-15-december-2022/

Sources attempted but not reachable (noted for transparency, not relied on for any claim): SAICA's Integritax technical article on tax invoices (`saica.co.za/integritax/2002/978_Tax_invoices_for_VAT.htm`) returned a TLS certificate mismatch on every access path tried (direct, non-www, Wayback Machine unavailable in this environment). SAIT's own article "VAT Invoice: The Importance Of A Compliant Tax Invoice" (`thesait.org.za`) returned HTTP 403 on fetch. Neither could be read, so neither is cited below — every claim instead rests on the VAT Act text itself and SARS's own official publications, which is a stronger foundation than a professional body's commentary in any case (SAICA/SAIT publications interpret the Act; they do not override it).

---

## 1. What the Act itself calls the tax-amount line — the word "tax" is a defined term, and it *means* VAT

This is the load-bearing fact the whole question turns on, and it's easy to miss: **"tax" is not a generic placeholder word in the VAT Act — it is a defined term.**

**Section 1 (Definitions):** *`"tax" means the tax chargeable under this Act;`*

Since the only tax chargeable "under this Act" (the VAT Act) is value-added tax, levied under s.7(1)(a) (quoted in full in the prior research), **"tax" and "VAT" are legal synonyms throughout this statute.** Every time the Act says "tax", it means VAT and nothing else — there is no second tax type lurking in the VAT Act that "tax" could ambiguously refer to.

This explains why the Act's own drafting language for tax invoice content uses the word "tax", not "VAT":

**Section 20(4)(g)(i)** — the required content for a full tax invoice's amount line: *"the value of the supply, **the amount of tax charged** and the consideration for the supply"*

**Section 20(4)(a)** — the required title wording: *"The words **'tax invoice', 'VAT invoice' or 'invoice'**;"*

So, at the level of pure statutory compliance for a **registered vendor**, the Act itself accepts "tax" as fully interchangeable with "VAT" — a document titled "Tax Invoice" (not "VAT Invoice") is one of the three explicitly sanctioned options, and the Act's own required-content clause is phrased as "amount of tax charged", not "amount of VAT charged". **A generic "Tax" label is not, by itself, incorrect or non-compliant for a VAT-registered vendor** — legally, it satisfies s.20(4) exactly as well as "VAT" would, because the Act has defined "tax" to mean VAT specifically. This is a South Africa-specific legal fact, not a matter of style: it only works because SA has exactly one transaction-based consumption tax. In a jurisdiction with several stacked transaction taxes (VAT + a separate luxury tax, or the US's federal/state/county sales tax patchwork), a bare "Tax" label would be genuinely ambiguous about *which* tax; in South Africa, under this specific Act, it cannot be — "tax" has one and only one referent.

## 2. What SARS itself actually writes on the amount line when it isn't drafting statute — "VAT", consistently

Legal permissibility (§1) is not the same question as convention. SARS's own **VAT 404 Guide for Vendors** (Issue 15, the vendor-facing plain-English guide, explicitly *not* intended as a legal reference — its own Preface says so) drops the Act's internal "tax" shorthand the moment it needs to communicate an actual rand amount to a vendor. Its worked illustration of how VAT flows through a supply chain ("Example 1 — Mechanism of the VAT system", p.3) reads:

> "A VAT registered paper manufacturer sells 2 rolls of uncoated print paper sheets to a VAT registered stationery distributor for R92,00 (**including VAT of R12,00**). The paper manufacturer recycled waste paper for which it paid R46,00 (**including VAT of R6,00**)..."
>
> "The stationery distributor also buys packaging boxes from another vendor for R34,50 (**inclusive of R4,50 VAT**)... The selling price of each box of paper includes **R1,05 VAT**..."
>
> "The supermarket sells 15 of the 20 boxes to its customers for R11,50 each (**inclusive of R1,50 VAT**)."

Every single rand figure in SARS's own official worked example — the one document SARS publishes specifically to show ordinary vendors how the tax appears in a price — is labelled "VAT", never "Tax". The accompanying diagram on the same page labels the running totals "Output Tax" and "Input Tax" (VAT-specific accounting terms of art, not generic "tax"), and the surrounding narrative text consistently uses "VAT" whenever a concrete amount is being described to a lay reader, reserving the bare word "tax" for the abstract, statutory sense (e.g. "the tax chargeable", mirroring the s.1 definition).

**Reading §1 and §2 together:** the Act's *own internal vocabulary* treats "tax" and "VAT" as synonyms (because it has defined them that way), but the moment SARS itself writes for an actual audience of vendors and customers rather than drafting statute, it reaches for "VAT" specifically. That is the clearest signal available of what "industry-standard" actually means in South African practice: **"VAT" is the expected, unambiguous, customer-facing label for a real amount on a real document; "Tax" is the Act's internal legal shorthand, not the plain-English convention SARS itself follows when addressing the public.**

## 3. Is a bare "Tax" label ever genuinely SA industry-standard on an actual invoice line?

No — not as a matter of established South African commercial practice, even though it would pass legal muster for a registered vendor per §1. Three independent reasons converge on the same answer:

- **There is only one tax it could be.** As already established in the prior research (§5 of `sa-vat-non-vendor-invoicing.md`), no other South African tax or levy is ever itemised on an ordinary trading/services invoice — not Turnover Tax (paid by the business on its own turnover, never passed through line-by-line), not excise/environmental levies (folded into manufacturer/importer cost price, not itemised by resellers), not withholding tax (cross-border payment context only, not a domestic sales invoice line). A generic "Tax" label therefore never disambiguates anything in the SA context — there is nothing else it could be labelling. Contrast the US, where "Sales Tax" genuinely needs a generic word because the rate and authority vary by state/county/city and a single "Tax" line can legitimately mean different things in different jurisdictions of the same country. South Africa has no equivalent structural reason for a generic label.
- **SARS's own plain-English material doesn't use it** (§2, above) — if the country's own tax authority, writing for vendors, reaches for "VAT" every time a real amount is shown, that is strong evidence of the operative convention.
- **The checklist SARS actually hands out for invoice validation** (`Tax-Invoice-Checklist-Version-2-29032016.pdf`) frames its own criterion for the amount line as *"Value of the supply, **the amount of tax charged** and the consideration of the supply (value and the **tax**)"* — note that even here, in the one place SARS's plain-language checklist does use the bare word "tax", it is always paired with, or immediately follows, the word "VAT" in the document's own title ("VALUE-ADDED TAX (VAT) INVOICES") and heading structure — it is never presented as a standalone, undefined generic label the way an off-the-shelf accounting-software default ("Tax") would be.

**Conclusion:** a bare "Tax" label on the amount line is not wrong under the letter of s.20(4) for a registered vendor (§1), but it is not South African convention either — it reads as an unlocalised accounting-software default (most invoicing software, including this fork's presumable upstream, is originally built for jurisdictions with multiple/variable transaction taxes) rather than a deliberate SA-market choice. There is no scenario — vendor or non-vendor — in which "Tax" is the *better* or *more standard* choice than "VAT" in South Africa; at best it is legally tolerable for a vendor, and for a non-vendor it doesn't even have that going for it (§4, below).

## 4. Does the analysis change anything from the prior research for the non-VAT-registered case?

No — if anything it reinforces the prior verdict. The prior research (§4 of `sa-vat-non-vendor-invoicing.md`) already concluded the cleanest position for a non-vendor is to suppress the tax row entirely rather than show it at R0,00, because an R0,00 "Tax"/"VAT" row invites confusion about vendor status even though it likely doesn't meet the strict statutory language of the s.58(1)(a)(i) offence (which requires *declaring* that tax "has been included in, or will be added to" the price — an explicit zero states the opposite).

Nothing found in this round of research disturbs that. Two points sharpen it:

- A search for a separate "holding out as a vendor" offence provision in the Act (distinct from s.58) found none — a full-text grep of the consolidated Act for "hold... out" / "holds... out" / "represents... vendor" returned no VAT-specific holding-out provision. **Section 58(1)(a) is the entire universe of relevant offence exposure here** — there is no broader catch-all "implying VAT-registered status" offence to separately worry about. This confirms the prior research's framing was complete, not merely a plausible reading.
- Given §1–§3 above, **if** a non-vendor's system ever did render a tax-related row (which it should not, per the existing verdict), the word "VAT" specifically would be the worse choice of the two, not the better one — "VAT" is the term SARS itself reserves for real, chargeable amounts (§2), so a non-vendor's row labelled "VAT" (even at R0,00) reads as a more direct claim to VAT-vendor status than a generic "Tax" row would. This doesn't change the recommendation (still: suppress the row, don't relabel it) but it does mean the developer's proposed direction — renaming toward "VAT" everywhere — is specifically backwards for the non-vendor case, not merely unnecessary.

## 5. Practical labeling logic for a system serving both registered and non-registered SA businesses

Pulling §1–§4 together, the two conditions the developer needs are:

| Company state | Row shown at all? | Label if shown | Why |
|---|---|---|---|
| **No `vat_id`** (non-vendor) | **No** — suppress the row entirely, per existing prior-research verdict | N/A | s.58(1)(a) risk (prior research §2, §4); no legitimate SA tax would populate this row for a non-vendor (prior research §5); "VAT" specifically is the worse of two bad labels if shown at all (§4, above) |
| **Has `vat_id`** (registered vendor) | **Yes** | **"VAT"**, not "Tax" | Not legally required over "Tax" (§1 — the Act treats them as synonyms via the s.1 definition), but is the label SARS itself uses whenever describing a real amount (§2), is the only transaction tax that could ever populate the row (§3), and removes any ambiguity for the customer at zero cost |

The general design principle: **the label should never be a generic, jurisdiction-agnostic placeholder ("Tax") copied from software built for multi-tax jurisdictions.** South Africa's VAT Act has already done the disambiguation work by defining "tax" to mean exactly one thing — but a template maintained going forward should say the specific, unambiguous, SARS-modelled word ("VAT") rather than lean on that statutory technicality, and should never show any tax-related word at all for an issuer with no `vat_id`.

---

## Verdict

**(a) Direct answer — "Tax" or "VAT"?**

- For a **VAT-registered** vendor's invoice: **"VAT" is the correct, SARS-modelled, industry-standard label for the tax-amount line.** A generic "Tax" label is not illegal (s.1's definition of "tax" as "the tax chargeable under this Act" makes it a technical synonym for VAT, and s.20(4)(a)/(g) both use the bare word "tax" in the Act's own drafting), but it is not South African convention — it is what an unlocalised, US-style accounting-software default looks like, not what SARS's own vendor-facing material (VAT 404 Guide, Example 1) actually writes when showing real amounts. South Africa has exactly one transaction-based consumption tax, so there is no disambiguation purpose a generic "Tax" label could ever serve here the way it does in multi-tax jurisdictions.
- For a **non-VAT-registered** business's invoice: **the word "VAT" should not appear at all**, confirming and sharpening the prior research's verdict — suppress the row entirely rather than show it labelled either "Tax" or "VAT" at R0,00. If a label had to be chosen despite that (it shouldn't be), "VAT" is the *worse* of the two options for a non-vendor, not the better one, since it is the term SARS itself reserves for genuine chargeable amounts.

**(b) What should change (or not) in the TARCZA Blade invoice template**

- **Reject the developer's request as stated** ("go through the invoices and change all references to TAX to VAT" applied uniformly) — it is right for one code path and wrong for the other, and applying it blindly would put "VAT" onto the non-vendor's documents, which is the specific outcome the prior research's whole verdict exists to prevent.
- **Follow it partially, gated on `company.vat_id`, exactly along the lines the prior research already established:**
  1. Where `company.vat_id` is **non-empty** (TARCZA becomes VAT-registered): change the label from generic "Tax" to **"VAT"** on the totals/breakdown line. This is the one part of the developer's request that is correct and should be implemented — but only inside the already-conditional block the prior research recommended gating on `vat_id`.
  2. Where `company.vat_id` is **empty** (TARCZA's current state): make **no** labeling change — the row stays suppressed entirely, per the existing prior verdict. Do not rename anything on this path; there is nothing to rename because nothing should render.
- Net code change: in the Blade template(s), any hardcoded "Tax" string inside the `vat_id`-gated (vendor) branch should become "VAT". Nothing in the non-vendor branch should be touched beyond what the prior research already specified (row suppressed, no "Tax Invoice"/"VAT Invoice" wording, plain "Invoice" only).

**(c) What would flip this verdict**

- If TARCZA ever became liable for a second, separately-itemised SA transaction tax alongside VAT (none currently exists for an ordinary trading/services Pty Ltd, per prior research §5) — at that point a more specific per-tax labeling scheme (not a bare "Tax") would be needed, not a reversion to generic "Tax".
- If SARS revises s.20(4)(a) to drop "tax invoice" as a permitted title option, or amends the s.1 definition of "tax" to no longer mean VAT exclusively (e.g. if a distinct national sales tax were ever introduced) — either change would require re-reading this analysis, since §1's core finding depends on "tax" currently being defined as a synonym for VAT specifically.
- If SAICA or SAIT publish specific, accessible guidance that contradicts the SARS-example-based convention identified here — worth rechecking once those sources are reachable (both were blocked by network/TLS issues during this research, not because they were checked and found silent).

This is not a substitute for a registered tax practitioner's sign-off — get one before shipping the VAT-registered code path live.
