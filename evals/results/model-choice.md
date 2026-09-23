# Choosing the model and effort

The default in `config/rfq.php` changes only by this rule, applied to a
`rfq:eval --repeat=3` (or more) run over **every** case.

A setting is **eligible** when, over all its runs:

1. **Worst-run recall ≥ 0.98.** A missed item is priced by nobody; one bad run in three is not
   an acceptable rate.
2. **Mean recall ≥ the default's mean recall − 0.01.** A cheaper setting may not be measurably
   worse at the thing that matters most.
3. **Worst-run precision ≥ 0.97.** An invented item is caught by the checks or at review, so a
   little more slack than recall.
4. **No errors**, and every trap case (04, 07) passes in every run — rates, dimensions and
   provisional sums are flagged, not extracted.

Among eligible settings, choose the **cheapest per case**; if two are within 10 % on cost,
choose the faster. If the current default is not eligible, report it as a regression before
choosing anything.

Why a rule written down first: with six easy cases every setting scored 1.00, and "the numbers
are the same, so take the cheaper one" is only safe when the cases can tell settings apart. The
rule makes the decision mechanical and the data reviewable, instead of a judgement made after
seeing the results.

## Decisions

| date | cases | runs | decision | table |
|---|---|---|---|---|
| 2026-09-22 | 01–06 | 1 per setting | keep claude-opus-5 @ medium — suite saturated, cannot separate settings | [20260922-221031.md](20260922-221031.md) |
| 2026-09-22 | 01–09 | 1 complete per setting (API credit ran out in run 2) | keep claude-opus-5 @ medium — the rule needs ≥ 3 runs, so it cannot be applied yet | [20260922-candidates-9-cases.md](20260922-candidates-9-cases.md) |

## Proposed amendment (not adopted)

Written after seeing the 2026-09-22 data, so it is a proposal for the next decision, not a
rule applied to that one: **5. No expected item is missed in more than one run.** Sonnet 5 @
low missed the same line ("4 nr basins", case 03) in both runs it reached, with pooled recall
0.997 — above every threshold above. A miss that repeats is a blind spot, not noise, and a
pooled number hides it.
