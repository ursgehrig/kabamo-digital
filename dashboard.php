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
 * dashboard.php — anonymised, read-only aggregates for the v2 dashboard.
 * Returns JSON. NEVER returns row-level PII (no hh_id, no phone_hash,
 * no per-household records). Aggregates only, with small-count suppression.
 *
 * Deploy at: gehrigpartner.com/kabamo/v2/dashboard.php
 * ============================================================ */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DB_PATH = __DIR__ . '/kabamo.db';
const K_ANON  = 5;   // suppress any bucket with fewer than K households (k-anonymity)

require_once __DIR__ . '/seed.php';

function db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

try {
    $pdo = db();
    seed_ensure($pdo);   // auto-populate synthetic demo data if near-empty

    // ---- headline counts (the asset register at a glance) ----
    $hhTotal = (int)$pdo->query("SELECT COUNT(*) FROM households")->fetchColumn();

    $sums = $pdo->query(
        "SELECT
            COALESCE(SUM(people_total),0)    AS people,
            COALESCE(SUM(sleeping_spaces),0)  AS spaces,
            COALESCE(SUM(under5),0)           AS under5,
            COALESCE(SUM(pregnant),0)         AS pregnant,
            COALESCE(SUM(nets_entitled),0)    AS nets_entitled
         FROM households"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    // derived, defensible figures
    $avgPeople = $hhTotal ? round(((int)$sums['people']) / $hhTotal, 1) : 0;
    $avgSpaces = $hhTotal ? round(((int)$sums['spaces']) / $hhTotal, 1) : 0;
    $netsPerPerson = ((int)$sums['people']) > 0
        ? round(((int)$sums['nets_entitled']) / ((int)$sums['people']), 2) : 0;

    // ---- distribution of sleeping spaces (the net-demand shape) ----
    // suppress buckets below K_ANON
    $distRows = $pdo->query(
        "SELECT sleeping_spaces AS k, COUNT(*) AS n
         FROM households WHERE sleeping_spaces IS NOT NULL
         GROUP BY sleeping_spaces ORDER BY sleeping_spaces"
    )->fetchAll(PDO::FETCH_ASSOC);
    $spacesDist = [];
    foreach ($distRows as $r) {
        $n = (int)$r['n'];
        $spacesDist[] = ['spaces' => (int)$r['k'], 'count' => ($n < K_ANON ? null : $n)];
    }

    // ---- re-survey freshness (how live is the register?) ----
    $due = (int)$pdo->query("SELECT COUNT(*) FROM v_resurvey_due")->fetchColumn();
    $fresh = max(0, $hhTotal - $due);

    // ---- readiness roll-up (latest status per chain) ----
    // count of reports by chain + status (1 ok / 2 partial / 3 problem)
    $repRows = $pdo->query(
        "SELECT chain, status, COUNT(*) AS n
         FROM reports GROUP BY chain, status"
    )->fetchAll(PDO::FETCH_ASSOC);
    $chains = [];
    foreach ($repRows as $r) {
        $c = $r['chain']; $s = (int)$r['status']; $n = (int)$r['n'];
        if (!isset($chains[$c])) $chains[$c] = ['ok'=>0,'partial'=>0,'problem'=>0];
        $key = [1=>'ok',2=>'partial',3=>'problem'][$s] ?? 'ok';
        $chains[$c][$key] += $n;
    }

    // ---- total reports + last activity ----
    $repTotal = (int)$pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
    $lastAct  = $pdo->query("SELECT MAX(created_at) FROM reports")->fetchColumn() ?: null;

    // ---- anonymised recent activity (last 7 households) ----
    // Masked id only, sleeping spaces, survey round, time. NO phone, NO full id.
    $recentRows = $pdo->query(
        "SELECT hh_id, sleeping_spaces, survey_round, last_seen
         FROM households ORDER BY last_seen DESC LIMIT 7"
    )->fetchAll(PDO::FETCH_ASSOC);
    $recent = [];
    foreach ($recentRows as $r) {
        $id = (string)$r['hh_id'];           // e.g. HH-00001
        $masked = 'HH-••' . substr($id, -3);  // -> HH-••001
        $recent[] = [
            'hh'     => $masked,
            'spaces' => $r['sleeping_spaces'] !== null ? (int)$r['sleeping_spaces'] : null,
            'round'  => (int)$r['survey_round'],
            'at'     => $r['last_seen'],
        ];
    }

    // ---- per-district coverage (reported vs expected) ----
    $regions = [];
    $hasDistricts = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='districts'")->fetchColumn();
    if ($hasDistricts) {
        $dRows = $pdo->query(
            "SELECT d.code, d.name, d.expected_households AS expected,
                    COUNT(h.hh_id) AS reported,
                    COALESCE(SUM(h.sleeping_spaces),0) AS spaces
             FROM districts d
             LEFT JOIN households h ON h.district = d.name
             GROUP BY d.code, d.name, d.expected_households
             ORDER BY d.code"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dRows as $r) {
            $exp = max(1, (int)$r['expected']);
            $rep = (int)$r['reported'];
            $cov = round(min(1.0, $rep / $exp), 2);
            $regions[] = [
                'code'     => $r['code'],
                'name'     => $r['name'],
                'expected' => (int)$r['expected'],
                'reported' => $rep,
                'coverage' => $cov,
                'spaces'   => (int)$r['spaces'],
                'status'   => $cov >= 0.5 ? 'ok' : ($cov >= 0.25 ? 'low' : 'critical'),
            ];
        }
    }
    $seeded = 0;
    try { $seeded = (int)$pdo->query("SELECT COUNT(*) FROM households WHERE seed=1")->fetchColumn(); }
    catch (Throwable $e) { $seeded = 0; }

    echo json_encode([
        'ok' => true,
        'generated_at' => date('c'),
        'register' => [
            'households'      => $hhTotal,
            'people'          => (int)$sums['people'],
            'sleeping_spaces' => (int)$sums['spaces'],
            'under5'          => (int)$sums['under5'],
            'pregnant'        => (int)$sums['pregnant'],
            'nets_entitled'   => (int)$sums['nets_entitled'],
            'avg_people'      => $avgPeople,
            'avg_spaces'      => $avgSpaces,
            'nets_per_person' => $netsPerPerson,
        ],
        'freshness' => [
            'fresh' => $fresh,
            'due'   => $due,
        ],
        'spaces_distribution' => $spacesDist,
        'chains' => $chains,
        'regions' => $regions,
        'seeded' => $seeded,
        'activity' => [
            'reports_total' => $repTotal,
            'last_activity' => $lastAct,
            'recent' => $recent,
        ],
        'k_anon' => K_ANON,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'no data yet', 'detail' => $e->getMessage()]);
}
