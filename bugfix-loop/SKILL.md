---
name: bugfix-loop
description: Iterate on your own bug-fix PR until an automated reviewer approves it. Self-reviews the diff before every push, runs narrow checks on changed code only, appends a per-round log to the PR description because the bot only reads that, replies to and resolves every thread, re-requests review, then polls the bot's own check runs and repeats — with a loop guard. Trigger when the user asks to work a PR through review, respond to review-bot findings, address review comments, or keep going until a PR is approved.
---

# Bugfix Loop

Drive **your own** PR through repeated automated review until it's approved, without burning rounds
or looping forever.

The `review` skill's standards apply here in full — verify assumptions, search for the pattern, the
quality pass, the scope filter. Read it and hold your own diff to it. This skill is only the loop
mechanics on top.

(For a human's Request Changes review, use `fix-request-changes`.)

## The two facts that make this converge

1. **The bot reads only the PR description.** Not comment threads. Anything explained only in a
   thread reply is invisible next round, so the same finding comes back forever. Every decision you
   want to stick goes in the **body**.
2. **Nothing retriggers the bot except re-requesting it as a reviewer.** Not a push, not a body
   edit. Hence the order: push → update body → re-request.

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

Then verify it twice, the way `review` does:

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

The **declined** entries are what stop findings recurring — this is the bot's only input.

One allowance: if a sentence in the original summary is now factually untrue, correct that sentence.
Don't restructure around it.

```
gh api -X PATCH repos/ORG/REPO/pulls/PR -F body=@newbody.md
```

Use this rather than `gh pr edit --body-file`, which fails on some orgs with a projects-classic
deprecation error.

### 7. Reply to and resolve every thread

Reply by REST using the first comment's `databaseId` from step 1; resolve by GraphQL using the
thread's node `id`. Resolving has no REST equivalent.

```
gh api -X POST repos/ORG/REPO/pulls/PR/comments/COMMENT_DATABASE_ID/replies -F body=@reply.md
gh api graphql -F threadId=THREAD_ID -F query=@graphql/resolve-thread.graphql
```

Declined threads get resolved too, with the reason.

### 8. Re-request review

```
gh api -X POST repos/ORG/REPO/pulls/PR/requested_reviewers -f reviewers[]=BOT
```

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
