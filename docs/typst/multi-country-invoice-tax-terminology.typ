// Multi-Country Invoice Tax Terminology — Design foundation for TARCZA / InvoiceShelf fork
// Generated: 2026-09-23

#set page(
  paper: "a4",
  margin: (top: 2cm, bottom: 2.2cm, left: 2.2cm, right: 2.2cm),
  header: [
    #set text(size: 8pt, fill: rgb("#888888"))
    #grid(columns: (1fr, 1fr),
      [Multi-Country Tax Terminology Research],
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
    #text(fill: white, weight: "bold", size: 18pt)[Multi-Country Invoice Tax Terminology]
    #v(0.3em)
    #text(fill: rgb("#aaccff"), size: 11pt)[Design and legal research foundation — no code changed]
  ]
  #v(0.5em)
]

#block(fill: rgb("#fff4e5"), inset: 10pt, radius: 3pt, width: 100%, stroke: 0.5pt + rgb("#e0a800"))[
  #text(weight: "bold")[Status: research only, nothing implemented.] This document extends the South-Africa-only research already shipped to the live TARCZA invoice template (see the companion document *SA VAT Invoicing Research*, `docs/typst/sa-vat-invoicing-research.pdf`). The existing `vat_id`-gated SA logic in `InvoiceService.php` and the Blade templates is untouched and continues generating real invoices exactly as before. Nothing here should be acted on in code until reviewed and, for any non-South-African jurisdiction, signed off by a locally-qualified tax advisor.
]

This document asks whether the invoice's tax terminology should become dynamic based on country — both the issuing company's own registered country and the customer's country — and if so, how. It surveys how VAT/GST systems actually work across eight jurisdictions, inspects this codebase's current data model for what such a feature would require, and recommends a scope for if and when this is ever built.

= Background

South Africa's VAT Act 89 of 1991 restricts who may charge or display tax on an invoice to registered vendors (s.58, criminal offence for a non-vendor; s.20, "tax invoice"/"VAT invoice" wording restricted to registered vendors) — already researched in depth and shipped as the current `vat_id`-gated logic. The natural next question, once TARCZA might ever deal with a non-South-African company or customer: does the same *kind* of restriction exist elsewhere, and does the tax *name* itself ("VAT," "GST," "Sales Tax") need to change based on country? This document answers both, and adds a third question the developer specifically raised: does the *customer's* country matter too, or only the issuing company's?

= Whose country's law actually governs the invoice?

This is the foundational design question — it decides whether "customer's country" changes the tax rate and label the way "issuer's country" does, or only informs something narrower.

== Domestic sales: origin/supplier-registration based, everywhere checked

Every VAT/GST system researched — the EU, UK, Australia, New Zealand, Canada, India, and South Africa from the prior research — works the same way for an ordinary domestic sale: *the supplier's own registration status and country determine what tax regime applies and what may legally be shown on the invoice.* The customer's location is irrelevant to a purely domestic transaction. This mirrors South Africa's own VAT Act s.7(1)(a)/s.20(1) pattern exactly: the charging and invoicing obligations attach to "a supplier, being a registered vendor" — never to the customer's status, for a domestic supply. This is not a coincidence particular to South Africa; it is how VAT/GST is built almost everywhere.

== Cross-border supplies: where the customer's country starts to matter — as a switch, not a new rate table

The OECD's *International VAT/GST Guidelines* (a Council Recommendation adopted September 2016) name the governing mechanism directly:

#quote[
"the application of the destination principle in VAT achieves neutrality in international trade. Under the destination principle, exports are not subject to tax with refund of input taxes (that is, 'free of VAT' or 'zero-rated') and imports are taxed on the same basis and at the same rates as domestic supplies."
]

Two concrete mechanisms implement this, confirmed directly from the EU VAT Directive's own text (Council Directive 2006/112/EC):

- *Zero-rating exports* — the exporting country's supplier still charges VAT, just at 0%, and can still reclaim input VAT. South Africa's own VAT Act does the same for exports (s.11).
- *Reverse charge* — for many cross-border B2B services, the supplier charges no VAT at all and the customer self-accounts for it in their own country instead. Article 196 of the EU VAT Directive: #quote["VAT shall be payable by any taxable person... to whom the services referred to in Article 44 are supplied, if the services are supplied by a taxable person not established within the territory of the Member State."] The invoice from the foreign supplier shows no VAT amount, typically annotated "reverse charge."

A third mechanism cuts the other way and matters for template design specifically: *Article 203* of the same Directive makes VAT strictly payable by #quote["any person who enters the VAT on an invoice"] — regardless of entitlement. This is a different enforcement route from South Africa's criminal s.58 (a debt automatically owed to the tax authority, rather than a criminal charge), but it delivers the same practical warning: showing a tax amount you were not entitled to show has real legal consequences everywhere checked, just via different mechanisms.

== Answer

*Customer's country does not, by itself, change what tax rate or label a domestic-registered supplier's invoice should show for a domestic sale.* It only becomes relevant when the supply itself is cross-border, and even then, in both dominant worldwide patterns, it does not change the *word* used for the tax line — it changes whether an amount appears at all, and may add an explanatory note ("Zero-rated export," "Reverse charge — VAT to be accounted for by the recipient"). *The issuing company's own country and registration status remain the primary driver of the tax label and whether the block renders at all; the customer's country is, at most, a secondary flag — never an independent second axis that picks its own label.*

= Reference table of major tax systems

Each entry cites that jurisdiction's own official tax-authority source or statute text. Where a claim could not be confirmed from a primary source within this research's scope, that gap is stated explicitly rather than guessed — the same discipline the prior South African research applied.

#table(
  columns: (auto, auto, 1fr, 1fr),
  inset: 7pt,
  stroke: 0.5pt + rgb("#cccccc"),
  fill: (col, row) => if row == 0 { rgb("#003366") } else if calc.odd(row) { rgb("#f2f6fa") } else { white },
  [#text(fill: white, weight: "bold")[Jurisdiction]], [#text(fill: white, weight: "bold")[Tax name]], [#text(fill: white, weight: "bold")[Mechanism]], [#text(fill: white, weight: "bold")[Non-registered person restricted from charging/showing tax?]],
  [South Africa #footnote[Baseline; prior research, already shipped.]], [VAT], [Multi-stage, input/output credit], [*Yes* — VAT Act s.58(1)(a) (criminal), s.20(1) (restricted wording)],
  [United Kingdom], [VAT], [Multi-stage, input/output credit], [*Yes* — Finance Act 2008 Sch.41 para.2, civil penalty up to 100% of lost revenue (replaced the old criminal s.67 in 2010)],
  [European Union #footnote[Treated as one representative entry — Directive-level harmonization, but each of 27 member states administers its own registration; not checked per state.]], [VAT], [Multi-stage, input/output credit], [*Partial, different mechanism* — Art.203 strict liability (anyone who enters VAT on an invoice owes it), not a criminal prohibition; per-member-state criminalisation not checked],
  [United States], [Sales tax — no federal VAT/GST at all], [Single-stage, no input credit; state/county/city level], [*Confirmed for California only* — R&TC s.6071 (misdemeanour to trade without a seller's permit); not checked for other 44 states],
  [Australia], [GST], [Multi-stage, input/output credit], [*Yes* — GST Act 1999 s.29-70 + ATO guidance: non-registered businesses "should not include the words 'tax invoice'"],
  [New Zealand], [GST], [Multi-stage, input/output credit], [*Yes* — registration-gated; term changed from "tax invoice" to "taxable supply information" 1 April 2023, but obligation still attaches only to registered suppliers],
  [Canada], [GST/HST], [Multi-stage, input/output credit; HST = federal+provincial harmonized rate], [*Not confirmed* — Excise Tax Act s.221 ties collection duty to "every person who makes a taxable supply," no specific "unauthorised invoice" offence located within scope],
  [India], [GST (CGST/SGST/IGST split)], [Multi-stage, input/output credit], [*Yes* — CGST Act 2017 s.32: a non-registered person "shall not collect... any amount by way of tax"],
  [Kuwait / Qatar], [None], [N/A — no general consumption tax], [N/A — no registration regime exists; current non-VAT status *not confirmed against a primary government source*, rests on converging secondary sources only],
)

== Notable per-jurisdiction detail

*United Kingdom* — the original criminal offence (VAT Act 1994 s.67) was repealed in 2010 and replaced with the current civil-penalty regime (Finance Act 2008 Sch.41). A different character of enforcement to South Africa's criminal approach, but the same design constraint: an unregistered UK business must not show a VAT amount.

*United States* — structurally the odd one out, and the reason a single "country" setting cannot fully capture US tax treatment even in principle. There is no federal consumption tax of any kind. Sales tax is levied by individual states (45 of 50 plus DC; Oregon, Montana, New Hampshire, Delaware, and Alaska have none at the state level), layered further by counties and cities. Since *South Dakota v. Wayfair, Inc.* (2018), a business can owe sales-tax collection obligations in a state from economic activity alone (revenue/transaction thresholds), no physical presence required. The correct unit for "who must register, under what rules" in the US is the state or locality, not the country.

*India* — GST splits into CGST (Central), SGST (State, for intra-state supplies), and IGST (Integrated, for inter-state and import supplies) — which combination applies depends on whether the supplier and customer are in the same Indian state, a domestic cross-state case that sits between "purely domestic" and the international cross-border case discussed above, resolved entirely within Indian law.

*New Zealand* — a useful data point for template design generally: IRD's own guidance confirms a business may continue labelling documents "tax invoice" by convention even though the Act's operative legal term changed to "taxable supply information" in 2023. Legal terminology and conventional/UI terminology can diverge within a single jurisdiction, and the tax authority itself treats that as fine.

*Kuwait / Qatar* — of the six GCC states that signed the 2017 GCC VAT Framework Agreement, four have implemented VAT (UAE and Saudi Arabia in 2018, Bahrain in 2019, Oman in 2021); Kuwait and Qatar have not, as of this research date. A genuinely current example of a "no general consumption tax" jurisdiction — but flagged as the one entry in this table not confirmed against a primary government source, since there is no "we have no VAT" statute to cite; the absence itself is the fact being checked.

= What this would require in the data model <data-model-section>

The codebase was inspected directly for this section — `Company`, `Customer`, `Address`, and `Country` models, plus the migrations that added the `vat_id`/`tax_id` columns.

== Current state

Neither `Company` nor `Customer` has a `country` column of its own today. Country exists only indirectly, through the `Address` model (`Company hasOne(Address)`, `Customer hasMany(Address)`, `Address belongsTo(Country)`) — an optional, nullable relation, not a guaranteed always-populated attribute. "The company's country" today is only reachable as `$company->address->country`, which can be entirely absent. The existing SA tax-gating logic (`if ($invoice->company->vat_id)`) does not reference country at all — a pure presence check, safe only because the deployment is single-tenant and single-jurisdiction by assumption, not because the code enforces that assumption anywhere.

== What a country-aware design would need to add

+ *A reliable, non-optional "company's own tax jurisdiction" field.* Promoting country to a first-class, required attribute directly on `Company` (simplest option), rather than relying on an optional address relation that could be entirely missing.
+ *"Registered for VAT/GST/tax in country X" as a concept distinct from "has an address in country X."* A UK company can be UK-VAT-registered and still deal with EU customers under different rules than an EU-resident company would face. Today `vat_id` is a single free-text string on both `Company` and `Customer`, with no field recording *which* jurisdiction issued it — a UK VAT number and a South African VAT number are indistinguishable in the schema. Country-aware logic would need either a paired `vat_country` field, or format-derived detection where the number format is unambiguous (SA numbers are 10 digits; UK numbers are `GB` + 9 or 12 digits; EU numbers carry a 2-letter country prefix), with an explicit fallback where it isn't.
+ *A per-jurisdiction rule table, not hardcoded per-jurisdiction template logic.* The current template hardcodes the SA-specific rule directly into the Blade templates and `InvoiceService.php`. A multi-country version needs this externalised into a small lookup structure per supported country: the tax name to display, whether an unregistered issuer may show any tax row at all, which document-title words are restricted, and whether the customer's country ever adds a cross-border note. This is a rules-engine-shaped problem, materially bigger than the current single boolean check.
+ *The customer country's role stays narrower*, per the finding above — it should feed a cross-border flag/note, not select its own independent label. It mainly requires knowing reliably whether the customer's country differs from the company's, plus (for reverse-charge cases specifically) the customer's own VAT/GST number, since EU-style reverse-charge invoices typically state it as part of the "why no VAT was charged" justification.

= Realistic scope recommendation

Full shipping-grade legal research, to the standard already applied to South Africa, for every jurisdiction in the world is not feasible — and attempting to auto-generate confident legal claims for a country nobody actually checked is worse than not supporting that country at all. It would produce a template that *looks* authoritative for jurisdictions where the underlying legal claim was never verified.

#block(fill: rgb("#f2f6fa"), inset: 10pt, radius: 3pt, width: 100%)[
*Recommended approach: a small, explicit allowlist of researched jurisdictions, with a safe fallback for everything else.*

+ *Allowlist scope* — South Africa only (already shipped), until TARCZA has real, current business in a second jurisdiction. That jurisdiction gets its own dedicated research round, held to the same primary-source bar as this document, before any code changes ship for it. The eight jurisdictions surveyed above are a starting menu for future rounds, not a batch ready to implement — several (Canada, the EU as a bloc, the US) have explicitly-flagged open gaps that would need closing first.
+ *Fallback for anything outside the allowlist* — render no tax line at all, and use no jurisdiction-specific label. This mirrors the prior South African non-vendor verdict: suppressing the row carries no legal exposure, while guessing a label or rule for an unresearched jurisdiction risks exactly the overclaiming problem this whole line of research exists to prevent. A generic, unconditional "Tax" fallback was considered and rejected for the same reason — it doesn't disambiguate anything for an unresearched jurisdiction, it just looks confident without being backed by anything.
+ *Trigger for adding a jurisdiction* — a real, current or firmly planned TARCZA customer or company registration there, not speculative "might expand there someday" scope creep.
]

= Verdict

*(a) Direct answer.* Yes, invoice tax terminology should eventually become country-aware if TARCZA ever operates in or bills customers in more than one jurisdiction — but the driver should overwhelmingly be the *issuing company's own registered country and registration status*, not the customer's country. Every jurisdiction checked works origin/supplier-registration-based for domestic sales, with the customer's country only entering the picture for genuinely cross-border supplies, and even then only as a zero-rate/reverse-charge flag — never as an independent selector of its own label.

*(b) No code should change yet.* This document is a design and legal research foundation only. The current South-Africa-only `vat_id`-gated logic is untouched and should stay exactly as shipped — TARCZA's real South African invoices continue on the existing, already-verified path.

*(c) Recommended scope.* South Africa only, until a second jurisdiction is researched to this same standard. No tax line at all — not a generic "Tax" label — for anything outside the researched allowlist.

*(d) Further work before shipping any non-SA jurisdiction:*

- Close the explicitly-flagged research gaps: Canada's offence-provision search was not exhaustive, the EU entry did not check any individual member state's national transposition, and the US entry checked exactly one state out of dozens with a sales tax.
- Build the data-model prerequisites in @data-model-section — without a reliable company/customer country field and a way to know which country issued a given `vat_id`, country-aware logic has nothing real to key off.
- *Regardless of how thorough this document is, an internationally-qualified tax advisor's sign-off is required before shipping tax-display logic for any jurisdiction outside South Africa.* This document necessarily spreads far thinner across eight jurisdictions than the deep, single-statute South African research it extends, and explicitly flags several claims as unconfirmed or partially confirmed.

#v(1em)
#line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
#v(0.3em)
#text(size: 9pt, fill: rgb("#888888"))[
Full source citations (statute sections, official tax-authority URLs) for every jurisdiction-specific claim in this document are recorded in the source file this consolidates: `docs/research/multi-country-invoice-tax-terminology.md`. This is not legal advice. Nothing in this document should be relied on to make an actual invoicing decision for a non-South-African entity without a locally-qualified tax practitioner reviewing it first — and, for South Africa specifically, a registered SA tax practitioner, per the companion South African research this document builds on.
]
