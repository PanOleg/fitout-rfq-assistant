## 2026-09-22 — piece context on case 06 (prompt v2, validator 4, claude-opus-5 @ medium)

Case 06 is always read in pieces (chunk_chars 250); most of its lines name no trade, only the
section headings do. Ten runs, five per mode; "failures" lists what was missed.

| mode | runs | passed | failures | latency / run |
|---|---|---|---|---|
| without context | 5 | 3 | F-04 "Type D, recessed" missed twice — the model's warning: the section heading is not in the extract, so the trade cannot be confirmed | 25.8–34.5 s |
| with context | 5 | 4 | W-01 missed once — a defect in the case (trade not stated in the document), fixed in af7300c; F-04 never missed | 21.7–29.7 s |

Two of the ten runs used the case before af7300c (W-01 wording, ref-code fragments); neither
change touches F-04. Five runs per mode is a direction, not a proof.
