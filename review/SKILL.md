---
name: review
description: Review someone else's pull request, branch, or diff and deliver a verdict. Covers correctness, security, reuse, separation of concerns, simplicity, tests, maintainability, and scope discipline. Produces concrete findings, a list of what was verified as correct, and a Request Changes / Approve call. Trigger when the user asks to review a PR, look over a branch, check someone's changes, or asks whether something is good to merge.
---

# Review

Review someone else's change and reach a verdict. One pass, no loop.
(For your own PR against a review bot, use `bugfix-loop`.)

## Rules

1. **Pick the verdict last.** Findings first, then the word. Never "approve, but fix these."
2. **Post it as a real review under the user's identity** — `--approve` or `--request-changes`,
   never a bare comment. A comment doesn't satisfy branch protection. Post whichever way the
   findings fell.
3. **Findings, not commits.** Don't push to someone else's branch.
4. **No follow-up tickets.** Loose ends get handled in this review.
5. **Don't re-run CI** — read the check results. Narrow checks on changed code are fine. If
   confirming needs a running app or real credentials, say so as a finding instead of guessing.
6. **If you write "not blocking" about a code change — it's blocking.**

## Step 1 — Target and context

Read the **linked issue first**, before the author's framing. No issue? Ask what problem it solves.

```
gh pr view PR -R ORG/REPO --json title,body,headRefOid,baseRefName,files
```

**Read every comment and thread before reviewing** — so you don't re-raise settled points, and so
you see what's still open.

```
gh pr view PR -R ORG/REPO --comments
gh api repos/ORG/REPO/pulls/PR/comments --jq '.[] | {user: .user.login, path, line, body}'
```

Look for: what a bot or earlier reviewer already raised, which threads are answered vs. still
hanging, and what the author committed to doing. **An author's "fixed in abc123" is a claim, not
evidence** — check the commit actually does it. A thread marked resolved with nothing changed is a
finding. Unresolved threads on real problems belong in your verdict.

**Find companion PRs before deciding.** Changes often ship as a set — core plus add-on, service plus
client — and approving one half merges a contract that only works when the other lands.

```
gh search prs --owner ORG --state open --author AUTHOR --json repository,number,title,headRefName
gh search prs --owner ORG --state open --head BRANCH_NAME --json repository,number,title,url
```

Also read the PR body and issue for cross-links in prose. For each companion, read its diff and
check the pair: do both sides agree on names, shapes, types, error handling? Does either depend on
something the other only adds? What merge order is required? Findings against a companion go in
*this* review. If the change implies a companion that doesn't exist — an endpoint nothing calls —
that absence is the finding.

## Step 2 — Fan out to subagents

Always, regardless of size. Slice a large diff by area; slice a small one by concern (correctness,
security, tests, comments, companion repos). One subagent per slice, in parallel, same standards.

Tell each one, explicitly:

- **Report only what the code proves.** Name the input or state that reaches it and what breaks.
  "Consider whether" is not a finding.
- **Never raise a finding from a comment, docblock, or the PR description.** Those are claims about
  the code, not the code.
- **Style and preference are not findings.**
- **Zero findings is a good outcome.** Padding wastes the author's day.

Require `file:line`, the failure scenario, and a fix on every finding — plus what it verified as
correct.

## Step 3 — Verify everything yourself

**Never forward a subagent finding.** Open the code and confirm the failure actually holds. What you
can't reproduce by reading gets dropped, or demoted to a question for the author.

Then check what the diff *assumes*: do the helpers exist and return what's claimed? Any side effects
or ordering it ignores? **Is the method that runs the one you think runs** — check overrides, traits,
base classes. Does it hold for zero, empty, null, the default case?

And the highest-value question in any bug fix: **where else does this pattern live?** A fix at one
call site routinely leaves the defect at three others. Search for the predicate being corrected. For
shared APIs, clone dependent repos shallow into scratch (never into the user's checkouts) and search
for callers and subclasses.

## Step 4 — Quality

**Security** (blocks like any correctness bug) — input validated where it enters, escaped where it
leaves? Permission and ownership checks on every new path, not just the happy one? Secrets in the
diff? User input reaching a query, path, shell, redirect, or deserializer?

**Reuse** — does a helper for this already exist? Is there now a second source of truth for one rule?
This is the most common miss and often *is* the fix.

**Simplicity** — simplest thing that solves the reported problem? Any abstraction with one caller?
Flags nobody asked for? Business logic in a template, or queries in a request handler?

**Tests** (only if a suite exists) — a bug fix without a test is a finding. Review the test, not its
presence: **would it still pass with the fix reverted?** Does it assert the bug by mistake? Is it
tautological, asserting a mock or its own setup? Does it cover the edge case that caused the bug? Is
it green because the code under it never ran?

**Comments** — a wrong comment is a finding, not a nit; the next person will trust it. Does each
comment the change touches still describe what the code now does? Do docblocks match the signature —
params, types, nullability, `@throws`? Does any comment claim something the code doesn't do? This is
the one place a comment *is* the finding.

## Step 5 — Scope

The test isn't "was it in the diff" — it's whether the change is **responsible** for it.

**In:** what the diff causes, what it newly reaches, what it leaves half-finished at another call
site, what it breaks downstream or in a companion repo.

**Out:** pre-existing problems it merely sits near, a refactor you'd have written differently,
"while you're in here," style the linter doesn't enforce.

Out-of-scope items get said once as a closing note, never attached to the verdict. You're not
obliged to carry a bot's out-of-scope findings into your review.

## Step 6 — Final pass, yourself

Not delegable. After the subagents are in and verified, read the whole diff again as one thing and
look for what no slice could see: contradictions between slices, a contract changed in one place and
consumed in another, duplicated logic across boundaries, the companion repos as a pair.

Then check, don't assume — never assert a repo fact from memory. Confirm the merge target, and read
the project's declared lint/test commands (`composer.json`, `package.json`, CI workflow) rather than
inventing them. Conventions you can't query — "this branch takes release merges only" — get asked
about.

**Distrust stale approvals.** A bot usually approves an older commit; compare PR head against the
review's `commit_id` and read anything pushed since yourself.

```
gh api repos/ORG/REPO/pulls/PR/reviews --jq '.[] | {user: .user.login, state: .state, commit: .commit_id[0:9]}'
```

## Step 7 — Verdict and post

The test is **"does this need a code change?"** — not "is this severe?"

**Request Changes:** wrong behavior on a reachable path, a security gap, a missing guard, a comment
documenting the wrong half of the change, an undocumented behavior change, a missing or wrong test
on a bug fix, a defect surviving at another call site, a companion mismatch.

**Doesn't block:** a missing description section, a changelog line, formatting the linter ignores,
preference with no runtime effect. Note it, or just fix it.

On a re-review, if every substantive finding is resolved, approve. Trivia doesn't earn another round
trip — decide it yourself.

Write the body to a file, then post:

```
gh pr review PR -R ORG/REPO --approve --body-file FILE
gh pr review PR -R ORG/REPO --request-changes --body-file FILE
```

**Body:** verdict · findings (file:line, what breaks, on which flow, suggested fix) · **verified as
correct** (most of the value — it stops the author re-litigating settled points) · non-blocking
notes · confidence, separating what you read from what you ran, claiming no runtime verification you
didn't do.
