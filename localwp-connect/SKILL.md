---
name: localwp-connect
description: Connect Claude Code to a LocalWP (Local by Flywheel) site on Windows, macOS, or Linux. Discovers the site, generates a WP-CLI wrapper, writes a CLAUDE.md section with site facts, and optionally symlinks the current project into the site's plugins directory. Trigger when the user asks to connect / link / hook up / wire up a LocalWP site.
---

# LocalWP Connect

Makes the **current working directory** aware of a chosen LocalWP site — DB creds, paths, URL, admin user, and a working `wp` command — and optionally symlinks the project into the site's plugins dir.

All heavy lifting is done by `connect.php`, which runs under LocalWP's own bundled PHP (guaranteed to exist since Local is the prerequisite). Your job is just the bootstrap and the user interaction.

## Step 1 — Find LocalWP's config dir and bundled PHP

Detect platform via `uname -s`. Check candidates **in order**; take the first whose `sites.json` exists:

| Platform | Config dir candidates |
|---|---|
| Linux | `$XDG_CONFIG_HOME/Local`, `$HOME/.config/Local`, `$HOME/.var/app/com.getflywheel.local.Local/config/Local`, `$HOME/snap/local/current/.config/Local` |
| macOS | `$HOME/Library/Application Support/Local`, `$HOME/Library/Application Support/Local by Flywheel` |
| Windows (Git Bash) | `$APPDATA/Local`, `$HOME/AppData/Roaming/Local` |

If nothing matches, tell the user Local doesn't appear to be installed (or has never been run) and stop. Do not prompt for a path unless the user offers one.

Once `CONFIG_DIR` is set, pick a bundled PHP **version 8.0 or newer** (connect.php requires 8.0+). Local may retain old 7.x dirs for legacy sites — filter them out before sorting:

```bash
PHP_DIR=""
while IFS= read -r d; do
  ver="${d##*/php-}"; ver="${ver%%+*}"         # e.g. "8.2.29"
  major="${ver%%.*}"
  [ "$major" -ge 8 ] 2>/dev/null && PHP_DIR="$d"
done < <(ls -1d "$CONFIG_DIR/lightning-services"/php-*/ 2>/dev/null | sed 's:/$::' | sort -V)
# $PHP_DIR is now the highest 8.0+ php dir (or empty if none).
[ -z "$PHP_DIR" ] && { echo "No PHP 8.0+ found in Local's lightning-services"; exit 1; }
```

Detect the arch subdir by probing (Apple Silicon uses `darwin-arm64`; modern Windows uses `win64`):

```bash
case "$(uname -s)" in
  Linux*) ARCH=linux ;;
  Darwin*)
    if [ "$(uname -m)" = "arm64" ] && [ -d "$PHP_DIR/bin/darwin-arm64" ]; then ARCH=darwin-arm64
    else ARCH=darwin; fi ;;
  MINGW*|MSYS*|CYGWIN*)
    if [ -d "$PHP_DIR/bin/win64" ]; then ARCH=win64; else ARCH=win32; fi ;;
esac
# PHP layout differs on Windows: bin/<arch>/php.exe (no nested bin/).
case "$ARCH" in
  win*) PHP_BIN="$PHP_DIR/bin/$ARCH/php.exe" ;;
  *)    PHP_BIN="$PHP_DIR/bin/$ARCH/bin/php" ;;
esac
```

On Linux/macOS, Local's PHP needs its shared libs on the loader path:
```bash
export LD_LIBRARY_PATH="$PHP_DIR/bin/$ARCH/shared-libs${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
# macOS also: export DYLD_LIBRARY_PATH="$PHP_DIR/bin/$ARCH/shared-libs${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}"
```

On Windows, DLLs resolve from the php.exe directory — no loader env var needed.

## Step 2 — List sites

```bash
"$PHP" "${CLAUDE_SKILL_DIR}/connect.php" --config-dir="$CONFIG_DIR" --list-sites
```

Returns JSON: `{"status":"ok","sites":[{"id","name","domain","path"},...]}`.

## Step 3 — Pick the site (one question at most)

- If the user already named a site in their prompt, match it against the list (case-insensitive exact → substring). Single match → use it without asking.
- Otherwise, use AskUserQuestion with the site names as options.

## Step 4 — Ask about the symlink

**Always ask the user** before creating a plugin symlink — never infer. Use AskUserQuestion with a yes/no. Mention the exact target path in the question so the user sees what will happen:

> Symlink this project into `<site path>/app/public/wp-content/plugins/<basename of $PWD>` so your edits are live?

If the current directory looks like a plugin (top-level `*.php` has a `Plugin Name:` header), you may note that as context — but still require an explicit answer. Default stance: don't assume.

## Step 5 — Connect

```bash
"$PHP" "${CLAUDE_SKILL_DIR}/connect.php" --config-dir="$CONFIG_DIR" --site="<name-or-id>" --symlink=<yes-or-no>
```

Pass `--symlink=yes` or `--symlink=no` based on the user's answer. Do **not** use `--symlink=auto` from this skill.

The script outputs JSON with everything it did: site facts, wrapper paths, CLAUDE.md status, symlink status, and a `router_recommendation` flag.

**If the output contains `"action":"blocked_*"` under `symlink`**, an existing file/dir is in the way. Ask the user (AskUserQuestion):
- `blocked_symlink_elsewhere` → re-run with `--symlink-force=replace` (or skip)
- `blocked_real_directory` → re-run with `--symlink-force=rename-bak` (or skip)

## Step 6 — Verify

Run the wrapper end-to-end:

```bash
./bin/wp core version
./bin/wp user get <admin_id> --field=user_login
```

(On native Windows: `bin\wp.cmd`.) Both must succeed. If either fails, report the failure — don't claim success.

## Step 7 — Report

Print a concise summary using the JSON output: site name, URL, router mode, DB socket, WP-CLI check ✓, CLAUDE.md status, symlink status.

If `router_recommendation` is true (router mode is `site-domains`), add:

> **Recommendation:** switch LocalWP to localhost router mode (Preferences → Advanced → Router Mode → Localhost), then restart the site. Avoids hosts-file edits, sudo prompts, and `*.local` DNS issues on corporate networks/VPNs.

End with: "Start a new Claude session in this directory and I'll pick up the site context from CLAUDE.md automatically."

## Guardrails

- **Never write inside the Local Site** except the optional plugin symlink. Do not edit `wp-config.php`, `router.json`, or site files.
- **Never silently overwrite** an existing file at the symlink target. Always re-prompt through a `--symlink-force=` flag.
- **Idempotent re-runs.** The script replaces the CLAUDE.md block in place and overwrites `bin/wp*` — running twice converges.
- **WSL note.** If the site path resolves to `/mnt/c/...`, the symlink result will include a `note` about cross-filesystem visibility — surface it to the user.
