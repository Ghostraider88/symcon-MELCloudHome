# ATA-Live-Sync

Der Live-Sync ist als optionaler, nativer Symcon-Datenfluss umgesetzt. Die
Connection-Instanz bleibt der einzige MELCloud-Fachadapter und hält das normale
REST-Polling als Fallback dauerhaft aktiv.

## Ablauf

1. Bei aktivierter Option fordert die Connection mit dem bestehenden OAuth-Token
   einen kurzlebigen WebSocket-Hash an.
2. Die Connection konfiguriert damit den nativen Symcon-WebSocket-Client auf
   wss://ws.melcloudhome.com/?hash=....
3. Ein gültiges MELCloud-JSON-Ereignis wird von ReceiveData() nur als Trigger
   verarbeitet. Die Push-Nutzdaten werden nicht direkt auf Statusvariablen
   gemappt.
4. Mehrere Push-Ereignisse innerhalb einer Sekunde werden zusammengefasst und
   lösen höchstens einen vollständigen /context-Abruf aus.
5. Der normale Status-Poll, Energie-Poll und Außentemperatur-Poll laufen
   unabhängig weiter. Ein WebSocket-Ausfall verhindert daher keine Aktualisierung.

Die WebSocket-URL wird regelmäßig mit einem neuen Hash aktualisiert. Das native
Symcon-I/O übernimmt die dauerhafte Verbindung und deren Transport-Reconnects;
das Modul hält zusätzlich den Reconnect-Zähler und den Live-Sync-Status sichtbar.

## Statusvariablen

- LiveSyncStatus: deaktiviert, Einrichtung, verbunden, Push empfangen oder
  Polling-Fallback.
- LiveSyncLastPush: Zeitpunkt des letzten gültigen JSON-Pushes.
- LiveSyncReconnects: erkannte Übergänge des nativen WebSocket-Parents zurück
  in den aktiven Zustand.

## Aktivierung und Prüfung

Die Option Optionalen Live-Sync verwenden ist standardmäßig aus. Nach dem
Aktivieren und Übernehmen sollte ein nativer WebSocket Client als Parent
angelegt beziehungsweise verbunden werden. Im Debug der Connection sind nur
Hash-Länge, Status und Fehler sichtbar; Token und vollständige Pushdaten werden
nicht protokolliert.

Für den Beta-Test prüfen:

1. Login/Token-Erneuerung und erstmalige Hash-Anforderung.
2. WebSocket-Verbindung, Push-Zeitpunkt und Aktualisierung der Klimageräte.
3. Mehrere schnelle Pushes sowie weiterhin laufendes REST-Polling.
4. Netzwerkunterbrechung, MELCloud-Ausfall, Symcon-Neustart und erneute
   Hash-Konfiguration.
5. Status Polling-Fallback und unveränderte Aktualisierung bei deaktiviertem
   oder ausgefallenem Live-Sync.

ATW-/Ecodan-Funktionen, Zonen, Warmwasser, Szenen, Zeitpläne und historische
Telemetrie bleiben außerhalb des Scopes.
