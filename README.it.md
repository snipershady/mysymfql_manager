# mysymfql_manager

Interfaccia web self-hosted per la gestione di server MySQL, costruita sul framework Symfony.

## Descrizione

mysymfql_manager è un'applicazione web che consente di gestire uno o più server MySQL remoti da un'unica interfaccia centralizzata. Gli utenti sono associati a specifici server MySQL e possono eseguire operazioni amministrative come la navigazione tra i database, la gestione delle tabelle, la gestione degli utenti MySQL, l'esecuzione di backup e ripristini, e il monitoraggio dei processi del server — tutto dal browser.

## Funzionalità

### Autenticazione e gestione utenti
- Login / logout
- Registrazione utente
- Reimpostazione della password via email (password temporanea inviata per email)
- Cambio password autenticato con verifica della password corrente
- CRUD degli utenti applicativi (solo admin)

### Gestione server MySQL (SqlClient)
- CRUD per i record dei server MySQL (host, porta, nome utente, password cifrata)
- Proprietà many-to-many: ogni server può essere assegnato a più utenti applicativi
- Dashboard con selettore del server (vengono mostrati solo i server assegnati all'utente autenticato)

### Dashboard
- Statistiche in tempo reale per il server selezionato: database assegnati, connessioni attive, processi in esecuzione, processi bloccati
- Tabella dei database con nome, dimensione, numero di tabelle e link alle azioni rapide
- Ricaricamento automatico al cambio del server tramite `fetch()`

### Gestione database e schema
- Elenco dei database per un server
- Pagina di dettaglio del database con lista delle tabelle
- Creazione database (charset/collation configurabili) con creazione opzionale di utente MySQL e GRANT
- Eliminazione database
- Svuotamento tabella (DELETE + reset AUTO_INCREMENT)
- Eliminazione tabella

### Backup e ripristino
- Backup di una singola tabella (struttura + dati tramite `mysqldump`)
- Ripristino della tabella da file di backup
- Dump compressi con zstd di default (gzip o nessuna compressione configurabili), decompressi automaticamente per visualizzazione e ripristino

### Gestione utenti MySQL
- Elenco degli utenti MySQL su un server con flag di grant per database
- Creazione utente MySQL con grant su database
- Eliminazione utente MySQL
- Cambio password utente MySQL

### Query console
- Editor SQL (CodeMirror 6) con completamento basato sullo schema, EXPLAIN / EXPLAIN ANALYZE
- Export del risultato mostrato in **CSV** (RFC 4180, UTF-8 con BOM) o **JSON**, generato nel browser: la query non viene rieseguita
- **Storico** delle proprie query sul database (dall'audit log: ultimi 90 giorni, password oscurate); un clic rimette l'istruzione nell'editor, senza eseguirla

### Monitoraggio del server
- Lista dei processi con terminazione del processo tramite ID
- Stato del motore InnoDB (solo admin)
- Transazioni InnoDB aperte (`information_schema.INNODB_TRX`: thread, stato, inizio, righe bloccate) con terminazione del thread
- Gli utenti non admin vedono solo processi e transazioni che lavorano sui propri database

### Audit log
- Ogni modifica fatta dal pannello viene registrata: sui server gestiti (creazione/eliminazione database,
  svuotamento/eliminazione/optimize tabelle, query console — ogni istruzione, letture comprese —, account MySQL
  creati/eliminati, password, grant, kill, ripristino ed eliminazione dei dump) e sul pannello stesso (utenti
  applicazione, server, assegnazioni database)
- Ogni voce: data, utente del pannello e IP, azione, server/database/oggetto, esito (riuscita, fallita con l'errore
  MySQL nativo, negata, dati non validi) e i parametri non segreti; le password non vengono mai salvate (i letterali di
  `IDENTIFIED BY`, `PASSWORD …` nell'SQL sono oscurati)
- Pagina `/admin/audit-log` (solo admin) con filtri per utente, server, azione, esito e periodo
- Pagina **Verifica grant** (`/admin/server-grants`) e comando `app:check-server-grants`: segnalano gli account dei server con grant oltre i database dei loro utenti
- Al massimo 90 giorni di storico, eliminati da `app:audit-prune` (cron)

---

## Requisiti

- PHP >= 8.4
- MySQL >= 8.4.8 (database applicativo)
- Redis/Valkey (sessione/cache)
- Binario `mysqldump` disponibile nel PATH
- Binario `zstd` disponibile nel PATH (compressione dei backup, vedi [Compressione dei backup](#compressione-dei-backup-zstd))
- Composer
- Un relay SMTP funzionante o un DSN mailer

---

## Installazione di `mysqldump` (client Oracle MySQL su Debian/Ubuntu)

L'applicazione richiede il binario `mysqldump` nel `PATH`. Il modo consigliato per installare gli strumenti client ufficiali Oracle MySQL su Debian o Ubuntu è tramite il repository APT di MySQL.

### 1. Scarica e installa il pacchetto di configurazione APT di MySQL

```bash
wget https://dev.mysql.com/get/mysql-apt-config_0.8.36-1_all.deb
sudo dpkg -i mysql-apt-config_0.8.36-1_all.deb
```

Durante l'installazione apparirà una finestra di dialogo che chiede quale prodotto MySQL configurare. Seleziona **MySQL Server & Cluster** e scegli la versione MySQL desiderata (es. `mysql-8.4`), poi seleziona **Ok**.

### 2. Aggiorna l'indice dei pacchetti

```bash
sudo apt update
```

### 3. Installa solo gli strumenti client MySQL (senza server)

```bash
sudo apt install mysql-client
```

Questo installa `mysql`, `mysqldump`, e le altre utilità client senza installare il server MySQL.

### 4. Verifica

```bash
mysqldump --version
```

Esempio di output atteso:

```
mysqldump  Ver 8.4.x Distrib 8.4.x, for Linux (x86_64)
```

> Se hai già il pacchetto `mysql-client` predefinito di Debian/Ubuntu installato (dal repository della distribuzione) e devi passare alla versione Oracle, rimuovilo prima: `sudo apt remove mysql-client && sudo apt autoremove`.

## Compressione dei backup (`zstd`)

I nuovi dump vengono compressi con **zstd** di default (`bkp_….sql.zst`): su un dump reale da 419 MB `zstd -3` ha
prodotto 121 MB (−71%) in 0,3 s, contro 7,6 s di `gzip -6` con lo stesso rapporto. Il formato si sceglie con
`BACKUP_COMPRESSION` in `.env.local`:

| Valore | File | Strumento necessario |
|---|---|---|
| `zstd` (default) | `bkp_….sql.zst` | `zstd` |
| `gzip` | `bkp_….sql.gz` | `gzip` (sempre installato) |
| `none` | `bkp_….sql` | — |

- Il formato di un dump esistente si ricava dall'estensione: cambiare l'impostazione non tocca i dump precedenti, e i
  dump `.sql`, `.sql.gz` e `.sql.zst` vengono elencati, visualizzati, ripristinati e ruotati allo stesso modo.
- Visualizzazione e ripristino dal pannello decomprimono automaticamente (il ripristino decomprime in un file temporaneo
  `bkp_restore_*.sql.part` dentro `BACKUP_PATH`, eliminato alla fine: serve spazio per il dump non compresso).
- La compressione non fa mai perdere un backup: se lo strumento manca, `BACKUP_COMPRESSION` non è valido o la
  compressione fallisce, il dump resta non compresso (`.sql`) e viene loggato un warning (mostrato anche dai comandi CLI).
- Il pulsante di download invia il file così com'è salvato (compresso).

### Installare `zstd` su Debian 13 (trixie)

`zstd` è nel repository ufficiale `main` di Debian (anche Debian 12, Ubuntu ≥ 18.04, RHEL/Rocky, Alpine):

```bash
sudo apt update
sudo apt install zstd
zstd --version    # *** Zstandard CLI (64-bit) v1.5.7 … ***
```

Deve essere nel `PATH` dell'utente che esegue PHP (`www-data` per PHP-FPM, l'utente del cron per i comandi CLI):
`/usr/bin/zstd` lo è. Per verificarlo dal punto di vista del web server: `sudo -u www-data which zstd`.

### Decomprimere un dump da riga di comando

```bash
# Decomprime accanto all'originale (il .zst resta): bkp_….sql
zstd -d bkp_2026-11-28_03-00-00_shop_full.sql.zst

# Decomprime in un altro file / sullo standard output
zstd -d bkp_2026-11-28_03-00-00_shop_full.sql.zst -o /tmp/shop.sql
zstd -dc bkp_2026-11-28_03-00-00_shop_full.sql.zst | less

# Ripristina direttamente in un database, senza scrivere il file decompresso
zstd -dc bkp_2026-11-28_03-00-00_shop_full.sql.zst | mysql -h HOST -u USER -p shop

# Verifica l'integrità di un dump
zstd -t bkp_2026-11-28_03-00-00_shop_full.sql.zst

# Dump gzip (.sql.gz)
gunzip -k bkp_2026-11-28_03-00-00_shop_full.sql.gz
gunzip -c bkp_2026-11-28_03-00-00_shop_full.sql.gz | mysql -h HOST -u USER -p shop
```

---

## Setup

### Prerequisiti — creazione del database applicativo e dell'utente

Prima di eseguire il comando di setup, crea il database MySQL e un utente dedicato che l'applicazione utilizzerà per connettersi. Connettiti al tuo server MySQL come root:

```bash
mysql -u root -p
```

Poi esegui:

```sql
CREATE DATABASE mysymfql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mysymfql'@'127.0.0.1' IDENTIFIED BY 'StrongPassword123!';
GRANT ALL PRIVILEGES ON mysymfql.* TO 'mysymfql'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> Adatta l'host (`127.0.0.1`) all'indirizzo da cui il processo PHP si connette a MySQL. Se PHP e MySQL girano sulla stessa macchina, `127.0.0.1` è corretto. Sostituisci `StrongPassword123!` con una password forte, generata casualmente.

### 1. Clona il repository e installa le dipendenze

```bash
git clone <repo-url> mysymfql_manager
cd mysymfql_manager
composer install
```

### 2. Esegui il comando di setup — prima esecuzione (configura `.env.local`)

Il comando `app:setup` guida l'utente attraverso l'intera configurazione in modo interattivo.

```bash
php bin/console app:setup
```

Alla prima esecuzione, poiché non esiste ancora nessun file `.env.local`, il comando:

1. **Genera automaticamente** le seguenti chiavi segrete e le mostra a schermo:
   - `APP_SECRET` — segreto applicativo Symfony (hex a 64 caratteri)
   - `SQLCLIENT_ENCRYPTION_KEY` — chiave di cifratura per le credenziali MySQL salvate nel DB (hex a 64 caratteri, XSalsa20-Poly1305)
   - `ALTCHAKEY` — chiave HMAC utilizzata dal widget CAPTCHA ALTCHA (hex a 64 caratteri)

2. **Chiede in modo interattivo** i parametri del database applicativo:
   - `APP_DB_NAME` — nome del database (es. `mysymfql`)
   - `APP_DB_HOSTNAME` — host del database (es. `127.0.0.1`)
   - `APP_DB_PORT` — porta del database (default: `3306`)
   - `APP_DB_USER` — utente del database (es. `mysymfql`)
   - `APP_DB_PASSWORD` — password del database (input nascosto)
   - `BACKUP_PATH` — percorso assoluto dove verranno salvati i file di backup delle tabelle (es. `/var/backups/mysymfql`)
   - `BACKUP_COMPRESSION` non viene chiesto: `zstd` di default (da `.env`), si cambia in `.env.local` con `gzip` o `none`

3. **Chiede il DSN del mailer:**
   - `MAILER_DSN` — DSN di Symfony Mailer per l'invio di email (reimpostazione password, ecc.). Premi Invio per mantenere il default `null://null` (disabilita l'invio di email).

Tutti i valori vengono scritti in `.env.local` (mai committato su git). Al termine il comando stampa:

```
 ! [NOTE] Riesegui il comando per completare il setup: php bin/console app:setup
```

### 3. Esegui il comando di setup — seconda esecuzione (schema database + utente admin)

```bash
php bin/console app:setup
```

Con `.env.local` ora presente, il comando:

1. Applica le migrazioni Doctrine versionate (`doctrine:migrations:migrate`) per creare lo schema applicativo.
2. Richiede in modo interattivo i dati del **primo account amministratore**:
   - `Username`
   - `Email`
   - `Password` (input nascosto)

> Se il setup è già stato eseguito (ovvero la tabella `app_user` esiste e contiene almeno un record), il comando termina senza apportare modifiche.

### 4. Configura il web server

Imposta la document root del tuo web server sulla directory `public/`. Esempio per Apache:

```apacheconf
<VirtualHost *:80>
    DocumentRoot /path/to/mysymfql_manager/public
    <Directory /path/to/mysymfql_manager/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Per Nginx, usa `try_files $uri /index.php$is_args$args;` all'interno del blocco location.

---

## Aggiornare un'installazione esistente

Dopo ogni `git pull` e `composer install --no-dev --optimize-autoloader` eseguire sempre:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile          # asset front-end -> public/assets (AssetMapper)
php bin/console cache:clear --env=prod
```

`composer install` esegue anche `importmap:install`, che scarica in `assets/vendor/` le librerie front-end elencate in
`importmap.php` (dal registry jsDelivr, solo al deploy: il browser non contatta mai una CDN).

### Passaggio una tantum: migrazioni versionate

Fino a novembre 2026 `app:setup` **generava** le migrazioni sul server (`doctrine:migrations:diff`), quindi non erano mai
versionate. Il repository ora contiene una **migrazione baseline** (`Version20261128085846`) che crea lo stesso schema.
Su un'installazione precedente le tabelle esistono già: marcare la baseline come eseguita **invece di** eseguirla
(nessun SQL sulle tabelle applicative, cambia solo la tabella di servizio `doctrine_migration_versions`):

```bash
# 1. Lo schema deve corrispondere alle entità (attesi due [OK])
php bin/console doctrine:schema:validate
# 2. Dimenticare le vecchie versioni generate localmente e registrare la baseline
php bin/console doctrine:migrations:version --delete --all --no-interaction
php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20261128085846' --add --no-interaction
# 3. Verifica: "New" deve essere 0 e migrate deve rispondere "Already at the latest version"
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --no-interaction
```

Se il punto 1 segnala uno schema non allineato, fermarsi e sistemarlo prima (non marcare la baseline).
Eventuali vecchi file di migrazione generati da `app:setup` in `migrations/` si possono cancellare.

### Passaggio una tantum: backup legacy

I backup creati prima di questa versione sono visibili solo agli amministratori. Assegnarli al loro server con
`app:backup-relocate <server> [--force]` (vedi la sezione sui comandi CLI) perché gli owner dei database li rivedano.

## Eseguire i test

La suite usa un database applicativo **dedicato** il cui nome deve finire con `_test` (default `mysymfql_test`, vedi
`.env.test`); viene svuotato e ricreato dalle migrazioni a ogni esecuzione. Le password vanno in `.env.test.local`
(ignorato da git):

```dotenv
APP_DB_PASSWORD=...        # password dell'utente MySQL mysymfql_test
TEST_SERVER_PASSWORD=...   # password dell'account che gestisce il MySQL locale (TEST_SERVER_USER)
```

```bash
composer test:unit          # non richiede MySQL / Redis
composer test:integration   # server MySQL reale
composer test:functional    # test HTTP (MySQL + Redis)
composer test:browser       # smoke test di tutte le pagine con Chrome headless (Node >= 22, Chrome)
composer test               # tutto
composer quality-check      # Rector, PHP-CS-Fixer, PHPStan (come in CI)
```

Gli stessi controlli girano su GitHub Actions (`.github/workflows/ci.yml`) con PHP 8.5.

## Aggiungere un server MySQL gestito — creazione di un account manager remoto

mysymfql_manager si connette ai server MySQL che registri nella sua interfaccia usando le credenziali fornite nella configurazione SqlClient. L'account MySQL utilizzato deve avere privilegi sufficienti per elencare i database, gestire gli utenti, eseguire `SHOW PROCESSLIST`, eseguire `KILL`, e così via.

L'esempio seguente utilizza:
- **Server MySQL:** `192.168.0.1`
- **Web server (che esegue questa app):** `192.168.0.2`

### 1. Connettiti al server MySQL come root

Dal server MySQL o da qualsiasi host che possa raggiungerlo:

```bash
mysql -h 192.168.0.1 -u root -p
```

### 2. Crea l'utente manager

L'utente deve essere creato con l'IP del web server come host, in modo che MySQL accetti le connessioni da esso:

```sql
CREATE USER 'manager'@'192.168.0.2' IDENTIFIED BY 'StrongPassword123!';
```

> Sostituisci `StrongPassword123!` con una password forte, generata casualmente.

### 3. Assegna i privilegi necessari

Per consentire la gestione completa di tutti i database su quel server:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'manager'@'192.168.0.2' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

`WITH GRANT OPTION` è necessario se si desidera che l'applicazione possa creare utenti MySQL e assegnare GRANT per loro conto.

Se preferisci un account più ristretto che copra comunque tutte le funzionalità dell'applicazione:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW,
      SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER,
      RELOAD, PROCESS, REFERENCES, SHOW DATABASES, SUPER
ON *.* TO 'manager'@'192.168.0.2' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

### 4. Verifica la connettività dal web server

Dal web server (`192.168.0.2`), verifica che l'account funzioni:

```bash
mysql -h 192.168.0.1 -u manager -p
```

Se la connessione viene rifiutata, controlla:
- `bind-address` di MySQL in `/etc/mysql/mysql.conf.d/mysqld.cnf` — non deve essere `127.0.0.1`; impostalo su `0.0.0.0` o sull'IP dell'interfaccia specifica e riavvia MySQL.
- Le regole del firewall su `192.168.0.1` — la porta `3306` deve essere aperta per le connessioni da `192.168.0.2`.

```bash
# Su 192.168.0.1 — consenti la porta 3306 dal web server (esempio ufw)
ufw allow from 192.168.0.2 to any port 3306
```

### 5. Registra il server in mysymfql_manager

Accedi all'applicazione come admin, vai su **Server MySQL → Aggiungi**, e compila:

| Campo | Valore |
|---|---|
| Host | `192.168.0.1` |
| Porta | `3306` |
| Nome utente | `manager` |
| Password | `StrongPassword123!` |

Poi assegna il server agli utenti applicativi che devono poterlo gestire.

---

## Comandi CLI

### `app:backup-all` — Backup completo di tutti i database su un server

Esegue il dump di ogni database trovato su un server MySQL registrato usando `mysqldump`. Ogni database viene salvato come file `.sql` separato nella directory configurata tramite la variabile d'ambiente `BACKUP_PATH`.

**Utilizzo**

```bash
php bin/console app:backup-all <host>
```

**Argomenti**

| Argomento | Obbligatorio | Descrizione |
|---|---|---|
| `host` | sì | Hostname del server MySQL come registrato nell'applicazione (es. `192.168.1.10`) |

Il valore dell'host deve corrispondere esattamente al campo `host` di un record SqlClient esistente. Se non viene trovato nessun server corrispondente, il comando termina con un errore.

**Cosa fa**

1. Cerca il record SqlClient per l'host indicato.
2. Recupera la lista di tutti i database su quel server.
3. Per ogni database, esegue `mysqldump` con i seguenti flag:
   - `--single-transaction` — snapshot consistente senza bloccare le tabelle (InnoDB)
   - `--set-gtid-purged=OFF` — evita errori relativi ai GTID durante il ripristino su una replica
   - `--routines` — include stored procedure e funzioni
   - `--events` — include gli eventi pianificati
   - `--triggers` — include i trigger
4. Salva ogni dump in `$BACKUP_PATH/{id server}/{dbname}/bkp_YYYY-MM-DD_H-i-s_{dbname}_full.sql.zst` (compressione: vedi `BACKUP_COMPRESSION`).
5. Le credenziali vengono passate tramite un file `.cnf` temporaneo (`--defaults-extra-file`) e non vengono mai esposte nella lista dei processi.

**Formato dell'output**

```
Backup di 4 database su 192.168.1.10
  myapp ... OK (bkp_2026-04-04_02-00-00_myapp_full.sql)
  myapp2 ... OK (bkp_2026-04-04_02-00-01_myapp2_full.sql)
  legacy ... FALLITO
  stats ... OK (bkp_2026-04-04_02-00-03_stats_full.sql)

 [WARNING] 3 backup completati, 1 falliti.
```

**Codici di uscita**

| Codice | Significato |
|---|---|
| `0` | Tutti i backup completati con successo |
| `1` | Uno o più backup falliti |

**Pianificazione con cron**

Per eseguire un backup completo notturno alle 02:00:

```cron
0 2 * * * /usr/bin/php /path/to/mysymfql_manager/bin/console app:backup-all 192.168.1.10 >> /var/log/mysymfql_backup.log 2>&1
```

---

### `app:backup-single` — Backup di un singolo database su un server

Esegue il dump di un database specifico trovato su un server MySQL registrato usando `mysqldump`. Il database viene salvato come file `.sql` nella directory configurata tramite la variabile d'ambiente `BACKUP_PATH`.

**Utilizzo**

```bash
php bin/console app:backup-single <host> <db_name>
```

**Argomenti**

| Argomento | Obbligatorio | Descrizione |
|---|---|---|
| `host` | sì | Hostname del server MySQL come registrato nell'applicazione (es. `localhost`) |
| `db_name` | sì | Nome del database di cui eseguire il backup (es. `test1`) |

Il valore dell'host deve corrispondere esattamente al campo `host` di un record SqlClient esistente. Se non viene trovato nessun server corrispondente, il comando termina con un errore.

**Esempio**

```bash
php bin/console app:backup-single localhost test1
```

**Cosa fa**

1. Cerca il record SqlClient per l'host indicato.
2. Recupera il database corrispondente al nome indicato.
3. Esegue `mysqldump` con i seguenti flag:
   - `--single-transaction` — snapshot consistente senza bloccare le tabelle (InnoDB)
   - `--set-gtid-purged=OFF` — evita errori relativi ai GTID durante il ripristino su una replica
   - `--routines` — include stored procedure e funzioni
   - `--events` — include gli eventi pianificati
   - `--triggers` — include i trigger
4. Salva il dump in `$BACKUP_PATH/{id server}/{dbname}/bkp_YYYY-MM-DD_H-i-s_{dbname}_full.sql.zst` (compressione: vedi `BACKUP_COMPRESSION`).
5. Le credenziali vengono passate tramite un file `.cnf` temporaneo (`--defaults-extra-file`) e non vengono mai esposte nella lista dei processi.

**Formato dell'output**

```
Backup di 1 database su localhost
  test1 ... OK (bkp_2026-04-05_02-00-00_test1_full.sql)

 [OK] Tutti i 1 backup completati con successo.
```

**Codici di uscita**

| Codice | Significato |
|---|---|
| `0` | Backup completato con successo |
| `1` | Backup fallito |

**Pianificazione con cron**

Per eseguire un backup notturno di un database specifico alle 03:00:

```cron
0 3 * * * /usr/bin/php /path/to/mysymfql_manager/bin/console app:backup-single localhost test1 >> /var/log/mysymfql_backup.log 2>&1
```

---

### `app:check-server-grants` — Verifica i grant degli account dei server

La query console esegue SQL con l'account MySQL del server: per gli utenti non admin i suoi grant sono l'unica
barriera verso i database non assegnati. Il comando (e la pagina **Verifica grant**, `/admin/server-grants`, solo admin)
legge i grant di ogni account con `SHOW GRANTS` (nessun privilegio richiesto) e li confronta con i database assegnati
nel pannello.

```bash
php bin/console app:check-server-grants [<nome>]
```

| Gravità | Quando |
|---|---|
| **danger** | privilegi globali (`ON *.*`), ruoli, `PROXY`, grant con wildcard (`` `app_%`.* ``), grant su database non assegnati a nessun utente del server, account condiviso da utenti non admin con database diversi, server non raggiungibile |
| warning | `WITH GRANT OPTION`, righe di grant non riconosciute |
| info | server usato solo da admin (privilegi ampi attesi) |

Codice d'uscita `1` quando un server ha un rilievo *danger*: si può lanciare da cron o da un sistema di monitoraggio.

### `app:backup-relocate` — Assegna i backup legacy a un server

I backup creati prima di novembre 2026 sono stati scritti direttamente in `BACKUP_PATH`
(`bkp_<data>_<db>_<tabella|full>.sql`) e non sono attribuibili a un server, quindi li vedono solo gli amministratori.
I nuovi backup sono salvati in `BACKUP_PATH/<id server>/<database>/` e li vedono gli owner di quel database. Questo
comando sposta i file legacy di un server nella nuova struttura.

```bash
php bin/console app:backup-relocate <name> [--db=<database>]... [--force]
```

| Argomento / opzione | Descrizione |
|---|---|
| `name` | Nome del `SqlClient` da cui provengono i dump legacy |
| `--db` | Nome di database aggiuntivo da considerare (ripetibile), es. un database eliminato dopo il dump |
| `--force` | Sposta davvero i file; senza, il comando mostra solo il piano |

Ogni nome file viene confrontato con i database esistenti sul server (più `--db`). I nomi compatibili con più database
(`app_old_users` = `app` + `old_users` oppure `app_old` + `users`) vengono risolti controllando quale tabella esiste;
i file che restano senza corrispondenza o ambigui vengono lasciati al loro posto e segnalati. I file esistenti non
vengono mai sovrascritti.

```bash
php bin/console app:backup-relocate Produzione            # controllare il piano
php bin/console app:backup-relocate Produzione --force    # spostare i file
```

### `app:backup-rotate` — Rotazione dei backup

Elimina i dump più vecchi di un certo numero di giorni. Pensato per il cron, dopo i backup. Legge solo `BACKUP_PATH`
(nessuna connessione a MySQL o al catalogo), quindi è leggero anche con migliaia di file.

```bash
php bin/console app:backup-rotate <giorni> [--keep=<n>] [--force]
```

| Argomento / opzione | Descrizione |
|---|---|
| `days` | Periodo di conservazione: si eliminano i dump con data di modifica più vecchia |
| `--keep` | Dump più recenti di ogni serie conservati sempre, qualunque sia l'età (default `1`, `0` = nessuno) |
| `--force` | Elimina davvero i file; senza mostra solo il piano |

Una *serie* è un tipo di dump di un database su un server (dump completi di `shop` sul server 3, dump della sua tabella
`orders`…): con il default `--keep=1` un database i cui backup si sono fermati conserva l'ultimo dump. Ruotano anche i
dump legacy, raggruppati per nome file. Vengono eliminati anche i file incompleti `*.sql.part` (lasciati da un
`mysqldump` interrotto) più vecchi di `giorni`. Un file non eliminabile viene segnalato e loggato, e il codice d'uscita è `1`.

```cron
30 3 * * * /usr/bin/php /path/to/mysymfql_manager/bin/console app:backup-rotate 30 --force --no-interaction >> /var/log/mysymfql_backup.log 2>&1
```

### `app:audit-prune` — Rotazione dell'audit log

Elimina le voci dell'audit log più vecchie di `--days` (default `90`, che è anche il massimo: l'audit log conserva al
massimo tre mesi di storico). Senza `--force` mostra solo quante voci eliminerebbe.

```bash
php bin/console app:audit-prune [--days=<1-90>] [--force]
```

```cron
45 3 * * * /usr/bin/php /path/to/mysymfql_manager/bin/console app:audit-prune --force --no-interaction >> /var/log/mysymfql_audit.log 2>&1
```

### `app:setup-sqlclient` — Registra un server MySQL (SqlClient)

Crea e persiste un nuovo record SqlClient nel database applicativo. La password viene cifrata automaticamente a riposo usando XSalsa20-Poly1305 (tramite la `SQLCLIENT_ENCRYPTION_KEY` configurata in `.env.local`).

**Utilizzo**

```bash
php bin/console app:setup-sqlclient <name> <host> <username> <password> [--port=<port>]
```

**Argomenti**

| Argomento | Obbligatorio | Descrizione |
|---|---|---|
| `name` | sì | Nome visualizzato univoco per il server (es. `Produzione`) |
| `host` | sì | Hostname o IP del server MySQL (es. `192.168.1.10`) |
| `username` | sì | Nome utente MySQL (es. `manager`) |
| `password` | sì | Password MySQL |

**Opzioni**

| Opzione | Default | Descrizione |
|---|---|---|
| `--port` / `-p` | `3306` | Porta MySQL |

Il comando termina con un errore se esiste già un SqlClient con lo stesso `host` o lo stesso `name`.

**Esempi**

```bash
# Porta predefinita 3306
php bin/console app:setup-sqlclient Produzione 192.168.1.10 manager StrongPassword123!

# Porta personalizzata
php bin/console app:setup-sqlclient Staging 192.168.1.20 manager StrongPassword123! --port=3307
```

**Formato dell'output**

```
 [OK] Server MySQL registrato con successo: [Produzione] manager@192.168.1.10:3306 (id: 1)
```

**Codici di uscita**

| Codice | Significato |
|---|---|
| `0` | SqlClient creato e persistito con successo |
| `1` | Esiste già un server con lo stesso host o nome |

---
