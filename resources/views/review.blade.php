<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review · {{ $tender->name }}</title>
<style>
:root{--paper:#f4f6f5;--sheet:#fff;--ink:#1b2226;--muted:#56636a;--rule:#d5dcda;--accent:#0f6e6a;--warn:#a2560a;--warn-soft:#fbeede;--bad:#b42318;--bad-soft:#fbe7e5;--ok:#2f7d32;--ok-soft:#e5f2e5}
@media (prefers-color-scheme:dark){:root{--paper:#131819;--sheet:#1a2022;--ink:#e4eaea;--muted:#9aa8ab;--rule:#2c3538;--accent:#5fc2ba;--warn:#e7a35a;--warn-soft:#33240f;--bad:#f08a7e;--bad-soft:#3a1c19;--ok:#7cc47f;--ok-soft:#1a2e1b;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px 16px 64px}
main{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:20px}
h1{margin:0;font-size:1.6rem}
.muted{color:var(--muted)}
.status{background:var(--ok-soft);color:var(--ok);padding:10px 14px;border-radius:4px}
.errors{background:var(--bad-soft);color:var(--bad);padding:10px 14px;border-radius:4px}
.warnings{background:var(--warn-soft);border-left:3px solid var(--warn);padding:10px 14px}
.warnings ul{margin:6px 0 0;padding-left:1.2em}
.sheet{background:var(--sheet);border:1px solid var(--rule);overflow-x:auto}
table{border-collapse:collapse;width:100%;min-width:980px}
th{font:600 .72rem/1 ui-monospace,Menlo,monospace;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);text-align:left;padding:10px 8px;border-bottom:1px solid var(--rule)}
td{padding:8px;border-bottom:1px solid var(--rule);vertical-align:top}
tr.rejected td{background:var(--bad-soft)}
tr.added td{background:transparent}
input,select,textarea{font:inherit;color:inherit;background:var(--paper);border:1px solid var(--rule);border-radius:3px;padding:5px 6px;width:100%}
input.qty{width:90px;text-align:right;font-variant-numeric:tabular-nums}
textarea{min-height:56px;font-family:ui-monospace,Menlo,monospace;font-size:.85rem}
.origin{font:600 .7rem/1 ui-monospace,Menlo,monospace;text-transform:uppercase;letter-spacing:.05em}
.problems{color:var(--bad);font-size:.82rem;margin-top:6px}
.decision label{display:block;white-space:nowrap}
button{font:inherit;background:var(--accent);color:#fff;border:0;border-radius:4px;padding:10px 18px;cursor:pointer}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
</style>
</head>
<body>
<main>
  <header>
    <h1>{{ $tender->name }}</h1>
    <p class="muted">Region {{ $tender->region }} · return by {{ $tender->return_by->toDateString() }}
      @if ($tender->reviewed_at) · reviewed {{ $tender->reviewed_at->toDayDateTimeString() }} @endif</p>
  </header>

  @if (session('status'))<p class="status">{{ session('status') }}</p>@endif
  @if ($errors->any())
    <div class="errors"><strong>Not saved.</strong> @foreach ($errors->all() as $error) {{ $error }} @endforeach</div>
  @endif

  @if ($warnings !== [])
    <section class="warnings">
      <strong>The tool was unsure about</strong>
      <ul>@foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
    </section>
  @endif

  <form method="post" action="{{ route('tenders.review.update', $tender) }}">
    @csrf
    <div class="sheet">
      <table>
        <thead><tr><th>Keep</th><th>Trade</th><th>Description</th><th>Qty</th><th>Unit</th><th>Quote from the document</th></tr></thead>
        <tbody>
        @foreach ($rows as $i => $row)
          <tr class="{{ $row['origin'] }}">
            <td class="decision">
              <span class="origin">{{ $row['origin'] === 'added' ? 'missed item' : $row['origin'] }}</span>
              <input type="hidden" name="rows[{{ $i }}][origin]" value="{{ $row['origin'] }}">
              <label><input type="radio" id="keep-{{ $i }}" name="rows[{{ $i }}][decision]" value="keep" @checked(old("rows.$i.decision", $row['decision']) === 'keep') style="width:auto"> keep</label>
              <label><input type="radio" id="drop-{{ $i }}" name="rows[{{ $i }}][decision]" value="drop" @checked(old("rows.$i.decision", $row['decision']) === 'drop') style="width:auto"> {{ $row['origin'] === 'added' ? 'ignore' : 'drop' }}</label>
            </td>
            <td>
              <select id="trade-{{ $i }}" name="rows[{{ $i }}][trade]">
                <option value=""></option>
                @foreach ($trades as $trade)<option value="{{ $trade->value }}" @selected(old("rows.$i.trade", $row['trade']) === $trade->value)>{{ $trade->label() }}</option>@endforeach
              </select>
            </td>
            <td><input id="description-{{ $i }}" name="rows[{{ $i }}][description]" value="{{ old("rows.$i.description", $row['description']) }}"></td>
            <td><input class="qty" id="quantity-{{ $i }}" name="rows[{{ $i }}][quantity]" inputmode="decimal" value="{{ old("rows.$i.quantity", $row['quantity']) }}"></td>
            <td>
              <select id="unit-{{ $i }}" name="rows[{{ $i }}][unit]" style="width:80px">
                <option value=""></option>
                @foreach ($units as $unit)<option value="{{ $unit }}" @selected(old("rows.$i.unit", $row['unit']) === $unit)>{{ $unit }}</option>@endforeach
              </select>
            </td>
            <td>
              <textarea id="source-{{ $i }}" name="rows[{{ $i }}][source_text]" placeholder="Copy the line from the document">{{ old("rows.$i.source_text", $row['source_text']) }}</textarea>
              <input type="hidden" name="rows[{{ $i }}][spec_reference]" value="{{ $row['spec_reference'] ?? '' }}">
              @if ($row['problems'] !== [])<div class="problems">{{ implode(' ', $row['problems']) }}</div>@endif
              @error("rows.$i.source_text")<div class="problems">{{ $message }}</div>@enderror
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <p><label for="notes" class="muted">Notes for the eval case (what the tool got wrong, and why)</label>
      <textarea id="notes" name="notes">{{ old('notes', $tender->review['notes'] ?? '') }}</textarea></p>
    <input type="hidden" name="version" value="{{ old('version', $tender->review_version) }}">
    <p><label for="reviewer" class="muted">Your name (recorded with this version)</label>
      <input id="reviewer" name="reviewer" value="{{ old('reviewer', $reviewer) }}" required style="max-width:280px"></p>
    <p><button type="submit">Save review</button>
      <span class="muted">Packages and RFQs will use these items. An eval case draft is written from your decisions.</span></p>
  </form>

  @if ($history->isNotEmpty())
    <section>
      <h2 style="font-size:1.1rem;margin:0 0 8px">History</h2>
      <div class="sheet"><table style="min-width:0">
        <thead><tr><th>Version</th><th>Reviewer</th><th>Saved</th><th>Decisions</th></tr></thead>
        <tbody>
        @foreach ($history as $review)
          <tr><td>{{ $review->version }}</td><td>{{ $review->reviewer }}</td><td>{{ $review->created_at->toDayDateTimeString() }}</td><td>{{ $review->summary }}</td></tr>
        @endforeach
        </tbody>
      </table></div>
    </section>
  @endif
</main>
</body>
</html>
