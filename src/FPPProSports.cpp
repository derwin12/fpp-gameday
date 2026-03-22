/*
 * fpp-nfl - Pro Sports Scoring Plugin for Falcon Player (FPP)
 * C++ plugin: polls ESPN API, triggers FPP sequences on scores/wins.
 */

#include <fpp-pch.h>
#include <httpserver.hpp>
#include <curl/curl.h>

#include "Plugin.h"
#include "Plugins.h"
#include "log.h"
#include "commands/Commands.h"
#include "common.h"
#include "settings.h"
#include "fppversion_defines.h"

#include <atomic>
#include <condition_variable>
#include <ctime>
#include <map>
#include <mutex>
#include <string>
#include <thread>

// ---------------------------------------------------------------------------
// cURL helpers
// ---------------------------------------------------------------------------

static size_t curlWriteCallback(void *contents, size_t size, size_t nmemb, std::string *out) {
    out->append(static_cast<char *>(contents), size * nmemb);
    return size * nmemb;
}

static std::string fetchURL(const std::string &url) {
    CURL *curl = curl_easy_init();
    if (!curl) return "";
    std::string response;
    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, curlWriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(curl, CURLOPT_ACCEPT_ENCODING, ""); // auto-decompress gzip/deflate
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "fpp-nfl/2.0");
    CURLcode res = curl_easy_perform(curl);
    curl_easy_cleanup(curl);
    if (res != CURLE_OK) {
        LogWarn(VB_PLUGIN, "fpp-nfl: fetchURL failed for %s: %s\n",
                url.c_str(), curl_easy_strerror(res));
        return "";
    }
    return response;
}

static std::string curlEscape(const std::string &s) {
    CURL *curl = curl_easy_init();
    if (!curl) return s;
    char *escaped = curl_easy_escape(curl, s.c_str(), static_cast<int>(s.size()));
    std::string result = escaped ? escaped : s;
    if (escaped) curl_free(escaped);
    curl_easy_cleanup(curl);
    return result;
}

// Parse ISO 8601 UTC datetime string (e.g. "2024-01-15T18:00Z") → time_t
static time_t parseISO8601(const std::string &s) {
    if (s.empty()) return 0;
    struct tm tm = {};
    const char *p = strptime(s.c_str(), "%Y-%m-%dT%H:%M:%SZ", &tm);
    if (!p) p = strptime(s.c_str(), "%Y-%m-%dT%H:%MZ", &tm);
    if (!p) return 0;
    tm.tm_isdst = 0;
    return timegm(&tm);
}

// Parse a JSON string using jsoncpp
static bool parseJson(const std::string &str, Json::Value &root) {
    Json::CharReaderBuilder builder;
    std::string errs;
    std::istringstream ss(str);
    return Json::parseFromStream(builder, ss, &root, &errs);
}

// Serialize a Json::Value to compact string
static std::string jsonToString(const Json::Value &val) {
    Json::StreamWriterBuilder builder;
    builder["indentation"] = "";
    return Json::writeString(builder, val);
}

// ---------------------------------------------------------------------------
// League state
// ---------------------------------------------------------------------------

struct LeagueState {
    std::string teamID;
    std::string teamName;
    std::string teamAbbreviation;
    std::string teamLogo;

    std::string nextEventID;
    std::string nextEventDate;
    std::string gameStatus; // "" / "pre" / "in" / "post"

    std::string oppoID;
    std::string oppoName;
    std::string oppoAbbreviation;

    int myScore   = 0;
    int oppoScore = 0;
    int gamePeriod = 0;
    std::string gameClock;

    // Sequences — football only
    std::string touchdownSequence;
    std::string fieldgoalSequence;
    // Sequences — hockey/baseball only
    std::string scoreSequence;
    // All sports
    std::string winSequence;
};

static bool isFootball(const std::string &league) {
    return league == "nfl" || league == "ncaa";
}

// ---------------------------------------------------------------------------
// ESPN API helpers
// ---------------------------------------------------------------------------

static std::string espnSport(const std::string &league) {
    if (league == "nhl") return "hockey";
    if (league == "mlb") return "baseball";
    return "football";
}

static std::string espnLeague(const std::string &league) {
    if (league == "ncaa") return "college-football";
    return league;
}

// Fills team identity + next event fields in state. Returns true on success.
static bool fetchTeamInfo(const std::string &league, LeagueState &state) {
    if (state.teamID.empty()) return false;

    std::string url = "https://site.api.espn.com/apis/site/v2/sports/"
                    + espnSport(league) + "/" + espnLeague(league)
                    + "/teams/" + state.teamID;

    std::string body = fetchURL(url);
    if (body.empty()) return false;

    Json::Value root;
    if (!parseJson(body, root)) return false;

    const Json::Value &team = root["team"];
    if (team.isNull()) return false;

    state.teamName         = team.get("displayName", "").asString();
    state.teamAbbreviation = team.get("abbreviation", "").asString();

    if (team["logos"].isArray() && !team["logos"].empty())
        state.teamLogo = team["logos"][0].get("href", "").asString();

    // Reset event/opponent fields before re-populating
    state.nextEventID      = "";
    state.nextEventDate    = "";
    state.gameStatus       = "";
    state.oppoID           = "";
    state.oppoName         = "";
    state.oppoAbbreviation = "";

    if (team["nextEvent"].isArray() && !team["nextEvent"].empty()) {
        const Json::Value &ev = team["nextEvent"][0];
        state.nextEventID   = ev.get("id", "").asString();
        state.nextEventDate = ev.get("date", "").asString();

        if (ev["competitions"].isArray() && !ev["competitions"].empty()) {
            const Json::Value &comp = ev["competitions"][0];
            state.gameStatus = comp["status"]["type"].get("state", "").asString();

            if (comp["competitors"].isArray()) {
                for (const auto &c : comp["competitors"]) {
                    std::string cid = c["team"].get("id", "").asString();
                    if (cid != state.teamID) {
                        state.oppoID           = cid;
                        state.oppoName         = c["team"].get("displayName", "").asString();
                        state.oppoAbbreviation = c["team"].get("abbreviation", "").asString();
                    }
                }
            }
        }
    }

    LogInfo(VB_PLUGIN, "fpp-nfl: [%s] team=%s nextGame=%s status=%s\n",
            league.c_str(), state.teamName.c_str(),
            state.nextEventDate.c_str(), state.gameStatus.c_str());
    return true;
}

// Fills status + scores from the live scoreboard. Returns true on success.
static bool fetchGameStatus(const std::string &league, const LeagueState &state,
                             std::string &statusOut, int &myScoreOut, int &oppoScoreOut,
                             int &periodOut, std::string &clockOut) {
    if (state.nextEventID.empty()) return false;

    std::string url = "https://site.api.espn.com/apis/site/v2/sports/"
                    + espnSport(league) + "/" + espnLeague(league)
                    + "/scoreboard/" + state.nextEventID;

    std::string body = fetchURL(url);
    if (body.empty()) return false;

    Json::Value root;
    if (!parseJson(body, root)) return false;

    myScoreOut   = 0;
    oppoScoreOut = 0;

    if (!root["competitions"].isArray() || root["competitions"].empty())
        return false;

    {
        const Json::Value &comp = root["competitions"][0];
        statusOut = comp["status"]["type"].get("state", "").asString();
        periodOut = comp["status"].get("period", 0).asInt();
        clockOut  = comp["status"].get("displayClock", "").asString();

        if (comp["competitors"].isArray()) {
            for (const auto &c : comp["competitors"]) {
                int score = 0;
                if (c.isMember("score")) {
                    try { score = std::stoi(c["score"].asString()); } catch (...) {}
                }
                if (c["team"].get("id", "").asString() == state.teamID)
                    myScoreOut = score;
                else
                    oppoScoreOut = score;
            }
        }
    }

    return !statusOut.empty();
}

// Trigger a sequence via FPP's local REST API
static void triggerSequence(const std::string &seq) {
    if (seq.empty()) return;
    std::string url = "http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/"
                    + curlEscape(seq + ".fseq") + "/0/0";
    LogInfo(VB_PLUGIN, "fpp-nfl: triggering sequence: %s\n", seq.c_str());
    fetchURL(url);
}

// ---------------------------------------------------------------------------
// Plugin class
// ---------------------------------------------------------------------------

static const std::vector<std::string> ALL_LEAGUES = {"nfl", "ncaa", "nhl", "mlb"};

class FPPProSportsPlugin : public FPPPlugins::Plugin,
                           public FPPPlugins::APIProviderPlugin,
                           public httpserver::http_resource {
public:
    FPPProSportsPlugin()
        : FPPPlugins::Plugin("fpp-nfl"),
          FPPPlugins::APIProviderPlugin(),
          m_running(false),
          m_enabled(false),
          m_logLevel(4) {

        for (auto &lg : ALL_LEAGUES)
            m_leagues[lg] = {};

        loadConfig();

        if (m_enabled.load())
            startThread();
    }

    virtual ~FPPProSportsPlugin() {
        stopThread();
    }

    void registerApis(httpserver::webserver *ws) override {
        ws->register_resource("/ProSportsScoring", this, true);
    }

    void unregisterApis(httpserver::webserver *ws) override {
        ws->unregister_resource("/ProSportsScoring");
    }

    // -------------------------------------------------------------------
    // HTTP GET  /api/plugin-apis/ProSportsScoring/{config|status}
    // -------------------------------------------------------------------

    virtual HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response>
    render_GET(const httpserver::http_request &req) override {
        auto pieces = req.get_path_pieces();
        // pieces[0] = "ProSportsScoring", pieces[1] = action
        std::string action = (pieces.size() > 1) ? pieces[1] : "";

        if (action == "config") {
            std::lock_guard<std::mutex> lock(m_stateMutex);
            return jsonResp(buildConfigJson());
        }
        if (action == "status") {
            std::lock_guard<std::mutex> lock(m_stateMutex);
            return jsonResp(buildStatusJson());
        }

        return errResp(404, "Not found");
    }

    // -------------------------------------------------------------------
    // HTTP POST  /api/plugin-apis/ProSportsScoring/{config|refresh/<league>}
    // -------------------------------------------------------------------

    virtual HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response>
    render_POST(const httpserver::http_request &req) override {
        auto pieces = req.get_path_pieces();
        std::string action = (pieces.size() > 1) ? pieces[1] : "";

        if (action == "config") {
            Json::Value cfg;
            if (!parseJson(std::string(req.get_content()), cfg))
                return errResp(400, "Invalid JSON");

            bool wasEnabled = m_enabled.load();
            applyConfig(cfg);
            bool nowEnabled = m_enabled.load();
            saveConfig();

            // Handle thread lifecycle OUTSIDE all locks to avoid deadlock
            if (!wasEnabled && nowEnabled)
                startThread();
            else if (wasEnabled && !nowEnabled)
                stopThread();
            else
                m_cv.notify_all();

            return jsonResp(std::string("{\"status\":\"ok\"}"));
        }

        if (action == "refresh" && pieces.size() > 2) {
            std::string league = pieces[2];
            size_t idx = (pieces.size() > 3) ? std::stoul(pieces[3]) : 0;
            if (m_leagues.find(league) == m_leagues.end())
                return errResp(400, "Unknown league");

            LeagueState copy;
            {
                std::lock_guard<std::mutex> lock(m_stateMutex);
                auto &teams = m_leagues[league];
                if (idx >= teams.size())
                    return errResp(400, "Index out of range");
                copy = teams[idx];
            }
            bool ok = fetchTeamInfo(league, copy);
            if (ok) {
                std::lock_guard<std::mutex> lock(m_stateMutex);
                auto &teams = m_leagues[league];
                if (idx < teams.size() && teams[idx].teamID == copy.teamID) {
                    copy.winSequence       = teams[idx].winSequence;
                    copy.touchdownSequence = teams[idx].touchdownSequence;
                    copy.fieldgoalSequence = teams[idx].fieldgoalSequence;
                    copy.scoreSequence     = teams[idx].scoreSequence;
                    copy.myScore   = 0;
                    copy.oppoScore = 0;
                    teams[idx] = copy;
                }
            }
            saveConfig();
            m_cv.notify_all();

            std::string body = ok ? "{\"status\":\"ok\"}" : "{\"status\":\"error\"}";
            return jsonResp(body);
        }

        return errResp(404, "Not found");
    }

private:
    // -------------------------------------------------------------------
    // Config persistence
    // -------------------------------------------------------------------

    void loadConfig() {
        std::string path = FPP_DIR_CONFIG("/plugin.fpp-nfl.json");
        if (!FileExists(path)) {
            LogInfo(VB_PLUGIN, "fpp-nfl: no config at %s, using defaults\n", path.c_str());
            return;
        }
        Json::Value cfg;
        if (!LoadJsonFromFile(path, cfg)) {
            LogWarn(VB_PLUGIN, "fpp-nfl: failed to parse config %s\n", path.c_str());
            return;
        }
        applyConfig(cfg);
        LogInfo(VB_PLUGIN, "fpp-nfl: config loaded\n");
    }

    void saveConfig() {
        std::string path = FPP_DIR_CONFIG("/plugin.fpp-nfl.json");
        Json::Value cfg;
        {
            std::lock_guard<std::mutex> lock(m_stateMutex);
            cfg = buildConfigJson();
        }
        if (!SaveJsonToFile(cfg, path))
            LogWarn(VB_PLUGIN, "fpp-nfl: failed to save config to %s\n", path.c_str());
    }

    // Must be called with m_stateMutex held
    Json::Value buildConfigJson() const {
        Json::Value cfg;
        cfg["enabled"]  = m_enabled.load();
        cfg["logLevel"] = m_logLevel;

        for (auto &lg : ALL_LEAGUES) {
            Json::Value arr(Json::arrayValue);
            for (const auto &s : m_leagues.at(lg)) {
                Json::Value lv;
                lv["teamID"]            = s.teamID;
                lv["teamName"]          = s.teamName;
                lv["teamAbbreviation"]  = s.teamAbbreviation;
                lv["teamLogo"]          = s.teamLogo;
                lv["nextEventID"]       = s.nextEventID;
                lv["nextEventDate"]     = s.nextEventDate;
                lv["gameStatus"]        = s.gameStatus;
                lv["oppoID"]            = s.oppoID;
                lv["oppoName"]          = s.oppoName;
                lv["oppoAbbreviation"]  = s.oppoAbbreviation;
                lv["myScore"]           = s.myScore;
                lv["oppoScore"]         = s.oppoScore;
                lv["winSequence"]       = s.winSequence;
                lv["touchdownSequence"] = s.touchdownSequence;
                lv["fieldgoalSequence"] = s.fieldgoalSequence;
                lv["scoreSequence"]     = s.scoreSequence;
                arr.append(lv);
            }
            cfg["leagues"][lg] = arr;
        }
        return cfg;
    }

    // Must be called with m_stateMutex held
    Json::Value buildStatusJson() const {
        Json::Value st;
        st["enabled"] = m_enabled.load();
        for (auto &lg : ALL_LEAGUES) {
            Json::Value arr(Json::arrayValue);
            for (const auto &s : m_leagues.at(lg)) {
                Json::Value lv;
                lv["teamID"]           = s.teamID;
                lv["teamName"]         = s.teamName;
                lv["teamAbbreviation"] = s.teamAbbreviation;
                lv["teamLogo"]         = s.teamLogo;
                lv["nextEventDate"]    = s.nextEventDate;
                lv["gameStatus"]       = s.gameStatus;
                lv["oppoName"]         = s.oppoName;
                lv["oppoAbbreviation"] = s.oppoAbbreviation;
                lv["myScore"]          = s.myScore;
                lv["oppoScore"]        = s.oppoScore;
                lv["gamePeriod"]       = s.gamePeriod;
                lv["gameClock"]        = s.gameClock;
                arr.append(lv);
            }
            st["leagues"][lg] = arr;
        }
        return st;
    }

    // Acquires m_stateMutex; safe to call without holding it.
    void applyConfig(const Json::Value &cfg) {
        std::lock_guard<std::mutex> lock(m_stateMutex);

        if (cfg.isMember("enabled"))  m_enabled  = cfg["enabled"].asBool();
        if (cfg.isMember("logLevel")) m_logLevel = cfg["logLevel"].asInt();

        if (!cfg.isMember("leagues")) return;
        const Json::Value &leagues = cfg["leagues"];

        for (auto &lg : ALL_LEAGUES) {
            if (!leagues.isMember(lg)) continue;
            const Json::Value &arr = leagues[lg];
            if (!arr.isArray()) continue;

            std::vector<LeagueState> &existing = m_leagues[lg];
            std::vector<LeagueState> newTeams;

            for (const auto &lv : arr) {
                std::string newID = lv.get("teamID", "").asString();

                // Start from existing cached state for same teamID
                LeagueState s;
                for (const auto &es : existing) {
                    if (!newID.empty() && es.teamID == newID) { s = es; break; }
                }
                s.teamID = newID;

                if (lv.isMember("teamName"))          s.teamName          = lv["teamName"].asString();
                if (lv.isMember("teamAbbreviation"))  s.teamAbbreviation  = lv["teamAbbreviation"].asString();
                if (lv.isMember("teamLogo"))          s.teamLogo          = lv["teamLogo"].asString();
                if (lv.isMember("nextEventID"))       s.nextEventID       = lv["nextEventID"].asString();
                if (lv.isMember("nextEventDate"))     s.nextEventDate     = lv["nextEventDate"].asString();
                if (lv.isMember("gameStatus"))        s.gameStatus        = lv["gameStatus"].asString();
                if (lv.isMember("oppoID"))            s.oppoID            = lv["oppoID"].asString();
                if (lv.isMember("oppoName"))          s.oppoName          = lv["oppoName"].asString();
                if (lv.isMember("oppoAbbreviation"))  s.oppoAbbreviation  = lv["oppoAbbreviation"].asString();
                if (lv.isMember("myScore"))           s.myScore           = lv["myScore"].asInt();
                if (lv.isMember("oppoScore"))         s.oppoScore         = lv["oppoScore"].asInt();
                if (lv.isMember("winSequence"))       s.winSequence       = lv["winSequence"].asString();
                if (lv.isMember("touchdownSequence")) s.touchdownSequence = lv["touchdownSequence"].asString();
                if (lv.isMember("fieldgoalSequence")) s.fieldgoalSequence = lv["fieldgoalSequence"].asString();
                if (lv.isMember("scoreSequence"))     s.scoreSequence     = lv["scoreSequence"].asString();
                newTeams.push_back(std::move(s));
            }

            m_leagues[lg] = std::move(newTeams);
        }
    }

    // -------------------------------------------------------------------
    // HTTP response helpers
    // -------------------------------------------------------------------

    static std::shared_ptr<httpserver::http_response> jsonResp(const Json::Value &val) {
        return std::make_shared<httpserver::string_response>(
            jsonToString(val), 200, "application/json");
    }

    static std::shared_ptr<httpserver::http_response> jsonResp(const std::string &json) {
        return std::make_shared<httpserver::string_response>(json, 200, "application/json");
    }

    static std::shared_ptr<httpserver::http_response> errResp(int code, const std::string &msg) {
        return std::make_shared<httpserver::string_response>(
            "{\"error\":\"" + msg + "\"}", code, "application/json");
    }

    // -------------------------------------------------------------------
    // Thread management
    // -------------------------------------------------------------------

    void startThread() {
        if (m_running.exchange(true)) return; // already running
        m_thread = std::thread(&FPPProSportsPlugin::pollLoop, this);
        LogInfo(VB_PLUGIN, "fpp-nfl: polling thread started\n");
    }

    // Safe to call from any thread; does NOT hold m_stateMutex.
    void stopThread() {
        if (!m_running.exchange(false)) return; // already stopped
        m_cv.notify_all();
        if (m_thread.joinable())
            m_thread.join();
        LogInfo(VB_PLUGIN, "fpp-nfl: polling thread stopped\n");
    }

    // -------------------------------------------------------------------
    // Polling loop
    // -------------------------------------------------------------------

    void pollLoop() {
        LogInfo(VB_PLUGIN, "fpp-nfl: pollLoop starting\n");

        while (m_running.load()) {
            if (!m_enabled.load()) {
                // Sleep on m_cvMutex — does NOT block m_stateMutex
                std::unique_lock<std::mutex> lk(m_cvMutex);
                m_cv.wait_for(lk, std::chrono::seconds(10),
                              [this] { return !m_running.load() || m_enabled.load(); });
                continue;
            }

            // Copy state out under lock so ESPN calls don't hold m_stateMutex
            std::map<std::string, std::vector<LeagueState>> snap;
            {
                std::lock_guard<std::mutex> lock(m_stateMutex);
                snap = m_leagues;
            }

            int minSleep = 600;

            for (auto &lg : ALL_LEAGUES) {
                if (!m_running.load()) break;
                auto &teams = snap[lg];

                for (size_t i = 0; i < teams.size(); i++) {
                    if (!m_running.load()) break;
                    LeagueState &ls = teams[i];
                    if (ls.teamID.empty()) continue;

                    int sleepSecs = pollLeague(lg, ls);
                    if (sleepSecs < minSleep) minSleep = sleepSecs;

                    // Write back under lock; match by teamID
                    {
                        std::lock_guard<std::mutex> lock(m_stateMutex);
                        for (auto &mt : m_leagues[lg]) {
                            if (mt.teamID == ls.teamID) {
                                ls.winSequence       = mt.winSequence;
                                ls.touchdownSequence = mt.touchdownSequence;
                                ls.fieldgoalSequence = mt.fieldgoalSequence;
                                ls.scoreSequence     = mt.scoreSequence;
                                mt = ls;
                                break;
                            }
                        }
                    }
                }
            }

            // Save after all leagues updated
            saveConfig();

            // Sleep on m_cvMutex — does NOT block m_stateMutex
            if (m_running.load()) {
                std::unique_lock<std::mutex> lk(m_cvMutex);
                m_cv.wait_for(lk, std::chrono::seconds(minSleep),
                              [this] { return !m_running.load(); });
            }
        }

        LogInfo(VB_PLUGIN, "fpp-nfl: pollLoop exiting\n");
    }

    // Polls one league. Returns recommended sleep time in seconds.
    // ls is a local copy — modify freely; caller writes back under lock.
    int pollLeague(const std::string &league, LeagueState &ls) {
        if (m_logLevel >= 5)
            LogDebug(VB_PLUGIN, "fpp-nfl: [%s] polling, status=%s\n",
                     league.c_str(), ls.gameStatus.c_str());

        // POST-game: look for the next game
        if (ls.gameStatus == "post") {
            fetchTeamInfo(league, ls);
            return 600;
        }

        // PRE-game: check if it's time to start watching
        if (ls.gameStatus == "pre") {
            time_t gameTime   = parseISO8601(ls.nextEventDate);
            time_t now        = time(nullptr);
            time_t timeToGame = (gameTime > 0) ? (gameTime - now) : 99999L;

            if (timeToGame > 1200)
                return 600;

            // Within 20 minutes — check if game has started
            std::string newStatus, newClock;
            int myScore = 0, oppoScore = 0, newPeriod = 0;
            if (fetchGameStatus(league, ls, newStatus, myScore, oppoScore, newPeriod, newClock)) {
                ls.gameStatus  = newStatus;
                ls.myScore     = myScore;
                ls.oppoScore   = oppoScore;
                ls.gamePeriod  = newPeriod;
                ls.gameClock   = newClock;
            }
            return 30;
        }

        // IN-game or unknown: fetch live scoreboard
        if (ls.gameStatus == "in" || ls.gameStatus.empty()) {
            // If we have no event yet, fetch team info first
            if (ls.nextEventID.empty()) {
                fetchTeamInfo(league, ls);
                if (ls.nextEventID.empty()) return 600;
            }

            std::string newStatus, newClock;
            int newMy = 0, newOppo = 0, newPeriod = 0;
            if (!fetchGameStatus(league, ls, newStatus, newMy, newOppo, newPeriod, newClock))
                return 30; // API error — retry soon

            int prevMy = ls.myScore;

            ls.gameStatus  = newStatus;
            ls.myScore     = newMy;
            ls.oppoScore   = newOppo;
            ls.gamePeriod  = newPeriod;
            ls.gameClock   = newClock;

            // Score change detection (only when game is live or just ended)
            if (newStatus == "in" || newStatus == "post") {
                int delta = newMy - prevMy;
                if (delta > 0) {
                    LogInfo(VB_PLUGIN, "fpp-nfl: [%s] score! my=%d (was %d) oppo=%d\n",
                            league.c_str(), newMy, prevMy, newOppo);
                    if (isFootball(league)) {
                        triggerSequence(delta >= 6 ? ls.touchdownSequence
                                                   : ls.fieldgoalSequence);
                    } else {
                        triggerSequence(ls.scoreSequence);
                    }
                }
            }

            // Win detection when game ends
            if (newStatus == "post") {
                if (newMy > newOppo) {
                    LogInfo(VB_PLUGIN, "fpp-nfl: [%s] WIN! my=%d oppo=%d\n",
                            league.c_str(), newMy, newOppo);
                    triggerSequence(ls.winSequence);
                }
                return 600;
            }

            return (newStatus == "in") ? 5 : 600;
        }

        return 600;
    }

    // -------------------------------------------------------------------
    // Members
    // -------------------------------------------------------------------

    mutable std::mutex      m_stateMutex;   // protects m_leagues, m_logLevel
    std::mutex              m_cvMutex;      // used ONLY with m_cv (never held long)
    std::condition_variable m_cv;
    std::thread             m_thread;

    std::atomic<bool> m_running;
    std::atomic<bool> m_enabled;
    int               m_logLevel;

    std::map<std::string, std::vector<LeagueState>> m_leagues;
};

// ---------------------------------------------------------------------------
// Plugin entry point
// ---------------------------------------------------------------------------

extern "C" {
    FPPPlugins::Plugin *createPlugin() {
        return new FPPProSportsPlugin();
    }
}
