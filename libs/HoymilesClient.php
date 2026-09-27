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
        $modes = $this->authMode === 'auto'
            ? ['web_v3', 'installer_v3', 'home_v3', 'legacy_v0']
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
     * Aktueller Wert je PV-Eingang aus dem Tagesverlauf (5-Minuten-Raster).
     * @return array<int,array> port => ['MODULE_POWER'=>W,'MODULE_V'=>V,'MODULE_I'=>A,'slot'=>'HH:MM']
     */
    public function getModuleValues(int $sid, int $microId, array $ports, int $maxAgeMinutes): array
    {
        $result = [];
        foreach ($ports as $port) {
            $raw = $this->callRaw('/pvm-data/api/0/module/data/count_by_day', [
                'sid'     => $sid,
                'date'    => date('Y-m-d'),
                'mi_list' => [['id' => $microId, 'port' => (int) $port]],
                'quota'   => ['MODULE_POWER', 'MODULE_V', 'MODULE_I'],
            ]);
            $result[(int) $port] = self::latestModuleValues(self::decodeLineChart($raw), $maxAgeMinutes);
        }
        return $result;
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
        $out = ['MODULE_POWER' => null, 'MODULE_V' => null, 'MODULE_I' => null, 'slot' => null];
        foreach ($chart['series'] as $s) {
            $type = $s['type'];
            if (!array_key_exists($type, $digits) || !$s['data']) {
                continue;
            }
            $idx = count($s['data']) - 1;
            $label = $chart['x_axis'][$idx] ?? (end($chart['x_axis']) ?: null);
            $out['slot'] = $label;
            $fresh = true;
            if ($label && preg_match('/^(\d{1,2}):(\d{2})$/', $label, $m)) {
                $slotTs = mktime((int) $m[1], (int) $m[2], 0);
                $fresh = $slotTs > time() || (time() - $slotTs) <= $maxAgeMinutes * 60;
            }
            $out[$type] = $fresh ? round((float) end($s['data']), $digits[$type]) : 0.0;
        }
        return $out;
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
