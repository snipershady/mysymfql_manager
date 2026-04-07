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

### Gestione utenti MySQL
- Elenco degli utenti MySQL su un server con flag di grant per database
- Creazione utente MySQL con grant su database
- Eliminazione utente MySQL
- Cambio password utente MySQL

### Monitoraggio del server
- Lista dei processi con terminazione del processo tramite ID
- Stato del motore InnoDB

---

## Requisiti

- PHP >= 8.4
- MySQL >= 8.4.8 (database applicativo)
- Redis/Valkey (sessione/cache)
- Binario `mysqldump` disponibile nel PATH
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

1. Genera e applica la migrazione Doctrine (`doctrine:migrations:diff` + `doctrine:migrations:migrate`) per creare lo schema applicativo.
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
4. Salva ogni dump in `$BACKUP_PATH/bkp_YYYY-MM-DD_H-i-s_{dbname}_full.sql`.
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
4. Salva il dump in `$BACKUP_PATH/bkp_YYYY-MM-DD_H-i-s_{dbname}_full.sql`.
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
