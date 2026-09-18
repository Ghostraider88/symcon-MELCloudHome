# Changelog

## Unreleased
- Klimagerät: Der erste Statusabruf nach dem Anlegen wird verzögert, bis der
  Connection-Parent aktiv ist. Dadurch wird kein InstanceInterface-is-not-available-
  Fehler mehr während der Instanzerstellung ausgelöst.
- Connection: Optionalen ATA-Live-Sync über den nativen Symcon-WebSocket-Client
  ergänzt. MELCloud-Pushdaten lösen einen gedrosselten vollständigen /context-Abruf
  aus; Polling für Status, Energie und Außentemperatur bleibt aktiv.
- Connection: Kurzlebige WebSocket-Hashes werden mit dem bestehenden OAuth-Token
  angefordert und regelmäßig erneuert. Status, letzter Push-Zeitpunkt und erkannte
  Reconnects werden als Diagnosevariablen bereitgestellt.
- Connection: Parallele /context-Abrufe werden synchronisiert. ATW-/Ecodan- und
  Worker-Logik bleibt außerhalb des Scopes.

## Build 38 - 2026-09-17
- Connection: Energieverbrauch wird zusätzlich als persistenter kumulativer ATA-Zähler mit
  Delta-Verarbeitung geführt. Die bestehende 24-Stunden-Anzeige `EnergyConsumed` bleibt
  unverändert. Korrupte oder rückläufige Einzelwerte werden nicht angerechnet.
- Connection: Außentemperatur wird stündlich aus einem 48-Stunden-Fenster gelesen. Der letzte
  echte Messzeitpunkt und eine Veraltet-Kennzeichnung werden an die Geräteinstanz übertragen.
- Connection: Statusabrufe tolerieren zwei aufeinanderfolgende Fehler, verwenden Backoff und
  unterscheiden Authentifizierung, Rate-Limit, Netzwerk- und Serverfehler. Requests werden
  global gebremst und Token-Erneuerungen synchronisiert.
- Klimagerät: Tatsächliche Lüfterstufe, Fehlercode, Standby, Frostschutz, Überhitzungsschutz
  und Urlaubsmodus werden lesend angezeigt. Betriebsarten, Lüfterstufen, Lamellen und
  Temperatur-Schrittweite berücksichtigen die Geräteeigenschaften.
- Klimagerät: Nicht befüllte Schutz-Detailvariablen (Frostschutz-/Überhitzungsgrenzen sowie
  Urlaubsbeginn/-ende) werden aus bestehenden Instanzen entfernt; die Hauptstatusvariablen
  bleiben erhalten.
- Tests/Dokumentation: Redigierte ATA-Fixtures, Parser-Tests und ein dokumentierter
  Energie-Migrationspfad ergänzt. ATW/Ecodan und ein produktiver WebSocket-Worker bleiben
  außerhalb dieses Beta-Updates.

## Build 37 - 2026-07-02
- Connection: Neues, eigenständiges Intervall für die Außentemperatur-Aktualisierung
  (bisher fest an das Energie-Intervall mit 30-Minuten-Minimum gekoppelt). Die
  Außentemperatur läuft über einen anderen Cloud-Endpunkt als der Energieverbrauch und
  kann jetzt unabhängig bis auf 5 Minuten heruntergestellt werden, um zu testen, ob dieser
  Endpunkt andere Rate-Limits als der Energie-Endpoint hat.

## Build 36 - 2026-07-02
- Klimagerät: `Verbunden` und `Störung` von Boolean auf String-Variablen umgestellt, analog
  zu `Status` – gleiche farbige Wertedarstellung mit Icons (`connected`/`disconnected` bzw.
  `ok`/`fault`), aber als eigene Wert-Idents statt Ja/Nein. Da sich der Variablentyp ändert,
  werden beide Variablen beim nächsten Update einmalig neu angelegt.

## Build 35 - 2026-07-02
- Klimagerät: Steuerbefehle, die kurz hintereinander eintreffen (z. B. wenn eine Symcon-Szene
  Zustand, Modus, Solltemperatur und Lüfter gleichzeitig setzt), wurden bisher als mehrere
  separate Teil-Befehle an die Cloud gesendet, wodurch teils nur der erste Wert tatsächlich
  ankam. Alle Änderungen innerhalb eines kurzen Zeitfensters werden jetzt zu einem einzigen
  kombinierten Befehl zusammengefasst.
- Klimagerät: Betriebsstatus (Aus/Leerlauf/Heizen/Kühlen/…) blieb bisher dauerhaft auf dem
  befohlenen Modus stehen (z. B. immer "Kühlen"), auch wenn das Gerät gerade nicht aktiv
  kühlt/heizt. Der Status wird jetzt – wie im Referenz-Home-Assistant-Modul – aus dem
  Vergleich von Raum- und Solltemperatur mit Hysterese abgeleitet und zeigt korrekt
  "Leerlauf", wenn die Solltemperatur bereits erreicht ist.

## Build 34 - 2026-07-01
- Configurator: Zeigte nach der automatischen Verbindung mit der Connection-Instanz teils
  dauerhaft „Keine Verbindung zum Splitter", obwohl die Verbindung tatsächlich bestand
  (sichtbar erst nach manuellem „Gateway ändern"). Der Verbindungsstatus wird jetzt auch
  beim Öffnen der Konfigurationsseite neu geprüft.

## Build 33 - 2026-07-01
- Repository: Doppelt vorhandenes Entwickler-Testskript (`docs/test_melcloud_login.php`)
  entfernt (liegt nur noch in `.tools/`).
- Doku: `MELCloud Connection`- und `MELCloud Klimagerät`-README auf den aktuellen Stand
  gebracht (Außentemperatur, neue Testknöpfe, moderne Variable Presentations statt
  veralteter Legacy-Profile, dynamischer Solltemperatur-Bereich).

## Build 32 - 2026-07-01
- Klimagerät: Solltemperatur-Bereich wird jetzt pro Betriebsmodus dynamisch aus den
  Geräte-`capabilities` der Cloud übernommen (z. B. Heizen bis 10 °C statt fix 16 °C, falls
  vom Gerät unterstützt), statt fest auf 16–31 °C begrenzt zu sein. Wird beim Moduswechsel
  sofort und bei jedem Status-Update aktualisiert.

## Build 31 - 2026-07-01
- Klimagerät: `Verbunden` folgt jetzt dem echten `isConnected`-Feld der Cloud statt fest auf
  „verbunden" zu stehen. Ausgewertet mit der API-Diagnose aus Build 30.
- Connection: API-Diagnose zeigt Energie-Telemetrie-Kennzahlen jetzt korrekt an (falsches
  Feld `measure` statt `type` abgefragt).

## Build 30 - 2026-07-01
- Connection: Neuer Testknopf **„API-Diagnose ausführen"**. Ruft `/context`, den
  Energie-Telemetrie- und den Trendsummary-Endpunkt für ein Beispielgerät ab, loggt die
  vollständigen Rohantworten ins Debug und meldet per Popup zusammengefasst, welche von der
  Cloud gelieferten Felder aktuell nicht ausgewertet werden (z. B. neue Settings, weitere
  Telemetrie-Kennzahlen oder nicht unterstützte Gerätearten wie Wärmepumpen im selben Konto).

## Build 29 - 2026-07-01
- Klimagerät: Alle Statusvariablen (Power, Vane vertikal/horizontal, Raumtemperatur,
  Außentemperatur, Verbunden, Störung, WLAN-Signal, Energieverbrauch) auf die exakte
  `VARIABLE_PRESENTATION_VALUE_PRESENTATION`-Konfiguration (Icon, Farbe, Suffix,
  Nachkommastellen) umgestellt, die manuell im Symcon-GUI eingerichtet und bestätigt wurde.
  Ersetzt die zuvor verwendeten Templates/`VALUE_INPUT`, die Icons bzw. Einheiten nicht
  korrekt angezeigt hatten.
- Verbunden: eigene Wertedarstellung mit `wifi`/`wifi-slash`-Icons statt einfachem Schalter.
- Power: Glow-Farbe/-Intensität und `power-off`-Icon ergänzt.

## Build 28 - 2026-06-30
- Klimagerät: Fehlende Einheiten (kWh, °C) bei Energieverbrauch und Außentemperatur behoben
  (Zwischenschritt auf `VARIABLE_PRESENTATION_VALUE_INPUT` mit explizitem Suffix, siehe
  Build 29 für die finale Lösung).

## Build 27 - 2026-06-30
- Klimagerät: Verbleibende instanzübergreifende Custom-Variablenprofile (`Verbunden`,
  `WLAN-Signal`, `Energieverbrauch`) durch Variable Presentations ersetzt; Aufräumen der
  Legacy-Profile um `MELCloud.RSSI` ergänzt.

## Build 26 - 2026-06-30
- Connection: Außentemperatur-Abruf korrigiert – die Cloud liefert die Antwort des
  `/report/v1/trendsummary`-Endpunkts listenverpackt (`[{...}]`, Mobile-BFF-Format); das
  Auspacken von `$data[0]` vor dem Zugriff auf `datasets` behebt das Problem.

## Build 25 - 2026-06-30
- Connection: Neuer Testknopf **„Alle Daten abrufen"** in der Konfiguration, ruft Status
  und Energie (inkl. Außentemperatur) für alle Geräte in einem Zug ab.

## Build 24 - 2026-06-30
- Klimagerät: Temperaturverlauf (Farbverlauf) am Solltemperatur-Schieberegler durch
  zusätzliches `TEMPLATE`-Feld neben `PRESENTATION` wiederhergestellt (beide Schlüssel
  sind bei Variable Presentations getrennt zu setzen).

## Build 23 - 2026-06-30
- Klimagerät: Legacy-Profil `~Temperature` auf Raumtemperatur durch
  `VARIABLE_TEMPLATE_VALUE_PRESENTATION_ROOM_TEMPERATURE` ersetzt.

## Build 22 - 2026-06-30
- Klimagerät: Solltemperatur-Schieberegler nach fehlgeschlagenem Template-Versuch (Build 21)
  auf funktionierende `VARIABLE_PRESENTATION_SLIDER`-Darstellung zurückgesetzt.

## Build 21 - 2026-06-30
- Klimagerät: Außentemperatur-Statusvariable ergänzt (Abruf über
  `/report/v1/trendsummary`).
- Klimagerät: Erster (fehlgeschlagener) Versuch, dem Solltemperatur-Schieberegler einen
  Temperaturverlauf zu geben – siehe Korrektur in Build 22/24.

## Build 20 - 2026-06-30
- Klimagerät: Zulässigen Bereich der Solltemperatur auf 16–31 °C korrigiert.

## Build 19 - 2026-06-30
- Klimagerät: Eigene Wertedarstellung für die Störungsvariable statt des fixen
  `~Alert`-Systemprofils (anpassbare Beschriftung statt „OK"/„Alarm").

## Build 18 - 2026-06-30
- Modulstore-Veröffentlichung vorbereitet (Metadaten, Beta-Hinweis in der README).

## Build 17 - 2026-06-30
- Klimagerät: Betriebsstatus auf Wertedarstellung mit String-Typ umgestellt (zeigt
  Beschriftung + Icon statt Rohwert).

## Build 16 - 2026-06-30
- Klimagerät: Betriebsstatus wieder auf Aufzählung umgestellt (Zwischenschritt, siehe
  Build 17 für die endgültige Lösung mit String-Wertedarstellung).

## Build 15 - 2026-06-30
- Connection: WLAN-Signal- und Energiedaten-Quelle anhand des Home-Assistant-Referenz-
  moduls (`andrew-blake/melcloudhome`) korrigiert.

## Build 14 - 2026-06-30
- Klimagerät: Wertedarstellungs-Schema für den Betriebsstatus korrigiert; zusätzliches
  Debug-Logging für WLAN-Signal und Energieverbrauch ergänzt.

## Build 13 - 2026-06-30
- Klimagerät: Icons für Betriebsmodus und Betriebsstatus korrigiert; schnelle,
  aufeinanderfolgende Solltemperatur-Änderungen (z. B. durch Klicken/Ziehen) werden jetzt
  entkoppelt und erst nach kurzer Verzögerung als ein einzelner Steuerbefehl gesendet.

## Build 12 - 2026-06-30
- Klimagerät: Aufzählungs-Buttons (Modus, Lüfter, Lamellen) zeigen jetzt Beschriftung und
  Icon; Betriebsstatus auf Wertedarstellung umgestellt.

## Build 11 - 2026-06-30
- Klimagerät: Schema der `OPTIONS` bei Aufzählungs-Darstellungen korrigiert; nach dem
  Anlegen eines Geräts über den Konfigurator wird die Anzeige automatisch aktualisiert.

## Build 10 - 2026-06-30
- Connection: Polling-Intervalle angepasst und mit Mindestwerten abgesichert (Status
  standardmäßig 60 s, Energie 30 min).

## Build 9 - 2026-06-30
- Klimagerät: Alte, instanzübergreifende Custom-Variablenprofile durch Variable
  Presentations ersetzt (Aufzählung für schaltbare Variablen, Wertedarstellung für reine
  Anzeigen).

## Build 8 - 2026-06-30
- Repository: Verzeichnis `tools/` in `.tools/` umbenannt, um eine fälschliche
  Modul-Fehlermeldung im Module Control zu beheben (reservierte Punkt-Ordner werden
  ignoriert).

## Build 7 - 2026-06-30
- Connection: Steuer-Endpoint korrigiert (`PUT /monitor/ataunit/{id}` statt eines nicht
  existierenden Pfads) und fehlende Query-Parameter (`from`, `to`, `interval`, `measure`)
  bei der Energie-Telemetrie-Abfrage ergänzt.

## Build 6 - 2026-06-30
- Connection/Klimagerät: Schreibpfad für Steuerbefehle repariert (RequestAction →
  SendDataToParent → ForwardData) und Status-Poll-Intervall verkürzt.

## Build 5 - 2026-06-30
- Konfigurator (Typ 4) als eigenständiges Modul `MELCloudConfigurator` eingeführt (zuvor
  fälschlich in den Splitter eingebettet).
- DataFlow-Verkabelung zwischen Connection (Splitter), Configurator und Klimagerät (Device)
  korrigiert (einheitliche DataFlow-GUID, `ReceiveDataFilter`, Array-Create-Format).

## Build 4 - 2026-06-29
- Splitter/Device-Verbindung repariert: gekreuzte DataFlow-GUIDs vereinheitlicht.

## Build 3 - 2026-06-29
- Splitter/Device-Verbindung repariert: einheitliche DataFlow-GUID für den Verbindungsaufbau
  zwischen Connection und Configurator/Device.

## Build 2 - 2026-06-29
- Login: OAuth-Code-Extraktion für das `melcloudhome://`-Custom-URI-Schema sowie
  JavaScript-Weiterleitungsseite nach dem Cognito-Callback korrigiert.
- Legacy-Profil `~Connect` durch `~Switch` ersetzt; nicht mit `IPSModuleStrict` kompatiblen
  `ConnectParent`-Aufruf entfernt.

## Build 1 - 2026-06-29
- Erste lauffähige Version: Anmeldung (OAuth 2.0 + PKCE), Status-Polling, Steuerung der
  Kernfunktionen (Power, Modus, Solltemperatur, Lüfter, Lamellen).
