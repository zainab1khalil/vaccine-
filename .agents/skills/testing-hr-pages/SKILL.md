---
name: testing-hr-pages
description: How to run and test the Al-Mujtaba HR Arabic RTL static HTML pages (index/departments/employees/exceptions/unscheduled-overtime/Al-Mujtaba_HR_Attendance_System) locally, including the top JS pitfall that silently kills a whole page.
---

# Testing the Al-Mujtaba HR static pages

## Serving locally
The pages are plain static HTML that load `supabase.js` and talk to a Laravel API (`API_BASE = http://localhost:8000/api`).

```bash
cd <repo> && python3 -m http.server 8811
# open http://localhost:8811/index.html
```

- The Laravel side (app/, Services/, routes/) usually cannot boot (no vendor/, .env, DB), so every `hrApi` call fails with `Failed to fetch`. Pages are expected to show Arabic error/empty states — that is not a bug by itself.
- Supabase **does** work from the browser (anon key is hardcoded in `supabase.js`), so `employees.html` / the attendance app load ~127 real employees from the `hr_workspace` snapshot. Real end-to-end UI testing of employee detail views is therefore possible without any backend.
- Workspace data is cached in `sessionStorage`; open a fresh tab if you need a clean load.

## CRITICAL pitfall: duplicate top-level `const` kills the whole inline script
`supabase.js` declares globals including `fDate`, `MONTHS_AR`, `mHHMM`, `toast`, `showToast`, `hrApi`, `API_BASE`, `SHIFT_CODES`.
If a page's inline `<script>` re-declares one of them with `const`/`let`/`class` (or declares a `function` with the same name as a `const` in supabase.js), the browser throws
`Uncaught SyntaxError: Identifier 'X' has already been declared` **at parse time**, so *no* code on that page runs: no tab switching, no modals, no loads. The page still looks fine (static HTML renders) — it is simply inert, which is easy to mistake for "clicks not registering".

Always run this check before/while testing any page:

```bash
python3 - <<'EOF'
import re,glob
lib=open('supabase.js',encoding='utf-8').read()
def tops(c): return set(re.findall(r'^(?:const|let|function|class)\s+([A-Za-z_$][\w$]*)',c,re.M))
L=tops(lib)
for f in glob.glob('*.html'):
    h=open(f,encoding='utf-8').read()
    for i,s in enumerate(re.findall(r'<script(?![^>]*src)[^>]*>(.*?)</script>',h,re.S)):
        d=sorted(tops(s)&L)
        if d: print(f,i,d)
EOF
```
Then confirm which are fatal by concatenating supabase.js + the inline script and running `node --check`:
```bash
node --check /tmp/combined.js   # prints the exact "already been declared" identifier
```
Workaround for continuing to test a page that is dead this way: copy the file, rename the local declaration (e.g. `fDate` -> `fDateLocal`), serve the copy, and clearly label results as "patched copy" in the report.

## Useful navigation notes
- Typing `localhost:8811/Al-Mujtaba_HR_Attendance_System.html` in the omnibox can get mangled (underscore dropped) → 404. Navigate via the sidebar link "📊 التقارير والرفع" instead.
- Employee detail: employees.html → search box → 🔍 بحث → click the row. Overtime card is "⏱️ احتساب الدوام الإضافي" on the ملخص tab.
- Overtime rules live in employees.html: `MAX_DAILY_HOURS=12`, `DUTY_QUOTA_12HR=17`, payout = round(otHours/8 * salary/30), payout only for `12hr` shift employees with a basic salary.
- Note: on the workspace (hr_workspace) render path the OT hours come straight from the attendance snapshot and are NOT recomputed with the 17-shift quota rule; only the DB/`daily_attendance` path applies the local formula. Expect apparent contradictions (e.g. "duty shifts 0" but 22.5 OT hours).
- Salary is rarely set in Supabase; to exercise the payout branch without writing to the production DB, set `currentEmp.basic_salary = <n>` in the console and click the "عرض" button (the button click is a real UI action that re-renders).
- Attendance app language toggle is the EN / AR pair at the top-right of the header; the Duty Carryover modal is opened from "🔄 Duty Carryover" on tab "4. Department Report".

## Devin Secrets Needed
None — Supabase anon key is committed in `supabase.js`; no login is required for any page.
