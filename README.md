# Hoymiles Cloud für IP-Symcon

[![Version](https://img.shields.io/badge/Version-1.1%20%C2%B7%20Build%2024-2ea44f)](library.json)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-ab%208.1-1f6feb)](https://www.symcon.de)
[![Symcon 9.0](https://img.shields.io/badge/optimiert%20f%C3%BCr-Symcon%209.0-0aa5a5)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Kachel](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-f2a900)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![PHP](https://img.shields.io/badge/PHP-8.x%20(inkl.%208.5)-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![Hoymiles](https://img.shields.io/badge/Hoymiles-S--Miles%20Cloud-e2001a)](https://global.hoymiles.com)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-yellow)](LICENSE)
[![Tests](https://github.com/cfaf2002/HoymilesCloud/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/HoymilesCloud/actions/workflows/tests.yml)
[![Letzter Commit](https://img.shields.io/github/last-commit/cfaf2002/HoymilesCloud)](https://github.com/cfaf2002/HoymilesCloud/commits)

Dieses Modul holt die Daten eines Hoymiles-Mikrowechselrichters (z. B. **HMS-1800-4T**) aus der **Hoymiles S-Miles Cloud** und stellt sie in IP-Symcon als Variablen bereit – Leistung, Erträge, die Werte jedes einzelnen Solarmoduls, Störungsmeldungen und die Ersparnis in Euro.

Das Modul braucht keinen direkten Zugriff auf den Wechselrichter oder die DTU im Heimnetz. Es nutzt dieselben Zugangsdaten wie die S-Miles-App auf dem Handy. Das ist besonders dann praktisch, wenn eine lokale Anbindung (z. B. über OpenDTU) nicht oder nicht mehr funktioniert.

Version 1.1 · IP-Symcon 8.1 bis 9.0 (optimiert für 9.0) · Oberfläche Deutsch und Englisch · Lizenz MIT

---

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Einrichtung Schritt für Schritt](#4-einrichtung-schritt-für-schritt)
5. [Das Konfigurationsformular](#5-das-konfigurationsformular)
6. [Die Variablen](#6-die-variablen)
7. [So kommen die Werte zustande](#7-so-kommen-die-werte-zustande) (inkl. Nachtmodus)
8. [Störungswarnungen](#8-störungswarnungen)
9. [Ersparnis in Euro](#9-ersparnis-in-euro)
10. [Firmware](#10-firmware)
11. [Wechselrichter steuern](#11-wechselrichter-steuern)
12. [Kachel für die Visualisierung](#12-kachel-für-die-visualisierung)
13. [Verlauf nachladen](#13-verlauf-nachladen)
14. [Archivierung](#14-archivierung)
15. [Status der Instanz](#15-status-der-instanz)
16. [Befehle für eigene Skripte](#16-befehle-für-eigene-skripte)
17. [Datensicherheit](#17-datensicherheit)
18. [Fehlerbehebung](#18-fehlerbehebung)
19. [Grenzen des Moduls](#19-grenzen-des-moduls)
20. [Für Entwickler: Sprachen, Tests, GitHub](#20-für-entwickler-sprachen-tests-github)
21. [Versionshistorie](#21-versionshistorie)
22. [Lizenz und Dank](#22-lizenz-und-dank)

---

## 1. Funktionsumfang

- **Anmeldung an der S-Miles Cloud** mit denselben Zugangsdaten wie in der App. Welche Anmeldeart das Konto braucht, findet das Modul selbst heraus.
- **Automatische Erkennung** von Anlage, Wechselrichter und Anzahl der Solarmodul-Eingänge (beim HMS-1800-4T sind es vier).
- **Werte der gesamten Anlage:** aktuelle Leistung, Ertrag heute / Monat / Jahr / gesamt, CO₂-Einsparung.
- **Werte je Solarmodul-Eingang (PV 1, PV 2, …):** Leistung, Spannung, Strom und **Ertrag heute** – so fällt sofort auf, wenn ein Modul verschattet, verschmutzt oder defekt ist.
- **Störungswarnungen:** Meldung, wenn tagsüber keine Daten kommen, wenn es hell ist, die Anlage aber kaum liefert, oder wenn ein Eingang deutlich schwächer ist als die anderen – als Push-Nachricht, per eigenem Skript und im Meldungsfenster.
- **Ersparnis in Euro** für heute, Monat, Jahr und gesamt.
- **Firmware-Stände** von DTU und Wechselrichter und Hinweis, wenn ein Update verfügbar ist.
- **Verlauf nachladen:** vergangene Tage aus der Cloud ins Symcon-Archiv holen, damit Diagramme nicht erst mit dem Installationstag beginnen.
- **Eigene Kachel** für die Kachel-Visualisierung mit Leistung, Tagesverlauf, PV-Eingängen und Störungen – in jeder Kachelgröße, hell und dunkel.
- **Nachtmodus:** Nachts wird nur selten und mit einer einzigen Anfrage abgefragt. Morgens geht es pünktlich wieder los – ausgelöst durch einen **Helligkeitssensor**, den Sonnenaufgang oder die ersten frischen Daten.
- **Archivierung auf Wunsch:** Leistung und Gesamtertrag werden automatisch aufgezeichnet, der Gesamtertrag als Zähler.
- **Aktivierungsschalter**, um die Abfrage vorübergehend abzuschalten, ohne etwas zu löschen.
- **Debug-Fenster** mit allen Anfragen an die Cloud (Zugangsdaten werden dabei ausgeblendet).

## 2. Voraussetzungen

| Was | Details |
|-----|---------|
| IP-Symcon | Version 8.1 oder neuer, empfohlen 9.0. Das Antippen von Werten in der Kachel (`openObject`) gibt es ab 8.2. Wer noch Symcon 7.x nutzt, bleibt bei Build 22. |
| Hoymiles-Konto | Die Anlage muss in der S-Miles Cloud bzw. App angelegt sein. Benötigt werden Benutzername (meist die E-Mail-Adresse) und Passwort. |
| Internet | Symcon muss `neapi.hoymiles.com` und `euapi.hoymiles.com` per HTTPS erreichen können. |
| PHP-Erweiterung `gd` | Nur für ein eigenes Bild in der Kachel (wird automatisch verkleinert). Ist bei Symcon normalerweise dabei. |
| PHP-Erweiterung `sodium` | Wird für die sichere Anmeldung benötigt. Fehlt sie, zeigt „Verbindung testen“ eine entsprechende Meldung. |
| optional | Helligkeitssensor in Symcon (für Nachtmodus und Warnung „hell, aber kaum Leistung“), Standort in der Instanz „Location“, WebFront oder Kachel-Visualisierung für Push-Nachrichten |

Der Wechselrichter selbst muss nichts Besonderes können – er muss nur wie gewohnt seine Daten an die Hoymiles-Cloud senden (über die DTU bzw. das eingebaute WLAN).

## 3. Installation

Es gibt zwei Wege. Beide führen zum selben Ergebnis.

### Variante A: über ein eigenes Git-Repository (empfohlen)

Vorteil: Updates lassen sich später mit einem Klick einspielen, und die automatischen Tests laufen bei jeder Änderung (siehe [Abschnitt 20](#20-für-entwickler-sprachen-tests-github)).

1. Den Inhalt dieses Ordners in ein eigenes GitHub-Repository hochladen (ein privates Repository reicht).
2. In der Symcon-Konsole **Kerninstanzen → Modules** öffnen.
3. Auf **Hinzufügen** klicken und die Adresse des Repositorys eintragen.
4. Symcon lädt das Modul herunter. Danach steht es beim Hinzufügen neuer Instanzen zur Verfügung.

### Variante B: manuell kopieren

1. Den Ordner `SymconHoymiles` in das Modul-Verzeichnis von Symcon kopieren:
   - Windows: `C:\ProgramData\Symcon\modules`
   - Linux / SymBox: `/var/lib/symcon/modules`
2. Den Symcon-Dienst neu starten.

### Update auf eine neue Version

- Variante A: in **Kerninstanzen → Modules** beim Modul auf **Aktualisieren** klicken.
- Variante B: den Ordner durch die neue Version ersetzen und den Symcon-Dienst neu starten.

Einstellungen, Variablen und Archivdaten bleiben bei einem Update erhalten. Neue Variablen werden beim nächsten Übernehmen bzw. Neustart automatisch angelegt.

## 4. Einrichtung Schritt für Schritt

1. **Instanz anlegen:** In der Konsole an gewünschter Stelle im Objektbaum rechts klicken → **Objekt hinzufügen → Instanz** → nach **Hoymiles Cloud** suchen und hinzufügen.
2. **Zugangsdaten eintragen:** Benutzername (E-Mail) und Passwort der S-Miles-App eintragen und auf **Übernehmen** klicken.
3. **Verbindung testen:** Auf **Verbindung testen** klicken. Es erscheint ein Fenster mit der verwendeten Anmeldeart und allen Anlagen im Konto, zum Beispiel:

   ```
   Login OK (Variante: installer_v3)

   Anlagen:
   • Dach (4711)
   ```

4. **Fertig:** Wenige Sekunden später liest das Modul Anlage und Wechselrichter ein, legt alle Variablen an und holt die ersten Werte. Im Formularbereich unten stehen danach Anlage, Modell, Seriennummer und Anzahl der PV-Eingänge.
5. **Optional:** Helligkeitssensor und Push-Nachrichten einrichten ([Nachtmodus](#nachtmodus), [Störungswarnungen](#8-störungswarnungen)), Strompreis prüfen ([Ersparnis](#9-ersparnis-in-euro)) und den [Verlauf nachladen](#13-verlauf-nachladen).

Gibt es mehrere Anlagen im Konto, kann nach dem Verbindungstest im Feld **Anlage** die gewünschte Anlage ausgewählt werden. Ansonsten nimmt das Modul automatisch die erste.

## 5. Das Konfigurationsformular

### Einstellungen

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Instanz aktiv** | an | Schaltet die regelmäßige Abfrage ein oder aus. Ausgeschaltet bleiben alle Variablen und Archivdaten erhalten, es werden nur keine neuen Werte geholt. |
| **Benutzer / E-Mail** | – | Anmeldename der S-Miles Cloud bzw. App. |
| **Passwort** | – | Passwort der S-Miles Cloud bzw. App. |
| **Anlage** | automatisch | Welche Anlage des Kontos ausgelesen wird. „automatisch“ nimmt die erste. Die Liste füllt sich nach „Verbindung testen“. |
| **Abfrageintervall** | 5 Minuten | Wie oft das Modul die Cloud abfragt (1–60 Minuten). Siehe Hinweis unten. |
| **Archivieren** | an | Schaltet die Aufzeichnung von Leistung und Gesamtertrag im Archiv ein (siehe [Archivierung](#14-archivierung)). |

> **Hinweis zum Abfrageintervall:** Die Hoymiles-Cloud berechnet nur etwa alle 5 Minuten neue Werte. Ein kürzeres Intervall ist möglich, bringt aber keine aktuelleren Daten – das Modul holt dann einfach mehrmals denselben Stand ab. 5 Minuten sind deshalb die sinnvolle Einstellung.

### Nachtmodus

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Nachts seltener abfragen** | an | Schaltet den Nachtmodus ein oder aus. Ausgeschaltet wird rund um die Uhr im normalen Intervall abgefragt. |
| **Abfrageintervall nachts** | 30 Minuten | Wie oft nachts nachgefragt wird (10–120 Minuten). |
| **Helligkeitssensor (optional)** | – | Eine beliebige Helligkeits-Variable aus Symcon (z. B. Lux-Wert eines Außensensors). Wenn gewählt, entscheidet der Sensor, wann Nacht ist. |
| **Ab dieser Helligkeit ist Tag** | 50 | Schwelle in der Einheit des Sensors. Liegt der Sensorwert darunter, gilt es als Nacht. Gilt, solange noch kein Wert gelernt ist (oder das Lernen ausgeschaltet ist). |
| **Schwelle automatisch lernen** | an | Das Modul merkt sich jeden Morgen, wie hell es war, als der Wechselrichter zu produzieren begann, und nimmt 80 % des typischen Werts der letzten Tage als Schwelle. Darunter zeigt das Formular die gelernte Schwelle und die Werte der letzten Tage. |

Wie der Nachtmodus arbeitet, steht in [Abschnitt 7](#7-so-kommen-die-werte-zustande).

### PV-Eingänge

Hier legst du je Eingang fest, was angeschlossen ist:

| Auswahl | Wann |
|---------|------|
| **Solarmodul** (Standard) | Am Eingang hängt direkt ein Solarmodul. |
| **Speicher (z. B. Zendure)** | Am Eingang hängt ein Speicher wie der Zendure SolarFlow Hub, der seinen Strom nach der Akku-Steuerung ausgibt und nicht nach der Sonne. |
| **nicht belegt** | Der Eingang ist frei. Seine Variablen werden ausgeblendet. |

Speicher- und freie Eingänge werden bei den Warnungen „hell, aber kaum Leistung“ und „Eingang schwächer“ nicht berücksichtigt (siehe [Abschnitt 8](#8-störungswarnungen)). Die Liste füllt sich, sobald die Anlage eingelesen ist.

**Beispiele:**

| Wechselrichter und Belegung | Einstellung |
|-----------------------------|-------------|
| HMS-800-2T: Eingang 1 Solarmodul, Eingang 2 Zendure-Hub | PV 1 = Solarmodul, PV 2 = Speicher |
| HMS-1800-4T: 2 × Solarmodul, 2 × Zendure-Hub | die beiden Hub-Eingänge = Speicher |
| HMS-1800-4T: 1 × Solarmodul, 1 × Zendure-Hub, 2 frei | Hub-Eingang = Speicher, freie Eingänge = nicht belegt |

**Speicher automatisch erkennen:** Mit dem Haken **„Ein Speicher ist angeschlossen“** achtet das Modul nachts darauf, welche Eingänge noch Strom liefern. Solarmodule liefern nachts nichts – ein Eingang mit mindestens 10 W in der Nacht hängt also am Speicher und wird ab dann automatisch wie **Speicher** behandelt, auch wenn er in der Liste auf „Solarmodul“ steht. Das Modul schreibt dazu eine Meldung ins Meldungsfenster, und unter dem Haken steht, welche Eingänge erkannt wurden. Dafür braucht es einen Helligkeitssensor (Nachtmodus) oder die Location-Instanz. Nach einem Umbau lässt sich die Erkennung mit **„Speicher-Erkennung zurücksetzen“** löschen.

Unabhängig davon vergleicht die Warnung „Eingang schwächer“ nachts gar nicht mehr (sofern Helligkeitssensor oder Location-Instanz vorhanden sind) – nachts liefert höchstens der Speicher, ein Vergleich wäre sinnlos.

Bleibt nur **ein** Solarmodul-Eingang übrig, gibt es nichts zu vergleichen – die Warnung „Eingang schwächer“ ist dann automatisch außer Betrieb. „Hell, aber kaum Leistung“ prüft dann nur dieses eine Modul; die Mindestleistung entsprechend niedrig wählen (z. B. 30 W).

### Störungswarnungen

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Warnen, wenn tagsüber keine Daten kommen** | an | Meldet, wenn der Wechselrichter tagsüber länger nichts an die Cloud sendet. |
| **Keine Daten länger als** | 60 Minuten | Ab wann das als Störung gilt. |
| **Warnen, wenn es hell ist, die Anlage aber kaum Leistung liefert** | an | Funktioniert nur mit Helligkeitssensor. |
| **Helligkeit, ab der Leistung erwartet wird** | 10000 | In der Einheit des Sensors (z. B. Lux). Erst ab dieser Helligkeit wird die Leistung geprüft. |
| **Erwartete Mindestleistung** | 50 W | Liegt die Leistung bei dieser Helligkeit darunter, gilt das als Störung. |
| **Warnen, wenn ein PV-Eingang deutlich weniger liefert als die anderen** | an | Vergleicht die Eingänge untereinander. |
| **Warnen, wenn ein Eingang um mehr als … unter den anderen liegt** | 50 % | Wie groß der Unterschied sein muss. |
| **Erst warnen, wenn der Zustand mindestens anhält** | 30 Minuten | Verhindert Fehlalarme durch einzelne Wolken. Gilt für die Warnungen „kaum Leistung“ und „Eingang schwächer“. |
| **Push-Nachricht über** | – | Eine WebFront- oder Kachel-Visualisierungs-Instanz, über die Push-Nachrichten verschickt werden. |
| **Zusätzlich dieses Skript ausführen** | – | Ein eigenes Skript, z. B. für Telegram oder E-Mail (siehe [Abschnitt 8](#8-störungswarnungen)). |

### Ersparnis

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Ersparnis in Euro berechnen** | an | Legt die Ersparnis-Variablen an. Ausgeschaltet werden sie entfernt. |
| **Strompreis (Bezug)** | 0,30 €/kWh | Was eine Kilowattstunde vom Stromversorger kostet. |
| **Anteil des Ertrags, den du selbst verbrauchst** | 100 % | Welcher Teil des Solarstroms im Haus verbraucht wird. |
| **Einspeisevergütung für den Rest** | 0,00 €/kWh | Was es für eingespeisten Strom gibt. |

### Kachel

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Hintergrund** | Illustration | Illustration, eigenes Bild rechts oben, eigenes Bild über die ganze Kachel oder keiner (siehe [Abschnitt 12](#12-kachel-für-die-visualisierung)). |
| **Eigenes Bild** | – | Medienobjekt mit dem Hintergrundbild. |
| **Sichtbarkeit des eigenen Bildes** | 30 % | Wie kräftig das eigene Bild erscheint. |
| **Nennleistung für den Ring** | 0 | 0 = aus dem Modellnamen des Wechselrichters. Z. B. 800 W eintragen, wenn der Wechselrichter auf 800 W begrenzt ist. |

### Erweitert

| Feld | Standard | Bedeutung |
|------|----------|-----------|
| **Login-Variante** | automatisch | Welche Anmeldeart verwendet wird. „automatisch“ probiert die drei sicheren Varianten (Web, Installer-App, Home-App) nacheinander. Nur ändern, wenn die automatische Erkennung nicht klappt. |
| **Werte älter als … als 0 W werten** | 20 Minuten | Sind die Daten der Cloud älter als diese Zeit (z. B. nachts, wenn der Wechselrichter schläft), werden Leistung, Spannung und Strom auf 0 gesetzt. |

Die Variante **Legacy (v0)** ist absichtlich nicht Teil der automatischen Erkennung, weil sie das Passwort nur schwach geschützt überträgt. Sie sollte nur gewählt werden, wenn keine andere Variante funktioniert (siehe [Datensicherheit](#17-datensicherheit)).

### Schaltflächen

| Schaltfläche | Was sie tut |
|--------------|-------------|
| **Verbindung testen** | Meldet sich neu bei Hoymiles an und zeigt die Anlagen des Kontos. Funktioniert auch bei deaktivierter Instanz – praktisch, um die Zugangsdaten vorher zu prüfen. |
| **Anlage neu einlesen** | Liest Anlage, Wechselrichter und PV-Eingänge erneut aus der Cloud und prüft beim nächsten Abruf die Firmware neu. Nötig, wenn ein Wechselrichter getauscht oder ergänzt wurde. |
| **Jetzt aktualisieren** | Holt sofort die aktuellen Werte, ohne auf den nächsten Zeitpunkt zu warten. |
| **Wechselrichter neu starten / aus / ein**, **DTU neu starten** | Im Bereich „Wechselrichter steuern“ – siehe [Abschnitt 11](#11-wechselrichter-steuern). |
| **Verlauf nachladen** / **Abbrechen** | Im Bereich „Verlauf nachladen“ – siehe [Abschnitt 13](#13-verlauf-nachladen). |

## 6. Die Variablen

Alle Variablen liegen direkt unter der Instanz.

### Werte der Anlage

| Name | Einheit | Bedeutung |
|------|---------|-----------|
| Produziert | Produziert / Standby | „Produziert“, solange der Wechselrichter frische Daten meldet und Leistung liefert |
| Leistung | W | Aktuelle Leistung der gesamten Anlage |
| Ertrag heute | kWh | Ertrag seit Mitternacht |
| Ertrag Monat | kWh | Ertrag im laufenden Monat |
| Ertrag Jahr | kWh | Ertrag im laufenden Jahr |
| Ertrag gesamt | kWh | Gesamtertrag seit Inbetriebnahme (Zählerstand) |
| CO₂-Einsparung | kg | Von Hoymiles berechnete CO₂-Einsparung insgesamt |
| Datenstand Cloud | Datum/Uhrzeit | Zeitpunkt, zu dem die Cloud die Werte zuletzt vom Wechselrichter bekommen hat |
| Letzte Abfrage | Datum/Uhrzeit | Zeitpunkt, zu dem das Modul zuletzt bei der Cloud nachgefragt hat |
| Nachtmodus | Tag / Nacht | Zeigt, ob das Modul gerade im seltenen Nacht-Takt abfragt |
| Störung | keine / Störung | Ist eine der [Störungswarnungen](#8-störungswarnungen) aktiv? „keine“ (grün) heißt: alles in Ordnung. |
| Störungsmeldung | Text | Beschreibung der aktiven Störung(en), sonst leer |
| Ersparnis heute / Monat / Jahr / gesamt | € | Siehe [Ersparnis](#9-ersparnis-in-euro) (nur wenn eingeschaltet) |
| Firmware DTU | Text | Firmware- und Hardware-Stand der DTU |
| Firmware Wechselrichter | Text | Seriennummer und Firmware-Stand je Wechselrichter |
| Firmware-Update | aktuell / Update verfügbar | Ob Hoymiles ein Update anbietet |

**„Datenstand Cloud“ und „Letzte Abfrage“** helfen bei der Einordnung: „Letzte Abfrage“ springt im eingestellten Intervall, „Datenstand Cloud“ nur, wenn Hoymiles tatsächlich neue Daten hat (etwa alle 5 Minuten).

### Werte je PV-Eingang

Für jeden Eingang des Wechselrichters (beim HMS-1800-4T also PV 1 bis PV 4) gibt es vier Variablen:

| Name | Einheit | Bedeutung |
|------|---------|-----------|
| PV 1 Leistung | W | Leistung des Solarmoduls an Eingang 1 |
| PV 1 Spannung | V | Spannung an Eingang 1 |
| PV 1 Strom | A | Strom an Eingang 1 |
| PV 1 Ertrag heute | kWh | Ertrag von Eingang 1 seit Mitternacht |

Sind mehrere Wechselrichter in der Anlage, heißen die Variablen „WR 1 PV 1 Leistung“, „WR 2 PV 1 Leistung“ usw.

An Eingängen, an denen ein **Speicher** hängt (z. B. Zendure SolarFlow Hub), zeigen diese Variablen die **Ausgabe des Speichers** und nicht die Leistung der Solarmodule dahinter. Die kennt nur der Speicher selbst.

### Kennungen für eigene Skripte

Jede Variable hat eine feste Kennung (Ident), die sich auch beim Umbenennen nicht ändert:

| Ident | Variable |
|-------|----------|
| `Producing`, `Power` | Produziert, Leistung |
| `EnergyToday`, `EnergyMonth`, `EnergyYear`, `EnergyTotal` | Erträge heute, Monat, Jahr, gesamt |
| `CO2`, `DataTime`, `LastUpdate`, `NightActive` | CO₂, Datenstand Cloud, Letzte Abfrage, Nachtmodus |
| `Alarm`, `AlarmText` | Störung, Störungsmeldung |
| `SavingsToday`, `SavingsMonth`, `SavingsYear`, `SavingsTotal` | Ersparnis |
| `FirmwareDTU`, `FirmwareInverter`, `FirmwareUpdate` | Firmware |
| `PV1_Power`, `PV1_Voltage`, `PV1_Current`, `PV1_Energy` | Werte von PV-Eingang 1 (entsprechend `PV2_…`, `PV3_…` usw.) |
| `WR1_PV1_Power` usw. | bei mehreren Wechselrichtern |

Beispiel: `GetValue(IPS_GetObjectIDByIdent('PV1_Power', 12345))` liefert die Leistung von Eingang 1 der Instanz mit der ID 12345.

**Darstellungen statt Profile:** Seit Build 23 nutzen alle Variablen die **Darstellungen** von Symcon (Einheit, Nachkommastellen, Icon, Farbe für „Störung“) statt eigener Variablenprofile. Beim Update werden bestehende Variablen automatisch umgestellt; die alten Profile `HOYM.*` löscht das Modul, sobald keine Variable sie mehr verwendet. Eigene Anpassungen an einer Variable (benutzerdefinierte Darstellung) bleiben unberührt.

## 7. So kommen die Werte zustande

Damit die Zahlen richtig eingeordnet werden können, hier kurz der Ablauf:

1. **Der Wechselrichter meldet an die Cloud.** Das geschieht etwa alle 5 Minuten. Schneller als in diesem Takt kann auch das Modul keine neuen Werte bekommen.
2. **Das Modul fragt die Cloud ab** – im eingestellten Intervall. Bei jeder Abfrage holt es zuerst die Werte der gesamten Anlage und dann die Werte der einzelnen PV-Eingänge.
3. **Fehlende Einzelwerte werden ergänzt.** Bei manchen Wechselrichtern liefert die Cloud in der Übersicht nicht für jeden Eingang Werte. Dann liest das Modul den Tagesverlauf des betreffenden Eingangs und nimmt daraus den neuesten Wert.
4. **Ertrag je Eingang:** Alle 15 Minuten liest das Modul die Tagesverläufe aller Eingänge (möglichst mit einer einzigen Anfrage) und berechnet daraus den Tagesertrag jedes Eingangs. Die Werte werden so abgeglichen, dass sie zusammen „Ertrag heute“ ergeben. Beim Einschlafen am Abend wird ein letztes Mal gerechnet, nach Mitternacht beginnen die Werte wieder bei 0.
5. **Nur Änderungen werden geschrieben.** Liefert die Cloud denselben Wert wie beim letzten Mal, wird die Variable nicht erneut geschrieben. So entstehen keine doppelten Einträge im Archiv. Ausnahme ist „Letzte Abfrage“, die bei jedem Abruf aktualisiert wird.
6. **Nachts wird auf 0 gesetzt.** Sobald die Daten älter sind als die eingestellte Zeit (Standard 20 Minuten), zeigen Leistung, Spannung und Strom 0. „Ertrag heute“ wird nach Mitternacht auf 0 gesetzt, sobald der Datenstand vom Vortag ist.
7. **Der Gesamtertrag läuft nie rückwärts.** Meldet die Cloud einmal kurz 0, wird dieser Wert ignoriert und der letzte Zählerstand bleibt stehen.

### Nachtmodus: Was passiert, wenn es dunkel ist?

Ohne Sonnenlicht schaltet der Wechselrichter ab und schickt nichts mehr an die Cloud. Ständiges Nachfragen bringt dann nichts. Deshalb wechselt das Modul nachts in einen sparsamen Betrieb:

- Es fragt nur noch im **Nacht-Intervall** ab (Standard 30 Minuten).
- Es stellt dabei nur **eine einzige Anfrage** (Summenwerte der Anlage). Die Einzelwerte der PV-Eingänge werden nicht abgefragt, sondern direkt auf 0 gesetzt.
- „Produziert“ steht auf *Standby*, „Nachtmodus“ auf *Nacht*.
- Der Status bleibt „Verbunden“ – Dunkelheit ist kein Fehler.

**Woran erkennt das Modul, dass es Nacht ist?** Es nimmt die erste verfügbare Quelle aus dieser Liste:

| Reihenfolge | Quelle | Morgens geht es wieder los … |
|-------------|--------|------------------------------|
| 1 | **Helligkeitssensor** (wenn im Formular gewählt) | … sofort, sobald der Sensor die Schwelle erreicht. Das Modul reagiert direkt auf die Änderung des Sensorwerts und fragt innerhalb einer Sekunde ab. |
| 2 | **Sonnenauf- und -untergang** aus der Symcon-Instanz „Location“ (wenn dort der Standort eingetragen ist) | … pünktlich zum Sonnenaufgang. Das Modul plant die nächste Abfrage genau auf diesen Zeitpunkt. |
| 3 | **Datenstand der Cloud** (immer verfügbar) | … spätestens nach einem Nacht-Intervall, sobald die Cloud wieder frische Daten hat. |

**Sicherheitsregel:** Solange der Wechselrichter noch frische Daten meldet, fragt das Modul immer im normalen Intervall ab – auch wenn Sensor oder Sonnenstand schon „Nacht“ sagen. So gehen in der Abenddämmerung keine Werte verloren.

**Die Schwelle lernt das Modul selbst:** Jeden Morgen schaut es im Tagesverlauf der Cloud nach, wann der Wechselrichter zu produzieren begann, und liest aus dem Archiv des Helligkeitssensors, wie hell es zu diesem Zeitpunkt war. Aus den letzten (bis zu zehn) Tagen nimmt es den typischen Wert (Median) und davon 80 % – so wacht das Modul kurz vor dem Start des Wechselrichters auf. Am besten den Helligkeitssensor archivieren; ohne Archiv kann das Modul nur lernen, wenn der Start erst wenige Minuten zurückliegt. Bis zum ersten gelernten Wert gilt die eingetragene Schwelle.

## 8. Störungswarnungen

Das Modul prüft bei jeder Abfrage drei Dinge:

| Warnung | Wann sie auslöst | Wann sie endet |
|---------|------------------|----------------|
| **Keine Daten** | Tagsüber kommen länger als eingestellt (Standard 60 Minuten) keine Daten vom Wechselrichter. Meist ist dann die DTU oder das WLAN offline. | Sobald wieder frische Daten kommen. Die Warnung bleibt auch über Nacht bestehen, damit sie nicht fälschlich als „behoben“ gemeldet wird. |
| **Hell, aber kaum Leistung** | Der Helligkeitssensor zeigt mindestens die eingestellte Helligkeit, die Anlage liefert aber weniger als die Mindestleistung – und das länger als die Wartezeit. Hinweis auf Schnee, Verschattung oder einen Defekt. | Sobald die Leistung wieder passt oder es dunkler wird. |
| **Eingang schwächer** | Ein PV-Eingang liefert länger als die Wartezeit deutlich weniger (Standard: mehr als 50 % weniger) als der Durchschnitt der anderen. Geprüft wird nur, wenn die anderen im Schnitt mindestens 10 % ihrer Nennleistung liefern (beim HMS-1800-4T 45 W je Eingang, mindestens aber 30 W) – bei wenig Licht morgens, abends oder bei Bewölkung wirken sich unterschiedliche Ausrichtungen sonst zu stark aus. Eingänge, für die die Cloud gerade keinen aktuellen Wert liefert, werden nicht verglichen (sie zählen nicht als 0 W). | Sobald der Eingang wieder aufholt – auch bei wenig Licht, wenn er dann im Rahmen der anderen liegt. |

**Was bei einer Störung passiert:**

- Die Variable **Störung** springt von „keine“ auf „Störung“ (rot), **Störungsmeldung** zeigt den Grund, zum Beispiel: *„PV 3 liefert nur 18 % der anderen Eingänge“*.
- Der Grund wird ins **Meldungsfenster** geschrieben.
- Ist unter **Push-Nachricht über** eine WebFront- oder Kachel-Visualisierungs-Instanz gewählt, kommt eine **Push-Nachricht** aufs Handy.
- Ist ein **Skript** gewählt, wird es ausgeführt.
- Ist alles wieder in Ordnung, kommt einmal die Nachricht *„Störung behoben“*.

Jede Störung wird nur **einmal** gemeldet, nicht bei jeder Abfrage erneut.

**Eigenes Skript für Benachrichtigungen:** Das Skript erhält diese Werte:

| Wert | Inhalt |
|------|--------|
| `$_IPS['TITLE']` | Kurzer Titel, z. B. „Hoymiles: Störung“ |
| `$_IPS['TEXT']` | Beschreibung der Störung |
| `$_IPS['ALARM']` | `true` bei einer Störung, `false` bei „Störung behoben“ und beim Firmware-Hinweis |
| `$_IPS['INSTANCE']` | ID der Hoymiles-Instanz |

Beispiel, das die Meldung per E-Mail verschickt (SMTP-Instanz mit der ID 23456 vorausgesetzt):

```php
<?php
SMTP_SendMail(23456, $_IPS['TITLE'], $_IPS['TEXT']);
```

> **Wichtig zum Vergleich der Eingänge:** Er ist nur sinnvoll, wenn die verglichenen Module gleich ausgerichtet und nicht unterschiedlich verschattet sind. Zeigen die Module in verschiedene Himmelsrichtungen, liefern sie je nach Tageszeit ganz unterschiedlich viel – dann diese Prüfung abschalten.

### Anlage mit Speicher (z. B. Zendure SolarFlow Hub)

Hängt ein Speicher zwischen Solarmodulen und Wechselrichter, richtet sich die Leistung an diesen Eingängen nach der Akku-Steuerung: Mittags lädt der Speicher und gibt vielleicht kaum etwas aus, abends entlädt er ins Haus. Damit das keine Fehlalarme auslöst, die betroffenen Eingänge unter **PV-Eingänge** auf **Speicher** stellen – oder den Haken **„Ein Speicher ist angeschlossen“** setzen, dann erkennt das Modul die Speicher-Eingänge nachts selbst. Dann gilt:

| Warnung | Verhalten |
|---------|-----------|
| Keine Daten | unverändert – bei gemischter Verkabelung halten die direkt angeschlossenen Module den Wechselrichter tagsüber wach |
| Hell, aber kaum Leistung | prüft nur die Summe der **Solarmodul**-Eingänge; die Mindestleistung entsprechend für diese Module wählen |
| Eingang schwächer | vergleicht nur die **Solarmodul**-Eingänge untereinander (mindestens zwei nötig), und nur tagsüber |

Hängen **alle** Eingänge am Speicher, schaltet der Wechselrichter ab, sobald der Speicher tagsüber nichts ausgibt. Dann die Warnung „Keine Daten“ abschalten oder die Zeit deutlich erhöhen (z. B. 240 Minuten).

Ertrag und Ersparnis stimmen in beiden Fällen, denn sie zählen den Strom, den der Wechselrichter tatsächlich ins Haus liefert – auch den aus dem Akku. Entlädt der Speicher abends, bleibt „Produziert“ an und das Modul fragt im normalen Takt weiter ab.

## 9. Ersparnis in Euro

Das Modul rechnet aus, wie viel Geld der Solarstrom spart:

**Ersparnis = Ertrag × (Eigenverbrauchsanteil × Strompreis + Rest × Einspeisevergütung)**

Beispiel: 3,5 kWh Ertrag heute, 100 % Eigenverbrauch, 0,30 €/kWh → 1,05 € gespart.

Bei **Balkonkraftwerken** wird meist fast alles selbst verbraucht und es gibt keine Vergütung – dann passen die Standardwerte (100 %, 0 €). Mit einem Speicher oder bei größeren Anlagen mit Einspeisevergütung die Werte entsprechend anpassen.

Die Ersparnis ist eine **Schätzung**: Das Modul kennt den tatsächlichen Eigenverbrauch nicht, sondern nimmt den eingestellten Anteil an. „Ersparnis gesamt“ bezieht sich auf den gesamten Ertrag seit Inbetriebnahme mit dem **heutigen** Strompreis.

## 10. Firmware

Einmal am Tag (während der Wechselrichter arbeitet) fragt das Modul die Cloud nach den Firmware-Ständen:

- **Firmware DTU** und **Firmware Wechselrichter** zeigen die Stände, z. B. `V01.01.10 (HW H00.04)`.
- **Firmware-Update** springt auf „Update verfügbar“, wenn Hoymiles ein Update anbietet. Dann kommt auch einmal eine Benachrichtigung (über denselben Weg wie die Störungswarnungen).

Das Update selbst wird wie gewohnt über die S-Miles-App eingespielt – das Modul installiert nichts.

Hinweis: Die Firmware-Abfrage nutzt Schnittstellen, die bei Konten der „S-Miles Home“-App etwas anders antworten. Bleiben die Felder leer oder zeigen „?“, liefert die Cloud für das Konto keine Versionsangaben – die übrigen Funktionen sind davon nicht betroffen.

## 11. Wechselrichter steuern

Im Formular unten gibt es den Bereich **Wechselrichter steuern** mit vier Befehlen:

| Schaltfläche | Was passiert |
|--------------|--------------|
| **Wechselrichter neu starten** | Der Wechselrichter startet neu und produziert etwa eine Minute lang nichts. Hilft z. B., wenn er hängt oder nach einer Störung nicht wieder anläuft. |
| **Wechselrichter ausschalten** | Der Wechselrichter hört auf einzuspeisen und **bleibt aus**, bis er wieder eingeschaltet wird – auch über Nacht. |
| **Wechselrichter einschalten** | Schaltet einen ausgeschalteten Wechselrichter wieder ein. |
| **DTU neu starten** | Die DTU (das Funk-Gateway zur Cloud) startet neu. Einige Minuten lang kommen keine Daten in der Cloud an. |

**So läuft ein Befehl ab:**

1. Bei mehreren Wechselrichtern oben den gewünschten **Wechselrichter** auswählen.
2. Schaltfläche klicken. Neustart, Ausschalten und DTU-Neustart fragen zur Sicherheit noch einmal nach.
3. Der Befehl geht über die Hoymiles-Cloud an die DTU – genau wie in der S-Miles-Weboberfläche. Die DTU bestätigt ihn innerhalb von etwa 30 Sekunden.
4. Das Ergebnis steht unter den Schaltflächen und im **Meldungsfenster**, z. B. *„Wechselrichter neu starten“ wurde erfolgreich ausgeführt.* Danach holt das Modul sofort neue Werte.

Es läuft immer nur **ein Befehl gleichzeitig**. Ist der Wechselrichter per Befehl ausgeschaltet, ruhen die Warnungen „hell, aber kaum Leistung“ und „Eingang schwächer“ (er soll ja nichts liefern) und im Formular steht „Per Befehl ausgeschaltet“. Nach dem Einschalten über das Formular sind die Warnungen wieder aktiv.

**Aus Skripten:**

```php
HOYM_SendCommand(12345, 'reboot', '');        // Wechselrichter neu starten ('' = erster Wechselrichter)
HOYM_SendCommand(12345, 'power_off', '');     // ausschalten
HOYM_SendCommand(12345, 'power_on', '');      // einschalten
HOYM_SendCommand(12345, 'dtu_reboot', '');    // DTU neu starten
```

> **Wichtig:** Diese Befehle schalten echte Hardware. Nicht in schnellen Automationen oder Regelungen verwenden (etwa „bei Überschuss aus, sonst ein“) – jeder Befehl braucht bis zu einer halben Minute und belastet die DTU. Für eine dynamische Steuerung der Einspeisung ist z. B. ein Speicher wie der Zendure SolarFlow Hub besser geeignet.

**Leistungsbegrenzung:** Eine Begrenzung der Leistung über die Cloud bietet das Modul nicht an – der passende Befehl der S-Miles-App ist nicht öffentlich bekannt. Die Begrenzung wird wie bisher in der S-Miles-App eingestellt. Bei einem als Balkonkraftwerk angemeldeten Wechselrichter muss die Begrenzung auf 800 W dauerhaft bestehen bleiben.

Hinweis: Bei Konten der „S-Miles Home“-App kann die Cloud Steuerbefehle ablehnen. Das Formular zeigt dann den Grund an.

## 12. Kachel für die Visualisierung

Das Modul bringt eine eigene Kachel für die **Kachel-Visualisierung** von Symcon mit. Sie erscheint automatisch, sobald die Hoymiles-Instanz in die Visualisierung gezogen wird – es muss nichts eingerichtet werden.

**Was die Kachel zeigt:**

- **Kopfzeile:** Name der Anlage und Modell, dazu der Zustand als Symbol mit Text: *Produziert* (Sonne), *Nacht* (Mond), *Standby*, *Ausgeschaltet* oder *Störung* (rot). Rechts eine Schaltfläche zum sofortigen Aktualisieren.
- **Störung:** Liegt eine Störung vor, steht die Meldung als rote Zeile direkt unter der Kopfzeile.
- **Aktuelle Leistung** groß in der Mitte, daneben ein Ring, der den Anteil an der Nennleistung zeigt (z. B. „45 % von 1.800 W“). Solange die Anlage produziert, leuchtet die Sonne im Ring.
- **Ertrag heute** und **heute gespart** (wenn die Ersparnis eingeschaltet ist).
- **Tagesverlauf** der Leistung als Fläche. Mit dem Finger oder der Maus lässt sich jeder Zeitpunkt antippen, dann erscheinen Uhrzeit und Leistung.
- **Die PV-Eingänge** als Balken mit Leistung und Tagesertrag. Speicher-Eingänge sind blau und mit Batterie-Symbol gekennzeichnet, ein auffällig schwacher Eingang wird rot hervorgehoben, freie Eingänge werden nicht angezeigt. Fährt man mit der Maus darüber, erscheinen zusätzlich Spannung und Strom.
- **Fußzeile:** Ertrag des Monats und des Jahres sowie das Alter der Daten („Daten vor 3 Min.“). Dahinter steht, wann das Modul zuletzt abgefragt hat („abgerufen 08:31“). Wer mit der Maus darauf zeigt, sieht zusätzlich die Uhrzeit der Cloud-Daten und des nächsten Abrufs.
- **Aktualisierung:** Nach jedem Abruf schickt das Modul den neuen Stand an die Kachel. Wird die Kachel wieder sichtbar (App aus dem Hintergrund geholt, Seite gewechselt, Kachel geöffnet), aktualisiert sie sich selbst: Ist der letzte Abruf älter als eine Minute, fragt sie sofort die Cloud ab (wie der Aktualisieren-Knopf), sonst holt sie nur den Stand vom Modul. Nachts wird dabei nicht die Cloud abgefragt.
- **Hinweis zum Alter der Daten:** „Daten vor X Min.“ ist das Alter der Werte **in der Cloud**, nicht der Zeitpunkt des Abrufs. Die DTU schickt ihre Werte nur alle paar Minuten an die Cloud. Deshalb kann dort auch direkt nach einem Abruf „vor 5 Min.“ stehen.

**Die Kachel passt sich der Größe an:** Eine kleine Kachel zeigt nur Leistung und Ertrag heute, eine breite zusätzlich den Ring und die Ersparnis, eine große Kachel alles inklusive Tagesverlauf und PV-Eingängen. Farben und Schrift übernimmt sie von der Visualisierung – sie funktioniert in heller und dunkler Darstellung.

**Hintergrund:** Unter **Kachel → Hintergrund** gibt es drei Möglichkeiten:

| Auswahl | Wirkung |
|---------|---------|
| **Illustration** (Standard) | Rechts neben der Kopfzahl ein gezeichnetes Haus mit Solarmodulen, Baum und Speicher an der Hauswand. Solange die Anlage produziert, scheint die Sonne, ein Lichtreflex wandert über die Module und Energie fließt vom Dach in den Speicher. Nachts stehen Mond und funkelnde Sterne am Himmel und die Fenster leuchten warm. Erscheint nur in breiten Kacheln, in denen dafür Platz ist. Wer Animationen in den Systemeinstellungen reduziert hat, bekommt ein ruhiges Bild. |
| **Eigenes Bild an Stelle der Illustration** | Ein eigenes Bild (z. B. ein Foto oder Rendering deines Hauses) erscheint rechts oben, dort wo sonst das gezeichnete Haus steht. Die Ränder werden weich ausgeblendet, damit sich das Bild in die Kachel einfügt; nachts wird es abgedunkelt. Am besten wirkt ein Bild mit dunklem oder ruhigem Hintergrund, auf dem das Haus möglichst den ganzen Ausschnitt füllt. Bei schmalen Kacheln wird es – wie die Illustration – ausgeblendet. |
| **Eigenes Bild über die ganze Kachel** | Ein Foto (z. B. vom eigenen Haus oder den Solarmodulen) wird abgeblendet über die ganze Kachel gelegt. Dazu das Bild im Objektbaum als **Medienobjekt** (Typ Bild) hochladen – große Bilder verkleinert das Modul automatisch und unter **Eigenes Bild** auswählen. Mit **Sichtbarkeit** (Standard 30 %) lässt sich einstellen, wie kräftig es erscheint – 20–40 % halten die Werte gut lesbar. |
| **Keiner** | Schlichte Kachel ohne Hintergrund. |

**Einstellung:** Die Nennleistung für den Ring nimmt das Modul aus dem Modellnamen (HMS-1800-4T → 1.800 W). Bei einem auf 800 W begrenzten Balkonkraftwerk bietet es sich an, unter **Kachel → Nennleistung für den Ring** 800 W einzutragen – dann ist der Ring bei voller Einspeisung auch voll.

Der Tagesverlauf wird alle 15 Minuten aus der Cloud neu berechnet und dazwischen mit dem aktuellen Wert ergänzt.

### Kachelschema und Symcon-Design

Die Kachel richtet sich nach dem **Design der Visualisierung**. Symcon stellt Schrift-, Akzent- und Kartenfarbe als CSS-Variablen bereit (`--content-color`, `--accent-color`, `--card-color`); die Kachel übernimmt Schrift- und Hintergrundfarbe daraus und leitet alle Linien und Nebenfarben davon ab. Sie passt so zu hellen und dunklen Designs.

Unter **Kachel → Farben** gibt es zwei Farbschemas:

| Farben | Wirkung |
|--------|---------|
| **Sonnengelb (klassisch)** | Leistung, Ring, Tagesverlauf und Balken in Sonnengelb – wie bisher. Standard. |
| **Symcon-Design** | Diese Elemente nehmen die **Akzentfarbe** deines Designs an. |

Speicher-Eingänge sind in beiden Schemas schraffiert, damit sie auch bei einer blauen Akzentfarbe erkennbar bleiben.

**Antippen öffnet die Variable** (ab Symcon 8.2): Leistung, Ertrag heute, Ersparnis, Monat, Jahr, die Störungsmeldung und jede PV-Zeile öffnen die zugehörige Variable – mit Verlauf, sofern sie archiviert wird. In älteren Versionen bleibt die Kachel wie bisher.

## 13. Verlauf nachladen

Frisch eingerichtet beginnen die Diagramme in Symcon erst mit dem heutigen Tag. Die Hoymiles-Cloud hat aber den Verlauf der vergangenen Tage gespeichert. Diesen kann das Modul ins Archiv holen.

**So geht’s:**

1. Im Formular unten den Bereich **Verlauf nachladen** aufklappen.
2. Die Anzahl der **Tage** einstellen (1–365, Standard 30).
3. Auf **Verlauf nachladen** klicken. Der Fortschritt steht darunter („Nachladen: 12 von 30 Tagen“).
4. Am Ende steht eine Zusammenfassung im Formular und im Meldungsfenster.

**Was nachgeladen wird:**

| Variable | Was eingetragen wird |
|----------|---------------------|
| PV 1 … PV n Leistung | Die Leistung jedes Eingangs im 5-Minuten-Raster, so wie die Cloud sie gespeichert hat |
| Ertrag gesamt | Je Tag der Zählerstand um 23:59 – so zeigt Symcon den Tagesertrag für jeden nachgeladenen Tag |

**Gut zu wissen:**

- Pro Tag dauert es etwa 2 Sekunden. 30 Tage sind also in rund einer Minute fertig, ein ganzes Jahr in etwa 12 Minuten. Das Nachladen läuft im Hintergrund weiter, auch wenn das Formular geschlossen wird, und lässt sich mit **Abbrechen** stoppen.
- **Tage, die schon Werte im Archiv haben, werden übersprungen** – es wird nichts doppelt eingetragen. Das Nachladen kann also gefahrlos mehrmals gestartet werden.
- Die Zählerstände werden aus den Tagesverläufen **berechnet**: Ausgehend vom heutigen Zählerstand der Cloud werden die Tageserträge rückwärts abgezogen. Weil die Verläufe die Leistung der Solarmodule (vor dem Wechselrichter) zeigen, rechnet das Modul sie mit dem Wirkungsgrad des Wechselrichters um. Die nachgeladenen Tageserträge können deshalb um wenige Prozent von der App abweichen.
- Die Gesamtleistung („Leistung“) wird nicht nachgeladen, nur die Leistung je Eingang.
- Voraussetzung: **Archivieren** ist eingeschaltet.

## 14. Archivierung

Ist **Archivieren** eingeschaltet, richtet das Modul die Aufzeichnung im Symcon-Archiv selbst ein:

| Variable | Art der Aufzeichnung |
|----------|---------------------|
| Leistung | Standard |
| PV 1 … PV n Leistung | Standard |
| Ertrag gesamt | **Zähler**, Nullwerte werden ignoriert |

Durch die Einstellung als Zähler kann Symcon den Ertrag für beliebige Zeiträume (Tag, Woche, Monat, Jahr) selbst berechnen, zum Beispiel für Diagramme oder die Energie-Auswertung.

Das Modul schaltet die Archivierung nur **ein**, nie aus. Wird das Häkchen entfernt, bleibt eine bereits eingerichtete Aufzeichnung bestehen und kann in der Konsole wie gewohnt selbst angepasst werden. Weitere Variablen (z. B. „PV 1 Ertrag heute“ oder die Ersparnis) lassen sich jederzeit von Hand zur Archivierung hinzufügen.

## 15. Status der Instanz

Der Status wird oben im Konfigurationsformular und im Objektbaum angezeigt.

| Code | Anzeige | Bedeutung und was zu tun ist |
|------|---------|------------------------------|
| 102 | Verbunden | Alles in Ordnung. |
| 104 | Instanz ist deaktiviert | Der Schalter „Instanz aktiv“ ist aus. Zum Starten wieder einschalten. |
| 204 | Bitte Zugangsdaten eintragen | Benutzername oder Passwort fehlen. |
| 201 | Login fehlgeschlagen | Zugangsdaten prüfen (am besten in der App testen). Siehe [Fehlerbehebung](#18-fehlerbehebung). |
| 202 | Keine Anlage bzw. kein Wechselrichter gefunden | Im Konto ist keine Anlage oder kein Mikrowechselrichter vorhanden, oder die gewählte Anlage gehört nicht zum Konto. |
| 203 | Fehler beim Abruf aus der Cloud | Die Cloud war nicht erreichbar oder hat einen Fehler gemeldet. Details stehen im Meldungsfenster. Meist nur vorübergehend. |

Der Status beschreibt die **Verbindung zur Cloud**. Probleme an der Anlage selbst (keine Daten, zu wenig Leistung) zeigt die Variable **Störung** (siehe [Abschnitt 8](#8-störungswarnungen)).

Fehler werden nur beim **Wechsel** in einen Fehlerzustand ins Meldungsfenster geschrieben, nicht bei jeder Abfrage erneut.

## 16. Befehle für eigene Skripte

Die Funktionen tragen das Präfix `HOYM_`. Als erster Parameter wird jeweils die ID der Instanz übergeben.

```php
// Sofort neue Werte holen
HOYM_Update(12345);

// Anlage, Wechselrichter und PV-Eingänge neu einlesen
HOYM_Discover(12345);

// Anmeldung testen und Anlagen auflisten (Ausgabe als Text)
HOYM_TestConnection(12345);

// Verlauf der letzten 30 Tage ins Archiv nachladen / Nachladen abbrechen
HOYM_Backfill(12345, 30);
HOYM_BackfillCancel(12345);

// Wechselrichter / DTU steuern (siehe Abschnitt 11)
HOYM_SendCommand(12345, 'reboot', '');
```

Beispiel: Den schwächsten Eingang des Tages ermitteln:

```php
$id = 12345; // ID der Hoymiles-Instanz
$ertraege = [];
foreach ([1, 2, 3, 4] as $pv) {
    $ertraege["PV $pv"] = GetValue(IPS_GetObjectIDByIdent("PV{$pv}_Energy", $id));
}
asort($ertraege);
echo 'Schwächster Eingang heute: ' . array_key_first($ertraege) . ' mit ' . reset($ertraege) . ' kWh';
```

## 17. Datensicherheit

**Was das Modul schützt:**

- Die Verbindung läuft ausschließlich per **HTTPS mit Zertifikatsprüfung** zu den Servern von Hoymiles (`neapi.hoymiles.com`, `euapi.hoymiles.com`). Weiterleitungen auf andere Server werden nicht verfolgt.
- Es werden **keine Daten an Dritte** geschickt – nur an Hoymiles. Push-Nachrichten laufen über deine eigene Symcon-Visualisierung.
- Das **Passwort wird nicht im Klartext übertragen.** Es wird vorher mit einem aufwendigen Verfahren (Argon2id) umgerechnet, zusammen mit einem Einmal-Code, den der Server bei jeder Anmeldung neu vergibt. Wer den Datenverkehr mitschneidet, kann damit nichts anfangen.
- Die unsichere **Legacy-Anmeldung** (einfacher MD5-Wert, ohne Einmal-Code) wird nie automatisch verwendet, sondern nur, wenn sie ausdrücklich ausgewählt ist.
- Im **Debug-Fenster** und in Fehlermeldungen werden Anmeldedaten und Token geschwärzt – auch, wenn eine Antwort abgeschnitten ist. Bei einer fehlerhaften Anmelde-Antwort landet gar nichts aus der Antwort in der Meldung.
- **Begrenzte Antworten:** Antworten der Cloud sind auf 8 MB begrenzt (auch nach dem Entpacken), der Tagesverlauf wird beim Einlesen auf Plausibilität geprüft. Fehlerhafte oder manipulierte Daten führen zu einer Fehlermeldung statt zu einem Absturz.
- **Kein Dauerfeuer auf das Konto:** Über die Kachel oder `RequestAction` wird höchstens alle 30 Sekunden abgefragt. Nach einer abgelehnten Anmeldung wartet das Modul 15 Minuten, dann 30, 60 … bis höchstens 6 Stunden, damit das Hoymiles-Konto nicht gesperrt wird. „Übernehmen“ oder „Verbindung testen“ starten sofort einen neuen Versuch.
- Zwei Abrufe laufen **nie gleichzeitig** (z. B. Timer und Kachel), damit sich Token und Zwischenstände nicht gegenseitig überschreiben.
- Texte aus der Cloud (Anlagenname, Störungsmeldungen) werden in der Kachel immer als reiner Text angezeigt, nie als HTML.
- Um zu erkennen, ob die Zugangsdaten geändert wurden, speichert das Modul einen **Prüfwert** (HMAC-SHA-256 mit zufälligem Salt je Instanz). Aus diesem Wert lässt sich das Passwort nicht zurückrechnen.

**Was man wissen sollte:**

- Wie bei allen Symcon-Modulen speichert Symcon das **Passwort unverschlüsselt** in seiner Konfiguration – und damit auch in Backups. Das Passwortfeld verdeckt die Eingabe nur auf dem Bildschirm. Wer Administrator-Zugriff auf Symcon oder Zugriff auf die Backups hat, kann das Passwort auslesen.
- Der **Zugangs-Token** der Cloud wird ebenfalls in Symcon gespeichert. Er ist nur etwa 2 Stunden gültig.

**Empfehlung:** Für das Hoymiles-Konto ein Passwort verwenden, das nirgendwo sonst genutzt wird. Dann bleibt der mögliche Schaden im schlimmsten Fall auf die Ansicht der PV-Anlage begrenzt.

### Geschwindigkeit und Last

- **Eine Verbindung je Abruf:** Alle Anfragen eines Abrufs nutzen dieselbe HTTPS-Verbindung (Keep-Alive). Das spart den Verbindungsaufbau für jede einzelne Anfrage. Antworten werden komprimiert übertragen.
- **Keine unnötigen Anfragen:** Liefert die Cloud bei der gemeinsamen Tagesverlauf-Anfrage nicht alle Eingänge, fragt das Modul einen Tag lang gleich einzeln – statt bei jedem Abruf erst vergeblich gemeinsam.
- Nachts reicht **eine Anfrage** je Abruf; Tagesertrag je Eingang und Tagesverlauf werden höchstens alle 15 Minuten neu berechnet; Variablen werden nur bei geänderten Werten geschrieben.
- Die **Kachel** hält ihre Animationen an, solange sie nicht sichtbar ist (App im Hintergrund), und ein eigenes Bild wird nur einmal verkleinert und dann zwischengespeichert.

## 18. Fehlerbehebung

**„Verbindung testen“ meldet „Login fehlgeschlagen“**

- Zugangsdaten in der S-Miles-App prüfen.
- Die Meldung enthält für jede Anmeldeart die Antwort von Hoymiles. Steht dort etwa „can only be used in … app“, gehört das Konto zu einer bestimmten App – die automatische Erkennung sollte dann die passende Variante finden.
- Klappt keine der drei Varianten, kann unter **Erweitert → Login-Variante** als letzter Versuch „Legacy (v0)“ gewählt werden (siehe [Datensicherheit](#17-datensicherheit)).

**Meldung: PHP-Erweiterung „sodium“ fehlt**

Die sichere Anmeldung benötigt diese PHP-Erweiterung. Sie gehört zu üblichen PHP-Installationen. Fehlt sie in der Symcon-Installation, bitte beim Symcon-Support nachfragen.

**Die Werte ändern sich nur alle 5 Minuten, obwohl ein kürzeres Intervall eingestellt ist**

Das ist normal: Die Hoymiles-Cloud hat nur etwa alle 5 Minuten neue Werte (siehe [Abschnitt 7](#7-so-kommen-die-werte-zustande)). An der Variable „Letzte Abfrage“ lässt sich sehen, dass das Modul trotzdem im eingestellten Takt nachfragt.

**Nachts steht die Leistung auf 0 W / es wird nur alle 30 Minuten abgefragt**

Gewollt – der Wechselrichter schläft ohne Sonnenlicht, und das Modul ist im Nachtmodus (siehe [Abschnitt 7](#7-so-kommen-die-werte-zustande)). Grenze und Intervall lassen sich unter **Erweitert** bzw. **Nachtmodus** einstellen.

**Morgens kommen die ersten Werte spät**

- Mit Helligkeitssensor: Schwelle niedriger einstellen.
- Ohne Sensor: In der Symcon-Instanz „Location“ den Standort eintragen, dann wird pünktlich zum Sonnenaufgang abgefragt.
- In der Variable „Nachtmodus“ bzw. im Debug-Fenster (Eintrag „Night mode … detected via …“) ist zu sehen, welche Quelle das Modul verwendet.

**Ständig Warnung „PV x liefert nur … % der anderen Eingänge“**

Hängt an diesem Eingang ein Speicher, ihn unter **PV-Eingänge** auf „Speicher“ stellen. Sonst sind die Module vermutlich unterschiedlich ausgerichtet oder teilweise verschattet. Unter **Störungswarnungen** die Abweichung erhöhen, die Wartezeit verlängern oder den Vergleich abschalten.

**Warnung „Keine Daten“, obwohl die App Werte zeigt**

„Datenstand Cloud“ prüfen. Ist er aktuell, bitte einen Ausschnitt aus dem Debug-Fenster sichern und melden.

**Keine Push-Nachricht**

- Unter **Push-Nachricht über** muss eine WebFront- oder Kachel-Visualisierungs-Instanz gewählt sein, und auf dem Handy muss die Symcon-App mit dieser Visualisierung verbunden und für Benachrichtigungen freigegeben sein.
- Das Meldungsfenster enthält jede Störung auch dann, wenn keine Push-Nachricht ankommt.

**Verlauf nachladen: „0 Zählerstände eingetragen“**

Für diese Tage stehen schon Werte im Archiv, oder „Ertrag gesamt“ hatte noch keinen Wert (dann erst einmal einen normalen Abruf abwarten und erneut starten).

**Tagsüber steht die Leistung auf 0 W, obwohl die Sonne scheint**

- „Datenstand Cloud“ prüfen: Ist der Zeitpunkt älter als 20 Minuten, hat der Wechselrichter keine Daten mehr an die Cloud gesendet. Dann in der S-Miles-App nachsehen, ob DTU bzw. Wechselrichter online sind.
- Ist der Datenstand aktuell, kann ein größerer Wert unter **Erweitert → Werte älter als …** helfen.

**Ein PV-Eingang zeigt dauerhaft 0**

- Ist an diesem Eingang überhaupt ein Solarmodul angeschlossen?
- Stimmt die Anzahl der Eingänge im Formular (z. B. „4 PV-Eingänge“)? Falls nicht, **Anlage neu einlesen** klicken.

**Wechselrichter getauscht oder hinzugefügt**

Auf **Anlage neu einlesen** klicken. Das Modul passt die Variablen an.

**Mehr Details sehen**

In der Konsole bei der Instanz auf **Debug** klicken. Dort erscheint jede Anfrage an die Cloud mit der Antwort. Anmeldedaten sind darin ausgeblendet. Bei Problemen hilft ein Ausschnitt aus diesem Fenster am meisten.

## 19. Grenzen des Moduls

- Hoymiles bietet **keine offizielle Schnittstelle** für Privatkunden an. Das Modul nutzt dieselben Wege wie die Hoymiles-Apps. Ändert Hoymiles diese mit einem Update, kann eine Anpassung des Moduls nötig werden.
- Das Modul kann den Wechselrichter **neu starten, aus- und einschalten** und die DTU neu starten – aber **keine Leistungsbegrenzung** setzen und keine Firmware installieren.
- Die Daten sind **bis zu etwa 5 Minuten alt**, weil sie über die Cloud laufen. Für eine sekundengenaue Regelung (etwa Nulleinspeisung oder PV-Überschussladen) ist eine lokale Anbindung bzw. ein Stromzähler besser geeignet.
- Batteriespeicher von Hoymiles werden von diesem Modul nicht ausgelesen.

## 20. Für Entwickler: Sprachen, Tests, GitHub

**Sprachen:** Die Oberfläche ist in Englisch geschrieben und wird über `HoymilesCloud/locale.json` ins Deutsche übersetzt. Symcon zeigt automatisch die Sprache der Konsole an. Damit erfüllt das Modul die Voraussetzung für eine spätere Veröffentlichung im Symcon-Module-Store.

**Automatische Tests:** Im Ordner `tests` liegen Tests, die das Modul mit den offiziellen [SymconStubs](https://github.com/symcon/SymconStubs) gegen eine nachgebaute Hoymiles-Cloud (`tests/FakeCloud/server.php`) prüfen – ohne echtes Symcon und ohne echte Cloud. Getestet werden u. a.:

- die Modul-Validierung von Symcon (library.json, module.json, Formular, Übersetzungen)
- Login, Abruf, Werte je Eingang und Tagesertrag je Eingang
- Nachtmodus mit Datenstand, Helligkeitssensor und Sonnenaufgang
- alle drei Störungswarnungen inkl. Wartezeit und „Störung behoben“, auch mit Speicher-Eingängen
- Ersparnis, Firmware, Verlauf nachladen
- die Kachel (Werte, Störung, Tagesverlauf, Aktualisieren-Schaltfläche)
- Steuerbefehle (Neustart, Aus/Ein, DTU-Neustart) inkl. Fehlerfall und ruhender Warnungen im ausgeschalteten Zustand
- dass bei falschem Passwort nie die unsichere Legacy-Anmeldung verwendet wird

**GitHub:** Die Datei `.github/workflows/tests.yml` führt bei jedem Hochladen auf GitHub automatisch die Syntaxprüfung aller Dateien und alle Tests mit PHP 8.2 und 8.3 aus. Das Ergebnis steht im Repository unter **Actions**.

Tests lokal ausführen:

```bash
git clone https://github.com/symcon/SymconStubs.git tests/stubs
phpunit --configuration phpunit.xml
```

## 21. Versionshistorie

### Was ist neu in 1.1

Version 1.1 fasst alle Erweiterungen seit 1.0 zusammen. Bestehende Einstellungen, Variablen und Archivdaten bleiben beim Update erhalten – es muss nichts neu eingerichtet werden.

- **Kachel für die Kachel-Visualisierung:** aktuelle Leistung mit Ring, Tagesertrag und Ersparnis, Tagesverlauf, Balken je PV-Eingang, Störungsanzeige und Aktualisieren-Knopf. Aktualisiert sich selbst, auch nach dem Öffnen oder wenn die App im Hintergrund war.
- **Hintergrund der Kachel:** gezeichnetes Haus mit Tag/Nacht-Darstellung, eigenes Bild rechts oben oder über die ganze Kachel; große Bilder werden automatisch verkleinert.
- **Wechselrichter steuern:** Neustart, Aus- und Einschalten, DTU-Neustart über das Formular oder `HOYM_SendCommand`.
- **Störungswarnungen:** keine Daten, hell aber kaum Leistung, ein Eingang deutlich schwächer – mit Push-Nachricht oder eigenem Skript; keine Fehlalarme bei wenig Licht oder fehlenden Werten.
- **PV-Eingänge einzeln:** Leistung, Spannung, Strom und Tagesertrag je Eingang; Einstellung „Solarmodul“, „Speicher“ (z. B. Zendure) oder „nicht belegt“.
- **Nachtmodus:** seltener abfragen, wenn es dunkel ist – über Helligkeitssensor (Schwelle wird automatisch gelernt), Sonnenauf- und -untergang oder den Datenstand der Cloud.
- **Ersparnis in Euro**, **Firmware-Stände** mit Update-Hinweis und **Verlauf ins Archiv nachladen**.
- **Genauere Tagesverläufe:** Überbleibsel vom Vortag um Mitternacht und unfertige letzte Abschnitte der Cloud werden erkannt.
- **Sicherheit:** keine unsichere Legacy-Anmeldung in der automatischen Erkennung, gesalzener Prüfwert für die Zugangsdaten.
- **Englische Oberfläche mit deutscher Übersetzung**, automatische Tests und GitHub-Workflow.

### Alle Builds

| Version | Build | Änderungen |
|---------|-------|------------|
| 1.1 | 24 | „Produziert“, „Nachtmodus“ und „Firmware-Update“ zeigten in der Symcon-App „Invalid Configuration“: jede Option der Darstellung hat jetzt immer eine Farbe; Störungsmeldung als einfache Textzeile |
| 1.1 | 23 | Symcon-9.0-Technik: Basisklasse `IPSModuleStrict` (ab Symcon 8.1), Darstellungen statt Variablenprofile (alte `HOYM.*`-Profile werden aufgeräumt), kompatibel mit PHP 8.5; Kachel folgt dem Design der Visualisierung, neues Farbschema „Symcon-Design“, Antippen öffnet Variablen (`openObject`, ab 8.2); Sicherheit: Token-Schwärzung, HTTPS-only, Größenbegrenzung, robuster Tagesverlauf-Decoder, höchstens ein Abruf je 30 s über Kachel/Skript, Wartezeit nach falscher Anmeldung, keine gleichzeitigen Abrufe; Geschwindigkeit: eine Verbindung je Abruf, Komprimierung, überflüssige Sammelanfrage entfällt, Kachel-Animationen pausieren im Hintergrund; Badges und Lizenzangaben im README |
| 1.1 | 22 | Neuer Haken „Ein Speicher ist angeschlossen“: Eingänge, die nachts Strom liefern, werden automatisch als Speicher erkannt und aus den Warnungen genommen; „Eingang schwächer“ vergleicht nachts nicht mehr |
| 1.1 | 21 | Version 1.1: Zusammenfassung aller Erweiterungen seit 1.0 (siehe oben) |
| 1.0 | 20 | Verkleinern sehr großer Bilder prüft vorher den freien Speicher (hebt die PHP-Grenze bei Bedarf kurz an) – kein „Allowed memory size exhausted“ mehr beim Übernehmen; ist es trotzdem zu groß, erscheint die Illustration und ein Hinweis im Meldungsfenster |
| 1.0 | 19 | Große Bilder für die Kachel werden automatisch verkleinert (behebt „Output-Buffer exceeds Limit“) |
| 1.0 | 18 | Kachel: eigenes Bild wahlweise an Stelle der Haus-Illustration (rechts oben, weich ausgeblendete Ränder, nachts abgedunkelt) |
| 1.0 | 17 | Überbleibsel um Mitternacht wird an der Zeitlücke erkannt (einzelner Wert um 00:00, nächster erst am Morgen) – auch bei Speicher-Eingängen, die morgens gleich Leistung liefern; genauere Ertragsberechnung bei lückenhaften Tagesverläufen; ausführlichere Debug-Ausgabe zum Tagesverlauf |
| 1.0 | 16 | Überbleibsel um Mitternacht wird zusätzlich in der Summe aller Eingänge erkannt; kein Einbruch auf 0 W mehr am Ende des Tagesverlaufs, wenn der letzte Abschnitt in der Cloud noch nicht fertig ist |
| 1.0 | 15 | Überbleibsel um Mitternacht wird auch erkannt, wenn danach kleine Werte folgen (Ruhestrom des Speichers); Tagesverlauf und Ertrag je Eingang werden nach dem Übernehmen bzw. Update sofort neu berechnet |
| 1.0 | 14 | Keine „liefert nur 0 %“-Fehlalarme mehr, wenn die Cloud für einen Eingang gerade keinen Wert liefert; Warnung „Eingang schwächer“ verschwindet auch bei wenig Licht, sobald die Eingänge wieder gleichauf liegen; Überbleibsel vom Vortag um Mitternacht (Zacke im Tagesverlauf, zu hohe Tageserträge je Eingang) wird ignoriert; mehrere Warnungen in der Kachel untereinander |
| 1.0 | 13 | Kachel fragt beim Öffnen sofort die Cloud ab, wenn der letzte Abruf älter als eine Minute ist; Uhrzeit des letzten Abrufs direkt in der Fußzeile |
| 1.0 | 12 | Kachel holt sich den aktuellen Stand, sobald sie wieder sichtbar wird (verpasste Aktualisierungen, z. B. wenn die App im Hintergrund war); Uhrzeit von Cloud-Daten, letztem und nächstem Abruf in der Fußzeile |
| 1.0 | 11 | Schwelle des Helligkeitssensors wird automatisch aus der Helligkeit beim morgendlichen Produktionsstart gelernt; aufwendigere Haus-Illustration mit Lichtreflex auf den Modulen, Energiefluss in den Speicher, ziehender Wolke und funkelnden Sternen |
| 1.0 | 10 | Kachel-Hintergrund: Illustration (Haus mit Solarmodulen, Tag/Nacht), eigenes Bild aus einem Medienobjekt oder keiner; Kachel-Einstellungen in eigenem Formularbereich |
| 1.0 | 9 | Geänderte PV-Eingänge oder Warn-Einstellungen setzen die betroffenen Warnungen sofort zurück; Tagesverlauf bleibt bei Störungsmeldung sichtbar, sofern Platz ist |
| 1.0 | 8 | Kachel: Platz oben für Titel und Vollbild-Knopf der Visualisierung, größerer Aktualisieren-Knopf, kompaktere Darstellung bei mittlerer Höhe; Tagesverlauf auch bei anderen Zeitformaten der Cloud; Eingangsvergleich erst ab 10 % der Nennleistung |
| 1.0 | 7 | Eigene Kachel für die Kachel-Visualisierung (Leistung mit Ring, Tagesverlauf, PV-Eingänge, Störung, Aktualisieren-Schaltfläche; passt sich Größe und hell/dunkel an) |
| 1.0 | 6 | Wechselrichter neu starten, aus- und einschalten, DTU neu starten (über das Formular und `HOYM_SendCommand`) |
| 1.0 | 5 | Variable „Störung“ zeigt „keine“ / „Störung“ statt „OK“ / „Alarm“; Einstellung je PV-Eingang „Solarmodul“, „Speicher“ (z. B. Zendure) oder „nicht belegt“ (Variablen werden ausgeblendet); Speicher-Eingänge werden bei den Warnungen „hell, aber kaum Leistung“ und „Eingang schwächer“ nicht berücksichtigt |
| 1.0 | 4 | Störungswarnungen (keine Daten, hell aber kaum Leistung, Eingang schwächer) mit Push-Nachricht und Skript; Tagesertrag je PV-Eingang; Ersparnis in Euro; Firmware-Stände und Update-Hinweis; Verlauf ins Archiv nachladen; englische Oberfläche mit deutscher Übersetzung; automatische Tests und GitHub-Workflow; Tagesverläufe aller Eingänge mit einer Anfrage |
| 1.0 | 3 | Nachtmodus mit seltenerer Abfrage; optionaler Helligkeitssensor; Sonnenauf- und -untergang aus der Location-Instanz; neue Variablen „Produziert“ und „Nachtmodus“; nachts nur noch eine Anfrage je Abruf |
| 1.0 | 2 | Legacy-Anmeldung nicht mehr in der automatischen Erkennung; gesalzener Prüfwert für die Zugangsdaten; Hinweis zum Abfrageintervall im Formular; Aktivierungsschalter; Versionsanzeige im Formular |
| 1.0 | 1 | Erste Version |

## 22. Lizenz und Dank

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei `LICENSE`): Jeder darf es nutzen, verändern und weitergeben – auch in eigenen Projekten –, solange der Lizenz- und Copyright-Hinweis erhalten bleibt. Es gibt keine Gewährleistung. Jede Code-Datei trägt dazu einen Kopf mit `SPDX-License-Identifier: MIT` und den Rechteinhabern.

| Teil | Rechteinhaber | Lizenz |
|------|---------------|--------|
| Modul, Kachel, Tests | Armin Frohwerk | MIT |
| Cloud-Client `libs/HoymilesClient.php` (Anmeldung, Endpunkte) | Portierung von Philra94 / Armin Frohwerk | MIT |
| Hinweise zu Firmware-, Geräte- und Steuerbefehlen (kein übernommener Code) | Eistee82 (ioBroker.hoymiles) | MIT |

Der Teil für die Kommunikation mit der Hoymiles-Cloud ist eine Portierung des Home-Assistant-Projekts [homeassistant-hoymiles-cloud](https://github.com/Philra94/homeassistant-hoymiles-cloud) von Philra94 (ebenfalls MIT-Lizenz). Hinweise zu Firmware-, Geräte- und Steuer-Schnittstellen stammen aus dem ioBroker-Adapter [ioBroker.hoymiles](https://github.com/Eistee82/ioBroker.hoymiles). Vielen Dank für die Vorarbeit beim Entschlüsseln von Anmeldung und Datenformat.

Dieses Modul ist kein offizielles Produkt von Hoymiles und steht in keiner Verbindung zu Hoymiles Power Electronics Inc. „Hoymiles“ und „S-Miles“ sind Marken ihrer jeweiligen Inhaber und werden hier nur zur Beschreibung verwendet. Das Modul nutzt die nicht offiziell dokumentierte Schnittstelle der S-Miles Cloud; Hoymiles kann sie jederzeit ändern. Die abgerufenen Anlagendaten gehören dir – das Modul gibt sie an niemanden weiter.
