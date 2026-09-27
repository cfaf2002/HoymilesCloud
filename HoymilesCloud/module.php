<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HoymilesClient.php';

class HoymilesDeviceException extends HoymilesException
{
}

class HoymilesCloud extends IPSModule
{
    private const STATUS_OK = 102;
    private const STATUS_NO_CREDENTIALS = 104;
    private const STATUS_AUTH_ERROR = 201;
    private const STATUS_NO_DEVICE = 202;
    private const STATUS_CLOUD_ERROR = 203;

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const CHANNEL_IDENT = '/^(WR\d+_)?PV\d+_(Power|Voltage|Current)$/';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('StationID', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyBoolean('Archive', true);
        $this->RegisterPropertyString('AuthMode', 'auto');
        $this->RegisterPropertyInteger('MaxAge', 20);

        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('TokenMode', '');
        $this->RegisterAttributeString('AuthHash', '');
        $this->RegisterAttributeString('Stations', '{}');   // id => Name (für das Formular)
        $this->RegisterAttributeInteger('ActiveStation', 0);
        $this->RegisterAttributeString('StationName', '');
        $this->RegisterAttributeString('Micros', '[]');
        $this->RegisterAttributeString('Channels', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'HOYM_Update($_IPS[\'TARGET\']);');

        $this->registerProfile('HOYM.Voltage', ' V', 1);
        $this->registerProfile('HOYM.Current', ' A', 2);
        $this->registerProfile('HOYM.kg', ' kg', 1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterVariableFloat('Power', 'Leistung', '~Watt', 1);
        $this->RegisterVariableFloat('EnergyToday', 'Ertrag heute', '~Electricity', 2);
        $this->RegisterVariableFloat('EnergyMonth', 'Ertrag Monat', '~Electricity', 3);
        $this->RegisterVariableFloat('EnergyYear', 'Ertrag Jahr', '~Electricity', 4);
        $this->RegisterVariableFloat('EnergyTotal', 'Ertrag gesamt', '~Electricity', 5);
        $this->RegisterVariableFloat('CO2', 'CO₂-Einsparung', 'HOYM.kg', 6);
        $this->RegisterVariableInteger('DataTime', 'Datenstand Cloud', '~UnixTimestamp', 7);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Abfrage', '~UnixTimestamp', 8);

        // Zugangsdaten geändert -> Token verwerfen
        $authHash = md5($this->ReadPropertyString('Username') . '|' . $this->ReadPropertyString('Password') . '|' . $this->ReadPropertyString('AuthMode'));
        if ($authHash !== $this->ReadAttributeString('AuthHash')) {
            $this->WriteAttributeString('AuthHash', $authHash);
            $this->WriteAttributeString('Token', '');
            $this->WriteAttributeInteger('TokenExpires', 0);
        }
        // andere Anlage gewählt -> neu einlesen
        $sid = $this->ReadPropertyInteger('StationID');
        if ($sid !== 0 && $sid !== $this->ReadAttributeInteger('ActiveStation')) {
            $this->WriteAttributeString('Channels', '[]');
        }

        $this->maintainChannelVariables($this->channels());
        $this->updateSummary();

        if (!$this->hasCredentials()) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(self::STATUS_NO_CREDENTIALS);
            return;
        }
        // Erster Abruf kurz nach dem Übernehmen, damit das Speichern nicht blockiert
        $this->SetTimerInterval('UpdateTimer', 2000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $stations = json_decode($this->ReadAttributeString('Stations'), true) ?: [];
        $selected = $this->ReadPropertyInteger('StationID');
        if ($selected !== 0 && !isset($stations[$selected])) {
            $stations[$selected] = 'Anlage ' . $selected;
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'StationID') {
                foreach ($stations as $id => $name) {
                    $element['options'][] = ['caption' => "$name ($id)", 'value' => (int) $id];
                }
            }
        }
        unset($element);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'DeviceInfo') {
                $action['caption'] = $this->deviceInfo();
            }
        }
        unset($action);

        return json_encode($form);
    }

    // ------------------------------------------------------ öffentliche Funktionen

    /** Zyklischer Abruf (Timer) – kann auch per HOYM_Update(ID) aufgerufen werden. */
    public function Update(): void
    {
        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60000);
        if (!$this->hasCredentials()) {
            $this->SetStatus(self::STATUS_NO_CREDENTIALS);
            return;
        }

        $client = $this->client();
        try {
            if (!$this->channels()) {
                $this->discoverDevices($client);
            }
            $this->fetch($client);
            $this->SetStatus(self::STATUS_OK);
        } catch (HoymilesAuthException $e) {
            $this->fail(self::STATUS_AUTH_ERROR, $e);
        } catch (HoymilesDeviceException $e) {
            $this->fail(self::STATUS_NO_DEVICE, $e);
        } catch (Throwable $e) {
            $this->fail(self::STATUS_CLOUD_ERROR, $e);
        } finally {
            $this->saveClientState($client);
            $this->SetValue('LastUpdate', time());
        }
    }

    /** Button „Verbindung testen“: frischer Login und Liste der Anlagen. */
    public function TestConnection(): void
    {
        $client = $this->client(true);
        try {
            $client->login();
            $stations = $client->getStations();
            $names = [];
            foreach ($stations as $id => $s) {
                $names[$id] = (string) ($s['name'] ?? "Anlage $id");
            }
            $this->WriteAttributeString('Stations', json_encode($names, JSON_FORCE_OBJECT));
            $this->saveClientState($client);
            $this->ReloadForm();

            $text = 'Login OK (Variante: ' . $client->getAuthMode() . ")\n\nAnlagen:\n";
            foreach ($names as $id => $name) {
                $text .= "• $name ($id)\n";
            }
            echo $text;
        } catch (Throwable $e) {
            $this->saveClientState($client);
            echo 'Fehler: ' . $e->getMessage();
        }
    }

    /** Button „Anlage neu einlesen“: Anlage, Wechselrichter und PV-Eingänge ermitteln. */
    public function Discover(): void
    {
        $client = $this->client();
        try {
            $this->discoverDevices($client);
            $this->saveClientState($client);
            $this->ReloadForm();
            echo $this->deviceInfo() . "\n\nVariablen wurden angelegt, Werte folgen mit dem nächsten Abruf.";
            $this->SetTimerInterval('UpdateTimer', 2000);
        } catch (Throwable $e) {
            $this->saveClientState($client);
            echo 'Fehler: ' . $e->getMessage();
        }
    }

    // ---------------------------------------------------------------- Abruf

    private function discoverDevices(HoymilesClient $client): void
    {
        $stations = $client->getStations();
        if (!$stations) {
            throw new HoymilesDeviceException('Keine Anlage im Konto gefunden');
        }
        $names = [];
        foreach ($stations as $id => $s) {
            $names[$id] = (string) ($s['name'] ?? "Anlage $id");
        }
        $this->WriteAttributeString('Stations', json_encode($names, JSON_FORCE_OBJECT));

        $sid = $this->ReadPropertyInteger('StationID') ?: (int) array_key_first($stations);
        if (!isset($stations[$sid])) {
            throw new HoymilesDeviceException("Anlage $sid gehört nicht zu diesem Konto");
        }

        $micros = [];
        foreach ($client->getMicroinverters($sid) as $m) {
            $mid = (int) $m['id'];
            $detail = $client->getMicroDetail($sid, $mid);
            $this->SendDebug('Wechselrichter', json_encode($detail), 0);
            $model = (string) ($detail['init_hard_no'] ?? $m['init_hard_no'] ?? '');
            $ports = (int) ($detail['rule']['port'] ?? 0);
            if ($ports < 1) {
                // Fallback über den Modellnamen, z. B. HMS-1800-4T -> 4
                $ports = preg_match('/-(\d)T/i', $model, $mm) ? (int) $mm[1] : 1;
            }
            $micros[] = ['id' => $mid, 'sn' => (string) ($m['sn'] ?? $detail['sn'] ?? ''), 'model' => $model, 'ports' => $ports];
        }
        if (!$micros) {
            throw new HoymilesDeviceException('Keine Mikrowechselrichter in der Anlage gefunden');
        }

        // Bei genau einem Wechselrichter entspricht PV-Eingang n dem Anlagen-Kanal n
        $single = count($micros) === 1;
        $channels = [];
        foreach ($micros as $k => $m) {
            for ($p = 1; $p <= $m['ports']; $p++) {
                $channels[] = [
                    'ident'   => $single ? "PV$p" : 'WR' . ($k + 1) . "_PV$p",
                    'name'    => $single ? "PV $p" : 'WR ' . ($k + 1) . " PV $p",
                    'micro'   => $m['id'],
                    'port'    => $p,
                    'channel' => $single ? $p : null,
                ];
            }
        }

        $this->WriteAttributeInteger('ActiveStation', $sid);
        $this->WriteAttributeString('StationName', $names[$sid]);
        $this->WriteAttributeString('Micros', json_encode($micros));
        $this->WriteAttributeString('Channels', json_encode($channels));
        $this->maintainChannelVariables($channels);
        $this->updateSummary();
    }

    private function fetch(HoymilesClient $client): void
    {
        $sid = $this->ReadAttributeInteger('ActiveStation');
        $maxAge = max(5, $this->ReadPropertyInteger('MaxAge'));
        $num = static function ($v): float {
            return HoymilesClient::isPlaceholder($v) ? 0.0 : (float) $v;
        };

        // Anlagen-Summenwerte
        $rt = $client->getRealTimeData($sid);
        $dataTs = !empty($rt['data_time']) ? (int) strtotime((string) $rt['data_time']) : 0;
        $fresh = $dataTs === 0 || (time() - $dataTs) <= $maxAge * 60;
        $sameDay = $dataTs === 0 || date('Y-m-d', $dataTs) === date('Y-m-d');

        $this->setIfChanged('Power', $fresh ? $num($rt['real_power'] ?? null) : 0.0);
        $this->setIfChanged('EnergyToday', $sameDay ? $num($rt['today_eq'] ?? null) / 1000 : 0.0);
        $this->setIfChanged('EnergyMonth', $num($rt['month_eq'] ?? null) / 1000);
        $this->setIfChanged('EnergyYear', $num($rt['year_eq'] ?? null) / 1000);
        $total = $num($rt['total_eq'] ?? null) / 1000;
        if ($total > 0) { // Zähler nie auf 0 zurückfallen lassen
            $this->setIfChanged('EnergyTotal', $total);
        }
        $this->setIfChanged('CO2', $num($rt['co2_emission_reduction'] ?? null) / 1000);
        if ($dataTs) {
            $this->setIfChanged('DataTime', $dataTs);
        }

        // PV-Eingänge: zuerst Anlagen-Indikatoren, fehlende aus dem Tagesverlauf
        $channels = $this->channels();
        $ind = HoymilesClient::indicatorMap($client->getPvIndicators($sid));
        $values = [];
        $missing = [];
        foreach ($channels as $c) {
            $n = $c['channel'];
            $p = $n ? ($ind["{$n}_pv_p"] ?? null) : null;
            $u = $n ? ($ind["{$n}_pv_v"] ?? null) : null;
            $i = $n ? ($ind["{$n}_pv_i"] ?? null) : null;
            if ($n && !HoymilesClient::isPlaceholder($p) && !HoymilesClient::isPlaceholder($u)) {
                $values[$c['ident']] = $fresh ? [(float) $p, (float) $u, $num($i)] : [0.0, 0.0, 0.0];
            } else {
                $missing[$c['micro']][] = $c['port'];
            }
        }
        foreach ($missing as $microId => $ports) {
            $mod = $client->getModuleValues($sid, (int) $microId, $ports, $maxAge);
            foreach ($channels as $c) {
                if ((int) $c['micro'] === (int) $microId && isset($mod[$c['port']])) {
                    $m = $mod[$c['port']];
                    $values[$c['ident']] = [(float) ($m['MODULE_POWER'] ?? 0), (float) ($m['MODULE_V'] ?? 0), (float) ($m['MODULE_I'] ?? 0)];
                }
            }
        }
        foreach ($values as $ident => [$p, $u, $i]) {
            $this->setIfChanged($ident . '_Power', $p);
            $this->setIfChanged($ident . '_Voltage', $u);
            $this->setIfChanged($ident . '_Current', $i);
        }
    }

    // -------------------------------------------------------------- Hilfen

    private function client(bool $fresh = false): HoymilesClient
    {
        $state = $fresh ? [] : [
            'token'   => $this->ReadAttributeString('Token'),
            'expires' => $this->ReadAttributeInteger('TokenExpires'),
            'mode'    => $this->ReadAttributeString('TokenMode'),
        ];
        return new HoymilesClient(
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyString('AuthMode'),
            $state,
            function (string $title, string $message): void {
                $this->SendDebug($title, $message, 0);
            }
        );
    }

    private function saveClientState(HoymilesClient $client): void
    {
        $s = $client->getState();
        if ($s['token'] !== $this->ReadAttributeString('Token')) {
            $this->WriteAttributeString('Token', $s['token']);
            $this->WriteAttributeInteger('TokenExpires', $s['expires']);
        }
        if ($s['mode'] !== '' && $s['mode'] !== $this->ReadAttributeString('TokenMode')) {
            $this->WriteAttributeString('TokenMode', $s['mode']);
        }
    }

    private function hasCredentials(): bool
    {
        return $this->ReadPropertyString('Username') !== '' && $this->ReadPropertyString('Password') !== '';
    }

    private function channels(): array
    {
        return json_decode($this->ReadAttributeString('Channels'), true) ?: [];
    }

    private function fail(int $status, Throwable $e): void
    {
        $this->SendDebug('Fehler', $e->getMessage(), 0);
        if ($this->GetStatus() !== $status) { // nur beim Wechsel ins Log, nicht alle 5 Minuten
            $this->LogMessage($e->getMessage(), KL_WARNING);
        }
        $this->SetStatus($status);
    }

    private function maintainChannelVariables(array $channels): void
    {
        $wanted = [];
        foreach ($channels as $k => $c) {
            $pos = 20 + $k * 3;
            $this->RegisterVariableFloat($c['ident'] . '_Power', $c['name'] . ' Leistung', '~Watt', $pos);
            $this->RegisterVariableFloat($c['ident'] . '_Voltage', $c['name'] . ' Spannung', 'HOYM.Voltage', $pos + 1);
            $this->RegisterVariableFloat($c['ident'] . '_Current', $c['name'] . ' Strom', 'HOYM.Current', $pos + 2);
            array_push($wanted, $c['ident'] . '_Power', $c['ident'] . '_Voltage', $c['ident'] . '_Current');
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = IPS_GetObject($child)['ObjectIdent'];
            if (preg_match(self::CHANNEL_IDENT, $ident) && !in_array($ident, $wanted, true)) {
                $this->UnregisterVariable($ident);
            }
        }

        if ($this->ReadPropertyBoolean('Archive')) {
            $log = ['Power' => 0, 'EnergyTotal' => 1]; // 1 = Zähler
            foreach ($channels as $c) {
                $log[$c['ident'] . '_Power'] = 0;
            }
            $this->enableArchive($log);
        }
    }

    private function enableArchive(array $idents): void
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (!$archives) {
            return;
        }
        $ac = $archives[0];
        $changed = false;
        foreach ($idents as $ident => $aggregation) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid && !AC_GetLoggingStatus($ac, $vid)) {
                AC_SetLoggingStatus($ac, $vid, true);
                AC_SetAggregationType($ac, $vid, $aggregation);
                if ($aggregation === 1 && function_exists('AC_SetCounterIgnoreZeros')) {
                    AC_SetCounterIgnoreZeros($ac, $vid, true);
                }
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($ac);
        }
    }

    private function deviceInfo(): string
    {
        $micros = json_decode($this->ReadAttributeString('Micros'), true) ?: [];
        if (!$micros) {
            return 'Noch keine Anlage eingelesen.';
        }
        $parts = [];
        foreach ($micros as $m) {
            $parts[] = trim($m['model'] . ' · SN ' . $m['sn'] . ' · ' . $m['ports'] . ' PV-Eingänge');
        }
        $mode = $this->ReadAttributeString('TokenMode');
        return 'Anlage: ' . $this->ReadAttributeString('StationName') . ' (' . $this->ReadAttributeInteger('ActiveStation') . ")\n"
            . 'Wechselrichter: ' . implode("\n", $parts)
            . ($mode !== '' ? "\nLogin-Variante: $mode" : '');
    }

    private function updateSummary(): void
    {
        $micros = json_decode($this->ReadAttributeString('Micros'), true) ?: [];
        $this->SetSummary(implode(', ', array_column($micros, 'model')));
    }

    private function setIfChanged(string $ident, $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /** Wird nur von den SymconStubs (automatisierte Tests) für Timer benötigt. */
    protected function getTime()
    {
        return time();
    }

    private function registerProfile(string $name, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
    }
}
