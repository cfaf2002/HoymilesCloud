<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: MIT
 * Copyright (c) 2026 Armin Frohwerk
 */

require_once __DIR__ . '/../libs/HoymilesClient.php';

class HoymilesDeviceException extends HoymilesException
{
}

class HoymilesCloud extends IPSModuleStrict
{
    private const STATUS_OK = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_NO_CREDENTIALS = 204;
    private const STATUS_AUTH_ERROR = 201;
    private const STATUS_NO_DEVICE = 202;
    private const STATUS_CLOUD_ERROR = 203;

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const LOCATION_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';

    /** Eingänge, für die beim aktuellen Abruf echte Messwerte vorlagen (nur für die Warnungen). */
    private $measured = [];
    private const TILE_VISU_GUID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';

    private const CHANNEL_IDENT = '/^(WR\d+_)?PV\d+_(Power|Voltage|Current|Energy)$/';
    private const ENERGY_CALC_INTERVAL = 900;   // Ertrag je PV-Eingang höchstens alle 15 Minuten neu berechnen
    private const FIRMWARE_CHECK_INTERVAL = 86400;
    private const BACKFILL_TICK_MS = 1500;
    private const COMMAND_POLL_MS = 2000;
    private const COMMAND_MAX_POLLS = 20;
    private const REFRESH_MIN_GAP = 30;          // Sekunden zwischen zwei Abrufen per Kachel/Skript
    private const AUTH_BACKOFF_MAX = 21600;      // nach falscher Anmeldung höchstens alle 6 Stunden erneut versuchen

    // Steuerbefehle (Codes aus der S-Miles-Weboberfläche): Name => [Aktion, Gerätetyp]
    private const COMMANDS = [
        'reboot'     => [3, 3],   // Wechselrichter neu starten
        'power_on'   => [6, 3],   // Wechselrichter einschalten
        'power_off'  => [7, 3],   // Wechselrichter ausschalten
        'dtu_reboot' => [1, 1],   // DTU neu starten
    ];

    public function Create(): void
    {
        parent::Create();

        // Zugang und Abfrage
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('StationID', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyBoolean('Archive', true);
        $this->RegisterPropertyString('AuthMode', 'auto');
        $this->RegisterPropertyInteger('MaxAge', 20);

        // Nachtmodus
        $this->RegisterPropertyBoolean('NightMode', true);
        $this->RegisterPropertyInteger('NightInterval', 30);
        $this->RegisterPropertyInteger('BrightnessVariable', 0);
        $this->RegisterPropertyFloat('BrightnessThreshold', 50.0);
        $this->RegisterPropertyBoolean('BrightnessAuto', true);

        // Was hängt an welchem PV-Eingang? (Solarmodul oder Speicher wie Zendure)
        $this->RegisterPropertyString('InputSources', '[]');
        $this->RegisterPropertyBoolean('StorageConnected', false);   // Speicher (z. B. Zendure) angeschlossen -> Speicher-Eingänge nachts erkennen

        // Störungswarnungen
        $this->RegisterPropertyBoolean('AlarmOffline', true);
        $this->RegisterPropertyInteger('AlarmOfflineMinutes', 60);
        $this->RegisterPropertyBoolean('AlarmLowPower', true);
        $this->RegisterPropertyFloat('AlarmBrightness', 10000.0);
        $this->RegisterPropertyInteger('AlarmMinPower', 50);
        $this->RegisterPropertyBoolean('AlarmModules', true);
        $this->RegisterPropertyInteger('AlarmModuleDeviation', 50);
        $this->RegisterPropertyInteger('AlarmDelay', 30);
        $this->RegisterPropertyInteger('NotifyInstance', 0);
        $this->RegisterPropertyInteger('NotifyScript', 0);

        // Ersparnis
        $this->RegisterPropertyBoolean('Savings', true);
        $this->RegisterPropertyFloat('PricePurchase', 0.30);
        $this->RegisterPropertyFloat('PriceFeedIn', 0.0);
        $this->RegisterPropertyInteger('SelfConsumption', 100);

        // Kachel
        $this->RegisterPropertyInteger('TileMaxPower', 0); // 0 = aus dem Wechselrichter-Modell
        $this->RegisterPropertyString('TileColors', 'solar');            // solar = Sonnengelb | accent = Akzentfarbe des Symcon-Designs
        $this->RegisterPropertyString('TileBackground', 'illustration'); // illustration | picture | image | none
        $this->RegisterPropertyInteger('TileImage', 0);                 // Medienobjekt (Bild)
        $this->RegisterPropertyInteger('TileImageOpacity', 30);          // %

        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('TokenMode', '');
        $this->RegisterAttributeString('AuthHash', '');
        $this->RegisterAttributeString('AuthSalt', '');
        $this->RegisterAttributeString('Stations', '{}');   // id => Name (für das Formular)
        $this->RegisterAttributeInteger('ActiveStation', 0);
        $this->RegisterAttributeString('StationName', '');
        $this->RegisterAttributeString('Micros', '[]');
        $this->RegisterAttributeString('Channels', '[]');
        $this->RegisterAttributeInteger('BrightnessRegistered', 0);
        $this->RegisterAttributeInteger('NextUpdate', 0);
        $this->RegisterAttributeInteger('AuthFailures', 0);
        $this->RegisterAttributeString('StorageDetected', '{}');      // Eingänge, die nachts Strom lieferten: Ident => Zeitpunkt
        $this->RegisterAttributeInteger('EnergyCalcAt', 0);
        $this->RegisterAttributeString('EnergyDate', '');
        $this->RegisterAttributeBoolean('WasFresh', false);
        $this->RegisterAttributeString('AlarmSince', '{}');
        $this->RegisterAttributeString('AlarmActive', '{}');
        $this->RegisterAttributeInteger('FirmwareCheckedAt', 0);
        $this->RegisterAttributeString('Backfill', '');
        $this->RegisterAttributeString('Command', '');      // laufender Steuerbefehl
        $this->RegisterAttributeString('DtuMap', '{}');     // Seriennummer Wechselrichter => DTU
        $this->RegisterAttributeString('SwitchedOff', '{}'); // per Befehl ausgeschaltete Wechselrichter
        $this->RegisterAttributeString('TodayCurve', '[]');  // Tagesverlauf für die Kachel: [[Minute, W], …]
        $this->RegisterAttributeString('AlarmConfig', '');   // Prüfwert der Warn-Einstellungen
        $this->RegisterAttributeString('BrightnessLearned', '{}'); // Datum => Helligkeit beim Produktionsstart

        $this->RegisterTimer('UpdateTimer', 0, 'HOYM_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BackfillTimer', 0, 'HOYM_BackfillStep($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CommandTimer', 0, 'HOYM_CommandStep($_IPS[\'TARGET\']);');

        // Eigene Kachel für die Kachel-Visualisierung (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        // Darstellungen (Symcon 8.0+) statt Variablenprofile; bestehende Variablen werden dabei umgestellt
        $this->RegisterVariableBoolean('Producing', $this->Translate('Producing'), $this->presentBool('Standby', 'moon', 'Producing', 'sun', 0xF2A900), 0);
        $this->RegisterVariableFloat('Power', $this->Translate('Power'), $this->presentValue(' W', 0, 'bolt'), 1);
        $this->RegisterVariableFloat('EnergyToday', $this->Translate('Yield today'), $this->presentValue(' kWh', 2, 'solar-panel'), 2);
        $this->RegisterVariableFloat('EnergyMonth', $this->Translate('Yield month'), $this->presentValue(' kWh', 1, 'solar-panel'), 3);
        $this->RegisterVariableFloat('EnergyYear', $this->Translate('Yield year'), $this->presentValue(' kWh', 0, 'solar-panel'), 4);
        $this->RegisterVariableFloat('EnergyTotal', $this->Translate('Yield total'), $this->presentValue(' kWh', 0, 'solar-panel'), 5);
        $this->RegisterVariableFloat('CO2', $this->Translate('CO₂ saved'), $this->presentValue(' kg', 1, 'leaf'), 6);
        $this->RegisterVariableInteger('DataTime', $this->Translate('Cloud data time'), $this->presentDateTime(), 7);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last query'), $this->presentDateTime(), 8);
        $this->RegisterVariableBoolean('NightActive', $this->Translate('Night mode'), $this->presentBool('Day', 'sun', 'Night', 'moon'), 9);
        $this->RegisterVariableBoolean('Alarm', $this->Translate('Fault'), $this->presentBool('none', 'circle-check', 'Fault', 'triangle-exclamation', 0xE53935, 0x2E9E44), 10);
        $this->RegisterVariableString('AlarmText', $this->Translate('Fault message'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'triangle-exclamation', 'MULTILINE' => true], 11);

        $savings = $this->ReadPropertyBoolean('Savings');
        foreach (['SavingsToday' => ['Savings today', 12], 'SavingsMonth' => ['Savings month', 13], 'SavingsYear' => ['Savings year', 14], 'SavingsTotal' => ['Savings total', 15]] as $ident => [$name, $pos]) {
            $this->MaintainVariable($ident, $this->Translate($name), VARIABLETYPE_FLOAT, $this->presentValue(' €', 2, 'euro-sign'), $pos, $savings);
        }

        $this->RegisterVariableString('FirmwareDTU', $this->Translate('Firmware DTU'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'microchip'], 16);
        $this->RegisterVariableString('FirmwareInverter', $this->Translate('Firmware inverter'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'microchip'], 17);
        $this->RegisterVariableBoolean('FirmwareUpdate', $this->Translate('Firmware update'), $this->presentBool('up to date', 'circle-check', 'update available', 'circle-info', 0x1E88E5), 18);

        $this->removeLegacyProfiles();
        $this->registerBrightnessSensor();

        // Zugangsdaten geändert -> Token verwerfen
        $authHash = $this->credentialFingerprint();
        if (!hash_equals($this->ReadAttributeString('AuthHash'), $authHash)) {
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
        $this->hideUnusedInputs();
        $this->resetAlarmsOnConfigChange();
        $this->updateSummary();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetTimerInterval('BackfillTimer', 0);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }
        if (!$this->hasCredentials()) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(self::STATUS_NO_CREDENTIALS);
            return;
        }
        $this->WriteAttributeInteger('AuthFailures', 0);
        if ($this->ReadAttributeString('Command') !== '') {
            $this->SetTimerInterval('CommandTimer', self::COMMAND_POLL_MS); // nach Neustart laufenden Befehl weiter verfolgen
        }
        // Erster Abruf kurz nach dem Übernehmen, damit das Speichern nicht blockiert
        $this->WriteAttributeInteger('EnergyCalcAt', 0); // Tagesverlauf und Ertrag je Eingang beim ersten Abruf neu berechnen
        $this->SetTimerInterval('UpdateTimer', 2000);
        $this->UpdateVisualizationValue(json_encode(['background' => $this->tileBackground()]));
        $this->pushTile();
        if ($this->ReadAttributeString('Backfill') !== '') {
            $this->SetTimerInterval('BackfillTimer', self::BACKFILL_TICK_MS);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        // Helligkeitssensor: wird es hell, sofort abfragen statt auf das Nachtintervall zu warten
        if ($Message === VM_UPDATE && $SenderID === $this->ReadAttributeInteger('BrightnessRegistered')) {
            if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('NightMode') || !$this->hasCredentials()) {
                return;
            }
            $brightness = (float) $Data[0];
            if ($brightness >= $this->brightnessThreshold() && $this->GetValue('NightActive')) {
                $this->SendDebug('Night mode', "brightness $brightness reached the threshold – day mode, querying now", 0);
                $this->SetValue('NightActive', false);
                $this->SetTimerInterval('UpdateTimer', 1000);
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $stations = json_decode($this->ReadAttributeString('Stations'), true) ?: [];
        $selected = $this->ReadPropertyInteger('StationID');
        if ($selected !== 0 && !isset($stations[$selected])) {
            $stations[$selected] = $this->Translate('Plant') . ' ' . $selected;
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'StationID') {
                foreach ($stations as $id => $name) {
                    $element['options'][] = ['caption' => "$name ($id)", 'value' => (int) $id];
                }
            }
            foreach ($element['items'] ?? [] as $k => $item) {
                if (($item['name'] ?? '') === 'LearnedInfo') {
                    $element['items'][$k]['caption'] = $this->learnedInfoText();
                }
                if (($item['name'] ?? '') === 'StorageInfo') {
                    $element['items'][$k]['caption'] = $this->storageInfoText();
                }
                if (($item['name'] ?? '') === 'InputSources') {
                    $sources = $this->configuredSources();
                    $rows = [];
                    foreach ($this->channels() as $c) {
                        $rows[] = ['Ident' => $c['ident'], 'Input' => $c['name'], 'Source' => $sources[$c['ident']] ?? 'panel'];
                    }
                    $element['items'][$k]['values'] = $rows;
                    $element['items'][$k]['rowCount'] = max(1, count($rows));
                }
            }
        }
        unset($element);

        foreach ($form['actions'] as &$action) {
            switch ($action['name'] ?? '') {
                case 'DeviceInfo':
                    $action['caption'] = $this->deviceInfo();
                    break;
                case 'Version':
                    $library = json_decode((string) file_get_contents(__DIR__ . '/../library.json'), true);
                    $action['caption'] = 'Hoymiles Cloud – ' . $this->Translate('Version') . ' ' . ($library['version'] ?? '?')
                        . ' (Build ' . ($library['build'] ?? '?') . ')';
                    break;
                case 'BackfillStatus':
                    $action['caption'] = $this->backfillStatusText();
                    break;
            }
            foreach ($action['items'] ?? [] as $k => $item) {
                if (($item['name'] ?? '') === 'CommandTarget') {
                    $options = [];
                    foreach (json_decode($this->ReadAttributeString('Micros'), true) ?: [] as $m) {
                        $options[] = ['caption' => trim($m['model'] . ' · SN ' . $m['sn']), 'value' => (string) $m['sn']];
                    }
                    $action['items'][$k]['options'] = $options ?: [['caption' => $this->Translate('No plant read in yet.'), 'value' => '']];
                    $action['items'][$k]['value'] = $options[0]['value'] ?? '';
                }
                if (($item['name'] ?? '') === 'CommandStatus') {
                    $action['items'][$k]['caption'] = $this->commandStatusText();
                }
            }
        }
        unset($action);

        return json_encode($form);
    }

    // ------------------------------------------------------ öffentliche Funktionen

    /** Zyklischer Abruf (Timer) – kann auch per HOYM_Update(ID) aufgerufen werden. */
    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }
        if (!$this->hasCredentials()) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(self::STATUS_NO_CREDENTIALS);
            return;
        }

        // Nicht zweimal gleichzeitig abfragen (Timer und Kachel), sonst überschreiben sich Token und Zwischenstände
        $lock = 'HOYM_Update_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            $this->SendDebug('Update', 'another query is still running – skipped', 0);
            return;
        }
        try {
            $this->runUpdate();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function runUpdate(): void
    {
        $client = $this->client();
        $fresh = null; // unbekannt, falls der Abruf scheitert
        try {
            if (!$this->channels()) {
                $this->discoverDevices($client);
            }
            $fresh = $this->fetch($client);
            $this->SetStatus(self::STATUS_OK);
        } catch (HoymilesAuthException $e) {
            $this->fail(self::STATUS_AUTH_ERROR, $e);
        } catch (HoymilesDeviceException $e) {
            $this->fail(self::STATUS_NO_DEVICE, $e);
        } catch (Throwable $e) {
            $this->fail(self::STATUS_CLOUD_ERROR, $e);
        } finally {
            if ($fresh !== null) {
                $this->updateSavings();
                $this->checkFirmware($client, $fresh);
            }
            $this->evaluateAlarms($fresh);
            $this->saveClientState($client);
            $this->SetValue('LastUpdate', time());
            $this->scheduleNextUpdate($fresh);
            $this->authBackoff();
            $this->pushTile();
        }
    }

    /** Aktionen aus der Kachel (HTML-SDK). */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Refresh':
                // Schutz vor Dauerfeuer aus der Kachel oder Skripten: höchstens alle 30 Sekunden
                // abfragen, nach falschem Passwort gar nicht (nur „Verbindung testen“ im Formular).
                $recent = time() - (int) $this->GetValue('LastUpdate') < self::REFRESH_MIN_GAP;
                if ($recent || $this->GetStatus() === self::STATUS_AUTH_ERROR) {
                    $this->SendDebug('Tile', $recent ? 'refresh skipped – last query less than 30 s ago' : 'refresh skipped – login failed, check the access data', 0);
                    $this->pushTile(true);
                    break;
                }
                $this->Update();
                break;
            case 'Sync': // Kachel wieder sichtbar: aktuellen Stand senden, ohne die Cloud abzufragen
                $this->pushTile();
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    /** Inhalt der Kachel für die Kachel-Visualisierung. */
    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . json_encode(json_encode(['background' => $this->tileBackground()])) . ');'
            . 'handleMessage(' . json_encode($this->tileMessage()) . ');</script>';
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
                $names[$id] = (string) ($s['name'] ?? $this->Translate('Plant') . " $id");
            }
            $this->WriteAttributeString('Stations', json_encode($names, JSON_FORCE_OBJECT));
            $this->saveClientState($client);
            if ($this->GetStatus() === self::STATUS_AUTH_ERROR && $this->ReadPropertyBoolean('Active')) {
                // Anmeldung klappt wieder: Wartezeit nach Fehlversuchen beenden, gleich abfragen
                $this->WriteAttributeInteger('AuthFailures', 0);
                $this->SetTimerInterval('UpdateTimer', 2000);
            }
            $this->ReloadForm();

            $text = sprintf($this->Translate('Login OK (variant: %s)'), $client->getAuthMode()) . "\n\n" . $this->Translate('Plants') . ":\n";
            foreach ($names as $id => $name) {
                $text .= "• $name ($id)\n";
            }
            echo $text;
        } catch (Throwable $e) {
            $this->saveClientState($client);
            echo $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /** Button „Anlage neu einlesen“: Anlage, Wechselrichter und PV-Eingänge ermitteln. */
    public function Discover(): void
    {
        $client = $this->client();
        try {
            $this->discoverDevices($client);
            $this->WriteAttributeInteger('FirmwareCheckedAt', 0); // Firmware beim nächsten Abruf neu lesen
            $this->saveClientState($client);
            $this->ReloadForm();
            echo $this->deviceInfo() . "\n\n" . $this->Translate('Variables have been created, values follow with the next query.');
            if ($this->ReadPropertyBoolean('Active')) {
                $this->SetTimerInterval('UpdateTimer', 2000);
            }
        } catch (Throwable $e) {
            $this->saveClientState($client);
            echo $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /** Button „Verlauf nachladen“: Tagesverläufe der letzten $Days Tage ins Archiv schreiben. */
    public function Backfill(int $Days): void
    {
        $Days = max(1, min(365, $Days));
        if (!$this->hasCredentials() || !$this->channels()) {
            echo $this->Translate('Please set up the instance first (credentials, "Test connection").');
            return;
        }
        if (!$this->archiveId()) {
            echo $this->Translate('No archive instance found.');
            return;
        }
        if (!$this->ReadPropertyBoolean('Archive')) {
            echo $this->Translate('Please enable "Archive" first.');
            return;
        }
        $dates = [];
        for ($d = $Days; $d >= 1; $d--) {
            $dates[] = date('Y-m-d', strtotime("-$d day")); // älteste zuerst
        }
        $this->WriteAttributeString('Backfill', json_encode([
            'total'   => $Days,
            'pending' => $dates,
            'energy'  => [],           // Datum => Wh (DC, alle Eingänge)
            'retries' => 0,
            'skipped' => [],
            'started' => time(),
        ]));
        $this->SetTimerInterval('BackfillTimer', 500);
        $this->SendDebug('Backfill', "started for $Days days", 0);
        echo sprintf($this->Translate('Loading history for %d days started. This takes about %d minutes. Progress is shown in the form.'), $Days, max(1, (int) ceil($Days * 2 / 60)));
    }

    /** Button „Nachladen abbrechen“. */
    /** Automatisch erkannte Speicher-Eingänge vergessen (z. B. nach einem Umbau). */
    public function ResetStorageDetection(): void
    {
        $this->WriteAttributeString('StorageDetected', '{}');
        $this->UpdateFormField('StorageInfo', 'caption', $this->storageInfoText());
        $this->SendDebug('Storage', 'detection reset', 0);
    }

    public function BackfillCancel(): void
    {
        $this->WriteAttributeString('Backfill', '');
        $this->SetTimerInterval('BackfillTimer', 0);
        $this->updateBackfillForm();
        echo $this->Translate('Loading history cancelled.');
    }

    /** Ein Schritt des Nachladens (Timer): ein Tag je Aufruf. */
    public function BackfillStep(): void
    {
        $job = json_decode($this->ReadAttributeString('Backfill'), true);
        if (!is_array($job)) {
            $this->SetTimerInterval('BackfillTimer', 0);
            return;
        }
        $this->SetTimerInterval('BackfillTimer', self::BACKFILL_TICK_MS);

        if ($job['pending']) {
            $date = $job['pending'][0];
            $client = $this->client();
            try {
                $job['energy'][$date] = $this->backfillDay($client, $date);
                array_shift($job['pending']);
                $job['retries'] = 0;
            } catch (Throwable $e) {
                $this->SendDebug('Backfill', "$date: " . $e->getMessage(), 0);
                if (++$job['retries'] >= 3) {
                    $job['skipped'][] = $date;
                    array_shift($job['pending']);
                    $job['retries'] = 0;
                }
            }
            $this->saveClientState($client);
            $this->WriteAttributeString('Backfill', json_encode($job));
            $this->updateBackfillForm();
            return;
        }

        // Alle Tage geladen: Zählerstände für „Ertrag gesamt“ berechnen und eintragen
        $this->SetTimerInterval('BackfillTimer', 0);
        $counterDays = $this->backfillCounter($job['energy']);
        $this->WriteAttributeString('Backfill', '');
        $msg = sprintf(
            $this->Translate('History loaded: %d days, %d counter values added, %d days skipped.'),
            count($job['energy']),
            $counterDays,
            count($job['skipped'])
        );
        $this->LogMessage($msg, KL_NOTIFY);
        $this->SendDebug('Backfill', $msg, 0);
        $this->updateBackfillForm($msg);
    }

    /**
     * Steuerbefehl senden. $Command: reboot, power_on, power_off, dtu_reboot.
     * $InverterSN: Seriennummer des Wechselrichters (leer = erster Wechselrichter).
     */
    public function SendCommand(string $Command, string $InverterSN): void
    {
        if (!isset(self::COMMANDS[$Command])) {
            echo $this->Translate('Unknown command.');
            return;
        }
        if ($this->ReadAttributeString('Command') !== '') {
            echo $this->Translate('Another command is still running. Please wait a moment.');
            return;
        }
        $micros = json_decode($this->ReadAttributeString('Micros'), true) ?: [];
        if (!$micros || !$this->hasCredentials()) {
            echo $this->Translate('Please set up the instance first (credentials, "Test connection").');
            return;
        }
        $sn = $InverterSN !== '' ? $InverterSN : (string) $micros[0]['sn'];
        if (!in_array($sn, array_column($micros, 'sn'), true)) {
            echo $this->Translate('Unknown inverter.');
            return;
        }

        $client = $this->client();
        try {
            $dtuSn = $this->dtuFor($client, $sn);
            [$action, $devType] = self::COMMANDS[$Command];
            $target = $devType === 1 ? $dtuSn : $sn;
            $task = $client->sendCommand($action, $target, $devType, $dtuSn);
            $this->WriteAttributeString('Command', json_encode(['task' => $task, 'command' => $Command, 'sn' => $sn, 'polls' => 0]));
            $this->SetTimerInterval('CommandTimer', self::COMMAND_POLL_MS);
            $this->SendDebug('Command', "$Command sent to $target (DTU $dtuSn), task $task", 0);
            $this->updateCommandForm(sprintf($this->Translate('"%s" sent – waiting for confirmation from the DTU …'), $this->commandName($Command)));
            echo sprintf($this->Translate('"%s" has been sent. The DTU confirms it within about 30 seconds; the result is shown in the form and in the message log.'), $this->commandName($Command));
        } catch (Throwable $e) {
            echo $this->Translate('Error') . ': ' . $e->getMessage();
        } finally {
            $this->saveClientState($client);
        }
    }

    /** Ergebnis eines Steuerbefehls abfragen (Timer). */
    public function CommandStep(): void
    {
        $job = json_decode($this->ReadAttributeString('Command'), true);
        if (!is_array($job)) {
            $this->SetTimerInterval('CommandTimer', 0);
            return;
        }
        $client = $this->client();
        $code = 2;
        $error = '';
        try {
            $code = $client->commandStatus((string) $job['task']);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $this->saveClientState($client);
        $job['polls']++;
        $name = $this->commandName($job['command']);

        if ($code === 2 && $error === '' && $job['polls'] < self::COMMAND_MAX_POLLS) {
            $this->WriteAttributeString('Command', json_encode($job));
            return; // läuft noch
        }

        $this->SetTimerInterval('CommandTimer', 0);
        $this->WriteAttributeString('Command', '');
        if ($code === 0) {
            $off = json_decode($this->ReadAttributeString('SwitchedOff'), true) ?: [];
            if ($job['command'] === 'power_off') {
                $off[$job['sn']] = time();
            } elseif ($job['command'] === 'power_on') {
                unset($off[$job['sn']]);
            }
            $this->WriteAttributeString('SwitchedOff', json_encode($off, JSON_FORCE_OBJECT));
            $msg = sprintf($this->Translate('"%s" was carried out successfully.'), $name);
            $this->LogMessage($msg, KL_NOTIFY);
            if ($this->ReadPropertyBoolean('Active')) {
                $this->SetTimerInterval('UpdateTimer', 5000); // Ergebnis gleich sichtbar machen
            }
        } else {
            $reason = $error !== '' ? $error : ($code === 2 ? $this->Translate('no confirmation from the DTU (timeout)') : sprintf($this->Translate('error code %d'), $code));
            $msg = sprintf($this->Translate('"%s" failed: %s'), $name, $reason);
            $this->LogMessage($msg, KL_WARNING);
        }
        $this->SendDebug('Command', $msg, 0);
        $this->updateCommandForm($msg);
        $this->pushTile();
    }

    // ---------------------------------------------------------------- Abruf

    private function discoverDevices(HoymilesClient $client): void
    {
        $stations = $client->getStations();
        if (!$stations) {
            throw new HoymilesDeviceException($this->Translate('No plant found in this account'));
        }
        $names = [];
        foreach ($stations as $id => $s) {
            $names[$id] = (string) ($s['name'] ?? $this->Translate('Plant') . " $id");
        }
        $this->WriteAttributeString('Stations', json_encode($names, JSON_FORCE_OBJECT));

        $sid = $this->ReadPropertyInteger('StationID') ?: (int) array_key_first($stations);
        if (!isset($stations[$sid])) {
            throw new HoymilesDeviceException(sprintf($this->Translate('Plant %d does not belong to this account'), $sid));
        }

        $micros = [];
        foreach ($client->getMicroinverters($sid) as $m) {
            $mid = (int) $m['id'];
            $detail = $client->getMicroDetail($sid, $mid);
            $this->SendDebug('Inverter', json_encode($detail), 0);
            $model = (string) ($detail['init_hard_no'] ?? $m['init_hard_no'] ?? '');
            $ports = (int) ($detail['rule']['port'] ?? 0);
            if ($ports < 1) {
                // Fallback über den Modellnamen, z. B. HMS-1800-4T -> 4
                $ports = preg_match('/-(\d)T/i', $model, $mm) ? (int) $mm[1] : 1;
            }
            $ports = max(1, min(8, $ports)); // Hoymiles-Wechselrichter haben höchstens 4 Eingänge – Schutz vor unsinnigen Angaben
            $micros[] = ['id' => $mid, 'sn' => (string) ($m['sn'] ?? $detail['sn'] ?? ''), 'model' => $model, 'ports' => $ports];
        }
        if (!$micros) {
            throw new HoymilesDeviceException($this->Translate('No microinverters found in this plant'));
        }

        // Bei genau einem Wechselrichter entspricht PV-Eingang n dem Anlagen-Kanal n
        $single = count($micros) === 1;
        $channels = [];
        foreach ($micros as $k => $m) {
            for ($p = 1; $p <= $m['ports']; $p++) {
                $channels[] = [
                    'ident'   => $single ? "PV$p" : 'WR' . ($k + 1) . "_PV$p",
                    'name'    => $single ? "PV $p" : $this->Translate('INV') . ' ' . ($k + 1) . " PV $p",
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
        $this->hideUnusedInputs();
        $this->updateSummary();
    }

    /** Holt alle Werte; liefert true, wenn der Wechselrichter aktuelle Daten meldet (wach ist). */
    private function fetch(HoymilesClient $client): bool
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

        $power = $fresh ? $num($rt['real_power'] ?? null) : 0.0;
        $this->setIfChanged('Power', $power);
        $this->setIfChanged('Producing', $fresh && $power > 0);
        $today = $sameDay ? $num($rt['today_eq'] ?? null) / 1000 : 0.0;
        $this->setIfChanged('EnergyToday', $today);
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

        $channels = $this->channels();
        $wasFresh = $this->ReadAttributeBoolean('WasFresh');
        $this->WriteAttributeBoolean('WasFresh', $fresh);

        if (!$fresh) {
            // Wechselrichter schläft: PV-Eingänge auf 0
            foreach ($channels as $c) {
                $this->setIfChanged($c['ident'] . '_Power', 0.0);
                $this->setIfChanged($c['ident'] . '_Voltage', 0.0);
                $this->setIfChanged($c['ident'] . '_Current', 0.0);
            }
            if (!$sameDay && $this->ReadAttributeString('EnergyDate') !== date('Y-m-d')) {
                // neuer Tag, Anlage schläft noch: Tageserträge je Eingang zurücksetzen
                foreach ($channels as $c) {
                    $this->setIfChanged($c['ident'] . '_Energy', 0.0);
                }
                $this->WriteAttributeString('EnergyDate', date('Y-m-d'));
                $this->WriteAttributeString('TodayCurve', '[]');
            } elseif ($wasFresh && $sameDay) {
                // gerade eingeschlafen: Tagesertrag je Eingang ein letztes Mal berechnen
                $this->updateChannelEnergy($client, $sid, $channels, $today, []);
            }
            return false;
        }

        // PV-Eingänge: zuerst Anlagen-Indikatoren, fehlende aus dem Tagesverlauf
        $ind = HoymilesClient::indicatorMap($client->getPvIndicators($sid));
        $values = [];
        $this->measured = [];
        $missing = [];
        foreach ($channels as $c) {
            $n = $c['channel'];
            $p = $n ? ($ind["{$n}_pv_p"] ?? null) : null;
            $u = $n ? ($ind["{$n}_pv_v"] ?? null) : null;
            $i = $n ? ($ind["{$n}_pv_i"] ?? null) : null;
            if ($n && !HoymilesClient::isPlaceholder($p) && !HoymilesClient::isPlaceholder($u)) {
                $values[$c['ident']] = [(float) $p, (float) $u, $num($i)];
                $this->measured[$c['ident']] = true;
            } else {
                $missing[] = $c;
            }
        }

        // Tagesverläufe nur holen, wenn Werte fehlen oder der Tagesertrag je Eingang fällig ist
        $energyDue = time() - $this->ReadAttributeInteger('EnergyCalcAt') >= self::ENERGY_CALC_INTERVAL
            || $this->ReadAttributeString('EnergyDate') !== date('Y-m-d');
        $charts = [];
        if ($missing || $energyDue) {
            $charts = $this->loadCharts($client, $sid, $energyDue ? $channels : $missing);
        }
        foreach ($missing as $c) {
            $chart = $charts[$c['micro']][$c['port']] ?? null;
            if ($chart) {
                $m = HoymilesClient::latestModuleValues($chart, $maxAge);
                $values[$c['ident']] = [(float) ($m['MODULE_POWER'] ?? 0), (float) ($m['MODULE_V'] ?? 0), (float) ($m['MODULE_I'] ?? 0)];
                if ($m['fresh'] && $m['MODULE_POWER'] !== null) {
                    $this->measured[$c['ident']] = true;
                }
            } else {
                // kein Wert zu bekommen: nicht den alten Wert stehen lassen
                $values[$c['ident']] = [0.0, 0.0, 0.0];
            }
        }
        if ($missing) {
            $this->SendDebug('PV inputs', 'measured: ' . (implode(', ', array_keys($this->measured)) ?: '-')
                . ' | without current value: ' . (implode(', ', array_diff(array_column($missing, 'ident'), array_keys($this->measured))) ?: '-'), 0);
        }
        foreach ($values as $ident => [$p, $u, $i]) {
            $this->setIfChanged($ident . '_Power', $p);
            $this->setIfChanged($ident . '_Voltage', $u);
            $this->setIfChanged($ident . '_Current', $i);
        }
        if ($energyDue) {
            $this->updateChannelEnergy($client, $sid, $channels, $today, $charts);
        }
        if ($power > 0) {
            $this->learnBrightness();
        }
        return true;
    }

    /** @return array<int,array<int,array>> micro id => port => chart */
    private function loadCharts(HoymilesClient $client, int $sid, array $channels, string $date = ''): array
    {
        $ports = [];
        foreach ($channels as $c) {
            $ports[(int) $c['micro']][] = (int) $c['port'];
        }
        $charts = [];
        foreach ($ports as $microId => $list) {
            $charts[$microId] = $client->getModuleCharts($sid, $microId, $list, $date);
        }
        return $charts;
    }

    /**
     * Tagesertrag je PV-Eingang aus dem Tagesverlauf. Die Summe wird auf den Tagesertrag der
     * Cloud abgeglichen, damit die Einzelwerte zusammen „Ertrag heute“ ergeben.
     */
    private function updateChannelEnergy(HoymilesClient $client, int $sid, array $channels, float $todayKWh, array $charts): void
    {
        $needed = [];
        foreach ($channels as $c) {
            if (!isset($charts[$c['micro']][$c['port']])) {
                $needed[] = $c;
            }
        }
        if ($needed) {
            foreach ($this->loadCharts($client, $sid, $needed) as $microId => $byPort) {
                foreach ($byPort as $port => $chart) {
                    $charts[$microId][$port] = $chart;
                }
            }
        }

        $wh = [];
        foreach ($channels as $c) {
            $wh[$c['ident']] = HoymilesClient::chartEnergyWh($charts[$c['micro']][$c['port']] ?? ['x_axis' => [], 'series' => []]);
        }
        $sum = array_sum($wh);
        $scale = 1.0;
        if ($sum > 50 && $todayKWh > 0) {
            $factor = $todayKWh * 1000 / $sum;
            if ($factor > 0.7 && $factor < 1.3) { // nur plausible Abweichungen (Wechselrichter-Wirkungsgrad) ausgleichen
                $scale = $factor;
            }
        }
        foreach ($wh as $ident => $value) {
            $this->setIfChanged($ident . '_Energy', round($value * $scale / 1000, 3));
        }

        // Tagesverlauf (Summe aller Eingänge) für die Kachel
        $curve = [];
        $dataTs = (int) $this->GetValue('DataTime');
        $lastTs = $dataTs && date('Y-m-d', $dataTs) === date('Y-m-d') ? $dataTs : null;
        foreach ($channels as $c) {
            $chart = $charts[$c['micro']][$c['port']] ?? null;
            if (!$chart) {
                continue;
            }
            $power = [];
            foreach ($chart['series'] as $series) {
                if ($series['type'] === 'MODULE_POWER') {
                    $power = $series['data'];
                    break;
                }
            }
            $head = [];
            foreach (array_slice(array_keys($power), 0, 4) as $idx) {
                $head[] = (string) ($chart['x_axis'][$idx] ?? '?') . '=' . (is_finite((float) $power[$idx]) ? round((float) $power[$idx], 1) : '-');
            }
            $this->SendDebug('Daily curve', sprintf(
                '%s: %d axis labels (first "%s", last "%s"), %d values, start: %s',
                $c['name'],
                count($chart['x_axis']),
                (string) ($chart['x_axis'][0] ?? ''),
                (string) (end($chart['x_axis']) ?: ''),
                count($power),
                implode(' | ', $head)
            ), 0);
            foreach (HoymilesClient::chartPowerSamples($chart, date('Y-m-d'), $lastTs) as $sample) {
                $minute = (int) date('G', $sample['TimeStamp']) * 60 + (int) date('i', $sample['TimeStamp']);
                $curve[$minute] = ($curve[$minute] ?? 0) + $sample['Value'];
            }
        }
        ksort($curve);
        // Überbleibsel um Mitternacht auch in der Summe prüfen (falls ein Eingang anders meldet)
        $curve = array_combine(array_keys($curve), HoymilesClient::withoutCarryOver(array_values($curve), array_keys($curve)));
        $points = [];
        foreach ($curve as $minute => $watt) {
            $points[] = [$minute, (int) round($watt * $scale)];
        }
        $this->WriteAttributeString('TodayCurve', json_encode($points));
        $this->WriteAttributeInteger('EnergyCalcAt', time());
        $this->WriteAttributeString('EnergyDate', date('Y-m-d'));
    }

    // ------------------------------------------------------------ Ersparnis

    private function updateSavings(): void
    {
        if (!$this->ReadPropertyBoolean('Savings')) {
            return;
        }
        $share = max(0, min(100, $this->ReadPropertyInteger('SelfConsumption'))) / 100;
        $perKWh = $share * $this->ReadPropertyFloat('PricePurchase') + (1 - $share) * $this->ReadPropertyFloat('PriceFeedIn');
        foreach (['Today', 'Month', 'Year', 'Total'] as $period) {
            $kwh = (float) $this->GetValue('Energy' . $period);
            $this->setIfChanged('Savings' . $period, round($kwh * $perKWh, 2));
        }
    }

    // ------------------------------------------------------------- Firmware

    private function checkFirmware(HoymilesClient $client, bool $fresh): void
    {
        if (!$fresh || time() - $this->ReadAttributeInteger('FirmwareCheckedAt') < self::FIRMWARE_CHECK_INTERVAL) {
            return;
        }
        $this->WriteAttributeInteger('FirmwareCheckedAt', time());
        $sid = $this->ReadAttributeInteger('ActiveStation');
        try {
            $tree = $client->getDeviceTree($sid);
            $this->SendDebug('Firmware', json_encode($tree), 0);
            $dtus = [];
            $inverters = [];
            $this->walkDeviceTree($tree, 0, $dtus, $inverters);
            $this->WriteAttributeString('DtuMap', json_encode($this->dtuMapFromTree($tree), JSON_FORCE_OBJECT));

            $fmt = function (array $d): string {
                $sw = $d['soft_ver'] ?? $d['sys_soft_ver'] ?? ($d['extend_data']['soft_num'] ?? null);
                $hw = $d['hard_ver'] ?? null;
                $text = $sw !== null && $sw !== '' ? (string) $sw : '?';
                if ($hw !== null && $hw !== '') {
                    $text .= ' (HW ' . $hw . ')';
                }
                return $text;
            };
            $this->setIfChanged('FirmwareDTU', implode(', ', array_map($fmt, $dtus)));
            $this->setIfChanged('FirmwareInverter', implode(', ', array_map(function (array $d) use ($fmt): string {
                return (isset($d['sn']) ? $d['sn'] . ': ' : '') . $fmt($d);
            }, $inverters)));

            $upgrade = false;
            foreach ($dtus as $dtu) {
                if (empty($dtu['sn'])) {
                    continue;
                }
                $status = $client->getFirmwareStatus($sid, (string) $dtu['sn']);
                $this->SendDebug('Firmware', json_encode($status), 0);
                if ((int) ($status['upgrade'] ?? 0) > 0) {
                    $upgrade = true;
                }
                foreach ($status['list'] ?? [] as $entry) {
                    if ((int) ($entry['is_upgrade'] ?? 0) > 0) {
                        $upgrade = true;
                    }
                }
            }
            if ($upgrade && !$this->GetValue('FirmwareUpdate')) {
                $this->notify($this->Translate('Hoymiles: firmware'), $this->Translate('A firmware update is available for the DTU or inverter.'), false);
            }
            $this->setIfChanged('FirmwareUpdate', $upgrade);
        } catch (Throwable $e) {
            // Firmware-Info ist Zusatz: Fehler nicht als Störung der Instanz werten
            $this->SendDebug('Firmware', $this->Translate('Error') . ': ' . $e->getMessage(), 0);
        }
    }

    private function walkDeviceTree(array $nodes, int $depth, array &$dtus, array &$inverters): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (int) ($node['dev_type'] ?? $node['type'] ?? 0);
            $children = $node['children'] ?? $node['devices'] ?? [];
            $isDtu = $type === 1 || ($type === 0 && $depth === 0 && !empty($children));
            if (isset($node['sn']) || isset($node['soft_ver'])) {
                if ($isDtu) {
                    $dtus[] = $node;
                } else {
                    $inverters[] = $node;
                }
            }
            if (is_array($children) && $children) {
                $this->walkDeviceTree($children, $depth + 1, $dtus, $inverters);
            }
        }
    }

    // ------------------------------------------------------ Störungswarnungen

    /**
     * Prüft die Störungsbedingungen. Jede Bedingung ist wahr, falsch oder unbekannt (null);
     * bei „unbekannt“ (z. B. nachts) bleibt ihr bisheriger Zustand erhalten.
     */
    private function evaluateAlarms(?bool $fresh): void
    {
        $now = time();
        $since = json_decode($this->ReadAttributeString('AlarmSince'), true) ?: [];
        $active = json_decode($this->ReadAttributeString('AlarmActive'), true) ?: [];
        $delay = max(0, $this->ReadPropertyInteger('AlarmDelay')) * 60;
        $conditions = []; // key => [bool|null, text, delay]

        $day = $this->isDaylight();
        if ($this->detectStorageInputs($fresh, $day)) {
            // Warnungen, die auf dem Vergleich mit dem nun erkannten Speicher-Eingang beruhen, verwerfen
            $related = static function (string $key): bool {
                return $key === 'lowpower' || strpos($key, 'module_') === 0;
            };
            foreach (array_keys($active) as $key) {
                if ($related($key)) {
                    unset($active[$key], $since[$key]);
                }
            }
        }
        // Per Befehl ausgeschaltet: „kaum Leistung“ und „Eingang schwächer“ sind dann gewollt
        $switchedOff = (bool) (json_decode($this->ReadAttributeString('SwitchedOff'), true) ?: []);

        // 1. Keine Daten, obwohl Tag
        if ($this->ReadPropertyBoolean('AlarmOffline')) {
            $dataTs = (int) $this->GetValue('DataTime');
            $limit = max(10, $this->ReadPropertyInteger('AlarmOfflineMinutes')) * 60;
            $stale = $dataTs > 0 && $now - $dataTs > $limit;
            $state = null;
            if ($fresh === true) {
                $state = false;
            } elseif ($day && $stale) {
                $state = true;
            } elseif (!$day && !isset($active['offline'])) {
                $state = false;
            }
            $conditions['offline'] = [$state, sprintf($this->Translate('No data from the inverter since %s – DTU or inverter offline?'), $dataTs ? date('d.m. H:i', $dataTs) : '?'), 0];
        }

        // 2. Hell, aber kaum Leistung (nur mit Helligkeitssensor)
        $sensor = $this->ReadPropertyInteger('BrightnessVariable');
        $channels = $this->channels();
        $sources = $this->inputSources();
        $panels = array_values(array_filter($channels, static function (array $c) use ($sources): bool {
            return ($sources[$c['ident']] ?? 'panel') === 'panel';
        }));
        $withStorage = count($panels) < count($channels);
        if ($this->ReadPropertyBoolean('AlarmLowPower') && $sensor > 0 && IPS_VariableExists($sensor) && ($panels || !$channels)) {
            $brightness = (float) GetValue($sensor);
            if ($withStorage) {
                // Speicher-Eingänge liefern je nach Akku-Steuerung – nur die Solarmodule zählen
                $power = 0.0;
                foreach ($panels as $c) {
                    $power += (float) $this->GetValue($c['ident'] . '_Power');
                }
            } else {
                $power = (float) $this->GetValue('Power');
            }
            $state = null;
            if ($switchedOff) {
                $state = false;
            } elseif ($fresh === true) {
                $state = $brightness >= $this->ReadPropertyFloat('AlarmBrightness') && $power < $this->ReadPropertyInteger('AlarmMinPower');
            } elseif ($fresh === false && $brightness < $this->ReadPropertyFloat('AlarmBrightness')) {
                $state = false;
            }
            $text = $withStorage
                ? $this->Translate('Only %d W from the solar panels although it is bright (%s) – shading, snow or defect?')
                : $this->Translate('Only %d W although it is bright (%s) – shading, snow or defect?');
            $conditions['lowpower'] = [$state, sprintf($text, (int) round($power), $this->formatNumber($brightness)), $delay];
        }

        // 3. Ein PV-Eingang liefert deutlich weniger als die anderen (nur Eingänge mit Solarmodul)
        // Nachts liefern Solarmodule nichts – ein Vergleich wäre sinnlos (Speicher-Eingänge liefern dann evtl. noch)
        $compareNow = $day || !$this->hasDaylightSource();
        if ($this->ReadPropertyBoolean('AlarmModules') && count($panels) >= 2 && $compareNow) {
            // Nur Eingänge mit echtem Messwert vergleichen. Fehlt ein Wert (Cloud liefert gerade
            // nichts), zählt er nicht als 0 W – sonst gäbe es Fehlalarme mit „0 %“.
            $powers = [];
            foreach ($panels as $c) {
                if (isset($this->measured[$c['ident']])) {
                    $powers[$c['ident']] = (float) $this->GetValue($c['ident'] . '_Power');
                }
            }
            $deviation = max(10, min(95, $this->ReadPropertyInteger('AlarmModuleDeviation'))) / 100;
            foreach ($panels as $c) {
                $others = $powers;
                unset($others[$c['ident']]);
                $mean = $others ? array_sum($others) / count($others) : 0.0;
                $state = null;
                if ($switchedOff) {
                    $state = false;
                } elseif ($fresh === true && isset($powers[$c['ident']]) && $others) {
                    $weak = $powers[$c['ident']] < $mean * (1 - $deviation);
                    if ($mean >= $this->minComparePower($c)) {
                        $state = $weak;
                    } elseif (!$weak && $mean > 0) {
                        // wenig Licht, aber der Eingang liegt im Rahmen der anderen: Warnung aufheben
                        $state = false;
                    }
                }
                $percent = $mean > 0 ? (int) round(($powers[$c['ident']] ?? 0) / $mean * 100) : 0;
                $conditions['module_' . $c['ident']] = [$state, sprintf($this->Translate('%s delivers only %d %% of the other inputs'), $c['name'], $percent), $delay];
            }
        }

        // Zustände fortschreiben
        $newActive = [];
        foreach ($conditions as $key => [$state, $text, $wait]) {
            if ($state === null) {
                if (isset($active[$key])) {
                    $newActive[$key] = $active[$key];
                }
                continue;
            }
            if (!$state) {
                unset($since[$key]);
                continue;
            }
            $since[$key] = $since[$key] ?? $now;
            if ($now - $since[$key] >= $wait) {
                $newActive[$key] = $text;
            }
        }
        // abgeschaltete Prüfungen verwerfen
        foreach (array_keys($since) as $key) {
            if (!isset($conditions[$key])) {
                unset($since[$key]);
            }
        }

        $added = array_diff_key($newActive, $active);
        $wasAlarm = (bool) $active;
        foreach ($added as $text) {
            $this->LogMessage($text, KL_WARNING);
            $this->notify($this->Translate('Hoymiles: fault'), $text, true);
        }
        if ($wasAlarm && !$newActive) {
            $this->LogMessage($this->Translate('Fault cleared'), KL_NOTIFY);
            $this->notify($this->Translate('Hoymiles: OK'), $this->Translate('Fault cleared – the plant is working normally again.'), false);
        }

        $this->WriteAttributeString('AlarmSince', json_encode($since, JSON_FORCE_OBJECT));
        $this->WriteAttributeString('AlarmActive', json_encode($newActive, JSON_FORCE_OBJECT));
        $this->setIfChanged('Alarm', (bool) $newActive);
        $this->setIfChanged('AlarmText', $newActive ? implode("\n", $newActive) : '');
    }

    /**
     * Wurden die PV-Eingänge oder die Warn-Einstellungen geändert, werden die davon abhängigen
     * Warnungen verworfen und neu bewertet. Sonst bliebe z. B. eine Warnung aus dem Vergleich mit
     * einem Speicher-Eingang bis zur nächsten Auswertung bei ausreichend Licht stehen.
     */
    private function resetAlarmsOnConfigChange(): void
    {
        $config = md5(json_encode([
            $this->inputSources(),
            $this->ReadPropertyBoolean('AlarmLowPower'),
            $this->ReadPropertyFloat('AlarmBrightness'),
            $this->ReadPropertyInteger('AlarmMinPower'),
            $this->ReadPropertyBoolean('AlarmModules'),
            $this->ReadPropertyInteger('AlarmModuleDeviation'),
            $this->ReadPropertyInteger('BrightnessVariable'),
        ]));
        if ($config === $this->ReadAttributeString('AlarmConfig')) {
            return;
        }
        $this->WriteAttributeString('AlarmConfig', $config);
        $keep = static function (string $key): bool {
            return $key !== 'lowpower' && strpos($key, 'module_') !== 0;
        };
        $active = json_decode($this->ReadAttributeString('AlarmActive'), true) ?: [];
        $since = json_decode($this->ReadAttributeString('AlarmSince'), true) ?: [];
        $newActive = array_filter($active, $keep, ARRAY_FILTER_USE_KEY);
        if (count($newActive) === count($active)) {
            return;
        }
        $this->WriteAttributeString('AlarmActive', json_encode($newActive, JSON_FORCE_OBJECT));
        $this->WriteAttributeString('AlarmSince', json_encode(array_filter($since, $keep, ARRAY_FILTER_USE_KEY), JSON_FORCE_OBJECT));
        $this->setIfChanged('Alarm', (bool) $newActive);
        $this->setIfChanged('AlarmText', $newActive ? implode("\n", $newActive) : '');
        $this->SendDebug('Fault', 'settings changed – warnings re-evaluated', 0);
    }

    /**
     * Ab welcher Durchschnittsleistung der anderen Eingänge verglichen wird: mindestens 30 W
     * bzw. 10 % der Nennleistung je Eingang. Bei wenig Licht (morgens, abends, bedeckt) wirken sich
     * unterschiedliche Ausrichtungen sonst übermäßig aus.
     */
    private function minComparePower(array $channel): float
    {
        foreach (json_decode($this->ReadAttributeString('Micros'), true) ?: [] as $m) {
            if ((int) $m['id'] === (int) $channel['micro'] && preg_match('/(\d{3,4})/', (string) $m['model'], $mm)) {
                return max(30.0, (int) $mm[1] / max(1, (int) $m['ports']) * 0.1);
            }
        }
        return 30.0;
    }

    private function storageInfoText(): string
    {
        if (!$this->ReadPropertyBoolean('StorageConnected')) {
            return $this->Translate('Storage detection is switched off.');
        }
        $detected = json_decode($this->ReadAttributeString('StorageDetected'), true) ?: [];
        if (!$detected) {
            return $this->Translate('No storage input detected yet. An input that still delivers power at night is treated as storage automatically.');
        }
        $names = [];
        foreach ($this->channels() as $c) {
            if (isset($detected[$c['ident']])) {
                $names[] = $c['name'] . ' (' . date('d.m. H:i', (int) $detected[$c['ident']]) . ')';
            }
        }
        return sprintf($this->Translate('Detected as storage (delivered power at night): %s'), implode(', ', $names));
    }

    /** Gibt es eine verlässliche Tag/Nacht-Quelle (Helligkeitssensor oder Location)? */
    private function hasDaylightSource(): bool
    {
        $sensor = $this->ReadPropertyInteger('BrightnessVariable');
        if ($sensor > 0 && IPS_VariableExists($sensor)) {
            return true;
        }
        $location = $this->locationTimes();
        return $location !== null && (($location[0] > 0 && $location[1] > 0) || $location[2] !== null);
    }

    /**
     * Speicher angeschlossen: Ein Eingang, der nachts Strom liefert, hängt am Speicher.
     * Er wird gemerkt und ab dann wie ein Speicher-Eingang behandelt.
     * @return bool true, wenn ein Eingang neu erkannt wurde
     */
    private function detectStorageInputs(?bool $fresh, bool $day): bool
    {
        if (!$this->ReadPropertyBoolean('StorageConnected') || $fresh !== true || $day || !$this->hasDaylightSource()) {
            return false;
        }
        $configured = $this->configuredSources();
        $detected = json_decode($this->ReadAttributeString('StorageDetected'), true) ?: [];
        $new = false;
        foreach ($this->channels() as $c) {
            $ident = $c['ident'];
            if (isset($detected[$ident]) || ($configured[$ident] ?? 'panel') !== 'panel' || !isset($this->measured[$ident])) {
                continue;
            }
            $power = (float) $this->GetValue($ident . '_Power');
            if ($power >= 10) {
                $detected[$ident] = time();
                $new = true;
                $this->SendDebug('Storage', sprintf('%s delivers %d W at night – treated as storage input', $c['name'], $power), 0);
                $this->LogMessage(sprintf($this->Translate('%s delivers %d W at night – it is treated as a storage input from now on.'), $c['name'], (int) round($power)), KL_NOTIFY);
            }
        }
        if ($new) {
            $this->WriteAttributeString('StorageDetected', json_encode($detected, JSON_FORCE_OBJECT));
        }
        return $new;
    }

    /** Ist es hell genug, dass der Wechselrichter arbeiten müsste? (für die Offline-Warnung) */
    private function isDaylight(): bool
    {
        $sensor = $this->ReadPropertyInteger('BrightnessVariable');
        if ($sensor > 0 && IPS_VariableExists($sensor)) {
            return (float) GetValue($sensor) >= $this->brightnessThreshold();
        }
        $location = $this->locationTimes();
        if ($location !== null) {
            [$rise, $set, $isDay] = $location;
            if ($rise > 0 && $set > 0) {
                // eine Stunde Abstand zu Sonnenauf- und -untergang, damit Dämmerung keinen Fehlalarm auslöst
                return time() >= $rise + 3600 && time() <= $set - 3600;
            }
            if ($isDay !== null) {
                return $isDay;
            }
        }
        $hour = (int) date('G');
        return $hour >= 10 && $hour < 16;
    }

    private function notify(string $title, string $text, bool $isAlarm): void
    {
        $target = $this->ReadPropertyInteger('NotifyInstance');
        if ($target > 0 && IPS_InstanceExists($target)) {
            try {
                $moduleId = IPS_GetInstance($target)['ModuleInfo']['ModuleID'] ?? '';
                if ($moduleId === self::WEBFRONT_GUID) {
                    WFC_PushNotification($target, mb_substr($title, 0, 32), mb_substr($text, 0, 256), '', $this->InstanceID);
                } elseif ($moduleId === self::TILE_VISU_GUID) {
                    VISU_PostNotification($target, $title, $text, $isAlarm ? 'Warning' : 'Info', $this->InstanceID);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Notification', $e->getMessage(), 0);
            }
        }
        $script = $this->ReadPropertyInteger('NotifyScript');
        if ($script > 0 && IPS_ScriptExists($script)) {
            IPS_RunScriptEx($script, ['INSTANCE' => $this->InstanceID, 'TITLE' => $title, 'TEXT' => $text, 'ALARM' => $isAlarm]);
        }
    }

    // ------------------------------------------------------------ Nachtmodus

    /**
     * Legt den nächsten Abrufzeitpunkt fest. Nachts wird seltener abgefragt;
     * bei bekanntem Sonnenaufgang wird pünktlich zum Sonnenaufgang wieder abgefragt.
     */
    private function scheduleNextUpdate(?bool $fresh): void
    {
        $normal = max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60;
        $night = false;
        $source = '';
        $wakeAt = 0;
        if ($this->ReadPropertyBoolean('NightMode')) {
            [$night, $source, $wakeAt] = $this->nightState($fresh);
            if ($fresh === true) {
                $night = false; // meldet noch Daten (z. B. Dämmerung) -> normal weiter abfragen
            }
        }

        $interval = $normal;
        if ($night) {
            $interval = max($normal, $this->ReadPropertyInteger('NightInterval') * 60);
            if ($wakeAt > time()) {
                $interval = min($interval, max(60, $wakeAt - time()));
            }
        }
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        $this->WriteAttributeInteger('NextUpdate', time() + $interval);

        if ((bool) $this->GetValue('NightActive') !== $night) {
            $this->SendDebug('Night mode', ($night ? 'active' : 'ended') . ($source !== '' ? " (detected via $source)" : ''), 0);
            $this->SetValue('NightActive', $night);
        }
        $this->SendDebug('Timer', 'next query in ' . round($interval / 60, 1) . ' minutes', 0);
    }

    /**
     * Ist es Nacht? Reihenfolge: Helligkeitssensor, Sonnenstand (Location), Datenstand der Cloud.
     * @return array{0:bool,1:string,2:int} [Nacht, Quelle, Zeitpunkt des Sonnenaufgangs oder 0]
     */
    private function nightState(?bool $fresh): array
    {
        $sensor = $this->ReadPropertyInteger('BrightnessVariable');
        if ($sensor > 0 && IPS_VariableExists($sensor)) {
            return [(float) GetValue($sensor) < $this->brightnessThreshold(), 'brightness sensor', 0];
        }

        $location = $this->locationTimes();
        if ($location !== null) {
            [$rise, $set, $isDay] = $location;
            if ($rise > 0 && $set > 0) {
                $now = time();
                if ($now < $rise) {
                    return [true, 'sun position', $rise];
                }
                if ($now > $set) {
                    return [true, 'sun position', $rise + 86400]; // ungefähr morgen
                }
                return [false, 'sun position', 0];
            }
            if ($isDay !== null) {
                return [!$isDay, 'sun position', 0];
            }
        }

        if ($fresh === null) { // Abruf gescheitert: Zustand beibehalten
            return [(bool) $this->GetValue('NightActive'), 'cloud data time', 0];
        }
        return [!$fresh, 'cloud data time', 0];
    }

    /**
     * Schwelle „ab hier ist Tag“: gelernt aus den letzten Tagen (80 % des Medians der
     * Helligkeit beim Produktionsstart) oder – solange noch nichts gelernt ist bzw. das
     * Lernen ausgeschaltet ist – der eingetragene Wert.
     */
    private function brightnessThreshold(): float
    {
        if ($this->ReadPropertyBoolean('BrightnessAuto')) {
            $values = array_values(json_decode($this->ReadAttributeString('BrightnessLearned'), true) ?: []);
            if ($values) {
                sort($values);
                $n = count($values);
                $median = $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
                return round($median * 0.8, 1);
            }
        }
        return $this->ReadPropertyFloat('BrightnessThreshold');
    }

    private function learnedInfoText(): string
    {
        $learned = json_decode($this->ReadAttributeString('BrightnessLearned'), true) ?: [];
        if (!$this->ReadPropertyBoolean('BrightnessAuto')) {
            return $this->Translate('Learning is switched off – the threshold entered above applies.');
        }
        if (!$learned) {
            return $this->Translate('Nothing learned yet – the threshold entered above applies until the first morning with a brightness sensor.');
        }
        $days = [];
        foreach ($learned as $date => $value) {
            $days[] = date('d.m.', (int) strtotime($date)) . ': ' . $this->formatNumber((float) $value);
        }
        return sprintf($this->Translate('Learned threshold: %s (80 %% of the brightness at production start, last days: %s)'), $this->formatNumber($this->brightnessThreshold()), implode(' · ', $days));
    }

    /**
     * Einmal am Tag merken, wie hell es war, als der Wechselrichter morgens zu produzieren begann.
     * Den Startzeitpunkt liefert der Tagesverlauf der Cloud, die Helligkeit dazu das Archiv des
     * Sensors (sonst der aktuelle Wert, falls der Start erst wenige Minuten her ist).
     */
    private function learnBrightness(): void
    {
        $sensor = $this->ReadPropertyInteger('BrightnessVariable');
        if (!$this->ReadPropertyBoolean('BrightnessAuto') || $sensor <= 0 || !IPS_VariableExists($sensor)) {
            return;
        }
        $learned = json_decode($this->ReadAttributeString('BrightnessLearned'), true) ?: [];
        $today = date('Y-m-d');
        if (isset($learned[$today])) {
            return;
        }
        $startMinute = null;
        foreach (json_decode($this->ReadAttributeString('TodayCurve'), true) ?: [] as [$minute, $watt]) {
            if ($watt > 1) {
                $startMinute = (int) $minute;
                break;
            }
        }
        if ($startMinute === null || $startMinute >= 12 * 60) {
            return; // noch kein Verlauf oder kein Morgenstart erkennbar
        }
        $start = (int) strtotime("$today 00:00:00") + $startMinute * 60;

        $value = null;
        $ac = $this->archiveId();
        if ($ac && AC_GetLoggingStatus($ac, $sensor)) {
            $best = null;
            foreach (AC_GetLoggedValues($ac, $sensor, $start - 1800, $start + 600, 0) as $row) {
                $distance = abs((int) $row['TimeStamp'] - $start);
                if ($best === null || $distance < $best) {
                    $best = $distance;
                    $value = (float) $row['Value'];
                }
            }
        }
        if ($value === null && time() - $start <= 20 * 60) {
            $value = (float) GetValue($sensor);
        }
        if ($value === null || $value <= 0) {
            $this->SendDebug('Night mode', 'brightness at production start not available today', 0);
            return;
        }
        $learned[$today] = $value;
        ksort($learned);
        $learned = array_slice($learned, -10, null, true); // die letzten 10 Tage
        $this->WriteAttributeString('BrightnessLearned', json_encode($learned, JSON_FORCE_OBJECT));
        $this->SendDebug('Night mode', sprintf('production started at %s with brightness %s – threshold now %s', date('H:i', $start), $value, $this->brightnessThreshold()), 0);
    }

    /** @return array{0:int,1:int,2:?bool}|null [Sonnenaufgang, Sonnenuntergang, Tag] aus der Location-Instanz */
    private function locationTimes(): ?array
    {
        $locations = IPS_GetInstanceListByModuleID(self::LOCATION_GUID);
        if (!$locations) {
            return null;
        }
        $riseId = @IPS_GetObjectIDByIdent('Sunrise', $locations[0]);
        $setId = @IPS_GetObjectIDByIdent('Sunset', $locations[0]);
        $isDayId = @IPS_GetObjectIDByIdent('IsDay', $locations[0]);
        return [
            $riseId ? (int) GetValue($riseId) : 0,
            $setId ? (int) GetValue($setId) : 0,
            $isDayId ? (bool) GetValue($isDayId) : null,
        ];
    }

    private function registerBrightnessSensor(): void
    {
        $old = $this->ReadAttributeInteger('BrightnessRegistered');
        $new = $this->ReadPropertyInteger('BrightnessVariable');
        if ($new > 0 && !IPS_VariableExists($new)) {
            $new = 0;
        }
        if ($old === $new) {
            return;
        }
        if ($old > 0) {
            $this->UnregisterMessage($old, VM_UPDATE);
            $this->UnregisterReference($old);
        }
        if ($new > 0) {
            $this->RegisterMessage($new, VM_UPDATE);
            $this->RegisterReference($new);
        }
        $this->WriteAttributeInteger('BrightnessRegistered', $new);
        $image = $this->ReadPropertyInteger('TileImage');
        if ($image > 0 && @IPS_MediaExists($image)) {
            $this->RegisterReference($image);
        }
    }

    // ---------------------------------------------------------------- Kachel

    /** Hintergrund der Kachel: eingebaute Illustration, eigenes Bild (Medienobjekt) oder keiner. */
    private function tileBackground(): array
    {
        $mode = $this->ReadPropertyString('TileBackground');
        $opacity = max(5, min(100, $this->ReadPropertyInteger('TileImageOpacity'))) / 100;
        if ($mode !== 'image' && $mode !== 'picture') {
            return ['mode' => $mode === 'none' ? 'none' : 'illustration', 'image' => null, 'opacity' => $opacity];
        }
        $media = $this->ReadPropertyInteger('TileImage');
        if ($media <= 0 || !IPS_MediaExists($media)) {
            return ['mode' => 'illustration', 'image' => null, 'opacity' => $opacity];
        }
        $image = $this->tileImageData($media, $mode === 'picture' ? 800 : 1600);
        if ($image === '') {
            return ['mode' => 'illustration', 'image' => null, 'opacity' => $opacity];
        }
        return ['mode' => $mode, 'image' => $image, 'opacity' => $opacity];
    }

    /**
     * Bild als data-URI für die Kachel. Große Bilder werden verkleinert (längste Seite $maxSize px,
     * JPEG bzw. PNG bei Transparenz), damit die Kachel die Grenze der Visualisierung von 1 MB
     * nicht überschreitet. Das Ergebnis wird zwischengespeichert.
     */
    private function tileImageData(int $media, int $maxSize): string
    {
        $content = (string) IPS_GetMediaContent($media); // Base64
        if ($content === '') {
            $this->SendDebug('Tile', 'image is empty – using the illustration', 0);
            return '';
        }
        $key = md5($media . '|' . $maxSize . '|' . strlen($content) . '|' . substr($content, 0, 64) . '|' . substr($content, -64));
        $cache = json_decode($this->GetBuffer('TileImage'), true) ?: [];
        if (($cache['key'] ?? '') === $key) {
            return (string) $cache['uri'];
        }

        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
        $ext = strtolower(pathinfo((string) IPS_GetMedia($media)['MediaFile'], PATHINFO_EXTENSION));
        $mime = $types[$ext] ?? 'image/jpeg';

        $uri = '';
        $problem = '';
        $scaled = self::scaleImage($content, $mime, $maxSize);
        if (isset($scaled['uri'])) {
            $uri = $scaled['uri'];
            $this->SendDebug('Tile', sprintf('image %dx%d scaled to %dx%d (%d kB)', $scaled['from'][0], $scaled['from'][1], $scaled['to'][0], $scaled['to'][1], strlen($uri) * 3 / 4 / 1024), 0);
        } elseif (isset($scaled['error'])) {
            $problem = sprintf($this->Translate('Tile image is too large to be scaled down (%d × %d pixels) – please use a smaller image (e.g. max. 2000 pixels wide).'), $scaled['from'][0], $scaled['from'][1]);
        } else {
            $uri = "data:$mime;base64,$content"; // klein genug oder nicht verkleinerbar: unverändert
        }
        unset($content);
        if ($problem === '' && strlen($uri) > 700 * 1024) {
            $problem = $this->Translate('Tile image is too large – please use a smaller image (JPEG, max. approx. 500 kB).');
            $uri = '';
        }
        if ($problem !== '') {
            $this->SendDebug('Tile', $problem . ' – using the illustration', 0);
            $this->LogMessage($problem, KL_WARNING);
        }
        // auch das Scheitern merken, damit nicht bei jedem Öffnen neu gerechnet und gewarnt wird
        $this->SetBuffer('TileImage', json_encode(['key' => $key, 'uri' => $uri]));
        return $uri;
    }

    /**
     * Verkleinert ein Bild (Base64) auf höchstens $maxSize px Kantenlänge.
     * @return array{uri:string,from:array,to:array}|null null, wenn nicht nötig oder nicht möglich
     */
    public static function scaleImage(string $base64, string $mime, int $maxSize): ?array
    {
        $raw = base64_decode($base64, true);
        if ($mime === 'image/svg+xml' || $raw === false || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }
        [$w, $h] = $info;
        if (max($w, $h) <= $maxSize && strlen($raw) <= 400 * 1024) {
            return null; // schon klein genug
        }
        // Entpackt braucht das Bild ca. 5 Byte je Pixel. Symcon erlaubt Skripten meist nur 32 MB –
        // vorher prüfen (und wenn möglich kurz anheben), statt mit einem Speicherfehler abzubrechen.
        $scale = min(1.0, $maxSize / max($w, $h));
        $need = (int) ($w * $h * 5.5 + ($w * $scale) * ($h * $scale) * 5 + strlen($raw) * 2 + 4 * 1024 * 1024);
        $oldLimit = ini_get('memory_limit');
        if (!self::ensureMemory($need)) {
            return ['error' => 'memory', 'from' => [$w, $h]];
        }
        try {
            $result = self::scaleDecoded($raw, $mime, $w, $h, $scale);
        } finally {
            if ($oldLimit !== false && function_exists('ini_set') && ini_get('memory_limit') !== $oldLimit) {
                @ini_set('memory_limit', $oldLimit);
            }
        }
        return $result;
    }

    /** Ist genug Speicher frei? Falls nicht, wird versucht, die Grenze passend anzuheben. */
    private static function ensureMemory(int $need): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit < 0) {
            return true; // unbegrenzt
        }
        $used = memory_get_usage(true);
        if ($limit - $used >= $need) {
            return true;
        }
        $wanted = $used + $need + 8 * 1024 * 1024;
        if (!function_exists('ini_set') || @ini_set('memory_limit', (string) $wanted) === false) {
            return false;
        }
        return self::bytes((string) ini_get('memory_limit')) >= $wanted;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $num = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
        }
        return $num;
    }

    private static function scaleDecoded(string $raw, string $mime, int $w, int $h, float $scale): ?array
    {
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return null;
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        $alpha = in_array($mime, ['image/png', 'image/webp', 'image/gif'], true);
        if ($alpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $alpha ? imagepng($out, null, 9) : imagejpeg($out, null, 82);
        $data = (string) ob_get_clean();
        unset($img, $out); // imagedestroy() ist seit PHP 8.0 wirkungslos und ab PHP 8.5 veraltet
        if ($data === '' || strlen($data) >= strlen($raw)) {
            return null;
        }
        return ['uri' => 'data:' . ($alpha ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode($data), 'from' => [$w, $h], 'to' => [$nw, $nh]];
    }

    /** @param bool $ack Antwort auf „Aktualisieren“, obwohl nicht neu abgefragt wurde (Kachel beendet die Drehung) */
    private function pushTile(bool $ack = false): void
    {
        $message = $this->tileMessage();
        if ($ack && str_ends_with($message, '}') && $message !== '{}') {
            $message = substr($message, 0, -1) . ',"ack":true}';
        }
        $this->UpdateVisualizationValue($message);
    }

    /** Alle Daten der Kachel als JSON (wird beim Öffnen und nach jedem Abruf gesendet). */
    private function tileMessage(): string
    {
        $channels = $this->channels();
        $micros = json_decode($this->ReadAttributeString('Micros'), true) ?: [];
        $sources = $this->inputSources();
        $alarms = json_decode($this->ReadAttributeString('AlarmActive'), true) ?: [];
        $switchedOff = (bool) (json_decode($this->ReadAttributeString('SwitchedOff'), true) ?: []);
        $value = function (string $ident, $default = 0) {
            return @$this->GetIDForIdent($ident) ? $this->GetValue($ident) : $default;
        };

        // Nennleistung: Einstellung, sonst aus dem Modellnamen (z. B. HMS-1800-4T -> 1800 W)
        $ratedPerMicro = [];
        foreach ($micros as $m) {
            $ratedPerMicro[$m['id']] = preg_match('/(\d{3,4})/', (string) $m['model'], $mm) ? (int) $mm[1] : 0;
        }
        $pmax = $this->ReadPropertyInteger('TileMaxPower') ?: array_sum($ratedPerMicro);

        $inputs = [];
        foreach ($channels as $c) {
            $source = $sources[$c['ident']] ?? 'panel';
            if ($source === 'unused') {
                continue;
            }
            $ports = 1;
            foreach ($micros as $m) {
                if ((int) $m['id'] === (int) $c['micro']) {
                    $ports = max(1, (int) $m['ports']);
                }
            }
            $inputs[] = [
                'id'      => (int) @$this->GetIDForIdent($c['ident'] . '_Power'),
                'name'    => $c['name'],
                'source'  => $source,
                'power'   => (float) $value($c['ident'] . '_Power'),
                'voltage' => (float) $value($c['ident'] . '_Voltage'),
                'current' => (float) $value($c['ident'] . '_Current'),
                'energy'  => (float) $value($c['ident'] . '_Energy'),
                'max'     => ($ratedPerMicro[$c['micro']] ?? 0) > 0 ? $ratedPerMicro[$c['micro']] / $ports : 500,
                'weak'    => isset($alarms['module_' . $c['ident']]),
            ];
        }

        // Tagesverlauf plus aktueller Wert
        $curve = json_decode($this->ReadAttributeString('TodayCurve'), true) ?: [];
        $dataTs = (int) $value('DataTime');
        $power = (float) $value('Power');
        if ($dataTs && date('Y-m-d', $dataTs) === date('Y-m-d') && $value('Producing', false)) {
            $minute = (int) date('G', $dataTs) * 60 + (int) date('i', $dataTs);
            if (!$curve || end($curve)[0] < $minute) {
                // Der letzte Abschnitt ist in der Cloud oft noch nicht fertig (0 W): nicht als Einbruch zeigen
                while ($power > 0 && $curve && end($curve)[1] <= 0 && $minute - end($curve)[0] <= 15) {
                    array_pop($curve);
                }
                $curve[] = [$minute, (int) round($power)];
            }
        }

        // Zustand für die Statusanzeige
        $okStatus = in_array($this->GetStatus(), [self::STATUS_OK, IS_CREATING], true);
        if (!$this->ReadPropertyBoolean('Active')) {
            [$status, $text] = ['standby', $this->Translate('Deactivated')];
        } elseif ($alarms) {
            [$status, $text] = ['fault', $this->Translate('Fault')];
        } elseif ($channels && !$okStatus) {
            [$status, $text] = ['fault', $this->Translate('No connection')];
        } elseif ($switchedOff) {
            [$status, $text] = ['standby', $this->Translate('Switched off')];
        } elseif ($value('Producing', false)) {
            [$status, $text] = ['producing', $this->Translate('Producing')];
        } elseif ($value('NightActive', false)) {
            [$status, $text] = ['night', $this->Translate('Night')];
        } else {
            [$status, $text] = ['standby', $this->Translate('Standby')];
        }

        return json_encode([
            'configured'   => (bool) $channels,
            'name'         => $this->ReadAttributeString('StationName') ?: 'Hoymiles',
            'model'        => implode(', ', array_column($micros, 'model')),
            'status'       => $status,
            'statusText'   => $text,
            'alarm'        => $alarms ? implode("\n", $alarms) : '',
            'power'        => $power,
            'pmax'         => $pmax,
            'today'        => (float) $value('EnergyToday'),
            'month'        => (float) $value('EnergyMonth'),
            'year'         => (float) $value('EnergyYear'),
            'total'        => (float) $value('EnergyTotal'),
            'savingsToday' => $this->ReadPropertyBoolean('Savings') ? (float) $value('SavingsToday') : null,
            'colors'       => $this->ReadPropertyString('TileColors') === 'accent' ? 'accent' : 'solar',
            'ids'          => [
                'power' => (int) @$this->GetIDForIdent('Power'),
                'today' => (int) @$this->GetIDForIdent('EnergyToday'),
                'saved' => (int) @$this->GetIDForIdent('SavingsToday'),
                'month' => (int) @$this->GetIDForIdent('EnergyMonth'),
                'year'  => (int) @$this->GetIDForIdent('EnergyYear'),
                'alarm' => (int) @$this->GetIDForIdent('AlarmText'),
            ],
            'dataTime'     => $dataTs,
            'lastUpdate'   => (int) $value('LastUpdate'),
            'nextUpdate'   => $this->ReadPropertyBoolean('Active') ? $this->ReadAttributeInteger('NextUpdate') : 0,
            'inputs'       => $inputs,
            'curve'        => $curve,
            'labels'       => [
                'today'         => $this->Translate('today'),
                'saved'         => $this->Translate('saved today'),
                'month'         => $this->Translate('Month'),
                'year'          => $this->Translate('Year'),
                'dataTime'      => $this->Translate('Data'),
                'lastQuery'     => $this->Translate('Last query'),
                'queried'       => $this->Translate('queried'),
                'nextQuery'     => $this->Translate('next query'),
                'cloudTime'     => $this->Translate('Cloud data from'),
                'justNow'       => $this->Translate('just now'),
                'minAgo'        => $this->Translate('%d min ago'),
                'ofRated'       => $this->Translate('%s % of %p W'),
                'refresh'       => $this->Translate('Update now'),
                'notConfigured' => $this->Translate('No plant read in yet.'),
                'noCurve'       => $this->Translate('No curve for today yet'),
                'storage'       => $this->Translate('Storage'),
                'panel'         => $this->Translate('Solar panel'),
            ],
        ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'; // ein ungültiger Wert darf die Kachel nicht leeren
    }

    // ---------------------------------------------------------- Steuerbefehle

    /** Seriennummer der DTU, an der ein Wechselrichter hängt (aus dem Geräte-Baum, zwischengespeichert). */
    private function dtuFor(HoymilesClient $client, string $inverterSn): string
    {
        $map = json_decode($this->ReadAttributeString('DtuMap'), true) ?: [];
        if (!empty($map[$inverterSn])) {
            return (string) $map[$inverterSn];
        }
        $tree = $client->getDeviceTree($this->ReadAttributeInteger('ActiveStation'));
        $map = $this->dtuMapFromTree($tree);
        $this->WriteAttributeString('DtuMap', json_encode($map, JSON_FORCE_OBJECT));
        if (empty($map[$inverterSn])) {
            throw new HoymilesException($this->Translate('The DTU of this inverter could not be determined.'));
        }
        return (string) $map[$inverterSn];
    }

    /** @return array<string,string> Seriennummer Wechselrichter => Seriennummer DTU */
    private function dtuMapFromTree(array $tree): array
    {
        $map = [];
        $firstDtu = '';
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $dtuSn = (string) ($node['sn'] ?? '');
            $firstDtu = $firstDtu ?: $dtuSn;
            foreach ($node['children'] ?? $node['devices'] ?? [] as $child) {
                if (is_array($child) && !empty($child['sn']) && $dtuSn !== '') {
                    $map[(string) $child['sn']] = $dtuSn;
                }
            }
        }
        // Wechselrichter, die im Baum nicht auftauchen, der (einzigen) DTU zuordnen
        foreach (json_decode($this->ReadAttributeString('Micros'), true) ?: [] as $m) {
            if (!isset($map[$m['sn']]) && $firstDtu !== '') {
                $map[$m['sn']] = $firstDtu;
            }
        }
        return $map;
    }

    private function commandName(string $command): string
    {
        $names = [
            'reboot'     => $this->Translate('Restart inverter'),
            'power_on'   => $this->Translate('Switch inverter on'),
            'power_off'  => $this->Translate('Switch inverter off'),
            'dtu_reboot' => $this->Translate('Restart DTU'),
        ];
        return $names[$command] ?? $command;
    }

    private function commandStatusText(): string
    {
        $job = json_decode($this->ReadAttributeString('Command'), true);
        if (is_array($job)) {
            return sprintf($this->Translate('"%s" sent – waiting for confirmation from the DTU …'), $this->commandName($job['command']));
        }
        $off = json_decode($this->ReadAttributeString('SwitchedOff'), true) ?: [];
        if ($off) {
            return sprintf($this->Translate('Switched off by command: %s'), implode(', ', array_keys($off)));
        }
        return $this->Translate('No command running.');
    }

    private function updateCommandForm(string $text): void
    {
        $this->UpdateFormField('CommandStatus', 'caption', $text);
    }

    // ------------------------------------------------------ Verlauf nachladen

    /** Lädt einen Tag und schreibt die Leistung je PV-Eingang ins Archiv. Liefert den DC-Ertrag in Wh. */
    private function backfillDay(HoymilesClient $client, string $date): float
    {
        $ac = $this->archiveId();
        $sid = $this->ReadAttributeInteger('ActiveStation');
        $channels = $this->channels();
        $charts = $this->loadCharts($client, $sid, $channels, $date);
        $dayStart = (int) strtotime("$date 00:00:00");
        $dayEnd = $dayStart + 86399;

        $wh = 0.0;
        $touched = [];
        foreach ($channels as $c) {
            $chart = $charts[$c['micro']][$c['port']] ?? null;
            if (!$chart) {
                continue;
            }
            $wh += HoymilesClient::chartEnergyWh($chart);
            $vid = @$this->GetIDForIdent($c['ident'] . '_Power');
            if (!$vid || !AC_GetLoggingStatus($ac, $vid) || $this->archiveHasData($ac, $vid, $dayStart, $dayEnd)) {
                continue; // an diesem Tag schon Werte im Archiv -> nichts doppelt eintragen
            }
            $samples = HoymilesClient::chartPowerSamples($chart, $date);
            if ($samples) {
                AC_AddLoggedValues($ac, $vid, $samples);
                $touched[] = $vid;
            }
        }
        foreach ($touched as $vid) {
            AC_ReAggregateVariable($ac, $vid);
        }
        $this->SendDebug('Backfill', sprintf('%s: %.0f Wh, %d inputs written', $date, $wh, count($touched)), 0);
        return $wh;
    }

    /**
     * Trägt für „Ertrag gesamt“ je Tag den Zählerstand um 23:59 ein. Ausgangspunkt ist der
     * Zählerstand der Cloud zu Beginn des heutigen Tages; davon werden die nachgeladenen
     * Tageserträge rückwärts abgezogen. Die Tageserträge aus dem Verlauf (Gleichstromseite)
     * werden dabei mit dem Verhältnis zum Cloud-Tagesertrag auf die Wechselstromseite umgerechnet.
     */
    private function backfillCounter(array $energy): int
    {
        $ac = $this->archiveId();
        $vid = @$this->GetIDForIdent('EnergyTotal');
        if (!$ac || !$vid || !AC_GetLoggingStatus($ac, $vid)) {
            return 0;
        }
        $counter = (float) $this->GetValue('EnergyTotal') - (float) $this->GetValue('EnergyToday');
        if ($counter <= 0) {
            return 0;
        }
        $scale = $this->acDcRatio();
        krsort($energy); // neueste zuerst
        $values = [];
        foreach ($energy as $date => $wh) {
            $ts = (int) strtotime("$date 23:59:00");
            if (!$this->archiveHasData($ac, $vid, $ts - 86340, $ts + 59)) {
                $values[] = ['TimeStamp' => $ts, 'Value' => round($counter, 3)];
            }
            $counter -= $wh * $scale / 1000;
            if ($counter <= 0) {
                break;
            }
        }
        if ($values) {
            AC_AddLoggedValues($ac, $vid, array_reverse($values));
            AC_ReAggregateVariable($ac, $vid);
        }
        return count($values);
    }

    /** Verhältnis Cloud-Ertrag (Wechselstrom) zu Summe der PV-Eingänge (Gleichstrom), Standard 0,96. */
    private function acDcRatio(): float
    {
        $dc = 0.0;
        foreach ($this->channels() as $c) {
            $dc += (float) $this->GetValue($c['ident'] . '_Energy');
        }
        $ac = (float) $this->GetValue('EnergyToday');
        if ($dc > 0.05 && $ac > 0) {
            $ratio = $ac / $dc;
            if ($ratio > 0.7 && $ratio <= 1.0) {
                return $ratio;
            }
        }
        return 0.96;
    }

    private function archiveHasData(int $ac, int $vid, int $start, int $end): bool
    {
        return count(AC_GetLoggedValues($ac, $vid, $start, $end, 1)) > 0;
    }

    private function archiveId(): int
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $archives ? (int) $archives[0] : 0;
    }

    private function backfillStatusText(): string
    {
        $job = json_decode($this->ReadAttributeString('Backfill'), true);
        if (!is_array($job)) {
            return $this->Translate('No history loading running.');
        }
        $done = $job['total'] - count($job['pending']);
        return sprintf($this->Translate('Loading history: %d of %d days'), $done, $job['total']);
    }

    private function updateBackfillForm(string $text = ''): void
    {
        $this->UpdateFormField('BackfillStatus', 'caption', $text !== '' ? $text : $this->backfillStatusText());
    }

    // -------------------------------------------------------------- Hilfen

    private function client(bool $fresh = false): HoymilesClient
    {
        $state = $fresh ? [] : [
            'token'   => $this->ReadAttributeString('Token'),
            'expires' => $this->ReadAttributeInteger('TokenExpires'),
            'mode'    => $this->ReadAttributeString('TokenMode'),
        ];
        $client = new HoymilesClient(
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyString('AuthMode'),
            $state,
            function (string $title, string $message): void {
                $this->SendDebug($title, $message, 0);
            }
        );
        $client->setNoCombineUntil((int) $this->GetBuffer('NoCombineUntil'));
        return $client;
    }

    private function saveClientState(HoymilesClient $client): void
    {
        if ((string) $client->getNoCombineUntil() !== $this->GetBuffer('NoCombineUntil')) {
            $this->SetBuffer('NoCombineUntil', (string) $client->getNoCombineUntil());
        }
        $s = $client->getState();
        if ($s['token'] !== $this->ReadAttributeString('Token')) {
            $this->WriteAttributeString('Token', $s['token']);
            $this->WriteAttributeInteger('TokenExpires', $s['expires']);
        }
        if ($s['mode'] !== '' && $s['mode'] !== $this->ReadAttributeString('TokenMode')) {
            $this->WriteAttributeString('TokenMode', $s['mode']);
        }
    }

    /**
     * Prüfwert, um geänderte Zugangsdaten zu erkennen. HMAC-SHA-256 mit zufälligem
     * Salt je Instanz, damit sich aus dem gespeicherten Wert kein Passwort zurückrechnen lässt.
     */
    private function credentialFingerprint(): string
    {
        $salt = $this->ReadAttributeString('AuthSalt');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            $this->WriteAttributeString('AuthSalt', $salt);
        }
        $data = $this->ReadPropertyString('Username') . "\0" . $this->ReadPropertyString('Password') . "\0" . $this->ReadPropertyString('AuthMode');
        return hash_hmac('sha256', $data, $salt);
    }

    private function hasCredentials(): bool
    {
        return $this->ReadPropertyString('Username') !== '' && $this->ReadPropertyString('Password') !== '';
    }

    /** Variablen nicht belegter PV-Eingänge ausblenden (und wieder einblenden, wenn belegt). */
    private function hideUnusedInputs(): void
    {
        $sources = $this->inputSources();
        foreach ($this->channels() as $c) {
            $hidden = ($sources[$c['ident']] ?? 'panel') === 'unused';
            foreach (['_Power', '_Voltage', '_Current', '_Energy'] as $suffix) {
                $vid = @$this->GetIDForIdent($c['ident'] . $suffix);
                if ($vid && IPS_GetObject($vid)['ObjectIsHidden'] !== $hidden) {
                    IPS_SetHidden($vid, $hidden);
                }
            }
        }
    }

    /** @return array<string,string> Ident des Eingangs => 'panel' | 'storage' | 'unused' */
    private function inputSources(): array
    {
        $map = $this->configuredSources();
        if ($this->ReadPropertyBoolean('StorageConnected')) {
            foreach (array_keys(json_decode($this->ReadAttributeString('StorageDetected'), true) ?: []) as $ident) {
                if (($map[$ident] ?? 'panel') === 'panel') {
                    $map[$ident] = 'storage';
                }
            }
        }
        return $map;
    }

    /** Einstellung aus der Liste „PV-Eingänge“ (ohne automatisch erkannte Speicher-Eingänge). */
    private function configuredSources(): array
    {
        $map = [];
        foreach (json_decode($this->ReadPropertyString('InputSources'), true) ?: [] as $row) {
            if (isset($row['Ident'])) {
                $source = (string) ($row['Source'] ?? 'panel');
                $map[(string) $row['Ident']] = in_array($source, ['storage', 'unused'], true) ? $source : 'panel';
            }
        }
        return $map;
    }

    private function channels(): array
    {
        return json_decode($this->ReadAttributeString('Channels'), true) ?: [];
    }

    /**
     * Nach einer abgelehnten Anmeldung nicht im normalen Takt weiter probieren – das kann das
     * Konto sperren. Wartezeit verdoppelt sich: 15 Minuten, 30, 60 … bis höchstens 6 Stunden.
     * „Übernehmen“ oder „Verbindung testen“ starten sofort einen neuen Versuch.
     */
    private function authBackoff(): void
    {
        if ($this->GetStatus() !== self::STATUS_AUTH_ERROR) {
            if ($this->ReadAttributeInteger('AuthFailures') !== 0) {
                $this->WriteAttributeInteger('AuthFailures', 0);
            }
            return;
        }
        $failures = $this->ReadAttributeInteger('AuthFailures') + 1;
        $this->WriteAttributeInteger('AuthFailures', $failures);
        $wait = (int) min(self::AUTH_BACKOFF_MAX, 900 * 2 ** min(10, $failures - 1));
        $this->SetTimerInterval('UpdateTimer', $wait * 1000);
        $this->WriteAttributeInteger('NextUpdate', time() + $wait);
        $this->SendDebug('Timer', sprintf('login failed %d time(s) – next attempt in %d minutes', $failures, $wait / 60), 0);
    }

    private function fail(int $status, Throwable $e): void
    {
        $this->SendDebug('Error', $e->getMessage(), 0);
        if ($this->GetStatus() !== $status) { // nur beim Wechsel ins Log, nicht bei jeder Abfrage
            $this->LogMessage($e->getMessage(), KL_WARNING);
        }
        $this->SetStatus($status);
    }

    private function maintainChannelVariables(array $channels): void
    {
        $wanted = [];
        foreach ($channels as $k => $c) {
            $pos = 30 + $k * 4;
            $this->RegisterVariableFloat($c['ident'] . '_Power', sprintf($this->Translate('%s power'), $c['name']), $this->presentValue(' W', 1, 'bolt'), $pos);
            $this->RegisterVariableFloat($c['ident'] . '_Voltage', sprintf($this->Translate('%s voltage'), $c['name']), $this->presentValue(' V', 1, 'plug'), $pos + 1);
            $this->RegisterVariableFloat($c['ident'] . '_Current', sprintf($this->Translate('%s current'), $c['name']), $this->presentValue(' A', 2, 'wave-square'), $pos + 2);
            $this->RegisterVariableFloat($c['ident'] . '_Energy', sprintf($this->Translate('%s yield today'), $c['name']), $this->presentValue(' kWh', 2, 'solar-panel'), $pos + 3);
            array_push($wanted, $c['ident'] . '_Power', $c['ident'] . '_Voltage', $c['ident'] . '_Current', $c['ident'] . '_Energy');
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
        $ac = $this->archiveId();
        if (!$ac) {
            return;
        }
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
            return $this->Translate('No plant read in yet.');
        }
        $parts = [];
        foreach ($micros as $m) {
            $parts[] = trim($m['model'] . ' · SN ' . $m['sn'] . ' · ' . sprintf($this->Translate('%d PV inputs'), $m['ports']));
        }
        $mode = $this->ReadAttributeString('TokenMode');
        return $this->Translate('Plant') . ': ' . $this->ReadAttributeString('StationName') . ' (' . $this->ReadAttributeInteger('ActiveStation') . ")\n"
            . $this->Translate('Inverter') . ': ' . implode("\n", $parts)
            . ($mode !== '' ? "\n" . $this->Translate('Login variant') . ": $mode" : '');
    }

    private function updateSummary(): void
    {
        $micros = json_decode($this->ReadAttributeString('Micros'), true) ?: [];
        $this->SetSummary(implode(', ', array_column($micros, 'model')));
    }

    private function setIfChanged(string $ident, $value): void
    {
        if (@$this->GetIDForIdent($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, $value < 100 ? 1 : 0, ',', '.');
    }

    /** Wird nur von den SymconStubs (automatisierte Tests) für Timer benötigt. */
    protected function getTime(): int
    {
        return time();
    }

    // ------------------------------------------------------- Darstellungen

    /** Wertanzeige für Zahlen: Einheit, Nachkommastellen, Icon (Font-Awesome-Name). */
    private function presentValue(string $suffix, int $digits, string $icon): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits, 'ICON' => $icon];
    }

    /** Wertanzeige für Ja/Nein mit Text, Icon und optional Farbe je Zustand. */
    private function presentBool(string $off, string $iconOff, string $on, string $iconOn, int $colorOn = -1, int $colorOff = -1): array
    {
        $option = function (bool $value, string $caption, string $icon, int $color): array {
            $o = ['Value' => $value, 'Caption' => $this->Translate($caption), 'IconActive' => true, 'IconValue' => $icon];
            if ($color >= 0) {
                $o['ColorActive'] = true;
                $o['ColorValue'] = $color;
            }
            return $o;
        };
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([$option(false, $off, $iconOff, $colorOff), $option(true, $on, $iconOn, $colorOn)]),
        ];
    }

    private function presentDateTime(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 1, 'MONTH_TEXT' => false, 'DAY_OF_THE_WEEK' => false, 'TIME' => 2];
    }

    /**
     * Die früheren Variablenprofile HOYM.* löschen, sobald keine Variable sie mehr nutzt
     * (nach der Umstellung auf Darstellungen). Ein Profil, das noch irgendwo verwendet wird –
     * z. B. von Hand einer anderen Variable zugewiesen –, bleibt erhalten.
     */
    private function removeLegacyProfiles(): void
    {
        $legacy = ['HOYM.Voltage', 'HOYM.Current', 'HOYM.kg', 'HOYM.Euro', 'HOYM.Producing', 'HOYM.Night', 'HOYM.Update', 'HOYM.Fault'];
        $existing = array_values(array_filter($legacy, 'IPS_VariableProfileExists'));
        if (!$existing) {
            return;
        }
        $used = [];
        foreach (IPS_GetVariableList() as $vid) {
            $v = IPS_GetVariable($vid);
            $used[(string) ($v['VariableProfile'] ?? '')] = true;
            $used[(string) ($v['VariableCustomProfile'] ?? '')] = true;
        }
        foreach ($existing as $name) {
            if (!isset($used[$name])) {
                IPS_DeleteVariableProfile($name);
                $this->SendDebug('Presentation', "legacy profile $name removed", 0);
            }
        }
    }
}
