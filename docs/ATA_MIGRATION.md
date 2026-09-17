# ATA-Daten und Migration

## Energie

`EnergyConsumed` behält die bisherige Semantik als gleitende Summe der letzten
24 Stunden in kWh. Die Abfrage nutzt dafür jetzt stündliche Werte aus einem
48-Stunden-Fenster und berücksichtigt nur Einträge mit gültigem Messzeitpunkt.

Zusätzlich gibt es je Klimagerät `EnergyTotal`. Dieser Zähler wird aus
Änderungen desselben Messzeitpunkts beziehungsweise neuen Messzeitpunkten
fortgeschrieben. Die Rohwerte werden im Connection-Attribut `EnergyState`
persistent gespeichert. Der erste gültige Abruf übernimmt die vorhandenen
Messwerte nur als Startbasis; er addiert die historische Cloud-Antwort nicht
noch einmal. Deshalb beginnt `EnergyTotal` bei einer neuen Installation bei
0 kWh. Ein bestehender `EnergyConsumed`-Wert wird nicht umgedeutet.

Negative, nicht numerische, unendlich große oder unrealistische Einzelwerte
(über 100 kWh pro Stundenwert) werden verworfen. Ein Rücksprung eines bereits
bekannten Messzeitpunkts wird ebenfalls nicht als Verbrauch angerechnet.

## Außentemperatur

Der Trendsummary-Abruf verwendet `Hourly` und ein 48-Stunden-Fenster. Die
Variable `OutdoorTemperatureLastReading` enthält den letzten echten
Messzeitpunkt in UTC (ISO-8601). `OutdoorTemperatureStale` wird ab sechs
Stunden ohne hinreichend aktuelle Messung gesetzt. Bei einem Abruffehler wird
der letzte gültige Temperaturwert nicht durch 0 oder einen Ersatzwert
überschrieben.

## Upgrade

1. Moduldateien aktualisieren und Connection sowie Geräteinstanzen einmal mit
   `ApplyChanges` anwenden.
2. Einen manuellen Abruf ausführen und prüfen, dass `EnergyConsumed` plausibel
   bleibt und `EnergyTotal` zunächst 0 kWh beziehungsweise ab dem nächsten
   neuen Delta fortgeschrieben wird.
3. Nach mindestens zwei Energieabrufen die Rohdaten und die Variable
   `EnergyTotal` vergleichen. Bei Auffälligkeiten das Attribut `EnergyState`
   nicht löschen, sondern zuerst sichern und diagnostisch prüfen.

Die bestehenden Modul-GUIDs und bestehenden Variablen-IDs bleiben erhalten;
die neuen Variablen werden erst ab Position 14 angelegt.
