<?php
/*
 * fpp-gameday - Pro Sports Scoring Plugin
 * Settings / configuration page
 */

function fetchJson($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'fpp-gameday/1.0');
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $body === false) return null;
    return json_decode($body, true);
}

function getTeams($sport, $league) {
    $url  = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$league}/teams?limit=200";
    $data = fetchJson($url);
    if (!$data) return [];
    $teams = [];
    foreach ($data['sports'] ?? [] as $s) {
        foreach ($s['leagues'] ?? [] as $l) {
            foreach ($l['teams'] ?? [] as $t) {
                $team = $t['team'] ?? [];
                if (isset($team['id'], $team['displayName']))
                    $teams[] = ['id' => $team['id'], 'name' => $team['displayName']];
            }
        }
    }
    usort($teams, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $teams;
}

function getNCAATeams() {
    $url  = "https://site.api.espn.com/apis/v2/sports/football/college-football/standings?limit=500";
    $data = fetchJson($url);
    if (!$data) return [];
    $teams = [];
    foreach ($data['children'] ?? [] as $conf) {
        foreach ($conf['standings']['entries'] ?? [] as $entry) {
            $team = $entry['team'] ?? [];
            if (isset($team['id'], $team['displayName']))
                $teams[] = ['id' => $team['id'], 'name' => $team['displayName']];
        }
    }
    usort($teams, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $teams;
}

function getSequences() {
    $data = fetchJson('http://127.0.0.1/api/sequence');
    if (!$data) return [];
    $seqs = isset($data['Sequences']) ? $data['Sequences'] : (array_values($data) === $data ? $data : []);
    sort($seqs);
    return array_values(array_map(fn($s) => preg_replace('/\.fseq$/i', '', $s), $seqs));
}

$teamsData = [
    'nfl'  => getTeams('football', 'nfl'),
    'ncaa' => getNCAATeams(),
    'nhl'  => getTeams('hockey', 'nhl'),
    'mlb'  => getTeams('baseball', 'mlb'),
    'afl'  => getTeams('australian-football', 'afl'),
];
$sequences = getSequences();
?>
<!DOCTYPE html>
<html>
<head>
  <title>GameDay</title>
  <!-- Bootstrap is already loaded by FPP's shell page -->
</head>
<body class="p-3">

<h2 class="mb-4">GameDay</h2>

<div class="mb-3 d-flex align-items-center gap-3">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" id="enabled" onchange="saveConfig()">
    <label class="form-check-label" for="enabled">Enable Plugin</label>
  </div>
  <button class="btn btn-primary btn-sm" onclick="saveConfig()">Save Settings</button>
  <span id="save-status" class="text-muted small"></span>
</div>

<ul class="nav nav-tabs mb-4" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-nfl"  type="button">NFL</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        data-bs-toggle="tab" data-bs-target="#pane-ncaa" type="button">NCAA</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        data-bs-toggle="tab" data-bs-target="#pane-nhl"  type="button">NHL</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        data-bs-toggle="tab" data-bs-target="#pane-mlb"  type="button">MLB</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        data-bs-toggle="tab" data-bs-target="#pane-afl"  type="button">AFL</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="pane-nfl"  role="tabpanel">
    <div id="teams-nfl"></div>
    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="addTeamRow('nfl')">+ Add NFL Team</button>
  </div>
  <div class="tab-pane fade" id="pane-ncaa" role="tabpanel">
    <div id="teams-ncaa"></div>
    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="addTeamRow('ncaa')">+ Add NCAA Team</button>
  </div>
  <div class="tab-pane fade" id="pane-nhl" role="tabpanel">
    <div id="teams-nhl"></div>
    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="addTeamRow('nhl')">+ Add NHL Team</button>
  </div>
  <div class="tab-pane fade" id="pane-mlb" role="tabpanel">
    <div id="teams-mlb"></div>
    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="addTeamRow('mlb')">+ Add MLB Team</button>
  </div>
  <div class="tab-pane fade" id="pane-afl" role="tabpanel">
    <div id="teams-afl"></div>
    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="addTeamRow('afl')">+ Add AFL Team</button>
  </div>
</div>

<script>
const TEAMS_DATA = <?= json_encode($teamsData) ?>;
const SEQUENCES  = <?= json_encode($sequences) ?>;
const LEAGUES    = ['nfl', 'ncaa', 'nhl', 'mlb', 'afl'];
const LEAGUE_LABELS = { nfl: 'NFL Football', ncaa: 'NCAA Football', nhl: 'NHL Hockey', mlb: 'MLB Baseball', afl: 'AFL' };

function buildTeamSelect(lg, selectedID) {
    const sel = document.createElement('select');
    sel.className = 'form-select team-select';
    sel.appendChild(new Option('-- None --', ''));
    for (const t of (TEAMS_DATA[lg] || [])) {
        const opt = new Option(t.name, t.id);
        if (String(t.id) === String(selectedID)) opt.selected = true;
        sel.appendChild(opt);
    }
    return sel;
}

function buildSeqSelect(selectedVal, cls) {
    const sel = document.createElement('select');
    sel.className = 'form-select ' + (cls || '');
    sel.appendChild(new Option('-- None --', ''));
    for (const s of SEQUENCES) {
        const opt = new Option(s, s);
        if (s === selectedVal) opt.selected = true;
        sel.appendChild(opt);
    }
    return sel;
}

function addTeamRow(lg, data) {
    data = data || {};
    const isFootball = (lg === 'nfl' || lg === 'ncaa');
    const container  = document.getElementById('teams-' + lg);

    const card = document.createElement('div');
    card.className = 'card mb-3 team-card';

    // Header
    const header = document.createElement('div');
    header.className = 'card-header d-flex align-items-center gap-2';

    const logo = document.createElement('img');
    logo.className = 'team-logo rounded';
    logo.style.cssText = 'height:40px;display:none;';

    const title = document.createElement('strong');
    title.className = 'team-title';
    title.textContent = data.teamName || LEAGUE_LABELS[lg];

    const badge = document.createElement('span');
    badge.className = 'badge bg-secondary ms-auto team-badge';
    badge.textContent = '-';

    const removeBtn = document.createElement('button');
    removeBtn.className = 'btn btn-outline-danger btn-sm ms-2';
    removeBtn.textContent = 'Remove';
    removeBtn.onclick = () => card.remove();

    header.append(logo, title, badge, removeBtn);

    // Body
    const body = document.createElement('div');
    body.className = 'card-body';

    // Team row
    const teamRow = document.createElement('div');
    teamRow.className = 'row g-3 mb-3';

    const teamCol = document.createElement('div');
    teamCol.className = 'col-md-6';
    const teamLabel = document.createElement('label');
    teamLabel.className = 'form-label';
    teamLabel.textContent = 'Team';
    const teamSel = buildTeamSelect(lg, data.teamID || '');
    teamSel.onchange = () => {
        const opt = teamSel.options[teamSel.selectedIndex];
        title.textContent = opt.value ? opt.text : LEAGUE_LABELS[lg];
        logo.style.display = 'none';
    };
    teamCol.append(teamLabel, teamSel);

    const refreshCol = document.createElement('div');
    refreshCol.className = 'col-md-6 d-flex align-items-end';
    const refreshBtn = document.createElement('button');
    refreshBtn.className = 'btn btn-outline-secondary btn-sm';
    refreshBtn.textContent = 'Refresh Team Info';
    refreshBtn.onclick = () => {
        const cards = Array.from(container.children);
        refreshLeague(lg, cards.indexOf(card));
    };
    refreshCol.append(refreshBtn);
    teamRow.append(teamCol, refreshCol);

    // Sequence row
    const seqRow = document.createElement('div');
    seqRow.className = 'row g-3';

    const winCol = document.createElement('div');
    winCol.className = 'col-md-6';
    const winLabel = document.createElement('label');
    winLabel.className = 'form-label';
    winLabel.textContent = 'Win Sequence';
    winCol.append(winLabel, buildSeqSelect(data.winSequence || '', 'win-seq'));
    seqRow.append(winCol);

    if (isFootball) {
        const tdCol = document.createElement('div');
        tdCol.className = 'col-md-6';
        const tdLabel = document.createElement('label');
        tdLabel.className = 'form-label';
        tdLabel.textContent = 'Touchdown Sequence';
        tdCol.append(tdLabel, buildSeqSelect(data.touchdownSequence || '', 'td-seq'));
        seqRow.append(tdCol);

        const fgCol = document.createElement('div');
        fgCol.className = 'col-md-6';
        const fgLabel = document.createElement('label');
        fgLabel.className = 'form-label';
        fgLabel.textContent = 'Field Goal Sequence';
        fgCol.append(fgLabel, buildSeqSelect(data.fieldgoalSequence || '', 'fg-seq'));
        seqRow.append(fgCol);
    } else {
        const scoreCol = document.createElement('div');
        scoreCol.className = 'col-md-6';
        const scoreLabel = document.createElement('label');
        scoreLabel.className = 'form-label';
        scoreLabel.textContent = 'Score Sequence';
        scoreCol.append(scoreLabel, buildSeqSelect(data.scoreSequence || '', 'score-seq'));
        seqRow.append(scoreCol);
    }

    body.append(teamRow, seqRow);
    card.append(header, body);
    container.append(card);

    // Apply logo/badge if we have live data
    if (data.teamLogo) { logo.src = data.teamLogo; logo.style.display = ''; }
    updateCardBadge(card, data.gameStatus || '');
}

function updateCardBadge(card, gameStatus) {
    const badge = card.querySelector('.team-badge');
    if (!badge) return;
    const classes = { pre: 'badge bg-info text-dark ms-auto team-badge', in: 'badge bg-success ms-auto team-badge', post: 'badge bg-secondary ms-auto team-badge' };
    badge.className = classes[gameStatus] || 'badge bg-secondary ms-auto team-badge';
    badge.textContent = gameStatus ? gameStatus.toUpperCase() : '-';
}

async function loadConfig() {
    try {
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/config');
        if (!resp.ok) return;
        const cfg = await resp.json();

        document.getElementById('enabled').checked = !!cfg.enabled;

        for (const lg of LEAGUES) {
            const teams = (cfg.leagues || {})[lg] || [];
            const container = document.getElementById('teams-' + lg);
            container.innerHTML = '';
            for (const t of teams) {
                if (t.teamID) addTeamRow(lg, t);
            }
        }
    } catch (e) {
        console.error('loadConfig error:', e);
    }
}

function buildConfigPayload() {
    const cfg = { enabled: document.getElementById('enabled').checked, leagues: {} };
    for (const lg of LEAGUES) {
        const isFootball = (lg === 'nfl' || lg === 'ncaa');
        const teams = [];
        for (const card of document.getElementById('teams-' + lg).children) {
            const t = {
                teamID:      (card.querySelector('.team-select') || {}).value || '',
                winSequence: (card.querySelector('.win-seq')     || {}).value || ''
            };
            if (isFootball) {
                t.touchdownSequence = (card.querySelector('.td-seq') || {}).value || '';
                t.fieldgoalSequence = (card.querySelector('.fg-seq') || {}).value || '';
            } else {
                t.scoreSequence = (card.querySelector('.score-seq') || {}).value || '';
            }
            teams.push(t);
        }
        cfg.leagues[lg] = teams;
    }
    return cfg;
}

async function saveConfig() {
    const statusEl = document.getElementById('save-status');
    statusEl.textContent = 'Saving...';
    try {
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/config', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(buildConfigPayload())
        });
        statusEl.textContent = resp.ok ? 'Saved.' : 'Save failed (' + resp.status + ')';
        if (resp.ok) setTimeout(() => { statusEl.textContent = ''; }, 2000);
    } catch (e) {
        statusEl.textContent = 'Save error: ' + e;
    }
}

async function refreshLeague(lg, idx) {
    const statusEl = document.getElementById('save-status');
    statusEl.textContent = 'Refreshing...';
    await fetch('/api/plugin-apis/ProSportsScoring/config', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildConfigPayload())
    });
    try {
        const resp = await fetch(`/api/plugin-apis/ProSportsScoring/refresh/${lg}/${idx}`, { method: 'POST' });
        statusEl.textContent = '';
        if (resp.ok) loadConfig();
        else statusEl.textContent = 'Refresh failed (' + resp.status + ')';
    } catch (e) {
        statusEl.textContent = 'Refresh error: ' + e;
    }
}

loadConfig();
</script>
</body>
</html>
