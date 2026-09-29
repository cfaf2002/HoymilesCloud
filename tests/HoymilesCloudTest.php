<?php

declare(strict_types=1);

include_once __DIR__ . '/bootstrap.php';

class HoymilesCloudTest extends TestCaseSymconValidation
{
    private const MODULE = '{B74BE38F-ACD1-46FD-93AB-D2FE680D3521}';
    private const ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const LOCATION = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    private $archive = 0;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        foreach (['~Watt' => 2, '~Electricity' => 2, '~UnixTimestamp' => 1, '~Alert' => 0] as $name => $type) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS\ModuleLoader::loadLibrary(SYMCON_STUBS_DIR . '/CoreStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        HoymilesClient::$baseUrlOverride = HOYMILES_FAKE_URL;
        foreach (['offset', 'nocombine', 'upgrade', 'weakpv4', 'requests.log', 'cmdresult', 'cmdpolls', 'labelfmt', 'stalepv3'] as $file) {
            @unlink(HOYMILES_FAKE_STATE . "/$file");
        }
        $this->archive = IPS_CreateInstance(self::ARCHIVE);
    }

    public function testValidation(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
        $this->validateModule(__DIR__ . '/../HoymilesCloud');
    }

    public function testWithoutCredentials(): void
    {
        $id = IPS_CreateInstance(self::MODULE);
        $this->assertSame(204, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testInactive(): void
    {
        $id = $this->instance(['Active' => false]);
        $this->assertSame(104, IPS_GetInstance($id)['InstanceStatus']);
        HOYM_Update($id);
        $this->assertSame([], $this->requests(), 'no cloud requests while inactive');
    }

    public function testUpdateDuringDay(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        $this->assertSame(102, IPS_GetInstance($id)['InstanceStatus']);
        $this->assertEqualsWithDelta(812.4, $this->v($id, 'Power'), 0.01);
        $this->assertEqualsWithDelta(3.456, $this->v($id, 'EnergyToday'), 0.0001);
        $this->assertEqualsWithDelta(4567.89, $this->v($id, 'EnergyTotal'), 0.001);
        $this->assertTrue($this->v($id, 'Producing'));
        $this->assertFalse($this->v($id, 'NightActive'));
        $this->assertEqualsWithDelta(217.6, $this->v($id, 'PV1_Power'), 0.01, 'PV1 from indicators');
        $this->assertEqualsWithDelta(103.0, $this->v($id, 'PV3_Power'), 0.01, 'PV3 from daily curve');
        $this->assertEqualsWithDelta(3.04, $this->v($id, 'PV4_Current'), 0.001);
        $this->assertSame(5, $this->timerMinutes($id));
        $this->assertTrue(AC_GetLoggingStatus($this->archive, IPS_GetObjectIDByIdent('EnergyTotal', $id)));
        $this->assertSame(1, AC_GetAggregationType($this->archive, IPS_GetObjectIDByIdent('EnergyTotal', $id)));
    }

    public function testEnergyPerInput(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        $e = [];
        for ($p = 1; $p <= 4; $p++) {
            $e[$p] = $this->v($id, "PV{$p}_Energy");
            $this->assertGreaterThan(0.0, $e[$p], "PV$p energy");
        }
        $this->assertTrue($e[4] > $e[1], 'input 4 (104 W) yields more than input 1 (101 W)');
        $this->assertSame(1, $this->countRequests('module/data/count_by_day'), 'one combined request for all inputs');
    }

    public function testDailyCurveFallbackToSingleRequests(): void
    {
        touch(HOYMILES_FAKE_STATE . '/nocombine');
        $id = $this->instance();
        HOYM_Update($id);
        $this->assertSame(5, $this->countRequests('module/data/count_by_day'), 'combined + one per input');
        $this->assertEqualsWithDelta(104.0, $this->v($id, 'PV4_Power'), 0.01);
    }

    public function testNightModeViaDataTime(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        file_put_contents(HOYMILES_FAKE_STATE . '/offset', (string) (3 * 3600));
        HOYM_Update($id); // gerade eingeschlafen: letzte Ertragsberechnung
        @unlink(HOYMILES_FAKE_STATE . '/requests.log');
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Producing'));
        $this->assertTrue($this->v($id, 'NightActive'));
        $this->assertSame(30, $this->timerMinutes($id));
        $this->assertSame(0.0, $this->v($id, 'PV3_Power'));
        $this->assertSame(['/pvm-data/api/0/station/data/count_station_real_data'], $this->requests(), 'one request at night');

        file_put_contents(HOYMILES_FAKE_STATE . '/offset', '60');
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'NightActive'));
        $this->assertSame(5, $this->timerMinutes($id));
    }

    public function testBrightnessSensorWakesUp(): void
    {
        file_put_contents(HOYMILES_FAKE_STATE . '/offset', (string) (3 * 3600));
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 5.0);
        $id = $this->instance(['BrightnessVariable' => $lux, 'BrightnessThreshold' => 50.0]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'NightActive'));
        $this->assertSame(30, $this->timerMinutes($id));

        $module = IPS\InstanceManager::getInstanceInterface($id);
        $module->MessageSink(time(), $lux, VM_UPDATE, [20.0, true, 5.0]);
        $this->assertSame(30, $this->timerMinutes($id), 'below threshold: keep sleeping');
        $module->MessageSink(time(), $lux, VM_UPDATE, [80.0, true, 20.0]);
        $this->assertFalse($this->v($id, 'NightActive'));
        $this->assertSame(0, $this->timerMinutes($id), 'query within a second');
    }

    public function testSunriseFromLocation(): void
    {
        file_put_contents(HOYMILES_FAKE_STATE . '/offset', (string) (3 * 3600));
        $location = IPS_CreateInstance(self::LOCATION);
        SetValue(IPS_GetObjectIDByIdent('IsDay', $location), false);
        $sunrise = IPS_CreateVariable(VARIABLETYPE_INTEGER);
        IPS_SetParent($sunrise, $location);
        IPS_SetIdent($sunrise, 'Sunrise');
        SetValue($sunrise, time() + 12 * 60);
        SetValue(IPS_GetObjectIDByIdent('Sunset', $location), time() + 14 * 3600);
        $id = $this->instance();
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'NightActive'));
        $this->assertSame(12, $this->timerMinutes($id), 'next query at sunrise');
    }

    public function testSavings(): void
    {
        $id = $this->instance(['PricePurchase' => 0.30, 'SelfConsumption' => 100, 'PriceFeedIn' => 0.0]);
        HOYM_Update($id);
        $this->assertEqualsWithDelta(1.04, $this->v($id, 'SavingsToday'), 0.001);
        $this->assertEqualsWithDelta(round(4567.89 * 0.30, 2), $this->v($id, 'SavingsTotal'), 0.001);

        $id2 = $this->instance(['PricePurchase' => 0.30, 'SelfConsumption' => 50, 'PriceFeedIn' => 0.08]);
        HOYM_Update($id2);
        $this->assertEqualsWithDelta(round(3.456 * 0.19, 2), $this->v($id2, 'SavingsToday'), 0.001);

        $id3 = $this->instance(['Savings' => false]);
        $this->assertFalse(@IPS_GetObjectIDByIdent('SavingsToday', $id3), 'no savings variables when disabled');
    }

    public function testFirmware(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        $this->assertSame('V01.01.10 (HW H00.04)', $this->v($id, 'FirmwareDTU'));
        $this->assertSame('1161A0000001: V01.00.20 (HW H00.01)', $this->v($id, 'FirmwareInverter'));
        $this->assertFalse($this->v($id, 'FirmwareUpdate'));

        touch(HOYMILES_FAKE_STATE . '/upgrade');
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'FirmwareUpdate'), 'checked only once a day');
        ob_start();
        HOYM_Discover($id); // setzt die Firmware-Prüfung zurück
        ob_end_clean();
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'FirmwareUpdate'));
    }

    public function testModuleComparisonAlarm(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 0]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));
        $this->assertStringContainsString('PV 4', $this->v($id, 'AlarmText'));

        @unlink(HOYMILES_FAKE_STATE . '/weakpv4');
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'));
        $this->assertSame('', $this->v($id, 'AlarmText'));
        $log = array_column(IPS\LogServer::getLogMessages(strval($id)), 'Message');
        $this->assertStringContainsString('PV 4', implode("\n", $log));
        $this->assertContains('Fault cleared', $log);
    }

    public function testMissingInputValueIsNoFault(): void
    {
        touch(HOYMILES_FAKE_STATE . '/stalepv3');
        $id = $this->instance(['AlarmDelay' => 0]);
        HOYM_Update($id);
        $this->assertEqualsWithDelta(0.0, $this->v($id, 'PV3_Power'), 0.01, 'no current value -> 0 W shown');
        $this->assertFalse($this->v($id, 'Alarm'), 'but not reported as "delivers only 0 %"');
    }

    public function testCarryOverAtMidnightIsIgnored(): void
    {
        $this->assertSame([0.0, 0.0, 0.0, 12.0], HoymilesClient::withoutCarryOver([950.0, 0.0, 0.0, 12.0]));
        $this->assertSame([300.0, 310.0, 0.0], HoymilesClient::withoutCarryOver([300.0, 310.0, 0.0]), 'real power at midnight (storage) stays');
        $this->assertSame([0.0, 2.0, 1.5, 0.0], HoymilesClient::withoutCarryOver([960.0, 2.0, 1.5, 0.0]), 'small standby values after the carry-over');
        $this->assertSame([0.0, 40.0, 35.0], HoymilesClient::withoutCarryOver([120.0, 40.0, 35.0], [0, 430, 435]), 'single point at midnight, next value hours later');
        $this->assertSame([120.0, 110.0, 100.0], HoymilesClient::withoutCarryOver([120.0, 110.0, 100.0], [0, 5, 10]), 'continuous night feed stays');
        $gap = ['x_axis' => ['00:00', '07:10', '07:15'], 'series' => [['type' => 'MODULE_POWER', 'data' => [120.0, 40.0, 35.0], 'port' => 2]]];
        $this->assertEqualsWithDelta(6.25, HoymilesClient::chartEnergyWh($gap), 0.01, 'only 07:10 and 07:15 count');
        $chart = ['x_axis' => ['00:00', '00:05', '00:10'], 'series' => [['type' => 'MODULE_POWER', 'data' => [1200.0, 0.0, 60.0], 'port' => 1]]];
        $this->assertEqualsWithDelta(5.0, HoymilesClient::chartEnergyWh($chart), 0.01);
        $this->assertSame(0.0, HoymilesClient::chartPowerSamples($chart, date('Y-m-d'))[0]['Value']);
    }

    public function testStorageInputDetectedAtNight(): void
    {
        touch(HOYMILES_FAKE_STATE . '/stalepv3'); // PV 3 liefert nachts nichts
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 2.0);
        $id = $this->instance(['BrightnessVariable' => $lux, 'BrightnessThreshold' => 50.0, 'BrightnessAuto' => false, 'StorageConnected' => true, 'AlarmDelay' => 0]);
        HOYM_Update($id);
        $detected = json_decode($this->readAttribute($id, 'StorageDetected'), true);
        $this->assertSame(['PV1', 'PV2', 'PV4'], array_keys($detected), 'inputs delivering at night are storage');
        $this->assertFalse($this->v($id, 'Alarm'), 'PV 3 is not compared with the storage inputs');
        $tile = $this->tileData($id);
        $this->assertSame('storage', $tile['inputs'][0]['source']);
        $this->assertSame('panel', $tile['inputs'][2]['source']);

        HOYM_ResetStorageDetection($id);
        $this->assertSame('{}', $this->readAttribute($id, 'StorageDetected'));
    }

    public function testNoStorageDetectionWhenSwitchedOffAndNoComparisonAtNight(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 2.0);
        $id = $this->instance(['BrightnessVariable' => $lux, 'BrightnessThreshold' => 50.0, 'BrightnessAuto' => false, 'AlarmDelay' => 0]);
        HOYM_Update($id);
        $this->assertSame('{}', $this->readAttribute($id, 'StorageDetected'));
        $this->assertFalse($this->v($id, 'Alarm'), 'no input comparison at night');

        SetValue($lux, 500.0); // Tag: jetzt wird verglichen
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));
    }

    public function testModuleComparisonRespectsDelay(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 30]);
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'), 'not before 30 minutes');
    }

    public function testOfflineAlarm(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        $id = $this->instance(['BrightnessVariable' => $lux, 'AlarmModules' => false]);
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'));

        file_put_contents(HOYMILES_FAKE_STATE . '/offset', (string) (3 * 3600));
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));
        $this->assertStringContainsString('offline', $this->v($id, 'AlarmText'));

        SetValue($lux, 0.0); // Nacht: Warnung bleibt bis wieder Daten kommen
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));

        file_put_contents(HOYMILES_FAKE_STATE . '/offset', '60');
        SetValue($lux, 30000.0);
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'));
    }

    public function testLowPowerAlarm(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        $id = $this->instance(['BrightnessVariable' => $lux, 'AlarmModules' => false, 'AlarmDelay' => 0, 'AlarmMinPower' => 1000]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));
        $this->assertStringContainsString('812 W', $this->v($id, 'AlarmText'));
    }

    public function testBackfill(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        ob_start();
        HOYM_Backfill($id, 3);
        ob_end_clean();
        for ($i = 0; $i < 4; $i++) {
            HOYM_BackfillStep($id);
        }
        $pv1 = IPS_GetObjectIDByIdent('PV1_Power', $id);
        $start = strtotime('-3 day 00:00');
        $values = AC_GetLoggedValues($this->archive, $pv1, $start, strtotime('today') - 1, 0);
        $this->assertCount(3 * 169, $values, '3 days × 06:00–20:00 in 5-minute steps');

        $total = IPS_GetObjectIDByIdent('EnergyTotal', $id);
        $counter = AC_GetLoggedValues($this->archive, $total, $start, strtotime('today') - 1, 0);
        $this->assertCount(3, $counter);
        $newest = max(array_column($counter, 'Value'));
        $this->assertEqualsWithDelta(4567.89 - 3.456, $newest, 0.001, 'counter at end of yesterday');
        $this->assertSame('', $this->readAttribute($id, 'Backfill'), 'job finished');
    }

    public function testStorageInputsAreLeftOutOfComparison(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 0, 'InputSources' => $this->sources(['PV3', 'PV4'])]);
        HOYM_Update($id);
        // (PV 2 kann in den Testdaten gegenüber PV 1 auffallen – entscheidend ist, dass PV 4 nicht gemeldet wird)
        $this->assertStringNotContainsString('PV 4', $this->v($id, 'AlarmText'), 'weak storage input is no fault');
    }

    public function testLowPowerCountsOnlySolarPanels(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        $base = ['BrightnessVariable' => $lux, 'AlarmModules' => false, 'AlarmDelay' => 0, 'InputSources' => $this->sources(['PV3', 'PV4'])];

        $id = $this->instance($base + ['AlarmMinPower' => 400]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'), 'PV1 + PV2 = 320 W < 400 W');
        $this->assertStringContainsString('320 W from the solar panels', $this->v($id, 'AlarmText'));

        $id2 = $this->instance($base + ['AlarmMinPower' => 300]);
        HOYM_Update($id2);
        $this->assertFalse($this->v($id2, 'Alarm'), '320 W >= 300 W');
    }

    public function testUnusedInputsAreIgnoredAndHidden(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 0, 'InputSources' => $this->sources(['PV2'], ['PV3', 'PV4'])]);
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'), 'only one solar panel input left: nothing to compare');
        $this->assertTrue(IPS_GetObject(IPS_GetObjectIDByIdent('PV4_Power', $id))['ObjectIsHidden']);
        $this->assertFalse(IPS_GetObject(IPS_GetObjectIDByIdent('PV1_Power', $id))['ObjectIsHidden']);

        IPS_SetProperty($id, 'InputSources', $this->sources([]));
        IPS_ApplyChanges($id);
        $this->assertFalse(IPS_GetObject(IPS_GetObjectIDByIdent('PV4_Power', $id))['ObjectIsHidden'], 'shown again');
    }

    public function testFormListsInputs(): void
    {
        $id = $this->instance(['InputSources' => $this->sources(['PV3'])]);
        HOYM_Update($id);
        $form = json_decode(IPS_GetConfigurationForm($id), true);
        $rows = null;
        foreach ($form['elements'] as $element) {
            foreach ($element['items'] ?? [] as $item) {
                if (($item['name'] ?? '') === 'InputSources') {
                    $rows = $item['values'];
                }
            }
        }
        $this->assertCount(4, $rows);
        $this->assertSame(['Ident' => 'PV3', 'Input' => 'PV 3', 'Source' => 'storage'], $rows[2]);
        $this->assertSame('panel', $rows[0]['Source']);
    }

    public function testFaultVariableShowsNoneOrFault(): void
    {
        $id = $this->instance();
        $alarm = IPS_GetObjectIDByIdent('Alarm', $id);
        $this->assertSame('HOYM.Fault', IPS_GetVariable($alarm)['VariableProfile']);
        IPS\VariableManager::setVariableProfile($alarm, '~Alert'); // Stand von Build 4
        IPS_ApplyChanges($id);
        $this->assertSame('HOYM.Fault', IPS_GetVariable($alarm)['VariableProfile'], 'updated from ~Alert on update');
    }

    public function testRestartInverter(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        @unlink(HOYMILES_FAKE_STATE . '/requests.log');
        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'reboot', '');
        });
        $put = $this->requestBodies('/pvm-ctl/api/0/dev/command/put');
        $this->assertCount(1, $put);
        $this->assertSame(['action' => 3, 'dev_sn' => '1161A0000001', 'dev_type' => 3, 'dtu_sn' => 'DTU0001', 'data' => []], $put[0]);

        HOYM_CommandStep($id); // DTU arbeitet noch
        $this->assertStringContainsString('reboot', $this->readAttribute($id, 'Command'));
        HOYM_CommandStep($id); // fertig
        $this->assertSame('', $this->readAttribute($id, 'Command'));
        $log = implode("\n", array_column(IPS\LogServer::getLogMessages(strval($id)), 'Message'));
        $this->assertStringContainsString('"Restart inverter" was carried out successfully', $log);
    }

    public function testRestartDtuAddressesTheDtu(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        @unlink(HOYMILES_FAKE_STATE . '/requests.log');
        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'dtu_reboot', '1161A0000001');
        });
        $put = $this->requestBodies('/pvm-ctl/api/0/dev/command/put');
        $this->assertSame(['action' => 1, 'dev_sn' => 'DTU0001', 'dev_type' => 1, 'dtu_sn' => 'DTU0001', 'data' => []], $put[0]);
    }

    public function testFailedCommandIsReported(): void
    {
        file_put_contents(HOYMILES_FAKE_STATE . '/cmdresult', '5');
        $id = $this->instance();
        HOYM_Update($id);
        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'power_on', '');
        });
        HOYM_CommandStep($id);
        HOYM_CommandStep($id);
        $log = implode("\n", array_column(IPS\LogServer::getLogMessages(strval($id)), 'Message'));
        $this->assertStringContainsString('"Switch inverter on" failed: error code 5', $log);
    }

    public function testSwitchedOffSuspendsPowerWarnings(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['BrightnessVariable' => $lux, 'AlarmDelay' => 0, 'AlarmMinPower' => 1000]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'), 'warnings active before switching off');

        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'power_off', '');
        });
        HOYM_CommandStep($id);
        HOYM_CommandStep($id);
        HOYM_Update($id);
        $this->assertFalse($this->v($id, 'Alarm'), 'no power warnings while switched off');

        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'power_on', '');
        });
        HOYM_CommandStep($id);
        HOYM_CommandStep($id);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'), 'warnings active again after switching on');
    }

    public function testOnlyOneCommandAtATime(): void
    {
        $id = $this->instance();
        HOYM_Update($id);
        $this->silent(function () use ($id) {
            HOYM_SendCommand($id, 'reboot', '');
        });
        ob_start();
        HOYM_SendCommand($id, 'power_off', '');
        $out = ob_get_clean();
        $this->assertStringContainsString('still running', $out);
    }

    public function testTileShowsCurrentValues(): void
    {
        $id = $this->instance(['InputSources' => $this->sources(['PV4'], ['PV3'])]);
        HOYM_Update($id);
        $tile = $this->tileData($id);
        $this->assertTrue($tile['configured']);
        $this->assertSame('producing', $tile['status']);
        $this->assertEqualsWithDelta(812.4, $tile['power'], 0.01);
        $this->assertSame(1800, $tile['pmax'], 'rated power from model name');
        $this->assertCount(3, $tile['inputs'], 'unused input is not shown');
        $this->assertSame('storage', $tile['inputs'][2]['source']);
        $this->assertEqualsWithDelta(450.0, $tile['inputs'][0]['max'], 0.01, '1800 W / 4 inputs');
        $this->assertGreaterThan(0, count($tile['curve']), 'daily curve for the tile');
        $this->assertStringContainsString('function handleMessage', IPS\InstanceManager::getInstanceInterface($id)->GetVisualizationTile());
    }

    public function testTileShowsFault(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 0, 'TileMaxPower' => 800]);
        HOYM_Update($id);
        $tile = $this->tileData($id);
        $this->assertSame('fault', $tile['status']);
        $this->assertStringContainsString('PV 4', $tile['alarm']);
        $this->assertTrue($tile['inputs'][3]['weak']);
        $this->assertSame(800, $tile['pmax'], 'rated power from the setting');
    }

    public function testTileRefreshButton(): void
    {
        $id = $this->instance();
        IPS\InstanceManager::getInstanceInterface($id)->RequestAction('Refresh', true);
        $this->assertGreaterThan(0, $this->countRequests('count_station_real_data'));
    }

    public function testTileSyncDoesNotQueryCloud(): void
    {
        $id = $this->instance(['UpdateInterval' => 3]);
        HOYM_Update($id);
        $tile = $this->tileData($id);
        $this->assertGreaterThan(0, $tile['lastUpdate'], 'time of the last query for the tile');
        $this->assertEqualsWithDelta(time() + 180, $tile['nextUpdate'], 5, 'next query in 3 minutes');

        $before = $this->countRequests('count_station_real_data');
        IPS\InstanceManager::getInstanceInterface($id)->RequestAction('Sync', true);
        $this->assertSame($before, $this->countRequests('count_station_real_data'), 'sync only resends the current state');
    }

    public function testTimeLabelFormats(): void
    {
        $this->assertSame(365, HoymilesClient::labelMinute('06:05'));
        $this->assertSame(365, HoymilesClient::labelMinute('06:05:00'));
        $this->assertSame(365, HoymilesClient::labelMinute('2026-09-27 06:05:00'));
        $this->assertSame(365, HoymilesClient::labelMinute((string) mktime(6, 5, 0)));
        $this->assertSame(365, HoymilesClient::labelMinute(mktime(6, 5, 0) * 1000));
        $this->assertSame(null, HoymilesClient::labelMinute('abc'));

        $chart = ['x_axis' => [], 'series' => [['type' => 'MODULE_POWER', 'data' => [10.0, 20.0, 30.0], 'port' => 1]]];
        $samples = HoymilesClient::chartPowerSamples($chart, date('Y-m-d'), mktime(12, 10, 0));
        $this->assertSame([mktime(12, 0, 0), mktime(12, 5, 0), mktime(12, 10, 0)], array_column($samples, 'TimeStamp'), 'no axis: counted back from the last value');
        $this->assertSame([], HoymilesClient::chartPowerSamples($chart, date('Y-m-d')), 'no axis and no reference: nothing');
    }

    public function testCurveWithSecondsInLabels(): void
    {
        file_put_contents(HOYMILES_FAKE_STATE . '/labelfmt', 'hms');
        $id = $this->instance();
        HOYM_Update($id);
        $this->assertGreaterThan(5, count($this->tileData($id)['curve']));
    }

    public function testCurveWithoutAxisLabels(): void
    {
        file_put_contents(HOYMILES_FAKE_STATE . '/labelfmt', 'none');
        $id = $this->instance();
        HOYM_Update($id);
        $curve = $this->tileData($id)['curve'];
        $this->assertGreaterThan(5, count($curve));
        $this->assertGreaterThan(0.0, $this->v($id, 'PV1_Energy'));
    }

    public function testChangingInputSourcesClearsComparisonFault(): void
    {
        touch(HOYMILES_FAKE_STATE . '/weakpv4');
        $id = $this->instance(['AlarmDelay' => 0]);
        HOYM_Update($id);
        $this->assertTrue($this->v($id, 'Alarm'));

        // PV 4 ist in Wahrheit der Speicher: Warnung verschwindet sofort, nicht erst beim nächsten hellen Abruf
        IPS_SetProperty($id, 'InputSources', $this->sources(['PV4']));
        IPS_ApplyChanges($id);
        $this->assertFalse($this->v($id, 'Alarm'));
        $this->assertSame('', $this->v($id, 'AlarmText'));
    }

    public function testLargeTileImageIsScaled(): void
    {
        $img = imagecreatetruecolor(3000, 2000);
        for ($i = 0; $i < 4000; $i++) { // Rauschen, damit das Bild groß wird
            imagefilledrectangle($img, random_int(0, 2990), random_int(0, 1990), random_int(0, 2999), random_int(0, 1999), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($img, null, 95);
        $raw = (string) ob_get_clean();
        $scaled = HoymilesCloud::scaleImage(base64_encode($raw), 'image/jpeg', 800);
        $this->assertNotNull($scaled);
        $this->assertSame([800, 533], $scaled['to']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $scaled['uri']);
        $this->assertLessThan(400 * 1024, strlen($scaled['uri']), 'fits well below the 1 MB tile limit');
        $this->assertNull(HoymilesCloud::scaleImage(base64_encode('<svg/>'), 'image/svg+xml', 800));
    }

    public function testTileBackground(): void
    {
        $id = $this->instance();
        $this->assertSame('illustration', $this->tileBackgroundData($id)['mode'], 'illustration by default');

        $media = IPS_CreateMedia(1);
        IPS_SetMediaFile($media, 'media/haus.png', false);
        IPS_SetMediaContent($media, base64_encode('PNGDATA'));
        IPS_SetProperty($id, 'TileBackground', 'image');
        IPS_SetProperty($id, 'TileImage', $media);
        IPS_SetProperty($id, 'TileImageOpacity', 25);
        IPS_ApplyChanges($id);
        // Die SymconStubs speichern keinen Medieninhalt: ohne Bilddaten fällt die Kachel auf die Illustration zurück
        $bg = $this->tileBackgroundData($id);
        $this->assertSame('illustration', $bg['mode'], 'empty image falls back to the illustration');
        $this->assertEqualsWithDelta(0.25, $bg['opacity'], 0.001);

        IPS_SetProperty($id, 'TileBackground', 'picture');
        IPS_ApplyChanges($id);
        $this->assertSame('illustration', $this->tileBackgroundData($id)['mode'], 'picture without image data falls back too');

        IPS_SetProperty($id, 'TileBackground', 'none');
        IPS_ApplyChanges($id);
        $this->assertSame('none', $this->tileBackgroundData($id)['mode']);
    }

    public function testLearnsBrightnessThresholdFromArchive(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        AC_SetLoggingStatus($this->archive, $lux, true);
        // Der Tagesverlauf der nachgebauten Cloud beginnt um 00:00 – dort liegt der archivierte Wert
        $start = strtotime('today 00:00');
        AC_AddLoggedValues($this->archive, $lux, [['TimeStamp' => $start - 300, 'Value' => 380.0], ['TimeStamp' => $start + 60, 'Value' => 400.0]]);

        $id = $this->instance(['BrightnessVariable' => $lux, 'BrightnessThreshold' => 50.0, 'AlarmModules' => false]);
        $this->assertEqualsWithDelta(50.0, $this->threshold($id), 0.01, 'entered value until something is learned');
        HOYM_Update($id);
        $this->assertEqualsWithDelta(320.0, $this->threshold($id), 0.01, '80 % of 400 (closest value to the start)');

        $form = json_decode(IPS_GetConfigurationForm($id), true);
        $info = '';
        foreach ($form['elements'] as $element) {
            foreach ($element['items'] ?? [] as $item) {
                if (($item['name'] ?? '') === 'LearnedInfo') {
                    $info = $item['caption'];
                }
            }
        }
        $this->assertStringContainsString('320', $info);
    }

    public function testLearningCanBeSwitchedOff(): void
    {
        $lux = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        SetValue($lux, 30000.0);
        AC_SetLoggingStatus($this->archive, $lux, true);
        AC_AddLoggedValues($this->archive, $lux, [['TimeStamp' => strtotime('today 00:00'), 'Value' => 400.0]]);
        $id = $this->instance(['BrightnessVariable' => $lux, 'BrightnessThreshold' => 75.0, 'BrightnessAuto' => false, 'AlarmModules' => false]);
        HOYM_Update($id);
        $this->assertEqualsWithDelta(75.0, $this->threshold($id), 0.01);
    }

    public function testWrongPasswordNeverUsesLegacyLogin(): void
    {
        $id = $this->instance(['Password' => 'falsch']);
        HOYM_Update($id);
        $this->assertSame(201, IPS_GetInstance($id)['InstanceStatus']);
        $this->assertSame(0, $this->countRequests('/iam/pub/0/auth/login'));
    }

    // ------------------------------------------------------------------ Hilfen

    private function instance(array $properties = []): int
    {
        $id = IPS_CreateInstance(self::MODULE);
        $properties = array_merge(['Username' => 'test@x.de', 'Password' => 'Geheim123!'], $properties);
        foreach ($properties as $name => $value) {
            IPS_SetProperty($id, $name, $value);
        }
        IPS_ApplyChanges($id);
        @unlink(HOYMILES_FAKE_STATE . '/requests.log');
        return $id;
    }

    private function tileData(int $id): array
    {
        $html = IPS\InstanceManager::getInstanceInterface($id)->GetVisualizationTile();
        $last = substr($html, strrpos($html, 'handleMessage('));
        $this->assertSame(1, preg_match('/^handleMessage\((".*")\);<\/script>$/s', $last, $m));
        return json_decode(json_decode($m[1]), true);
    }

    private function tileBackgroundData(int $id): array
    {
        $html = IPS\InstanceManager::getInstanceInterface($id)->GetVisualizationTile();
        $this->assertSame(1, preg_match('/handleMessage\((".*?")\);handleMessage/s', $html, $m));
        return json_decode(json_decode($m[1]), true)['background'];
    }

    private function threshold(int $id): float
    {
        $module = IPS\InstanceManager::getInstanceInterface($id);
        $method = new ReflectionMethod($module, 'brightnessThreshold');
        $method->setAccessible(true);
        return $method->invoke($module);
    }

    private function silent(callable $fn): void
    {
        ob_start();
        $fn();
        ob_end_clean();
    }

    private function requestBodies(string $path): array
    {
        $bodies = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents(HOYMILES_FAKE_STATE . '/requests.log'))) as $line) {
            [$p, $json] = explode(' ', $line, 2);
            if ($p === $path) {
                $bodies[] = json_decode($json, true);
            }
        }
        return $bodies;
    }

    private function sources(array $storage, array $unused = []): string
    {
        $rows = [];
        foreach ([1, 2, 3, 4] as $p) {
            $source = in_array("PV$p", $storage, true) ? 'storage' : (in_array("PV$p", $unused, true) ? 'unused' : 'panel');
            $rows[] = ['Ident' => "PV$p", 'Input' => "PV $p", 'Source' => $source];
        }
        return json_encode($rows);
    }

    private function v(int $id, string $ident)
    {
        return GetValue(IPS_GetObjectIDByIdent($ident, $id));
    }

    private function requests(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents(HOYMILES_FAKE_STATE . '/requests.log')));
        return array_values(array_map(static function (string $line): string {
            return explode(' ', $line)[0];
        }, $lines));
    }

    private function countRequests(string $needle): int
    {
        return count(array_filter($this->requests(), static function (string $path) use ($needle): bool {
            return strpos($path, $needle) !== false;
        }));
    }

    private function timerMinutes(int $id): int
    {
        $module = IPS\InstanceManager::getInstanceInterface($id);
        $method = new ReflectionMethod($module, 'GetTimerInterval');
        $method->setAccessible(true);
        return intdiv($method->invoke($module, 'UpdateTimer') + 1000, 60000);
    }

    private function readAttribute(int $id, string $name): string
    {
        $module = IPS\InstanceManager::getInstanceInterface($id);
        $method = new ReflectionMethod($module, 'ReadAttributeString');
        $method->setAccessible(true);
        return $method->invoke($module, $name);
    }
}
