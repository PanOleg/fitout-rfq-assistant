---
name: add-eval-case
description: Turn a new document (a BoQ, spec extract, site email) or a production mis-extraction into a golden eval case in evals/cases. Use when the user shares a document to test against, or when a tender's needs_review shows a failure worth keeping.
---

# Add an eval case

A case is two files in `evals/cases/`: `NN-slug.txt` (the document) and
`NN-slug.expected.json` (what a correct extraction contains). Every case should test
something the existing cases do not — a new layout, a trap, a trade mix.

## Steps

For a document already uploaded as a tender, `php artisan rfq:draft-case {tender} {slug}` writes
the document, an empty expectations file and the model's output (reference only) to the
git-ignored `evals/drafts/`. Continue from step 1 with those files.

1. **Anonymise first.** Replace client names, addresses, people and prices with fictional
   ones. Keep the structure, units, quantities and awkwardness intact — they are the test.
   Never commit a real client document.
2. Pick the next free number `NN` and a slug describing the shape (`06-scanned-table`,
   `07-mixed-revisions`). Save the document as `NN-slug.txt`.
3. Write `NN-slug.expected.json` by reading the document yourself, line by line — not by
   running the extractor and copying its output (that would grade the model against itself):
   ```json
   {
     "items": [
       {"trade": "flooring", "quantity": 640, "unit": "m2", "source_contains": "Carpet tiles"}
     ],
     "warnings_about": ["matting"]
   }
   ```
   - `trade` / `unit`: values from `src/Domain/Trade.php` and `src/Domain/Unit.php`.
   - `quantity`: exactly as written. If it would need calculating, it is NOT an item —
     add a keyword for that line to `warnings_about` instead.
   - `source_contains`: a short fragment unique to that line, copied from the document.
   - Headings, totals, exclusions, notes, provisional sums: not items.
4. Run `php artisan test --filter=ScorerTest` — it fails if any `source_contains` does not
   occur in its document.
5. Ask the user before a live run (it costs money), then:
   `php artisan rfq:eval --case=NN`. If the model fails the new case, that is the point:
   report which expectations it missed. Do not edit the expectations to make it pass.
6. Commit as `test(evals): add NN-slug — <what it tests>`.
