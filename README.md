# Hoymiles Cloud für IP-Symcon

Liest Hoymiles-Mikrowechselrichter (z. B. HMS-1800-4T) über die **S-Miles Cloud** aus – ohne DTU-Zugriff im lokalen Netz.
Portierung des Cloud-Clients aus [homeassistant-hoymiles-cloud](https://github.com/Philra94/homeassistant-hoymiles-cloud) (MIT).

## Funktionen

- Login mit allen bekannten Varianten der S-Miles Cloud (Web, Installer, Home, Legacy), automatische Erkennung
- Anlage, Wechselrichter und PV-Eingänge werden selbst erkannt
- Variablen: Leistung, Ertrag heute/Monat/Jahr/gesamt (kWh), CO₂-Einsparung, Datenstand, letzte Abfrage
- Je PV-Eingang: Leistung, Spannung, Strom (fehlende Werte werden aus dem Tagesverlauf der Cloud ergänzt)
- Nachts bzw. bei veralteten Cloud-Daten wird 0 W geschrieben statt des letzten Tageswerts
- Optional Archivierung (Gesamtertrag als Zähler, Nullwerte ignoriert)
- Debug-Ausgabe aller Requests im Debug-Fenster der Instanz (Login-Daten ausgeblendet)

## Voraussetzungen

- IP-Symcon ab 7.0
- PHP-Erweiterung `sodium` (in Symcon enthalten; wird für den Argon2id-Login benötigt)
- Hoymiles-Konto mit der Anlage (dieselben Zugangsdaten wie in der S-Miles-App)

## Installation

**Variante A – eigenes Git-Repository (empfohlen):**
Inhalt dieses Ordners in ein eigenes (auch privates) GitHub-Repository legen, dann in der Konsole
unter *Kerninstanzen → Modules* die Repository-URL hinzufügen.

**Variante B – manuell:**
Ordner `SymconHoymiles` in das `modules`-Verzeichnis von Symcon kopieren
(Windows: `C:\ProgramData\Symcon\modules`, Linux/SymBox: `/var/lib/symcon/modules`)
und den Symcon-Dienst neu starten.

## Einrichtung

1. Instanz hinzufügen: *Hoymiles Cloud*
2. Benutzer und Passwort eintragen, *Übernehmen*
3. *Verbindung testen* – zeigt die verwendete Login-Variante und die Anlagen im Konto
4. Nach kurzer Zeit werden Wechselrichter und PV-Eingänge automatisch angelegt und befüllt

Gibt es mehrere Anlagen im Konto, kann nach dem Verbindungstest eine bestimmte Anlage gewählt werden.

## Status-Codes

| Code | Bedeutung |
|------|-----------|
| 102 | Verbunden |
| 104 | Zugangsdaten fehlen |
| 201 | Login fehlgeschlagen |
| 202 | Keine Anlage bzw. kein Wechselrichter gefunden |
| 203 | Fehler beim Abruf aus der Cloud |

## PHP-Befehlsreferenz

```php
HOYM_Update(int $InstanzID);          // sofort abrufen
HOYM_Discover(int $InstanzID);        // Anlage und PV-Eingänge neu einlesen
HOYM_TestConnection(int $InstanzID);  // Login testen, Anlagen auflisten
```

## Hinweise

- Die Cloud aktualisiert die Werte nur etwa alle 5 Minuten; kürzere Intervalle bringen keine neueren Daten.
- Die Endpunkte sind nicht offiziell dokumentiert und können sich durch Hoymiles-Updates ändern.
