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
document ──► Claude extracts line items ──► every item checked against the document
         ──► grouped into trade packages ──► bidders shortlisted ──► RFQ drafts
```

The model reads. Deterministic code checks what it read, groups it, picks who to
invite, and writes the numbers into the RFQ.

## Design decisions

**Schema-constrained output, then grounding.** Extraction uses Claude's structured
outputs (`output_config.format = json_schema`), so malformed JSON can't happen. That only
guarantees shape. In a tender the costly failures are an item that isn't in the document and a
quantity that was "tidied up". Every item must quote the source verbatim, and
[`GroundingValidator`](src/Extraction/GroundingValidator.php) checks that the quote exists and
that the quantity is written in it.

**A bounded repair loop, with no silent drops.** Items that fail validation go back to the
model with the concrete reasons ([`LlmLineItemExtractor`](src/Extraction/LlmLineItemExtractor.php)).
After two rounds, anything still failing is returned as `rejected` alongside the model's own
`warnings`. The API shows both under `needs_review`. A missing item is caught at tender
review. A wrong one gets priced and built.

**LLM only where it earns its place.** Supplier shortlisting is a sort order: trade, region,
rating, specialist before generalist. RFQ text comes from a template. Both have to be
explainable and exact, and neither needs a model.

**Evals are part of the codebase.** `evals/cases/` holds five fictional documents in the
shapes estimators actually get: a tabular BoQ, prose specification, a site email, an M&E
schedule, and a file of traps (totals, exclusions, provisional sums, quantities that would
need calculating). `php artisan rfq:eval` scores precision and recall and checks required
warnings. It also reports tokens, cost and latency per case, so a change of prompt, model
or effort is a measured decision.

**Cost is visible and cacheable.** Each extraction records model, prompt version, attempts,
token usage and USD cost. Identical documents under the same prompt version and model hit a
cache instead of the API. The prompt version is part of the key, so editing the prompt
invalidates the cache by construction.

**Failures are sorted by what can be done about them.** Rate limits, overload and network
errors raise `LlmUnavailable`, and the queued job retries with backoff. A refusal or a
truncated answer fails the tender immediately, because retrying the same request won't help.
Server-side refusal fallback is enabled. Uploads are idempotent on the `Idempotency-Key`
header, including under concurrent requests.

## Layout

```
src/                      framework-free; layer rules enforced by Deptrac
  Domain/                 Trade, Unit, Quantity, LineItem, WorkPackage, Supplier
  Llm/                    LlmClient port, Anthropic adapter, usage and pricing
  Extraction/             prompt + schema, grounding, repair loop, cache decorator
  Suppliers/              directory port, shortlist rules
  Rfq/                    RFQ draft composer
  Evals/                  golden-case loader and scorer
app/                      Laravel: HTTP, queue job, Eloquent, composition root
evals/cases/              golden documents and expected items
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

curl -s localhost:8000/api/tenders/{id}         # packages, needs_review, cost
curl -s localhost:8000/api/tenders/{id}/rfqs    # one draft per package, with recipients

php artisan rfq:eval                            # live eval run; costs a few cents
php artisan rfq:eval --effort=low               # compare a cheaper setting
```

## What production would add

- PDF and scanned drawings as input, via Claude document blocks with citations in place
  of the text-quote check.
- Tenants, authentication, and a real supplier table with bid history feeding the rating.
- A review screen for `needs_review`, where accepted corrections become new eval cases.
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
