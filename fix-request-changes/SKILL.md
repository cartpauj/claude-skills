---
name: fix-request-changes
description: Work a Request Changes review on your own PR — verify each requested change against the real code, fix the ones that hold up, push back on the ones that don't, resolve every thread with what was done and why, open issues for verified unrelated findings, post a summary, and re-request review. Trigger when a reviewer requests changes, leaves comments to address, or the user asks to respond to review feedback on their PR.
---

# Fix Request Changes

Someone reviewed your PR and asked for changes. Verify each request, act on the ones that hold up,
say why for the ones that don't, and hand it back ready to merge. One pass.

(For an automated review bot that re-runs every round, use `bugfix-loop`. For reviewing someone
else's PR, use `review`.)

## Rules

1. **A requested change is a claim, not an instruction.** Verify it against the code before you act.
   Fixing something that isn't broken makes the PR worse and wastes the next reviewer's time.
2. **Push back with evidence, never with opinion.** Declining needs `file:line` and what actually
   happens — not "I think this is fine."
3. **Stay in the PR's scope.** Unrelated findings become issues, not commits. Scope comes from the
   linked issue and the PR description, not from what a reviewer happened to notice.
4. **Never open an issue you haven't verified in the code.** No speculative tickets.
5. **Never force-push.** It detaches existing review comments. Add commits.
6. **Resolve every thread** — accepted, declined, or already-fixed — each with a reply saying what
   you did and why.
7. **Comments in code only where the intent isn't obvious.** Never narrate what the line does.

## Step 1 — Ground yourself

Confirm it's your PR, then read the **linked issue and PR description first** — that's your scope
boundary for everything below.

```
gh pr view PR -R ORG/REPO --json title,body,author,headRefName,headRefOid,baseRefName,mergeable
gh pr diff PR -R ORG/REPO
```

Work in a throwaway worktree, not the shared checkout — another session may hold it:

```
git worktree add /path/to/scratch/wt-PR origin/BRANCH
```

## Step 2 — Collect every request

Reviews and inline threads are separate; you need both.

```
gh api repos/ORG/REPO/pulls/PR/reviews --jq '.[] | {user: .user.login, state: .state, commit: .commit_id[0:9], body}'
gh api graphql -F owner=ORG -F repo=REPO -F pr=PR -F query=@graphql/review-threads.graphql
```

**Paginate `reviewThreads`** — follow `pageInfo.hasNextPage` with `after: $cursor`. If the returned
count equals the page size, assume there's more. A truncated page silently hides whole rounds.

Sort what you get:

- **Human vs bot.** Both get a re-request at the end. Don't assume a bot re-runs on push — most
  don't, and a PR left waiting on a review nobody triggered stalls silently.
- **Live vs stale.** `isOutdated` means the code moved under the comment. Re-read that code before
  assuming the point still stands — it may already be fixed.
- **Conflicting requests.** If two reviewers want opposite things, don't pick a side silently. Ask
  the user which way to go.

## Step 3 — Triage each request against the code

For every request, open the file and decide with evidence:

- **Holds up** → fix it (Step 4).
- **Doesn't hold up** → the code already handles it, the premise is wrong, or the suggestion breaks
  something. Note `file:line` and the concrete reason; you'll reply with it in Step 7.
- **Right but out of scope** → real, verified, unrelated to this PR's problem. Issue, not commit.
- **Preference with no runtime effect** → decline briefly, unless the project's linter enforces it.

## Step 4 — Fix what holds up

- Smallest change that resolves the point. Don't refactor around it.
- **Keep tests current.** A behavior change updates its tests; a real bug fixed here gets a test
  that fails without the fix. If the project has no suite, add nothing.
- Comments only where the *why* isn't obvious from the code. Delete any comment your change made
  untrue.
- Don't fix out-of-scope things you noticed on the way. They're Step 8.

## Step 5 — Verify twice, before committing

**Pass one — fan out.** Subagents in parallel over the changed code: one on whether each fix
actually resolves the request, one on what the fixes might have broken, one on tests, one on
comments and docblocks. Tell each: report only what the code proves, name the input and the failure,
never raise a finding from a comment or a commit message, zero findings is a good outcome.

**Pass two — re-verify yourself.** Open the code and confirm each finding independently. Subagents
are confidently wrong often enough to matter; anything you can't reproduce by reading gets dropped.

**Fix everything that survives pass two before you commit.** Then re-read the whole diff
(`git diff origin/BASE...HEAD`) as one change, not as a list of patches.

## Step 6 — Commit and push

One commit per logical fix, or one clean commit for the round. Reference the thread or reviewer
where it helps a future reader. Then push — **no force, no rebase, no amend** on a branch under
review.

## Step 7 — Resolve every thread

Reply first, then resolve. Every thread, including ones you declined and ones that were already
fixed.

```
gh api graphql -F threadId=THREAD_ID -F body="..." -F query=@graphql/reply-thread.graphql
gh api graphql -F threadId=THREAD_ID -F query=@graphql/resolve-thread.graphql
```

Each reply says **what action was taken and why**, with links — the commit SHA that fixed it, the
issue opened for it, a related PR, or the `file:line` proving it needed no change. A resolved thread
with no explanation reads as ignored.

## Step 8 — Issues for verified unrelated findings

Only for things you confirmed in the code and deliberately left out of scope.

**Search for duplicates first:**

```
gh issue list -R ORG/REPO --search "KEYWORD" --state all --limit 20
```

If one exists, link it in the thread reply instead of opening another. Otherwise open one with the
`file:line`, what actually goes wrong, and how to reproduce — then link it back into the thread that
raised it.

## Step 9 — Summarize and hand back

Post one comment on the PR:

```
gh pr comment PR -R ORG/REPO --body-file FILE
```

Covering: **what was fixed** (with commit links), **what was declined and why** (with the evidence),
**what became an issue** (with links), and anything you need the reviewer to decide. Keep it short
enough to read in the notification.

Then re-request review from **everyone** who requested changes, bots included — a review bot
generally does not re-run on push, so an un-triggered one leaves the PR stalled with no one aware:

```
gh pr edit PR -R ORG/REPO --add-reviewer LOGIN
```

If a reviewer won't re-add (GitHub refuses a re-request in some states), say so in the summary
comment and `@`-mention them there instead of leaving it silent.

## Step 10 — Confirm it's actually green

Pushing isn't finishing. Wait for the checks and read them:

```
gh pr checks PR -R ORG/REPO
```

If something you changed broke CI, fix it now — don't hand back a red PR. A coverage-threshold nag
is not a test failure. Report the final state plainly, including anything still red and why.
