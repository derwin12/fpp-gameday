# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**GameDay** (`fpp-gameday`) is a [Falcon Pi Player (FPP)](https://github.com/FalconChristmas/fpp) plugin that monitors live sports scores via the ESPN API and triggers FPP light sequences when a tracked team scores or wins. Supports NFL, NCAA Football, NHL, and MLB. Multiple teams per league are supported.

- Repo: `https://github.com/derwin12/fpp-gameday`
- FPP install path: `/home/fpp/media/plugins/fpp-gameday/`
- FPP version requirement: 9.0+

## Architecture

The plugin is a **C++ shared library** compiled to `libfpp-gameday.so` and loaded automatically by `fppd` at startup.

**C++ plugin** (`src/FPPProSports.cpp`)
- Class `FPPProSportsPlugin` inherits `FPPPlugins::Plugin`, `FPPPlugins::APIProviderPlugin`, `httpserver::http_resource`
- Registered at `/api/plugin-apis/ProSportsScoring/{config,status,refresh/<league>/<index>}`
- Background polling thread per enabled team: polls ESPN every 5s (live), 30s (pre-game <20min), 600s (otherwise)
- Two mutexes: `m_stateMutex` (protects `m_leagues`), `m_cvMutex` (used only with condition variable to avoid deadlock)
- `m_leagues`: `map<string, vector<LeagueState>>` — each league holds an array of tracked teams
- Score detection: football delta ≥6 → touchdown, <6 → field goal; hockey/baseball: any positive delta
- Win detection: game goes to "post" and myScore > oppoScore
- Sequence triggering: `POST http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/{seq}.fseq/0/0`
- Config persisted to `FPP_DIR_CONFIG("/plugin.fpp-gameday.json")` (JSON, array per league)

**FPP web UI** (PHP, served by FPP's Apache)
- `content.php` — Settings page: tabs per league, dynamic add/remove team rows, JS populates from C++ config API, saves as JSON arrays. Do NOT load Bootstrap — FPP already provides it.
- `status.php` — Live status: compact single-row cards per team, polls `/status` every 10s via JS
- `menu.inc` — Registers "GameDay Setup" (content) and "GameDay Status" (status) in FPP nav

## Build

```bash
cd /home/fpp/media/plugins/fpp-gameday
make SRCDIR=/opt/fpp/src       # produces libfpp-gameday.so
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
