<?php
/*
 * fpp-nfl - Pro Sports Scoring Plugin
 * Settings / configuration page
 */

//----------------------------------------------------------------------
// Helper: fetch JSON from a URL using PHP curl
//----------------------------------------------------------------------
function fetchJson($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'fpp-nfl/1.0');
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $body === false) return null;
    return json_decode($body, true);
}

//----------------------------------------------------------------------
// Fetch team list from ESPN
//----------------------------------------------------------------------
function getTeams($sport, $league) {
    $url  = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$league}/teams?limit=200";
    $data = fetchJson($url);
    if (!$data) return [];
    $teams = [];
    $sports = $data['sports'] ?? [];
    foreach ($sports as $s) {
        foreach ($s['leagues'] ?? [] as $l) {
            foreach ($l['teams'] ?? [] as $t) {
                $team = $t['team'] ?? [];
                if (isset($team['id'], $team['displayName'])) {
                    $teams[] = ['id' => $team['id'], 'name' => $team['displayName']];
                }
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
            if (isset($team['id'], $team['displayName'])) {
                $teams[] = ['id' => $team['id'], 'name' => $team['displayName']];
            }
        }
    }
    usort($teams, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $teams;
}

//----------------------------------------------------------------------
// Fetch available sequences from FPP
//----------------------------------------------------------------------
function getSequences() {
    $data = fetchJson('http://127.0.0.1/api/sequence');
    if (!$data) return [];
    // FPP returns a plain array; older versions used {"Sequences":[...]}
    $seqs = isset($data['Sequences']) ? $data['Sequences'] : (array_values($data) === $data ? $data : []);
    sort($seqs);
    return $seqs;
}

//----------------------------------------------------------------------
// Load team lists and sequences (server-side, for dropdowns)
//----------------------------------------------------------------------
$nflTeams  = getTeams('football', 'nfl');
$ncaaTeams = getNCAATeams();
$nhlTeams  = getTeams('hockey', 'nhl');
$mlbTeams  = getTeams('baseball', 'mlb');
$sequences = getSequences();

//----------------------------------------------------------------------
// Helper: render a <select> for teams
//----------------------------------------------------------------------
function teamSelect($id, $teams) {
    echo "<select class=\"form-select\" id=\"{$id}\">\n";
    echo "  <option value=\"\">-- None --</option>\n";
    foreach ($teams as $t) {
        $eid  = htmlspecialchars($t['id'],   ENT_QUOTES);
        $name = htmlspecialchars($t['name'], ENT_QUOTES);
        echo "  <option value=\"{$eid}\">{$name}</option>\n";
    }
    echo "</select>\n";
}

//----------------------------------------------------------------------
// Helper: render a <select> for sequences
//----------------------------------------------------------------------
function seqSelect($id, $sequences) {
    echo "<select class=\"form-select\" id=\"{$id}\">\n";
    echo "  <option value=\"\">-- None --</option>\n";
    foreach ($sequences as $seq) {
        $display = preg_replace('/\.fseq$/i', '', $seq);
        $val     = htmlspecialchars($display, ENT_QUOTES);
        echo "  <option value=\"{$val}\">{$val}</option>\n";
    }
    echo "</select>\n";
}

//----------------------------------------------------------------------
// Helper: render a league settings card
//----------------------------------------------------------------------
function leagueCard($league, $label, $teams, $sequences, $isFootball) {
    $lid = htmlspecialchars($league, ENT_QUOTES);
?>
<div class="card mb-4" id="card-<?= $lid ?>">
  <div class="card-header d-flex align-items-center gap-3">
    <img id="logo-<?= $lid ?>" src="" alt="<?= htmlspecialchars($label) ?> Team Logo"
         style="height:48px;display:none;" class="rounded">
    <strong><?= htmlspecialchars($label) ?></strong>
    <span id="status-badge-<?= $lid ?>" class="badge bg-secondary ms-auto">-</span>
  </div>
  <div class="card-body">

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="team-<?= $lid ?>">Team</label>
        <?php teamSelect("team-{$league}", $teams); ?>
      </div>
      <div class="col-md-6 d-flex align-items-end">
        <button class="btn btn-outline-secondary btn-sm"
                onclick="refreshLeague('<?= $lid ?>')">
          Refresh Team Info
        </button>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="win-<?= $lid ?>">Win Sequence</label>
        <?php seqSelect("win-{$league}", $sequences); ?>
      </div>
<?php if ($isFootball): ?>
      <div class="col-md-6">
        <label class="form-label" for="td-<?= $lid ?>">Touchdown Sequence</label>
        <?php seqSelect("td-{$league}", $sequences); ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="fg-<?= $lid ?>">Field Goal / Safety Sequence</label>
        <?php seqSelect("fg-{$league}", $sequences); ?>
      </div>
<?php else: ?>
      <div class="col-md-6">
        <label class="form-label" for="score-<?= $lid ?>">Score Sequence</label>
        <?php seqSelect("score-{$league}", $sequences); ?>
      </div>
<?php endif; ?>
    </div>

  </div>
</div>
<?php
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Pro Sports Scoring</title>
  <!-- Bootstrap is already loaded by FPP's shell page -->
</head>
<body class="p-3">

<h2 class="mb-4">Pro Sports Scoring Plugin</h2>

<div class="mb-3 d-flex align-items-center gap-3">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" id="enabled" onchange="saveConfig()">
    <label class="form-check-label" for="enabled">Enable Plugin</label>
  </div>
  <button class="btn btn-primary btn-sm" onclick="saveConfig()">Save Settings</button>
  <span id="save-status" class="text-muted small"></span>
</div>

<ul class="nav nav-tabs mb-4" id="leagueTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tab-nfl"  data-bs-toggle="tab" data-bs-target="#pane-nfl"  type="button">NFL</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        id="tab-ncaa" data-bs-toggle="tab" data-bs-target="#pane-ncaa" type="button">NCAA</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        id="tab-nhl"  data-bs-toggle="tab" data-bs-target="#pane-nhl"  type="button">NHL</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link"        id="tab-mlb"  data-bs-toggle="tab" data-bs-target="#pane-mlb"  type="button">MLB</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="pane-nfl"  role="tabpanel">
    <?php leagueCard('nfl',  'NFL Football',  $nflTeams,  $sequences, true);  ?>
  </div>
  <div class="tab-pane fade" id="pane-ncaa" role="tabpanel">
    <?php leagueCard('ncaa', 'NCAA Football', $ncaaTeams, $sequences, true);  ?>
  </div>
  <div class="tab-pane fade" id="pane-nhl"  role="tabpanel">
    <?php leagueCard('nhl',  'NHL Hockey',    $nhlTeams,  $sequences, false); ?>
  </div>
  <div class="tab-pane fade" id="pane-mlb"  role="tabpanel">
    <?php leagueCard('mlb',  'MLB Baseball',  $mlbTeams,  $sequences, false); ?>
  </div>
</div>

<script>
const LEAGUES = ['nfl', 'ncaa', 'nhl', 'mlb'];

function sel(id) { return document.getElementById(id); }

function setSelectValue(selectEl, value) {
    if (!selectEl) return;
    for (let i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].value === String(value)) {
            selectEl.selectedIndex = i;
            return;
        }
    }
}

async function loadConfig() {
    try {
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/config');
        if (!resp.ok) return;
        const cfg = await resp.json();

        sel('enabled').checked = !!cfg.enabled;

        for (const lg of LEAGUES) {
            const ls = (cfg.leagues || {})[lg] || {};
            setSelectValue(sel('team-' + lg), ls.teamID || '');
            setSelectValue(sel('win-'  + lg), ls.winSequence || '');
            if (lg === 'nfl' || lg === 'ncaa') {
                setSelectValue(sel('td-' + lg), ls.touchdownSequence || '');
                setSelectValue(sel('fg-' + lg), ls.fieldgoalSequence || '');
            } else {
                setSelectValue(sel('score-' + lg), ls.scoreSequence || '');
            }
            updateLeagueDisplay(lg, ls);
        }
    } catch (e) {
        console.error('loadConfig error:', e);
    }
}

function updateLeagueDisplay(lg, ls) {
    const logoEl  = sel('logo-' + lg);
    const badgeEl = sel('status-badge-' + lg);

    if (logoEl) {
        if (ls.teamLogo) {
            logoEl.src = ls.teamLogo;
            logoEl.style.display = '';
        } else {
            logoEl.style.display = 'none';
        }
    }

    if (badgeEl) {
        const st  = ls.gameStatus || '';
        const map = { pre: 'badge ms-auto bg-info text-dark', in: 'badge ms-auto bg-success', post: 'badge ms-auto bg-secondary' };
        badgeEl.className   = map[st] || 'badge ms-auto bg-secondary';
        badgeEl.textContent = st ? st.toUpperCase() : '-';
    }
}

function buildConfigPayload() {
    const cfg = { enabled: sel('enabled').checked, leagues: {} };
    for (const lg of LEAGUES) {
        const ls = {
            teamID:      (sel('team-' + lg) || {}).value || '',
            winSequence: (sel('win-'  + lg) || {}).value || ''
        };
        if (lg === 'nfl' || lg === 'ncaa') {
            ls.touchdownSequence = (sel('td-' + lg) || {}).value || '';
            ls.fieldgoalSequence = (sel('fg-' + lg) || {}).value || '';
        } else {
            ls.scoreSequence = (sel('score-' + lg) || {}).value || '';
        }
        cfg.leagues[lg] = ls;
    }
    return cfg;
}

async function saveConfig() {
    const statusEl = sel('save-status');
    statusEl.textContent = 'Saving...';
    try {
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/config', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(buildConfigPayload())
        });
        if (resp.ok) {
            statusEl.textContent = 'Saved.';
            setTimeout(() => { statusEl.textContent = ''; }, 2000);
        } else {
            statusEl.textContent = 'Save failed (' + resp.status + ')';
        }
    } catch (e) {
        statusEl.textContent = 'Save error: ' + e;
    }
}

async function refreshLeague(lg) {
    const statusEl = sel('save-status');
    statusEl.textContent = 'Refreshing ' + lg.toUpperCase() + '...';
    try {
        // Save current selection first so C++ knows the teamID
        await fetch('/api/plugin-apis/ProSportsScoring/config', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(buildConfigPayload())
        });
        const resp = await fetch('/api/plugin-apis/ProSportsScoring/refresh/' + lg, { method: 'POST' });
        statusEl.textContent = '';
        if (resp.ok) {
            // Reload config to get updated logo / status
            loadConfig();
        } else {
            statusEl.textContent = 'Refresh failed (' + resp.status + ')';
        }
    } catch (e) {
        statusEl.textContent = 'Refresh error: ' + e;
    }
}

// Load config on page ready
loadConfig();
</script>
</body>
</html>
