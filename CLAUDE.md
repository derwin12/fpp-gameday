# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**fpp-nfl** is a [Falcon Pi Player (FPP)](https://github.com/FalconChristmas/fpp) plugin that monitors live sports scores via the ESPN API and triggers FPP light sequences when your team scores or wins. It supports NFL, NCAA Football, NHL, and MLB.

FPP runs on Linux (Raspberry Pi / BeagleBone). The plugin installs to `/home/fpp/media/plugins/fpp-nfl/` and depends on FPP's PHP environment at `/opt/fpp/www/`.

## Architecture

The plugin has two distinct execution contexts:

**1. Background daemon** (`nfl.php` → `functions.inc.php`)
- Started by `scripts/postStart.sh` as a background PHP process: `php nfl.php &`
- Reads plugin config from `$settings['configDirectory']/plugin.fpp-nfl` (INI format)
- Polling loop: calls `updateTeamStatus()` repeatedly, sleeping between iterations
- Sleep intervals: 5s during live games, 30s within 20 min of kickoff, 600s otherwise
- Score change detection triggers `insertPlaylistImmediate()` → HTTP GET to FPP's local API (`http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/...`)
- Football score deltas: +6 = touchdown, +3 = field goal; hockey/baseball: any increase

**2. FPP web UI** (`content.php`, `status.php`, `about.php`)
- PHP pages rendered inside the FPP web interface
- `content.php` — Settings page: tabbed Bootstrap 5 UI, one tab per league; team selection dropdowns populated from ESPN API; AJAX calls back to `functions.inc.php` to refresh team data
- `status.php` — Live game status display (scores, opponent, game state)
- `menu.inc` — Registers pages in FPP's navigation menu

**Key shared logic** (`functions.inc.php`):
- `getTeams($sport, $league)` — fetches team list from ESPN API
- `getTeamInfo($sport, $league, $teamID)` — fetches next event ID, date, logo, abbreviation
- `getGameStatus($sport, $league, $gameID, $teamID)` — fetches live score and game state
- `updateTeam($sport, $league)` — saves team config, fetches next event info
- `updateTeamStatus()` — main polling function: reads config, checks scores, fires sequences
- `insertPlaylistImmediate($playlist)` — calls FPP local API to trigger a `.fseq` sequence
- `WriteSettingToFile()` — FPP helper (from `common.php`) that persists config values

## Config Keys (per league prefix: `nfl`, `ncaa`, `nhl`, `mlb`)

Each league stores: `{league}TeamID`, `{league}TeamAbbreviation`, `{league}TeamLogo`, `{league}TeamName`, `{league}Start` (next game date), `{league}GameStatus` (pre/in/post), `{league}TeamNextEventID`, `{league}OppoID`, `{league}OppoName`, `{league}MyScore`, `{league}OppoScore`, `{league}WinSequence`, and sport-specific sequences (`TouchdownSequence`, `FieldgoalSequence` for football; `ScoreSequence` for hockey/baseball). Global: `ENABLED`, `logLevel`.

## ESPN API Endpoints Used

- Teams list: `https://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/teams`
- NCAA teams: `http://site.api.espn.com/apis/v2/sports/football/college-football/standings`
- Team info: `http://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/teams/{id}`
- Scoreboard: `http://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/scoreboard/{gameID}`
- FPP sequences list: `http://127.0.0.1/api/sequence/`

NCAA uses `college-football` as the league slug in ESPN URLs, but `ncaa` everywhere else.

## Installation / Lifecycle Scripts

| Script | Purpose |
|--------|---------|
| `scripts/fpp_install.sh` | Registers plugin with FPP, adds ESPN CDN to Apache CSP (`img-src https://a.espncdn.com`) |
| `scripts/fpp_uninstall.sh` | Removes plugin registration |
| `scripts/postStart.sh` | Launches `nfl.php` as background process on FPP start |
| `scripts/postStop.sh` | Stops the background process on FPP stop |

## Development Notes

- There is no build step, test suite, or package manager. Changes deploy by copying files to the FPP device.
- The plugin runs as a long-lived PHP CLI process — restart requires stopping/starting FPP or the plugin process.
- All values written to the config INI file are URL-encoded via `urlencode()`; always decode with `urldecode()` when reading.
- `insertPlaylistImmediate()` appends `.fseq` to the sequence name before calling the FPP API.
- Log output goes to `$settings['logDirectory']/fpp-nfl.log`. Log level 4 = Info, 5 = Debug.

---

<!-- dgc-policy-v11 -->
# Dual-Graph Context Policy

This project uses a local dual-graph MCP server for efficient context retrieval.

## MANDATORY: Adaptive graph_continue rule

**Call `graph_continue` ONLY when you do NOT already know the relevant files.**

### Call `graph_continue` when:
- This is the first message of a new task / conversation
- The task shifts to a completely different area of the codebase
- You need files you haven't read yet in this session

### SKIP `graph_continue` when:
- You already identified the relevant files earlier in this conversation
- You are doing follow-up work on files already read (verify, refactor, test, docs, cleanup, commit)
- The task is pure text (writing a commit message, summarising, explaining)

**If skipping, go directly to `graph_read` on the already-known `file::symbol`.**

## When you DO call graph_continue

1. **If `graph_continue` returns `needs_project=true`**: call `graph_scan` with `pwd`. Do NOT ask the user.

2. **If `graph_continue` returns `skip=true`**: fewer than 5 files — read only specifically named files.

3. **Read `recommended_files`** using `graph_read`.
   - Always use `file::symbol` notation (e.g. `src/auth.ts::handleLogin`) — never read whole files.
   - `recommended_files` entries that already contain `::` must be passed verbatim.

4. **Obey confidence caps:**
   - `confidence=high` → Stop. Do NOT grep or explore further.
   - `confidence=medium` → `fallback_rg` at most `max_supplementary_greps` times, then `graph_read` at most `max_supplementary_files` more symbols. Stop.
   - `confidence=low` → same as medium. Stop.

## Session State (compact, update after every turn)

Maintain a short JSON block in your working memory. Update it after each turn:

```json
{
  "files_identified": ["path/to/file.py"],
  "symbols_changed": ["module::function"],
  "fix_applied": true,
  "features_added": ["description"],
  "open_issues": ["one-line note"]
}
```

Use this state — not prose summaries — to remember what's been done across turns.

## Token Usage

A `token-counter` MCP is available for tracking live token usage.

- Before reading a large file: `count_tokens({text: "<content>"})` to check cost first.
- To show running session cost: `get_session_stats()`
- To log completed task: `log_usage({input_tokens: N, output_tokens: N, description: "task"})`

## Rules

- Do NOT use `rg`, `grep`, or bash file exploration before calling `graph_continue` (when required).
- Do NOT do broad/recursive exploration at any confidence level.
- `max_supplementary_greps` and `max_supplementary_files` are hard caps — never exceed them.
- Do NOT call `graph_continue` more than once per turn.
- Always use `file::symbol` notation with `graph_read` — never bare filenames.
- After edits, call `graph_register_edit` with changed files using `file::symbol` notation.

## Context Store

Whenever you make a decision, identify a task, note a next step, fact, or blocker during a conversation, append it to `.dual-graph/context-store.json`.

**Entry format:**
```json
{"type": "decision|task|next|fact|blocker", "content": "one sentence max 15 words", "tags": ["topic"], "files": ["relevant/file.ts"], "date": "YYYY-MM-DD"}
```

**To append:** Read the file → add the new entry to the array → Write it back → call `graph_register_edit` on `.dual-graph/context-store.json`.

**Rules:**
- Only log things worth remembering across sessions (not every minor detail)
- `content` must be under 15 words
- `files` lists the files this decision/task relates to (can be empty)
- Log immediately when the item arises — not at session end

## Session End

When the user signals they are done (e.g. "bye", "done", "wrap up", "end session"), proactively update `CONTEXT.md` in the project root with:
- **Current Task**: one sentence on what was being worked on
- **Key Decisions**: bullet list, max 3 items
- **Next Steps**: bullet list, max 3 items

Keep `CONTEXT.md` under 20 lines total. Do NOT summarize the full conversation — only what's needed to resume next session.
