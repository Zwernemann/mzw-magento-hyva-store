# Reusable prompt — Codespace → EC2 Magento migration tooling

This is the prompt that would (re)produce the migration tooling in `migration/`
in a single shot. It front-loads the constraints that originally only surfaced
through back-and-forth, and instructs the agent to verify the rest itself.

## The prompt

```text
I have a fully working Magento Open Source shop running in this GitHub Codespace
(Magento root = repo root, with bin/magento, app/, vendor/, pub/). I want to
migrate ONLY the functioning shop — codebase + database + media — to a second
machine: an AWS EC2 instance running Amazon Linux 2023 that already has PHP 8.4
and MariaDB installed.

Deliverable: an export script I run here that produces ONE self-contained TAR
bundle, plus an installer script (inside the bundle) I run on the EC2 that does
the whole setup. Also write a README. Put all of this under migration/.

Before writing anything, INSPECT the running shop and don't assume — check:
- exact Magento version and the PHP version it expects,
- which backing services it currently uses (read app/etc/env.php + core_config_data):
  cache, session, message queue, search engine,
- the DB server version and table collation,
- DB size, media size, any custom modules in app/code.
Report what you find, then build accordingly.

Hard constraints / environment facts (these are NON-NEGOTIABLE):
- The EC2 has ONLY PHP 8.4 + MariaDB. No Redis, no RabbitMQ, no search engine
  installed, and I do NOT want the installer to install any of them — not even
  optionally. Reconfigure Magento to file cache, file sessions, and the DB queue.
- Search engine: the EC2 already runs an Elasticsearch 7.x that must stay
  untouched (different app needs it). Magento 2.4.x dropped ES7, so I run a
  SEPARATE managed OpenSearch as an EXTERNAL service (Aiven, over HTTPS with
  username/password). The installer must point Magento at that external endpoint
  and ONLY verify reachability — never install or start a local search engine.
  Magento reads the protocol from the hostname, so store the scheme with it
  (https://...). Aiven's cert is publicly trusted, so no CA cert is needed;
  still expose an OPTIONAL CA-cert path (added via update-ca-trust) as a
  fallback for endpoints that use a private CA.
- Ship vendor/ inside the bundle so the EC2 needs NO composer run and NO
  Hyvä/Magento auth credentials. Never ask me for those credentials.
- Preserve the crypt key from the source env.php so encrypted DB values still
  decrypt; rewrite env.php for the target (localhost DB, no Redis/RabbitMQ).
- Handle the MySQL/MariaDB collation gotcha: if the source uses a collation the
  target MariaDB version doesn't support, rewrite it during import.
- File ownership on the target: EVERY file in the install dir must end up owned
  by a configurable owner:group (I'll use magento:apache). Guarantee this even
  for files Magento generates during setup:upgrade/reindex (setgid dirs +
  group-writable + a final re-chown and a verification pass). The env.php write
  must be sudo-safe. Abort early if the user or group doesn't exist.

Installer requirements:
- Run it as a normal sudo-capable user (ec2-user); escalate with sudo only where
  needed; run bin/magento as the Magento file owner. Must also work if invoked
  as root.
- Everything configurable via env vars with sensible defaults: APP_DIR,
  BASE_URL (required), DB host/name/user/pass, RUN_USER, FILE_GROUP, and the
  SEARCH_* settings (engine, scheme, host, port, user, pass, CA cert path,
  index prefix). Provide a --help.
- Steps: pre-flight checks (PHP version + required extensions, mysql client,
  search endpoint reachable) → extract code+media → create DB+user → import dump
  (with collation fix) → write env.php → set ownership → rewrite base URL and
  search config in core_config_data → setup:upgrade → reindex → cache:flush →
  re-chown + verify → print a clear summary with the remaining manual steps
  (web server docroot to pub/, PHP-FPM user, cron, security group) and a
  reminder to delete the bundle.
- Keep MAGE_MODE=default (static content generated on demand; no di:compile).
- Bundle excludes var/, generated/, pub/static, .git, app/etc/env.php, and any
  auth.json — but includes pub/media and app/etc/config.php.

Security: the bundle contains the full DB dump and the crypt key — treat it as a
secret, add it (and any local backup archives) to .gitignore, and tell me to
delete it after the migration. Don't push anything; just commit the migration/
scripts at the end with a conventional-commit message.

Syntax-check both scripts, actually build the bundle to confirm it works, and
then walk me through the EC2 steps.
```

## Why these additions (prompting lessons)

- **State the constraints only you know.** "EC2 has only PHP+MariaDB", "ES 7 must
  stay", "external Aiven OpenSearch", "ownership magento:apache" — each of these
  cost an iteration because it was missing from the first prompt.
- **Tell the agent to inspect, not assume.** Letting it read `env.php` /
  `core_config_data` surfaces the version, Redis/RabbitMQ/OpenSearch usage, the
  crypt key, and the collation by itself.
- **Spell out negative requirements** ("install NO search engine, not even
  optionally") — otherwise the agent adds "helpful" options you don't want.
- **Name the non-obvious pitfalls up front** (TLS trust store, crypt key,
  collation, sudo-safe env.php write) — these only showed up as bugs otherwise.

A true one-shot is still hard: some facts (the existing Elasticsearch was 7.x)
only emerged mid-conversation. But this prompt would have saved most of the rounds.
