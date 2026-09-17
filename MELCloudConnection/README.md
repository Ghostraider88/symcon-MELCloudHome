# MELCloud Connection

Splitter-Modul, das die Verbindung zur MELCloud-Home-Cloud herstellt und als Eltern-Instanz
für die Klimageräte dient.

## Aufgaben

- Anmeldung über OAuth 2.0 Authorization Code + PKCE (AWS-Cognito-Federated-Login),
  Speicherung und Erneuerung der Tokens.
- Zyklisches Polling des Gerätestatus über `GET /context` und Verteilung an die Kinder.
- Zyklisches Polling von Energieverbrauch und Außentemperatur über die Telemetrie-/
  Report-Endpunkte (längeres Intervall).
- Entgegennahme von Steuerbefehlen der Kinder und Versand als `PUT /monitor/ataunit/{id}`.
- Konfigurator zum Anlegen der Klimageräte-Instanzen.

## Einstellungen

| Feld | Beschreibung |
|------|--------------|
| E-Mail | Benutzername des MELCloud-Home-Kontos |
| Passwort | Passwort des Kontos |
| Status-Aktualisierung | Polling-Intervall für den Gerätestatus (Sekunden, min. 60) |
| Energie-Aktualisierung | Polling-Intervall für den Energieverbrauch (Minuten, min. 30) |
| Außentemperatur-Aktualisierung | Polling-Intervall für die Außentemperatur (Minuten, min. 30) |

## Hinweise

- Status-Polling deckt alle steuerungsrelevanten Klimadaten ab (Solltemperatur, Raumtemperatur,
  Betriebsmodus, Lüfterstufe, Vane-Einstellungen, Power-State). 60 Sekunden ist die sinnvolle
  Untergrenze für die MELCloud-Statusdaten – lokale Fernbedienungs-Änderungen sind damit binnen
  ca. einer Minute sichtbar, ohne die Cloud aggressiv abzufragen (kein 10s/30s-Polling).
- Energieverbrauch wird bewusst seltener (≥30 Minuten) abgefragt, da dieser Telemetrie-Endpunkt
  empfindlich auf häufige Abfragen reagiert (bekannte HTTP-429-Throttling-Fehler).
- Die Außentemperatur läuft über den Trendsummary/Report-Endpoint mit `Hourly` und einem
  48-Stunden-Fenster. Die letzte echte Messzeit und eine mögliche Veraltung werden an die
  Geräteinstanz weitergegeben. Das Mindestintervall beträgt 30 Minuten.

## Aktionen

- **Login testen** – prüft die Zugangsdaten und zeigt die Anzahl gefundener Klimageräte.
- **Alle Daten abrufen** – löst einmalig einen sofortigen Status-, Energie- und
  Außentemperatur-Abruf für alle angelegten Geräte aus (nützlich zum Testen der Einrichtung).
- **API-Diagnose ausführen** – ruft `/context`, den Energie- und den Trendsummary-Endpunkt für
  ein Beispielgerät ab, loggt redigierte Rohantworten ins Debug und meldet, welche von
  der Cloud gelieferten Felder aktuell nicht ausgewertet werden. Gedacht, um neue oder
  übersehene API-Felder zu finden.
- **Konfigurator** – listet die Geräte des Kontos und legt daraus Geräte-Instanzen an.
