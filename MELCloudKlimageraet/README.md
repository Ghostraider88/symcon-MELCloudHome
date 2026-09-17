# MELCloud Klimagerät

Device-Modul für ein einzelnes Mitsubishi-Klimagerät (ATA). Die Instanz wird normalerweise
über den Konfigurator der **MELCloud Connection** angelegt und erhält darüber automatisch
die passende Geräte-ID.

## Variablen

Alle Variablen nutzen moderne IP-Symcon Variable Presentations (Icons, Farben, Einheiten)
statt klassischer Variablenprofile.

### Steuerbar
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Zustand | Schalter | Gerät ein-/ausschalten |
| Betriebsmodus | Aufzählung | Automatik, Heizen, Kühlen, Entfeuchten, Lüften |
| Solltemperatur | Schieberegler mit Temperaturverlauf | Bereich abhängig vom Betriebsmodus und Gerät (typ. 16–31 °C, im Heizmodus je nach Gerät auch niedriger), 0,5 °C-Schritte |
| Lüftergeschwindigkeit | Aufzählung | Automatik, 1–5 |
| Lamelle vertikal | Aufzählung | Automatik, 1–5, Schwenken |
| Lamelle horizontal | Aufzählung | Automatik, Links … Rechts, Schwenken |

### Anzeige
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Raumtemperatur | Wertedarstellung | aktuelle Raumtemperatur |
| Außentemperatur | Wertedarstellung | Außentemperatur (nur bei Geräten mit Außensensor) |
| Letzte Außentemperatur-Messung | Text | Zeitstempel der letzten echten Messung in UTC |
| Außentemperatur veraltet | Schalter | keine hinreichend aktuelle Messung seit sechs Stunden |
| Status | Wertedarstellung | abgeleitet aus Betriebsmodus + Temperaturvergleich (Aus/Leerlauf/Heizen/Kühlen/Entfeuchten/Lüften/Automatik) |
| Verbunden | Wertedarstellung | echter Cloud-/WLAN-Verbindungsstatus des Geräts |
| Störung | Wertedarstellung | Fehlerzustand des Geräts |
| WLAN-Signal | Wertedarstellung | Signalstärke in dBm |
| Energieverbrauch | Wertedarstellung | gleitender Verbrauch in kWh (letzte 24h, stündlich) |
| Kumulativer Energiezähler | Wertedarstellung | persistenter ATA-Gesamtzähler in kWh, aus Messwert-Deltas |
| Tatsächliche Lüfterstufe | Wertedarstellung | vom Gerät gemeldete Ist-Lüfterstufe, getrennt von der Sollstufe |
| Fehlercode | Text | vom Gerät gemeldeter Fehlercode |
| Standby-Modus | Schalter | vom Gerät gemeldeter Standby-Zustand |
| Frostschutz / Überhitzungsschutz | Schalter + Temperaturwerte | nur lesend aus `/context` |
| Urlaubsmodus | Schalter + Start/Ende | nur lesend aus `/context` |

## Hinweise

- Schaltvorgänge werden optimistisch sofort gesetzt und beim nächsten Status-Poll bestätigt.
- Empfangene Daten werden über einen Empfangsfilter auf die eigene Geräte-ID begrenzt.
- Der Bereich der Solltemperatur wird beim Moduswechsel automatisch anhand der vom Gerät
  gemeldeten Fähigkeiten (`capabilities` aus der Cloud-Antwort) angepasst – z. B. erlauben
  manche Geräte im Heizmodus niedrigere Werte als im Kühl-/Trocken-/Automatikmodus.
- Mehrere Steuerbefehle, die kurz hintereinander eintreffen (z. B. wenn eine Symcon-Szene
  Zustand, Modus, Solltemperatur und Lüfter gleichzeitig setzt), werden zu einem einzigen
  kombinierten Befehl an die Cloud zusammengefasst (Sammelfenster ca. 0,5 s), statt als
  mehrere separate Teil-Befehle gesendet zu werden.
- Nicht unterstützte Betriebsarten, Lüfterstufen oder Lamellenfunktionen werden anhand der
  Gerätespezifikation ausgeblendet beziehungsweise als „Nicht verfügbar“ dargestellt und
  nicht an die Cloud gesendet.
- Der Status (Aus/Leerlauf/Heizen/Kühlen/…) wird – mangels eines eigenen API-Felds dafür –
  aus dem Vergleich von Raum- und Solltemperatur mit 0,5 °C Hysterese abgeleitet: Nur bei
  ausreichender Abweichung gilt das Gerät als aktiv heizend/kühlend, sonst als Leerlauf.
