# ATA-Live-Sync – technischer Prototyp

Der produktive Connection-Splitter bleibt beim vollständigen `/context`-
Polling. Ein WebSocket-Push soll später lediglich einen normalen, gedrosselten
`/context`-Abruf auslösen; Push-Daten werden nicht direkt in Symcon-Variablen
gemappt. Damit bleibt Polling der belastbare Fallback.

Ein dauerhaft verbundener WebSocket benötigt in Symcon einen langlebigen
Worker-Prozess. Ein Timer-Callback ist dafür nicht geeignet: er beendet sich
nach dem Callback und darf keine blockierende Socket-Schleife dauerhaft halten.
Deshalb ist der Live-Sync in dieser ATA-Adaption bewusst noch nicht als
produktiver Worker aktiviert. Es werden keine Statusvariablen angelegt, die
eine nicht vorhandene Verbindung vortäuschen.

## Verifikation vor einer Aktivierung

1. Mit anonymisierten WebSocket-Frames prüfen: Handshake, Hash-Anforderung,
   Delta-Ereignis, Ping/Pong, Close und Reconnect.
2. Sicherstellen, dass jedes Delta höchstens einen debounced vollständigen
   `/context`-Abruf auslöst.
3. Worker-Lebenszyklus, Authentifizierungsablauf, Backoff und Symcon-Neustart
   testen.
4. Erst danach die drei Diagnosevariablen für Verbindung, letztes Push-Update
   und Reconnects ergänzen und den Worker optional zuschaltbar machen.

ATW-/Ecodan-Funktionen, Zonen, Warmwasser, Szenen, Zeitpläne und historische
Telemetrie bleiben außerhalb des Scopes.
