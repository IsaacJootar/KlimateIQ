# Data License Agreement — Template (Sector #1: Parametric Insurance & Reinsurance)

Enterprise pivot, Module 5 (`docs/ENTERPRISE_BUILD_PLAN.md`) — the business-side counterpart to
the API feed shipped in this module. **This is a drafting starting point, not a finished legal
document.** Every bracketed field is a placeholder — fill in real parties, dates, and figures —
and have it reviewed by qualified legal counsel before either side signs anything. Nothing below
should be treated as legal advice.

The structure matches `ENTERPRISE_PIVOT_PLAN.md` §5's own description of this product: *"a licensed
read-only feed of Flood Risk / Riverine Flood Forecast / Drought Risk for a named set of LGAs...
an insurer's payout contract can reference."*

---

## Data License Agreement

**Between:** [Platform Operator Legal Name] ("Licensor")
**And:** [Client Legal Name] ("Licensee")
**Effective date:** [DATE]

### 1. Licensed data

Licensor grants Licensee a non-exclusive, non-transferable license to access, via the API
described in Exhibit A, the following indices for the Local Government Areas (LGAs) named in
Exhibit B:

- [ ] Flood Risk Index
- [ ] Riverine Flood Forecast
- [ ] Drought Risk Index
- [ ] [other index, if applicable]

The named LGAs and the active index set are enforced technically by a single API token scoped to
Licensee's own Portfolio (Exhibit B) — the token cannot read any other client's data, and Licensor
cannot expand what it covers without issuing a new token.

### 2. Permitted use

Licensee may use the licensed data to:
- Reference current and forecast risk levels when structuring or administering parametric
  insurance or reinsurance products covering the named LGAs.
- Incorporate the data, or values derived from it, into Licensee's own payout-trigger logic,
  underwriting models, or client-facing reporting.

Licensee may **not** resell, sublicense, or redistribute the raw feed to a third party outside its
own organization without Licensor's prior written consent.

### 3. Data accuracy and limitations

The licensed data is model-derived risk scoring, not a certified measurement or a guarantee of any
future event. [Platform Name]'s own validation status for each index — what it is calibrated
against, and where it is not yet calibrated — is documented at [link to validation
dossier/Module 10, once published] and incorporated by reference. Licensor makes no warranty that
any score will predict, or correlate with, any specific real-world loss event. Any use of this
data as a trigger for a financial payout is Licensee's own risk-transfer design decision, made
with its own actuarial and legal advice.

### 4. Delivery and availability

- **Endpoint:** `GET /api/v1/portfolios/{portfolio_id}/feed` (token-authenticated; see
  `docs/INGESTION_GUIDE.md`).
- **Format:** JSON, documented in Exhibit A.
- **Update frequency:** [match the underlying index's actual ingestion cadence — do not promise a
  frequency the pipeline doesn't deliver].
- **Support/SLA:** [fill in, if any is actually being offered].

### 5. Term and termination

[Term length], renewing [automatically / by written agreement] unless either party gives [notice
period] written notice. Licensor may suspend or revoke Licensee's token immediately for breach of
Section 2, with written notice following within [X] business days.

### 6. Fees

[Fee structure — flat fee, per-LGA, per-request, or other. This document intentionally leaves
pricing blank; it is a commercial decision, not something to pre-fill from a template.]

### 7. Confidentiality, liability, governing law

[Standard clauses — to be drafted by counsel. Not provided here.]

---

## Exhibit A — API contract

See `docs/INGESTION_GUIDE.md`, "Portfolio feed" section, for the full request/response shape.
Summary: one bearer token, scoped to exactly the Portfolio named in Exhibit B, returns that
portfolio's locations ranked by current risk, each with its score, risk band, and (for a
coordinate-only location) whether it was approximated against a nearby LGA or found to have no
LGA close enough to approximate from.

## Exhibit B — Licensed Portfolio

- **Portfolio name:** [as created in-app, e.g. "Acme Re — Lagos flood book"]
- **Portfolio ID:** [`portfolio_id`, shown on the portfolio's own page]
- **LGAs covered:** [list, or "see Portfolio" if the list is expected to change over the term —
  in which case, note here whether Licensee may add/remove LGAs unilaterally or only by amendment]
