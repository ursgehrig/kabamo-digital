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
 * seed.php — auto-populate the demo with clearly-labelled SYNTHETIC data
 * so the dashboard always looks alive. Idempotent and safe:
 *   - creates a districts reference table (6 Kabamo districts) if missing
 *   - adds a `seed` flag column to households if missing
 *   - if real households are scarce, inserts synthetic rows across districts
 *     with deliberately UNEVEN coverage (so some districts visibly lag)
 *
 * Included by dashboard.php; can also be hit directly to (re)seed.
 * All synthetic rows are marked seed=1 and use a 'demo:' phone-hash prefix
 * so they are never confused with real registrations.
 * ============================================================ */

declare(strict_types=1);

function seed_ensure(PDO $pdo): void {
    // 1) districts reference table: name + expected households (the denominator target)
    $pdo->exec("CREATE TABLE IF NOT EXISTS districts (
        code TEXT PRIMARY KEY,
        name TEXT,
        expected_households INTEGER
    )");
    $have = (int)$pdo->query("SELECT COUNT(*) FROM districts")->fetchColumn();
    if ($have === 0) {
        // 6 districts (matches the Kabamo case). Names are fictional.
        $rows = [
            ['D1','Belessa',   90],   // resistance district (CFP)
            ['D2','Tamou',     70],
            ['D3','Kori',      60],
            ['D4','Sahel-Nord',80],
            ['D5','Gourma',    50],
            ['D6','Falaise',   40],
        ];
        $ins = $pdo->prepare("INSERT INTO districts (code,name,expected_households) VALUES (?,?,?)");
        foreach ($rows as $r) $ins->execute($r);
    }

    // 2) add seed flag to households if missing (ALTER is a no-op-safe try)
    try { $pdo->exec("ALTER TABLE households ADD COLUMN seed INTEGER DEFAULT 0"); }
    catch (Throwable $e) { /* column already exists */ }

    // 3) populate synthetic households if the register is near-empty
    $hh = (int)$pdo->query("SELECT COUNT(*) FROM households")->fetchColumn();
    if ($hh >= 20) return;   // enough data already; don't double-seed

    // deliberately uneven coverage: D5/D6 lag (so 'who hasn't reported' is visible)
    $coverage = ['D1'=>0.72,'D2'=>0.55,'D3'=>0.40,'D4'=>0.50,'D5'=>0.18,'D6'=>0.10];
    $dists = $pdo->query("SELECT code,name,expected_households FROM districts")->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare(
        "INSERT INTO households
           (hh_id, phone_hash, district, people_total, adults, under5, pregnant,
            sleeping_spaces, nets_entitled, created_at, last_seen, survey_round, seed)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)"
    );

    $n = (int)$pdo->query("SELECT COUNT(*) FROM households")->fetchColumn();
    mt_srand(20260628); // stable demo data
    foreach ($dists as $d) {
        $target = (int)round($d['expected_households'] * ($coverage[$d['code']] ?? 0.4));
        for ($i=0; $i<$target; $i++) {
            $n++;
            $people  = 3 + mt_rand(0,5);                 // 3..8
            $spaces  = max(1, (int)round($people/1.6) + mt_rand(-1,1)); // ~mattresses
            $under5  = mt_rand(0, 3);
            $pregnant= (mt_rand(0,100) < 18) ? 1 : 0;     // ~18% have a pregnancy
            $entitled= min($spaces, 4);                   // 4-net cap
            $round   = (mt_rand(0,100) < 25) ? 2 : 1;     // ~25% already re-surveyed
            // ages of records spread over the last ~8 months (some due for re-survey)
            $daysAgo = mt_rand(5, 240);
            $seen    = date('Y-m-d H:i:s', time() - $daysAgo*86400);
            $created = date('Y-m-d H:i:s', time() - ($daysAgo + mt_rand(0,30))*86400);
            $hhid    = sprintf('HH-%05d', $n);
            $phash   = 'demo:' . hash('sha256', $hhid . $d['code']);  // demo prefix
            $ins->execute([$hhid, $phash, $d['name'], $people, $people-$under5, $under5,
                           $pregnant, $spaces, $entitled, $created, $seen, $round]);
        }
    }
}

// allow direct hit to (re)seed for convenience
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header('Content-Type: application/json');
    try {
        $pdo = new PDO('sqlite:' . __DIR__ . '/kabamo.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        seed_ensure($pdo);
        $hh = (int)$pdo->query("SELECT COUNT(*) FROM households")->fetchColumn();
        echo json_encode(['ok'=>true,'households'=>$hh]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
}
