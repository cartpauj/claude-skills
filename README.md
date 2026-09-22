# claude-skills

A collection of skills for [Claude Code](https://claude.com/claude-code).

## Skills

| Skill | Description |
|---|---|
| [localwp-connect](./localwp-connect) | Connect Claude Code to a LocalWP (Local by Flywheel) site — generates a WP-CLI wrapper, records DB/URL/admin facts in `CLAUDE.md`, and optionally symlinks the project into the site's plugins dir. |
| [localwp-disconnect](./localwp-disconnect) | Undo everything `localwp-connect` set up — removes the symlink, the `bin/wp` wrappers, and the `CLAUDE.md` block. |
| [html-to-image](./html-to-image) | Generate pixel-perfect PNGs from code-drawn HTML/CSS using Playwright + ImageMagick. Ideal for banners, icons, logos, social cards, and plugin assets — not photorealistic imagery. |
| [review](./review) | Review someone else's PR, branch, or diff and deliver a verdict — correctness, security, reuse, separation, simplicity, tests, maintainability, and scope discipline. One pass, no loop. Always fans out to subagents and re-verifies every finding itself, reads existing PR threads, checks companion PRs in other repos, and posts the verdict as a real review. |
| [bugfix-loop](./bugfix-loop) | Drive your own bug-fix PR through repeated automated review until approved — self-review before each push, append a per-round log to the description, resolve every thread, with a 5-round guard. |
| [fix-request-changes](./fix-request-changes) | Work a Request Changes review on your own PR — verify each request against the real code, fix what holds up, push back with evidence on what doesn't, resolve every thread with what was done and why, open issues for verified unrelated findings, then summarize and re-request review. |
| [baptism-program](./baptism-program) | Interview the user for names, dates and songs, then generate a print-ready, two-up baptism program HTML file (letter landscape, cut down the center for two 5.5×8.5in programs). |

The PR skills (`review`, `fix-request-changes`, `bugfix-loop`) require the
[GitHub CLI](https://cli.github.com) (`gh`) authenticated via `gh auth login`.

## Installing a skill

Copy the skill folder into `~/.claude/skills/`:

```bash
cp -r localwp-connect ~/.claude/skills/
```

Then invoke it in Claude Code with `/localwp-connect` (or let Claude trigger it from context).
