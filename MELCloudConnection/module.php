<?php

declare(strict_types=1);

require_once __DIR__ . '/MELCloudDataTools.php';

/**
 * MELCloud Connection (Splitter)
 *
 * Hält die Verbindung zur MELCloud Home Cloud (OAuth 2.0 + PKCE), pollt den
 * Gerätestatus über /context und verteilt ihn an die Klimageräte-Instanzen.
 * Steuerbefehle der Kinder werden per ForwardData entgegengenommen und als
 * PUT /monitor/ataunit/{unit} an die Cloud gesendet.
 */
class MELCloudConnection extends IPSModuleStrict
{
    // OAuth / API Endpunkte (abgeleitet aus andrew-blake/melcloudhome)
    private const AUTH_BASE_URL    = 'https://auth.melcloudhome.com';
    private const API_BASE_URL     = 'https://mobile.bff.melcloudhome.com';
    private const WS_TOKEN_URL     = 'https://6x2dgdulg7omjsxalnhmo4ynba0dcgwk.lambda-url.eu-west-1.on.aws/';
    private const WS_URL            = 'wss://ws.melcloudhome.com/';
    private const OAUTH_CLIENT_ID  = 'homemobile';
    private const OAUTH_REDIRECT   = 'melcloudhome://';
    private const OAUTH_SCOPES     = 'openid profile email offline_access IdentityServerApi';
    private const USER_AGENT       = 'MonitorAndControl.App.Mobile/52 CFNetwork/3860.400.51 Darwin/25.3.0';

    // Symcon-Datenfluss: natives WebSocket-I/O -> Connection
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const SIMPLE_RX            = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
    private const SIMPLE_TX            = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    // Datenschnittstelle zu den Kind-Instanzen
    private const TX_TO_CHILD = '{2FD07B1C-5822-48B2-B394-0000776DF537}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);              // Sekunden
        $this->RegisterPropertyInteger('EnergyInterval', 30);              // Minuten
        $this->RegisterPropertyInteger('OutdoorTemperatureInterval', 30);  // Minuten
        $this->RegisterPropertyBoolean('EnableLiveSync', false);

        // Token werden als Attribute (nicht im Formular) gespeichert
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);
        $this->RegisterAttributeString('EnergyState', '{}');
        $this->RegisterAttributeString('LastHttpRequestAt', '0');
        $this->RegisterAttributeInteger('StatusFailureCount', 0);
        $this->RegisterAttributeString('UnitTimeZones', '{}');
        $this->RegisterAttributeString('OutdoorReadings', '{}');
        $this->RegisterAttributeInteger('LiveSyncLastPushAt', 0);
        $this->RegisterAttributeInteger('LiveSyncReconnectCount', 0);
        $this->RegisterAttributeInteger('LiveSyncParentStatus', 0);

        // Keine ~String-Profilreferenz verwenden: Das Profil ist nicht in jeder
        // Symcon-Installation vorhanden. Eine leere Presentation ist portabel.
        $this->RegisterVariableString('LiveSyncStatus', 'Live-Sync Status', [], 90);
        $this->RegisterVariableString('LiveSyncLastPush', 'Letztes Live-Update', [], 91);
        $this->RegisterVariableInteger('LiveSyncReconnects', 'Live-Sync Reconnects', [], 92);

        $this->RegisterTimer('UpdateStatus', 0, 'MELC_UpdateStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateEnergy', 0, 'MELC_UpdateEnergy($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateOutdoorTemperature', 0, 'MELC_UpdateOutdoorTemperature($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LiveSyncConfigure', 0, 'MELC_ConfigureLiveSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LiveSyncRefresh', 0, 'MELC_RefreshLiveSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LiveSyncMonitor', 0, 'MELC_MonitorLiveSync($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        if (!$this->ReadPropertyBoolean('EnableLiveSync')) {
            return '{}';
        }

        return (string) json_encode([
            'type'      => 'require',
            'moduleIDs' => [self::WS_CLIENT_MODULE_ID]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Statuswerte erst nach Create()/Persistenz-Laden initialisieren.
        // Während Create() besitzt die Instanz-Schnittstelle noch nicht
        // zuverlässig den vollständigen Objektbaum.
        if ($this->ReadAttributeInteger('LiveSyncLastPushAt') === 0) {
            $this->SetValue('LiveSyncLastPush', 'Nie');
        }
        $this->SetValue('LiveSyncReconnects', $this->ReadAttributeInteger('LiveSyncReconnectCount'));

        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(104); // inaktiv: Zugangsdaten fehlen
            $this->SetTimerInterval('UpdateStatus', 0);
            $this->SetTimerInterval('UpdateEnergy', 0);
            $this->SetTimerInterval('UpdateOutdoorTemperature', 0);
            $this->SetTimerInterval('LiveSyncConfigure', 0);
            $this->SetTimerInterval('LiveSyncRefresh', 0);
            $this->SetTimerInterval('LiveSyncMonitor', 0);
            $this->setLiveSyncStatus('Deaktiviert');
            return;
        }

        $this->SetStatus(102); // aktiv

        // Status (Solltemperatur, Raumtemperatur, Modus, Lüfter, Vanes, Power) – 60s ist die
        // sinnvolle Untergrenze für MELCloud; aggressiveres Polling (10s/30s) wird unterbunden.
        $statusInterval = max(60, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        // Energieverbrauch – deutlich rate-limit-empfindlicher (bekannte 429-Fehler),
        // daher mindestens 30 Minuten.
        $energyInterval = max(30, $this->ReadPropertyInteger('EnergyInterval')) * 60 * 1000;
        // Der Report-Endpoint liefert stündliche Messwerte und ist rate-limit-empfindlich.
        $outdoorTemperatureInterval = max(30, $this->ReadPropertyInteger('OutdoorTemperatureInterval')) * 60 * 1000;
        $this->SetTimerInterval('UpdateStatus', $statusInterval);
        $this->SetTimerInterval('UpdateEnergy', $energyInterval);
        $this->SetTimerInterval('UpdateOutdoorTemperature', $outdoorTemperatureInterval);

        if ($this->ReadPropertyBoolean('EnableLiveSync')) {
            // Die Hash-Anforderung läuft bewusst in einem Timer, nicht während
            // ApplyChanges. So blockiert die Konfigurationsoberfläche nicht.
            $this->SetTimerInterval('LiveSyncConfigure', 1000);
            $this->SetTimerInterval('LiveSyncMonitor', 30000);
            $this->SetTimerInterval('LiveSyncRefresh', 0);
            $this->setLiveSyncStatus('WebSocket wird eingerichtet');
        } else {
            $this->SetTimerInterval('LiveSyncConfigure', 0);
            $this->SetTimerInterval('LiveSyncRefresh', 0);
            $this->SetTimerInterval('LiveSyncMonitor', 0);
            $this->setLiveSyncStatus('Deaktiviert');
        }
    }

    /**
     * Empfängt die einfachen Datenpakete des nativen Symcon-WebSocket-Clients.
     *
     * MELCloud-Pushdaten werden absichtlich nicht direkt auf Variablen gemappt.
     * Jedes gültige JSON-Ereignis startet nur einen debouncten vollständigen
     * /context-Abruf. Der REST-Poll bleibt unabhängig davon aktiv.
     */
    public function ReceiveData(string $JSONString): string
    {
        if (!$this->ReadPropertyBoolean('EnableLiveSync')) {
            return '';
        }

        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || ($packet['DataID'] ?? '') !== self::SIMPLE_RX) {
            return '';
        }

        $frame = $this->decodeSimpleBuffer($packet['Buffer'] ?? null);
        if ($frame === '' || !is_array(json_decode($frame, true))) {
            $this->SendDebug(__FUNCTION__, 'WebSocket-Frame ohne gültiges JSON ignoriert', 0);
            return '';
        }

        $now = time();
        $this->WriteAttributeInteger('LiveSyncLastPushAt', $now);
        $this->SetValue('LiveSyncLastPush', (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format(DATE_ATOM));
        $this->setLiveSyncStatus('Push empfangen');
        // Debounce: viele Einzel-Frames führen zu höchstens einem /context-Abruf
        // pro Sekunde. Der nächste Push verschiebt den Abruf erneut um eine Sekunde.
        $this->SetTimerInterval('LiveSyncRefresh', 1000);

        return '';
    }

    public function ConfigureLiveSync(): void
    {
        if (!$this->ReadPropertyBoolean('EnableLiveSync') || $this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('LiveSyncConfigure', 0);
            return;
        }

        try {
            $this->configureLiveSyncParent();
            $this->SetTimerInterval('LiveSyncConfigure', 30 * 60 * 1000);
        } catch (Exception $e) {
            $this->setLiveSyncStatus('Polling-Fallback');
            $this->SendDebug(__FUNCTION__, 'WebSocket-Konfiguration fehlgeschlagen: ' . $e->getMessage(), 0);
            // Bei einem abgelaufenen Hash oder temporären MELCloud-Fehlern
            // nicht im Sekundentakt erneut anmelden.
            $retry = str_contains($e->getMessage(), 'Kein nativer Symcon-WebSocket-Client') ? 10 : 5 * 60;
            $this->SetTimerInterval('LiveSyncConfigure', $retry * 1000);
        }
    }

    public function RefreshLiveSync(): void
    {
        $this->SetTimerInterval('LiveSyncRefresh', 0);
        if ($this->ReadPropertyBoolean('EnableLiveSync')) {
            $this->UpdateStatus();
        }
    }

    public function MonitorLiveSync(): void
    {
        if (!$this->ReadPropertyBoolean('EnableLiveSync')) {
            return;
        }
        $this->updateLiveSyncState();
    }

    /* -------------------------------------------------------------------------
     * Öffentliche Aktionen
     * ---------------------------------------------------------------------- */

    /**
     * Ruft alle Daten einmalig ab (Status + Energie + Außentemperatur).
     * Gedacht als Test-Button im Formular.
     */
    public function FetchAll(): void
    {
        $this->UpdateStatus();
        $this->UpdateEnergy();
        $this->UpdateOutdoorTemperature();
        echo $this->Translate('Done. Check the debug output for details.');
    }

    /**
     * Ruft /context, den Energie- und den Trendsummary-Endpunkt für ein Beispielgerät ab,
     * loggt die vollständigen Rohantworten ins Debug und meldet per Popup zusammengefasst,
     * welche von der Cloud gelieferten Felder aktuell NICHT ausgewertet werden. Gedacht, um
     * neue/übersehene API-Felder (z. B. weitere Sensoren, Telemetrie-Kennzahlen) zu finden.
     */
    public function DiagnoseApi(): void
    {
        try {
            $context = $this->fetchContext();
        } catch (Exception $e) {
            echo $this->Translate('Error') . ': ' . $e->getMessage();
            return;
        }

        $this->chunkedDebug('DiagnoseApi/context', (string) json_encode($this->redactSensitiveData($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Felder, die normalizeUnit() bereits auswertet
        $usedUnitKeys    = ['id', 'givenDisplayName', 'displayName', 'rssi', 'settings', 'isConnected', 'isInError', 'capabilities', 'timeZone', 'timezone', 'errorCode', 'frostProtection', 'overheatProtection', 'holidayMode'];
        $usedSettingKeys = ['Power', 'OperationMode', 'SetTemperature', 'RoomTemperature', 'SetFanSpeed', 'ActualFanSpeed', 'VaneVerticalDirection', 'VaneHorizontalDirection', 'InStandbyMode', 'IsInError', 'ErrorCode', 'FrostProtection', 'OverheatProtection', 'HolidayMode'];

        $unusedUnitKeys    = [];
        $unusedSettingKeys = [];
        $otherDeviceLists  = [];
        $unitCount         = 0;

        foreach ($context['buildings'] ?? [] as $building) {
            foreach ($building as $key => $value) {
                if ($key !== 'airToAirUnits' && is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0] ?? null)) {
                    $otherDeviceLists[$key] = ($otherDeviceLists[$key] ?? 0) + count($value);
                }
            }
            foreach ($building['airToAirUnits'] ?? [] as $unit) {
                $unitCount++;
                foreach (array_keys($unit) as $key) {
                    if (!in_array($key, $usedUnitKeys, true)) {
                        $unusedUnitKeys[$key] = true;
                    }
                }
                foreach ($unit['settings'] ?? [] as $s) {
                    $name = $s['name'] ?? null;
                    if ($name !== null && !in_array($name, $usedSettingKeys, true)) {
                        $unusedSettingKeys[$name] = true;
                    }
                }
            }
        }

        // Telemetrie/Trend-Endpunkte stichprobenartig für das erste konfigurierte Gerät prüfen
        $sampleUnit    = $this->getChildUnitIDs()[0] ?? null;
        $energyLabels  = [];
        $trendLabels   = [];

        if ($sampleUnit !== null) {
            try {
                $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $from  = $now->modify('-1 day');
                $query = http_build_query([
                    'from'     => $from->format('Y-m-d H:i'),
                    'to'       => $now->format('Y-m-d H:i'),
                    'interval' => 'Hour',
                    'measure'  => 'cumulative_energy_consumed_since_last_upload'
                ]);
                $response = $this->apiRequest('GET', '/telemetry/telemetry/energy/' . rawurlencode($sampleUnit) . '?' . $query);
                $this->chunkedDebug('DiagnoseApi/energy', $this->redactSensitiveText($response));
                $data = json_decode($response, true);
                foreach ($data['measureData'] ?? [] as $measure) {
                    if (isset($measure['type'])) {
                        $energyLabels[] = (string) $measure['type'];
                    }
                }
            } catch (Exception $e) {
                $this->SendDebug('DiagnoseApi/energy', 'Fehler: ' . $e->getMessage(), 0);
            }

            try {
                $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $from  = $now->modify('-1 day');
                $query = http_build_query([
                    'unitId' => $sampleUnit,
                    'period' => 'Daily',
                    'from'   => $from->format('Y-m-d\TH:i:s.0000000'),
                    'to'     => $now->format('Y-m-d\TH:i:s.0000000')
                ]);
                $response = $this->apiRequest('GET', '/report/v1/trendsummary?' . $query);
                $this->chunkedDebug('DiagnoseApi/trendsummary', $this->redactSensitiveText($response));
                $data = json_decode($response, true);
                if (isset($data[0])) {
                    $data = $data[0];
                }
                foreach ($data['datasets'] ?? [] as $dataset) {
                    $trendLabels[] = (string) ($dataset['label'] ?? '?');
                }
            } catch (Exception $e) {
                $this->SendDebug('DiagnoseApi/trendsummary', 'Fehler: ' . $e->getMessage(), 0);
            }
        }

        $summary   = [];
        $summary[] = $unitCount . ' Klimagerät(e) in /context gefunden.';
        $summary[] = 'Ungenutzte Felder auf Geräte-Ebene: ' . (empty($unusedUnitKeys) ? '(keine)' : implode(', ', array_keys($unusedUnitKeys)));
        $summary[] = 'Ungenutzte settings-Felder: ' . (empty($unusedSettingKeys) ? '(keine)' : implode(', ', array_keys($unusedSettingKeys)));
        if (!empty($otherDeviceLists)) {
            $parts = [];
            foreach ($otherDeviceLists as $key => $count) {
                $parts[] = $key . ' (' . $count . ')';
            }
            $summary[] = 'Weitere Gerätelisten im Konto (nicht unterstützt): ' . implode(', ', $parts);
        }
        $summary[] = 'Telemetrie-Kennzahlen (Energie-Endpoint): ' . (empty($energyLabels) ? '(keine gefunden)' : implode(', ', array_unique($energyLabels)));
        $summary[] = 'Verfügbare Trend-Datasets (Report-Endpoint): ' . (empty($trendLabels) ? '(keine gefunden)' : implode(', ', array_unique($trendLabels)));
        $summary[] = 'Vollständige Rohdaten stehen im Debug-Log (Präfix "DiagnoseApi/...").';

        $text = implode("\n", $summary);
        $this->SendDebug('DiagnoseApi/summary', $text, 0);
        echo $text;
    }

    /**
     * Testet die Anmeldung und meldet das Ergebnis zurück (für Button im Formular).
     */
    public function TestLogin(): void
    {
        try {
            $token = $this->getAccessToken(true);
            if ($token === '') {
                echo $this->Translate('Login failed. Please check your credentials.');
                return;
            }
            $context = $this->fetchContext();
            $devices = $this->extractDevices($context);
            echo sprintf($this->Translate('Login successful. %d air conditioner(s) found.'), count($devices));
            $this->ReloadForm();
        } catch (Exception $e) {
            echo $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /**
     * Pollt den Gerätestatus und verteilt ihn an die Kinder.
     */
    public function UpdateStatus(): void
    {
        $semaphore = 'MELCloudConnectionStatus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            $this->SendDebug(__FUNCTION__, 'Statusabruf bereits aktiv, dieser Lauf wird übersprungen', 0);
            return;
        }
        try {
            $this->updateStatusInternal();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function updateStatusInternal(): void
    {
        try {
            $context = $this->fetchContext();
        } catch (Exception $e) {
            $failures = $this->ReadAttributeInteger('StatusFailureCount') + 1;
            $this->WriteAttributeInteger('StatusFailureCount', $failures);
            $this->SendDebug(__FUNCTION__, sprintf('Statusabruf %d fehlgeschlagen: %s', $failures, $e->getMessage()), 0);
            $base = max(60, $this->ReadPropertyInteger('UpdateInterval'));
            $backoff = min($base * (2 ** max(0, $failures - 1)), 900);
            $this->SetTimerInterval('UpdateStatus', $backoff * 1000);
            // Zwei Ausfälle werden toleriert; Kinder behalten ihre letzten gültigen Werte.
            if ($failures >= 3) {
                $this->SetStatus(201);
            }
            return;
        }

        $this->WriteAttributeInteger('StatusFailureCount', 0);
        $this->SetTimerInterval('UpdateStatus', max(60, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        $devices = $this->extractDevices($context);
        $timeZones = [];
        foreach ($devices as $device) {
            $timeZones[(string) $device['UnitID']] = (string) ($device['TimeZone'] ?? 'Europe/Berlin');
        }
        $this->WriteAttributeString('UnitTimeZones', (string) json_encode($timeZones));
        if ($this->GetStatus() != 102) {
            $this->SetStatus(102);
        }

        $allInstances = IPS_GetInstanceListByModuleID('{73860314-C683-4067-B8BC-00005121318D}');
        $connectedCount = 0;
        foreach ($allInstances as $instID) {
            $connID = IPS_GetInstance($instID)['ConnectionID'];
            $isConnected = ($connID === $this->InstanceID);
            if ($isConnected) {
                $connectedCount++;
            }
            $this->SendDebug('UpdateStatus', 'Instanz #' . $instID . ' ConnectionID=' . $connID . ($isConnected ? ' [verbunden ✓]' : ' [NICHT verbunden, erwartet=' . $this->InstanceID . ']'), 0);
        }
        $this->SendDebug('UpdateStatus', 'Sende an ' . count($devices) . ' Cloud-Geräte, ' . $connectedCount . '/' . count($allInstances) . ' Klimageraet-Instanzen verbunden', 0);
        foreach ($devices as $device) {
            $uid = (string) $device['UnitID'];
            $payload = (string) json_encode([
                'DataID'  => self::TX_TO_CHILD,
                'UnitID'  => $uid,
                'Buffer'  => bin2hex((string) json_encode($device))
            ]);
            $this->SendDebug('UpdateStatus', 'SendDataToChildren UnitID=' . $uid, 0);
            $this->SendDataToChildren($payload);
        }
    }

    /**
     * Pollt den Energieverbrauch je Gerät (seltener) und verteilt ihn an die Kinder.
     */
    public function UpdateEnergy(): void
    {
        foreach ($this->getChildUnitIDs() as $unitID) {
            try {
                $energy = $this->fetchEnergy($unitID);
            } catch (Exception $e) {
                $this->SendDebug(__FUNCTION__, $unitID . ' Energie: ' . $e->getMessage(), 0);
                continue;
            }
            if ($energy['hasData']) {
                $this->sendToChild($unitID, [
                    'EnergyConsumed' => $energy['rolling24hKWh'],
                    'EnergyTotal' => $energy['totalKWh']
                ]);
            }
        }
    }

    /**
     * Pollt die Außentemperatur je Gerät (eigenes, unabhängig konfigurierbares Intervall)
     * und verteilt sie an die Kinder.
     */
    public function UpdateOutdoorTemperature(): void
    {
        foreach ($this->getChildUnitIDs() as $unitID) {
            try {
                $temp = $this->fetchOutdoorTemperature($unitID);
            } catch (Exception $e) {
                $this->SendDebug(__FUNCTION__, $unitID . ' Außentemperatur: ' . $e->getMessage(), 0);
                $this->sendToChild($unitID, ['OutdoorTemperatureStale' => $this->isOutdoorStale($unitID)]);
                continue;
            }
            if ($temp !== null) {
                $readings = json_decode($this->ReadAttributeString('OutdoorReadings'), true);
                if (!is_array($readings)) {
                    $readings = [];
                }
                $readings[$unitID] = $temp['recordedAt'];
                $this->WriteAttributeString('OutdoorReadings', (string) json_encode($readings));
                $this->sendToChild($unitID, [
                    'OutdoorTemperature' => $temp['value'],
                    'OutdoorTemperatureLastReading' => (new DateTimeImmutable('@' . $temp['recordedAt']))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                    'OutdoorTemperatureStale' => (time() - $temp['recordedAt']) > 21600
                ]);
            } else {
                // Den letzten Temperaturwert beibehalten, aber seine Aktualität
                // auch bei einer leeren Antwort sichtbar machen.
                $this->sendToChild($unitID, ['OutdoorTemperatureStale' => $this->isOutdoorStale($unitID)]);
            }
        }
    }

    /**
     * @param array<string,mixed> $fields Zusätzlich zur UnitID zu übertragende Felder.
     */
    private function sendToChild(string $unitID, array $fields): void
    {
        $this->SendDataToChildren((string) json_encode([
            'DataID' => self::TX_TO_CHILD,
            'UnitID' => $unitID,
            'Buffer' => bin2hex((string) json_encode(array_merge(['UnitID' => $unitID], $fields)))
        ]));
    }

    /* -------------------------------------------------------------------------
     * Datenfluss von den Kindern (Steuerbefehle)
     * ---------------------------------------------------------------------- */

    public function ForwardData(string $JSONString): string
    {
        $this->SendDebug('ForwardData', 'Empfangen: ' . substr($JSONString, 0, 300), 0);

        $outer = json_decode($JSONString, true);
        $data  = isset($outer['Buffer']) ? json_decode(hex2bin($outer['Buffer']), true) : null;
        if (!is_array($data) || !isset($data['UnitID'], $data['Control'])) {
            $this->SendDebug('ForwardData', 'Ungültige Anfrage (kein UnitID/Control)', 0);
            return (string) json_encode(['success' => false, 'error' => 'invalid request']);
        }

        try {
            $this->sendControl($data['UnitID'], $data['Control']);
            $this->SendDebug('ForwardData', 'sendControl OK für UnitID=' . $data['UnitID'], 0);
            // Bewusst kein Sofort-Refresh: die Cloud übernimmt den neuen Wert ggf. erst
            // mit Verzögerung, ein sofortiger UpdateStatus() würde den optimistisch
            // gesetzten Wert wieder mit dem alten Cloud-Stand überschreiben.
            return (string) json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Control-Fehler: ' . $e->getMessage(), 0);
            return (string) json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /* -------------------------------------------------------------------------
     * Exportierte Funktion für den Konfigurator
     * ---------------------------------------------------------------------- */

    /**
     * Liefert die Geräteliste als JSON-String an den MELCloud Configurator.
     */
    public function GetDeviceListJSON(): string
    {
        try {
            $context = $this->fetchContext();
            $devices = $this->extractDevices($context);
            return (string) json_encode($devices);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
            return '[]';
        }
    }

    /**
     * @return array<string,int> UnitID => InstanceID der bereits angelegten Geräte
     */
    private function getExistingDeviceInstances(string $deviceModuleID): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID($deviceModuleID) as $instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] !== $this->InstanceID) {
                continue;
            }
            $unitID = IPS_GetProperty($instanceID, 'UnitID');
            if (is_string($unitID) && $unitID !== '') {
                $result[$unitID] = $instanceID;
            }
        }
        return $result;
    }

    /**
     * @return string[] UnitIDs der verbundenen Kind-Instanzen
     */
    private function getChildUnitIDs(): array
    {
        return array_keys($this->getExistingDeviceInstances('{73860314-C683-4067-B8BC-00005121318D}'));
    }

    /* -------------------------------------------------------------------------
     * MELCloud API
     * ---------------------------------------------------------------------- */

    /**
     * Liefert den vollständigen /context-Datensatz.
     */
    private function fetchContext(): array
    {
        $response = $this->apiRequest('GET', '/context');
        $this->SendDebug('fetchContext', 'Rohe Antwort (redigiert, 1500 Zeichen): ' . substr($this->redactSensitiveText($response), 0, 1500), 0);
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Ungültige /context-Antwort');
        }
        $this->SendDebug('fetchContext', 'Top-Level-Keys: ' . implode(', ', array_keys($data)), 0);
        return $data;
    }

    /**
     * Extrahiert die ATA-Klimageräte aus der /context-Antwort.
     *
     * Struktur: context.buildings[*].airToAirUnits[*]
     * Status-Felder stehen im settings-Array als {name, value}-Paare.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractDevices(array $context): array
    {
        $devices = [];
        foreach ($context['buildings'] ?? [] as $building) {
            $buildingTimezone = $building['timezone'] ?? $building['timeZone'] ?? null;
            foreach ($building['airToAirUnits'] ?? [] as $unit) {
                $normalized = $this->normalizeUnit($unit, is_string($buildingTimezone) ? $buildingTimezone : null);
                if ($normalized !== null) {
                    $devices[] = $normalized;
                }
            }
        }
        $this->SendDebug('extractDevices', count($devices) . ' Geräte gefunden', 0);
        return $devices;
    }

    private function normalizeUnit(array $unit, ?string $buildingTimezone = null): ?array
    {
        $unitID = $unit['id'] ?? null;
        if ($unitID === null) {
            return null;
        }

        // settings ist ein [{name, value}]-Array → in eine Map umwandeln
        $settings = [];
        foreach ($unit['settings'] ?? [] as $s) {
            if (isset($s['name'])) {
                $settings[$s['name']] = $s['value'] ?? null;
            }
        }

        $power = isset($settings['Power']) ? strtolower((string) $settings['Power']) !== 'false' : false;
        $errorCode = $settings['ErrorCode'] ?? $unit['errorCode'] ?? null;
        // MELCloud liefert isInError auf Geräteebene. Das ältere settings-Feld
        // bleibt als Fallback erhalten, falls es bei einzelnen ATA-Modellen noch
        // vorhanden ist.
        $isInError = array_key_exists('isInError', $unit) && $unit['isInError'] !== null
            ? $this->toBoolean($unit['isInError'])
            : $this->toBoolean($settings['IsInError'] ?? false);
        if ($errorCode !== null && (string) $errorCode !== '' && (string) $errorCode !== '0') {
            $isInError = true;
        }

        // Pro Modus unterschiedlicher Solltemperatur-Bereich (z. B. Heizen bis 10 °C
        // möglich, Kühlen/Trocknen/Automatik meist erst ab 16 °C) – aus capabilities.
        $capabilities = is_array($unit['capabilities'] ?? null) ? $unit['capabilities'] : [];

        return [
            'UnitID'                  => (string) $unitID,
            'Name'                    => $unit['givenDisplayName'] ?? $unit['displayName'] ?? (string) $unitID,
            'Power'                   => $power,
            'OperationMode'           => $settings['OperationMode'] ?? null,
            'SetTemperature'          => isset($settings['SetTemperature']) ? (float) $settings['SetTemperature'] : null,
            'RoomTemperature'         => isset($settings['RoomTemperature']) ? (float) $settings['RoomTemperature'] : null,
            'SetFanSpeed'             => $settings['SetFanSpeed'] ?? null,
            'ActualFanSpeed'          => $settings['ActualFanSpeed'] ?? null,
            'VaneVerticalDirection'   => $settings['VaneVerticalDirection'] ?? null,
            'VaneHorizontalDirection' => $settings['VaneHorizontalDirection'] ?? null,
            'InStandbyMode'           => isset($settings['InStandbyMode']) && strtolower((string) $settings['InStandbyMode']) !== 'false',
            'IsInError'               => $isInError,
            'ErrorCode'               => $errorCode,
            'TimeZone'                => $this->resolveTimeZone($unit['timeZone'] ?? $unit['timezone'] ?? null, $buildingTimezone),
            'FrostProtection'         => $this->normalizeProtection($unit, $settings, ['frostProtection', 'FrostProtection']),
            'OverheatProtection'      => $this->normalizeProtection($unit, $settings, ['overheatProtection', 'OverheatProtection']),
            'HolidayMode'             => $this->normalizeProtection($unit, $settings, ['holidayMode', 'HolidayMode']),
            // rssi liegt auf Geräte-Ebene, nicht im settings-Array (bestätigt über
            // andrew-blake/melcloudhome: AirToAirUnit.rssi <- data.get("rssi")).
            'rssi'                    => isset($unit['rssi']) ? (int) $unit['rssi'] : null,
            // isConnected liegt ebenfalls auf Geräte-Ebene (bool); fällt das Gerät aus der
            // Cloud-Antwort weg, wird es in extractDevices() ohnehin nicht mehr gemeldet –
            // hier geht es um den tatsächlichen WLAN-/Cloud-Verbindungsstatus des Geräts.
            'Connected'               => !isset($unit['isConnected']) || (bool) $unit['isConnected'],
            'Capabilities'            => $capabilities
        ];
    }

    /**
     * Sendet einen Steuerbefehl an ein Gerät.
     *
     * @param array<string,mixed> $control Teilmenge der steuerbaren Felder.
     */
    private function sendControl(string $unitID, array $control): void
    {
        // Vollständiger Body, nicht gesetzte Felder bleiben null (analog HA-Modul)
        $body = [
            'power'                       => null,
            'operationMode'               => null,
            'setFanSpeed'                 => null,
            'vaneHorizontalDirection'     => null,
            'vaneVerticalDirection'       => null,
            'setTemperature'              => null,
            'temperatureIncrementOverride' => null,
            'inStandbyMode'               => null
        ];
        foreach ($control as $key => $value) {
            if (array_key_exists($key, $body)) {
                $body[$key] = $value;
            }
        }

        $this->apiRequest('PUT', '/monitor/ataunit/' . rawurlencode($unitID), $body);
    }

    /**
     * Holt den (kumulierten) Energieverbrauch eines Geräts in kWh (letzte 24h, stündlich).
     */
    private function fetchEnergy(string $unitID): array
    {
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $now->modify('-2 days');
        $query = http_build_query([
            'from'     => $from->format('Y-m-d H:i'),
            'to'       => $now->format('Y-m-d H:i'),
            'interval' => 'Hour',
            'measure'  => 'cumulative_energy_consumed_since_last_upload'
        ]);

        $response = $this->apiRequest('GET', '/telemetry/telemetry/energy/' . rawurlencode($unitID) . '?' . $query);
        $data     = json_decode($response, true);

        // Antwortstruktur: measureData -> values -> [{ value }]. Die Werte kommen in Wh
        // (bestätigt über andrew-blake/melcloudhome), nicht in kWh – daher /1000.
        if (!is_array($data)) {
            throw new Exception('Ungültige Energie-Antwort');
        }
        $entries = MELCloudDataTools::parseEnergyEntries($data, $now);
        if ($entries === []) {
            $this->SendDebug(__FUNCTION__, $unitID . ' keine gültigen Messwerte', 0);
            return ['hasData' => false, 'rolling24hKWh' => 0.0, 'totalKWh' => 0.0];
        }
        $allState = json_decode($this->ReadAttributeString('EnergyState'), true);
        if (!is_array($allState)) {
            $allState = [];
        }
        $updated = MELCloudDataTools::updateEnergyState($allState[$unitID] ?? [], $entries, $now->getTimestamp());
        $allState[$unitID] = $updated['state'];
        $this->WriteAttributeString('EnergyState', (string) json_encode($allState));
        if ($updated['rejected'] > 0) {
            $this->SendDebug(__FUNCTION__, $unitID . ' rückläufige Einzelwerte verworfen: ' . $updated['rejected'], 0);
        }
        $this->SendDebug(__FUNCTION__, sprintf('%s: 24h=%.3f kWh, kumulativ=%.3f kWh, Delta=%.3f kWh', $unitID, $updated['rolling24hKWh'], $updated['state']['totalKWh'], $updated['deltaKWh']), 0);
        return [
            'hasData' => true,
            'rolling24hKWh' => $updated['rolling24hKWh'],
            'totalKWh' => $updated['state']['totalKWh']
        ];
    }

    /**
     * Holt die Außentemperatur eines Geräts über den trendsummary-Endpunkt.
     * Gibt null zurück, wenn das Gerät keinen Außensensor hat oder keine Daten vorliegen.
     */
    private function fetchOutdoorTemperature(string $unitID): ?array
    {
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $now->modify('-2 days');
        $query = http_build_query([
            'unitId' => $unitID,
            'period' => 'Hourly',
            'from'   => $from->format('Y-m-d\TH:i:s.0000000\Z'),
            'to'     => $now->format('Y-m-d\TH:i:s.0000000\Z')
        ]);

        $response = $this->apiRequest('GET', '/report/v1/trendsummary?' . $query);
        $this->SendDebug('fetchOutdoorTemperature', $unitID . ' Antwort (redigiert, 300 Zeichen): ' . substr($this->redactSensitiveText($response), 0, 300), 0);
        $data = json_decode($response, true);

        // Mobile BFF liefert die Antwort teilweise als Ein-Element-Liste.
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data) || !isset($data['datasets']) || !is_array($data['datasets'])) {
            $this->SendDebug('fetchOutdoorTemperature', $unitID . ' kein datasets-Feld', 0);
            return null;
        }

        $zones = json_decode($this->ReadAttributeString('UnitTimeZones'), true);
        $timezone = is_array($zones) ? (string) ($zones[$unitID] ?? 'Europe/Berlin') : 'Europe/Berlin';
        $reading = MELCloudDataTools::parseOutdoorReading($data, $timezone, $now->getTimestamp());
        if ($reading !== null) {
            $this->SendDebug('fetchOutdoorTemperature', $unitID . ' = ' . $reading['value'] . ' °C, Messzeit ' . gmdate(DATE_ATOM, $reading['recordedAt']), 0);
            return $reading;
        }

        $labels = implode(', ', array_map(fn($d) => $d['label'] ?? '?', $data['datasets']));
        $this->SendDebug('fetchOutdoorTemperature', $unitID . ' OUTDOOR_TEMPERATURE nicht gefunden – Labels: ' . $labels, 0);
        return null;
    }

    /**
     * Loggt langen Text (z. B. vollständige JSON-Rohantworten) in mehreren SendDebug-
     * Aufrufen, da die Debug-Konsole einzelne Nachrichten sonst abschneidet.
     */
    private function chunkedDebug(string $sender, string $text, int $chunkSize = 3000): void
    {
        $chunks = str_split($text, $chunkSize) ?: [''];
        foreach ($chunks as $i => $chunk) {
            $this->SendDebug($sender . ' (' . ($i + 1) . '/' . count($chunks) . ')', $chunk, 0);
        }
    }

    /** @return array<string,mixed> */
    private function redactSensitiveData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, ['email', 'password', 'accesstoken', 'refreshtoken', 'token', 'hash', 'authorization', 'givendisplayname', 'displayname', 'address', 'firstname', 'lastname', 'username'], true)) {
                $result[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function redactSensitiveText(string $text): string
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return (string) json_encode($this->redactSensitiveData($decoded), JSON_UNESCAPED_UNICODE);
        }
        return preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $text) ?? $text;
    }

    /** @param array<int,string> $keys @return array<string,mixed> */
    private function normalizeProtection(array $unit, array $settings, array $keys): ?array
    {
        $source = null;
        foreach ($keys as $key) {
            if (is_array($unit[$key] ?? null)) {
                $source = $unit[$key];
                break;
            }
            if (is_array($settings[$key] ?? null)) {
                $source = $settings[$key];
                break;
            }
        }
        if (!is_array($source)) {
            return null;
        }
        return [
            'enabled' => $this->toBoolean($source['enabled'] ?? $source['isEnabled'] ?? false),
            'active' => $this->toBoolean($source['active'] ?? $source['isActive'] ?? false)
        ];
    }

    private function resolveTimeZone(mixed $unitTimezone, ?string $buildingTimezone): string
    {
        foreach ([$unitTimezone, $buildingTimezone, 'Europe/Berlin'] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            try {
                new DateTimeZone($candidate);
                return $candidate;
            } catch (Exception) {
                // Ungültige Cloud-Zeitzone: nächste Fallback-Stufe prüfen.
            }
        }
        return 'Europe/Berlin';
    }

    private function isOutdoorStale(string $unitID): bool
    {
        $readings = json_decode($this->ReadAttributeString('OutdoorReadings'), true);
        $last = is_array($readings) ? (int) ($readings[$unitID] ?? 0) : 0;
        return $last <= 0 || (time() - $last) > 21600;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['false', '0', 'no', ''], true);
        }
        return (bool) $value;
    }

    private function configureLiveSyncParent(): void
    {
        $parentID = $this->getLiveSyncParentID();
        if ($parentID === 0) {
            throw new Exception('Kein nativer Symcon-WebSocket-Client als Parent verbunden');
        }

        $users = 0;
        foreach (IPS_GetInstanceList() as $instanceID) {
            if ((int) $instanceID === $this->InstanceID) {
                continue;
            }
            if ((int) (IPS_GetInstance($instanceID)['ConnectionID'] ?? 0) === $parentID) {
                $users++;
            }
        }
        if ($users > 0) {
            throw new Exception('WebSocket-Parent wird bereits von einer anderen Instanz verwendet');
        }

        $hash = $this->fetchWebSocketHash();
        $url = self::WS_URL . '?hash=' . rawurlencode($hash);
        $changed = false;

        if ((string) IPS_GetProperty($parentID, 'URL') !== $url) {
            IPS_SetProperty($parentID, 'URL', $url);
            $changed = true;
        }
        if (!(bool) IPS_GetProperty($parentID, 'VerifyCertificate')) {
            IPS_SetProperty($parentID, 'VerifyCertificate', true);
            $changed = true;
        }
        if (!(bool) IPS_GetProperty($parentID, 'Active')) {
            IPS_SetProperty($parentID, 'Active', true);
            $changed = true;
        }
        if ($changed) {
            IPS_ApplyChanges($parentID);
        }

        $this->SendDebug(__FUNCTION__, 'WebSocket-URL aktualisiert (Hash-Länge ' . strlen($hash) . ')', 0);
        $this->updateLiveSyncState();
    }

    private function fetchWebSocketHash(): string
    {
        $token = $this->getAccessToken();
        if ($token === '') {
            throw new Exception('Keine gültige Anmeldung für den WebSocket-Hash');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT
        ];
        [$status, $response] = $this->httpRequest('GET', self::WS_TOKEN_URL, $headers);

        if ($status === 401) {
            $token = $this->getAccessToken(true);
            if ($token === '') {
                throw new Exception('WebSocket-Hash abgelehnt (HTTP 401)');
            }
            $headers[0] = 'Authorization: Bearer ' . $token;
            [$status, $response] = $this->httpRequest('GET', self::WS_TOKEN_URL, $headers);
        }

        if ($status === 429) {
            throw new Exception('WebSocket-Hash Rate-Limit erreicht (HTTP 429)');
        }
        if ($status >= 500) {
            throw new Exception('MELCloud-Serverfehler beim WebSocket-Hash (HTTP ' . $status . ')');
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception('WebSocket-Hash fehlgeschlagen (HTTP ' . $status . ')');
        }

        $data = json_decode($response, true);
        $hash = is_array($data) ? (string) ($data['hash'] ?? '') : '';
        if ($hash === '') {
            throw new Exception('WebSocket-Hash fehlt in der Serverantwort');
        }
        return $hash;
    }

    private function getLiveSyncParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentID = (int) ($instance['ConnectionID'] ?? 0);
        if ($parentID <= 0) {
            return 0;
        }

        $parent = IPS_GetInstance($parentID);
        return (($parent['ModuleInfo']['ModuleID'] ?? '') === self::WS_CLIENT_MODULE_ID) ? $parentID : 0;
    }

    private function updateLiveSyncState(): void
    {
        if (!$this->ReadPropertyBoolean('EnableLiveSync')) {
            $this->setLiveSyncStatus('Deaktiviert');
            return;
        }

        $parentID = $this->getLiveSyncParentID();
        if ($parentID === 0) {
            $this->setLiveSyncStatus('Polling-Fallback');
            return;
        }

        $status = (int) (IPS_GetInstance($parentID)['InstanceStatus'] ?? 0);
        $previousStatus = $this->ReadAttributeInteger('LiveSyncParentStatus');
        if ($previousStatus !== 0 && $previousStatus !== 102 && $status === 102) {
            $reconnects = $this->ReadAttributeInteger('LiveSyncReconnectCount') + 1;
            $this->WriteAttributeInteger('LiveSyncReconnectCount', $reconnects);
            $this->SetValue('LiveSyncReconnects', $reconnects);
        }
        $this->WriteAttributeInteger('LiveSyncParentStatus', $status);

        $this->setLiveSyncStatus($status === 102 ? 'WebSocket verbunden' : 'Polling-Fallback');
    }

    private function setLiveSyncStatus(string $status): void
    {
        $this->SetValue('LiveSyncStatus', $status);
    }

    private function decodeSimpleBuffer(mixed $buffer): string
    {
        if (!is_string($buffer) || $buffer === '') {
            return '';
        }

        // IPSModuleStrict nutzt bei Datenflüssen HEX. Ältere/native I/O-Varianten
        // liefern den Buffer dagegen direkt als UTF-8. Beide Formen werden akzeptiert.
        if ((strlen($buffer) % 2) === 0 && preg_match('/^[0-9a-f]+$/i', $buffer) === 1) {
            $decoded = hex2bin($buffer);
            if ($decoded !== false && is_array(json_decode($decoded, true))) {
                return $decoded;
            }
        }
        return $buffer;
    }

    /**
     * Führt einen authentifizierten API-Request aus und liefert den Body zurück.
     */
    private function apiRequest(string $method, string $path, ?array $body = null): string
    {
        $token = $this->getAccessToken();
        if ($token === '') {
            throw new Exception('Keine gültige Anmeldung');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        [$status, $response] = $this->httpRequest($method, self::API_BASE_URL . $path, $headers, $body === null ? null : json_encode($body));

        if ($status === 401) {
            // Token erneuern und einmal wiederholen
            $token = $this->getAccessToken(true);
            if ($token === '') {
                throw new Exception('Authentifizierung abgelehnt (HTTP 401)');
            }
            $headers[0] = 'Authorization: Bearer ' . $token;
            [$status, $response] = $this->httpRequest($method, self::API_BASE_URL . $path, $headers, $body === null ? null : json_encode($body));
        }

        if ($status < 200 || $status >= 300) {
            if ($status === 401) {
                throw new Exception('Authentifizierung abgelehnt (HTTP 401)');
            }
            if ($status === 429) {
                throw new Exception('MELCloud Rate-Limit erreicht (HTTP 429)');
            }
            if ($status >= 500) {
                throw new Exception('MELCloud-Serverfehler (HTTP ' . $status . ')');
            }
            throw new Exception(sprintf('MELCloud-API-Fehler HTTP %d bei %s %s', $status, $method, $path));
        }

        return $response;
    }

    /* -------------------------------------------------------------------------
     * OAuth 2.0 Authorization Code + PKCE
     * ---------------------------------------------------------------------- */

    /**
     * Liefert ein gültiges Access-Token (refresht/loggt bei Bedarf neu ein).
     */
    private function getAccessToken(bool $forceRefresh = false): string
    {
        $semaphore = 'MELCloudConnectionAuth_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            throw new Exception('Token-Erneuerung bereits aktiv');
        }
        try {
            return $this->getAccessTokenUnlocked($forceRefresh);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function getAccessTokenUnlocked(bool $forceRefresh = false): string
    {
        $now    = time();
        $access = $this->ReadAttributeString('AccessToken');
        $expiry = $this->ReadAttributeInteger('TokenExpiry');

        if (!$forceRefresh && $access !== '' && $expiry > $now + 60) {
            return $access;
        }

        // 1. Versuch: Refresh-Token verwenden
        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh !== '') {
            $tokens = $this->refreshTokens($refresh);
            if ($tokens !== null) {
                $this->storeTokens($tokens);
                return $tokens['access_token'];
            }
        }

        // 2. Versuch: Vollständiger Login
        $tokens = $this->login();
        if ($tokens === null) {
            return '';
        }
        $this->storeTokens($tokens);
        return $tokens['access_token'];
    }

    private function storeTokens(array $tokens): void
    {
        $this->WriteAttributeString('AccessToken', $tokens['access_token']);
        if (!empty($tokens['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', $tokens['refresh_token']);
        }
        $expiresIn = isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : 3600;
        $this->WriteAttributeInteger('TokenExpiry', time() + $expiresIn);
    }

    private function refreshTokens(string $refreshToken): ?array
    {
        [$status, $response] = $this->httpRequest(
            'POST',
            self::AUTH_BASE_URL . '/connect/token',
            ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
            http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => self::OAUTH_CLIENT_ID
            ])
        );

        if ($status !== 200) {
            if ($status === 429) {
                throw new Exception('MELCloud Rate-Limit bei Token-Erneuerung (HTTP 429)');
            }
            if ($status >= 500) {
                throw new Exception('MELCloud-Serverfehler bei Token-Erneuerung (HTTP ' . $status . ')');
            }
            $this->SendDebug(__FUNCTION__, 'Refresh fehlgeschlagen: HTTP ' . $status, 0);
            return null;
        }
        $tokens = json_decode($response, true);
        return isset($tokens['access_token']) ? $tokens : null;
    }

    private function login(): ?array
    {
        $email    = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        if ($email === '' || $password === '') {
            return null;
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'melc_');
        $this->SendDebug('login', 'Start – Zugangsdaten vorhanden', 0);

        try {
            // Schritt 1: PAR
            $codeVerifier  = $this->base64Url(random_bytes(48));
            $codeChallenge = $this->base64Url(hash('sha256', $codeVerifier, true));
            $state         = $this->base64Url(random_bytes(16));

            $this->SendDebug('login/1-PAR', 'POST ' . self::AUTH_BASE_URL . '/connect/par', 0);
            [$status, $response] = $this->httpRequest(
                'POST',
                self::AUTH_BASE_URL . '/connect/par',
                ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
                http_build_query([
                    'response_type'         => 'code',
                    'client_id'             => self::OAUTH_CLIENT_ID,
                    'redirect_uri'          => self::OAUTH_REDIRECT,
                    'scope'                 => self::OAUTH_SCOPES,
                    'state'                 => $state,
                    'code_challenge'        => $codeChallenge,
                    'code_challenge_method' => 'S256'
                ]),
                $cookieJar
            );
            $this->SendDebug('login/1-PAR', 'HTTP ' . $status . ' – Body: ' . substr($response, 0, 300), 0);
            if ($status !== 201 && $status !== 200) {
                throw new Exception('PAR fehlgeschlagen: HTTP ' . $status);
            }
            $par = json_decode($response, true);
            if (!isset($par['request_uri'])) {
                throw new Exception('PAR ohne request_uri – Antwort: ' . substr($response, 0, 200));
            }
            $this->SendDebug('login/1-PAR', 'request_uri: ' . $par['request_uri'], 0);

            // Schritt 2: Authorize → Loginseite
            $authorizeUrl = self::AUTH_BASE_URL . '/connect/authorize?' . http_build_query([
                'client_id'   => self::OAUTH_CLIENT_ID,
                'request_uri' => $par['request_uri']
            ]);
            $this->SendDebug('login/2-Authorize', 'URL: ' . $authorizeUrl, 0);
            $loginPage = $this->followToLoginPage($authorizeUrl, $cookieJar, $loginUrl);
            $this->SendDebug('login/2-Authorize', 'Finale Login-URL: ' . $loginUrl, 0);
            $this->SendDebug('login/2-Authorize', 'Seiteninhalt (500 Zeichen): ' . substr(strip_tags($loginPage), 0, 500), 0);
            if ($loginUrl === '') {
                throw new Exception('Cognito-Loginseite nicht erreicht – Seiteninhalt: ' . substr($loginPage, 0, 300));
            }

            // Schritt 3: CSRF
            $csrf = $this->extractCsrf($loginPage);
            $this->SendDebug('login/3-CSRF', $csrf !== '' ? 'Gefunden: ' . substr($csrf, 0, 20) . '…' : 'NICHT gefunden – möglicherweise anderes CSRF-Feld', 0);
            if ($csrf === '') {
                // Alle input-Felder im HTML loggen für Diagnose
                preg_match_all('/<input[^>]+>/i', $loginPage, $inputs);
                $this->SendDebug('login/3-CSRF', 'HTML-input-Felder: ' . implode(' | ', array_map(fn($t) => strip_tags('<x ' . $t . '>'), array_slice($inputs[0], 0, 20))), 0);
            }

            // Schritt 4: Zugangsdaten senden
            $this->SendDebug('login/4-Submit', 'POST an: ' . $loginUrl . ' (CSRF: ' . ($csrf !== '' ? 'ja' : 'nein') . ')', 0);
            $code = $this->submitCredentials($loginUrl, $csrf, $email, $password, $cookieJar);
            $this->SendDebug('login/4-Submit', $code !== '' ? 'Auth-Code erhalten (Länge ' . strlen($code) . ')' : 'KEIN Auth-Code erhalten', 0);
            if ($code === '') {
                throw new Exception('Kein Auth-Code erhalten (Zugangsdaten prüfen)');
            }

            // Schritt 5: Token-Tausch
            $this->SendDebug('login/5-Token', 'POST ' . self::AUTH_BASE_URL . '/connect/token', 0);
            [$status, $response] = $this->httpRequest(
                'POST',
                self::AUTH_BASE_URL . '/connect/token',
                ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
                http_build_query([
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => self::OAUTH_REDIRECT,
                    'code_verifier' => $codeVerifier,
                    'client_id'     => self::OAUTH_CLIENT_ID
                ]),
                $cookieJar
            );
            $this->SendDebug('login/5-Token', 'HTTP ' . $status . ' – Body: ' . substr($this->redactSensitiveText($response), 0, 200), 0);
            if ($status !== 200) {
                throw new Exception('Token-Tausch fehlgeschlagen: HTTP ' . $status . ' – ' . substr($response, 0, 200));
            }
            $tokens = json_decode($response, true);
            if (!isset($tokens['access_token'])) {
                throw new Exception('Kein access_token in Antwort: ' . substr($response, 0, 200));
            }
            $this->SendDebug('login/5-Token', 'Erfolgreich – Token-Typ: ' . ($tokens['token_type'] ?? '?') . ', gültig: ' . ($tokens['expires_in'] ?? '?') . 's', 0);
            return $tokens;
        } catch (Exception $e) {
            $this->SendDebug('login', 'FEHLER: ' . $e->getMessage(), 0);
            $this->LogMessage('MELCloud-Login fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            return null;
        } finally {
            if (is_string($cookieJar) && file_exists($cookieJar)) {
                if (!unlink($cookieJar)) {
                    $this->SendDebug(__FUNCTION__, 'Temporäre Cookie-Datei konnte nicht gelöscht werden', 0);
                }
            }
        }
    }

    private function followToLoginPage(string $url, string $cookieJar, ?string &$finalUrl = null): string
    {
        $finalUrl = '';
        for ($hop = 0; $hop < 10; $hop++) {
            $this->SendDebug('followToLoginPage', 'Hop ' . $hop . ': GET ' . $url, 0);
            [$status, $body, $location, $effectiveUrl] = $this->httpRequestRaw('GET', $url, ['User-Agent: ' . self::USER_AGENT], null, $cookieJar);
            $this->SendDebug('followToLoginPage', 'Hop ' . $hop . ': HTTP ' . $status . ' – Location: ' . ($location ?: '(keine)') . ' – Body: ' . strlen($body) . ' Bytes', 0);

            if ($location !== '') {
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    $this->SendDebug('followToLoginPage', 'Sofort-Redirect mit Auth-Code erkannt', 0);
                    $finalUrl = $location;
                    return $body;
                }
                $url = $this->resolveUrl($url, $location);
                continue;
            }

            $finalUrl = $effectiveUrl !== '' ? $effectiveUrl : $url;
            return $body;
        }
        $this->SendDebug('followToLoginPage', 'Zu viele Weiterleitungen (>10)', 0);
        return '';
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf[-_]?token"\s+value="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        // Weitere bekannte Varianten
        if (preg_match('/["\']csrf["\']\s*:\s*["\']([^"\']+)["\']/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    private function submitCredentials(string $loginUrl, string $csrf, string $email, string $password, string $cookieJar): string
    {
        $postFields = http_build_query([
            '_csrf'          => $csrf,
            'username'       => $email,
            'password'       => $password,
            'cognitoAsfData' => ''
        ]);

        $url    = $loginUrl;
        $method = 'POST';
        $data   = $postFields;

        for ($hop = 0; $hop < 12; $hop++) {
            $headers = ['User-Agent: ' . self::USER_AGENT, 'Referer: ' . $loginUrl];
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }

            $this->SendDebug('submitCredentials', 'Hop ' . $hop . ': ' . $method . ' ' . $url, 0);
            [$status, $body, $location] = $this->httpRequestRaw($method, $url, $headers, $data, $cookieJar);
            $this->SendDebug('submitCredentials', 'Hop ' . $hop . ': HTTP ' . $status . ' – Location: ' . ($location ?: '(keine)') . ' – Body: ' . strlen($body) . ' Bytes', 0);

            if ($location !== '') {
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    $this->SendDebug('submitCredentials', 'Redirect-URL mit Auth-Code: ' . substr($location, 0, 120), 0);
                    return $this->extractCodeFromUrl($location);
                }
                $url    = $this->resolveUrl($url, $location);
                $method = 'GET';
                $data   = null;
                continue;
            }

            // Kein Redirect – Body auf Code/Fehler prüfen
            $this->SendDebug('submitCredentials', 'Kein Redirect – Body (400 Zeichen): ' . substr(strip_tags($body), 0, 400), 0);
            $code = $this->extractCodeFromHtml($body);
            if ($code !== '') {
                $this->SendDebug('submitCredentials', 'Auth-Code aus HTML-Body extrahiert', 0);
                return $code;
            }

            // JavaScript-Redirect-Seite: RedirectUri aus aktuellem URL-Parameter auslesen
            $nextUrl = $this->extractJsRedirect($body, $url);
            if ($nextUrl !== '') {
                $this->SendDebug('submitCredentials', 'JS-Redirect folgen: ' . substr($nextUrl, 0, 150), 0);
                $url    = $nextUrl;
                $method = 'GET';
                $data   = null;
                continue;
            }

            break;
        }
        return '';
    }

    private function extractJsRedirect(string $body, string $currentUrl): string
    {
        // window.location / window.location.href = "..."
        if (preg_match('/window\.location(?:\.href)?\s*=\s*["\']([^"\']{5,})["\']/', $body, $m)) {
            return $this->resolveUrl($currentUrl, $m[1]);
        }
        // <meta http-equiv="refresh" content="0; url=...">
        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^;]+;\s*url=([^"\'>\s]+)/i', $body, $m)) {
            return $this->resolveUrl($currentUrl, html_entity_decode($m[1]));
        }
        // RedirectUri=... Parameter aus der aktuellen URL
        $query = parse_url($currentUrl, PHP_URL_QUERY) ?? '';
        if ($query !== '') {
            parse_str($query, $params);
            if (!empty($params['RedirectUri'])) {
                return $this->resolveUrl($currentUrl, $params['RedirectUri']);
            }
        }
        return '';
    }

    private function extractCodeFromUrl(string $url): string
    {
        // PHP parse_url() cannot handle custom schemes like melcloudhome://, use regex first
        if (preg_match('/[?&]code=([^&\s#]+)/', $url, $m)) {
            return urldecode($m[1]);
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query !== null && $query !== false && $query !== '') {
            parse_str($query, $params);
            if (!empty($params['code'])) {
                return $params['code'];
            }
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT) ?: '';
        if ($fragment !== '') {
            parse_str($fragment, $params);
            if (!empty($params['code'])) {
                return $params['code'];
            }
        }
        return '';
    }

    private function extractCodeFromHtml(string $html): string
    {
        if (preg_match('/[?&#]code=([^"&\'<>\s]+)/', $html, $m)) {
            return urldecode($m[1]);
        }
        return '';
    }

    /* -------------------------------------------------------------------------
     * HTTP-Hilfsfunktionen
     * ---------------------------------------------------------------------- */

    /**
     * Einfacher Request mit automatischem Folgen von Weiterleitungen.
     *
     * @return array{0:int,1:string} [HTTP-Status, Body]
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body = null, ?string $cookieJar = null): array
    {
        [$status, $respBody] = $this->httpRequestRaw($method, $url, $headers, $body, $cookieJar, true);
        return [$status, $respBody];
    }

    /**
     * Roh-Request ohne automatisches Folgen (Weiterleitung wird zurückgegeben),
     * sofern $followRedirects = false.
     *
     * @return array{0:int,1:string,2:string,3:string} [Status, Body, Location, EffectiveURL]
     */
    private function httpRequestRaw(string $method, string $url, array $headers, ?string $body = null, ?string $cookieJar = null, bool $followRedirects = false): array
    {
        // Eine globale Pause gilt auch für OAuth- und Redirect-Requests. Damit
        // vermeiden parallele Timer die bekannten MELCloud-429-Fehler.
        $paceSemaphore = 'MELCloudConnectionRequestPacing_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($paceSemaphore, 5000)) {
            throw new Exception('HTTP-Request-Pause konnte nicht synchronisiert werden');
        }
        try {
            $lastRequest = (float) $this->ReadAttributeString('LastHttpRequestAt');
            $wait = 0.5 - (microtime(true) - $lastRequest);
            if ($lastRequest > 0 && $wait > 0) {
                usleep((int) round($wait * 1000000));
            }
            $this->WriteAttributeString('LastHttpRequestAt', (string) microtime(true));
        } finally {
            IPS_SemaphoreLeave($paceSemaphore);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 15,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => ''
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL-Fehler: ' . $error);
        }

        $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $respBody   = substr($raw, $headerSize);
        $location   = $this->extractLocationHeader($headerText);

        return [$status, $respBody, $location, $effectiveUrl];
    }

    private function extractLocationHeader(string $headerText): string
    {
        // letzte Location:-Zeile (bei mehreren Headerblöcken)
        $location = '';
        foreach (preg_split('/\r?\n/', $headerText) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
        }
        return $location;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $relative) || strpos($relative, self::OAUTH_REDIRECT) === 0) {
            return $relative;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (strpos($relative, '/') === 0) {
            return $origin . $relative;
        }
        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
        return $origin . $path . $relative;
    }

    /* -------------------------------------------------------------------------
     * Kleine Helfer
     * ---------------------------------------------------------------------- */

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function hasAnyKey(array $arr, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $arr)) {
                return true;
            }
        }
        return false;
    }

    private function pick(array $arr, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $arr) && $arr[$key] !== null) {
                return $arr[$key];
            }
        }
        return null;
    }
}
