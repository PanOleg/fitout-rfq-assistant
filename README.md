# Fit-out RFQ Assistant

[![ci](https://github.com/PanOleg/fitout-rfq-assistant/actions/workflows/ci.yml/badge.svg)](https://github.com/PanOleg/fitout-rfq-assistant/actions/workflows/ci.yml)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20)
![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

A main contractor on a UK office fit-out receives the scope as a bill of quantities,
a few pages of Employer's Requirements, or an email from site. Before anything can
be priced, someone splits it into trade packages and sends each package to three
subcontractors. This service does that first pass:

```
PDF / XLSX / CSV / text ──► text ──► Claude extracts line items (in pieces if long)
    ──► every item checked against the document ──► grouped into trade packages
    ──► bidders shortlisted ──► RFQ drafts
```

The model reads. Deterministic code checks what it read, groups it, picks who to
invite, and writes the numbers into the RFQ.

## Design decisions

**Schema-constrained output, then grounding.** Extraction uses Claude's structured
outputs (`output_config.format = json_schema`), so malformed JSON can't happen. That only
guarantees shape. In a tender the costly failures are an item that isn't in the document and a
quantity that was "tidied up". Every item must quote the source verbatim, and
[`GroundingValidator`](src/Extraction/GroundingValidator.php) checks:

- the quote exists in the document (ignoring whitespace, case and typographic quotes);
- the quantity is *the* quantity the line states, not just any number in it. Numbers are
  classified first: carrying a unit (`312 m2`, `410sqm`, `6no`), part of a dimension
  (`600x600`, `3.0 x 2.5 m`), a rate (`420 m2 per floor`), or part of a reference, range or
  price (`K10/120`, `levels 1-3`, `£5,000`). When a line writes a unit, only a number carrying
  one can be the quantity;
- the unit written next to that number is the item's unit (`96 m` cannot become `96 m2`).

What is **not** checked: the trade and the description. Those are the model's judgement and
are only measured by the evals. A unit test runs every golden-case expectation through the
validator, so tightening a check can't start rejecting what the evals call correct.

**Files in, text through.** PDFs (text layer), XLSX and CSV are converted to text
deterministically ([`DocumentText`](src/Ingestion/DocumentText.php)); a spreadsheet row becomes
one tab-separated line with empty columns kept, so Qty stays next to Unit. The pipeline after
that is unchanged, so every quote is still checked against what the file says. Sending the PDF
to Claude as a document block was the obvious alternative and was rejected: it drops the
quote check, and citations — the API's own grounding — can't be combined with structured
outputs (the API returns a 400). A PDF with no text layer is refused with a reason rather
than guessed at.

**Long documents are read in pieces.** Over `rfq.llm.chunk_chars` (20k characters) a document
is split between paragraphs, then lines — never overlapping, so each item is read once
([`ChunkedLineItemExtractor`](src/Extraction/ChunkedLineItemExtractor.php)). A piece whose
answer still hits `max_tokens` is halved at a line break and read again; only a small piece
that still overflows fails the tender.

**A bounded repair loop, with no silent drops.** Items that fail validation go back to the
model with the concrete reasons ([`LlmLineItemExtractor`](src/Extraction/LlmLineItemExtractor.php)).
After two rounds, anything still failing is returned as `rejected` alongside the model's own
`warnings`. The API shows both under `needs_review`. A missing item is caught at tender
review. A wrong one gets priced and built.

**LLM only where it earns its place.** Supplier shortlisting is a sort order: trade, region,
rating, specialist before generalist, over the `suppliers` table. RFQ text comes from a
template. Both have to be explainable and exact, and neither needs a model.

**Evals are part of the codebase.** `evals/cases/` holds five fictional documents in the
shapes estimators get: a tabular BoQ, prose specification, a site email, an M&E schedule, and
a file of traps (totals, exclusions, provisional sums, quantities that would need
calculating). `php artisan rfq:eval` scores precision and recall and checks required
warnings, with tokens, cost and latency per case. Given several `--model` / `--effort`
values it runs the grid and writes a comparison table to `evals/results/`, which is
committed with the decision it supports.

**Cost is visible and cacheable.** Each extraction records model, prompt version, attempts,
token usage and USD cost. Identical documents under the same prompt version and model hit a
cache instead of the API. The prompt version is part of the key, so editing the prompt
invalidates the cache by construction.

**Failures are sorted by what can be done about them.** Rate limits, overload and network
errors raise `LlmUnavailable`, and the queued job retries with backoff. A refusal or a
truncated answer fails the tender immediately, because retrying the same request won't help.
Server-side refusal fallback is enabled. Uploads are idempotent on the `Idempotency-Key`
header, including under concurrent requests; the key is bound to the request it was first
used with, so reusing it for a different document is a 422, not the old tender.

## Layout

```
src/                      framework-free; layer rules enforced by Deptrac
  Domain/                 Trade, Unit, Quantity, LineItem, WorkPackage, Supplier
  Llm/                    LlmClient port, Anthropic adapter, usage and pricing
  Ingestion/              PDF / XLSX / CSV → text
  Extraction/             prompt + schema, grounding, repair loop, chunking, cache
  Suppliers/              directory port, shortlist rules
  Rfq/                    RFQ draft composer
  Evals/                  golden-case loader and scorer
app/                      Laravel: HTTP, queue job, Eloquent, composition root
evals/cases/              golden documents and expected items
evals/results/            committed model × effort comparison tables
database/data/            fictional demo suppliers (seeded)
```

## Running it

Requires PHP 8.4 and Composer. It uses SQLite, so there is nothing else to install.

```bash
composer setup
composer check          # Pint → PHPStan L8 → Deptrac → PHPUnit (no API key needed)
```

The test suite never calls the API. The Anthropic adapter is tested against canned HTTP
responses, and everything above it uses a scripted `LlmClient`.

To run against Claude, set `ANTHROPIC_API_KEY` in `.env`, then:

```bash
php artisan serve &
php artisan queue:work &

curl -s localhost:8000/api/tenders -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-1' \
  -d "$(jq -n --rawfile doc evals/cases/01-tabular-boq.txt \
        '{name: "25 Harbour Lane, 4th floor", region: "london", return_by: "2026-12-01", document: $doc}')"

# or upload the file itself (PDF with a text layer, XLSX, CSV, TXT)
curl -s localhost:8000/api/tenders -H 'Accept: application/json' \
  -F name='25 Harbour Lane' -F region=london -F return_by=2026-12-01 -F file=@boq.xlsx

curl -s localhost:8000/api/tenders/{id}         # packages, needs_review, cost
curl -s localhost:8000/api/tenders/{id}/rfqs    # one draft per package, with recipients

php artisan rfq:eval                            # live eval run; costs a few cents
php artisan rfq:eval --model=claude-opus-5 --model=claude-sonnet-5 \
                     --effort=low --effort=medium --effort=high   # the grid, 30 extractions
```

## Measured results

None yet — and that matters. The default (`claude-opus-5` at `medium` effort) is a starting
point, not a measured choice: extraction is closer to reading than reasoning, so a cheaper
model or lower effort may hold the same quality. The grid above is how that gets decided;
its table goes in `evals/results/` and the default in `config/rfq.php` changes only with it.
Five fictional cases are also too few to separate close settings — the first real documents
should become cases before the numbers are trusted.

## What breaks first

Known weaknesses, roughly in the order a real tender would hit them:

1. **Scans and drawings.** A PDF without a text layer is refused. Most real BoQs are
   generated PDFs or spreadsheets, but site mark-ups and older bills are scans; that needs OCR
   and a different grounding story (checking against OCR text is checking against a guess).
2. **Context lost between pieces.** A long document is split without overlap. A section
   heading ("PARTITIONS") or a table header whose units apply to rows further down is not
   seen by the next piece, so trade classification there is weaker and bare-number
   quantities fall back to the looser check.
3. **Trade and description are not grounded.** The validator proves the quote and the
   quantity; it cannot prove that "door and frame, including decoration" belongs to doors.
   Misclassification only shows up in evals and at review.
4. **PDF layout.** Text extraction follows the PDF's drawing order. Multi-column layouts and
   wrapped table cells can interleave, so a quantity can end up on another item's line. The
   quote check verifies against that extracted text, so it cannot tell a faithful quote of a
   scrambled line from a correct one — a wrong description/quantity pairing can pass.
5. **Conventions the checks do not know.** Quantities in words ("two doorsets"), imperial
   units, and unusual unit spellings are rejected, not guessed. Safe, but noisy.
6. **Five fictional cases.** They were written by the author, including the traps, so they
   test what the author thought of. Real documents will find the rest.

## Product hypotheses (unverified)

Nothing below has been checked with a customer yet. It is the plan for checking it.

- **Who pays:** estimating teams at small and mid-sized UK fit-out main contractors, who
  turn a tender round in one to three weeks and split scope into packages by hand.
- **What it saves:** the first pass from "documents received" to "RFQs out". Hypothesis: a
  few hours per tender for an estimator, and fewer late packages because a line was missed.
- **Success metric:** time from documents received to RFQs sent, and the share of extracted
  items an estimator corrects at review. Recall on missed items matters more than precision:
  a missing item is priced by nobody.
- **How to check it:** three anonymised real BoQs turned into eval cases; five estimator
  interviews on how packaging is done today and what a wrong quantity costs; one live tender
  shadowed end to end with the tool's output compared to the estimator's packages.

## What production would add

- OCR for scanned PDFs, with the quote check run against the OCR text and low-confidence
  regions sent straight to review.
- A review screen for `needs_review`, where an estimator's corrections become new eval
  cases — the loop that makes the evals grow from real failures instead of imagined ones.
- Tenants, authentication, and bid history feeding the supplier rating.
- Evals in CI on a schedule, run through the Batch API at half the price.

## How this was built

Built with Claude Code. Commits are co-authored and marked as such. Trust in the code
rests on the tests, static analysis, layer rules and the eval harness, not on who typed it.
The commit history follows the order the system was designed in.

The repo carries its own agent setup: [`CLAUDE.md`](CLAUDE.md) holds the project rules, and
three skills in [`.claude/skills`](.claude/skills) cover the quality loop:
`add-eval-case`, which writes expectations from the document rather than from model output;
`prompt-change`, which bumps the version and compares evals before and after; and
`review-extraction`, which sorts each failure into missed, invented or misclassified.
