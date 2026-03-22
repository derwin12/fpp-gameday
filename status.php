<?php
/*
 * fpp-gameday - Pro Sports Scoring Plugin
 * Live status page - static HTML, data loaded via JS from C++ API
 */
?>
<!DOCTYPE html>
<html>
<head>
  <title>GameDay &mdash; Live Status</title>
  <!-- Bootstrap is already loaded by FPP's shell page -->
</head>
<body class="p-3">

<h2 class="mb-4">GameDay &mdash; Live Status</h2>

<div id="disabled-notice" class="alert alert-warning d-none">
  Plugin is currently disabled. Enable it in the Settings tab.
</div>

<div id="status-container">
  <div class="text-muted">Loading&hellip;</div>
</div>

<script>
const LEAGUE_LABELS = {
    nfl:  'NFL Football',
    ncaa: 'NCAA Football',
    nhl:  'NHL Hockey',
    mlb:  'MLB Baseball',
    afl:  'AFL'
};
const PERIOD_LABEL = {
    nfl:  'Q', ncaa: 'Q', afl: 'Q',
    nhl:  'P', mlb:  'Inn'
};

function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
        return new Date(dateStr).toLocaleString();
    } catch (e) {
        return dateStr;
    }
}

function statusBadge(state) {
    const map = {
        pre:  '<span class="badge bg-info text-dark">PRE</span>',
        in:   '<span class="badge bg-success">LIVE</span>',
        post: '<span class="badge bg-secondary">FINAL</span>'
    };
    return map[state] || '<span class="badge bg-light text-dark">—</span>';
}

async function loadStatus() {
    const container = document.getElementById('status-container');
    const notice    = document.getElementById('disabled-notice');

    try {
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/status');
        if (!resp.ok) {
            container.innerHTML = '<div class="alert alert-danger">Could not reach plugin API (status ' + resp.status + '). Is fpp-gameday installed and FPP running?</div>';
            return;
        }
        const data = await resp.json();

        if (!data.enabled) {
            notice.classList.remove('d-none');
            container.innerHTML = '';
            return;
        }
        notice.classList.add('d-none');

        const leagues = data.leagues || {};
        let html = '';
        let anyActive = false;

        for (const lg of ['nfl', 'ncaa', 'nhl', 'mlb', 'afl']) {
            const teams = leagues[lg] || [];
            for (const ls of teams) {
            if (!ls.teamID) continue;
            anyActive = true;

            const gameState = ls.gameStatus || '';
            const oppoName  = ls.oppoName || '—';
            const myName    = ls.teamName || ls.teamID;
            const logoHtml  = ls.teamLogo
                ? `<img src="${ls.teamLogo}" alt="${myName}" style="height:32px;" class="rounded me-2 flex-shrink-0">`
                : '';

            let centerHtml = '';
            if (gameState === 'in' || gameState === 'post') {
                const period = ls.gamePeriod || 0;
                const clock  = ls.gameClock  || '';
                let periodStr = '';
                if (period > 0) {
                    const pl = PERIOD_LABEL[lg] || 'P';
                    periodStr = gameState === 'in'
                        ? `<span class="text-muted small ms-3">${pl}${period}` + (clock && clock !== '0:00' ? ` ${clock}` : '') + `</span>`
                        : '';
                }
                centerHtml = `<span class="fw-bold">${myName} ${ls.myScore ?? 0}</span>
                  <span class="text-muted mx-2">vs</span>
                  <span class="fw-bold">${oppoName} ${ls.oppoScore ?? 0}</span>${periodStr}`;
            } else if (gameState === 'pre') {
                centerHtml = `<span class="text-muted small">vs ${oppoName} &mdash; ${formatDate(ls.nextEventDate)}</span>`;
            } else {
                centerHtml = `<span class="text-muted small">vs ${oppoName}</span>`;
            }

            html += `
<div class="d-flex align-items-center border rounded px-3 py-2 mb-2">
  ${logoHtml}
  <div class="me-3 flex-shrink-0" style="min-width:7rem">
    <div class="fw-semibold lh-sm">${myName}</div>
    <div class="text-muted" style="font-size:.75rem">${LEAGUE_LABELS[lg] || lg.toUpperCase()}</div>
  </div>
  <div class="flex-grow-1">${centerHtml}</div>
  <div class="ms-3 flex-shrink-0">${statusBadge(gameState)}</div>
</div>`;
            } // end for ls of teams
        }

        if (!anyActive) {
            html = '<div class="text-muted">No teams configured. Go to the Settings tab to set up your teams.</div>';
        }

        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<div class="alert alert-danger">Error loading status: ' + e + '</div>';
    }
}

// Load on page ready, then refresh every 10 seconds
loadStatus();
setInterval(loadStatus, 10000);
</script>

</body>
</html>
