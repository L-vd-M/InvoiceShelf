// SA VAT Invoicing Research — Consolidated findings for TARCZA invoice template
// Generated: 2026-09-23

#set page(
  paper: "a4",
  margin: (top: 2cm, bottom: 2.2cm, left: 2.2cm, right: 2.2cm),
  header: [
    #set text(size: 8pt, fill: rgb("#888888"))
    #grid(columns: (1fr, 1fr),
      [SA VAT Invoicing Research],
      align(right)[TARCZA / InvoiceShelf fork])
    #line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
  ],
  footer: [
    #set text(size: 8pt, fill: rgb("#888888"))
    #line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
    #grid(columns: (1fr, 1fr),
      [Generated: 2026-09-23],
      align(right)[Page #context counter(page).display()])
  ]
)

#set text(font: "Liberation Serif", size: 10.5pt, lang: "en")
#set heading(numbering: "1.")

#show heading.where(level: 1): it => {
  v(1em)
  block(fill: rgb("#003366"), inset: (x: 10pt, y: 6pt), radius: 3pt, width: 100%,
    text(fill: white, weight: "bold", size: 12pt, it.body))
  v(0.4em)
}
#show heading.where(level: 2): it => {
  v(0.7em)
  text(weight: "bold", size: 11pt, fill: rgb("#003366"), it.body)
  v(0.2em)
}
#show heading.where(level: 3): it => {
  v(0.5em)
  text(weight: "bold", size: 10.5pt, it.body)
  v(0.1em)
}

#show raw: it => {
  set text(font: "Liberation Mono", size: 9pt)
  it
}

#show quote: it => {
  block(fill: rgb("#f2f6fa"), inset: 10pt, radius: 3pt, width: 100%, it)
}

// ─── TITLE BLOCK ─────────────────────────────────────────────────────────────
#align(center)[
  #v(0.5em)
  #block(fill: rgb("#003366"), inset: (x: 20pt, y: 14pt), radius: 4pt, width: 100%)[
    #text(fill: white, weight: "bold", size: 18pt)[SA VAT Invoicing Research]
    #v(0.3em)
    #text(fill: rgb("#aaccff"), size: 11pt)[Consolidated findings — TARCZA (Pty) Ltd invoice template, InvoiceShelf fork]
  ]
  #v(0.5em)
]

This document consolidates three rounds of research conducted 2026-09-23 into a single reference: (1) whether a non-VAT-registered South African company may show a tax amount on an invoice at all, (2) what word — "Tax" or "VAT" — should label that amount when it is shown, and (3) the general conceptual difference between "VAT" and "Tax" as invoice terminology. Each round produced a written verdict and, in the first two cases, a direct code change to the live TARCZA invoice PDF template. All three source documents live in `docs/research/` in this repository; this file merges them into one reading, in the order the questions actually arose, and adds a final section on what was actually shipped to production as a result.

#v(0.3em)
#line(length: 100%, stroke: 1pt + rgb("#003366"))
#v(0.3em)

= Background and business context

TARCZA (Pty) Ltd is a real South African company issuing customer invoices through this InvoiceShelf fork. At the time of this research, TARCZA was *not registered for VAT* — its annual turnover was well under both the mandatory registration threshold and the voluntary registration threshold. The invoice PDF template, however, had been built generically (following the upstream open-source project's multi-country design) and displayed a "Tax" line in its totals box, plus optional per-item tax columns, regardless of whether the issuing company was actually a registered VAT vendor. This mismatch — showing tax terminology on invoices from a company with no legal basis to charge tax — is what triggered the research documented here.

The registration thresholds themselves are not static: the 2026 Budget raised them from R1,000,000 (compulsory) / R50,000 (voluntary) to *R2,300,000 (compulsory) / R120,000 (voluntary)*, effective 1 April 2026. Since this research was conducted after that date, the higher thresholds are the ones that actually apply — TARCZA remains a non-vendor under either set of figures, but any future re-check of registration status should use the current numbers, not the historical ones that appear in older documentation or training data.

= Round 1 — May a non-vendor show a tax amount at all?

== The "vendor" concept and who may levy VAT

South Africa's Value-Added Tax Act 89 of 1991 ("the VAT Act") does not permit just anyone to charge VAT. Two provisions define the boundary:

- *Section 1 (Definitions)*: #quote["'vendor' means any person who is or is required to be registered under this Act."] A company that has neither registered nor become liable to register is, in the Act's own terms, simply not a vendor.
- *Section 7(1)(a) (Imposition of VAT)*: VAT is levied #quote["on the supply by any vendor of goods or services supplied by him ... in the course or furtherance of any enterprise carried on by him"], at 15%. The charging provision is scoped to vendors only — a non-vendor has no statutory mechanism to levy VAT on anything, regardless of intent.

== It is a criminal offence, not a labelling nicety

Section 58(1) of the VAT Act creates a direct offence for exactly this fact pattern:

#quote[
"Any person who—
(a) being an auctioneer or supplier of goods or services wilfully—
(i) declares to any person to whom goods or services are supplied by such auctioneer or supplier that tax has been included in, or will be added to, the price or amount chargeable in respect of such supply, where in fact no tax is payable in terms of this Act;
(ii) includes in, or adds to, the price or amount charged to the recipient in relation to such supply any tax, where in fact no tax is payable in terms of this Act; or
(iii) includes in, or adds to, the price or amount charged to the recipient in relation to such supply any tax in excess of the tax properly leviable under this Act in respect of the value of such supply; ...
is guilty of an offence and is liable, upon conviction, to a fine or to imprisonment for a period not exceeding two years."
]

Because a non-vendor has no VAT liability under s.7(1)(a) — "no tax is payable" for their supplies — *wilfully declaring on an invoice that tax is included or added, or actually adding a tax amount to the price, is a criminal offence under s.58(1)(a)(i)/(ii)*: a fine or up to two years' imprisonment. Two qualifiers matter:

- The offence requires *wilfulness*. A one-off accidental labelling slip likely would not meet the threshold — but a company that deliberately builds and ships a PDF invoice template with a permanent "VAT" line is not acting accidentally; that is wilful, repeated conduct by design.
- Sub-paragraph (i) criminalises the *declaration* that tax has been included or will be added — the representation itself, independent of whether money changes hands over it. Sub-paragraph (ii) separately criminalises actually adding the amount. Either one alone is sufficient for the offence to apply.

A cross-check against the Tax Administration Act 28 of 2011 (ss.234–235, general non-compliance and evasion offences) confirmed that while those broader provisions could theoretically also apply, VAT Act s.58(1)(a) is the specific, directly-applicable provision for this exact fact pattern and is the one the analysis rests on.

== "Tax invoice" is a restricted term, not loose commercial language

Section 20(1) of the Act ties the entire concept of a "tax invoice" to registered-vendor status: #quote["a supplier, being a registered vendor, making a taxable supply ... must within 21 days of the date of that supply issue a tax invoice containing such particulars as are specified in this section."] Section 20(4)(a) then lists the permitted title wording: #quote["The words 'tax invoice', 'VAT invoice' or 'invoice';"] — but this list only governs the document a *registered vendor* issues. Plain "Invoice" with no "tax" or "VAT" qualifier remains legally safe for anyone, vendor or not; it is specifically "tax invoice" and "VAT invoice" that are reserved terms.

== Zero-amount rows do not provide safe cover

A careful reading of s.58(1)(a) draws a real distinction between two cases:

- A line literally labelled *"VAT" showing a nonzero amount* unambiguously falls inside both s.58(1)(a)(i) (declaring tax is included or will be added) and (ii) (actually adding the amount) — clear offence exposure.
- A generic *"Tax" line showing R0,00* is meaningfully lower-risk, and arguably not an offence at all: an explicit zero states the opposite of "tax has been included or will be added," so it does not fit the statutory language cleanly.

Despite that technical distinction, the research concluded the R0,00 row is still bad practice: it invites customer confusion about whether the business is VAT-registered, and offers no benefit in exchange for that risk. The cleanest position — with no exposure to argument either way — is to *not render the row at all* rather than lean on the zero-amount technicality as a shield.

== No other South African tax justifies keeping a generic row

The research checked every category of tax or levy a small trading/services Pty Ltd could plausibly be exposed to, to see whether any of them would legitimately populate a "Tax" line on a customer invoice regardless of VAT status:

- *Turnover Tax* (Sixth Schedule, Income Tax Act) — paid by the business on its own turnover, never passed through to or itemised on customer invoices.
- *Excise duties / environmental levies* (plastic bag levy, CO2 emissions tax, tyre levy, electricity levy, Health Promotion Levy) — levied under the Customs and Excise Act 91 of 1964 on manufacturers and importers at source, folded into cost price as goods move down the supply chain. A reseller with no manufacturing/importing role has no mechanism to itemise these separately.
- *Withholding taxes* (non-resident withholding on royalties, interest, service fees) — apply to specific cross-border *outgoing* payments, not to ordinary domestic sales invoices.

Conclusion: for an ordinary small South African Pty Ltd that is neither importing/manufacturing excisable goods nor a Turnover Tax micro-business, *there is no other South African tax or levy that legitimately justifies a generic "Tax" line item on a customer invoice.* The only tax that ever belongs in that row is VAT, and VAT can only be charged by a registered vendor.

== Verdict — Round 1

*Hide the Tax/VAT row entirely when `company.vat_id` is empty — do not render it even at R0,00.*

1. Gate the whole tax block on `company.vat_id` being non-empty, not on the tax amount being non-zero.
2. Never label anything "Tax Invoice" or "VAT Invoice" on documents from a company with no `vat_id` — use plain "Invoice".
3. If/when TARCZA becomes VAT-registered (crosses the R2.3m compulsory or opts in above R120k voluntary threshold), reverse the condition: show "VAT", populate the VAT number field, and switch to "Tax Invoice"/"VAT Invoice" labelling per s.20(4)(a) — at that point the full s.20(4) particulars list and s.65 price-disclosure rules become mandatory too.
4. A live, nonzero "VAT" line on a real invoice from an unregistered company, shipped as a permanent template feature, is wilful conduct under s.58(1)(a) — exactly the exposure this fix removes.

= Round 2 — "Tax" or "VAT": what word should the label actually say?

Having settled *whether* a tax line may appear, the second round of research settled *what word* to use — for both the VAT-registered and non-VAT-registered cases.

== "Tax" is a defined term in the Act, and it means VAT specifically

The load-bearing fact this round turned on: *"tax" is not a generic placeholder in the VAT Act — it is a defined term.* Section 1 states plainly: #quote[`"tax" means the tax chargeable under this Act;`] Since the only tax chargeable under the VAT Act is VAT itself (s.7(1)(a)), *"tax" and "VAT" are legal synonyms throughout this statute.* This is why the Act's own drafting uses "tax" rather than "VAT" — s.20(4)(g)(i) requires stating "the amount of tax charged," and s.20(4)(a) lists "tax invoice" as one of three permitted titles. For a registered vendor, a bare "Tax" label is not, by itself, non-compliant — it satisfies s.20(4) exactly as well as "VAT" would, precisely because the Act has already defined "tax" to mean VAT and nothing else. This only works because South Africa has exactly one transaction-based consumption tax; in a jurisdiction stacking multiple transaction taxes, a bare "Tax" label would be genuinely ambiguous.

== SARS itself writes "VAT" the moment it addresses real people

Legal permissibility is a different question from convention. SARS's own *VAT 404 Guide for Vendors* (Issue 15, current to December 2024) — explicitly a plain-English operational guide, not a legal reference — drops the Act's internal "tax" shorthand the instant it needs to describe an actual rand amount. Its worked example of how VAT flows through a supply chain reads, in part:

#quote[
"A VAT registered paper manufacturer sells 2 rolls of uncoated print paper sheets to a VAT registered stationery distributor for R92,00 (including VAT of R12,00)... The selling price of each box of paper includes R1,05 VAT... The supermarket sells 15 of the 20 boxes to its customers for R11,50 each (inclusive of R1,50 VAT)."
]

Every rand figure in SARS's own official worked example is labelled "VAT" — never "Tax." The accompanying diagram labels running totals "Output Tax" and "Input Tax" (VAT-specific terms of art), reserving the bare word "tax" for the abstract statutory sense. Reading the Act and the Guide together: the Act's internal vocabulary treats "tax" and "VAT" as synonyms because it defined them that way, but the moment SARS writes for an actual audience, it reaches for "VAT" specifically. That is the clearest available signal of South African industry-standard practice.

== A bare "Tax" label is not South African convention

Three independent reasons converge on the same answer, even though "Tax" would pass legal muster for a registered vendor:

+ There is only one tax it could ever be — no other South African tax or levy is itemised on an ordinary invoice (Round 1's finding), so a generic label never disambiguates anything here. Contrast the United States, where "Sales Tax" genuinely needs to stay generic because the rate and authority vary by state, county, and city.
+ SARS's own plain-English material never uses it for real amounts (above).
+ SARS's official *Tax Invoice Checklist* frames its own criterion as "the amount of tax charged," but always inside a document whose own title and heading structure is "VALUE-ADDED TAX (VAT) INVOICES" — "tax" never stands alone as an undefined generic label the way an off-the-shelf accounting-software default does.

== Sharpening the non-vendor case

This round also searched the Act for a separate "holding out as a vendor" offence distinct from s.58 — a full-text search for "hold... out" / "represents... vendor" language returned nothing. Section 58(1)(a) is the entire universe of relevant offence exposure; there is no broader catch-all to separately worry about. It also established that *if* a non-vendor's system had to show some label despite the Round 1 verdict against doing so, "VAT" specifically would be the *worse* of the two choices, not the better one — it is the term SARS reserves for genuine chargeable amounts, so a non-vendor's row labelled "VAT" (even at zero) reads as a more direct claim to vendor status than a generic "Tax" row would.

== Verdict — Round 2

#table(
  columns: (auto, auto, auto, 1fr),
  inset: 8pt,
  stroke: 0.5pt + rgb("#cccccc"),
  fill: (col, row) => if row == 0 { rgb("#003366") } else if calc.odd(row) { rgb("#f2f6fa") } else { white },
  [#text(fill: white, weight: "bold")[Company state]], [#text(fill: white, weight: "bold")[Row shown?]], [#text(fill: white, weight: "bold")[Label if shown]], [#text(fill: white, weight: "bold")[Why]],
  [No `vat_id` (non-vendor)], [No — suppress entirely], [N/A], [s.58(1)(a) risk; no legitimate SA tax populates this row for a non-vendor; "VAT" is the worse of two bad labels if shown at all],
  [Has `vat_id` (registered vendor)], [Yes], [*"VAT"*, not "Tax"], [Not legally required over "Tax" (the Act treats them as synonyms), but is what SARS itself writes for real amounts, is the only transaction tax that could ever populate the row, and removes customer ambiguity at zero cost],
)

The developer's initial instinct — "change all references to TAX to VAT" applied uniformly across the invoice — was *rejected as stated*: correct for the VAT-registered branch, wrong (indeed backwards) for the non-vendor branch, where nothing should render at all. The verdict was implemented *gated on `company.vat_id`*: any hardcoded "Tax" string inside the vendor branch became "VAT"; the non-vendor branch was left untouched beyond what Round 1 already specified.

= Round 3 — What is "VAT" vs "Tax," conceptually?

The first two rounds settled whether a tax line may appear and what word to use. This final round steps back to answer a broader, more general question — useful for onboarding a future contributor, and for any future jurisdiction this codebase or a sibling project might target.

== The general/international distinction

"VAT" and "Tax" are not two names for the same thing at the conceptual level — they sit at different levels of abstraction. *VAT (Value-Added Tax)* is a specific, named mechanism: a multi-stage consumption tax charged at every step of a supply chain, where each VAT-registered business charges VAT on its sales ("output tax"), reclaims VAT paid on its own purchases ("input tax"), and remits only the difference. The mechanism is designed so the tax never compounds — only the final, non-registered consumer bears the full cost. VAT (or its Commonwealth-flavoured sibling GST, functionally identical, used in Australia, Canada, India, New Zealand) is used by more than 170 countries, including every EU member state, the UK, and South Africa.

*US-style sales tax is structurally different*, not a rebranding of the same idea: a single-stage tax charged only once, at the final retail sale. A manufacturer or distributor selling to another business in the chain charges no sales tax at all on that intermediate sale — the tax appears exactly once, at the register. There is no input-tax-credit mechanism, because nothing was paid further up the chain to credit. Rates and rules also vary by state, county, and city.

*"Tax" exists as a generic invoice-software field label because of this exact fragmentation.* Software built to serve customers across many countries cannot hardcode "Sales Tax" into a UI shown to a South African customer, or "VAT" into a UI shown to a Texan one. So it reaches for the most neutral possible word — a placeholder that can be relabelled per locale, or left generic when the jurisdiction is unknown. This is a legitimate design compromise *in a multi-jurisdiction product*. It stops being a legitimate compromise the moment a product, fork, or single-tenant deployment is scoped to one jurisdiction that has exactly one such tax — at that point the generic label has nothing left to disambiguate.

== Exhaustively checking South Africa for a second candidate tax

Round 1 had already ruled out Turnover Tax, excise/environmental levies, and withholding tax as ever appearing on a customer invoice. This round checked every remaining plausible South African tax type against SARS's own primary pages, one at a time, specifically asking whether each is ever a line item on a sales invoice issued *to a customer*:

#table(
  columns: (auto, 1fr),
  inset: 7pt,
  stroke: 0.5pt + rgb("#cccccc"),
  fill: (col, row) => if row == 0 { rgb("#003366") } else if calc.odd(row) { rgb("#f2f6fa") } else { white },
  [#text(fill: white, weight: "bold")[Tax / levy]], [#text(fill: white, weight: "bold")[Ever a line on a customer sales invoice?]],
  [PAYE (employees' tax)], [No — an employer↔employee payroll deduction. An employee is not a customer; a payslip is not an invoice.],
  [Withholding tax on royalties (non-residents)], [No — a deduction from a payment the SA business makes *outward* to a non-resident; direction is backwards from an invoice.],
  [Withholding tax on interest/dividends (non-residents)], [No — same outward-payment mechanism as royalties.],
  [Skills Development Levy], [No — 1% of the employer's own payroll, absorbed as overhead, no relationship to any sale.],
  [UIF contributions], [No — same payroll-deduction mechanism as PAYE.],
  [Customs duty / excise duty], [Generally no for an ordinary reseller — duty is folded into landed cost price before sale. One genuine exception: a customs clearing agent or freight forwarder may itemise duty as a disbursement line on *their own* invoice — inapplicable to TARCZA, which is not a clearing agent.],
  [Turnover Tax], [No — paid by the business on its own turnover, never passed through to a customer invoice.],
  [Provisional / income tax], [No — not even conceptually adjacent to an invoice; no transactional trigger exists at all.],
)

Every candidate is either a payroll deduction between employer and employee, a withholding deducted from an outward payment, a duty absorbed into cost price before the sale, or a tax paid on the business's own income with no per-transaction trigger. *None of them is ever a line item added onto a sales invoice issued to a customer.* This confirms, exhaustively rather than by assertion, that VAT is the only South African tax ever added as a percentage-of-sale-value line on an outgoing customer invoice.

== Why the statute and SARS's own guide use different words

This is a standard legislative drafting convention, not an inconsistency. A statute defines a term once, precisely (VAT Act s.1), and then reuses that short defined term throughout the rest of the document rather than repeating the full concept every time — the same reason contracts define "the Agreement" or "the Parties" once and use the short form afterward. The defined term is an internal cross-reference device for people reading the statute itself, not a communication choice aimed at ordinary vendors or customers.

SARS's public guidance, by contrast, is explicitly written for an audience that needs to recognise the tax by its actual, commonly-used name — a vendor working out how much to charge, a bookkeeper reconciling input and output tax. Using the Act's internal shorthand in a document meant for the public would force an unnecessary lookup ("which tax? oh, s.1 says 'tax' means VAT") for a reader who already knows which tax it is, because in South Africa there is only one.

*Practical implication:* match SARS's public-facing convention ("VAT") when writing for actual humans and customers — not the Act's internal drafting shorthand ("tax"). That the Act's shorthand is technically legal for a registered vendor is a fact about statutory compliance, not a style recommendation.

== Verdict — Round 3

"VAT" names a specific tax mechanism; "Tax" is a generic umbrella label that exists to serve jurisdictions where genuine ambiguity exists about *which* tax is meant. South Africa is not such a jurisdiction — VAT is its only transaction-based consumption tax ever itemised on a customer invoice, confirmed exhaustively against every other candidate tax type. A "Tax" amount on a South African invoice is therefore definitionally a VAT amount, and the label should say so.

This round was explicitly *confirmatory, not a further code change* — Rounds 1 and 2 had already produced the operative rules and the corresponding code changes (suppress the row with no `vat_id`; label it "VAT" when `vat_id` is present). This document exists to record *why* that logic is correct at a conceptual level, for onboarding and for reuse if TARCZA or a sibling project ever targets a second jurisdiction.

= What would flip these verdicts

All three rounds converge on the same set of conditions that would require re-running this research rather than just toggling a flag:

- *TARCZA crosses the VAT registration threshold* (currently R2.3m compulsory / R120k voluntary, effective 1 April 2026) — the entire `vat_id`-gated logic reverses direction: show "VAT," populate the VAT number, switch to "Tax Invoice"/"VAT Invoice" labelling, and the full s.20(4) particulars list and s.65 price-disclosure rules become mandatory.
- *South Africa introduces a second transaction-based consumption tax* stacked alongside VAT — at that point a bare label genuinely becomes ambiguous for the first time in the SA context, and the fix is a specific, named second line, not a reversion to a generic "Tax" catch-all.
- *TARCZA becomes a customs clearing agent or freight forwarder* itemising duty disbursements on client invoices — the one genuine exception identified in Round 3's table — requiring its own distinct label, not a merge into the VAT row.
- *TARCZA expands to serve customers or entities in a second country* — the single-jurisdiction assumption underlying the whole "Tax always means VAT here" argument no longer holds, and a genuinely jurisdiction-aware labelling scheme becomes necessary again.
- *SARS revises s.20(4)(a)* to drop "tax invoice" as a permitted title, or the s.1 definition of "tax" is amended to no longer mean VAT exclusively — either change would require re-reading Round 2's core finding.

= Code changes implemented as a result

Both compliance-driving rounds (1 and 2) translated directly into changes shipped to the live TARCZA invoice PDF template (`partials/table.blade.php` and the `TARCZA-A4-Portrait`/`TARCZA-A4-Landscape` Blade templates), verified against real rendered invoices before and after each change:

+ *Totals-box tax row* — wrapped in `@if(\$isVatRegistered)` where `\$isVatRegistered = (bool) \$invoice->company->vat_id`. Previously rendered unconditionally (even at R0,00); now does not render at all for a non-VAT-registered company, and reads "VAT" (not generic "Tax") when it does render.
+ *Per-item tax column* (shown only when the company's `tax_per_item` setting is enabled) — gated on the same `\$isVatRegistered` flag, closing the same exposure for the item-level breakdown, and relabelled "VAT" in the registered branch.
+ *Customer-side "Tax No:" field* — a related but distinct finding: the customer's tax reference number field (`tax_id`) is generic across the whole application (used for any tax identifier, not VAT-specific), unlike the company side where `tax_id` and `vat_id` are already separate columns. Rather than mislabel a generic field as a VAT number, a dedicated `vat_id` column was added to the `customers` table, wired through both the main customer form and the quick-add modal, synced through the `CustomerCompany` linking feature, and the Bill To block now shows "VAT No:" when a customer's `vat_id` is set, falling back to "Tax No:" otherwise.
+ *Header-height reservation* — the dompdf fixed-header sizing logic in `InvoiceService.php` was updated so the reserved space accounts for either the company's VAT/Tax line or the customer's VAT/Tax line being present, keeping the header band correctly sized regardless of which fields are populated.

None of the three research rounds found a reason to alter the underlying tax *calculation* logic — only what gets displayed, and under what conditions. The calculation engine (how `sub_total`, `tax`, and `total` relate under `tax_included`) was addressed separately, in an earlier round of work not covered by this document.

#v(1em)
#line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
#v(0.3em)
#text(size: 9pt, fill: rgb("#888888"))[
This document consolidates research, not legal advice. Every claim traces back to the VAT Act 89 of 1991 text or an official SARS publication, cited in full in the three source documents this file summarises (`docs/research/sa-vat-non-vendor-invoicing.md`, `docs/research/sa-invoice-tax-vs-vat-labeling.md`, `docs/research/vat-vs-tax-invoice-terminology.md`). It is not a substitute for sign-off from a registered tax practitioner or accountant before anything is filed with SARS or relied on in a dispute.
]
