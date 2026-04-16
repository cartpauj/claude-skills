# claude-skills

A collection of skills for [Claude Code](https://claude.com/claude-code).

## Skills

| Skill | Description |
|---|---|
| [localwp-connect](./localwp-connect) | Connect Claude Code to a LocalWP (Local by Flywheel) site — generates a WP-CLI wrapper, records DB/URL/admin facts in `CLAUDE.md`, and optionally symlinks the project into the site's plugins dir. |
| [localwp-disconnect](./localwp-disconnect) | Undo everything `localwp-connect` set up — removes the symlink, the `bin/wp` wrappers, and the `CLAUDE.md` block. |
| [html-to-image](./html-to-image) | Generate pixel-perfect PNGs from code-drawn HTML/CSS using Playwright + ImageMagick. Ideal for banners, icons, logos, social cards, and plugin assets — not photorealistic imagery. |

## Installing a skill

Copy the skill folder into `~/.claude/skills/`:

```bash
cp -r localwp-connect ~/.claude/skills/
```

Then invoke it in Claude Code with `/localwp-connect` (or let Claude trigger it from context).
