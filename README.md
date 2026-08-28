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

Eine **NestAccount**-Instanz anlegen (eine pro Nest-Konto). Je nachdem, ob das Konto zu einem Google-Konto migriert wurde oder nicht, nur **einen** der beiden folgenden Wege nutzen -- beide brauchen einmalig manuell aus dem Browser geholte Zugangsdaten (Anleitung übernommen aus dem aktiv gepflegten [`nest_legacy`](https://github.com/tronikos/nest_legacy)-Projekt, das beide Kontotypen unterstützt).

**Legacy-Konto (nicht zu Google migriert) -- einfacher Weg, kein DevTools nötig:**
1. Auf [home.nest.com](https://home.nest.com) mit dem Nest-Konto einloggen (nicht über Google).
2. **Neuen Tab** öffnen und direkt zu `https://home.nest.com/session` navigieren.
3. Im angezeigten Text `"access_token": "..."` suchen -- **der Name kommt mehrfach vor** (z. B. noch einmal verschachtelt in einem `weave`-Bereich). Das richtige Feld ist das **erste/oberste**, ganz am Anfang der Seite. Nur den Wert zwischen den Anführungszeichen kopieren → als `Access Token` eintragen. Zur Kontrolle: der richtige Wert beginnt mit `b` und ist deutlich kürzer als 100 Zeichen -- ein Wert, der nicht mit `b` beginnt oder sehr lang ist (200+ Zeichen, endet oft auf `=`), ist das falsche, verschachtelte Feld.
4. **Nicht** bei home.nest.com ausloggen -- das macht den Token sofort ungültig. Einfach den Tab schließen.

**Google-Konto (zu Google migriert):**

⚠️ **Nicht Chrome oder Edge verwenden** -- Google bindet die Anmeldung dort an eine hardwaregebundene Sicherheits-Session, die nach wenigen Stunden (oder beim IPS-Neustart) wieder ungültig wird. **Firefox oder Safari** verwenden, und bei home.nest.com den Tracking-Schutz deaktivieren (Firefox: Schild-Symbol in der Adressleiste).

1. Entwicklertools öffnen (**F12**) → Reiter **Network** → Checkbox **"Preserve Log"** anhaken.
2. Filter auf `issueToken` setzen → auf home.nest.com "Sign in with Google" klicken und einloggen.
3. Die Anfrage `iframerpc` anklicken → im Headers-Tab die komplette **Request-URL** kopieren → als `Issue Token` eintragen.
4. Filter auf `oauth2/iframe` ändern → die **letzte** `iframe`-Anfrage anklicken → bei Request Headers den Wert von `cookie` kopieren → als `Cookies` eintragen.
5. **Nicht** ausloggen, nur den Tab schließen.

Danach über den Button "Verbindung testen / Geräte auflisten" prüfen, ob die Anmeldung funktioniert -- die gefundenen Geräte werden dort mit Seriennummer und Modell aufgelistet.

Die Zugangsdaten bleiben gültig, bis man sich im Browser ausloggt oder das Passwort ändert -- dann müssen sie hier neu eingetragen werden.

### 2. Eine NestProtect-Instanz pro Melder anlegen

Für jeden Rauchmelder eine **NestProtect**-Instanz anlegen:
- **Nest-Konto-Instanz**: die oben angelegte NestAccount-Instanz auswählen.
- **Seriennummer**: aus der Geräteliste des Kontos (siehe oben) übernehmen.

Ausgelesene Werte: Rauch-Alarm, CO-Alarm, Hitze-Alarm, Batterie (%, aus der rohen Millivolt-Spannung umgerechnet), Netzstrom (kabelgebunden ja/nein), Stummschaltung, Zeitpunkt des letzten Selbsttests, Austauschdatum.

## Funktionsweise

`NestAccount` meldet sich periodisch bei Google/Nest an (Cookie → Google-OAuth-Token → Nest-JWT → Nest-Session, mit Zwischenspeicherung der Session bis zum Ablauf) und fragt die Gerätedaten ab. `NestProtect`-Instanzen haben keine eigene Verbindung -- sie lesen die zwischengespeicherten Daten der zugeordneten Konto-Instanz aus und suchen sich darin ihre eigene Seriennummer heraus. So authentifiziert und pollt nicht jeder Melder einzeln.
