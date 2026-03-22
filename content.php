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

function getPlaylists() {
    $data = fetchJson('http://127.0.0.1/api/playlists');
    if (!$data || !is_array($data)) return [];
    sort($data);
    return $data;
}

function getAudio() {
    $data = fetchJson('http://127.0.0.1/api/media');
    if (!$data || !is_array($data)) return [];
    sort($data);
    return $data;
}

$playlists = getPlaylists();
$audio     = getAudio();

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
  <label for="enabled">Enable Plugin:&nbsp;<input type="checkbox" id="enabled" class="enableCheckbox"></label>
  <input type="button" class="buttons" value="Save Settings" onclick="saveConfig()">
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
const PLAYLISTS  = <?= json_encode($playlists) ?>;
const AUDIO      = <?= json_encode($audio) ?>;
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

const ACTION_LISTS = { sequence: SEQUENCES, playlist: PLAYLISTS, audio: AUDIO };

function buildActionSelects(typeVal, valueVal, typeClass, valueClass) {
    const wrap = document.createElement('div');
    wrap.className = 'd-flex gap-2';

    const typeSel = document.createElement('select');
    typeSel.className = 'form-select form-select-sm ' + (typeClass || '');
    typeSel.style.maxWidth = '110px';
    for (const [v, l] of [['', 'None'], ['sequence', 'Sequence'], ['playlist', 'Playlist'], ['audio', 'Audio']]) {
        const o = new Option(l, v);
        if (v === typeVal) o.selected = true;
        typeSel.appendChild(o);
    }

    const valueSel = document.createElement('select');
    valueSel.className = 'form-select form-select-sm ' + (valueClass || '');

    function populateValues() {
        const t = typeSel.value;
        valueSel.innerHTML = '';
        valueSel.disabled = !t;
        if (!t) { valueSel.appendChild(new Option('—', '')); return; }
        valueSel.appendChild(new Option('-- None --', ''));
        for (const s of (ACTION_LISTS[t] || [])) {
            const o = new Option(s, s);
            if (s === valueVal) o.selected = true;
            valueSel.appendChild(o);
        }
    }

    typeSel.onchange = () => { valueVal = ''; populateValues(); };
    populateValues();

    wrap.append(typeSel, valueSel);
    return wrap;
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

    function actionCol(label, typeClass, valueClass, typeVal, valueVal) {
        const col = document.createElement('div');
        col.className = 'col-md-6';
        const lbl = document.createElement('label');
        lbl.className = 'form-label';
        lbl.textContent = label;
        col.append(lbl, buildActionSelects(typeVal, valueVal, typeClass, valueClass));
        return col;
    }

    seqRow.append(actionCol('Win', 'win-type', 'win-val',
        data.winActionType || '', data.winActionValue || ''));

    if (isFootball) {
        seqRow.append(actionCol('Touchdown', 'td-type', 'td-val',
            data.touchdownActionType || '', data.touchdownActionValue || ''));
        seqRow.append(actionCol('Field Goal', 'fg-type', 'fg-val',
            data.fieldgoalActionType || '', data.fieldgoalActionValue || ''));
    } else {
        seqRow.append(actionCol('Score', 'score-type', 'score-val',
            data.scoreActionType || '', data.scoreActionValue || ''));
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
                teamID:         (card.querySelector('.team-select') || {}).value || '',
                winActionType:  (card.querySelector('.win-type')    || {}).value || '',
                winActionValue: (card.querySelector('.win-val')     || {}).value || ''
            };
            if (isFootball) {
                t.touchdownActionType  = (card.querySelector('.td-type')  || {}).value || '';
                t.touchdownActionValue = (card.querySelector('.td-val')   || {}).value || '';
                t.fieldgoalActionType  = (card.querySelector('.fg-type')  || {}).value || '';
                t.fieldgoalActionValue = (card.querySelector('.fg-val')   || {}).value || '';
            } else {
                t.scoreActionType  = (card.querySelector('.score-type') || {}).value || '';
                t.scoreActionValue = (card.querySelector('.score-val')  || {}).value || '';
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
