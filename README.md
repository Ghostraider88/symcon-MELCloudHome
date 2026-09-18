[![Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)

# MELCloud Home Klima

Mit diesem Modul lassen sich **Mitsubishi-Klimageräte (Air-to-Air / Klimaanlagen)** über die
**MELCloud Home** Cloud komfortabel in Symcon nutzen.

Nach der Anmeldung mit den Zugangsdaten des MELCloud-Home-Kontos werden die vorhandenen
Klimageräte per Konfigurator ausgewählt und als eigene Instanzen angelegt. Status und
Energieverbrauch werden zyklisch abgerufen, Steuerbefehle (Ein/Aus, Modus, Solltemperatur,
Lüfter, Lamellen) gehen direkt an die Cloud.

> Hinweis: Es wird ausschließlich die Klima-Funktionalität (ATA) unterstützt – keine
> Wärmepumpe (ATW), kein Warmwasser. Als technische Vorlage für die undokumentierten
> Cloud-Endpunkte diente das Home-Assistant-Projekt
> [`andrew-blake/melcloudhome`](https://github.com/andrew-blake/melcloudhome) (MIT-Lizenz) –
> übernommen wurde dabei nur das Wissen über API-Endpunkte/Parameter, kein Code.

**Status:** Beta. Geplant ist die Einreichung im offiziellen
[IP-Symcon Module Store](https://www.symcon.de/de/module-store/) (zunächst Beta-Kanal,
später Stable nach Symcon-Review).

## Inhaltsverzeichnis

1. [Module](#1-module)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation und Konfiguration](#3-installation-und-konfiguration)
4. [Funktionsumfang je Klimagerät](#4-funktionsumfang-je-klimagerät)
5. [Überblick](#5-überblick)
6. [Fehlersuche](#6-fehlersuche)
7. [FAQ](#7-faq)

## 1. Module

| Modul | Typ | Aufgabe |
|-------|-----|---------|
| [MELCloud Connection](MELCloudConnection/README.md) | Splitter | Anmeldung (OAuth 2.0 + PKCE), Status-/Energie-Polling, Befehlsweiterleitung |
| [MELCloud Configurator](MELCloudConfigurator/README.md) | Konfigurator | Listet Geräte aus dem Konto, legt Klimagerät-Instanzen an (Kind der Connection-Instanz) |
| [MELCloud Klimagerät](MELCloudKlimageraet/README.md) | Gerät | Ein Klimagerät: Statusvariablen und Steuerung |

## 2. Voraussetzungen

- Symcon ab Version 8.1
- ein bestehendes MELCloud-Home-Konto (E-Mail + Passwort) mit mindestens einem
  registrierten Mitsubishi-Klimagerät

## 3. Installation und Konfiguration

1. In IP-Symcon **Module Control** öffnen und die URL dieses Repositories als neue Library
   hinzufügen.
2. Eine Instanz **„MELCloud Connection"** anlegen.
3. E-Mail und Passwort des MELCloud-Home-Kontos eintragen, mit **„Login testen"** prüfen.
4. Über den **Konfigurator** in der Connection-Instanz die gefundenen Klimageräte auswählen
   und anlegen.
5. Optional: In der Connection-Instanz über **„Alle Daten abrufen"** einen sofortigen
   Status- und Energie-Poll aller angelegten Geräte auslösen, um die Einrichtung zu prüfen.

## 4. Funktionsumfang je Klimagerät

**Steuerbar:** Ein/Aus, Betriebsmodus (Automatik/Heizen/Kühlen/Entfeuchten/Lüften),
Solltemperatur (16–31 °C, 0,5 °C-Schritte), Lüftergeschwindigkeit (Automatik, 1–5),
Lamelle vertikal und horizontal (Automatik, Stufen, Schwenken).

**Anzeige:** Raumtemperatur, Außentemperatur mit letzter Messzeit und Veraltet-Kennzeichnung,
Betriebsstatus, Verbindungsstatus, Störung und Fehlercode, WLAN-Signalstärke, tatsächliche
Lüfterstufe, Standby, Frost-/Überhitzungsschutz, Urlaubsmodus sowie gleitender und kumulativer
Energieverbrauch. Schutzmodi werden ausschließlich gelesen.

Die Details zur Energie-Migration stehen in
[`docs/ATA_MIGRATION.md`](docs/ATA_MIGRATION.md). Der aktuelle Stand zur technischen
Live-Sync-Prüfung ist in [`docs/LIVE_SYNC_PROTOTYPE.md`](docs/LIVE_SYNC_PROTOTYPE.md)
dokumentiert. Der Live-Sync ist optional und standardmäßig deaktiviert; Polling bleibt
unabhängig davon der Fallback.

## 5. Überblick

```text
MELCloud Home Cloud
  |  Status (GET /context), Energie/Außentemperatur (Telemetrie/Report-Endpunkte)
  v
MELCloud Connection (Splitter, hält Session + Konfigurator)
  ^  optionaler nativer WebSocket-Push als /context-Trigger
  |  verteilt Daten an die Geräte-Instanzen
  v
MELCloud Klimagerät (Device, je Gerät eine Instanz)
```

- Statusdaten werden zyklisch von `GET /context` geholt und an die Geräte verteilt.
- Steuerbefehle gehen vom Gerät über den Splitter als `PUT /monitor/ataunit/{id}` an die Cloud.
- Energiedaten und Außentemperatur werden über eigene Telemetrie-/Report-Endpunkte in einem
  längeren Intervall geholt.

## 6. Fehlersuche

- **Login schlägt fehl:** Zugangsdaten in der Connection-Instanz prüfen, danach erneut
  **„Login testen"** ausführen. Debug-Ausgabe der Connection-Instanz liefert Details zum
  OAuth-/Cognito-Ablauf.
- **Klimagerät reagiert nicht auf Befehle:** Debug-Ausgabe von Gerät und Connection-Instanz
  parallel öffnen und einen Wert ändern; im Splitter-Debug muss `sendControl OK` erscheinen.
  Bleibt es bei einem Control-Fehler, ist meist die Session abgelaufen – Instanz neu anmelden.
- **Werte werden nicht aktualisiert:** Poll-Intervalle in der Connection-Instanz prüfen
  (Status/Energie) oder über **„Alle Daten abrufen"** einen sofortigen Abruf auslösen.
- **Außentemperatur oder Energieverbrauch bleiben leer:** Debug-Ausgabe der
  Connection-Instanz prüfen – dort werden Antworten der Telemetrie-/Report-Endpunkte
  protokolliert.

## 7. FAQ

### Werden auch Wärmepumpen (ATW) oder Warmwasserbereiter unterstützt?

Nein, aktuell ausschließlich Klimageräte (ATA).

### Warum ist eine zweite Instanz (Configurator) nötig, um Geräte anzulegen?

Der Konfigurator sorgt dafür, dass Geräte-Instanzen kontrolliert vom Nutzer angelegt werden,
statt dass das Modul selbstständig Instanzen erzeugt (siehe Symcon-Konvention „Hoheit des
Nutzers wahren").

### Kann ich mehrere MELCloud-Home-Konten gleichzeitig nutzen?

Ja, dazu einfach mehrere „MELCloud Connection"-Instanzen mit unterschiedlichen Zugangsdaten
anlegen.

## Lizenz

[MIT](LICENSE) – siehe `LICENSE`-Datei.
