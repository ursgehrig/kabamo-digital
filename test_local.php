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
 * test_local.php — simulate Africa's Talking calls WITHOUT the aggregator.
 * Usage:  php test_local.php
 * It POSTs increasing keypress strings to ussd.php via the built-in server,
 * OR (simpler) just include-and-call. Here we shell out to the PHP CLI server.
 *
 * Quick manual alternative (no server needed):
 *   curl -s -X POST http://localhost:8000/ussd.php \
 *        -d 'sessionId=test1&phoneNumber=+260971234567&text=1*4*3*2*2'
 * ============================================================ */

declare(strict_types=1);

$BASE = getenv('USSD_URL') ?: 'http://localhost:8000/ussd.php';

function call(string $base, string $text, string $session = 'sess-test', string $phone = '+260971234567'): string {
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'sessionId'   => $session,
            'serviceCode' => '*384#',
            'phoneNumber' => $phone,
            'text'        => $text,
        ]),
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    if ($res === false) $res = 'ERROR: ' . curl_error($ch);
    curl_close($ch);
    return (string)$res;
}

function show(string $label, string $text, string $base): void {
    echo "──────────────────────────────────────────\n";
    echo "» $label   (text='$text')\n";
    echo call($base, $text) . "\n";
}

echo "Testing against: $BASE\n";
echo "(start the server first:  php -S localhost:8000 -t .  )\n\n";

echo "=== MAIN MENU ===\n";
show('dial in', '', $BASE);

echo "\n=== CHAIN 1: REGISTER THE DEMAND (full walk) ===\n";
show('chose Register',       '1', $BASE);
show('people = 6',           '1*6', $BASE);
show('sleeping places = 4',  '1*6*4', $BASE);
show('under5 = 2',           '1*6*4*2', $BASE);
show('pregnant = one',       '1*6*4*2*2', $BASE);   // -> END, writes household

echo "\n=== CHAIN 1: validation (bad input) ===\n";
show('people = 0 (invalid)', '1*0', $BASE);

echo "\n=== CHAIN 3: SUPPLY stub ===\n";
show('chose Supply',         '3', $BASE);
show('status = problem',     '3*3', $BASE);          // -> END, writes report

echo "\n=== done. Inspect DB:  sqlite3 kabamo.db 'SELECT * FROM households; SELECT * FROM reports;' ===\n";
