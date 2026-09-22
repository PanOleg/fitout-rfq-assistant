---
name: review-extraction
description: Investigate a tender's extraction — why items were rejected, what the model warned about, whether anything was missed — and propose fixes or new eval cases. Use when the user gives a tender id or asks why an extraction looks wrong.
---

# Review an extraction

## Steps

1. Load the tender: `GET /api/tenders/{id}`, or in `php artisan tinker`:
   `App\Models\Tender::find($id)` — the document is in `document`, the result in `extraction`.
2. Read the document yourself and list the items a quantity surveyor would expect.
3. Compare against `packages`, `needs_review.warnings` and `needs_review.rejected`.
   Classify every difference:
   - **Missed**: in the document, not extracted, not warned about.
   - **Invented**: extracted, not in the document (grounding should have caught it — if it
     did not, that is a validator bug; write a failing test in `GroundingValidatorTest`).
   - **Misclassified**: wrong trade or unit.
   - **Correctly flagged**: warned about or rejected for a good reason.
   - **Over-cautious**: rejected although the item and quantity are plainly in the text.
4. For each class, name the cause: prompt, validator, document shape, or a genuinely
   ambiguous line.
5. Propose the smallest fix per cause. Prompt fixes go through the `prompt-change` skill;
   validator fixes need a failing test first.
6. If the document shape is new, suggest turning an anonymised version into a case with
   `add-eval-case`.

Report findings as a short table (item, class, cause, proposed fix). Do not change code
until the user picks which fixes to make.
