<?php

declare(strict_types=1);

/*
 * Hoymiles S-Miles Cloud Client (ohne Symcon-Abhängigkeiten)
 *
 * Portierung des API-Teils von github.com/Philra94/homeassistant-hoymiles-cloud
 * (MIT-Lizenz, Copyright (c) 2025 Philra94). Endpunkte und Login-Ablauf sind
 * dort per Beobachtung der Hoymiles-Apps ermittelt, keine offizielle API.
 */

class HoymilesException extends Exception
{
}

class HoymilesAuthException extends HoymilesException
{
}

class HoymilesClient
{
    public const AUTH_MODES = ['auto', 'web_v3', 'installer_v3', 'home_v3', 'legacy_v0'];

    private const BASE_NE = 'https://neapi.hoymiles.com';
    private const BASE_EU = 'https://euapi.hoymiles.com';

    // mode => [profile, auth base url, user agent, app version]
    private const PROFILES = [
        'web_v3'       => ['web', self::BASE_NE, 'IPSymcon-HoymilesCloud', null],
        'installer_v3' => ['installer', self::BASE_NE, 'S-Miles Installer', '3.7.1'],
        'home_v3'      => ['home', self::BASE_EU, 'sma/ad', '2.10.0'],
    ];

    private const TOKEN_LIFETIME = 6900; // Token gilt laut Cloud ca. 7200 s

    /** Nur für automatisierte Tests: ersetzt https://…hoymiles.com durch einen lokalen Testserver. */
    public static $baseUrlOverride = null;

    private $user;
    private $password;
    private $authMode;
    private $debug;

    /** @var array{token:string,expires:int,mode:string} */
    private $state;

    /**
     * @param array    $state letzter Token-Zustand ['token','expires','mode']
     * @param callable $debug fn(string $title, string $message)
     */
    public function __construct(string $user, string $password, string $authMode = 'auto', array $state = [], ?callable $debug = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->authMode = in_array($authMode, self::AUTH_MODES, true) ? $authMode : 'auto';
        $this->debug = $debug;
        $this->state = [
            'token'   => (string) ($state['token'] ?? ''),
            'expires' => (int) ($state['expires'] ?? 0),
            'mode'    => (string) ($state['mode'] ?? ''),
        ];
        if ($this->state['expires'] < time()) {
            $this->state['token'] = '';
        }
    }

    /** Aktueller Token-Zustand zum Speichern (z. B. in Attributen). */
    public function getState(): array
    {
        return $this->state;
    }

    // ------------------------------------------------------------------ Login

    public function login(): string
    {
        if ($this->user === '' || $this->password === '') {
            throw new HoymilesAuthException('Benutzername oder Passwort fehlt');
        }
        // "auto" probiert nur die sicheren v3-Varianten (Argon2id bzw. SHA-256 mit Einmal-Code).
        // Legacy (unsalzenes MD5) wird nur verwendet, wenn es ausdrücklich gewählt ist.
        $modes = $this->authMode === 'auto'
            ? ['web_v3', 'installer_v3', 'home_v3']
            : [$this->authMode];
        if ($this->authMode === 'auto' && in_array($this->state['mode'], $modes, true)) {
            // zuletzt erfolgreiche Variante zuerst probieren
            $modes = array_values(array_unique(array_merge([$this->state['mode']], $modes)));
        }

        $errors = [];
        foreach ($modes as $mode) {
            try {
                $token = $mode === 'legacy_v0' ? $this->loginLegacy() : $this->loginV3($mode);
                $this->state = ['token' => $token, 'expires' => time() + self::TOKEN_LIFETIME, 'mode' => $mode];
                $this->log('Login', "OK mit $mode");
                return $token;
            } catch (HoymilesAuthException $e) {
                $errors[] = "$mode: " . $e->getMessage();
                $this->log('Login', "$mode fehlgeschlagen: " . $e->getMessage());
            }
        }
        throw new HoymilesAuthException('Login fehlgeschlagen – ' . implode(' | ', $errors));
    }

    public function getAuthMode(): string
    {
        return $this->state['mode'];
    }

    private function loginV3(string $mode): string
    {
        $base = self::PROFILES[$mode][1];
        $headers = $this->headers($mode);

        $pre = $this->preInspect($base, $headers);
        $salt = $pre['a'] ?? null;

        if ($salt) {
            if (!function_exists('sodium_crypto_pwhash')) {
                throw new HoymilesAuthException('PHP-Erweiterung "sodium" fehlt (für Argon2id nötig)');
            }
            $saltBin = self::decodeSalt((string) $salt);
            if (strlen($saltBin) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
                throw new HoymilesAuthException('unerwartete Salt-Länge ' . strlen($saltBin));
            }
            // Argon2id: time_cost 3, memory 32 MiB, parallelism 1, 32 Byte
            $ch = bin2hex(sodium_crypto_pwhash(
                32,
                $this->password,
                $saltBin,
                3,
                32768 * 1024,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            ));
            return $this->loginV3Candidate($base, $headers, $ch, (string) $pre['n']);
        }

        // Konten ohne Salt: zwei beobachtete Hash-Varianten
        $sha = hash('sha256', $this->password, true);
        $candidates = [md5($this->password) . '.' . base64_encode($sha), bin2hex($sha)];
        $last = null;
        foreach ($candidates as $i => $ch) {
            if ($i > 0) {
                $pre = $this->preInspect($base, $headers); // Nonce ist einmalig
            }
            try {
                return $this->loginV3Candidate($base, $headers, $ch, (string) $pre['n']);
            } catch (HoymilesAuthException $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    private function preInspect(string $base, array $headers): array
    {
        $resp = $this->httpJson($base . '/iam/pub/3/auth/pre-insp', ['u' => $this->user], $headers);
        if (isset($resp['status']) || isset($resp['data'])) {
            if (isset($resp['status']) && (string) $resp['status'] !== '0') {
                throw new HoymilesAuthException((string) ($resp['message'] ?? $resp['status']));
            }
            $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        } else {
            $data = $resp; // manche Antworten liefern die Felder direkt
        }
        if (empty($data['n'])) {
            throw new HoymilesAuthException('pre-insp ohne Nonce');
        }
        return $data;
    }

    private function loginV3Candidate(string $base, array $headers, string $ch, string $nonce): string
    {
        $resp = $this->httpJson(
            $base . '/iam/pub/3/auth/login',
            ['u' => $this->user, 'ch' => $ch, 'n' => $nonce],
            $headers
        );
        if ((string) ($resp['status'] ?? '') === '0' && !empty($resp['data']['token'])) {
            return (string) $resp['data']['token'];
        }
        throw new HoymilesAuthException(($resp['status'] ?? '?') . ' ' . ($resp['message'] ?? 'ohne Meldung'));
    }

    private function loginLegacy(): string
    {
        $resp = $this->httpJson(
            self::BASE_NE . '/iam/pub/0/auth/login',
            ['user_name' => $this->user, 'password' => md5($this->password)],
            $this->headers('web_v3')
        );
        if ((string) ($resp['status'] ?? '') === '0' && !empty($resp['data']['token'])) {
            return (string) $resp['data']['token'];
        }
        throw new HoymilesAuthException(($resp['status'] ?? '?') . ' ' . ($resp['message'] ?? 'ohne Meldung'));
    }

    private static function decodeSalt(string $salt): string
    {
        $salt = trim($salt);
        if (strlen($salt) % 2 === 0 && ctype_xdigit($salt)) {
            return (string) hex2bin($salt);
        }
        $b64 = base64_decode($salt, true);
        return $b64 !== false ? $b64 : $salt;
    }

    private function headers(string $mode): array
    {
        $h = ['Content-Type: application/json', 'Accept: application/json'];
        if (!isset(self::PROFILES[$mode])) {
            $mode = 'web_v3';
        }
        [$profile, , $ua, $ver] = self::PROFILES[$mode];
        if ($profile === 'home') {
            // S-Miles-Home-Konten werden nur mit dem echten App-User-Agent akzeptiert
            $h[] = "User-Agent: $ua/$ver/159/0";
        } elseif ($ver) {
            $h[] = "User-Agent: $ua/$ver";
            $h[] = "App-Version: $ver";
            $h[] = "X-App-Version: $ver";
            $h[] = 'X-Client-Type: mobile';
        } else {
            $h[] = "User-Agent: $ua";
        }
        return $h;
    }

    private function authHeaders(): array
    {
        if ($this->state['token'] === '') {
            $this->login();
        }
        $h = $this->headers($this->state['mode'] ?: 'web_v3');
        $h[] = 'Authorization: ' . $this->state['token']; // roher Token, kein "Bearer"
        return $h;
    }

    // ------------------------------------------------------------ Datenabruf

    /** Authentifizierter POST mit JSON-Antwort; bei abgelaufenem Token einmal neu einloggen. */
    public function call(string $path, array $payload): array
    {
        for ($try = 0; $try < 2; $try++) {
            $http = 0;
            $resp = $this->httpJson(self::BASE_NE . $path, $payload, $this->authHeaders(), $http);
            if ((string) ($resp['status'] ?? '') === '0') {
                return is_array($resp['data'] ?? null) ? $resp['data'] : [];
            }
            if ($try === 0 && self::looksLikeAuthError($http, $resp)) {
                $this->state['token'] = '';
                continue;
            }
            throw new HoymilesException("$path: " . ($resp['status'] ?? '?') . ' ' . ($resp['message'] ?? ''));
        }
        return [];
    }

    /** Wie call(), liefert "data" aber unverändert (auch Text oder Zahl). */
    public function callValue(string $path, array $payload)
    {
        for ($try = 0; $try < 2; $try++) {
            $http = 0;
            $resp = $this->httpJson(self::BASE_NE . $path, $payload, $this->authHeaders(), $http);
            if ((string) ($resp['status'] ?? '') === '0') {
                return $resp['data'] ?? null;
            }
            if ($try === 0 && self::looksLikeAuthError($http, $resp)) {
                $this->state['token'] = '';
                continue;
            }
            throw new HoymilesException("$path: " . ($resp['status'] ?? '?') . ' ' . ($resp['message'] ?? ''));
        }
        return null;
    }

    /** Wie call(), für Endpunkte, deren "data" eine Liste ist. */
    public function callList(string $path, array $payload): array
    {
        return array_values($this->call($path, $payload));
    }

    /** Wie call(), aber mit binärer (Protobuf-)Antwort. */
    public function callRaw(string $path, array $payload): string
    {
        for ($try = 0; $try < 2; $try++) {
            $http = 0;
            $body = $this->http(self::BASE_NE . $path, $payload, $this->authHeaders(), $http);
            if ($http === 200 && ($body === '' || $body[0] !== '{')) {
                return $body;
            }
            $resp = json_decode($body, true) ?: [];
            if ($try === 0 && self::looksLikeAuthError($http, $resp)) {
                $this->state['token'] = '';
                continue;
            }
            throw new HoymilesException("$path: HTTP $http " . ($resp['message'] ?? substr($body, 0, 200)));
        }
        return '';
    }

    /** @return array<int,array> station id => station */
    public function getStations(): array
    {
        $stations = [];
        for ($page = 1; $page < 20; $page++) {
            $data = $this->call('/pvm/api/0/station/select_by_page', ['page_size' => 100, 'page_num' => $page]);
            $list = $data['list'] ?? [];
            foreach ($list as $s) {
                $stations[(int) $s['id']] = $s;
            }
            if (!$list || count($stations) >= (int) ($data['total'] ?? 0) || count($list) < 100) {
                break;
            }
        }
        return $stations;
    }

    public function getRealTimeData(int $sid): array
    {
        return $this->call('/pvm-data/api/0/station/data/count_station_real_data', ['sid' => $sid]);
    }

    public function getPvIndicators(int $sid): array
    {
        return $this->call('/pvm-data/api/0/indicators/data/select_real_indicators_data', ['sid' => $sid, 'type' => 4]);
    }

    public function getMicroinverters(int $sid): array
    {
        $data = $this->call('/pvm/api/0/dev/micro/select_by_station', ['sid' => $sid, 'page_size' => 1000, 'page_num' => 1, 'show_warn' => 0]);
        return $data['list'] ?? [];
    }

    public function getMicroDetail(int $sid, int $id): array
    {
        return $this->call('/pvm/api/0/dev/micro/find', ['id' => $id, 'sid' => $sid]);
    }

    /**
     * Tagesverlauf (5-Minuten-Raster) je PV-Eingang eines Wechselrichters.
     * Versucht zuerst eine gemeinsame Anfrage für alle Eingänge; liefert die Cloud
     * nicht für jeden Eingang eine eigene Kurve, wird je Eingang einzeln gefragt.
     * @return array<int,array> port => ['x_axis'=>[...], 'series'=>[...]]
     */
    public function getModuleCharts(int $sid, int $microId, array $ports, string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        $ports = array_values(array_unique(array_map('intval', $ports)));
        $request = function (array $p) use ($sid, $microId, $date): array {
            $raw = $this->callRaw('/pvm-data/api/0/module/data/count_by_day', [
                'sid'     => $sid,
                'date'    => $date,
                'mi_list' => array_map(static function (int $port) use ($microId): array {
                    return ['id' => $microId, 'port' => $port];
                }, $p),
                'quota'   => ['MODULE_POWER', 'MODULE_V', 'MODULE_I'],
            ]);
            return self::decodeLineChart($raw);
        };

        if (count($ports) > 1) {
            $chart = $request($ports);
            $byPort = [];
            foreach ($chart['series'] as $series) {
                if ($series['port'] !== null) {
                    $byPort[(int) $series['port']][] = $series;
                }
            }
            $complete = true;
            foreach ($ports as $port) {
                $types = array_column($byPort[$port] ?? [], 'type');
                if (!in_array('MODULE_POWER', $types, true)) {
                    $complete = false;
                }
            }
            if ($complete) {
                $result = [];
                foreach ($ports as $port) {
                    $result[$port] = ['x_axis' => $chart['x_axis'], 'series' => $byPort[$port]];
                }
                return $result;
            }
            $this->log('Tagesverlauf', 'gemeinsame Anfrage unvollständig – frage Eingänge einzeln ab');
        }

        $result = [];
        foreach ($ports as $port) {
            $result[$port] = $request([$port]);
        }
        return $result;
    }

    /** Geräte-Baum der Anlage (DTU mit Wechselrichtern, inkl. Firmware-Ständen). */
    public function getDeviceTree(int $sid): array
    {
        if ($this->state['mode'] === 'home_v3') {
            $data = $this->callList('/pvmc/api/0/station/select_device_c', ['sid' => $sid]);
        } else {
            $data = $this->callList('/pvm/api/0/station/select_device_of_tree', ['id' => $sid]);
        }
        return $data;
    }

    /** Firmware-Vergleich für eine DTU: ['upgrade' => 0|1, 'list' => [...]] */
    public function getFirmwareStatus(int $sid, string $dtuSn): array
    {
        $path = $this->state['mode'] === 'home_v3' ? '/pvmc/api/0/station/upgrade_compare_c' : '/pvm/api/0/upgrade/compare';
        return $this->call($path, ['sid' => $sid, 'dtu_sn' => $dtuSn]);
    }

    /**
     * Steuerbefehl an Wechselrichter oder DTU senden (wie in der S-Miles-Weboberfläche).
     * Liefert die Auftragsnummer; das Ergebnis wird mit commandStatus() abgefragt.
     */
    public function sendCommand(int $action, string $devSn, int $devType, string $dtuSn): string
    {
        $data = $this->callValue('/pvm-ctl/api/0/dev/command/put', [
            'action'   => $action,
            'dev_sn'   => $devSn,
            'dev_type' => $devType,
            'dtu_sn'   => $dtuSn,
            'data'     => new stdClass(),
        ]);
        if (!is_scalar($data) || (string) $data === '') {
            throw new HoymilesException('Befehl wurde von der Cloud nicht angenommen');
        }
        return (string) $data;
    }

    /** Stand eines Steuerbefehls: 2 = läuft noch, 0 = erfolgreich, sonst Fehlercode. */
    public function commandStatus(string $taskId): int
    {
        $data = $this->call('/pvm-ctl/api/0/dev/command/put_status', ['id' => $taskId]);
        return (int) ($data['code'] ?? -1);
    }

    // --------------------------------------------------- Protobuf / Auswertung

    public static function decodeLineChart(string $raw): array
    {
        $chart = ['x_axis' => [], 'series' => []];
        $pos = 0;
        $len = strlen($raw);
        while ($pos < $len) {
            [$field, $value] = self::pbField($raw, $pos);
            if ($field === 1) {
                $chart['x_axis'][] = $value;
            } elseif ($field === 2) {
                $series = ['type' => '', 'data' => [], 'port' => null];
                $p = 0;
                $l = strlen($value);
                while ($p < $l) {
                    [$f, $v] = self::pbField($value, $p);
                    if ($f === 1) {
                        $series['type'] = $v;
                    } elseif ($f === 2) {
                        $series['data'] = strlen($v) >= 4 ? array_values(unpack('g*', $v)) : [];
                    } elseif ($f === 4) {
                        $series['port'] = $v;
                    }
                }
                $chart['series'][] = $series;
            }
        }
        return $chart;
    }

    /** Letzter Wert je Größe; ist der letzte Slot älter als $maxAgeMinutes, wird 0 geliefert. */
    public static function latestModuleValues(array $chart, int $maxAgeMinutes): array
    {
        $digits = ['MODULE_POWER' => 1, 'MODULE_V' => 1, 'MODULE_I' => 2];
        $out = ['MODULE_POWER' => null, 'MODULE_V' => null, 'MODULE_I' => null, 'slot' => null, 'fresh' => false];
        foreach ($chart['series'] as $s) {
            $type = $s['type'];
            if (!array_key_exists($type, $digits) || !$s['data']) {
                continue;
            }
            $idx = count($s['data']) - 1;
            $label = $chart['x_axis'][$idx] ?? (end($chart['x_axis']) ?: null);
            $out['slot'] = $label;
            $fresh = true;
            $minute = self::labelMinute($label);
            if ($minute !== null) {
                $slotTs = mktime(intdiv($minute, 60), $minute % 60, 0);
                $fresh = $slotTs > time() || (time() - $slotTs) <= $maxAgeMinutes * 60;
            }
            $out[$type] = $fresh ? round((float) end($s['data']), $digits[$type]) : 0.0;
            if ($type === 'MODULE_POWER') {
                $out['fresh'] = $fresh;
            }
        }
        return $out;
    }

    /**
     * Der erste Wert eines Tagesverlaufs (Mitternacht) ist manchmal ein Überbleibsel vom Vortag:
     * ein einzelner hoher Wert, gefolgt von Nullen bis zum Sonnenaufgang. Er wird ignoriert.
     * Echte Leistung um Mitternacht (z. B. aus einem Speicher) hat auch danach Werte > 0.
     */
    public static function withoutCarryOver(array $data): array
    {
        $keys = array_keys($data);
        if (count($keys) >= 2) {
            $first = (float) $data[$keys[0]];
            $next = (float) $data[$keys[1]];
            if ($first > 0 && (!is_finite($next) || $next <= 0)) {
                $data[$keys[0]] = 0.0;
            }
        }
        return $data;
    }

    /** Energie eines Tagesverlaufs in Wh (Summe der Leistungswerte × Rasterlänge). */
    public static function chartEnergyWh(array $chart): float
    {
        foreach ($chart['series'] as $s) {
            if ($s['type'] === 'MODULE_POWER' && $s['data']) {
                $minutes = self::slotMinutes($chart['x_axis']);
                $sum = 0.0;
                foreach (self::withoutCarryOver($s['data']) as $w) {
                    if (is_finite((float) $w) && $w > 0) {
                        $sum += (float) $w;
                    }
                }
                return $sum * $minutes / 60;
            }
        }
        return 0.0;
    }

    /**
     * Leistungswerte eines Tagesverlaufs mit Zeitstempel (für Archiv und Kachel).
     * Die Uhrzeit kommt aus den Beschriftungen der Zeitachse. Fehlen diese, wird bei
     * bekanntem Zeitpunkt des letzten Werts ($lastTs) im 5-Minuten-Raster zurückgerechnet,
     * bei einem vollständigen Tag (288 Werte) ab Mitternacht.
     * @return array<int,array{TimeStamp:int,Value:float}>
     */
    public static function chartPowerSamples(array $chart, string $date, ?int $lastTs = null): array
    {
        $out = [];
        $dayStart = (int) strtotime("$date 00:00:00");
        foreach ($chart['series'] as $s) {
            if ($s['type'] !== 'MODULE_POWER') {
                continue;
            }
            $count = count($s['data']);
            $labelled = false;
            foreach ($chart['x_axis'] as $label) {
                if (self::labelMinute($label) !== null) {
                    $labelled = true;
                    break;
                }
            }
            foreach (self::withoutCarryOver($s['data']) as $idx => $w) {
                if (!is_finite((float) $w)) {
                    continue;
                }
                if ($labelled) {
                    $minute = self::labelMinute($chart['x_axis'][$idx] ?? null);
                    if ($minute === null) {
                        continue;
                    }
                    $ts = $dayStart + $minute * 60;
                } elseif ($lastTs) {
                    $ts = $lastTs - ($count - 1 - $idx) * 300;
                } elseif ($count === 288) {
                    $ts = $dayStart + $idx * 300;
                } else {
                    return [];
                }
                $out[] = ['TimeStamp' => $ts, 'Value' => round(max(0.0, (float) $w), 1)];
            }
            break;
        }
        return $out;
    }

    /**
     * Minute des Tages aus einer Achsenbeschriftung: "06:05", "06:05:00",
     * "2026-09-27 06:05[:00]" oder Unix-Zeit (Sekunden bzw. Millisekunden).
     */
    public static function labelMinute($label): ?int
    {
        if (is_int($label) || is_float($label) || (is_string($label) && ctype_digit(trim($label)))) {
            $ts = (int) $label;
            if ($ts > 100000000000) {
                $ts = intdiv($ts, 1000);
            }
            return $ts > 1000000000 ? (int) date('G', $ts) * 60 + (int) date('i', $ts) : null;
        }
        if (is_string($label) && preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $label, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            return $h <= 23 && $i <= 59 ? $h * 60 + $i : null;
        }
        return null;
    }

    /** Rasterlänge des Verlaufs in Minuten (aus den Uhrzeit-Beschriftungen, Standard 5). */
    private static function slotMinutes(array $xAxis): float
    {
        $times = [];
        foreach ($xAxis as $label) {
            $minute = self::labelMinute($label);
            if ($minute !== null) {
                $times[] = $minute;
            }
        }
        if (count($times) < 2) {
            return 5.0;
        }
        $diffs = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            if ($times[$i] > $times[$i - 1]) {
                $diffs[] = $times[$i] - $times[$i - 1];
            }
        }
        if (!$diffs) {
            return 5.0;
        }
        sort($diffs);
        return (float) $diffs[intdiv(count($diffs), 2)]; // Median
    }

    /** Liste [{key, val}] in key => val umwandeln. */
    public static function indicatorMap(array $indicators): array
    {
        $map = [];
        foreach ($indicators['list'] ?? [] as $item) {
            if (isset($item['key'])) {
                $map[$item['key']] = $item['val'] ?? null;
            }
        }
        return $map;
    }

    public static function isPlaceholder($v): bool
    {
        return $v === null || $v === '' || $v === '-' || (is_string($v) && trim($v) === '');
    }

    private static function pbVarint(string $buf, int &$pos): int
    {
        $value = 0;
        $shift = 0;
        while (true) {
            if ($pos >= strlen($buf)) {
                throw new HoymilesException('Protobuf: Varint abgeschnitten');
            }
            $b = ord($buf[$pos++]);
            $value |= ($b & 0x7F) << $shift;
            if (!($b & 0x80)) {
                return $value;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new HoymilesException('Protobuf: Varint zu lang');
            }
        }
    }

    private static function pbField(string $buf, int &$pos): array
    {
        $tag = self::pbVarint($buf, $pos);
        $field = $tag >> 3;
        switch ($tag & 7) {
            case 0:
                return [$field, self::pbVarint($buf, $pos)];
            case 1:
                $v = substr($buf, $pos, 8);
                $pos += 8;
                return [$field, $v];
            case 2:
                $l = self::pbVarint($buf, $pos);
                if ($pos + $l > strlen($buf)) {
                    throw new HoymilesException('Protobuf: Feld abgeschnitten');
                }
                $v = substr($buf, $pos, $l);
                $pos += $l;
                return [$field, $v];
            case 5:
                $v = substr($buf, $pos, 4);
                $pos += 4;
                return [$field, $v];
        }
        throw new HoymilesException('Protobuf: unbekannter Wire-Type ' . ($tag & 7));
    }

    // --------------------------------------------------------------- HTTP

    private static function looksLikeAuthError(int $http, array $resp): bool
    {
        if ($http === 401 || $http === 403) {
            return true;
        }
        $msg = strtolower((string) ($resp['message'] ?? ''));
        return in_array((string) ($resp['status'] ?? ''), ['100', '101', '401'], true)
            || strpos($msg, 'token') !== false || strpos($msg, 'login') !== false;
    }

    private function httpJson(string $url, array $payload, array $headers, int &$http = 0): array
    {
        $body = $this->http($url, $payload, $headers, $http);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new HoymilesException("Ungültige Antwort (HTTP $http): " . substr($body, 0, 200));
        }
        return $json;
    }

    private function http(string $url, array $payload, array $headers, int &$http = 0): string
    {
        if (self::$baseUrlOverride !== null) {
            $url = (string) preg_replace('#^https://[a-z]+\.hoymiles\.com#', self::$baseUrlOverride, $url);
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $isAuth = strpos($url, '/auth/') !== false;
        $this->log('Request', "POST $url " . ($isAuth ? '[Login-Daten ausgeblendet]' : $json));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new HoymilesException("Verbindungsfehler: $err");
        }
        $body = (string) $body;
        $shown = ($body !== '' && $body[0] !== '{') ? '[binär, ' . strlen($body) . ' Byte]' : substr($body, 0, 2000);
        if ($isAuth) {
            $shown = preg_replace('/"token"\s*:\s*"[^"]+"/', '"token":"***"', $shown);
        }
        $this->log('Response', "HTTP $http $shown");
        return $body;
    }

    private function log(string $title, string $message): void
    {
        if ($this->debug) {
            ($this->debug)($title, $message);
        }
    }
}
