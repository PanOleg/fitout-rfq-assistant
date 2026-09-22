---
name: prompt-change
description: Change the extraction prompt, schema or repair message safely — version bump, before/after eval comparison, recorded result. Use for any edit to src/Extraction/ExtractionPrompt.php or when the user wants to improve extraction quality.
---

# Change the extraction prompt

The prompt is code with a version. Cached results and eval reports are keyed by
`ExtractionPrompt::VERSION`, so an unversioned change silently mixes old and new behaviour.

## Steps

1. **Baseline.** Find the latest report for the current version in `storage/evals/`. If
   there is none, ask the user before running `php artisan rfq:eval` (it costs money) and
   keep the report path.
2. **State the hypothesis** in one sentence: which cases or failures the change should fix,
   and what it must not break.
3. Edit `ExtractionPrompt`. Keep changes minimal and targeted at the hypothesis. Prefer
   explaining the reason for a rule over adding emphasis (no ALL CAPS, no "CRITICAL").
   If the schema changes, update `GroundingValidator` and `LineItem` to match.
4. Bump `VERSION` (`v1` → `v2`).
5. `composer check` must pass.
6. Run the same eval command as the baseline (same `--model`, `--effort`). Compare per case:
   precision, recall, unflagged warnings, attempts, cost. A change that improves one case and
   regresses another is not an improvement until both are understood.
7. Report a before/after table to the user. If the change does not help, revert it — do not
   keep tuning expectations or the scorer to make numbers go up.
8. Commit as `feat(extraction): prompt vN — <what changed>`, with the before/after numbers
   in the commit body.
