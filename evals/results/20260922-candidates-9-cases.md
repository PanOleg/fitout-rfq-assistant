## 2026-09-22 — prompt v2, validator 5, 9 cases, 1 complete run each (credit ran out during run 2)

| model | effort | cases passed | precision | recall | rejected | cost | cost / case | cost, case 07 | latency / case |
|---|---|---|---|---|---|---|---|---|---|
| claude-opus-5 | medium | 9/9 | 1.000 | 1.000 | 0 | $1.08 | $0.120 | $0.63 | 35.0 s |
| claude-sonnet-5 | low | 8/9 | 1.000 | 0.997 | 0 | $0.57 | $0.063 | $0.41 | 23.9 s |

Misses: claude-sonnet-5 @ low left out "4 nr basins" in case 03 ("replace 4 nr pans and 4 nr basins") — in run 1, and again in the partial run 2 before it stopped. claude-opus-5 @ medium: none.
