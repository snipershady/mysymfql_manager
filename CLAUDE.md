# MyApp — Database Manager

## Stack
- PHP 8.4 + Symfony 8.0
- Doctrine/orm ^3.6 + MySQL
- Twig ^3.2

## Obiettivo
Applicativo web per gestire MySQL, con MFA obbligatorio,

## Stato attuale
- [x] Setup progetto Composer (composer.json con tutte le dipendenze)
- [ ] Creazione template TWIG con interfaccia stile pannello amministrativo basato su bootstrap 5.3.8 da CDN https://cdnjs.com/ 
- [ ] Integrazione Font Awesome da CDN da https://cdnjs.com/
- [ ] Integrazione Datatables da CDN
- [ ] Implementaizione autenticazione
- [ ] Implementazione reset password
- [ ] Implementazione change password
- [ ] Implementazione 2FA con Google Authenticator
- [ ] Implementazione pagina elenco db assegnati all'utente autenticato
- [ ] Pagina elenco database
- [ ] Pagina dettaglio database con elenco tabelle
- [ ] Feature backup database
- [ ] Feature ripristino database su qualsiasi db
- [ ] Feature esportazione singola tabella (struttura+dati) da un db
- [ ] Feature importazione singola tabella da un dump di una tabella
- [ ] Feature create db + create user
- [ ] Feature grant, assegnazione permessi utente per i db
- [ ] Feature Audit Log
- [ ] Feature elenca process list
- [ ] Feature Termina processi bloccati
