---
name: baptism-program
description: >
  Generates a print-ready, two-up baptism program HTML file from an interview with the user.
  Use this skill whenever the user asks to create, update, or generate a baptism program,
  order of service, or baptism bulletin — even if they just say "make a program for a baptism"
  or "I need to print programs." The skill interviews the user for all needed details, looks up
  song lyrics online and confirms them, then outputs the final HTML file directly for review.
---

# Baptism Program Generator

## Overview

This skill produces a baptism program HTML file by substituting tokens in `assets/template.html`.
The user opens the file in Chrome, reviews it, and requests changes. When done, they print:
**File → Print → Save as PDF | Letter | Landscape | Margins: None | Scale: 100%**
Then cut down the dashed center line — two finished 5.5×8.5in programs.

---

## Workflow

### Step 1 — Interview (one question at a time)

Ask each question as a plain chat message, one at a time. Wait for the answer before asking the
next. Do not dump a list. Do not use ask_user_input_v0.

Ask in this order:

1. "What is the child's full name?"
2. "What is the date of the baptism? (e.g. November 8, 2025)"
3. "Who is conducting? (name + title or relationship)"
4. "Who is the pianist?"
5. "Who is the chorister?"
6. "What is the opening song?"
7. "Who is giving the opening prayer?"
8. "Who is giving the talk on baptism?"
9. "Who will perform the baptism?"
10. "Who are the two witnesses? (e.g. John Smith (Uncle), Jane Doe (Aunt))"
11. "Who is giving the talk on the Holy Ghost?"
12. "Who will perform the confirmation?"
13. "Who is giving the closing remarks?"
14. "What is the closing song?"
15. "Who is giving the closing prayer?"
16. "Which songs should appear on the lyrics page? (I'll look up the lyrics automatically)"
17. "Anything else to add — a scripture, extra rows, a note? Or say 'no' to continue."

Then summarize everything back in a clean list and ask: "Does this all look correct?"
Wait for confirmation before continuing.

---

### Step 2 — Look Up Lyrics

For each song on the lyrics page:

1. Search: `"[Song Title]" LDS primary song lyrics site:churchofjesuschrist.org` or similar.
2. Show the user the lyrics and ask: "Here are the lyrics for [Song] — do these look correct?"
3. Wait for confirmation or corrections.
4. Note the verse/chorus structure.

If lyrics can't be found online, ask the user to paste them.

---

### Step 3 — Build the HTML (fast token substitution)

Read `assets/template.html`. It contains these tokens — replace every one, both occurrences:

| Token | Replace with |
|---|---|
| `{{CHILD_NAME}}` | Child's full name |
| `{{CHILD_FIRST_NAME}}` | Child's first name only |
| `{{DATE}}` | Baptism date (use `&nbsp;` between words) |
| `{{PERFORMED_BY}}` | Performer of ordinance |
| `{{WITNESSES}}` | Witnesses string (use `&middot;` between them) |
| `{{TALK_HOLY_GHOST_ROLE}}` | "Talk on the Holy Ghost" |
| `{{TALK_HOLY_GHOST_PERSON}}` | Person giving that talk |
| `{{CONFIRMATION_BY}}` | Person performing confirmation |
| `{{OPENING_ROWS}}` | Generated HTML for opening order rows (see below) |
| `{{CLOSING_ROWS}}` | Generated HTML for closing order rows (see below) |
| `{{SONG_BLOCKS}}` | Generated HTML for all songs (see below) |

**Important:** Every token appears exactly twice (once per panel). Replace all occurrences.

If the program requires content that doesn't fit neatly into a token slot — extra rows in the
middle, additional sections, a scripture, a fourth talk, etc. — read the surrounding HTML
structure and make the edit directly and intelligently. Always apply the same change to both
the left and right panel copies. Use the existing HTML patterns as a guide.

#### Order row HTML pattern
```html
<div class="order-row"><span class="order-role">Role</span><span class="order-dots"></span><span class="order-name">Person</span></div>
```

Opening rows (in order): Conducting, Pianist, Chorister, Opening Song, Opening Prayer, Talk on Baptism
Closing rows (in order): Closing Remarks, Closing Song, Closing Prayer

#### Song block HTML pattern
```html
<div class="song-heading"><span class="hr"></span>Song Title<span class="hr"></span></div>
<div class="verse">Line one<br>Line two<br>Line three</div>
<div class="verse chorus"><span class="chorus-label">Chorus</span>Chorus line one<br>Chorus line two</div>
```

Rules:
- Show verses first, chorus ONCE at the end. Do NOT repeat chorus after each verse.
- Separate songs with a blank line between the last stanza and the next song-heading.

---

### Step 4 — Auto-fit

After substitution, count content and adjust CSS in the `<style>` block if needed.

**Front panel:**

| Total order rows | Adjustment |
|---|---|
| ≤ 8 | None |
| 9–10 | `.order-list font-size: 11pt`, `.order-row margin: 2pt 0` |
| 11+ | `.order-list font-size: 10pt`, `.order-row margin: 1.5pt 0`, `.section-header font-size: 22pt` |

Child name > 14 chars → `.title-name font-size: 44pt`. > 18 chars → `36pt`.

**Back panel (safe text area = 6.45in):**

| Total lyric lines | Adjustment |
|---|---|
| ≤ 30 | None |
| 31–38 | `.verse font-size: 10.5pt`, `line-height: 1.3`, `margin-bottom: 5pt` |
| 39–46 | `.verse font-size: 9.5pt`, `line-height: 1.22`, `margin-bottom: 4pt`, `.lyrics-title font-size: 32pt` |
| 47+ | Also `.song-heading font-size: 7.5pt`, `margin: 5pt 0 4pt` |

---

### Step 5 — Output

Before saving, make the file self-contained by base64-encoding the greenery images and
replacing the relative src references:

```python
import base64, os

here = os.path.dirname('assets/template.html')  # skill assets folder
top = base64.b64encode(open('assets/greenery_top.png','rb').read()).decode()
bot = base64.b64encode(open('assets/greenery_bottom.png','rb').read()).decode()

html = html.replace('src="greenery_top.png"',    f'src="data:image/png;base64,{top}"')
html = html.replace('src="greenery_bottom.png"', f'src="data:image/png;base64,{bot}"')
```

Run this as a bash_tool Python snippet using the actual asset paths
(`/path/to/skill/assets/greenery_top.png` etc.) before writing the output file.

Save to `/mnt/user-data/outputs/[ChildFirstName]_Baptism_Program.html` and use `present_files`
to deliver it. Tell the user:

> "Open this in Chrome to review — it's fully self-contained, no other files needed.
> When ready to print: File → Print → Save as PDF |
> Paper: Letter | Landscape | Margins: None | Scale: 100%.
> Print both pages, cut down the dashed center line."

If the user requests changes, make them and re-deliver the file.

---

## Notes

- **Greenery images** are in the skill's assets folder and will be base64-embedded into the
  final output file, so the user receives a single self-contained HTML — no extra files needed.
- **White vs ivory:** Default is white. Change `.sheet background` and panel content
  `background` to `#f7f5ee` for warm ivory if requested.
- **Extra content:** Add centered italic text as:
  `<div style="text-align:center;font-style:italic;font-size:11pt;margin:6pt 0;">text</div>`
