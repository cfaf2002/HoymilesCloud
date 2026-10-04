<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: MIT
 * Copyright (c) 2026 Armin Frohwerk
 */

/*
 * Nachgebaute Hoymiles-Cloud für die automatisierten Tests (php -S … server.php).
 * Das Verhalten lässt sich über Dateien im Verzeichnis HOYMILES_FAKE_STATE steuern:
 *   offset     – Alter von data_time in Sekunden (Standard 120)
 *   nocombine  – vorhanden: gemeinsame Tagesverlauf-Anfragen liefern keine Port-Zuordnung
 *   upgrade    – vorhanden: Firmware-Update verfügbar
 *   cmdresult  – Ergebniscode für Steuerbefehle (Standard 0 = erfolgreich)
 * Jede Anfrage wird in requests.log protokolliert.
 */

$state = getenv('HOYMILES_FAKE_STATE') ?: sys_get_temp_dir() . '/hoymiles-fake';
@mkdir($state, 0777, true);
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$headers = function_exists('getallheaders') ? getallheaders() : [];
$ua = $headers['User-Agent'] ?? '';
$auth = $headers['Authorization'] ?? '';
file_put_contents("$state/requests.log", $path . ' ' . json_encode($in) . "\n", FILE_APPEND);

const SALT = '00112233445566778899aabbccddeeff';
const PASSWORD = 'Geheim123!';

function out(array $d): void
{
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}
function ok($d): void
{
    out(['status' => '0', 'message' => 'success', 'data' => $d]);
}
function varint(int $n): string
{
    $o = '';
    while ($n > 0x7f) {
        $o .= chr(($n & 0x7f) | 0x80);
        $n >>= 7;
    }
    return $o . chr($n);
}
function ld(int $f, string $b): string
{
    return varint(($f << 3) | 2) . varint(strlen($b)) . $b;
}

if ($path === '/iam/pub/3/auth/pre-insp') {
    ok(['a' => SALT, 'n' => 'nonce123', 'u' => 1]);
}
if ($path === '/iam/pub/3/auth/login') {
    if (strpos($ua, 'S-Miles Installer') === false) {
        out(['status' => '1', 'message' => 'The account can only be used in installer app']);
    }
    $expected = bin2hex(sodium_crypto_pwhash(32, PASSWORD, hex2bin(SALT), 3, 32768 * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13));
    if (($in['ch'] ?? '') === $expected && ($in['n'] ?? '') === 'nonce123') {
        ok(['token' => 'TOK1']);
    }
    out(['status' => '7', 'message' => 'Log in failed']);
}
if ($auth !== 'TOK1') {
    out(['status' => '100', 'message' => 'token invalid']);
}

$offset = is_file("$state/offset") ? (int) file_get_contents("$state/offset") : 120;

switch ($path) {
    case '/pvm/api/0/station/select_by_page':
        ok(['total' => 1, 'list' => [['id' => 4711, 'name' => 'Dach']]]);
        // no break
    case '/pvm/api/0/dev/micro/select_by_station':
        ok(['total' => 1, 'list' => [['id' => 999, 'sn' => '1161A0000001', 'init_hard_no' => 'HMS-1800-4T']]]);
        // no break
    case '/pvm/api/0/dev/micro/find':
        ok(['id' => 999, 'init_hard_no' => 'HMS-1800-4T', 'rule' => ['dev_type' => 3, 'port' => 4]]);
        // no break
    case '/pvm-data/api/0/station/data/count_station_real_data':
        ok([
            'real_power' => '812.4', 'today_eq' => '3456', 'month_eq' => '98765', 'year_eq' => '1234567',
            'total_eq' => '4567890', 'co2_emission_reduction' => '2345678', 'plant_tree' => '3',
            'data_time' => date('Y-m-d H:i:s', time() - $offset),
        ]);
        // no break
    case '/pvm-data/api/0/indicators/data/select_real_indicators_data':
        ok(['list' => [
            ['key' => 'pv_p_total', 'val' => 812.4],
            ['key' => '1_pv_v', 'val' => 35.1], ['key' => '1_pv_i', 'val' => 6.2], ['key' => '1_pv_p', 'val' => 217.6],
            ['key' => '2_pv_v', 'val' => '-'], ['key' => '2_pv_i', 'val' => '-'], ['key' => '2_pv_p', 'val' => '-'],
        ]]);
        // no break
    case '/pvm/api/0/station/select_device_of_tree':
        ok([['sn' => 'DTU0001', 'dev_type' => 1, 'soft_ver' => 'V01.01.10', 'hard_ver' => 'H00.04', 'children' => [
            ['sn' => '1161A0000001', 'dev_type' => 3, 'soft_ver' => 'V01.00.20', 'hard_ver' => 'H00.01'],
        ]]]);
        // no break
    case '/pvm/api/0/upgrade/compare':
        ok(['upgrade' => is_file("$state/upgrade") ? 1 : 0, 'done' => 0, 'tid' => 'x']);
        // no break
    case '/pvm-ctl/api/0/dev/command/put':
        @unlink("$state/cmdpolls");
        ok('task-' . ($in['action'] ?? 0));
        // no break
    case '/pvm-ctl/api/0/dev/command/put_status':
        $polls = (int) @file_get_contents("$state/cmdpolls") + 1;
        file_put_contents("$state/cmdpolls", (string) $polls);
        // erste Abfrage: DTU arbeitet noch (2), danach Ergebnis aus Datei "cmdresult" (Standard 0 = OK)
        ok(['code' => $polls < 2 ? 2 : (is_file("$state/cmdresult") ? (int) file_get_contents("$state/cmdresult") : 0)]);
        // no break
    case '/pvm-data/api/0/module/data/count_by_day':
        $date = $in['date'] ?? date('Y-m-d');
        $end = $date === date('Y-m-d') ? time() - 300 : strtotime("$date 20:00");
        $labels = [];
        for ($t = strtotime($date === date('Y-m-d') ? "$date 00:00" : "$date 06:00"); $t <= $end; $t += 300) {
            $labels[] = date('H:i', $t);
        }
        $msg = '';
        // Achsenformat wie von der Cloud: Standard "HH:MM", Datei "labelfmt" = "hms" ("HH:MM:SS") oder "none" (keine Achse)
        $fmt = is_file("$state/labelfmt") ? trim((string) file_get_contents("$state/labelfmt")) : 'hm';
        foreach ($labels as $l) {
            if ($fmt === 'hms') {
                $msg .= ld(1, $l . ':00');
            } elseif ($fmt !== 'none') {
                $msg .= ld(1, $l);
            }
        }
        $n = count($labels);
        $combined = count($in['mi_list']) > 1;
        foreach ($in['mi_list'] as $k => $mi) {
            $port = (int) $mi['port'];
            if ($combined && is_file("$state/nocombine") && $k > 0) {
                break; // Server liefert bei Sammelanfragen nur den ersten Eingang
            }
            // Eingang 4 liefert im Test nur 10 % (für die Modulvergleich-Warnung, wenn Datei "weakpv4" existiert)
            $factor = ($port === 4 && is_file("$state/weakpv4")) ? 0.1 : 1.0;
            // Eingang 3 ohne aktuelle Werte (Verlauf endet 2 Stunden vorher), wenn Datei "stalepv3" existiert
            $count = ($port === 3 && is_file("$state/stalepv3")) ? max(1, $n - 24) : $n;
            foreach (['MODULE_POWER' => (100 + $port) * $factor, 'MODULE_V' => 30 + $port / 10, 'MODULE_I' => (3 + $port / 100) * $factor] as $q => $v) {
                $s = ld(1, $q) . ($n ? ld(2, pack('g*', ...array_fill(0, $count, $v))) : '') . varint(3 << 3) . varint((int) $mi['id']) . varint(4 << 3) . varint($port);
                $msg .= ld(2, $s);
            }
        }
        header('Content-Type: application/octet-stream');
        echo $msg;
        exit;
}
out(['status' => '404', 'message' => "unknown $path"]);
