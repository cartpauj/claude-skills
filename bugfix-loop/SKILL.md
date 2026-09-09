---
name: bugfix-loop
description: Iterate on your own bug-fix PR until an automated reviewer approves it. Verifies branch and working-tree state before touching anything, self-reviews the diff before every push, runs narrow checks on changed code only, appends a per-round log to the PR description because the bot only reads that, re-requests review, polls the bot's own check runs, then triages and resolves every thread and repeats. Trigger when the user asks to work a PR through review, respond to review-bot findings, address review comments, or keep going until a PR is approved.
---

# Bugfix Loop

Drive **your own** PR through repeated automated review until it's approved — without burning rounds
and without letting the loop run forever.

The standards in the `review` skill apply here in full; you just apply them to your own diff before
you push. Read that skill's Steps 3-6 (verify assumptions, search the pattern, quality pass, scope
filter) and hold your work to them.

## Portability - read this before running anything

These steps run on Linux, macOS and Windows, in any editor or terminal. So:

- **Search and read files with your own tools**, not `grep`/`cat`/`type`. Windows has neither.
- **Never pipe to `sort`, `uniq`, `head`, `wc` or `jq`.** `gh` embeds its own jq - use `--jq` (or
  `-q`) to filter and shape inside the `gh` call, as below.
- **One command per line.** No `\` continuations (invalid in PowerShell), no `for` loops, no `&&`
  chains, no `$(...)` substitution.
- **Long text is passed from a file**, as `-F body=@<path>`. That avoids shell quoting entirely and
  keeps the value a string. Multi-line text pasted inline will break on some shell somewhere.
- Write paths with forward slashes; `git` and `gh` accept them everywhere.

Almost everything here is plain `gh` REST. **Two operations have no REST equivalent** and use the
GraphQL files in `graphql/` next to this skill: reading whether a review thread is *resolved*, and
resolving one. Nothing else needs GraphQL.

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
  (pushing, updating the PR body, replying to and resolving threads). If it's missing, `gh auth refresh -s repo`.
- **`gh` older than ~2.20** may reject some `--json` field names used here. If a field is rejected,
  say so and ask the user to upgrade rather than silently dropping the check.

Then confirm you can actually see the target repo, which catches SSO-gated orgs and typos in one shot:

```
gh repo view ORG/REPO --json viewerPermission --jq .viewerPermission
```

You need `WRITE` or better here. If it comes back `READ`, stop: you cannot push or resolve threads, and the loop can't run. If it errors, the user may need to authorize the org for SSO in their browser.

## Setup - resolve these once, don't assume them

- **Repo, PR number, and base branch.** Confirm all three; don't infer from the working directory.
- **The reviewer bot's login.** Read it off the PR's existing reviews or requested reviewers, or ask
  the user. Never hardcode a guess. Called `BOT` below.
- **The size of the diff**, excluding vendored, generated and lock files:
  ```
  gh pr view PR -R ORG/REPO --json files --jq '[.files[] | select(.path | test("vendor/|node_modules/|dist/|build/|\\.lock$|-lock\\.json$") | not)] | {files: length, lines: (map(.additions + .deletions) | add)}'
  ```
  Large diffs are the main reason this loop fails to converge (see **Loop guard**). If the PR is
  already big and sprawls across unrelated concerns, say so **now** and offer to split it - that is
  far cheaper before five review cycles than after.

## Working-tree safety - check before every edit and every commit

Another session, task, or person can move `HEAD` or dirty the tree between your tool calls. One check
at the start is not enough.

**Before you make changes**, confirm the branch and that the tree is clean:

```
git rev-parse --abbrev-ref HEAD
git status --porcelain
```

`git status --porcelain` must come back empty. If it doesn't, stop and report - never stash, reset, or
commit someone else's work in progress.

**Re-run both immediately before committing.** If the branch or tree moved underneath you, stop and
report rather than committing onto whatever is now checked out.

**Safest option: work in a throwaway worktree**, so nothing can move under you and the shared checkout
stays untouched. Create it in a scratch directory outside any real checkout:

```
git worktree add <scratch-dir>/wt-PR BRANCH
```

Remove it when the loop ends:

```
git worktree remove --force <scratch-dir>/wt-PR
```

**Never force-push during the loop.** Rewriting history re-anchors or outdates every review thread,
throwing away the round you just paid for and making the bot re-report what you already answered. If
history must be rewritten, finish the loop first.

## The one fact that makes this loop converge

**The bot reads only the PR description.** It does not read comment threads.

Anything you explain only in a thread reply is invisible next round, so the same finding comes back
and the loop never terminates. Every decision you want to stick must be in the **body**.

A body edit alone does not retrigger the bot - only a push does. So the order below matters: update
the body, *then* re-request.

## Loop guard

Track the round number. **After 5 full rounds without an approval, stop and report:** what is still
being raised, what you declined and why, and your recommendation.

**If you're still going at 5 rounds, the PR is probably too big.** These reviewers degrade badly on
large diffs - they keep finding new things on every pass, so the loop can run almost indefinitely on a
big PR while converging in two rounds on a small one. Lead with that: recommend **splitting the change
into several smaller, independently reviewable PRs**, each with one coherent purpose. Suggest concrete
seams based on what the diff actually touches - per bug, per layer, per module - and separate
mechanical churn (renames, formatting, moves) from behavioral change.

Also stop immediately if a round produces no new findings and no new commits - that means you're
re-litigating, not converging.

---

## Order of operations

### 1. Read the current state

Fetch the PR body, head SHA and every thread with resolution state. **Always paginate
`reviewThreads`** - a single page silently truncates and will make you report "no new threads" when a
dozen have landed. If the returned count equals the page size, there is more.

```
gh api graphql -F owner=ORG -F repo=REPO -F pr=PR -F query=@graphql/review-threads.graphql
```

Follow `pageInfo.hasNextPage`, passing `endCursor` back as `-F cursor=...`, until it is false.

Then read the verdicts and note **which commit each one points at**:

```
gh api repos/ORG/REPO/pulls/PR/reviews --jq '.[] | {user: .user.login, state: .state, commit: .commit_id[0:9], at: .submitted_at}'
```

### 2. Triage every thread - fix only if needed

For each unresolved thread, decide one of three things:

- **Fix it.** A real defect, or a cheap win with actual value.
- **Decline it.** Out of scope, pre-existing code the diff merely sits near, a style preference, or
  simply wrong. The bot raises these freely and you are not obliged to act on them.
- **Already handled.** Fixed in a later commit, or the thread is outdated.

Apply the `review` skill's scope filter to your own work: in scope is what your diff *causes*,
*reaches*, *leaves half-finished*, or *breaks downstream*. Out of scope is code your diff merely sits
near. Declining an out-of-scope suggestion is the correct answer, not a shortcut.

**Fix only when needed - but reply to and resolve every thread either way,** with a short note saying
what you did or why you declined. An unresolved thread is noise the next round has to re-read.

### 3. Make the fixes and self-review before committing

This is the step that saves rounds. Re-check branch and tree state first, then before committing:

- Re-read the **whole** diff against the base branch, not just the lines you touched:
  ```
  git diff BASE...HEAD
  ```
- Verify every API the diff assumes actually exists and behaves as claimed - search for it rather than
  trusting the name.
- Run the `review` skill's quality pass on your own change: **security**, reuse, separation, KISS,
  tests, maintainability.
- **Search for the pattern, not just your line.** If the defect you fixed exists at other call sites,
  fix those too - otherwise the linked issue isn't closed and it returns as a fresh bug report.
- **If the change touches a shared or core API, check the downstream repos yourself.** Dependent
  add-ons, plugins, themes and services appear in neither your diff nor this repo's CI, and the bot
  will never mention them. List the siblings, then clone the plausible consumers shallowly into a
  scratch directory outside any real checkout:
  ```
  gh repo list ORG --limit 200 --json name,isArchived --jq '[.[] | select(.isArchived == false) | .name] | sort | join("\n")'
  gh repo clone ORG/REPO_NAME -- --depth 1
  ```
  Search each for callers relying on the old behavior and for **subclasses overriding what you
  changed** - those either dodge your fix or break on it. Delete the directory afterwards. If you find
  a downstream break, record it in the round log so the next reader knows it was checked.
- **Tests** - only if the project has a suite. Add or fix the test that reproduces the bug, and make
  sure it would **fail with your fix reverted**. A test that passes either way is worse than none,
  because it looks like coverage.
- Sweep the categories automated reviewers reliably flag, so they don't cost you a round:
  - raw request/superglobal access and dynamic variable extraction
  - missing input sanitization or output escaping
  - missing permission, capability or ownership checks on new paths
  - unquoted attributes in generated markup
  - dead, duplicated, or unreachable branches
  - parameters that are never used
  - missing or stale doc comments, and comments the change made untrue
  - inconsistent brace/indent style against the surrounding file
  - behavior changes that would break existing links, stored data, or persisted state

### 4. Run narrow checks on what you changed

Do **not** run the whole suite or a whole-repo lint - CI does that. Check only the files you touched
and, if the project has a suite, only the tests covering them.

**Find the project's commands by reading what it declares** rather than inventing an invocation. Open
`composer.json`, `package.json`, `Makefile`, or the CI workflow with your file-reading tool and use
the scripts defined there - that is what CI runs, and it works on every platform. Only if nothing is
declared, look for a linter config file. Some repos ship none on purpose.

### 5. Commit and push

Re-check branch and tree state one more time, then commit with a message naming the defect and the
fix. Push to the PR branch - never with force.

**Confirm the push actually landed.** Re-requesting review after a failed push wastes a whole round:

```
gh pr view PR -R ORG/REPO --json headRefOid --jq .headRefOid
```

That must now match your local `HEAD`.

Note the CI state but **don't gate on it** - a red build doesn't stop the bot reviewing the rest of
the code, and waiting for green costs time for nothing. One exception: if CI is red **because of your
change**, fix it now, since the bot will likely flag it anyway.

### 6. Append this round to the PR description

**Do not rewrite or delete the existing body.** You already have it from step 1. Append a new round
entry and write the whole thing back. Rewriting risks dropping the issue-closing keyword - `Closes #N`
- which silently stops the issue auto-closing on merge, and nobody notices until someone audits stale
issues.

Append a section shaped like:

```
## Review round N

- Fixed: <finding> - <what changed, file:line>
- Declined: <finding> - <why: out of scope / pre-existing / incorrect>
- Downstream: <repos checked, result>
```

The declined entries are what stop findings recurring, since this is the bot's only input.

One allowance: if a sentence in the original summary has been made **factually untrue** by your
changes, correct that sentence. Leaving a false description in the body keeps the bot re-flagging what
your rounds already settled. Correct it; don't restructure around it.

Write the assembled body to a file, then:

```
gh api -X PATCH repos/ORG/REPO/pulls/PR -F body=@newbody.md
```

Use this REST call rather than `gh pr edit --body-file`, which can fail on some orgs with a
projects-classic deprecation error.

### 7. Reply to and resolve the threads

Reply with REST, using the **first comment's `databaseId`** from the step 1 query as the thread anchor:

```
gh api -X POST repos/ORG/REPO/pulls/PR/comments/COMMENT_DATABASE_ID/replies -F body=@reply.md
```

Then resolve the thread, using its GraphQL node `id` from the same query:

```
gh api graphql -F threadId=THREAD_ID -F query=@graphql/resolve-thread.graphql
```

Resolve every thread you answered - the declined ones included.

### 8. Re-request review

```
gh api -X POST repos/ORG/REPO/pulls/PR/requested_reviewers -f reviewers[]=BOT
```

### 9. Poll for the new verdict - and for the bot still being alive

The bot re-runs on push, with a lag. Poll roughly every 45 seconds for a new review from `BOT` or new
threads, comparing against this round's starting counts rather than absolute numbers.

**Short-circuit instead of waiting on a review that will never arrive.** Each poll, check the bot's
own check runs on the head commit:

```
gh api repos/ORG/REPO/commits/HEAD_SHA/check-runs --jq '.check_runs | map({name: .name, status: .status, conclusion: .conclusion})'
```

- Still `queued` or `in_progress` - keep polling.
- `completed` with a failure, or the run vanished / never started - **stop polling and report.** The
  bot is not going to answer, and the user needs to know its workflow failed rather than waiting.

Say which state you saw, so it is obvious whether you're waiting on the bot or the bot is broken.

### 10. Repeat or exit

- **New findings** - back to step 2, increment the round.
- **Approved** - stop. Do not re-request again.
- **Bot workflow failed or never ran** - stop and report, per step 9.
- **5 rounds, no approval** - stop and report, per the loop guard, leading with the recommendation to
  split the PR.

## After approval

An approval still ships threads, often a dozen or more. Handle them - fix and resolve - **without
another round trip**.

Then be precise about what the approval covers: if any commit landed after the approved `commit_id`,
say plainly that the standing approval sits on the pre-fix commit rather than implying the current
head is approved. Whoever merges needs to know.

## Reporting back

Each round, keep it short: which threads you fixed, which you declined and why, what you pushed, the
CI state, and the current verdict with the commit it points at. At the end: the final verdict, whether
the approval covers the head commit, and anything still open.

Never submit a review, approve, or merge on the user's behalf unless they explicitly asked for that on
this PR. If branch protection blocks a merge, report what is blocking rather than overriding it.
