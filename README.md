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

Eine **NestAccount**-Instanz anlegen (eine pro Google/Nest-Konto). Sie braucht `issue_token` und `cookies`, die einmalig manuell aus dem Browser geholt werden müssen:

1. Im Browser auf [home.nest.com](https://home.nest.com) einloggen.
2. Entwicklertools öffnen → Netzwerk-Tab → nach `issue_token` filtern.
3. Seite neu laden.
4. Die gefundene Anfrage an `accounts.google.com` öffnen:
   - Die **komplette Anfrage-URL** kopieren → als `Issue Token` eintragen.
   - Den Wert des Anfrage-Headers `cookie` kopieren → als `Cookies` eintragen.

Danach über den Button "Verbindung testen / Geräte auflisten" prüfen, ob die Anmeldung funktioniert -- die gefundenen Geräte werden dort mit Seriennummer und Modell aufgelistet.

Die Anmeldung bleibt gültig, bis man sich im Browser ausloggt oder das Passwort ändert -- dann müssen `issue_token`/`cookies` hier neu eingetragen werden.

### 2. Eine NestProtect-Instanz pro Melder anlegen

Für jeden Rauchmelder eine **NestProtect**-Instanz anlegen:
- **Nest-Konto-Instanz**: die oben angelegte NestAccount-Instanz auswählen.
- **Seriennummer**: aus der Geräteliste des Kontos (siehe oben) übernehmen.

Ausgelesene Werte: Rauch-Alarm, CO-Alarm, Hitze-Alarm, Batterie (%, aus der rohen Millivolt-Spannung umgerechnet), Netzstrom (kabelgebunden ja/nein), Stummschaltung, Zeitpunkt des letzten Selbsttests, Austauschdatum.

## Funktionsweise

`NestAccount` meldet sich periodisch bei Google/Nest an (Cookie → Google-OAuth-Token → Nest-JWT → Nest-Session, mit Zwischenspeicherung der Session bis zum Ablauf) und fragt die Gerätedaten ab. `NestProtect`-Instanzen haben keine eigene Verbindung -- sie lesen die zwischengespeicherten Daten der zugeordneten Konto-Instanz aus und suchen sich darin ihre eigene Seriennummer heraus. So authentifiziert und pollt nicht jeder Melder einzeln.
