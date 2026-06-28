-- ============================================================
-- Kabamo v2 — demand-register-first USSD demonstrator
-- SQLite schema. Run once:  sqlite3 kabamo.db < schema.sql
-- ============================================================
-- Design notes:
--  * "households" IS the asset register — the live denominator the
--    MSH quantification step assumes but usually lacks.
--  * Net demand is keyed on SLEEPING SPACES (mattresses), not headcount
--    (Senegal method): the adult son on his own mattress is a real net need.
--  * Phone numbers are stored HASHED (near-anonymous). Never store raw MSISDN.
--  * "reports" logs every chain/step/status event (the readiness loop).
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ---------- 1. HOUSEHOLDS = the asset register ----------
CREATE TABLE IF NOT EXISTS households (
  hh_id           TEXT PRIMARY KEY,         -- e.g. 'HH-01458'
  phone_hash      TEXT UNIQUE,              -- sha256(msisdn + salt); never raw
  region          TEXT DEFAULT 'Kabamo',
  district        TEXT,
  village         TEXT,
  -- demand-defining fields (the register's payload)
  people_total    INTEGER,                  -- headcount (context, not the net unit)
  adults          INTEGER,
  under5          INTEGER,
  pregnant        INTEGER,                  -- count of pregnant household members
  sleeping_spaces INTEGER,                  -- = mattresses; THE net-demand unit
  -- derived entitlement (capped)
  nets_entitled   INTEGER,                  -- min(sleeping_spaces, cap) per policy
  -- lifecycle
  created_at      TEXT DEFAULT (datetime('now')),
  last_seen       TEXT DEFAULT (datetime('now')),  -- drives 6-monthly re-survey
  survey_round    INTEGER DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_hh_lastseen ON households(last_seen);
CREATE INDEX IF NOT EXISTS idx_hh_district ON households(district);

-- ---------- 2. SESSIONS = live USSD state ----------
-- Africa's Talking is largely stateless (it resends the full keypress
-- string each hit), but we persist a row so we can resume / audit and
-- store partial register answers mid-flow.
CREATE TABLE IF NOT EXISTS sessions (
  session_id   TEXT PRIMARY KEY,            -- from the aggregator
  phone_hash   TEXT,
  chain        TEXT,                        -- 'register','quantify','supply','lastmile','protect'
  step         TEXT,                        -- current step key
  scratch      TEXT,                        -- JSON blob: partial answers this session
  created_at   TEXT DEFAULT (datetime('now')),
  updated_at   TEXT DEFAULT (datetime('now'))
);

-- ---------- 3. REPORTS = the readiness loop events ----------
-- Every chain/step status report (1 ok / 2 partial / 3 problem).
CREATE TABLE IF NOT EXISTS reports (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  chain        TEXT NOT NULL,
  step         TEXT NOT NULL,
  status       INTEGER NOT NULL,            -- 1 green, 2 amber, 3 red
  hh_id        TEXT,                         -- nullable (some reports are role-level)
  phone_hash   TEXT,
  district     TEXT,
  note         TEXT,
  created_at   TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_rep_chain ON reports(chain, step);
CREATE INDEX IF NOT EXISTS idx_rep_time  ON reports(created_at);

-- ---------- 4. CHAIN / STEP reference (optional, documents the model) ----------
CREATE TABLE IF NOT EXISTS chain_def (
  chain      TEXT,
  seq        INTEGER,
  step       TEXT,
  prompt     TEXT,
  msh_node   TEXT,                          -- maps to MSH cycle node
  PRIMARY KEY (chain, step)
);

DELETE FROM chain_def;
INSERT INTO chain_def (chain, seq, step, prompt, msh_node) VALUES
 -- Chain 1: REGISTER THE DEMAND (the new, flagship chain) -> feeds Quantification
 ('register', 1, 'people',   'How many people live here? (1-9)',                'Selection & Quantification'),
 ('register', 2, 'spaces',   'How many sleeping places / mattresses? (1-9)',    'Selection & Quantification'),
 ('register', 3, 'under5',   'How many children under 5? (0-9)',                'Selection & Quantification'),
 ('register', 4, 'pregnant', 'Anyone pregnant? 1 no  2 one  3 more',            'Selection & Quantification'),
 -- Chain 2: QUANTIFY & ALLOCATE
 ('quantify', 1, 'entitle',  'Confirm entitlement vs register',                 'Selection & Quantification'),
 -- Chain 3: SUPPLY TO CHC
 ('supply',   1, 'fund',     'Tranche released? 1 yes 2 partial 3 no',          'Procurement'),
 ('supply',   2, 'store',    'Nets received + stored? 1 secure 2 part 3 outdoor','Procurement'),
 -- Chain 4: LAST-MILE & RESISTANCE
 ('lastmile', 1, 'move',     'Moved to points? 1 all 2 some 3 none',            'Storage & Distribution'),
 ('lastmile', 2, 'cfp',      'CFP nets reached Belessa? 1 yes 2 part 3 no',     'Storage & Distribution'),
 -- Chain 5: PROTECTION & MONITORING -> loops back to register
 ('protect',  1, 'slept',    'Slept under net last night? 1 yes 2 partly 3 no', 'Use'),
 ('protect',  2, 'resurvey', 'Re-survey due? logs last_seen',                   'Use');

-- ---------- handy views ----------
-- Households due for re-survey (>180 days since last_seen)
CREATE VIEW IF NOT EXISTS v_resurvey_due AS
  SELECT hh_id, district, last_seen
  FROM households
  WHERE julianday('now') - julianday(last_seen) > 180;

-- Latest status per chain/step (the dashboard roll-up)
CREATE VIEW IF NOT EXISTS v_latest_status AS
  SELECT chain, step, status, district, MAX(created_at) AS at
  FROM reports
  GROUP BY chain, step, district;
