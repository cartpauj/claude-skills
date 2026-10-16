---
name: bugfix-loop
description: Iterate on your own bug-fix PR until an automated reviewer approves it. Opens it with a risk-accurate title and labels, rehearses the bot's own review locally before every push, runs narrow checks on changed code only, replies to every thread with the [fixed]/[dismissed] tags the bot reads, appends a per-round log to the PR description, re-requests review, then polls the bot's own check runs and repeats — with a loop guard. Trigger when the user asks to work a PR through review, respond to review-bot findings, address review comments, or keep going until a PR is approved.
---

# Bugfix Loop

Drive **your own** PR through repeated automated review until it's approved, without burning rounds
or looping forever.

The `review` skill's standards apply here in full — verify assumptions, search for the pattern, the
quality pass, the scope filter. Read it and hold your own diff to it. This skill is only the loop
mechanics on top.

(For a human's Request Changes review, use `fix-request-changes`.)

## The facts that make this converge

1. **The bot reads the diff, PR body, linked issues, and earlier threads** — but a thread only
   counts through a **tag at the start of its newest reply** (below). An untagged reply, or a
   resolve with no reply, gets the finding reviewed again from scratch. That's why findings recur.
2. **Nothing retriggers the bot except re-requesting it as a reviewer.** Not a push, not a body
   edit. If it already approved, remove it and add it back. Hence the order: push → reply → body →
   re-request.
3. **Every round spends the team's monthly AI budget**, and bigger or more sensitive PRs cost more
   (more models). Rehearse locally; request the bot only when you expect an approval.

**Thread reply tags — the bot reads only these.** On a devkit-based bot (`cspf-services`), the
next round reads each thread's **newest reply** and acts on a tag at its very start:

| Newest reply starts with | Next round |
|---|---|
| `[fixed <sha>]` (4–40 hex, the fixing commit) | Checks that commit once; drops the finding if it holds and nothing changed since |
| `[dismissed] <reason>` | Never raises it again; the reason is shown in its review |
| Anything else — no tag, plain "Fixed", `[dismissed]` with no reason, resolved with no reply | **Reviews it again from scratch** |

Resolving a thread does nothing for the bot. Only the tag counts, and only on the newest reply — a
later untagged reply (yours or anyone's) cancels it. Tag first, then the human-readable why:
`[fixed a1b2c3d] Escaped with esc_attr() at class-foo.php:42.`
`[dismissed] Pre-existing; this PR doesn't touch that path. Tracked in #123.`

## How deep the bot looks (caseproof `cspf-services`)

A planner scores the PR 0–6 when review is requested; higher = more models (up to 4), more cost.

- **Size:** 6+ files or 500+ changed lines adds points (lock files, `vendor/`, `dist/`, `*.min.js`
  don't count). If ≥80% of lines sit in the top 3 files, size drops by one.
- **Keywords** from the title's `type(scope):` (or a leading `[tag]`) and **exact** labels, on the PR
  or linked issues. Up: auth, authentication, billing, cart, checkout, fees, migration, payment(s),
  paypal, ppcp, permission, privacy, security, stripe. Down (size only): chore, ci, docs, test(s),
  deps, dependencies.
- **Risky paths** from `.github/ai-code-review.yml` on the base branch — none unless a repo sets them.
- **Skipped:** drafts (not auto-requested), forks, the `no review` label (on the PR, not the issue).

The plan job's log shows the `breakdown` and `reason` lines if you need to check.

## Rules

1. **Fix only what needs fixing; reply to and resolve every thread either way.** The bot raises
   pre-existing code, out-of-scope items and style freely. Declining is a valid answer — say why.
2. **Never force-push.** It re-anchors or outdates every thread, throwing away the round you just
   paid for. Rewrite history after the loop, if ever.
3. **Never rewrite the PR body** — append to it. Dropping `Closes #N` silently stops the issue
   auto-closing and nobody notices for months.
4. **Don't gate on CI.** A red build doesn't stop the bot reviewing. Exception: if CI is red
   *because of your change*, fix it now — the bot will flag it anyway.
5. **Don't run the full suite or a whole-repo lint** — CI does that. Only the files you touched and
   the tests covering them.
6. **Never approve or merge on the user's behalf** unless they asked for that on this PR.

## Opening the PR — the first review starts the moment it opens

The bot is auto-requested on every non-draft PR, so the title and labels it plans from are the ones
you open with. Get them right **in the `gh pr create` call**:

- **Title** `type(scope): …` with the most sensitive scope that's true — `fix(security): …`,
  `fix(stripe): …`. Never `chore`/`docs`/`test` on code that touches payments, auth or security.
- **Labels** matching what it touches (`security`, `payment`, `stripe`, `auth`, `checkout`…).
- **Pre-flight before `gh pr create`:** make sure every label, assignee and reviewer you're about to
  use exists. A missing label fails the create — or, if you retry without it, opens the PR unlabeled
  and the bot plans a lighter review that can't be undone. Create missing labels; never drop them.

```
# Labels: create any that are missing (gh pr/issue create fails on a missing one).
for L in security payment; do
  gh label list -R ORG/REPO --limit 500 --json name --jq '.[].name' | grep -qixF "$L" \
    || gh label create "$L" -R ORG/REPO
done
# People: every assignee/reviewer must have access (204 = OK), or the create fails.
gh api repos/ORG/REPO/collaborators/LOGIN --silent && echo ok
```
- **Not ready?** Open it as `--draft`; mark ready only after the local rehearsal (Step 3) is clean.
- **Keep it small.** Small, concentrated PRs score lower, cost less, and converge faster.

## Loop guard

Track the round. **Stop and report after 5 rounds without approval** — or immediately if a round
produces no new findings *and* no new commits, which means you're re-litigating, not converging.

**If you're still going at 5 rounds, the PR is too big.** These reviewers degrade badly on large
diffs, finding new things forever on a big PR while converging in two rounds on a small one. Lead
with that: recommend splitting into smaller independently reviewable PRs, name concrete seams from
what the diff actually touches, and separate mechanical churn from behavioral change.

Say this at the *start* too, if the PR is already big and sprawls across unrelated concerns — far
cheaper before five cycles than after.

## Setup — resolve once, don't assume

- **Repo, PR number, base branch.** Confirm all three; don't infer from the working directory.
- **The bot's login** (`BOT` below) — read it off the PR's reviews or requested reviewers, or ask.
  Never hardcode a guess.
- **`gh auth status`** must show `repo` scope, and `gh repo view ORG/REPO --json viewerPermission`
  must be `WRITE` or better. On `READ`, stop — you can't push or resolve threads.
- **Work in a throwaway worktree** so nothing moves under you and the shared checkout stays free:
  `git worktree add <scratch>/wt-PR BRANCH`, removed when the loop ends. Otherwise check
  `git status --porcelain` is empty before every commit, and never stash or reset someone else's
  work.

Long text goes to `gh` from a file (`-F body=@path`), never inline — it avoids shell quoting
entirely.

## Order of operations

### 1. Read the current state

```
gh api graphql -F owner=ORG -F repo=REPO -F pr=PR -F query=@graphql/review-threads.graphql
gh api repos/ORG/REPO/pulls/PR/reviews --jq '.[] | {user: .user.login, state: .state, commit: .commit_id[0:9]}'
```

**Paginate `reviewThreads`** — follow `pageInfo.hasNextPage`, passing `endCursor` back as
`-F cursor=...`. One page silently truncates and makes you report "no new threads" when a dozen
landed. If the count equals the page size, there's more.

Note which commit each verdict points at.

### 2. Triage every thread

Fix it / decline it / already handled. Apply the `review` skill's scope filter to your own work.

### 3. Fix, then self-review before committing

This is the step that saves rounds. Run the `review` skill's Steps 3–6 against your own diff —
including **searching for the pattern** (if the defect exists at other call sites, fix those too)
and **checking downstream repos** if you touched a shared API, since neither your diff nor CI will
show those.

Then verify it three ways:

- **Rehearse the bot.** Fetch its own instructions —
  `gh api repos/caseproof/caseproof-ai-plugins/contents/devkit/agents/code-reviewer.md -H "Accept: application/vnd.github.raw"` —
  and have one subagent apply that checklist to `git diff origin/BASE...HEAD` plus the linked issue
  and this round's tagged threads, returning findings and a verdict. Don't write its
  `.caseproof-dev/` output into the repo. Its checklist reports everything, so put each finding through
  the `review` scope filter: fix only what holds up **and** this PR caused or left half-done; anything
  else (pre-existing, nearby, style) gets declined in the body up front — never fixed here. This is the cheap round — the bot is the expensive one.

- **Fan out to subagents** over the changed code — one on whether the fixes hold, one on what they
  might have broken, one on tests, one on comments. Tell each: report only what the code proves,
  never raise a finding from a comment or commit message, zero findings is a good outcome.
- **Re-verify every finding yourself** before acting on it. Subagents are confidently wrong often
  enough to matter.

**Tests** (only if a suite exists): the test must **fail with your fix reverted**. One that passes
either way is worse than none — it looks like coverage.

Then sweep what automated reviewers reliably flag, so it doesn't cost a round: raw superglobals and
dynamic variable extraction; missing sanitization or escaping; missing permission/capability/
ownership checks on new paths; unquoted attributes in generated markup; dead or unreachable
branches; unused parameters; stale doc comments and comments your change made untrue; brace/indent
style against the surrounding file; behavior changes that break existing links or stored data.

### 4. Narrow checks

Only the files you touched. **Read the project's declared commands** (`composer.json`,
`package.json`, `Makefile`, CI workflow) rather than inventing an invocation — that's what CI runs.
Some repos ship no linter on purpose.

### 5. Commit and push

Re-check branch and tree state, commit naming the defect and the fix, push — never with force.
**Confirm it landed**, because re-requesting after a failed push wastes a whole round:

```
gh pr view PR -R ORG/REPO --json headRefOid --jq .headRefOid
```

### 6. Append this round to the body

Take the body from step 1, append, write the whole thing back:

```
## Review round N

- Fixed: <finding> — <what changed, file:line>
- Declined: <finding> — <why: out of scope / pre-existing / incorrect>
- Downstream: <repos checked, result>
```

The thread tags are what stop findings recurring; the body log is the backstop — it covers
findings raised only in a review body (no thread to tag) and gives humans the history.

One allowance: if a sentence in the original summary is now factually untrue, correct that sentence.
Don't restructure around it.

```
gh api -X PATCH repos/ORG/REPO/pulls/PR -F body=@newbody.md
```

Use this rather than `gh pr edit --body-file`, which fails on some orgs with a projects-classic
deprecation error.

### 7. Reply to and resolve every thread — with tags

Every reply starts with `[fixed <sha>]` or `[dismissed] <reason>` (see the table above); the
`<sha>` is the pushed commit that fixes it. Reply by REST using the first comment's `databaseId`
from step 1; resolve by GraphQL using the thread's node `id`. Resolving has no REST equivalent.

```
gh api -X POST repos/ORG/REPO/pulls/PR/comments/COMMENT_DATABASE_ID/replies -F body=@reply.md
gh api graphql -F threadId=THREAD_ID -F query=@graphql/resolve-thread.graphql
```

Declined threads get resolved too, with the reason. Before re-requesting, re-read each thread's
newest reply: if anyone replied after you, your tag no longer counts — reply again with it.

### 8. Re-request review

```
gh api -X POST repos/ORG/REPO/pulls/PR/requested_reviewers -f reviewers[]=BOT
```

If the bot has already approved, a plain re-request may not start a run: remove it first
(same path, `-X DELETE`), then add it back.

### 9. Poll — and watch for a bot that will never answer

The re-request in step 8 is what triggers the run; it lands with a lag. Poll about every 45 seconds
for a new review or new threads, comparing against this round's starting counts.

Each poll, check the bot's own check runs on the head commit:

```
gh api repos/ORG/REPO/commits/HEAD_SHA/check-runs --jq '.check_runs | map({name, status, conclusion})'
```

`queued`/`in_progress` → keep polling. Completed with a failure, or never started → **stop and
report**; the bot isn't going to answer. If nothing ever started, suspect the re-request never
registered rather than a slow bot. Say which state you saw, so it's clear whether you're waiting on
the bot or the bot is broken.

### 10. Repeat or exit

New findings → step 2, increment the round. Approved → stop, don't re-request again. Bot broken, or
5 rounds → stop and report.

## Finishing

An approval still ships threads, often a dozen. Fix and resolve them **without another round trip**.

Then be precise about what the approval covers: if any commit landed after the approved `commit_id`,
say plainly that the approval sits on the pre-fix commit rather than implying head is approved.
Whoever merges needs to know.

Each round, report short: what you fixed, what you declined and why, what you pushed, CI state, and
the current verdict with the commit it points at. At the end: final verdict, whether it covers head,
and anything still open. If branch protection blocks a merge, report what's blocking rather than
overriding it.
