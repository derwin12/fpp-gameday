<?php
/*
 * fpp-nfl - Pro Sports Scoring Plugin
 * Live status page - static HTML, data loaded via JS from C++ API
 */
?>
<!DOCTYPE html>
<html>
<head>
  <title>Pro Sports Scoring - Status</title>
  <!-- Bootstrap is already loaded by FPP's shell page -->
</head>
<body class="p-3">

<h2 class="mb-4">Pro Sports Scoring &mdash; Live Status</h2>

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
    mlb:  'MLB Baseball'
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
            container.innerHTML = '<div class="alert alert-danger">Could not reach plugin API (status ' + resp.status + '). Is fpp-nfl installed and FPP running?</div>';
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

        for (const lg of ['nfl', 'ncaa', 'nhl', 'mlb']) {
            const ls = leagues[lg] || {};
            if (!ls.teamID) continue;
            anyActive = true;

            const label     = LEAGUE_LABELS[lg] || lg.toUpperCase();
            const logoHtml  = ls.teamLogo
                ? `<img src="${ls.teamLogo}" alt="${ls.teamName}" style="height:48px;" class="rounded me-3">`
                : '';
            const gameState = ls.gameStatus || '';
            const oppoName  = ls.oppoName || '—';

            let scoreHtml = '';
            if (gameState === 'in' || gameState === 'post') {
                scoreHtml = `
                <div class="mt-2">
                  <span class="fs-4 fw-bold">${ls.teamName || ls.teamID} ${ls.myScore ?? 0}</span>
                  <span class="fs-5 text-muted mx-2">vs</span>
                  <span class="fs-4 fw-bold">${oppoName} ${ls.oppoScore ?? 0}</span>
                </div>`;
            }

            html += `
<div class="card mb-3">
  <div class="card-header d-flex align-items-center">
    ${logoHtml}
    <div>
      <strong>${label}</strong><br>
      <small class="text-muted">${ls.teamName || ls.teamID}</small>
    </div>
    <div class="ms-auto">${statusBadge(gameState)}</div>
  </div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">Opponent</dt>
      <dd class="col-sm-9">${oppoName}</dd>

      <dt class="col-sm-3">Next / Current Game</dt>
      <dd class="col-sm-9">${formatDate(ls.nextEventDate)}</dd>
    </dl>
    ${scoreHtml}
  </div>
</div>`;
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
