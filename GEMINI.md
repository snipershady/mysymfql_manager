# MyApp — Database Manager

## Stack
- PHP >=8.4 + >=Symfony 8.0
- Doctrine/orm ^3.6 + MySQL >=8.4.8
- Twig ^3.2

## Dipendenze CDN front-end
- Bootstrap 5.3.8 css: <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.8/css/bootstrap.min.css" integrity="sha512-2bBQCjcnw658Lho4nlXJcc6WkV/UxpE/sAokbXPxQNGqmNdQrWqtw26Ns9kFF/yG792pKR1Sx8/Y1Lf1XN4GKA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
- Bootstrap 5.3.8 js: <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.8/js/bootstrap.min.js" integrity="sha512-nKXmKvJyiGQy343jatQlzDprflyB5c+tKCzGP3Uq67v+lmzfnZUi/ZT+fc6ITZfSC5HhaBKUIvr/nTLCV+7F+Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
- Font-Awesome 7.0.1 css: <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
- jQuery 3.7.1 js: <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
- DataTables css: <link href="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-2.3.7/b-3.2.6/b-html5-3.2.6/b-print-3.2.6/date-1.6.3/datatables.min.css" rel="stylesheet" integrity="sha384-jcpzz7iGy2rMtOqJYU0p/DWvuHgKci3J0eklqA3XvR80yoYDduwwfiVfdXyqXNrS" crossorigin="anonymous">
- DataTables js: 
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js" integrity="sha384-VFQrHzqBh5qiJIU0uGU5CIW3+OWpdGGJM9LBnGbuIH2mkICcFZ7lPd/AAtI7SNf7" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js" integrity="sha384-/RlQG9uf0M2vcTw3CX7fbqgbj/h8wKxw7C3zu9/GxcBPRKOEcESxaxufwRXqzq6n" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-2.3.7/b-3.2.6/b-html5-3.2.6/b-print-3.2.6/date-1.6.3/datatables.min.js" integrity="sha384-XoQCVVIYV2853g5L/gmdyvIXhpYiaZFoJVT2oyHuyLWas0ZPhqRD0vIT5N3/8rEc" crossorigin="anonymous"></script>


## Obiettivo
Applicativo web per gestire MySQL, con MFA obbligatorio,

## Stato attuale
- [x] Setup progetto Composer (composer.json con tutte le dipendenze)
- [x] Setup doctrine config con main db connection
- [ ] Creazione template TWIG con interfaccia stile pannello amministrativo basato su bootstrap 5.3.8 da CDN https://cdnjs.com/ 
- [ ] Integrazione Font Awesome da CDN da https://cdnjs.com/
- [ ] Integrazione Datatables da CDN
- [x] Implementaizione autenticazione
- [x] Implementaizione registrazione
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
