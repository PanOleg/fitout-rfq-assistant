# Fit-out RFQ Assistant

Laravel 13 / PHP 8.4. Claude extracts line items from fit-out documents; deterministic
code validates, groups, shortlists suppliers and writes RFQs. See README for the why.

## Map

- Extraction (model call, repair loop) → `src/Extraction/LlmLineItemExtractor.php`
- Grounding checks on model output → `src/Extraction/GroundingValidator.php`
- Prompt, schema, repair message → `src/Extraction/ExtractionPrompt.php`
- Anthropic adapter → `src/Llm/Anthropic/`; port → `src/Llm/LlmClient.php`
- RFQ writing → `src/Rfq/RfqComposer.php`; suppliers → `src/Suppliers/`, `config/suppliers.php`
- API → `app/Http/Controllers/TenderController.php` + queued `app/Jobs/ExtractTender.php`
- Evals → cases in `evals/cases/`, scorer in `src/Evals/`, command `app/Console/Commands/RunEvals.php` (`rfq:eval`)

## Rules

- `src/` is framework-free. No `Illuminate\*` there (except in tests). Laravel lives in
  `app/`; `AppServiceProvider` is the only place that wires ports to adapters.
  Layer rules are in `deptrac.yaml` — extend them when adding a directory, never loosen them.
- The model only ever reads. Anything that must be exact or explainable — supplier choice,
  RFQ numbers, totals — is plain code.
- Every extracted item must stay traceable to the document (`source_text`). Do not add a
  field the model fills without a matching check in `GroundingValidator`, or a reason in
  the PR why it cannot be checked.
- Any change to `ExtractionPrompt` (system text, user message, schema, repair message)
  bumps `ExtractionPrompt::VERSION`. Use the `prompt-change` skill.
- Tests never call the API. Use `Tests\Support\ScriptedLlmClient` above the adapter and a
  Guzzle mock transport for the adapter itself.
- Model choice and effort come from `config/rfq.php`; do not hard-code them elsewhere.

## Commands

```bash
composer check                    # the gate: Pint → PHPStan L8 → Deptrac → PHPUnit
php artisan test --filter=Name    # one test
php artisan rfq:eval              # live evals — costs money, ask before running
php artisan rfq:eval --case=04 --effort=low
```

`composer check` must pass before every commit. Commit messages: conventional commits,
body explains why, not what.
