<?php

declare(strict_types=1);

/**
 * MELCloud Klimagerät (Device)
 *
 * Repräsentiert ein einzelnes Mitsubishi-Klimagerät (ATA). Erhält den Status
 * vom MELCloud-Connection-Splitter (ReceiveData) und schickt Steuerbefehle
 * über den Splitter an die Cloud (SendDataToParent).
 */
class MELCloudKlimageraet extends IPSModuleStrict
{
    private const RX_TO_PARENT = '{7D0C324F-EF82-4716-A8A0-00006378D27F}';

    // int <-> API-String Zuordnungen
    private const MODE_MAP   = [0 => 'Automatic', 1 => 'Heat', 2 => 'Cool', 3 => 'Dry', 4 => 'Fan'];
    private const FAN_MAP    = [0 => 'Auto', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five'];
    private const VANE_V_MAP = [0 => 'Auto', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 7 => 'Swing'];
    private const VANE_H_MAP = [0 => 'Auto', 1 => 'Left', 2 => 'LeftCentre', 3 => 'Centre', 4 => 'RightCentre', 5 => 'Right', 7 => 'Swing'];

    // Verzögerung, mit der mehrere schnelle Steuerbefehle (z. B. durch eine Symcon-Szene,
    // die Power/Modus/Solltemperatur/Lüfter/Lamellen kurz hintereinander setzt) zu einem
    // einzigen kombinierten Steuerbefehl zusammengefasst werden, statt mehrere einzelne
    // Teil-Requests an die Cloud zu senden (die sich sonst gegenseitig überschreiben können).
    private const CONTROL_DEBOUNCE_MS = 500;

    // Hysterese (°C) zur Ableitung des tatsächlichen Betriebsstatus aus dem Vergleich von
    // Raum- und Solltemperatur (siehe deriveOperatingStatus()).
    private const OPERATING_STATUS_HYSTERESIS = 0.5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('UnitID', '');
        $this->RegisterAttributeString('Capabilities', '{}');
        $this->RegisterTimer('FlushControl', 0, 'MELA_FlushControl($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Steuerbare Variablen
        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 1, true);
        $this->MaintainVariable('Mode', $this->Translate('Mode'), VARIABLETYPE_INTEGER, $this->modePresentation(), 2, true);
        [$tempMin, $tempMax] = $this->temperatureRangeForMode($this->GetValue('Mode'));
        $this->MaintainVariable('SetTemperature', $this->Translate('Target temperature'), VARIABLETYPE_FLOAT, $this->temperaturePresentation($tempMin, $tempMax), 3, true);
        $this->MaintainVariable('FanSpeed', $this->Translate('Fan speed'), VARIABLETYPE_INTEGER, $this->fanSpeedPresentation(), 4, true);
        $this->MaintainVariable('VaneVertical', $this->Translate('Vane vertical'), VARIABLETYPE_INTEGER, $this->vaneVerticalPresentation(), 5, true);
        $this->MaintainVariable('VaneHorizontal', $this->Translate('Vane horizontal'), VARIABLETYPE_INTEGER, $this->vaneHorizontalPresentation(), 6, true);

        // Reine Anzeige-Variablen
        $this->MaintainVariable('RoomTemperature', $this->Translate('Room temperature'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-low', ' °C', 1, 1), 7, true);
        $this->MaintainVariable('OperatingStatus', $this->Translate('Operating status'), VARIABLETYPE_STRING, $this->statusPresentation(), 8, true);

        // Alte, instanzübergreifende Custom-Profile entfernen (Legacy, durch Presentations ersetzt)
        $this->removeLegacyProfiles();
        $this->MaintainVariable('Connected', $this->Translate('Connected'), VARIABLETYPE_STRING, $this->connectedPresentation(), 9, true);
        $this->MaintainVariable('Error', $this->Translate('Error'), VARIABLETYPE_STRING, $this->errorPresentation(), 10, true);
        $this->MaintainVariable('WiFiSignal', $this->Translate('WiFi signal'), VARIABLETYPE_INTEGER, $this->numericValuePresentation('signal-stream', ' dbm', 0), 11, true);
        $this->MaintainVariable('EnergyConsumed', $this->Translate('Energy consumed'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('plug-circle-bolt', ' kWh', 2), 12, true);
        $this->MaintainVariable('OutdoorTemperature', $this->Translate('Outdoor temperature'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-low', ' °C', 1), 13, true);
        $this->MaintainVariable('EnergyTotal', $this->Translate('Cumulative energy'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('plug-circle-bolt', ' kWh', 2, 1, 1000000), 14, true);
        $this->MaintainVariable('OutdoorTemperatureLastReading', $this->Translate('Outdoor temperature last reading'), VARIABLETYPE_STRING, [], 15, true);
        $this->MaintainVariable('OutdoorTemperatureStale', $this->Translate('Outdoor temperature stale'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 16, true);
        $this->MaintainVariable('ActualFanSpeed', $this->Translate('Actual fan speed'), VARIABLETYPE_STRING, $this->actualFanPresentation(), 17, true);
        $this->MaintainVariable('ErrorCode', $this->Translate('Error code'), VARIABLETYPE_STRING, [], 18, true);
        $this->MaintainVariable('InStandbyMode', $this->Translate('Standby mode'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 19, true);
        $this->MaintainVariable('FrostProtection', $this->Translate('Frost protection'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 20, true);
        $this->MaintainVariable('FrostProtectionMin', $this->Translate('Frost protection minimum'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-low', ' °C', 1), 21, true);
        $this->MaintainVariable('FrostProtectionMax', $this->Translate('Frost protection maximum'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-low', ' °C', 1), 22, true);
        $this->MaintainVariable('OverheatProtection', $this->Translate('Overheat protection'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 23, true);
        $this->MaintainVariable('OverheatProtectionMin', $this->Translate('Overheat protection minimum'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-high', ' °C', 1), 24, true);
        $this->MaintainVariable('OverheatProtectionMax', $this->Translate('Overheat protection maximum'), VARIABLETYPE_FLOAT, $this->numericValuePresentation('temperature-high', ' °C', 1), 25, true);
        $this->MaintainVariable('HolidayMode', $this->Translate('Holiday mode'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 26, true);
        $this->MaintainVariable('HolidayModeActive', $this->Translate('Holiday mode active'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 27, true);
        $this->MaintainVariable('HolidayStart', $this->Translate('Holiday start'), VARIABLETYPE_STRING, [], 28, true);
        $this->MaintainVariable('HolidayEnd', $this->Translate('Holiday end'), VARIABLETYPE_STRING, [], 29, true);

        // Aktionen für steuerbare Variablen aktivieren
        foreach (['Power', 'Mode', 'SetTemperature', 'FanSpeed', 'VaneVertical', 'VaneHorizontal'] as $ident) {
            $this->EnableAction($ident);
        }

        // Filter auf DataID – UnitID-Prüfung erfolgt in ReceiveData
        $unitID = $this->ReadPropertyString('UnitID');
        if ($unitID !== '') {
            $this->SetReceiveDataFilter('.*2FD07B1C-5822-48B2-B394-0000776DF537.*');
            $this->SetStatus(102);
            $this->triggerImmediateRefresh();
        } else {
            $this->SetReceiveDataFilter('(?!)'); // nichts empfangen, solange unkonfiguriert
            $this->SetStatus(104);
        }
    }

    /**
     * Stößt direkt nach dem Anlegen/Speichern einen sofortigen Status-Poll am
     * Connection-Splitter an, damit die Werte nicht erst auf den nächsten
     * regulären Polling-Zyklus (bis zu 60s) warten müssen.
     */
    private function triggerImmediateRefresh(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            return;
        }
        try {
            MELC_UpdateStatus($parentID);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Sofort-Refresh fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    /* -------------------------------------------------------------------------
     * Datenempfang vom Splitter
     * ---------------------------------------------------------------------- */

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug('ReceiveData', 'Empfangen (100 Zeichen): ' . substr($JSONString, 0, 100), 0);
        $outer = json_decode($JSONString, true);
        if (!isset($outer['Buffer']) || !is_string($outer['Buffer'])) {
            $this->SendDebug('ReceiveData', 'Kein Buffer in Daten', 0);
            return '';
        }
        $buffer = json_decode(hex2bin($outer['Buffer']), true);
        if (!is_array($buffer)) {
            $this->SendDebug('ReceiveData', 'Buffer konnte nicht dekodiert werden', 0);
            return '';
        }
        $myID = $this->ReadPropertyString('UnitID');
        $bufID = (string) ($buffer['UnitID'] ?? '');
        $this->SendDebug('ReceiveData', 'Buffer-UnitID=' . $bufID . ' eigene=' . $myID, 0);
        if ($bufID !== $myID) {
            return '';
        }

        if (array_key_exists('Power', $buffer)) {
            $this->SetValue('Power', (bool) $buffer['Power']);
        }
        $modeChanged = false;
        if (array_key_exists('OperationMode', $buffer) && $buffer['OperationMode'] !== null) {
            $this->SetValue('Mode', $this->apiToInt(self::MODE_MAP, (string) $buffer['OperationMode'], 0));
            $modeChanged = true;
        }
        if (array_key_exists('Capabilities', $buffer) && is_array($buffer['Capabilities'])) {
            $this->WriteAttributeString('Capabilities', (string) json_encode($buffer['Capabilities']));
            $this->applyCapabilityPresentations();
            $modeChanged = true; // Bereich anhand der (ggf. neuen) Capabilities neu berechnen
        }
        if ($modeChanged) {
            $this->applyTemperatureRange((int) $this->GetValue('Mode'));
        }
        if (array_key_exists('SetTemperature', $buffer) && is_numeric($buffer['SetTemperature'])) {
            $this->SetValue('SetTemperature', (float) $buffer['SetTemperature']);
        }
        if (array_key_exists('RoomTemperature', $buffer) && is_numeric($buffer['RoomTemperature'])) {
            $this->SetValue('RoomTemperature', (float) $buffer['RoomTemperature']);
        }
        if (array_key_exists('SetFanSpeed', $buffer) && $buffer['SetFanSpeed'] !== null) {
            $this->SetValue('FanSpeed', $this->apiToInt(self::FAN_MAP, (string) $buffer['SetFanSpeed'], 0));
        }
        if (array_key_exists('ActualFanSpeed', $buffer) && $buffer['ActualFanSpeed'] !== null) {
            $this->SetValue('ActualFanSpeed', $this->normalizeActualFanSpeed($buffer['ActualFanSpeed']));
        }
        if (array_key_exists('VaneVerticalDirection', $buffer) && $buffer['VaneVerticalDirection'] !== null) {
            $this->SetValue('VaneVertical', $this->apiToInt(self::VANE_V_MAP, (string) $buffer['VaneVerticalDirection'], 0));
        }
        if (array_key_exists('VaneHorizontalDirection', $buffer) && $buffer['VaneHorizontalDirection'] !== null) {
            $this->SetValue('VaneHorizontal', $this->apiToInt(self::VANE_H_MAP, (string) $buffer['VaneHorizontalDirection'], 0));
        }
        if (array_key_exists('IsInError', $buffer)) {
            $this->SetValue('Error', ((bool) $buffer['IsInError']) ? 'fault' : 'ok');
        }
        if (array_key_exists('ErrorCode', $buffer) && $buffer['ErrorCode'] !== null) {
            $this->SetValue('ErrorCode', (string) $buffer['ErrorCode']);
        }
        if (array_key_exists('InStandbyMode', $buffer)) {
            $this->SetValue('InStandbyMode', (bool) $buffer['InStandbyMode']);
        }
        if (array_key_exists('Connected', $buffer)) {
            $this->SetValue('Connected', ((bool) $buffer['Connected']) ? 'connected' : 'disconnected');
        }
        if (array_key_exists('rssi', $buffer) && is_numeric($buffer['rssi'])) {
            $this->SetValue('WiFiSignal', (int) $buffer['rssi']);
        }
        if (array_key_exists('EnergyConsumed', $buffer) && is_numeric($buffer['EnergyConsumed'])) {
            $this->SetValue('EnergyConsumed', (float) $buffer['EnergyConsumed']);
        }
        if (array_key_exists('OutdoorTemperature', $buffer) && is_numeric($buffer['OutdoorTemperature'])) {
            $this->SetValue('OutdoorTemperature', (float) $buffer['OutdoorTemperature']);
        }
        if (array_key_exists('EnergyTotal', $buffer) && is_numeric($buffer['EnergyTotal'])) {
            $this->SetValue('EnergyTotal', (float) $buffer['EnergyTotal']);
        }
        if (array_key_exists('OutdoorTemperatureLastReading', $buffer) && is_string($buffer['OutdoorTemperatureLastReading'])) {
            $this->SetValue('OutdoorTemperatureLastReading', $buffer['OutdoorTemperatureLastReading']);
        }
        if (array_key_exists('OutdoorTemperatureStale', $buffer)) {
            $this->SetValue('OutdoorTemperatureStale', (bool) $buffer['OutdoorTemperatureStale']);
        }
        $this->updateProtectionVariables($buffer);

        // Betriebsstatus ableiten (nur bei vollständigem Statusdatensatz)
        if (array_key_exists('Power', $buffer)) {
            $this->SetValue('OperatingStatus', $this->deriveOperatingStatus($buffer));
        }

        return '';
    }

    /* -------------------------------------------------------------------------
     * Steuerung
     * ---------------------------------------------------------------------- */

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug('RequestAction', $Ident . '=' . json_encode($Value), 0);

        switch ($Ident) {
            case 'Power':
                $power = (bool) $Value;
                $this->SetValue('Power', $power);
                // Beim Einschalten werden Power und der aktuell gewählte Modus
                // atomar übertragen; dadurch entsteht kein kurzer ungültiger Zustand.
                $control = ['power' => $power];
                if ($power) {
                    $control['operationMode'] = self::MODE_MAP[(int) $this->GetValue('Mode')] ?? 'Automatic';
                }
                $this->queueControl($control);
                break;

            case 'Mode':
                $api = self::MODE_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                if (!$this->supportsMode((int) $Value)) {
                    $this->SendDebug('RequestAction', 'Nicht unterstützter Betriebsmodus: ' . $Value, 0);
                    return;
                }
                $this->SetValue('Mode', (int) $Value);
                $this->applyTemperatureRange((int) $Value);
                $this->queueControl(['power' => (bool) $this->GetValue('Power'), 'operationMode' => $api]);
                break;

            case 'SetTemperature':
                [$tempMin, $tempMax] = $this->temperatureRangeForMode((int) $this->GetValue('Mode'));
                $temp = max($tempMin, min($tempMax, (float) $Value));
                $this->SetValue('SetTemperature', $temp);
                $this->queueControl(['setTemperature' => $temp]);
                break;

            case 'FanSpeed':
                $api = self::FAN_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                if (!$this->supportsFanSpeed((int) $Value)) {
                    $this->SendDebug('RequestAction', 'Nicht unterstützte Lüfterstufe: ' . $Value, 0);
                    return;
                }
                $this->SetValue('FanSpeed', (int) $Value);
                $this->queueControl(['setFanSpeed' => $api]);
                break;

            case 'VaneVertical':
                $api = self::VANE_V_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                if (!$this->supportsVane(false, (int) $Value)) {
                    $this->SendDebug('RequestAction', 'Vertikale Lamellensteuerung nicht unterstützt', 0);
                    return;
                }
                $this->SetValue('VaneVertical', (int) $Value);
                $this->queueControl(['vaneVerticalDirection' => $api]);
                break;

            case 'VaneHorizontal':
                $api = self::VANE_H_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                if (!$this->supportsVane(true, (int) $Value)) {
                    $this->SendDebug('RequestAction', 'Horizontale Lamellensteuerung nicht unterstützt', 0);
                    return;
                }
                $this->SetValue('VaneHorizontal', (int) $Value);
                $this->queueControl(['vaneHorizontalDirection' => $api]);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    /**
     * Puffert ein einzelnes Steuerfeld und (re-)startet den Sammel-Timer. Mehrere
     * Änderungen, die innerhalb von CONTROL_DEBOUNCE_MS eintreffen (z. B. alle Werte
     * einer Symcon-Szene), werden dadurch zu EINEM kombinierten Steuerbefehl
     * zusammengefasst, statt als mehrere separate Teil-Requests an die Cloud zu gehen.
     *
     * @param array<string,mixed> $control Ein Feld, z.B. ['power' => true]
     */
    private function queueControl(array $control): void
    {
        $pending = json_decode($this->GetBuffer('PendingControl'), true);
        if (!is_array($pending)) {
            $pending = [];
        }
        $pending = array_merge($pending, $control);
        $this->SetBuffer('PendingControl', (string) json_encode($pending));
        $this->SetTimerInterval('FlushControl', self::CONTROL_DEBOUNCE_MS);
    }

    /**
     * Timer-Callback (MELA_FlushControl): sendet die zuletzt gesammelten Steuerfelder
     * genau einmal als kombinierten Steuerbefehl an die Cloud, nachdem für
     * CONTROL_DEBOUNCE_MS keine weitere Änderung mehr eingegangen ist.
     */
    public function FlushControl(): void
    {
        $this->SetTimerInterval('FlushControl', 0);

        $pending = json_decode($this->GetBuffer('PendingControl'), true);
        if (!is_array($pending) || $pending === []) {
            return;
        }
        $this->SetBuffer('PendingControl', '');
        $this->control($pending);
    }

    /**
     * Schickt einen Steuerbefehl über den Splitter an die Cloud.
     *
     * @param array<string,mixed> $control
     */
    private function control(array $control): void
    {
        $unitID = $this->ReadPropertyString('UnitID');
        if ($unitID === '') {
            throw new Exception($this->Translate('Device is not configured.'));
        }

        $result = $this->SendDataToParent((string) json_encode([
            'DataID' => self::RX_TO_PARENT,
            'Buffer' => bin2hex((string) json_encode([
                'UnitID'  => $unitID,
                'Control' => $control
            ]))
        ]));

        $decoded = json_decode((string) $result, true);
        if (is_array($decoded) && isset($decoded['success']) && $decoded['success'] === false) {
            $this->LogMessage('MELCloud-Steuerung fehlgeschlagen: ' . ($decoded['error'] ?? 'unbekannt'), KL_ERROR);
        }
    }

    /* -------------------------------------------------------------------------
     * Helfer
     * ---------------------------------------------------------------------- */

    /**
     * Leitet den tatsächlichen Betriebsstatus ab. Die Cloud liefert kein eigenes Feld dafür
     * (z.B. "Kompressor aktiv") – daher wird, wie im Referenz-Home-Assistant-Modul
     * (andrew-blake/melcloudhome, HVACActionDeterminer), die Raum- mit der Solltemperatur
     * verglichen: Nur wenn die Abweichung die Hysterese überschreitet, gilt das Gerät als
     * aktiv heizend/kühlend, sonst als Leerlauf. Ohne diesen Vergleich (Temperaturwerte
     * fehlen) fällt die Anzeige auf den befohlenen Modus zurück.
     */
    private function deriveOperatingStatus(array $buffer): string
    {
        if (!($buffer['Power'] ?? false)) {
            return 'off';
        }
        if ($buffer['InStandbyMode'] ?? false) {
            return 'idle';
        }

        $mode = (string) ($buffer['OperationMode'] ?? '');
        $room = is_numeric($buffer['RoomTemperature'] ?? null) ? (float) $buffer['RoomTemperature'] : null;
        $set  = is_numeric($buffer['SetTemperature'] ?? null) ? (float) $buffer['SetTemperature'] : null;

        if ($room === null || $set === null) {
            switch ($mode) {
                case 'Heat':      return 'heating';
                case 'Cool':      return 'cooling';
                case 'Dry':       return 'drying';
                case 'Fan':       return 'fan';
                case 'Automatic': return 'automatic';
                default:          return 'idle';
            }
        }

        switch ($mode) {
            case 'Heat':
                return $room < $set - self::OPERATING_STATUS_HYSTERESIS ? 'heating' : 'idle';
            case 'Cool':
                return $room > $set + self::OPERATING_STATUS_HYSTERESIS ? 'cooling' : 'idle';
            case 'Automatic':
                if ($room < $set - self::OPERATING_STATUS_HYSTERESIS) {
                    return 'heating';
                }
                if ($room > $set + self::OPERATING_STATUS_HYSTERESIS) {
                    return 'cooling';
                }
                return 'idle';
            case 'Dry':
                return 'drying';
            case 'Fan':
                return 'fan';
            default:
                return 'idle';
        }
    }

    /**
     * @param array<int,string> $map
     */
    private function apiToInt(array $map, string $value, int $default): int
    {
        $key = array_search($value, $map, true);
        return $key === false ? $default : (int) $key;
    }

    private function switchPresentation(): array
    {
        return [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'ICON_FALSE'     => 'power-off',
            'ICON_TRUE'      => 'power-off',
            'USE_ICON_FALSE' => false,
            'GLOW_COLOR'     => 16771899,
            'GLOW_INTENSITY' => 50,
            'USAGE_TYPE'     => 0
        ];
    }

    private function temperaturePresentation(float $min = 16, float $max = 31): array
    {
        $caps = $this->capabilities();
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'TEMPLATE'     => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE,
            'MIN'          => $min,
            'MAX'          => $max,
            'STEP_SIZE'    => $this->capabilityValue($caps, ['hasHalfDegreeIncrements', 'supportsHalfDegree'], true) ? 0.5 : 1.0,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1
        ];
    }

    /**
     * Liefert den vom Gerät unterstützten Solltemperatur-Bereich für einen Betriebsmodus.
     * Datenquelle: das `capabilities`-Objekt aus der MELCloud-/context-Antwort (z.B.
     * minTempHeat/maxTempHeat), das die Connection-Instanz mitsendet. Ohne bekannte
     * Capabilities (z.B. direkt nach dem Anlegen) gilt der bisherige Standardbereich.
     *
     * @return array{0:float,1:float} [Min, Max]
     */
    private function temperatureRangeForMode(int $mode): array
    {
        $caps = json_decode($this->ReadAttributeString('Capabilities'), true);
        if (!is_array($caps)) {
            $caps = [];
        }

        switch ($mode) {
            case 1: // Heat
                $min = $caps['minTempHeat'] ?? 16;
                $max = $caps['maxTempHeat'] ?? 31;
                break;
            case 2: // Cool
            case 3: // Dry
                $min = $caps['minTempCoolDry'] ?? 16;
                $max = $caps['maxTempCoolDry'] ?? 31;
                break;
            case 0: // Automatic
            default:
                $min = $caps['minTempAutomatic'] ?? 16;
                $max = $caps['maxTempAutomatic'] ?? 31;
                break;
        }

        return [(float) $min, (float) $max];
    }

    /**
     * Aktualisiert MIN/MAX des Solltemperatur-Schiebereglers für den aktuellen Modus.
     */
    private function applyTemperatureRange(int $mode): void
    {
        [$min, $max] = $this->temperatureRangeForMode($mode);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('SetTemperature'), $this->temperaturePresentation($min, $max));
    }

    private function applyCapabilityPresentations(): void
    {
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Mode'), $this->modePresentation());
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('FanSpeed'), $this->fanSpeedPresentation());
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VaneVertical'), $this->vaneVerticalPresentation());
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VaneHorizontal'), $this->vaneHorizontalPresentation());
        $this->applyTemperatureRange((int) $this->GetValue('Mode'));
    }

    /** @return array<string,mixed> */
    private function capabilities(): array
    {
        $caps = json_decode($this->ReadAttributeString('Capabilities'), true);
        return is_array($caps) ? $caps : [];
    }

    private function capabilityValue(array $caps, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $caps)) {
                $value = $caps[$key];
                if (is_string($value)) {
                    return !in_array(strtolower($value), ['false', '0', 'no', ''], true);
                }
                return (bool) $value;
            }
        }
        return $default;
    }

    private function supportsMode(int $mode): bool
    {
        $caps = $this->capabilities();
        $keys = match ($mode) {
            0 => ['hasAutoOperationMode', 'hasAutomaticMode', 'hasAutoMode', 'supportsAutomatic'],
            1 => ['hasHeatOperationMode', 'hasHeatingMode', 'hasHeatMode', 'supportsHeating'],
            2 => ['hasCoolOperationMode', 'hasCoolingMode', 'hasCoolMode', 'supportsCooling'],
            3 => ['hasDryOperationMode', 'hasDryMode', 'supportsDry'],
            4 => ['hasFanMode', 'supportsFan'],
            default => []
        };
        return $keys !== [] ? $this->capabilityValue($caps, $keys, true) : false;
    }

    private function supportsFanSpeed(int $speed): bool
    {
        $caps = $this->capabilities();
        if ($speed === 0) {
            return $this->capabilityValue($caps, ['hasAutomaticFanSpeed', 'hasAutoFanSpeed', 'supportsAutoFan'], true);
        }
        $count = (int) ($caps['numberOfFanSpeeds'] ?? $caps['fanSpeedCount'] ?? 5);
        return $speed >= 1 && $speed <= max(1, min(5, $count));
    }

    private function supportsVane(bool $horizontal, int $value): bool
    {
        $caps = $this->capabilities();
        $axisKeys = $horizontal
            ? ['hasHorizontalVane', 'hasHorizontalSwing', 'hasAirDirection', 'supportsHorizontalVane']
            : ['hasVerticalVane', 'hasVerticalSwing', 'hasAirDirection', 'supportsVerticalVane'];
        if (!$this->capabilityValue($caps, $axisKeys, true)) {
            return false;
        }
        if ($value === 7) {
            return $this->capabilityValue($caps, $horizontal ? ['hasHorizontalSwing', 'hasSwing'] : ['hasVerticalSwing', 'hasSwing'], true);
        }
        return true;
    }

    private function normalizeActualFanSpeed(mixed $value): string
    {
        $value = ucfirst(strtolower(trim((string) $value)));
        if ($value === '0' || $value === 'Off') {
            return 'off';
        }
        if (in_array($value, ['One', 'Two', 'Three', 'Four', 'Five'], true)) {
            return strtolower($value);
        }
        if (in_array($value, ['1', '2', '3', '4', '5'], true)) {
            return ['1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five'][$value];
        }
        return 'unknown';
    }

    /** @param array<string,mixed> $buffer */
    private function updateProtectionVariables(array $buffer): void
    {
        $this->updateProtection('FrostProtection', $buffer['FrostProtection'] ?? null, 'FrostProtectionMin', 'FrostProtectionMax');
        $this->updateProtection('OverheatProtection', $buffer['OverheatProtection'] ?? null, 'OverheatProtectionMin', 'OverheatProtectionMax');
        $holiday = is_array($buffer['HolidayMode'] ?? null) ? $buffer['HolidayMode'] : [];
        if ($holiday !== []) {
            $this->SetValue('HolidayMode', (bool) ($holiday['enabled'] ?? false));
            $this->SetValue('HolidayModeActive', (bool) ($holiday['active'] ?? false));
            $this->SetValue('HolidayStart', (string) ($holiday['start'] ?? ''));
            $this->SetValue('HolidayEnd', (string) ($holiday['end'] ?? ''));
        }
    }

    /** @param array<string,mixed>|null $protection */
    private function updateProtection(string $ident, ?array $protection, string $minIdent, string $maxIdent): void
    {
        if ($protection === null) {
            return;
        }
        $this->SetValue($ident, (bool) ($protection['active'] ?? $protection['enabled'] ?? false));
        if (is_numeric($protection['min'] ?? null)) {
            $this->SetValue($minIdent, (float) $protection['min']);
        }
        if (is_numeric($protection['max'] ?? null)) {
            $this->SetValue($maxIdent, (float) $protection['max']);
        }
    }

    private function modePresentation(): array
    {
        $options = [
            [0, $this->Translate('Automatic'), 'arrows-rotate', -1, ['hasAutoOperationMode', 'hasAutomaticMode', 'hasAutoMode', 'supportsAutomatic']],
            [1, $this->Translate('Heat'), 'heat', 0xFF4500, ['hasHeatOperationMode', 'hasHeatingMode', 'hasHeatMode', 'supportsHeating']],
            [2, $this->Translate('Cool'), 'snowflake', 0x1E90FF, ['hasCoolOperationMode', 'hasCoolingMode', 'hasCoolMode', 'supportsCooling']],
            [3, $this->Translate('Dry'), 'droplet', 0x00CED1, ['hasDryOperationMode', 'hasDryMode', 'supportsDry']],
            [4, $this->Translate('Fan'), 'fan', -1, ['hasFanMode', 'supportsFan']]
        ];
        $result = [];
        foreach ($options as $option) {
            if ($this->supportsMode($option[0])) {
                $result[] = array_slice($option, 0, 4);
            }
        }
        return $this->enumerationPresentation($result !== [] ? $result : array_map(fn($o) => array_slice($o, 0, 4), $options));
    }

    private function fanSpeedPresentation(): array
    {
        $caps = $this->capabilities();
        $count = (int) ($caps['numberOfFanSpeeds'] ?? $caps['fanSpeedCount'] ?? 5);
        $count = max(1, min(5, $count));
        $options = [];
        if ($this->capabilityValue($caps, ['hasAutomaticFanSpeed', 'hasAutoFanSpeed', 'supportsAutoFan'], true)) {
            $options[] = [0, $this->Translate('Automatic'), 'fan', -1];
        }
        for ($i = 1; $i <= $count; $i++) {
            $options[] = [$i, (string) $i, '', -1];
        }
        return $this->enumerationPresentation($options);
    }

    private function vaneVerticalPresentation(): array
    {
        if (!$this->supportsVane(false, 0)) {
            return $this->enumerationPresentation([[0, $this->Translate('Not available'), '', -1]], 'arrow-down-from-bracket');
        }
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), '', -1],
            [1, '1', '', -1],
            [2, '2', '', -1],
            [3, '3', '', -1],
            [4, '4', '', -1],
            [5, '5', '', -1],
            [7, $this->Translate('Swing'), '', -1]
        ], 'arrow-down-from-bracket');
    }

    private function vaneHorizontalPresentation(): array
    {
        if (!$this->supportsVane(true, 0)) {
            return $this->enumerationPresentation([[0, $this->Translate('Not available'), '', -1]], 'arrow-right-to-bracket');
        }
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), '', -1],
            [1, $this->Translate('Left'), '', -1],
            [2, $this->Translate('Left-Centre'), '', -1],
            [3, $this->Translate('Centre'), '', -1],
            [4, $this->Translate('Right-Centre'), '', -1],
            [5, $this->Translate('Right'), '', -1],
            [7, $this->Translate('Swing'), '', -1]
        ], 'arrow-right-to-bracket');
    }

    /**
     * Status ist eine reine Anzeige-Variable (kein EnableAction) – Aufzählung
     * (VARIABLE_PRESENTATION_ENUMERATION) ist dafür nicht zulässig ("Diese Darstellung
     * ist nur für Variablen mit einer Variablenaktion verfügbar"). Die Wertedarstellung
     * (VARIABLE_PRESENTATION_VALUE_PRESENTATION) erwartet außerdem Value-Einträge, deren
     * Typ exakt zum Variablentyp passt – daher String-Variable mit String-Values statt
     * Integer (per IPS_GetVariable-Dump einer funktionierenden Referenzvariable bestätigt).
     */
    private function statusPresentation(): array
    {
        return $this->valuePresentation([
            ['off', $this->Translate('Off'), '', -1],
            ['idle', $this->Translate('Idle'), '', -1],
            ['heating', $this->Translate('Heating'), 'heat', 0xFF4500],
            ['cooling', $this->Translate('Cooling'), 'snowflake', 0x1E90FF],
            ['drying', $this->Translate('Drying'), 'droplet', 0x00CED1],
            ['fan', $this->Translate('Ventilating'), 'fan', -1],
            ['automatic', $this->Translate('Automatic'), 'arrows-rotate', -1]
        ]);
    }

    /**
     * Eigene Wertedarstellung statt des Systemprofils ~Alert, dessen Beschriftung
     * ("OK"/"Alarm") nicht änderbar ist. String-Variable (wie OperatingStatus), damit
     * eigene Wert-Idents statt Boolean verwendet werden können.
     */
    private function errorPresentation(): array
    {
        return $this->valuePresentation([
            ['ok', $this->Translate('No fault'), 'play', -1],
            ['fault', $this->Translate('Fault'), 'triangle-exclamation', 0xFF0000]
        ]);
    }

    private function actualFanPresentation(): array
    {
        return $this->valuePresentation([
            ['unknown', $this->Translate('Unknown'), '', -1],
            ['off', $this->Translate('Off'), '', -1],
            ['one', '1', 'fan', -1],
            ['two', '2', 'fan', -1],
            ['three', '3', 'fan', -1],
            ['four', '4', 'fan', -1],
            ['five', '5', 'fan', -1]
        ], 'fan');
    }

    /**
     * Wertedarstellung für den Verbindungsstatus (ersetzt den einfachen Schalter).
     * String-Variable (wie OperatingStatus), damit eigene Wert-Idents statt Boolean
     * verwendet werden können.
     */
    private function connectedPresentation(): array
    {
        return $this->valuePresentation([
            ['disconnected', $this->Translate('Disconnected'), 'wifi-slash', 16077123],
            ['connected', $this->Translate('Connected'), 'wifi', 1692672]
        ], 'wifi');
    }

    /**
     * @param array<int,array{0:bool|string,1:string,2:string,3:int}> $options Je Eintrag: Value, Caption, Icon, Color
     */
    private function valuePresentation(array $options, string $icon = ''): array
    {
        $values = [];
        foreach ($options as $option) {
            $hasIcon  = $option[2] !== '';
            $hasColor = $option[3] !== -1;
            $values[] = [
                'Value'       => $option[0],
                'Caption'     => $option[1],
                'IconActive'  => $hasIcon,
                'IconValue'   => $hasIcon ? $option[2] : '',
                'ColorActive' => $hasColor,
                'ColorValue'  => $hasColor ? $option[3] : -1
            ];
        }
        return [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => $icon,
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW'  => true,
            'OPTIONS'       => json_encode($values)
        ];
    }

    /**
     * Wertedarstellung für rein numerische Anzeige-Variablen (Temperatur, Energie, WLAN-Signal).
     */
    private function numericValuePresentation(string $icon, string $suffix, int $digits, int $usageType = 0, float $max = 100): array
    {
        return [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => $icon,
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 0,
            'DIGITS'        => $digits,
            'MIN'           => 0,
            'MAX'           => $max,
            'PERCENTAGE'    => false,
            'PREFIX'        => '',
            'SUFFIX'        => $suffix,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW'  => true,
            'USAGE_TYPE'    => $usageType
        ];
    }

    /**
     * Baut eine Enumeration-Presentation (ersetzt die alten Custom-Variablenprofile).
     * Für schaltbare Variablen, die als Auswahl-Buttons dargestellt werden.
     *
     * Das OPTIONS-Listenformular des Editors (enumerationForm.php) erwartet je Zeile
     * IconActive (bool, ob das Icon überschrieben wird) und IconValue (Icon-Name) statt
     * eines einfachen "Icon"-Schlüssels – ohne diese Schlüssel meldet der Editor
     * "Undefined array key IconActive". Damit Beschriftung UND Icon angezeigt werden
     * (statt nur Beschriftung), wird zusätzlich DISPLAY=2 (Caption and Icon) gesetzt;
     * LAYOUT=1 (Row) ergibt die segmentierte Button-Reihe.
     *
     * @param array<int,array{0:int,1:string,2:string,3:int}> $options Je Eintrag: Value, Caption, Icon, Color
     */
    private function enumerationPresentation(array $options, string $icon = ''): array
    {
        $values = $this->buildOptionValues($options);
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($values),
            'ICON'         => $icon,
            'LAYOUT'       => 1, // Row
            'DISPLAY'      => 2  // Caption and Icon
        ];
    }

    /**
     * @param array<int,array{0:int,1:string,2:string,3:int}> $options
     * @return array<int,array<string,mixed>>
     */
    private function buildOptionValues(array $options): array
    {
        $values = [];
        foreach ($options as $option) {
            $hasIcon = $option[2] !== '';
            $values[] = [
                'Value'      => $option[0],
                'Caption'    => $option[1],
                'IconActive' => $hasIcon,
                'IconValue'  => $hasIcon ? $option[2] : '',
                'Color'      => $option[3]
            ];
        }
        return $values;
    }

    /**
     * Entfernt die alten, instanzübergreifenden Custom-Variablenprofile aus Vorversionen.
     * Schlägt (abgefangen) fehl, solange noch eine andere, nicht aktualisierte Geräte-Instanz
     * das Profil referenziert – das Profil wird dann beim nächsten ApplyChanges erneut versucht.
     */
    private function removeLegacyProfiles(): void
    {
        foreach (['MELCloud.Mode', 'MELCloud.FanSpeed', 'MELCloud.VaneVertical', 'MELCloud.VaneHorizontal', 'MELCloud.Status', 'MELCloud.Temperature', 'MELCloud.RSSI'] as $profile) {
            if (IPS_VariableProfileExists($profile)) {
                try {
                    IPS_DeleteVariableProfile($profile);
                } catch (Exception $e) {
                    // noch in Benutzung durch eine andere Instanz – beim nächsten Mal erneut versuchen
                }
            }
        }
    }
}
