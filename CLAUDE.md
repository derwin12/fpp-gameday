# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**GameDay** (`fpp-nfl`) is a [Falcon Pi Player (FPP)](https://github.com/FalconChristmas/fpp) plugin that monitors live sports scores via the ESPN API and triggers FPP light sequences when a tracked team scores or wins. Supports NFL, NCAA Football, NHL, and MLB. Multiple teams per league are supported.

- Repo: `https://github.com/derwin12/fpp-nfl`
- FPP install path: `/home/fpp/media/plugins/fpp-nfl/`
- FPP version requirement: 9.0+

## Architecture

The plugin is a **C++ shared library** compiled to `libfpp-nfl.so` and loaded automatically by `fppd` at startup.

**C++ plugin** (`src/FPPProSports.cpp`)
- Class `FPPProSportsPlugin` inherits `FPPPlugins::Plugin`, `FPPPlugins::APIProviderPlugin`, `httpserver::http_resource`
- Registered at `/api/plugin-apis/ProSportsScoring/{config,status,refresh/<league>/<index>}`
- Background polling thread per enabled team: polls ESPN every 5s (live), 30s (pre-game <20min), 600s (otherwise)
- Two mutexes: `m_stateMutex` (protects `m_leagues`), `m_cvMutex` (used only with condition variable to avoid deadlock)
- `m_leagues`: `map<string, vector<LeagueState>>` — each league holds an array of tracked teams
- Score detection: football delta ≥6 → touchdown, <6 → field goal; hockey/baseball: any positive delta
- Win detection: game goes to "post" and myScore > oppoScore
- Sequence triggering: `POST http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/{seq}.fseq/0/0`
- Config persisted to `FPP_DIR_CONFIG("/plugin.fpp-nfl.json")` (JSON, array per league)

**FPP web UI** (PHP, served by FPP's Apache)
- `content.php` — Settings page: tabs per league, dynamic add/remove team rows, JS populates from C++ config API, saves as JSON arrays. Do NOT load Bootstrap — FPP already provides it.
- `status.php` — Live status: compact single-row cards per team, polls `/status` every 10s via JS
- `menu.inc` — Registers "GameDay Setup" (content) and "GameDay Status" (status) in FPP nav

## Build

```bash
cd /home/fpp/media/plugins/fpp-nfl
make SRCDIR=/opt/fpp/src       # produces libfpp-nfl.so
sudo systemctl restart fppd    # reload plugin
```

`callbacks.sh` echoes `"c++"` on `--list` so FPP knows to load the `.so`.
`scripts/preStart.sh` runs `make` before FPP starts.
`scripts/fpp_install.sh` runs `make` and registers ESPN CSP entries.

## ESPN API Endpoints

| Purpose | URL |
|---------|-----|
| Teams list | `https://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/teams?limit=200` |
| NCAA teams | `https://site.api.espn.com/apis/v2/sports/football/college-football/standings?limit=500` |
| Team info + next event | `https://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/teams/{id}` |
| Live scoreboard | `https://site.api.espn.com/apis/site/v2/sports/{sport}/{league}/scoreboard/{eventID}` |
| FPP sequence list | `http://127.0.0.1/api/sequence` (returns plain JSON array) |

- `ncaa` maps to `college-football` in ESPN URLs, `football` as sport
- Scoreboard response: status/period/clock live in `competitions[0].status`, scores in `competitions[0].competitors[]`
- ESPN responses are gzip-encoded; libcurl is configured with `CURLOPT_ACCEPT_ENCODING ""`

## Config JSON Schema

```json
{
  "enabled": true,
  "leagues": {
    "nhl": [
      {
        "teamID": "21",
        "teamName": "Toronto Maple Leafs",
        "teamLogo": "https://...",
        "nextEventID": "401803457",
        "nextEventDate": "2026-03-21T23:00Z",
        "gameStatus": "in",
        "oppoName": "Ottawa Senators",
        "myScore": 1, "oppoScore": 3,
        "gamePeriod": 2, "gameClock": "9:17",
        "winSequence": "", "scoreSequence": ""
      }
    ],
    "nfl": [], "ncaa": [], "mlb": []
  }
}
```

Football leagues add `touchdownSequence` and `fieldgoalSequence` instead of `scoreSequence`.

## Key Development Notes

- After any C++ change: `sed -i 's/\r//' src/FPPProSports.cpp && make SRCDIR=/opt/fpp/src`
- PHP/JSON changes take effect on next page load — no restart needed
- Sequence names stored and passed **without** `.fseq`; the C++ appends it when calling FPP API
- Log output via `LogInfo`/`LogWarn`/`LogDebug` with `VB_PLUGIN`; view with `sudo journalctl -u fppd`
- The `.gitattributes` enforces LF line endings — Windows CRLF breaks `make` on the Pi

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
