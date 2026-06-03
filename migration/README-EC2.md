# Magento Umzug Codespace → EC2 (Amazon Linux 2023)

Dieses Bündel enthält deinen kompletten, lauffähigen Magento-Shop:
Code + `vendor/` (kein Composer/Hyvä-Login nötig), die komplette Datenbank
und die Medien. Ein Installer richtet alles auf der EC2 ein.

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
- **Suchmaschine** – Magento 2.4 läuft **nicht** ohne. Magento **2.4.9 unterstützt
  nur `elasticsearch8` oder `opensearch`** (Elasticsearch 7 wurde entfernt!).
  - Hast du bereits **Elasticsearch 8.x** auf der Box → mit
    `SEARCH_ENGINE=elasticsearch8` nutzen, der Installer installiert dann **kein**
    OpenSearch (kein Port-Konflikt). Der Installer prüft die ES-Version vorab.
  - Sonst kann der Installer ein lokales Single-Node-**OpenSearch** installieren
    (fragt nach).
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

   **Mit deiner bestehenden Elasticsearch 8.x** (kein OpenSearch, kein Konflikt):
   ```bash
   BASE_URL=http://<EC2_IP>/ \
   SEARCH_ENGINE=elasticsearch8 SEARCH_HOST=localhost SEARCH_PORT=9200 \
   ./install-on-ec2.sh
   ```

   Beispiel mit allen Werten:
   ```bash
   APP_DIR=/var/www/magento \
   BASE_URL=https://shop.example.com/ \
   DB_NAME=magento DB_USER=magento DB_PASS=geheim \
   RUN_USER=nginx \
   SEARCH_ENGINE=elasticsearch8 SEARCH_HOST=localhost SEARCH_PORT=9200 \
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
2. **PHP-FPM**: läuft als `nginx` (oder `apache`) und ist gestartet.
3. **Cron** (empfohlen, sonst keine Index-/Mail-/Queue-Jobs):
   ```bash
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
