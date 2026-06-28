<?php
/*
 * Copyright 2026 Urs Gehrig
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
/* ============================================================
 * Kabamo v2 — USSD demonstrator  (Africa's Talking webhook)
 * Demand-register-first: the first chain builds the live denominator.
 *
 * AT contract:  receives POST sessionId, serviceCode, phoneNumber, text
 *   - text = accumulated keypresses joined by '*'  (e.g. "1*2*3")
 *   - reply "CON ..."  -> keep session open, show menu
 *   - reply "END ..."  -> close session
 *
 * Deploy at: gehrigpartner.com/kabamo/v2/ussd.php
 * DB:        sqlite3 kabamo.db < schema.sql   (same dir, writable by web user)
 * ============================================================ */

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

// ---------- config ----------
const DB_PATH   = __DIR__ . '/kabamo.db';
const HASH_SALT = '4074f45390bd650dfc28e5b1b074003af7620e776cebcb8e5e6d69c5838758cc';   // for phone hashing
const NET_CAP   = 4;                                       // 4-net household cap

// ---------- helpers ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }
    return $pdo;
}
function phone_hash(string $msisdn): string {
    return hash('sha256', $msisdn . '|' . HASH_SALT);
}
/* text is the full AT keypress string; parts() splits it into the path */
function parts(string $text): array {
    $t = trim($text);
    if ($t === '') return [];
    return explode('*', $t);
}
function con(string $msg): void { echo "CON " . $msg; exit; }
function end_(string $msg): void { echo "END " . $msg; exit; }

// ---------- input ----------
$sessionId = $_POST['sessionId']   ?? ('sim-' . substr(md5((string)microtime(true)), 0, 8));
$msisdn    = $_POST['phoneNumber'] ?? '+000000000000';
$text      = $_POST['text']        ?? '';
$pid       = phone_hash($msisdn);
$p         = parts($text);

/* ============================================================
 * LEVEL 0 — main menu (the chain picker)
 * ============================================================ */
if (count($p) === 0) {
    con(
        "Kabamo readiness (v2)\n" .
        "1. Register my household\n" .
        "2. Quantify & allocate\n" .
        "3. Supply to CHC\n" .
        "4. Last-mile & resistance\n" .
        "5. Protection & monitoring"
    );
}

$chain = $p[0];

/* ============================================================
 * CHAIN 1 — REGISTER THE DEMAND   (flagship, fully built)
 *   feeds MSH "Selection & Quantification"
 *   net-demand unit = sleeping spaces (mattresses), Senegal method
 *   path after '1':  people * spaces * under5 * pregnant
 * ============================================================ */
if ($chain === '1') {
    // p[1]=people, p[2]=spaces, p[3]=under5, p[4]=pregnant
    $n = count($p);

    if ($n === 1) {
        con("Register household\nHow many people live here? (1-9)");
    }
    if ($n === 2) {
        if (!preg_match('/^[1-9]$/', $p[1])) con("Enter a digit 1-9.\nHow many people live here?");
        con("Sleeping places / mattresses?\n(how many people can sleep separately) (1-9)");
    }
    if ($n === 3) {
        if (!preg_match('/^[1-9]$/', $p[2])) con("Enter a digit 1-9.\nSleeping places / mattresses?");
        con("Children under 5? (0-9)");
    }
    if ($n === 4) {
        if (!preg_match('/^[0-9]$/', $p[3])) con("Enter a digit 0-9.\nChildren under 5?");
        con("Anyone pregnant?\n1. No\n2. One\n3. More than one");
    }
    if ($n === 5) {
        if (!preg_match('/^[1-3]$/', $p[4])) con("Reply 1-3.\nAnyone pregnant?");

        $people   = (int)$p[1];
        $spaces   = (int)$p[2];
        $under5   = (int)$p[3];
        $pregMap  = [1 => 0, 2 => 1, 3 => 2];
        $pregnant = $pregMap[(int)$p[4]];

        // entitlement: nets per sleeping space, capped
        $entitled = min($spaces, NET_CAP);

        // upsert household (near-anonymous: keyed on phone hash)
        $hh = db()->prepare("SELECT hh_id, survey_round FROM households WHERE phone_hash = ?");
        $hh->execute([$pid]);
        $row = $hh->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $hh_id = $row['hh_id'];
            $round = (int)$row['survey_round'] + 1;
            $u = db()->prepare(
                "UPDATE households SET people_total=?, sleeping_spaces=?, under5=?, pregnant=?,
                        nets_entitled=?, last_seen=datetime('now'), survey_round=?
                 WHERE phone_hash=?"
            );
            $u->execute([$people, $spaces, $under5, $pregnant, $entitled, $round, $pid]);
        } else {
            // new household id
            $cnt = (int)db()->query("SELECT COUNT(*) FROM households")->fetchColumn();
            $hh_id = sprintf('HH-%05d', $cnt + 1);
            $i = db()->prepare(
                "INSERT INTO households
                   (hh_id, phone_hash, people_total, sleeping_spaces, under5, pregnant, nets_entitled)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $i->execute([$hh_id, $pid, $people, $spaces, $under5, $pregnant, $entitled]);
        }

        // log the register event into the readiness loop
        $r = db()->prepare(
            "INSERT INTO reports (chain, step, status, hh_id, phone_hash, note)
             VALUES ('register','spaces',1,?,?,?)"
        );
        $r->execute([$hh_id, $pid, "spaces=$spaces people=$people u5=$under5 preg=$pregnant"]);

        $capTxt = NET_CAP;
        end_(
            "Registered: $hh_id\n" .
            "People $people · sleeping places $spaces\n" .
            "Net entitlement: $entitled (cap $capTxt)\n" .
            "Thank you — your record keeps the count current."
        );
    }
}

/* ============================================================
 * CHAINS 2-5 — stubbed (status-report pattern, build out next)
 *   each: pick chain -> one or two single-digit status answers
 * ============================================================ */
$stubs = [
    '2' => ['Quantify & allocate', 'Confirm entitlement vs register?', 'quantify', 'entitle'],
    '3' => ['Supply to CHC',       'Nets received + stored?',          'supply',   'store'],
    '4' => ['Last-mile & resist.', 'CFP nets reached Belessa?',        'lastmile', 'cfp'],
    '5' => ['Protection & monit.', 'Slept under net last night?',      'protect',  'slept'],
];
if (isset($stubs[$chain])) {
    [$title, $q, $cName, $sName] = $stubs[$chain];
    $n = count($p);
    if ($n === 1) {
        con("$title\n$q\n1. OK  2. Partial  3. Problem");
    }
    if ($n === 2) {
        if (!preg_match('/^[1-3]$/', $p[1])) con("Reply 1-3.\n$q");
        $status = (int)$p[1];
        $r = db()->prepare(
            "INSERT INTO reports (chain, step, status, phone_hash) VALUES (?,?,?,?)"
        );
        $r->execute([$cName, $sName, $status, $pid]);
        $label = [1 => 'OK', 2 => 'partial', 3 => 'problem'][$status];
        end_("$title\nReport saved: $label.\nThank you.");
    }
}

/* ---------- fallback ---------- */
end_("Sorry, that option isn't recognised.\nDial again and choose 1-5.");
