<?php

declare(strict_types=1);

/**
 * MELCloud Configurator (type 4)
 *
 * Erstellt MELCloudKlimageraet-Instanzen aus der Geräteliste des
 * MELCloud-Connection-Splitters. Muss als Kind-Instanz direkt unter
 * einer MELCloud-Connection-Instanz angelegt werden.
 */
class MELCloudConfigurator extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{73860314-C683-4067-B8BC-00005121318D}';

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->updateConnectionStatus();
    }

    /**
     * Die automatische Verbindung zu einem kompatiblen Gateway (Connection-Instanz) wird
     * von Symcon teils erst NACH ApplyChanges() hergestellt (z.B. direkt beim Anlegen der
     * Instanz). Ohne erneute Prüfung bliebe der Status auf "Keine Verbindung zum Splitter"
     * stehen, bis der Nutzer die Verbindung manuell bestätigt. Da die Konfigurationsseite
     * ohnehin geöffnet werden muss, um Geräte anzulegen, wird der Status hier zusätzlich
     * aktualisiert.
     */
    private function updateConnectionStatus(): void
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $this->SetStatus($parentID !== 0 ? 102 : 104);
    }

    public function GetConfigurationForm(): string
    {
        $this->updateConnectionStatus();

        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $values = [];
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID !== 0) {
            try {
                $json    = MELC_GetDeviceListJSON($parentID);
                $devices = json_decode($json, true);
                if (is_array($devices)) {
                    $values = $this->buildValues($devices, $parentID);
                }
            } catch (Exception $e) {
                $this->SendDebug('GetConfigurationForm', 'Fehler: ' . $e->getMessage(), 0);
            }
        }

        foreach ($form['actions'] as &$action) {
            if (isset($action['name']) && $action['name'] === 'Configurator') {
                $action['values'] = $values;
            }
        }
        unset($action);

        return (string) json_encode($form);
    }

    /**
     * @param array<int,array<string,mixed>> $devices
     * @return array<int,array<string,mixed>>
     */
    private function buildValues(array $devices, int $parentID): array
    {
        // Bereits angelegte Geräte: UnitID -> InstanceID
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instID) {
            if (IPS_GetInstance($instID)['ConnectionID'] !== $parentID) {
                continue;
            }
            $unitID = IPS_GetProperty($instID, 'UnitID');
            if (is_string($unitID) && $unitID !== '') {
                $existing[$unitID] = $instID;
            }
        }

        $values = [];
        foreach ($devices as $device) {
            $unitID = (string) $device['UnitID'];
            $name   = (string) ($device['Name'] ?? $unitID);

            $values[] = [
                'instanceID' => $existing[$unitID] ?? 0,
                'UnitID'     => $unitID,
                'Name'       => $name,
                // Einzelobjekt-create: IP-Symcon verbindet das neue Gerät automatisch
                // mit dem Eltern-Splitter dieses Konfigurators.
                'create'     => [
                    'moduleID'      => self::DEVICE_MODULE_ID,
                    'name'          => $name,
                    'configuration' => ['UnitID' => $unitID]
                ]
            ];
        }

        return $values;
    }
}
