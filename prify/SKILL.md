---
name: prify
description: Take a GitHub issue from link to open PR. Asks who reviews and whether cspf-services should review, fixes it locally, tests and self-reviews it, pushes, opens the PR assigned to me, assigns the issue to me and sets its points, then hands off to bugfix-loop if cspf-services is reviewing. Trigger on /prify <issue link>, or when the user asks to fix an issue and open a PR for it.
argument-hint: <issue URL>
---

# Prify

Issue link in → PR out. Target: `$ARGUMENTS`

The `review` skill's standards apply to your own fix in full. `bugfix-loop` covers how the bot
behaves (scoring, tags, re-requests). Read both, and don't repeat their rules here.

## Step 0: Ask first, before anything else

Your first action is one `AskUserQuestion` call with two questions. Don't read the issue first.

1. **"Who should review this PR (human)?"** with the options *Nobody* or *Someone (type their GitHub
   login under Other)*. If they give a name rather than a login, look the login up before using it.
2. **"Should cspf-services review it?"** with the options *Yes* or *No (adds the `no review` label)*.

If no issue link was passed, ask for one in the same call.

## Rules

1. **The PR's first state is the one that counts.** On Caseproof, opening a non-draft PR starts the bot
   right away, scored on the title and labels it opened with. So test and rehearse **before**
   `gh pr create`, and get the title, labels and `no review` right in that one call.
2. **Never force-push. Never merge.**
3. **Stay in scope.** Fix the issue. Other problems you find go in the final report, not in this PR.
4. **Stop and ask if the issue is unclear**, or if the fix needs a product call (behavior change,
   new setting, wording users will see). Don't guess.

## Step 1: Read the issue

```
gh issue view URL --json number,title,body,labels,assignees,comments,repository
```

Read every comment. Note any linked or companion issues and PRs.

Then assign it to me right away, so nobody else picks it up:
```
gh issue edit URL --add-assignee @me
```

## Step 2: Set up

- **Repo and base branch.** The base is the repo's default branch unless the issue says otherwise.
  `gh repo view ORG/REPO --json defaultBranchRef,viewerPermission`. Anything less than `WRITE`: stop.
- **Find the local clone.** Look under `~/caseproof/github/`. If there's none, ask before cloning.
- **Work in a worktree** off a fresh base:
  `git fetch origin && git worktree add <scratch>/wt-ISSUE -b fix/ISSUE-short-slug origin/BASE`.
  Match the repo's branch naming if it clearly uses another one (check `git branch -r`).
- **membercore note:** MemberPress fixes go in `membercore`, not the generated `memberpress` build.

## Step 3: Fix

Find the root cause, not just the symptom. **Search for the pattern**: if the same bug exists at
other call sites, fix those too. If you touched a shared API, check the downstream repos
(add-ons that call it).

## Step 4: Test

- **Narrow checks only**, on the files you touched, using the project's own declared commands
  (`composer.json`, `package.json`, `Makefile`, CI workflow).
- **Tests:** if the repo has a suite, add or update a test that **fails with the fix reverted**.
- **Real run when it matters:** if the fix changes something visible in WordPress, try it on a LocalWP
  site with `lwp` (see `lwp --help`) when one is set up for this plugin. If you can't, say so in the
  report rather than claiming it's tested.

## Step 5: Self-review and rehearse

Do `bugfix-loop` Step 3 against `git diff origin/BASE...HEAD`: the `review` skill's checks, the
bot rehearsal (only if cspf-services is reviewing), the subagent fan-out, and verifying every
finding yourself. Then the sweep list at the end of that step. Fix what holds up, then re-run Step 4.

Commit naming the defect and the fix. Push: `git push -u origin BRANCH`.

## Step 6: Open the PR

**Title:** `type(scope): summary`, using the most sensitive scope that's true (see `bugfix-loop`,
"Opening the PR"). **Never** use `chore`/`docs`/`test` on payment, auth or security code.

**Body** (write it to a file):
```
Closes ORG/REPO#N

## What was wrong
<the root cause, in plain words>

## What changed
<file:line level, short>

## Testing
<what you actually ran and what you couldn't>
```

**Pre-flight** (see `bugfix-loop`): every label exists (create any that are missing, never drop one),
and every reviewer and assignee has access.

```
gh pr create -R ORG/REPO --base BASE --head BRANCH \
  --title "..." --body-file body.md \
  --assignee @me \
  [--reviewer HUMAN_LOGIN] \
  [--label scope-labels] [--label "no review"]   # both only if they answered No to cspf-services
```

- **cspf-services = No:** pass `--reviewer HUMAN_LOGIN` (if they picked one) and `--label "no review"`.
- **cspf-services = Yes:** **no** human reviewer yet. They're added after the bot approves (Step 8).
  Don't pass `--reviewer cspf-services` either. The ruleset requests it on open.

Confirm the PR head matches your pushed commit.

## Step 7: Points and project

Estimate points by **Paul's effort**, not how fast you did it, on the scale in `~/.claude/CLAUDE.md`
(1, 3, 5, 8, 13; no 2). Then:
```
python3 ~/caseproof/github/email-tasks/auto/gh-project.py start ORG/REPO#ISSUE POINTS
```
This adds the issue to MemberCore project #8 if needed and sets Status=In Progress,
Quarter=current and Points.

## Step 8: Report, then hand off

Short report: PR link, root cause in one line, what you tested (and what you didn't), points and why,
who's reviewing, and anything out of scope you noticed.

- **cspf-services = No:** done. Remove the worktree.
- **cspf-services = Yes:** invoke the `bugfix-loop` skill on the new PR. Tell it the first review was
  requested automatically when the PR opened, so it skips opening and re-requesting and starts at
  Step 9 (poll for the first review). Hand the worktree over to it. bugfix-loop removes it when the
  loop ends.

  **When the loop ends:** if the bot approved and its leftover threads are fixed and resolved, now
  request the human reviewer (if one was picked):
  `gh api -X POST repos/ORG/REPO/pulls/PR/requested_reviewers -f 'reviewers[]=HUMAN_LOGIN'`.
  If the loop stopped without an approval (5 rounds, or the bot broke), **don't** add the human.
  Report why it stopped and let Paul decide.
