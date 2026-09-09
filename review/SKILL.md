---
name: review
description: Review someone else's pull request, branch, or diff and deliver a verdict. Covers correctness, security, reuse, separation of concerns, simplicity, tests, maintainability, and scope discipline. Produces concrete findings, a list of what was verified as correct, and a Request Changes / Approve call. Trigger when the user asks to review a PR, look over a branch, check someone's changes, or asks whether something is good to merge.
---

# Review

Review **someone else's** change and reach a verdict. One pass, one verdict, no loop.

For iterating on *your own* PR against an automated reviewer, use `bugfix-loop`. The standards below
apply there too — you just hold your own diff to them before you push.

## Portability — read this before running anything

These steps run on Linux, macOS and Windows, in any editor or terminal. So:

- **Search with your own file-search tool, not shell `grep`.** Windows has no `grep`, and your tool
  is faster and respects ignore files.
- **Read files with your own file-reading tool, not `cat`/`type`.**
- **Never pipe to `sort`, `uniq`, `head`, `wc` or `jq`** — none are guaranteed present. `gh` embeds
  its own jq: use `--jq` (or `-q`) to filter, group and sort inside the `gh` call, as the examples
  below do.
- **One command per line.** No `\` continuations (invalid in PowerShell), no `for` loops, no `&&`
  chains, no `$(…)` substitution. Run repeated operations as repeated single calls.
- **Prefer the project's own declared commands** over invented invocations — see Step 7.
- Write paths with forward slashes; `git` and `gh` accept them everywhere.

## Preflight - stop if the environment isn't ready

Run these before anything else. If any fails, **stop and tell the user exactly what to fix.** Do not
fall back to guessing, do not work around it with raw `git` against the remote, and do not proceed
with a partial picture.

```
git --version
gh --version
gh auth status
```

- **`gh` not found** - it isn't installed or isn't on `PATH`. Point the user at the install for their
  OS: `winget install GitHub.cli` (Windows), `brew install gh` (macOS), or the package/binary from
  cli.github.com (Linux). Stop until it's there.
- **`gh auth status` exits non-zero** - not signed in. Tell the user to run `gh auth login` and say
  it's interactive, so they need to run it themselves rather than you running it for them.
- **Token scopes** are printed by `gh auth status`. `repo` is required for anything that writes
  (not needed for a read-only review, but required if the user later asks you to submit the review). If it's missing, `gh auth refresh -s repo`.
- **`gh` older than ~2.20** may reject some `--json` field names used here. If a field is rejected,
  say so and ask the user to upgrade rather than silently dropping the check.

Then confirm you can actually see the target repo, which catches SSO-gated orgs and typos in one shot:

```
gh repo view ORG/REPO --json viewerPermission --jq .viewerPermission
```

`READ` is enough to review. If this errors, the user may need to authorize the org for SSO in their browser - say so rather than retrying.

## Non-negotiables

1. **Pick the verdict last.** Assemble every finding first, then choose the word. Never decide
   "approve" and then attach a fix-first list to it.
2. **Never submit a review under the user's identity unless they said so for this specific PR.**
   "Review this" / "look this over" means review and report back in chat. An earlier "approve from
   me" on another PR does not carry forward. Submitting, approving, or requesting changes on their
   behalf needs an explicit instruction each time.
3. **Don't push commits to someone else's branch unasked.** A fix you'd like to make is a finding,
   not a commit. Offer it; let them decide.
4. **No follow-up issues.** If something small still needs doing, it belongs in this review, not in
   a ticket for later.
5. **Don't re-run CI.** The full suite, a whole-repo lint, or a type-check across the project is
   already done on every push — read the check results instead.
   **You may run narrow checks when a finding actually depends on it:** the affected file's own
   tests, or the linter on just the changed files. Keep it to the code in the diff.
   **If confirming something needs more than that** — a running app, real data, credentials, a
   specific environment — do not guess and do not claim you checked. State the uncertainty as a
   finding and ask the author to confirm it. That is a legitimate review outcome.

## Step 1 — Establish the target, the claim, and the size

Identify what you're reviewing (PR number, branch, or working diff) and what it claims to do. Read the
**linked issue or bug report first** — before the author's framing of it.

If the change references an issue, read it. If it doesn't, ask what problem it solves; a change with
no stated problem cannot be reviewed for scope.

If you're reviewing a local branch or working diff rather than a PR on the server, confirm you're
actually on the branch you think you are and that the tree is clean before you read anything -
another session or task may have moved it:

```
git rev-parse --abbrev-ref HEAD
git status --porcelain
```

Then measure how much there is to review, excluding vendored, generated and lock files:

```
gh pr view PR -R ORG/REPO --json files --jq '[.files[] | select(.path | test("vendor/|node_modules/|dist/|build/|\\.lock$|-lock\\.json$") | not)] | {files: length, lines: (map(.additions + .deletions) | add)}'
```

### Over ~500 reviewable lines: fan out, then verify centrally

Don't skim a large diff, and don't tell the author to split it — by the time it's open, that advice
costs them a rebase. Review it properly by dividing the work:

1. **Slice the diff by area** — module, directory, or file cluster — so each slice is coherent and no
   file lands in two slices.
2. **Dispatch one subagent per slice**, in parallel. Give each the same standards (Steps 3–6 of this
   skill) and require that every finding carry `file:line`, the failure scenario, and a suggested
   fix — plus its own list of what it verified as correct.
3. **Never pass a subagent's finding straight into your report.** Subagents are confidently wrong
   often enough to matter. For each surviving finding, open the code yourself and confirm it before
   it goes in.
4. **Then do one final pass across the whole diff yourself**, looking for what no single slice could
   see: contradictions between slices, a contract changed in one place and consumed in another,
   duplicated logic across slice boundaries.

Deduplicate before reporting — several slices touching the same helper will each report it.

## Step 2 — Read in this order

1. The linked issue / reported behavior.
2. The full diff, top to bottom — not just the interesting hunks.
3. The code **around** the diff. Open the files. A three-line change can be wrong because of what
   sits above and below it.
4. The CI check results, only if the verdict depends on them.

## Step 3 — Verify what the diff assumes

Do not trust the description. For each thing the diff relies on, confirm it:

- Do the helpers, methods and fields it calls actually exist, and return what's claimed?
- Any side effects, ordering requirements, or data constraints it ignores?
- **Inheritance and dispatch:** is the method that actually runs the one you think runs? Check for
  overrides, traits/mixins, and base-class implementations the diff never shows.
- Does it hold for edge inputs — zero, empty, null, the "free" or "default" case?

## Step 4 — Search for the pattern, not the line

The highest-value question in any bug-fix review: **where else does this same pattern live?**

A fix at one call site routinely leaves the identical defect at three others — so the change is
correct and still doesn't close the issue. Search the repo for the predicate, condition, or API being
corrected, using your own search tool.

Then check **downstream repos** when the change touches a shared or core API. Dependent add-ons,
plugins, themes and services appear in neither this diff nor this repo's CI.

Work in a scratch directory outside any real checkout — your harness's scratch/temp directory if it
has one, otherwise a new folder under the OS temp directory. **Never clone into the user's working
directories,** where a stale shallow copy gets mistaken for a live one.

```
gh repo list ORG --limit 200 --json name,isArchived --jq '[.[] | select(.isArchived == false) | .name] | sort | join("\n")'
```

Then one clone per repo you picked (shallow, so it's quick):

```
gh repo clone ORG/REPO_NAME -- --depth 1
```

Search each clone with your file-search tool for callers relying on the old behavior, and for
**subclasses overriding what changed** — those either dodge the fix or break on it. Delete the
directory when done; it's a lookup, not a checkout.

## Step 5 — The quality pass

A diff can be correct and still be the wrong thing to merge. Ask:

**Security** — Is input validated and sanitized where it enters, and escaped where it's output? Are
permission, capability, ownership and authentication checks present on every new path, not just the
happy one? Any secret, token or credential in the diff? Any user-controlled value reaching a query,
a filesystem path, a shell call, a redirect, or a deserializer? Is a new endpoint or handler
protected the same way its neighbors are? Security findings are correctness findings — they block.

**Reuse** — Does a helper for this already exist? Is this the same logic as somewhere else with two
words changed? Is there now a second source of truth for one rule? Reuse is the most common miss and
frequently *is* the fix: calling an existing predicate beats writing a new one.

**Separation** — Is business logic sitting in a view/template, or markup being assembled in a
controller? Are styles or scripts inlined where the project has an asset pipeline? Are queries in the
data layer rather than the request handler?

**KISS** — Is this the simplest thing that solves the reported problem? Any abstraction with exactly
one caller? Could a clever one-liner be three obvious lines? Any flags or options nobody asked for?

**Tests** — Only if the project already has a test suite. Then: does a bug fix ship with a test that
actually reproduces the bug? **Review the test, not just its presence** — would it still pass with
the fix reverted? Does it assert the buggy behavior by mistake? Is it tautological, or asserting a
mock rather than the code? Does it cover the edge case that caused the bug? A missing or wrong test
on a bug fix is a finding; if there's no suite in the project, say nothing.

**Maintainability** — Will the next person understand this in six months without the PR thread? Do
names say what things are, and are magic numbers explained? Do comments state intent rather than
restate the code — and are they **still true** after this change?

## Step 6 — Filter by scope

Findings belong to the change under review. A review that becomes a wishlist for the whole file costs
the author a day and gets the next PR read less carefully.

The test is not "was this line in the diff?" — it's whether the change is **responsible** for it.

**In scope**
- What the diff *causes*: a new path, a changed contract, a newly reachable branch.
- What the diff *reaches*: code that now runs because of it, including inherited methods absent from
  the diff.
- What the diff *leaves half-finished*: the same defect surviving at another call site, so the linked
  issue isn't actually closed.
- What the diff *breaks downstream*: a dependent repo or add-on — downstream of the change even
  though it isn't in it.

**Out of scope**
- Pre-existing problems in code the diff merely sits *near*.
- A refactor you'd have written differently, where the author's version is fine.
- "While you're in here…" additions.
- Style the project's linter doesn't enforce.

Out-of-scope observations get said **once**, as a closing note, never attached to the verdict. If an
automated reviewer has already commented, expect it to raise pre-existing and out-of-scope items
freely; you are not obliged to carry those into your review.

## Step 7 — Check, don't assume

Never assert a repo fact from memory.

```
gh repo view ORG/REPO --json defaultBranchRef --jq .defaultBranchRef.name
```

The default branch is often not the merge target, so check where merged PRs actually landed:

```
gh pr list -R ORG/REPO --state merged --limit 15 --json baseRefName --jq 'group_by(.baseRefName) | map({branch: .[0].baseRefName, count: length}) | sort_by(-.count)'
```

**To find the project's lint/test commands, read what the project declares** rather than inventing an
invocation. Open `composer.json`, `package.json`, `Makefile`, or the CI workflow with your
file-reading tool and use the scripts defined there — that's what CI runs, and it works on every
platform. Only if nothing is declared, look for a linter config file. Some repos ship none on
purpose; that's not a finding.

**Who should review something is a question, not an inference.** Ask the author or the team. Don't
derive it from who reviewed the last twenty PRs — that tells you who happened to be around, not who
owns the surface, and it loads more review onto whoever is already busiest. If the repo has a
`CODEOWNERS` file, that's the answer; otherwise a person is.

Conventions you cannot query at all — that a branch takes release merges only, that a repo ships no
ruleset on purpose — must be asked about rather than guessed.

## Step 8 — Reading automated and stale signals

If bots or prior reviews are on the PR, don't take them at face value:

- A platform's aggregate "review decision" usually reflects **required human reviewers only**; a
  bot's own approval is tracked separately and doesn't move it.
- **An automated approval often sits on an older commit.** Compare the PR head against the review's
  `commit_id`, and confirm anything pushed since is non-production (comments, tests) before leaning
  on it.

```
gh pr view PR -R ORG/REPO --json headRefOid --jq .headRefOid
gh api repos/ORG/REPO/pulls/PR/reviews --jq '.[] | {user: .user.login, state: .state, commit: .commit_id[0:9]}'
```

- Coverage-threshold failures are a nag, not a test failure. Read them, then judge on merit.
- A bot that adds itself as a reviewer is not a human reviewer and doesn't satisfy branch protection.

## Step 9 — The verdict

The test is **"does this need a code change?"** — not "is this severe?"

**Request Changes** for: wrong behavior on any reachable path; a security gap; a fragile or missing
guard; a comment that documents the wrong half of a change; an undocumented behavior change; a
missing or wrong test on a bug fix where a suite exists; an incomplete fix where the same defect
survives elsewhere.

**Does not block:** a missing description section, a changelog line, a wrong commit-message prefix,
formatting the linter doesn't flag, style preferences with no runtime effect. Say these as a closing
note, or just fix them yourself.

Anything that would otherwise become a follow-up ticket gets handled **now**. One more round trip
beats a merged loose end.

The tell that you've drifted: if you write *"not blocking"* about a code change — it is blocking.

Do **not** loop. On a re-review, if every substantive finding is resolved, approve. Trivia with no
runtime effect — dead code, a stale docblock, a vestigial parameter — does not earn another round
trip. Decide it yourself rather than handing the call back.

## Output format

1. **Verdict** — one line. `Request Changes` or `Approve`.
2. **Findings** — each naming file, line, what breaks, on which flow, and a suggested fix, so the
   author can act without asking a follow-up question.
3. **Verified as correct** — what you checked and found sound. This is most of the value of a review
   and the part most often skipped: it stops the author re-litigating settled points and tells the
   next reader what's already been covered.
4. **Notes** — out-of-scope observations and housekeeping, explicitly marked non-blocking.
5. **Confidence** — what you verified by reading, what you verified by running a narrow check, and
   what you could not check at all. Never imply runtime verification you didn't do.
