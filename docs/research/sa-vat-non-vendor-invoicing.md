# Can a Non-VAT-Registered SA Company Show a "Tax"/"VAT" Line on an Invoice?

Research date: 2026-09-23. Scope: Value-Added Tax Act 89 of 1991 ("VAT Act"), Tax Administration Act 28 of 2011 ("TAA"), and SARS official guidance. Primary sources only; every claim below is cited to the statute section or SARS page it comes from.

Sources used (consolidated legislation text, current to 1 August 2021, cross-checked for later amendment notes embedded in the same text; no amendments to ss.7, 20, 58 or 65 postdate the cited versions):

- VAT Act 89 of 1991, consolidated text: https://disasterlaw.ifrc.org/sites/default/files/media/disaster_law/2021-10/89%20of%201991%20VALUE-ADDED%20TAX%20ACT_2021.08.01%20-%20to%20date.pdf (mirrors the official Act; official government copy at https://www.gov.za/documents/value-added-tax-act-12-may-2015-0846)
- SARS — Tax Invoices: https://www.sars.gov.za/businesses-and-employers/government/tax-invoices/
- SARS — VAT registration threshold change (2026 Budget): https://www.sars.gov.za/faq/what-is-the-new-threshold-for-vat-registration/
- Tax Administration Act 28 of 2011: https://www.saflii.org/za/legis/consol_act/taa2011215/

---

## 1. Who may levy VAT — the "vendor" concept

- **Definition of "vendor"** (VAT Act s.1): *"'vendor' means any person who is or is required to be registered under this Act."* A company that has not registered, and is not liable to register, is simply not a vendor.
- **Section 7(1)(a) — Imposition of VAT**: VAT is levied *"on the supply by any vendor of goods or services supplied by him ... in the course or furtherance of any enterprise carried on by him"*, at 15%. The charging provision is scoped to vendors only. A non-vendor has no statutory basis to levy VAT on anything, full stop.
- Registration thresholds (context, not central to the legal question): compulsory registration applies above R1 million in taxable supplies in a 12-month period, voluntary above R50,000 — **but these were increased in the 2026 Budget to R2.3 million (compulsory) and R120,000 (voluntary), effective 1 April 2026** (SARS threshold FAQ, above). Since today is 23 September 2026, the *current* thresholds are R2.3m/R120k, not the R1m/R50k figures in your brief — worth noting since TARCZA's turnover must now be compared against the new, higher bar (it still qualifies as non-VAT-registered on the facts given, just flagging the figures have moved).

## 2. Charging or representing an amount as tax when you're not a vendor is a criminal offence

**VAT Act s.58(1) — Offences:**

> "Any person who—
> (a) being an auctioneer or supplier of goods or services wilfully—
> (i) declares to any person to whom goods or services are supplied by such auctioneer or supplier that tax has been included in, or will be added to, the price or amount chargeable in respect of such supply, where in fact no tax is payable in terms of this Act;
> (ii) includes in, or adds to, the price or amount charged to the recipient in relation to such supply any tax, where in fact no tax is payable in terms of this Act; or
> (iii) includes in, or adds to, the price or amount charged to the recipient in relation to such supply any tax in excess of the tax properly leviable under this Act in respect of the value of such supply; or
> (b) wilfully fails to comply with the provisions of paragraph (i) of the proviso to section 20(1) or item (A) of the proviso to section 21(3),
> is guilty of an offence and is liable, upon conviction, to a fine or to imprisonment for a period not exceeding two years."

This is squarely on point. Because a non-vendor has no VAT liability under s.7(1)(a) ("no tax is payable" for their supplies), **wilfully declaring on an invoice that tax is included/added, or actually adding a tax amount to the price, is a criminal offence under s.58(1)(a)(i)/(ii)** — fine or up to 2 years' imprisonment.

Two important qualifiers, both read directly off the text:

- The offence requires **wilfulness** ("wilfully"). A one-off accidental labelling slip probably wouldn't meet the mens rea threshold — but a company that *deliberately builds and ships a PDF invoice template* with a live "VAT" line is not in "accidental" territory; that is a wilful, repeated act by design.
- Sub-paragraph (i) criminalises the *declaration* that tax "has been included in, or will be added to" the price — i.e. the representation itself, independent of whether money actually changes hands over it. Sub-paragraph (ii) separately criminalises actually adding a tax amount to the charged price. **Either one alone is enough.**

**Section 58(2)(b)** separately criminalises wilful *or negligent* contravention of **s.65** (prices advertised/quoted must include tax, discussed below) — same penalty (fine or up to 2 years). Section 65 only binds vendors, so it isn't the operative risk for TARCZA, but it shows the Act treats tax-representation and tax-quoting rules as parallel, equally-serious criminal matters, not administrative footnotes.

**Cross-check against the Tax Administration Act**: TAA ss.234–235 create general offences for non-compliance (s.234, up to 2 years, includes things like failing to register, negligent or wilful) and tax evasion (s.235, requires "intent to evade", up to 5 years). These are broader, general-purpose offence provisions that *could* also be engaged (e.g. if unregistered VAT charging were framed as evasion), but **VAT Act s.58(1)(a) is the specific, directly-applicable provision** for the exact fact pattern here (a non-vendor showing/charging a tax amount) and is the one to rely on for the analysis.

## 3. "Tax invoice" is a term of art restricted to registered vendors

**Section 20(1):** *"a supplier, **being a registered vendor**, making a taxable supply ... must within 21 days of the date of that supply issue a tax invoice containing such particulars as are specified in this section."*

**Section 20(4)(a):** the required content list starts with *"The words 'tax invoice', 'VAT invoice' or 'invoice'"* — but this list only applies to the s.20 document a **registered vendor** issues. The word "invoice" appearing as one of the three permitted labels doesn't loosen the restriction; it's simply the plainest of three vendor-only label options.

**Conclusion:** "Tax invoice" and "VAT invoice" are not loose commercial terms in SA law — they are defined by reference to a registered vendor issuing the document. A non-vendor's billing document is not a "tax invoice" in the statutory sense and should not be labelled as one. In casual business usage people do call any bill an "invoice" loosely, and that plain word ("invoice", full stop, with no "tax" or "VAT" qualifier) is fine for anyone — it's only "tax invoice"/"VAT invoice" that is reserved.

## 4. R0 "Tax" line vs nonzero "VAT" line — is there a legal distinction?

Reasoning from the exact wording of s.58(1)(a):

- **(a) A line literally labelled "VAT" with a nonzero amount** — this unambiguously falls inside s.58(1)(a)(i) (declaring tax is included/added) *and* (ii) (actually adding the amount). Clear offence exposure if the label is "VAT" specifically, because "VAT" only has one meaning: value-added tax under this Act, chargeable only by vendors (s.7(1)(a)).
- **(b) A generic "Tax" line showing R0,00** — this is meaningfully different and **legally lower-risk, arguably not an offence at all**: s.58(1)(a)(i) criminalises declaring that tax "has been included in, or will be added to" the price — an explicit R0,00 figure states the opposite (nothing is added), so it doesn't fit the statutory language of the offence. It also adds nothing to the price, so (ii) doesn't bite either. That said, a **visible "Tax"/"VAT" row at R0,00 is still bad practice**: it invites customer confusion about whether the business is VAT-registered, and if the label is "VAT" (rather than a fully generic "Tax") rather than blank/absent, it edges back toward "declaring" something about VAT status even at zero value. The cleanest, zero-ambiguity position — and the one with no exposure to argument either way — is to **not render the row at all** when there's no VAT number, rather than rely on the R0 distinction as a shield.

## 5. Are there other SA taxes/levies that would justify a generic "Tax" line regardless of VAT status?

Checked against the categories of tax/levy a small trading or services Pty Ltd could plausibly be exposed to:

- **Turnover Tax** (Sixth Schedule, Income Tax Act, for qualifying micro businesses) — this is a tax the *business* pays to SARS on its own turnover; it is not passed through to, or itemised on, customer invoices at all. Structurally irrelevant to invoice templates.
- **Excise duties / environmental levies** (plastic bag levy, CO2 vehicle emissions tax, tyre levy, electricity levy, Health Promotion Levy/"sugar tax") — these are levied under the Customs and Excise Act 91 of 1964 on **manufacturers and importers at source**, and get folded into the cost price as goods move down the supply chain. A reseller or services company (not itself manufacturing/importing the excisable goods) has no obligation — and generally no mechanism — to itemise these separately on a customer invoice.
- **Withholding taxes** (e.g. non-resident withholding tax on royalties/interest/service fees) — apply to specific cross-border payment types, not to ordinary domestic sales invoices, and would appear (if ever) as a deduction context, not a "Tax added" line.

**Conclusion:** For an ordinary small SA Pty Ltd that is not itself importing/manufacturing excisable goods and is not a Turnover Tax micro-business passing tax through to customers, **there is no other South African tax or levy that legitimately justifies a generic "Tax" line item on a customer invoice.** The only tax that ever belongs in that row is VAT, and VAT can only be charged by a registered vendor. There is no legal reason to preserve a generic non-VAT "Tax" row in the template "just in case."

## 6. What must a non-VAT-registered vendor's invoice actually say?

- **Must not** use the words "tax invoice" or "VAT invoice" — both are restricted terms under s.20(1)/(4)(a) tied to registered-vendor status (see §3).
- **Must not** show a VAT number field (there isn't one to show), and per the analysis in §2/§4, should not show any nonzero "Tax"/"VAT" amount.
- **No statutory wording requirement was found** (checked SARS's Tax Invoices page and the VAT Act itself) mandating specific text like "VAT not applicable" on a non-vendor's invoice — that phrase is a common accounting-industry convention for clarity with customers, not a SARS-mandated form of words. It's good practice, not a legal must.
- Plain "Invoice" (no "tax"/"VAT" qualifier) is legally safe for anyone to use, vendor or not.

---

## Verdict for TARCZA

**Hide the Tax/VAT row entirely when `company.vat_id` is empty — do not render it even at R0,00.** Specifically:

1. **Gate the whole tax block on `company.vat_id` being non-empty**, not on the tax amount being non-zero. Rendering a "Tax: R0,00" row for a non-VAT-registered company is not itself the clear statutory offence (that requires declaring tax is included/added, per s.58(1)(a)(i), which an explicit zero contradicts) — but it's unnecessary customer-facing noise that implies VAT-registered status, and TARCZA gets zero benefit from keeping it. There's no other legitimate SA tax (§5) that would ever populate that row for TARCZA, so there's nothing to preserve it for.
2. **Never label anything "Tax Invoice" or "VAT Invoice" on documents from a company with no `vat_id`** — use plain "Invoice". This is restricted terminology under VAT Act s.20(1)/(4)(a), not a stylistic choice (§3).
3. **If/when TARCZA becomes VAT-registered** (crosses the now-R2.3m compulsory or opts in above R120k voluntary — §1), reverse the condition: show "VAT" (not generic "Tax"), populate the VAT number field, and switch labelling to "Tax Invoice"/"VAT Invoice" per s.20(4)(a) — at that point s.65 (prices must include/disclose tax) and the full s.20(4) particulars list become mandatory, so the per-item tax breakdown logic should also key off the same `vat_id` presence check.
4. **Bottom line on the actual offence risk**: a live, nonzero "VAT" line on a real invoice from an unregistered company would be a textbook s.58(1)(a) offence (fine or up to 2 years' imprisonment) if done wilfully — and shipping it as a permanent template feature is wilful, not accidental. This is exactly the scenario TARCZA's owner was right to be cautious about; the fix (condition the whole block on `vat_id`) removes the exposure completely.
