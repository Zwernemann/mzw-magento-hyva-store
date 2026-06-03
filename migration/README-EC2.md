# Magento Umzug Codespace → EC2 (Amazon Linux 2023)

Dieses Bündel enthält deinen kompletten, lauffähigen Magento-Shop:
Code + `vendor/` (kein Composer/Hyvä-Login nötig), die komplette Datenbank
und die Medien. Ein Installer richtet alles auf der EC2 ein.

> **Bündel erzeugen (im Codespace):** Das portable Tool `magento-export.sh`
> ausführen — es erkennt die Magento-Root automatisch, dumpt die DB und erzeugt
> genau dieses Bündel (inkl. diesem Installer). Eine einzige Datei, in jeden
> Magento-Codespace kopierbar.

## Inhalt des Bündels

| Datei | Inhalt |
|---|---|
| `code.tar.gz` | Magento-Code, `vendor/`, `pub/media`, `app/etc/config.php` (ohne `var/`, `generated/`, `pub/static`, `.git`, `auth.json`) |
| `db.sql.gz` | Vollständiger Datenbank-Dump (alle Shop-Daten) |
| `env.source.php` | Ursprüngliche `env.php` – nur als Quelle für den **Crypt-Key** (wichtig, sonst lassen sich verschlüsselte DB-Werte nicht mehr entschlüsseln) |
| `install-on-ec2.sh` | Der Installer |
| `README-EC2.md` | Diese Datei |

## Voraussetzungen auf der EC2

- **PHP 8.4** mit den üblichen Magento-Extensions (`intl`, `gd`, `pdo_mysql`, `bcmath`, `soap`, `xsl`, `sodium`, `zip`, …) ✅ (hast du)
- **MariaDB** läuft, root per `sudo mysql` erreichbar ✅ (hast du)
- **Externe Suchmaschine** – Magento 2.4 läuft **nicht** ohne. Magento **2.4.9
  unterstützt nur `elasticsearch8` oder `opensearch`** (Elasticsearch 7 wurde
  entfernt). **Der Installer installiert selbst keine Suchmaschine** – er zeigt
  Magento auf deinen externen Dienst (z. B. **OpenSearch bei Aiven**) und prüft
  nur die Erreichbarkeit. Deine lokale Elasticsearch 7 bleibt damit komplett
  unberührt (kein Port-Konflikt, kein RAM-Verbrauch). Siehe Abschnitt unten.
- **Datei-Owner** – alle Dateien im Zielverzeichnis werden auf `RUN_USER:FILE_GROUP`
  gesetzt (z. B. `magento:apache`). Beide müssen auf der EC2 existieren.
- Web-Server (nginx oder Apache) + PHP-FPM – richtest du nach dem Installer ein.
- Empfehlung: ≥ 4 GB RAM (OpenSearch + MariaDB + PHP-FPM auf einer Box).

> Redis und RabbitMQ aus dem Codespace werden **bewusst weggelassen**. Der
> Installer konfiguriert stattdessen File-Cache, File-Sessions und die DB-Queue.
> Das hält den EC2-Stack minimal. Später nachrüstbar.

## Ablauf

1. Bündel auf die EC2 kopieren:
   ```bash
   scp -i deinkey.pem magento-migrate-*.tar.gz ec2-user@<EC2_IP>:~/
   ```
2. Auf der EC2 entpacken und Installer starten:
   ```bash
   tar xzf magento-migrate-*.tar.gz
   cd magento-migrate
   BASE_URL=http://<EC2_IP>/ ./install-on-ec2.sh
   ```
   `BASE_URL` ist Pflicht (öffentliche URL/IP deines Shops). Alles andere hat
   sinnvolle Defaults – siehe `./install-on-ec2.sh --help`.

   **Dein Setup: externe OpenSearch bei Aiven, Dateien als `magento:apache`:**
   ```bash
   BASE_URL=http://<EC2_IP>/ \
   RUN_USER=magento FILE_GROUP=apache \
   SEARCH_ENGINE=opensearch SEARCH_SCHEME=https \
   SEARCH_HOST=<service>.aivencloud.com SEARCH_PORT=<port> \
   SEARCH_USER=avnadmin SEARCH_PASS=<pass> \
   ./install-on-ec2.sh
   ```
   > **Kein CA-Zertifikat nötig:** Aivens TLS-Zertifikat ist öffentlich vertraut
   > (im System-CA-Store von Amazon Linux 2023). `SEARCH_CA_CERT` brauchst du
   > nur, falls dein Endpoint eine **private** CA verwendet.

Alle Variablen: `./install-on-ec2.sh --help`. **Der Installer installiert keine
Suchmaschine** – er nutzt deinen externen Dienst und prüft nur die Erreichbarkeit.

## Managed OpenSearch über Aiven (Free-Tier) einrichten

Passt zu deinem Fall: lokale **Elasticsearch 7** bleibt unverändert, Magento nutzt
eine getrennte, externe OpenSearch.

1. Bei [aiven.io](https://aiven.io) ein **OpenSearch**-Service anlegen
   (Free-/Hobbyist-Plan, Region nahe der EC2). Warten bis „Running“.
   > Free-Tier vorher kurz prüfen: Plan-Größe/Retention reichen für einen
   > Demo-Magento locker; Magento legt nur eine Handvoll Indizes an.
2. Im Aiven-Dashboard unter **Connection information** notieren:
   `Host`, `Port`, `User` (meist `avnadmin`), `Password`.
3. Installer mit dem OpenSearch-Beispiel oben starten — **kein Zertifikat nötig**.

**Kein CA-Zertifikat erforderlich.** Aivens TLS-Zertifikat ist öffentlich vertraut
(z. B. Let's Encrypt) und liegt bereits im System-CA-Store von Amazon Linux 2023.
Sowohl die Vorab-Prüfung als auch Magentos OpenSearch-Client vertrauen ihm direkt.
`SEARCH_CA_CERT` brauchst du nur als Fallback, falls dein Endpoint eine **private**
CA verwendet (dann legt der Installer das Cert via `update-ca-trust` ab).

> Tipp: Erst testen mit
> `curl -u avnadmin:<pass> https://<host>:<port>/`
> — kommt eine JSON-Antwort mit `"distribution":"opensearch"`, passt alles.
> (Kein `--cacert` verwenden — das führt nur zu Zertifikatsfehlern.)

   Beispiel mit allen Werten:
   ```bash
   APP_DIR=/var/www/magento \
   BASE_URL=https://shop.example.com/ \
   DB_NAME=magento DB_USER=magento DB_PASS=geheim \
   RUN_USER=magento FILE_GROUP=apache \
   SEARCH_ENGINE=opensearch SEARCH_SCHEME=https \
   SEARCH_HOST=<service>.aivencloud.com SEARCH_PORT=<port> \
   SEARCH_USER=avnadmin SEARCH_PASS=<pass> \
   ./install-on-ec2.sh
   ```

Der Installer macht: Checks → Code entpacken → DB + User anlegen → Dump
importieren (Kollation wird bei MariaDB < 11.4 automatisch angepasst) →
frische `env.php` schreiben (Crypt-Key erhalten) → Base-URL setzen →
Rechte → `setup:upgrade` → `reindex` → `cache:flush`.

## Nach dem Installer (manuell)

Diese Schritte sind bewusst **nicht** im Skript, weil sie von deiner
Server-Einrichtung abhängen:

1. **Web-Server**: Docroot auf `$APP_DIR/pub` zeigen lassen. Magento liefert
   `nginx.conf.sample` mit – in deinen Server-Block einbinden:
   ```nginx
   set $MAGE_ROOT /var/www/magento;
   include /var/www/magento/nginx.conf.sample;
   ```
2. **PHP-FPM**: läuft als `apache` (bzw. dein `RUN_USER`) und ist gestartet.
   Der Pool-User sollte zur `FILE_GROUP` passen, damit er in `var/`, `generated/`,
   `pub/static` und `pub/media` schreiben darf (das Skript setzt diese
   gruppen-schreibbar + setgid).
3. **Cron** (empfohlen, sonst keine Index-/Mail-/Queue-Jobs) – als `RUN_USER`:
   ```bash
   sudo -u magento crontab -l   # prüfen
   * * * * * php /var/www/magento/bin/magento cron:run >> /var/www/magento/var/log/cron.log 2>&1
   ```
4. **Security Group / Firewall** für HTTP(S) öffnen.
5. **Bündel löschen** – es enthält DB-Dump **und** Crypt-Key:
   ```bash
   rm -rf ~/magento-migrate ~/magento-migrate-*.tar.gz
   ```

## Performance später optimieren (optional)

- In den **Production-Mode** wechseln (statische Assets vorab deployen):
  ```bash
  cd /var/www/magento
  php bin/magento deploy:mode:set production
  ```
- Redis für Cache/Session nachrüsten und in `env.php` eintragen.
- OpenSearch-Heap (`/etc/opensearch/jvm.options`) an den RAM anpassen.

## Troubleshooting

- **Storefront 500 beim ersten Aufruf** → `var/log/` prüfen; meist ist
  OpenSearch noch nicht erreichbar oder die Rechte stimmen nicht.
- **„Could not validate a connection to Elasticsearch/OpenSearch“** →
  `curl localhost:9200` prüfen, ggf. `systemctl status opensearch`.
- **Reindex-Fehler** → erst OpenSearch-Gesundheit sichern, dann
  `php bin/magento indexer:reindex` erneut.
- **Falsche URLs / Redirect-Loop** → Base-URL in `core_config_data` prüfen
  und `php bin/magento cache:flush`.
