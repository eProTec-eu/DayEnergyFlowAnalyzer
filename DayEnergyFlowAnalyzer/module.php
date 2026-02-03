<?php
declare(strict_types=1);

class DayEnergyFlowAnalyzer extends IPSModule
{
    /* Optional: Minimalformular zum Testen
    public function GetConfigurationForm()
    {
        return '{"elements":[{"type":"Label","caption":"Form-Minimaltest: OK"}],"actions":[]}';
    }
    */

    public function Create()
    {
        parent::Create();

        // Quellen & Flags
        $this->RegisterPropertyInteger('ArchiveControlID', 0);

        $this->RegisterPropertyInteger('VarPV', 0);
        $this->RegisterPropertyBoolean('PVIsCounter', false);

        $this->RegisterPropertyInteger('VarLoad', 0);
        $this->RegisterPropertyBoolean('LoadIsCounter', false);

        $this->RegisterPropertyInteger('VarHPHeat', 0);
        $this->RegisterPropertyBoolean('HPHeatIsCounter', false);

        $this->RegisterPropertyInteger('VarHPDHW', 0);
        $this->RegisterPropertyBoolean('HPDHWIsCounter', false);

        $this->RegisterPropertyInteger('VarHP', 0);
        $this->RegisterPropertyBoolean('HPIsCounter', false);

        $this->RegisterPropertyInteger('VarImport', 0);
        $this->RegisterPropertyBoolean('ImportIsCounter', false);

        $this->RegisterPropertyInteger('VarExport', 0);
        $this->RegisterPropertyBoolean('ExportIsCounter', false);

        $this->RegisterPropertyInteger('VarSOC', 0);

        // Wärmemengenzähler (Heizen & WW)
        $this->RegisterPropertyInteger('VarHeatEnergy', 0);
        $this->RegisterPropertyBoolean('HeatEnergyIsCounter', false);

        $this->RegisterPropertyInteger('VarDHWEnergy', 0);
        $this->RegisterPropertyBoolean('DHW_EnergyIsCounter', false);

        $this->RegisterPropertyInteger('StepMinutes', 15);

        // Zeitbereich
        $this->RegisterPropertyString('TimeRangeMode', 'relative');
        $this->RegisterPropertyInteger('RelAmount', 3);
        $this->RegisterPropertyString('RelUnit', 'days');
        $this->RegisterPropertyBoolean('UseNowAsEnd', true);
        $this->RegisterPropertyString('FromDateTime', '');
        $this->RegisterPropertyString('ToDateTime', '');
        $this->RegisterPropertyBoolean('AlignToMinute', true);

        // Komfort-Parameter
        $this->RegisterPropertyFloat('CapKWh', 10.0);
        $this->RegisterPropertyFloat('EtaC', 0.95);
        $this->RegisterPropertyFloat('EtaD', 0.95);
        $this->RegisterPropertyFloat('ChargeKW', 3.0);
        $this->RegisterPropertyFloat('DischargeKW', 3.0);
        $this->RegisterPropertyBoolean('ReturnDetails', true);

        // Freies JSON
        $this->RegisterPropertyString('ParamsJSON', '');

        // Ergebnisvariablen
        $this->RegisterVariableString('TargetDate', 'Zieldatum');
        $this->RegisterVariableFloat('HP_kWh', 'WP-Energie [kWh]');
        $this->RegisterVariableFloat('PV_to_WP_total_kWh', 'PV→WP gesamt [kWh]');
        $this->RegisterVariableFloat('Import_real_kWh', 'Import mit WP [kWh]');
        $this->RegisterVariableFloat('Import_no_wp_kWh', 'Import ohne WP (Ggszen.) [kWh]');
        $this->RegisterVariableFloat('WP_import_change_signed_kWh', 'Netto-Importänderung [kWh]');
        $this->RegisterVariableString('ResultJSON', 'Analyseergebnis (JSON)');
        $this->RegisterVariableString('ResultDetailsJSON', 'Intervall-Details (JSON)');

        // COP-Variablen (mit Historie)
        $this->RegisterVariableFloat('COP_WP_Total', 'Arbeitszahl WP gesamt');
        $this->RegisterVariableFloat('COP_WP_Heating', 'Arbeitszahl Heizen');
        $this->RegisterVariableFloat('COP_WP_DHW', 'Arbeitszahl Warmwasser');

        // Backfill-Parameter
        $this->RegisterPropertyInteger('BackfillCategoryID', 0);
        $this->RegisterPropertyString('BF_FromDate', '');
        $this->RegisterPropertyString('BF_ToDate', '');
        $this->RegisterAttributeString('BF_TimeRangeMode', '');
        $this->RegisterAttributeString('BF_FromDateTime', '');
        $this->RegisterAttributeString('BF_ToDateTime', '');
        $this->RegisterAttributeBoolean('BF_AlignToMinute', false);
        $this->RegisterPropertyBoolean('DailyBackfillEnabled', true);
        $this->RegisterPropertyString('DailyStartTime', '02:30');

        // Timer für täglichen Backfill
        $this->RegisterTimer('DEFA_DailyBackfillTimer', 0, 'DEFA_RunDailyBackfill($_IPS["TARGET"]);');

        // Dashboard-Properties
        $this->RegisterPropertyInteger('Dash_HeatWMZ', 0);
        $this->RegisterPropertyInteger('Dash_DHW_WMZ', 0);
        $this->RegisterPropertyInteger('Dash_HeatPower', 0);
        $this->RegisterPropertyInteger('Dash_DHW_Power', 0);
        $this->RegisterPropertyInteger('Dash_TotalPower', 0);
        $this->RegisterPropertyInteger('Dash_NettoEffect', 0);
        $this->RegisterPropertyInteger('Dash_COP_Total', 0);
        $this->RegisterPropertyInteger('Dash_COP_Heat', 0);
        $this->RegisterPropertyInteger('Dash_COP_DHW', 0);
        $this->RegisterPropertyInteger('Dash_PV', 0);
        $this->RegisterPropertyInteger('Dash_Consumption', 0);
        $this->RegisterPropertyInteger('Dash_GridBuy', 0);
        $this->RegisterPropertyInteger('Dash_HeaterHours', 0);
        $this->RegisterPropertyInteger('Dash_DHWHours', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Daily Backfill Timer setzen
        $enabled = $this->ReadPropertyBoolean('DailyBackfillEnabled');
        $timeStr = $this->ReadPropertyString('DailyStartTime') ?: '02:30';
        $next = $this->calcNextRun($timeStr);
        if ($enabled && $next > 0) {
            $this->SetTimerInterval('DEFA_DailyBackfillTimer', max(0, ($next - time()) * 1000));
        } else {
            $this->SetTimerInterval('DEFA_DailyBackfillTimer', 0);
        }
    }

    public function Analyze()
    {
        try {
            $acID = $this->ReadPropertyInteger('ArchiveControlID');
            if ($acID <= 0 || !IPS_InstanceExists($acID)) {
                throw new InvalidArgumentException('Bitte Archive Control auswählen.');
            }

            // Zeitmodus (Backfill-Attribute überschreiben temporär die Properties)
            $mode  = $this->ReadAttributeString('BF_TimeRangeMode') ?: $this->ReadPropertyString('TimeRangeMode');
            $align = $this->ReadAttributeBoolean('BF_AlignToMinute') ?? $this->ReadPropertyBoolean('AlignToMinute');

            // Quellen lesen
            $varPV   = $this->ReadPropertyInteger('VarPV');
            $pvC     = $this->ReadPropertyBoolean('PVIsCounter');
            $varLoad = $this->ReadPropertyInteger('VarLoad');
            $ldC     = $this->ReadPropertyBoolean('LoadIsCounter');

            $varHPH  = $this->ReadPropertyInteger('VarHPHeat');
            $hphC    = $this->ReadPropertyBoolean('HPHeatIsCounter');
            $varHPW  = $this->ReadPropertyInteger('VarHPDHW');
            $hpwC    = $this->ReadPropertyBoolean('HPDHWIsCounter');
            $varHP   = $this->ReadPropertyInteger('VarHP');
            $hpC     = $this->ReadPropertyBoolean('HPIsCounter');

            $varImp  = $this->ReadPropertyInteger('VarImport');
            $impC    = $this->ReadPropertyBoolean('ImportIsCounter');
            $varExp  = $this->ReadPropertyInteger('VarExport');
            $expC    = $this->ReadPropertyBoolean('ExportIsCounter');
            $varSOC  = $this->ReadPropertyInteger('VarSOC');

            // Wärmemenge Heizen/WW
            $varHeat = $this->ReadPropertyInteger('VarHeatEnergy');
            $heatC   = $this->ReadPropertyBoolean('HeatEnergyIsCounter');
            $varDHW  = $this->ReadPropertyInteger('VarDHWEnergy');
            $dhwC    = $this->ReadPropertyBoolean('DHW_EnergyIsCounter');

            foreach ([['PV',$varPV],['Load',$varLoad],['Import',$varImp]] as [$label,$vid]) {
                if ($vid <= 0 || !IPS_VariableExists($vid)) {
                    throw new InvalidArgumentException("Fehlende Variable: $label");
                }
            }

            $hasDualHP  = ($varHPH > 0 && IPS_VariableExists($varHPH)) && ($varHPW > 0 && IPS_VariableExists($varHPW));
            $hasSingleHP= ($varHP  > 0 && IPS_VariableExists($varHP));
            if (!$hasDualHP && !$hasSingleHP) {
                throw new InvalidArgumentException('Bitte entweder WP Heizen + Warmwasser ODER WP Gesamt angeben.');
            }
            if ($varExp > 0 && !IPS_VariableExists($varExp)) $varExp = 0;
            if ($varSOC > 0 && !IPS_VariableExists($varSOC)) $varSOC = 0;
            if ($varHeat > 0 && !IPS_VariableExists($varHeat)) $varHeat = 0;
            if ($varDHW  > 0 && !IPS_VariableExists($varDHW))  $varDHW  = 0;

            // Zeitfenster bestimmen
            $tz = new DateTimeZone(date_default_timezone_get());
            if ($mode === 'absolute') {
                $json = $this->ReadAttributeString('BF_FromDateTime');
                if ($json === '' || $json === null) { $json = $this->ReadPropertyString('FromDateTime'); }
                $fromObj = $json !== '' ? json_decode($json, true) : null;

                $json = $this->ReadAttributeString('BF_ToDateTime');
                if ($json === '' || $json === null) { $json = $this->ReadPropertyString('ToDateTime'); }
                $toObj = $json !== '' ? json_decode($json, true) : null;

                $okF = is_array($fromObj) && (($fromObj['year'] ?? 0) > 0);
                $okT = is_array($toObj)   && (($toObj['year']   ?? 0) > 0);
                if (!$okF || !$okT) throw new InvalidArgumentException('Bitte „Von/Bis“ gültig setzen (absoluter Modus).');

                $from = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                    (int)$fromObj['year'],(int)$fromObj['month'],(int)$fromObj['day'],(int)($fromObj['hour']??0),(int)($fromObj['minute']??0),(int)($fromObj['second']??0)
                ), $tz);
                $to   = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                    (int)$toObj['year'],(int)$toObj['month'],(int)$toObj['day'],(int)($toObj['hour']??0),(int)($toObj['minute']??0),(int)($toObj['second']??0)
                ), $tz);
                if ($to <= $from) throw new InvalidArgumentException('„Bis“ muss nach „Von“ liegen (absoluter Modus).');
            } else {
                $amount = max(1, (int)$this->ReadPropertyInteger('RelAmount'));
                $unit   = $this->ReadPropertyString('RelUnit') ?: 'days';
                $now    = new DateTimeImmutable('now', $tz);
                $to     = $this->ReadPropertyBoolean('UseNowAsEnd') ? $now : $now->setTime((int)date('H'), (int)date('i'), 0);
                $from   = $unit === 'hours' ? $to->modify('-'.$amount.' hours') : $to->modify('-'.$amount.' days');
            }
            if ($align) {
                $from = $from->setTime((int)$from->format('H'), (int)$from->format('i'), 0);
                $to   = $to  ->setTime((int)$to  ->format('H'), (int)$to  ->format('i'), 0);
            }

            $stepMin = max(1, (int)$this->ReadPropertyInteger('StepMinutes'));
            $grid = $this->buildGrid($from, $to, $stepMin); // G0..Gn

            // Historie (Counter -> Delta)
            $pvRaw   = $this->readHistory($acID, $varPV , $from, $to, $pvC);
            $loadRaw = $this->readHistory($acID, $varLoad, $from, $to, $ldC);
            if ($hasDualHP) {
                $hpHRaw = $this->readHistory($acID, $varHPH, $from, $to, $hphC);
                $hpWRaw = $this->readHistory($acID, $varHPW, $from, $to, $hpwC);
                $hpRaw  = $this->sumSeriesPairs($hpHRaw, $hpWRaw);
            } else {
                $hpRaw  = $this->readHistory($acID, $varHP , $from, $to, $hpC);
                $hpHRaw = [];
                $hpWRaw = [];
            }
            $impRaw  = $this->readHistory($acID, $varImp, $from, $to, $impC);
            $expRaw  = $varExp ? $this->readHistory($acID, $varExp, $from, $to, $expC) : [];
            $socRaw  = $varSOC ? $this->readHistorySOC($acID, $varSOC, $from, $to) : [];
            $heatRaw = $varHeat ? $this->readHistory($acID, $varHeat, $from, $to, $heatC) : [];
            $dhwRaw  = $varDHW  ? $this->readHistory($acID, $varDHW , $from, $to, $dhwC ) : [];

            // Resampling
            $pvR   = $this->resampleEnergyBuckets($pvRaw  , $grid);
            $loadR = $this->resampleEnergyBuckets($loadRaw, $grid);
            $hpR   = $this->resampleEnergyBuckets($hpRaw  , $grid);
            $impR  = $this->resampleEnergyBuckets($impRaw , $grid);
            $expR  = $varExp ? $this->resampleEnergyBuckets($expRaw, $grid) : array_fill(0, count($grid), 0.0);
            $heatR = $this->resampleEnergyBuckets($heatRaw, $grid);
            $dhwR  = $this->resampleEnergyBuckets($dhwRaw , $grid);
            $socR  = $varSOC ? $this->resampleStateToGrid($socRaw, $grid) : array_fill(0, count($grid), null);

            // Komfort + JSON Params
            $params = [
                'cap_kwh'   => (float)$this->ReadPropertyFloat('CapKWh'),
                'eta_c'     => (float)$this->ReadPropertyFloat('EtaC'),
                'eta_d'     => (float)$this->ReadPropertyFloat('EtaD'),
                'charge_kw' => (float)$this->ReadPropertyFloat('ChargeKW'),
                'discharge_kw' => (float)$this->ReadPropertyFloat('DischargeKW'),
                'return_details' => $this->ReadPropertyBoolean('ReturnDetails'),
                'tz' => date_default_timezone_get()
            ];
            $pjson = trim($this->ReadPropertyString('ParamsJSON'));
            if ($pjson !== '') {
                try {
                    $u = json_decode($pjson, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($u)) $params = array_merge($params, $u);
                } catch (\Throwable $e) {
                    $this->SendDebug('ParamsJSON','JSON-Fehler: '.$e->getMessage(),0);
                }
            }

            // Intervall-Rows aus Buckets i=1..n-1 (Ende grid[i])
            $rows = [];
            for ($i=1; $i<count($grid); $i++) {
                $rows[] = [
                    't' => date('Y-m-d H:i:s', $grid[$i]),
                    'dt_h' => ($grid[$i]-$grid[$i-1])/3600.0,
                    'pv' => $pvR[$i],
                    'load' => $loadR[$i],
                    'hp' => $hpR[$i],
                    'import' => $impR[$i],
                    'export' => $expR[$i],
                    'soc' => $socR[$i]===null? null : (float)$socR[$i]
                ];
            }

            $result = $this->analyzeDay_Roll_WithBuckets($rows, $params, $grid);

            // Sanity: PV→WP nicht größer als HP
            if ($result['pv_to_wp_total_kwh'] > $result['hp_kwh'] + 1e-6) {
                $sumParts = max(1e-9, $result['pv_to_wp_direct_kwh'] + $result['bat_pv_to_wp_kwh']);
                $result['pv_to_wp_total_kwh'] = $result['hp_kwh'];
                $result['pv_to_wp_direct_kwh'] = round($result['pv_to_wp_direct_kwh'] * $result['hp_kwh'] / $sumParts, 3);
                $result['bat_pv_to_wp_kwh']    = round($result['hp_kwh'] - $result['pv_to_wp_direct_kwh'], 3);
            }

            // Core-Index für Zieltag bestimmen
            $tzName = (string)($params['tz'] ?? date_default_timezone_get());
            $tz2 = new DateTimeZone($tzName);
            $midIndex = intdiv(count($grid), 2);
            $midTs    = $grid[$midIndex];
            $targetDT = (new DateTimeImmutable('@'.$midTs))->setTimezone($tz2);
            $targetDate = $targetDT->format('Y-m-d');
            $dayStartTs = (new DateTimeImmutable($targetDate.' 00:00:00', $tz2))->getTimestamp();
            $dayEndTs   = (new DateTimeImmutable($targetDate.' 23:59:59', $tz2))->getTimestamp();

            $coreIdx = [];
            foreach ($rows as $i => $r) {
                $ts = $this->ts_from_any($r['t'], $tz2);
                if ($ts >= $dayStartTs && $ts <= $dayEndTs) $coreIdx[] = $i;
            }
            if (empty($coreIdx)) {
                throw new RuntimeException('Keine Intervalle im Zieltag '.$targetDate.' gefunden.');
            }

            // Tages-Summen Wärme & WP getrennt
            $heatSum = 0.0; $dhwSum = 0.0;
            foreach ($coreIdx as $i) { $heatSum += $heatR[$i] ?? 0.0; $dhwSum += $dhwR[$i] ?? 0.0; }

            $hpSum = (float)$result['hp_kwh'];
            $hpHeatSum = 0.0; $hpDHWSum = 0.0;
            if ($hasDualHP) {
                $hpHR = $this->resampleEnergyBuckets($hpHRaw, $grid);
                $hpWR = $this->resampleEnergyBuckets($hpWRaw, $grid);
                foreach ($coreIdx as $i) { $hpHeatSum += $hpHR[$i] ?? 0.0; $hpDHWSum += $hpWR[$i] ?? 0.0; }
            }

            // COPs
            $cop_total = ($hpSum     > 0) ? round(($heatSum + $dhwSum) / $hpSum, 3) : 0.0;
            $cop_heat  = ($hpHeatSum > 0) ? round(($heatSum) / $hpHeatSum, 3)      : 0.0;
            $cop_dhw   = ($hpDHWSum  > 0) ? round(($dhwSum)  / $hpDHWSum , 3)      : 0.0;

            // Ergebnisse schreiben
            $jsonOptions = JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
            SetValueString($this->GetIDForIdent('TargetDate'), (string)$result['target_date']);
            SetValueFloat($this->GetIDForIdent('HP_kWh'), (float)$result['hp_kwh']);
            SetValueFloat($this->GetIDForIdent('PV_to_WP_total_kWh'), (float)$result['pv_to_wp_total_kwh']);
            SetValueFloat($this->GetIDForIdent('Import_real_kWh'), (float)$result['import_real_kwh']);
            SetValueFloat($this->GetIDForIdent('Import_no_wp_kWh'), (float)$result['import_no_wp_kwh']);
            SetValueFloat($this->GetIDForIdent('WP_import_change_signed_kWh'), (float)$result['wp_import_change_signed_kwh']);
            SetValueString($this->GetIDForIdent('ResultJSON'), json_encode($result, $jsonOptions));
            if (isset($result['details']) && is_array($result['details'])) {
                SetValueString($this->GetIDForIdent('ResultDetailsJSON'), json_encode($result['details'], $jsonOptions));
            }

            SetValueFloat($this->GetIDForIdent('COP_WP_Total'), $cop_total);
            SetValueFloat($this->GetIDForIdent('COP_WP_Heating'), $cop_heat);
            SetValueFloat($this->GetIDForIdent('COP_WP_DHW'), $cop_dhw);
        }
        catch (\Throwable $e) {
            $this->SendDebug('Analyze/Error', $e->getMessage(), 0);
            throw $e;
        }
    }

    public function ExportDetails()
    {
        $json = GetValueString($this->GetIDForIdent('ResultDetailsJSON'));
        if ($json === '') { echo 'about:blank'; return; }
        $rows = json_decode($json, true);
        if (!is_array($rows) || empty($rows)) { echo 'about:blank'; return; }

        $keys = array_keys($rows[0]);
        // CSV mit Semikolon als Trenner (Excel-freundlich in DE)
        $csv  = implode(";", $keys) . "\n";
        foreach ($rows as $r) {
            $line = [];
            foreach ($keys as $k) { $line[] = $r[$k] ?? ''; }
            $csv .= implode(";", $line) . "\n";
        }

        // In WebFront-User-Temp speichern (öffentlich erreichbar)
        $baseDir = IPS_GetKernelDir() . 'user/';
        $tempDir = $baseDir . 'temp/';
        if (!is_dir($tempDir)) { mkdir($tempDir, 0777, true); }
        $filename = 'defa_export_' . time() . '.csv';
        $fullPath = $tempDir . $filename;
        file_put_contents($fullPath, $csv);

        // IP ermitteln
        $host = '127.0.0.1';
        $net = Sys_GetNetworkInfo();
        foreach ($net as $iface) { if (!empty($iface['IP']) && $iface['IP'] !== '127.0.0.1') { $host = $iface['IP']; break; } }
        $url = 'http://' . $host . ':3777/user/temp/' . $filename;
        echo $url;
    }

    public function ShowHelp()
    {
        $f = __DIR__.'/../README.md';
        $t = '';
        if (file_exists($f)) {
            $md = @file_get_contents($f);
            if ($md !== false && $md !== '') {
                $t = str_replace(["\r\n","\r"],"\n", $md);
                $t = preg_replace('/^###\s+/m','▶︎ ',$t);
                $t = preg_replace('/^##\s+/m','◆ ',$t);
                $t = preg_replace('/^#\s+/m','■ ',$t);
                $t = preg_replace('/\*\*(.*?)\*\*/s','$1',$t);
                $t = preg_replace_callback('/```([\s\S]*?)```/m', function($m){ return "\n".trim($m[1])."\n"; }, $t);
                $t = preg_replace('/^\s*[\-*]\s+/m','• ',$t);
            } else { $t = 'README.md ist leer oder konnte nicht gelesen werden.'; }
        } else { $t = 'README.md nicht gefunden.'; }
        $this->UpdateFormField('HelpHtml','caption',$t);
        return '';
    }

    public function OpenHelp()
    {
        $src = __DIR__ . '/docs/help.html';
        if (!file_exists($src)) { echo 'about:blank'; return; }
        $html = @file_get_contents($src);
        if ($html === false || $html === '') { echo 'about:blank'; return; }

        $baseDir = IPS_GetKernelDir() . 'user/';
        $tempDir = $baseDir . 'temp/';
        if (!is_dir($tempDir)) { mkdir($tempDir, 0777, true); }
        $filename = 'defa_help_' . time() . '.html';
        $fullPath = $tempDir . $filename;
        file_put_contents($fullPath, $html);

        $host = '127.0.0.1';
        $netInfo = Sys_GetNetworkInfo();
        foreach ($netInfo as $iface) { if (!empty($iface['IP']) && $iface['IP'] !== '127.0.0.1') { $host = $iface['IP']; break; } }
        $url = 'http://' . $host . ':3777/user/temp/' . $filename;
        echo $url;
    }

    public function BackfillRange(): void
    {
        try {
            $fromObj = $this->ReadPropertyString('BF_FromDate');
            $toObj   = $this->ReadPropertyString('BF_ToDate');
            $catID   = $this->ReadPropertyInteger('BackfillCategoryID');
            if ($catID <= 0 || !IPS_CategoryExists($catID)) { $this->uiLog('Bitte Ziel-Kategorie auswählen.'); return; }
            $from = $this->dateFromSelect($fromObj);
            $to   = $this->dateFromSelect($toObj);
            if ($from === null || $to === null || $to < $from) { $this->uiLog('Ungültiger Zeitraum.'); return; }
            [$written, $days] = $this->backfillRangeEngine($from, $to, $catID, false);
            $this->uiLog("Backfill abgeschlossen: $written Punkte, $days Tage.");
        } catch (\Throwable $e) { $this->uiLog('Fehler: '.$e->getMessage()); throw $e; }
    }

    public function RunDailyBackfillNow(): void
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $y  = (new DateTimeImmutable('yesterday', $tz))->setTime(0,0,0);
        $catID = $this->ReadPropertyInteger('BackfillCategoryID');
        if ($catID <= 0 || !IPS_CategoryExists($catID)) { $this->uiLog('Ziel-Kategorie fehlt.'); return; }
        [$written, $days] = $this->backfillRangeEngine($y, $y, $catID, false);
        $this->uiLog("Tages-Backfill (gestern) fertig: $written Punkte.");
    }

    public function RunDailyBackfill(int $ID): void
    {
        $next = $this->calcNextRun($this->ReadPropertyString('DailyStartTime') ?: '02:30');
        $this->SetTimerInterval('DEFA_DailyBackfillTimer', max(0, ($next - time()) * 1000));
        $tz = new DateTimeZone(date_default_timezone_get());
        $y  = (new DateTimeImmutable('yesterday', $tz))->setTime(0,0,0);
        $catID = $this->ReadPropertyInteger('BackfillCategoryID');
        if ($catID <= 0) return;
        [$written, $days] = $this->backfillRangeEngine($y, $y, $catID, false);
        $this->uiLog("Daily Backfill abgeschlossen: $written Punkte.");
    }

    public function OpenDashboard(): string
    {
        $host = $this->getHost();

        // Verzeichnisse (Symcon, portabel)
        $kernelDir = IPS_GetKernelDir();         // z. B. /var/lib/symcon/ (OS-abhängig, mit abschließendem Slash)
        $baseDir   = $kernelDir . 'user/';       // …/user/
        $tempDir   = $baseDir . 'temp/';         // …/user/temp/
        $year = date("Y");
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException("Verzeichnis konnte nicht erstellt werden: {$baseDir}");
        }
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new \RuntimeException("Verzeichnis konnte nicht erstellt werden: {$tempDir}");
        }

        // Ziel-Datei unter /user/
        $fileName = 'defa_dashboard.php';
        $filePath = $baseDir . $fileName;

        // Inhalt der Seite (immer NEU generiert → aktuelle Daten)
        $content = $this->buildDefaDashboardPhp($this->InstanceID);

        // Datei neu schreiben
        $bytes = file_put_contents($filePath, $content, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException("Konnte Datei nicht schreiben: {$filePath}");
        }
        @chmod($filePath, 0664);

        //URL zurückgeben (Konsole öffnet den Link automatisch, weil in form.json link:true + echo …)
        $url = "http://{$host}:3777/user/{$fileName}?year={$year}";
        return $url;
    }

    public function ExportDashboardPDF()
    {
        $host = $this->getHost();
        $url  = "http://{$host}:3777/user/defa_dashboard.php";

        $html = @file_get_contents($url);
        if (!$html) {
            echo "about:blank";
            return;
        }

        $pdfFile = IPS_GetKernelDir() . "user/temp/dashboard_" . time() . ".pdf";

        $python = <<<PY
        from reportlab.pdfgen import canvas
        from reportlab.lib.pagesizes import A4
        import textwrap

        html = r\"\"\"$html\"\"\"
        c = canvas.Canvas("$pdfFile", pagesize=A4)
        w, h = A4

        y = h - 40
        for line in textwrap.wrap(html, 120):
            if y < 40:
                c.showPage()
                y = h - 40
            c.drawString(40, y, line)
            y -= 14
        c.save()
        PY;

        IPS_RunScriptText($python);

        echo "http://{$host}:3777/user/temp/" . basename($pdfFile);
    }

    public function GenerateDiagrams()
    {
        $acID = $this->ReadPropertyInteger('ArchiveControlID');
        if ($acID <= 0 || !IPS_InstanceExists($acID)) {
            $this->SendDebug("GenerateDiagrams", "ArchiveControl fehlt/ungültig", 0);
            echo "[]";
            return;
        }

        // 1) Dash_* Properties lesen
        $vars = [
            "WMZ_Heizen"   => $this->ReadPropertyInteger('Dash_HeatWMZ'),
            "WMZ_WW"       => $this->ReadPropertyInteger('Dash_DHW_WMZ'),
            "WP_Heizen"    => $this->ReadPropertyInteger('Dash_HeatPower'),
            "WP_WW"        => $this->ReadPropertyInteger('Dash_DHW_Power'),
            "WP_Gesamt"    => $this->ReadPropertyInteger('Dash_TotalPower'),
            "Netto"        => $this->ReadPropertyInteger('Dash_NettoEffect'),
            "COP_Total"    => $this->ReadPropertyInteger('Dash_COP_Total'),
            "COP_Heizen"   => $this->ReadPropertyInteger('Dash_COP_Heat'),
            "COP_WW"       => $this->ReadPropertyInteger('Dash_COP_DHW'),
            "PV"           => $this->ReadPropertyInteger('Dash_PV'),
            "Haus"         => $this->ReadPropertyInteger('Dash_Consumption'),
            "Netz"         => $this->ReadPropertyInteger('Dash_GridBuy'),
            "Std_Heizen"   => $this->ReadPropertyInteger('Dash_HeaterHours'),
            "Std_WW"       => $this->ReadPropertyInteger('Dash_DHWHours'),
        ];

        // 2) Ungültige Variablen (0/nicht vorhanden) herausfiltern
        $valid = [];
        foreach ($vars as $key => $vid) {
            if ($vid > 0 && @IPS_VariableExists($vid)) {
                $valid[$key] = $vid;
            } else {
                $this->SendDebug("GenerateDiagrams", "Skip $key (id=$vid ungültig/nicht vorhanden)", 0);
            }
        }
        if (empty($valid)) {
            $this->SendDebug("GenerateDiagrams", "Keine gültigen Dash_* Variablen konfiguriert", 0);
            echo "[]";
            return;
        }

        // 3) Aggregations-Metadaten:
        //    --> Bis auf COP alles ZÄHLER (Summe), Laufzeiten = Stunden aus Avg*Duration/3600
        $meta = [
            // Zähler / Summen
            "WMZ_Heizen" => ["field" => "Sum",    "title" => "WMZ Heizen"],
            "WMZ_WW"     => ["field" => "Sum",    "title" => "WMZ Warmwasser"],
            "WP_Heizen"  => ["field" => "Sum",    "title" => "WP Heizen"],
            "WP_WW"      => ["field" => "Sum",    "title" => "WP Warmwasser"],
            "WP_Gesamt"  => ["field" => "Sum",    "title" => "WP Gesamt"],
            "Netto"      => ["field" => "Sum",    "title" => "Netto-Effekt"],
            "PV"         => ["field" => "Sum",    "title" => "PV"],
            "Haus"       => ["field" => "Sum",    "title" => "Hausverbrauch"],
            "Netz"       => ["field" => "Sum",    "title" => "Netzbezug"],

            // COP (keine Zähler): Monats-Durchschnitt
            "COP_Total"  => ["field" => "Avg",    "title" => "COP Gesamt (Ø)"],
            "COP_Heizen" => ["field" => "Avg",    "title" => "COP Heizen (Ø)"],
            "COP_WW"     => ["field" => "Avg",    "title" => "COP Warmwasser (Ø)"],

            // Laufzeitstunden (boolean geloggt): Stunden = Avg * Duration / 3600
            "Std_Heizen" => ["field" => "OnHours","title" => "WP-Heizstunden"],
            "Std_WW"     => ["field" => "OnHours","title" => "WP-WW-Stunden"],
        ];

        // Nur Meta für vorhandene Keys übernehmen
        $metaUsed = [];
        foreach ($valid as $k => $_) {
            $metaUsed[$k] = $meta[$k] ?? ["field" => "Sum", "title" => $k];
        }

        // 4) Jahre (aktuelles Jahr + zwei Vorjahre)
        $yearNow = (int)date("Y");
        $years   = [$yearNow, $yearNow - 1, $yearNow - 2];

        // 5) Robuste Monatsaggregation (immer Aggregationsstufe=Monat, Zukunfts-Schutz, 6→5-Param-Fallback)
        //    Caches zur Beschleunigung
        $loggedCache  = [];
        $aggTypeCache = [];

        $aggMonth = function (int $varId, string $field, int $y, int $m) use ($acID, &$loggedCache, &$aggTypeCache): float
        {
            // Zeitraum: kompletter Monat (bis 'jetzt' gekappt)
            $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $y, $m));
            $nextMonth  = strtotime(sprintf('%04d-%02d-01 00:00:00 +1 month', $y, $m));
            if ($monthStart === false || $nextMonth === false) {
                $this->SendDebug("GenerateDiagrams/aggMonth", "Ungültiges Datum y=$y m=$m", 0);
                return 0.0;
            }
            $now   = time();
            $start = $monthStart;
            $end   = min($nextMonth, $now);
            if ($start >= $end) {
                return 0.0; // kompletter Monat in der Zukunft
            }

            if (!@IPS_VariableExists($varId)) {
                $this->SendDebug("GenerateDiagrams/aggMonth", "Variable $varId existiert nicht", 0);
                return 0.0;
            }

            // Logging-Status (optional; cachen)
            $isLogged = true;
            if (function_exists('AC_GetLoggingStatus')) {
                if (array_key_exists($varId, $loggedCache)) {
                    $isLogged = $loggedCache[$varId];
                } else {
                    try {
                        $isLogged = (bool)@AC_GetLoggingStatus($acID, $varId);
                    } catch (\Throwable $e) {
                        $isLogged = true; // konservativer Fallback
                    }
                    $loggedCache[$varId] = $isLogged;
                }
                if (!$isLogged) {
                    $this->SendDebug("GenerateDiagrams/aggMonth", "Var $varId nicht geloggt → 0", 0);
                    return 0.0;
                }
            }

            // Aggregationstyp (0=Standard, 1=Zähler) – cachen
            $isCounter = false;
            if (array_key_exists($varId, $aggTypeCache)) {
                $isCounter = $aggTypeCache[$varId];
            } else {
                try {
                    if (function_exists('AC_GetAggregationType')) {
                        $isCounter = (@AC_GetAggregationType($acID, $varId) === 1);
                    }
                } catch (\Throwable $e) { /* ignore */ }
                $aggTypeCache[$varId] = $isCounter;
            }

            // Monatsbucket holen (Aggregationsstufe=3), limit=1
            $rows = null;
            try {
                $rows = @AC_GetAggregatedValues($acID, $varId, 3 /* Monat */, $start, $end, 1);
            } catch (\ArgumentCountError $e) {
                $rows = @AC_GetAggregatedValues($acID, $varId, 3 /* Monat */, $start, $end);
            } catch (\Throwable $e) {
                try {
                    $rows = @AC_GetAggregatedValues($acID, $varId, 3 /* Monat */, $start, $end);
                } catch (\Throwable $e2) {
                    $this->SendDebug("GenerateDiagrams/aggMonth", "AC_GetAggregatedValues Fallback Exception: ".$e2->getMessage(), 0);
                    return 0.0;
                }
            }
            if (!is_array($rows) || count($rows) === 0) {
                return 0.0;
            }
            $r = $rows[0]; // Monat → genau 1 Bucket im Zeitraum

            $f = strtolower((string)$field);

            // Laufzeitstunden: Stunden = Avg * Duration / 3600
            if ($f === 'onhours' || $f === 'hours') {
                if (array_key_exists('Avg', $r) && is_numeric($r['Avg']) &&
                    array_key_exists('Duration', $r) && is_numeric($r['Duration'])) {
                    return (float)$r['Avg'] * ((float)$r['Duration'] / 3600.0);
                }
                return 0.0;
            }

            if ($f === 'sum') {
                // Zähler: bevorzugt 'Sum', Fallback 'Avg' (Summe der Deltas)
                if ($isCounter && array_key_exists('Sum', $r) && is_numeric($r['Sum'])) {
                    return (float)$r['Sum'];
                }
                if (array_key_exists('Avg', $r) && is_numeric($r['Avg'])) {
                    return (float)$r['Avg'];
                }
                return 0.0;
            }

            if ($f === 'avg') {
                if (array_key_exists('Avg', $r) && is_numeric($r['Avg'])) {
                    return (float)$r['Avg'];
                }
                return 0.0;
            }

            // Weitere Felder/Notfälle
            if (array_key_exists($field, $r) && is_numeric($r[$field])) {
                return (float)$r[$field];
            }

            return 0.0;
        };

        // 6) Datenmatrix aufbauen
        $data   = [];   // $data["PV"]["2026"] = [12 Monatswerte]
        $titles = [];
        foreach ($valid as $key => $vid) {
            $cfg          = $metaUsed[$key];
            $field        = (string)$cfg["field"];
            $titles[$key] = (string)$cfg["title"];

            foreach ($years as $y) {
                $vals = [];
                for ($m = 1; $m <= 12; $m++) {
                    // zukünftige Monate des laufenden Jahres direkt 0
                    if ($y === (int)date('Y') && $m > (int)date('n')) {
                        $vals[] = 0.0;
                        continue;
                    }
                    $vals[] = round($aggMonth($vid, $field, $y, $m), 6);
                }
                $data[$key][(string)$y] = $vals;
            }
        }

        // Prüfen, ob irgendwo Daten > 0 existieren
        $hasData = false;
        foreach ($data as $perYear) {
            foreach ($perYear as $series) {
                if (array_sum($series) > 0) { $hasData = true; break 2; }
            }
        }
        if (!$hasData) {
            $this->SendDebug("GenerateDiagrams", "Alle Serien leer (Summen=0) – gebe [] zurück", 0);
            echo "[]";
            return;
        }

        // 7) Verzeichnisse vorbereiten
        $kernelDir = IPS_GetKernelDir();             // z. B. /var/lib/symcon/
        $baseDir   = $kernelDir . 'user/';           // .../user/
        $tempDir   = $baseDir . 'temp/';             // .../user/temp/
        if (!is_dir($tempDir)) { @mkdir($tempDir, 0777, true); }

        // 8) SVG-Renderer (wie bei Dir)
        $makeSVG = function (string $key, string $title, array $perYear, string $filePath)
        {
            $width = 820; $height = 340;
            $marginL = 60; $marginR = 20; $marginT = 35; $marginB = 40;
            $plotW = $width - $marginL - $marginR;
            $plotH = $height - $marginT - $marginB;
            $months = range(1, 12);

            // Max ermitteln
            $maxV = 0.0;
            foreach ($perYear as $vals) {
                $mv = max($vals);
                if ($mv > $maxV) $maxV = $mv;
            }
            if ($maxV <= 0) $maxV = 1.0;

            // "Schöne" Obergrenze (sanft)
            $power10 = pow(10, floor(log10($maxV)));
            $niceMax = ceil($maxV / $power10) * $power10;
            if ($niceMax / $maxV > 4) { $niceMax = $maxV; } // nicht übertreiben
            $yMax = $niceMax;
            $yTicks = 5;
            $yStep = $yMax / $yTicks;

            $colors = [
                "#1f77b4", "#ff7f0e", "#2ca02c", "#d62728", "#9467bd"
            ];

            // Skalierungsfunktionen
            $sx = function($m) use ($months, $plotW, $marginL) {
                $i = $m - 1;
                $dx = (count($months) > 1) ? $plotW / (count($months) - 1) : 0;
                return $marginL + $i * $dx;
            };
            $sy = function($v) use ($yMax, $plotH, $marginT) {
                $v = max(0.0, min($yMax, $v));
                $p = ($yMax > 0) ? ($v / $yMax) : 0;
                return $marginT + $plotH * (1.0 - $p);
            };

            ob_start();
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">' . "\n";
            echo '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="#ffffff"/>' . "\n";

            $titleSafe = htmlspecialchars((string)$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<text x="'.($width/2).'" y="'.($marginT-12).'" text-anchor="middle" font-size="16" font-family="sans-serif">'.$titleSafe.'</text>'."\n";

            // Achsen
            $x0 = $marginL; $y0 = $marginT + $plotH; $x1 = $marginL + $plotW; $y1 = $marginT;
            echo '<line x1="'.$x0.'" y1="'.$y0.'" x2="'.$x1.'" y2="'.$y0.'" stroke="#333" stroke-width="1"/>'."\n";
            echo '<line x1="'.$x0.'" y1="'.$y0.'" x2="'.$x0.'" y2="'.$y1.'" stroke="#333" stroke-width="1"/>'."\n";

            // Gitter + y-Ticks
            for ($i = 0; $i <= $yTicks; $i++) {
                $yv = $i * $yStep;
                $yy = $sy($yv);
                $label = rtrim(rtrim(number_format($yv, 2, ',', ''), '0'), ',');
                echo '<line x1="'.$x0.'" y1="'.$yy.'" x2="'.$x1.'" y2="'.$yy.'" stroke="#ddd" stroke-width="1"/>'."\n";
                echo '<text x="'.($x0-8).'" y="'.($yy+4).'" text-anchor="end" font-size="12" font-family="sans-serif">'.$label.'</text>'."\n";
            }

            // x-Ticks (Monate)
            foreach ($months as $m) {
                $xx = $sx($m);
                echo '<line x1="'.$xx.'" y1="'.($y0).'" x2="'.$xx.'" y2="'.($y0+4).'" stroke="#333" stroke-width="1"/>'."\n";
                echo '<text x="'.$xx.'" y="'.($y0+18).'" text-anchor="middle" font-size="12" font-family="sans-serif">'.$m.'</text>'."\n";
            }
            echo '<text x="'.($width/2).'" y="'.($height-8).'" text-anchor="middle" font-size="12" font-family="sans-serif">Monat</text>'."\n";
            echo '<text x="14" y="'.($marginT + $plotH/2).'" text-anchor="middle" font-size="12" font-family="sans-serif" transform="rotate(-90 14,'.($marginT + $plotH/2).')">Wert</text>'."\n";

            // Linien je Jahr
            $yearKeys = array_keys($perYear);
            $colorIdx = 0;
            foreach ($yearKeys as $yk) {
                $vals = $perYear[$yk];
                $col  = $colors[$colorIdx % count($colors)];
                $colorIdx++;

                $d = '';
                for ($i = 0; $i < count($vals); $i++) {
                    $x = $sx($i+1);
                    $y = $sy($vals[$i]);
                    $d .= ($i === 0 ? 'M' : ' L') . $x . ' ' . $y;
                }
                echo '<path d="'.$d.'" fill="none" stroke="'.$col.'" stroke-width="2"/>'."\n";

                for ($i = 0; $i < count($vals); $i++) {
                    $x = $sx($i+1);
                    $y = $sy($vals[$i]);
                    echo '<circle cx="'.$x.'" cy="'.$y.'" r="3" fill="'.$col.'" />'."\n";
                }

                $ykStr = (string)$yk;
                $ykSafe = htmlspecialchars($ykStr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lx = $marginL + 10 + 120 * (($colorIdx-1) % 5);
                $ly = $marginT - 18;
                echo '<rect x="'.($lx-18).'" y="'.($ly-10).'" width="12" height="12" fill="'.$col.'"/>';
                echo '<text x="'.$lx.'" y="'.$ly.'" font-size="12" font-family="sans-serif">'.$ykSafe.'</text>'."\n";
            }

            echo '</svg>';
            $svg = ob_get_clean();

            @file_put_contents($filePath, $svg);
            return file_exists($filePath);
        };

        // 9) Dateien erzeugen (SVG pro Kennzahl) und URLs zurückgeben
        $files = [];
        foreach ($data as $key => $perYear) {
            $title = $titles[$key] ?? $key;
            $fname = "diagram_{$key}.svg";
            $fpath = $tempDir . $fname;

            $ok = $makeSVG($key, $title, $perYear, $fpath);
            if ($ok) {
                $files[] = $fname;
            } else {
                $this->SendDebug("GenerateDiagrams", "SVG-Erzeugung fehlgeschlagen für $key", 0);
            }
        }

        if (empty($files)) {
            $this->SendDebug("GenerateDiagrams", "Keine Dateien erzeugt", 0);
            echo "[]";
            return;
        }

        $host = $this->getHost();
        $urls = array_map(function ($f) use ($host) {
            return "http://{$host}:3777/user/temp/" . $f;
        }, $files);

        echo json_encode($urls);
    }

    private function getHost()
    {
        $ifaces = Sys_GetNetworkInfo();
        foreach ($ifaces as $i) {
            if (!empty($i['IP']) && $i['IP'] !== '127.0.0.1') {
                return $i['IP'];
            }
        }
        return "127.0.0.1";
    }

    // ==== Datenzugriff ====
    private function readHistory(int $acID, int $varID, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $isCounter): array
    {
        $raw = AC_GetLoggedValues($acID, $varID, $from->getTimestamp(), $to->getTimestamp(), 0);
        $vals = [];
        foreach ($raw as $r) { $vals[] = [(int)$r['TimeStamp'], (float)$r['Value']]; }
        usort($vals, fn($a,$b)=> $a[0] <=> $b[0]);
        if ($isCounter) {
            $diff = [];
            for ($i=1; $i<count($vals); $i++) {
                $d = $vals[$i][1] - $vals[$i-1][1];
                if (!is_finite($d) || $d < 0) $d = 0.0;
                $diff[] = [ $vals[$i][0], $d ];
            }
            return $diff;
        }
        return $vals; // Energieimpulse pro Event
    }

    private function readHistorySOC(int $acID, int $varID, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $raw = AC_GetLoggedValues($acID, $varID, $from->getTimestamp(), $to->getTimestamp(), 0);
        $vals = [];
        foreach ($raw as $r) { $vals[] = [(int)$r['TimeStamp'], (float)$r['Value']]; }
        usort($vals, fn($a,$b)=> $a[0] <=> $b[0]);
        return $vals;
    }

    // ==== Resampling ====
    private function buildGrid(\DateTimeImmutable $from, \DateTimeImmutable $to, int $stepMinutes): array
    {
        $step = max(60, $stepMinutes*60);
        $ts = $from->getTimestamp();
        $end= $to->getTimestamp();
        $ts -= $ts % $step;
        $g = [];
        for ($t=$ts; $t <= $end; $t += $step) $g[] = $t;
        return $g;
    }

    private function resampleEnergyBuckets(array $deltas, array $grid): array
    {
        // out[i] = Summe der deltas mit ts in (grid[i-1], grid[i]]; out[0]=0
        $n = count($grid);
        $out = array_fill(0, $n, 0.0);
        $m = count($deltas);
        if ($n==0 || $m==0) return $out;
        $j=0;
        for ($i=1; $i<$n; $i++) {
            $start = $grid[$i-1];
            $end   = $grid[$i];
            $sum = 0.0;
            while ($j<$m && $deltas[$j][0] <= $end) { if ($deltas[$j][0] > $start) { $sum += (float)$deltas[$j][1]; } $j++; }
            $out[$i] = $sum;
        }
        return $out;
    }

    private function resampleStateToGrid(array $series, array $grid): array
    {
        // LOCF
        $res = [];
        $n = count($series);
        if ($n==0) { foreach ($grid as $_) $res[] = null; return $res; }
        $idx = 0; $last = null;
        foreach ($grid as $t) {
            while ($idx+1<$n && $series[$idx+1][0] <= $t) $idx++;
            $val = $series[$idx][1] ?? null; if ($val !== null) $last = $val; $res[] = $last;
        }
        return $res;
    }

    private function sumSeriesPairs(array $a, array $b): array
    {
        $all = array_merge($a,$b); usort($all, fn($x,$y)=> $x[0] <=> $y[0]); return $all;
    }

    // ==== Analyse ====
    private function analyzeDay_Roll_WithBuckets(array $rows, array $params, array $grid): array
    {
        $capKWh = (float)($params['cap_kwh'] ?? 0.0);
        if ($capKWh <= 0) throw new InvalidArgumentException('cap_kwh (kWh) ist Pflicht und > 0.');
        $etaC = (float)($params['eta_c'] ?? 0.95);
        $etaD = (float)($params['eta_d'] ?? 0.95);
        $chargeKW = isset($params['charge_kw']) ? (float)$params['charge_kw'] : INF;
        $dischKW  = isset($params['discharge_kw']) ? (float)$params['discharge_kw'] : INF;
        $tzName   = (string)($params['tz'] ?? date_default_timezone_get());
        $tz = new DateTimeZone($tzName);
        $returnDet = (bool)($params['return_details'] ?? false);
        $epsImp = (float)($params['epsilon_import'] ?? 0.002);
        if (count($rows) < 1) throw new InvalidArgumentException('Zu wenige Intervalle.');

        // Zieltag (Mitte des Grid)
        $midIndex=intdiv(count($grid),2); $midTs=$grid[$midIndex];
        $targetDT=(new DateTimeImmutable('@'.$midTs))->setTimezone($tz);
        $targetDate=$targetDT->format('Y-m-d');
        $dayStartTs=(new DateTimeImmutable($targetDate.' 00:00:00',$tz))->getTimestamp();
        $dayEndTs  =(new DateTimeImmutable($targetDate.' 23:59:59',$tz))->getTimestamp();

        // Core-Index (nur Zieltag)
        $coreIdx=[]; foreach ($rows as $i=>$r) { $ts=$this->ts_from_any($r['t'],$tz); if ($ts >= $dayStartTs && $ts <= $dayEndTs) $coreIdx[]=$i; }
        if (empty($coreIdx)) throw new RuntimeException('Keine Intervalle im Zieltag '.$targetDate.' gefunden.');

        // Simulation
        $soc=100.0; if (isset($rows[0]['soc']) && $rows[0]['soc']!==null) $soc=(float)$rows[0]['soc'];
        $capKWh=max(1e-9,$capKWh);
        $batE=$capKWh*$soc/100.0; $batPV=$batE;
        $hpSum=0.0; $pvToHP_direct=0.0; $batPVToHP_AC=0.0; $hoursCore=0.0; $details=[];

        $importRealCore=0.0; foreach ($coreIdx as $i){ $imp=max(0.0,(float)($rows[$i]['import']??0.0)); if($imp<$epsImp)$imp=0.0; $importRealCore+=$imp; }

        foreach ($rows as $i=>$r) {
            $E=max(0.0,(float)($r['pv']??0.0));
            $L=max(0.0,(float)($r['load']??0.0));
            $HP=max(0.0,(float)($r['hp']??0.0));
            $h=(float)($r['dt_h']??0.25);
            $Other=max(0.0,$L-$HP);

            // Direktverbrauch
            $pvToOther=min($E,$Other); $remPV=$E-$pvToOther; $remOther=$Other-$pvToOther; $pvToHP=min($remPV,$HP); $remPV-=$pvToHP; $remHP=$HP-$pvToHP;

            // Laden
            $chargeLimitKWh=is_finite($chargeKW)?$chargeKW*$h:INF;
            $capSpaceKWh=max(0.0,$capKWh-$batE);
            $chargeRawKWh=min($remPV,$chargeLimitKWh,($capSpaceKWh>0?$capSpaceKWh/$etaC:0.0));
            $chargeBatKWh=$etaC*$chargeRawKWh; $batE+=$chargeBatKWh; $batPV+=$chargeBatKWh;

            // Entladen Other
            $dischLimitKWh=is_finite($dischKW)?$dischKW*$h:INF;
            $pvFrac=$batE>1e-9? min(1.0,max(0.0,$batPV/$batE)) : 0.0;
            $avail=min($dischLimitKWh,$etaD*$batE);
            $batToOther=min($remOther,$avail);
            $batE-=$batToOther/$etaD; $batPV-=$pvFrac*($batToOther/$etaD); $remOther-=$batToOther;

            // Entladen HP
            $pvFrac=$batE>1e-9? min(1.0,max(0.0,$batPV/$batE)) : 0.0;
            $avail=min(max(0.0,$dischLimitKWh-$batToOther),$etaD*$batE);
            $batToHP=min($remHP,$avail);
            $batE-=$batToHP/$etaD; $pvPartFromBatToHP_DC=$pvFrac*($batToHP/$etaD); $batPV-=$pvPartFromBatToHP_DC;

            $ts=$this->ts_from_any($r['t'],$tz); $inCore=($ts >= $dayStartTs && $ts <= $dayEndTs);
            if ($inCore) {
                $hpSum += $HP; $pvToHP_direct += $pvToHP; $batPVToHP_AC += $pvPartFromBatToHP_DC*$etaD; $hoursCore += $h;
                if ($returnDet) {
                    $details[] = [ 't'=>$r['t'],'dt_h'=>round($h,6), 'pv_kwh'=>round($E,6),'load_kwh'=>round($L,6),'hp_kwh'=>round($HP,6),
                                   'pv_to_wp_direct_kwh'=>round($pvToHP,6),'bat_to_wp_kwh'=>round($batToHP,6),'bat_pv_to_wp_kwh'=>round($pvPartFromBatToHP_DC*$etaD,6),
                                   'soc_sim_percent'=>round(100.0*$batE/$capKWh,3) ];
                }
            }
        }

        // Gegenszenario ohne WP
        $soc2 = isset($rows[0]['soc']) && $rows[0]['soc']!==null? (float)$rows[0]['soc'] : 100.0;
        $importNoHPCore=0.0; $increaseCore=0.0; $avoidedCore=0.0;
        foreach ($rows as $i=>$r) {
            $E=max(0.0,(float)($r['pv']??0.0)); $L=max(0.0,(float)($r['load']??0.0)); $HP=max(0.0,(float)($r['hp']??0.0));
            $Imp=max(0.0,(float)($r['import']??0.0)); if($Imp<$epsImp)$Imp=0.0; $h=(float)($r['dt_h']??0.25); $L0=max(0.0,$L-$HP);
            $batE=$capKWh*$soc2/100.0; $pvToLoad=min($E,$L0); $pvExcess=max(0.0,$E-$pvToLoad);
            $chargeLimitKWh=is_finite($chargeKW)?$chargeKW*$h:INF; $capSpaceKWh=max(0.0,$capKWh-$batE);
            $chargeRawKWh=min($pvExcess,$chargeLimitKWh,($capSpaceKWh>0?$capSpaceKWh/$etaC:0.0)); $batE+=$etaC*$chargeRawKWh;
            $deficit=max(0.0,$L0-$pvToLoad); $dischLimitKWh=is_finite($dischKW)?$dischKW*$h:INF; $availableDischKWh=min($dischLimitKWh,$etaD*$batE);
            $dischargeKWh=min($deficit,$availableDischKWh); $batE-=$dischargeKWh/$etaD; $gridImpNoHP=max(0.0,$deficit-$dischargeKWh); if($gridImpNoHP<$epsImp)$gridImpNoHP=0.0;
            $soc2=100.0*$batE/$capKWh; $ts=$this->ts_from_any($r['t'],$tz); $inCore=($ts >= $dayStartTs && $ts <= $dayEndTs);
            if ($inCore) {
                $importNoHPCore += $gridImpNoHP; $delta = $Imp - $gridImpNoHP; if ($delta>0)$increaseCore+=$delta; elseif($delta<0)$avoidedCore+=-$delta;
                if ($returnDet) { $idx=$this->safeIndex($details,$i); $details[$idx]['import_real_kwh']=round($Imp,6); $details[$idx]['import_no_wp_kwh']=round($gridImpNoHP,6); $details[$idx]['delta_import_kwh']=round($delta,6); }
            }
        }

        $pv_attr_total = $pvToHP_direct + $batPVToHP_AC; $share=function(float $n,float $d):float{ return $d>0? round(100.0*$n/$d,2):0.0;};
        $net_grid_effect = $importRealCore - $importNoHPCore; $wp_net_inc=max(0.0,$net_grid_effect); $wp_net_red=max(0.0,-$net_grid_effect);

        return [ 'target_date'=>$targetDate, 'hours_represented'=>round($hoursCore,3), 'hp_kwh'=>round($hpSum,3),
                 'pv_to_wp_direct_kwh'=>round($pvToHP_direct,3), 'bat_pv_to_wp_kwh'=>round($batPVToHP_AC,3), 'pv_to_wp_total_kwh'=>round($pv_attr_total,3), 'pv_attr_share_percent'=>$share($pv_attr_total,$hpSum),
                 'import_real_kwh'=>round($importRealCore,3), 'import_no_wp_kwh'=>round($importNoHPCore,3),
                 'wp_import_increase_brutto_kwh'=>round($increaseCore,3), 'wp_import_avoided_brutto_kwh'=>round($avoidedCore,3),
                 'wp_import_increase_brutto_share_pct'=>$share($increaseCore,$hpSum), 'wp_import_avoided_brutto_share_pct'=>$share($avoidedCore,$hpSum),
                 'wp_import_change_signed_kwh'=>round($net_grid_effect,3), 'wp_import_increase_netto_kwh'=>round($wp_net_inc,3), 'wp_import_reduction_netto_kwh'=>round($wp_net_red,3),
                 'wp_import_increase_netto_share_pct'=>$share($wp_net_inc,$hpSum), 'wp_import_reduction_netto_share_pct'=>$share($wp_net_red,$hpSum),
                 'grid_increase_by_wp_kwh'=>round($increaseCore,3), 'grid_avoided_by_wp_kwh'=>round($avoidedCore,3), 'net_grid_effect_kwh'=>round($net_grid_effect,3),
                 'details'=>$returnDet? $details : null ];
    }

    private function safeIndex(array $details, int $i): int
    { $n=count($details); if($n==0)return 0; if($i<$n)return $i; return $n-1; }

    private function ts_from_any($t, DateTimeZone $tz): int
    {
        if ($t instanceof DateTimeInterface) return $t->getTimestamp();
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s',(string)$t,$tz)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s',(string)$t,$tz)
            ?: new DateTimeImmutable((string)$t,$tz);
        return $dt->getTimestamp();
    }

    private function calcNextRun(string $hhmm): int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) { $hhmm = '02:30'; preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m); }
        $tz = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->setTime((int)$m[1], (int)$m[2], 0);
        if ($today->getTimestamp() <= $now->getTimestamp()) { $today = $today->modify('+1 day'); }
        return $today->getTimestamp();
    }

    private function uiLog(string $txt): void
    { $this->UpdateFormField('BackfillLog', 'caption', $txt); }

    private function dateFromSelect(string $json): ?DateTimeImmutable
    {
        if (trim($json) === '') return null; $o = @json_decode($json, true); if (!is_array($o) || empty($o['year'])) return null;
        $tz = new DateTimeZone(date_default_timezone_get());
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $o['year'], $o['month'], $o['day']), $tz);
    }

    private function backfillRangeEngine(DateTimeImmutable $from, DateTimeImmutable $to, int $catID, bool $dryRun): array
    {
        // Modul-Variablen (Quelle)
        $mv = [
            'hp'  => $this->GetIDForIdent('HP_kWh'),
            'pvwp'=> $this->GetIDForIdent('PV_to_WP_total_kWh'),
            'impR'=> $this->GetIDForIdent('Import_real_kWh'),
            'impN'=> $this->GetIDForIdent('Import_no_wp_kWh'),
            'net' => $this->GetIDForIdent('WP_import_change_signed_kWh'),
            'copT'=> $this->GetIDForIdent('COP_WP_Total'),
            'copH'=> $this->GetIDForIdent('COP_WP_Heating'),
            'copW'=> $this->GetIDForIdent('COP_WP_DHW')
        ];
        foreach ($mv as $k=>$vid) if ($vid <= 0) throw new Exception("Modul-Variable fehlt: $k");

        // Zielvariablen anlegen (Backfill-Ordner)
        $targets = $this->ensureBackfillTargets($catID);

        // Archive Control
        $acID = $this->ReadPropertyInteger('ArchiveControlID'); if ($acID <= 0) throw new Exception('ArchiveControl fehlt.');

        $written = 0;
        try {
            for ($d=$from; $d <= $to; $d = $d->modify('+1 day')) {
                $ymd = $d->format('Y-m-d');

                // Analysefenster: D-1 .. D+1
                $winFrom = $d->modify('-1 day')->setTime(0,0,0);
                $winTo   = $d->modify('+1 day')->setTime(0,0,0);

                // Attribute für Analyze setzen
                $this->WriteAttributeString('BF_TimeRangeMode', 'absolute');
                $this->WriteAttributeString('BF_FromDateTime', $this->dateToSelectJSON($winFrom));
                $this->WriteAttributeString('BF_ToDateTime',   $this->dateToSelectJSON($winTo));
                $this->WriteAttributeBoolean('BF_AlignToMinute', true);

                // Analyse ausführen
                $this->Analyze();

                // Attribute zurücksetzen
                $this->WriteAttributeString('BF_TimeRangeMode', '');
                $this->WriteAttributeString('BF_FromDateTime', '');
                $this->WriteAttributeString('BF_ToDateTime', '');
                $this->WriteAttributeBoolean('BF_AlignToMinute', false);

                // Werte holen
                $hp   = (float)GetValueFloat($mv['hp']);
                $pvwp = (float)GetValueFloat($mv['pvwp']);
                $impR = (float)GetValueFloat($mv['impR']);
                $impN = (float)GetValueFloat($mv['impN']);
                $net  = (float)GetValueFloat($mv['net']);

                // COP aus Modulvariablen holen
                $copT = (float)GetValueFloat($mv['copT']);
                $copH = (float)GetValueFloat($mv['copH']);
                $copW = (float)GetValueFloat($mv['copW']);

                // ZÄHLER schreiben (00:00=0, 23:59=Summe)
                $written += $this->writeCounterDay($acID, $targets['HP_kWh_Backfilled'], $ymd, $hp, $dryRun);
                $written += $this->writeCounterDay($acID, $targets['PV_to_WP_total_kWh_Backfilled'], $ymd, $pvwp, $dryRun);
                $written += $this->writeCounterDay($acID, $targets['Import_real_kWh_Backfilled'], $ymd, $impR, $dryRun);
                $written += $this->writeCounterDay($acID, $targets['Import_no_wp_kWh_Backfilled'], $ymd, $impN, $dryRun);
                $written += $this->writeCounterDay($acID, $targets['WP_import_change_signed_kWh_Backfilled'], $ymd, $net, $dryRun);

                // COP als Ereigniswert (Tagesmitte) schreiben
                $written += $this->writeEventDay($acID, $targets['COP_WP_Total_Backfilled'],   $ymd, $copT, $dryRun);
                $written += $this->writeEventDay($acID, $targets['COP_WP_Heating_Backfilled'], $ymd, $copH, $dryRun);
                $written += $this->writeEventDay($acID, $targets['COP_WP_DHW_Backfilled'],     $ymd, $copW, $dryRun);
            }

            // Reaggregate aller Zielvariablen
            foreach ($targets as $vid) AC_ReAggregateVariable($acID, $vid);
        } finally { }

        return [$written, ($to->getTimestamp() - $from->getTimestamp())/86400 + 1];
    }

    private function ensureBackfillTargets(int $catID): array
    {
        // Map mit Aggregationstyp: 1=Zähler, 0=Ereignis
        $map = [
            'HP_kWh_Backfilled' => ['name'=>'WP Tagesenergie [kWh]','agg'=>1],
            'PV_to_WP_total_kWh_Backfilled' => ['name'=>'PV→WP [kWh]','agg'=>1],
            'Import_real_kWh_Backfilled' => ['name'=>'Netzbezug mit WP [kWh]','agg'=>1],
            'Import_no_wp_kWh_Backfilled' => ['name'=>'Netzbezug ohne WP [kWh]','agg'=>1],
            'WP_import_change_signed_kWh_Backfilled' => ['name'=>'Netto-Effekt [kWh]','agg'=>1],

            // NEU: COP als Ereignisse
            'COP_WP_Total_Backfilled'   => ['name'=>'COP Gesamt','agg'=>0],
            'COP_WP_Heating_Backfilled' => ['name'=>'COP Heizen','agg'=>0],
            'COP_WP_DHW_Backfilled'     => ['name'=>'COP Warmwasser','agg'=>0]
        ];

        $acID = $this->ReadPropertyInteger('ArchiveControlID');
        $out = [];
        foreach ($map as $ident=>$cfg) {
            $vid = @IPS_GetObjectIDByIdent($ident, $catID);
            if (!$vid) {
                $vid = IPS_CreateVariable(VARIABLETYPE_FLOAT);
                IPS_SetParent($vid, $catID);
                IPS_SetName($vid, $cfg['name']);
                IPS_SetIdent($vid, $ident);
            }
            AC_SetLoggingStatus($acID, $vid, true);
            AC_SetAggregationType($acID, $vid, (int)$cfg['agg']);
            $out[$ident] = $vid;
        }
        return $out;
    }

    private function writeCounterDay(int $acID, int $varID, string $ymd, float $sum, bool $dryRun): int
    {
        if ($dryRun) return 0;
        $tsStart = strtotime($ymd.' 00:00:00');
        $tsEnd   = strtotime($ymd.' 23:59:00');
        AC_DeleteVariableData($acID, $varID, $tsStart, $tsEnd);
        AC_AddLoggedValues($acID, $varID, [ ['TimeStamp'=>$tsStart,'Value'=>0.0], ['TimeStamp'=>$tsEnd,'Value'=>$sum] ]);
        return 2;
    }

    private function writeEventDay(int $acID, int $varID, string $ymd, float $value, bool $dryRun): int
    {
        if ($dryRun) return 0;
        $ts = strtotime($ymd.' 12:00:00'); // Tagesmitte
        AC_DeleteVariableData($acID, $varID, $ts, $ts);
        AC_AddLoggedValues($acID, $varID, [ ['TimeStamp'=>$ts,'Value'=>$value] ]);
        return 1;
    }

    private function dateToSelectJSON(\DateTimeImmutable $dt): string
    { return json_encode([ 'year'=>(int)$dt->format('Y'), 'month'=>(int)$dt->format('m'), 'day'=>(int)$dt->format('d'), 'hour'=>(int)$dt->format('H'), 'minute'=>(int)$dt->format('i'), 'second'=>(int)$dt->format('s') ], JSON_UNESCAPED_SLASHES); }

    private function archiveDayExists(int $acID, int $varID, string $ymd): bool
    { $tsEnd = strtotime($ymd.' 23:59:00'); $data = AC_GetLoggedValues($acID, $varID, $tsEnd, $tsEnd, 0); return is_array($data) && count($data) > 0; }

    private function buildDefaDashboardPhp(int $moduleId): string
    {
        $year = (int)date('Y');

        // 1) Template ohne Interpolation (NOWDOC)
        $tpl = <<<'PHP'
        <?php
        declare(strict_types=1);

        // -------------------------------------------
        // Modul- & Laufzeitparameter
        // -------------------------------------------
        $MODULE_ID = {{MODULE_ID}};
        $YEAR      = (int)($_GET['year'] ?? {{YEAR}});
        $FORMAT    = (string)($_GET['format'] ?? 'html'); // 'html' oder 'csv'

        // -------------------------------------------
        // Hilfen: Archiv & Aggregation
        // -------------------------------------------
        function getArchiveId(): int {
            // GUID Archive-Control: {43192F0B-135B-4CE7-A0A7-1475603F3060}
            $list = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (empty($list)) {
                throw new RuntimeException('Archive Control nicht gefunden.');
            }
            return (int)$list[0];
        }

        function getMonthly($archiveId, int $varId, int $year): array {
            if ($varId <= 0) { return array_fill(1, 12, 0.0); }
            $start = strtotime("first day of January $year 00:00:00");
            $end   = strtotime("last day of December $year 23:59:59");
            // Aggregationsstufe 3 = Monat
            $agg   = AC_GetAggregatedValues($archiveId, $varId, 3, $start, $end, 0);
            $out = array_fill(1, 12, 0.0);
            foreach ($agg as $row) {
                $m = (int)date('n', $row['TimeStamp']);
                // Hinweis: Bei "Zähler"-Variablen ist 'Avg' die Monatssumme (Summe der positiven Deltas).
                $out[$m] = (float)$row['Avg'];
            }
            return $out;
        }

        // Formatierer (DE)
        function f1(float $v): string { return number_format($v, 1, ',', '.'); }
        function f2(float $v): string { return number_format($v, 2, ',', '.'); }

        // -------------------------------------------
        // Property-IDs aus der Modulinstanz
        // -------------------------------------------
        $id_wmzH   = (int)IPS_GetProperty($MODULE_ID, 'Dash_HeatWMZ');
        $id_wmzW   = (int)IPS_GetProperty($MODULE_ID, 'Dash_DHW_WMZ');
        $id_wpH    = (int)IPS_GetProperty($MODULE_ID, 'Dash_HeatPower');
        $id_wpW    = (int)IPS_GetProperty($MODULE_ID, 'Dash_DHW_Power');
        $id_wpG    = (int)IPS_GetProperty($MODULE_ID, 'Dash_TotalPower');

        $id_pv     = (int)IPS_GetProperty($MODULE_ID, 'Dash_PV');           // PV gesamt (falls vorhanden)
        $id_haus   = (int)IPS_GetProperty($MODULE_ID, 'Dash_Consumption');   // Haus gesamt
        $id_netTot = (int)IPS_GetProperty($MODULE_ID, 'Dash_GridBuy');       // Netz gesamt (Haushalt)
        $id_netWP  = (int)IPS_GetProperty($MODULE_ID, 'Dash_NettoEffect');   // Netz (WP)

        $id_hh     = (int)IPS_GetProperty($MODULE_ID, 'Dash_HeaterHours');   // Betriebsstunden Heizen
        $id_wh     = (int)IPS_GetProperty($MODULE_ID, 'Dash_DHWHours');      // Betriebsstunden WW

        // -------------------------------------------
        // Daten laden (Monatsaggregation)
        // -------------------------------------------
        $ac = getArchiveId();

        $wmzH = getMonthly($ac, $id_wmzH,   $YEAR);
        $wmzW = getMonthly($ac, $id_wmzW,   $YEAR);

        $wpH  = getMonthly($ac, $id_wpH,    $YEAR);
        $wpW  = getMonthly($ac, $id_wpW,    $YEAR);
        $wpG  = getMonthly($ac, $id_wpG,    $YEAR);

        $pv   = getMonthly($ac, $id_pv,     $YEAR);
        $haus = getMonthly($ac, $id_haus,   $YEAR);
        $netT = getMonthly($ac, $id_netTot, $YEAR);
        $netW = getMonthly($ac, $id_netWP,  $YEAR);

        $stdH = getMonthly($ac, $id_hh,     $YEAR);
        $stdW = getMonthly($ac, $id_wh,     $YEAR);

        // Summen vorbereiten
        $S = [
        'wmzH'=>0.0,'wmzW'=>0.0,'wpH'=>0.0,'wpW'=>0.0,'wpG'=>0.0,
        'pv'=>0.0,'haus'=>0.0,'netT'=>0.0,'netW'=>0.0,
        'stdH'=>0.0,'stdW'=>0.0
        ];

        // ------------------------------
        // CSV-Ausgabe?
        // ------------------------------
        if (strtolower($FORMAT) === 'csv') {
            // **Header zuerst**, dann BOM, dann Inhalte
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="defa_dashboard_' . $YEAR . '.csv"');
            echo "\xEF\xBB\xBF"; // BOM für Excel

            // Kopf
            $header = [
                'Monat',
                'Wärme Heizen (kWh)','Wärme WW (kWh)',
                'WP Strom Heizen (kWh)','WP Strom WW (kWh)','WP Strom Gesamt (kWh)',
                'COP Gesamt','COP Heizen','COP WW',
                'PV (kWh)','Haus (kWh)','Netz gesamt (kWh)','Netz (WP) (kWh)',
                'Betriebsstunden Heizen','Betriebsstunden WW'
            ];
            echo implode(';', $header) . "\r\n";

            for ($m=1; $m<=12; $m++) {
                $wH = (float)$wmzH[$m];  $wW = (float)$wmzW[$m];
                $eH = (float)$wpH[$m];   $eW = (float)$wpW[$m];  $eG = (float)$wpG[$m];
                $copT = ($eG>0)?($wH+$wW)/$eG:0.0;
                $copH = ($eH>0)?$wH/$eH:0.0;
                $copW = ($eW>0)?$wW/$eW:0.0;

                $pvM   = (float)$pv[$m];
                $hausM = (float)$haus[$m];
                $netTM = (float)$netT[$m];
                $netWM = (float)$netW[$m];
                $hH    = (float)$stdH[$m];
                $wHrs  = (float)$stdW[$m];

                // Summen
                $S['wmzH']+=$wH; $S['wmzW']+=$wW;
                $S['wpH'] +=$eH; $S['wpW'] +=$eW; $S['wpG']+=$eG;
                $S['pv']  +=$pvM; $S['haus']+=$hausM; $S['netT']+=$netTM; $S['netW']+=$netWM;
                $S['stdH']+=$hH;  $S['stdW']+=$wHrs;

                // Monatsname (lokalisiert)
                $date      = DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $YEAR, $m));
                $monthName = \IntlDateFormatter::formatObject($date, 'LLLL', 'de_DE');

                $row = [
                    $monthName,
                    f1($wH), f1($wW),
                    f1($eH), f1($eW), f1($eG),
                    f2($copT), f2($copH), f2($copW),
                    f1($pvM), f1($hausM), f1($netTM), f1($netWM),
                    f1($hH), f1($wHrs)
                ];
                echo implode(';', $row) . "\r\n";
            }

            // Jahreszeile + Kennzahlen
            $copT_sum = ($S['wpG']>0)?($S['wmzH']+$S['wmzW'])/$S['wpG']:0.0;
            $copH_sum = ($S['wpH']>0)?  $S['wmzH']/$S['wpH']:0.0;
            $copW_sum = ($S['wpW']>0)?  $S['wmzW']/$S['wpW']:0.0;

            $autWP    = ($S['wpG']>0)? max(0.0, min(1.0, 1.0 - ($S['netW']/$S['wpG']))):0.0;
            $autHaus  = ($S['haus']>0)? max(0.0, min(1.0, 1.0 - ($S['netT']/$S['haus']))):0.0;

            $footer = [
                'Gesamt',
                f1($S['wmzH']), f1($S['wmzW']),
                f1($S['wpH']), f1($S['wpW']), f1($S['wpG']),
                f2($copT_sum), f2($copH_sum), f2($copW_sum),
                f1($S['pv']), f1($S['haus']), f1($S['netT']), f1($S['netW']),
                f1($S['stdH']), f1($S['stdW'])
            ];
            echo implode(';', $footer) . "\r\n";

            // Kennzahlenzeilen
            echo "Kennzahl;Jahres-COP (WP gesamt);" . f2($copT_sum) . "\r\n";
            echo "Kennzahl;Autarkie (WP) [%];"       . number_format($autWP*100, 1, ',', '.')  . "\r\n";
            echo "Kennzahl;Autarkie (Haushalt) [%];" . number_format($autHaus*100, 1, ',', '.') . "\r\n";
            exit;
        }

        // ------------------------------
        // HTML-Ansicht (Standard)
        // ------------------------------
        ?><!doctype html>
        <html lang="de">
        <head>
        <meta charset="utf-8">
        <title>WP Jahresübersicht <?=htmlspecialchars((string)$YEAR, ENT_QUOTES, 'UTF-8')?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body { font-family: system-ui, Arial, sans-serif; margin: 1rem; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th, td { padding: .4rem .6rem; border-bottom: 1px solid #e0e0e0; }
            th[scope="row"] { text-align: left; }
            .num { text-align: right; white-space: nowrap; }
            tfoot .summary { text-align: right; padding-top: .6rem; }
            .toolbar { margin: .5rem 0 1rem; display:flex; gap:.5rem; flex-wrap:wrap; }
            .btn { display:inline-block; padding:.45rem .7rem; border:1px solid #ccc; border-radius:.3rem; text-decoration:none; color:#222; background:#f7f7f7; }
            .btn:hover { background:#eee; }
            .year { font-weight:600; }
        </style>
        </head>
        <body>
        <h1>WP Jahresübersicht <span class="year"><?=htmlspecialchars((string)$YEAR, ENT_QUOTES, 'UTF-8')?></span></h1>

        <div class="toolbar">
            <a class="btn" href="?year=<?= (int)$YEAR ?>&format=csv">CSV exportieren</a>
            <a class="btn" href="?year=<?= (int)$YEAR - 1 ?>">« Vorjahr</a>
            <a class="btn" href="?year=<?= (int)$YEAR + 1 ?>">Folgejahr »</a>
        </div>

        <table class="wp-jahresuebersicht">
            <thead>
            <tr>
                <th scope="col">Monat</th>
                <th scope="col" colspan="2">Wärme (kWh)</th>
                <th scope="col" colspan="3">WP&nbsp;Strom (kWh)</th>
                <th scope="col" colspan="3">COP</th>
                <th scope="col" colspan="4">Energie (kWh)</th>
                <th scope="col" colspan="2">Betriebsstunden</th>
            </tr>
            <tr>
                <th></th>
                <th>Heizen</th><th>WW</th>
                <th>Heizen</th><th>WW</th><th>Gesamt</th>
                <th>Gesamt</th><th>Heizen</th><th>WW</th>
                <th>PV</th><th>Haus</th><th>Netz gesamt</th><th>Netz (WP)</th>
                <th>Heizen</th><th>WW</th>
            </tr>
            </thead>
            <tbody>
        <?php
            for ($m=1; $m<=12; $m++) {
                $wH = (float)$wmzH[$m];  $wW = (float)$wmzW[$m];
                $eH = (float)$wpH[$m];   $eW = (float)$wpW[$m];  $eG = (float)$wpG[$m];
                $copT = ($eG>0)?($wH+$wW)/$eG:0.0;
                $copH = ($eH>0)?$wH/$eH:0.0;
                $copW = ($eW>0)?$wW/$eW:0.0;

                $pvM   = (float)$pv[$m];
                $hausM = (float)$haus[$m];
                $netTM = (float)$netT[$m];
                $netWM = (float)$netW[$m];

                $hH    = (float)$stdH[$m];
                $wHrs  = (float)$stdW[$m];

                // Summen
                $S['wmzH']+=$wH; $S['wmzW']+=$wW;
                $S['wpH'] +=$eH; $S['wpW'] +=$eW; $S['wpG']+=$eG;
                $S['pv']  +=$pvM; $S['haus']+=$hausM; $S['netT']+=$netTM; $S['netW']+=$netWM;
                $S['stdH']+=$hH;  $S['stdW']+=$wHrs;

                $date      = DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $YEAR, $m));
                $monthName = \IntlDateFormatter::formatObject($date, 'LLLL', 'de_DE');
        ?>
            <tr>
                <th scope="row"><?=htmlspecialchars($monthName, ENT_QUOTES, 'UTF-8')?></th>
                <td class="num"><?=f1($wH)?></td>
                <td class="num"><?=f1($wW)?></td>
                <td class="num"><?=f1($eH)?></td>
                <td class="num"><?=f1($eW)?></td>
                <td class="num"><?=f1($eG)?></td>
                <td class="num"><?=f2($copT)?></td>
                <td class="num"><?=f2($copH)?></td>
                <td class="num"><?=f2($copW)?></td>
                <td class="num"><?=f1($pvM)?></td>
                <td class="num"><?=f1($hausM)?></td>
                <td class="num"><?=f1($netTM)?></td>
                <td class="num"><?=f1($netWM)?></td>
                <td class="num"><?=f1($hH)?></td>
                <td class="num"><?=f1($wHrs)?></td>
            </tr>
        <?php } ?>
            </tbody>
            <tfoot>
        <?php
            $copT_sum = ($S['wpG']>0)?($S['wmzH']+$S['wmzW'])/$S['wpG']:0.0;
            $copH_sum = ($S['wpH']>0)? $S['wmzH']/$S['wpH']:0.0;
            $copW_sum = ($S['wpW']>0)? $S['wmzW']/$S['wpW']:0.0;

            // Autarkien
            $autWP    = ($S['wpG']>0)? max(0.0, min(1.0, 1.0 - ($S['netW']/$S['wpG']))):0.0;
            $autHaus  = ($S['haus']>0)? max(0.0, min(1.0, 1.0 - ($S['netT']/$S['haus']))):0.0;
        ?>
            <tr>
                <th scope="row">Gesamt</th>
                <td class="num"><?=f1($S['wmzH'])?></td>
                <td class="num"><?=f1($S['wmzW'])?></td>
                <td class="num"><?=f1($S['wpH'])?></td>
                <td class="num"><?=f1($S['wpW'])?></td>
                <td class="num"><?=f1($S['wpG'])?></td>
                <td class="num"><?=f2($copT_sum)?></td>
                <td class="num"><?=f2($copH_sum)?></td>
                <td class="num"><?=f2($copW_sum)?></td>
                <td class="num"><?=f1($S['pv'])?></td>
                <td class="num"><?=f1($S['haus'])?></td>
                <td class="num"><?=f1($S['netT'])?></td>
                <td class="num"><?=f1($S['netW'])?></td>
                <td class="num"><?=f1($S['stdH'])?></td>
                <td class="num"><?=f1($S['stdW'])?></td>
            </tr>
            <tr>
                <td colspan="15" class="summary">
                <strong>Jahres‑COP (WP gesamt):</strong> <?=f2($copT_sum)?>
                &nbsp;|&nbsp;
                <strong>Autarkie (WP):</strong> <?=number_format($autWP*100, 1, ',', '.') ?>&nbsp;%
                &nbsp;|&nbsp;
                <strong>Autarkie (Haushalt):</strong> <?=number_format($autHaus*100, 1, ',', '.') ?>&nbsp;%
                &nbsp;|&nbsp;
                <a class="btn" href="?year=<?= (int)$YEAR ?>&format=csv">CSV exportieren</a>
                </td>
            </tr>
            </tfoot>
        </table>

        <p style="margin-top:1rem;color:#666">
            Quelle: IP‑Symcon Archiv (Monatsaggregation). IDs aus Modul #<?= (int)$MODULE_ID ?>.
        </p>
        </body>
        </html>
        PHP;

        // 2) Platzhalter ersetzen und Template zurückgeben
        return strtr($tpl, [
            '{{MODULE_ID}}' => (string)$moduleId,
            '{{YEAR}}'      => (string)$year,
        ]);
    } 
}
