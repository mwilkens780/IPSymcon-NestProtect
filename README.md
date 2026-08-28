# Nest Protect – IP-Symcon Modul

Bindet Google Nest Protect Rauch-/CO-Melder in IP-Symcon ein. Google bietet dafür **keine offizielle API** an -- die "Smart Device Management API" unterstützt nur Thermostat, Cam, Doorbell und Hub Max, Nest Protect ist explizit ausgenommen. Dieses Modul nutzt stattdessen die gleiche inoffizielle, Cookie-basierte Nest-Web-API wie die bekannten Home-Assistant-Projekte `ha-nest-protect` und `nest_legacy`.

**Wichtig:** Das ist eine undokumentierte API, keine öffentliche Schnittstelle. Sie kann sich jederzeit ändern oder von Google blockiert werden (ist 2022 schon einmal passiert). Nest Protect liefert außerdem nur Lesezugriff -- es gibt keine steuerbaren Aktionen.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-NestProtect
```

## Konfiguration

### 1. Nest-Konto-Instanz anlegen

Eine **NestAccount**-Instanz anlegen (eine pro Nest-Konto). Je nachdem, ob das Konto zu einem Google-Konto migriert wurde oder nicht, nur **einen** der beiden folgenden Wege nutzen -- beide brauchen einmalig manuell aus dem Browser geholte Zugangsdaten. In beiden Fällen: Entwicklertools des Browsers öffnen (Chrome/Edge: Taste **F12**), Reiter **"Network"/"Netzwerk"**, Checkbox **"Preserve log"/"Log beibehalten"** anhaken (sonst verschwindet die gesuchte Anfrage bei der Weiterleitung während des Logins), dann erst einloggen.

**Legacy-Konto (nicht zu Google migriert):**
1. Auf [home.nest.com](https://home.nest.com) mit dem Nest-Konto einloggen (nicht über Google).
2. Im Netzwerk-Tab nach `session` filtern.
3. Bei der Anfrage an `home.nest.com/session` im **Antwort-Body (Response)** das Feld `access_token` kopieren → als `Access Token` eintragen.

**Google-Konto (zu Google migriert):**
1. Auf [home.nest.com](https://home.nest.com) einloggen.
2. Im Netzwerk-Tab nach `issue_token` filtern.
3. Die gefundene Anfrage an `accounts.google.com` öffnen:
   - Die **komplette Anfrage-URL** kopieren → als `Issue Token` eintragen.
   - Den Wert des Anfrage-Headers `cookie` kopieren → als `Cookies` eintragen.

Danach über den Button "Verbindung testen / Geräte auflisten" prüfen, ob die Anmeldung funktioniert -- die gefundenen Geräte werden dort mit Seriennummer und Modell aufgelistet.

Die Zugangsdaten bleiben gültig, bis man sich im Browser ausloggt oder das Passwort ändert -- dann müssen sie hier neu eingetragen werden.

### 2. Eine NestProtect-Instanz pro Melder anlegen

Für jeden Rauchmelder eine **NestProtect**-Instanz anlegen:
- **Nest-Konto-Instanz**: die oben angelegte NestAccount-Instanz auswählen.
- **Seriennummer**: aus der Geräteliste des Kontos (siehe oben) übernehmen.

Ausgelesene Werte: Rauch-Alarm, CO-Alarm, Hitze-Alarm, Batterie (%, aus der rohen Millivolt-Spannung umgerechnet), Netzstrom (kabelgebunden ja/nein), Stummschaltung, Zeitpunkt des letzten Selbsttests, Austauschdatum.

## Funktionsweise

`NestAccount` meldet sich periodisch bei Google/Nest an (Cookie → Google-OAuth-Token → Nest-JWT → Nest-Session, mit Zwischenspeicherung der Session bis zum Ablauf) und fragt die Gerätedaten ab. `NestProtect`-Instanzen haben keine eigene Verbindung -- sie lesen die zwischengespeicherten Daten der zugeordneten Konto-Instanz aus und suchen sich darin ihre eigene Seriennummer heraus. So authentifiziert und pollt nicht jeder Melder einzeln.
