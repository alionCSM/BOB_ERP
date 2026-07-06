# BOB ERP — Installazione & Deploy

Guida per installare BOB su un server Linux (Ubuntu 22.04 LTS di riferimento) e per
aggiornare un'installazione esistente. Tutti i pacchetti e le variabili qui sotto
sono presi dal codice reale (non generici).

> Workflow di rilascio: si lavora su `dev`, si fa `merge` su `main`, si tagga
> (`vX.Y.Z`) e in produzione si fa checkout del tag. La versione mostrata in app è
> letta automaticamente dal tag git (`includes/version.php`).

---

## 1. Stack

| Componente | Versione / Note |
|---|---|
| PHP | **8.1+** (FPM) — usa readonly props, named args, enum |
| Web server | Nginx (o Apache) con document root su `public/` |
| Database principale | **MySQL 8** / MariaDB 10.6+ (`utf8mb4`) |
| Database secondario | **SQL Server** (integrazione "Yard") via driver `sqlsrv` |
| Storage file | **NFS** montato, esposto via `CLOUD_ROOT` |
| PDF | dompdf (in `composer`) |
| Excel | PhpSpreadsheet (in `composer`) |
| DWG → SVG | python3 + `ezdxf` + `dwg2dxf` (LibreDWG) — opzionale, solo BOB Zone/Disegni |
| AI (opzionale) | endpoint Ollama/OpenAI-compatibile |
| Editor documenti (opz.) | OnlyOffice Document Server |

---

## 2. Pacchetti di sistema

```bash
sudo apt update
sudo apt install -y \
  nginx \
  php8.1-fpm php8.1-cli \
  php8.1-mysql \
  php8.1-mbstring php8.1-xml php8.1-curl php8.1-gd php8.1-zip \
  php8.1-intl php8.1-bcmath php8.1-exif \
  unixodbc unixodbc-dev \
  nfs-common \
  git unzip
```

Estensioni PHP che il codice usa direttamente:
- `pdo_mysql` → DB principale (`src/Infrastructure/Database.php`)
- `pdo_sqlsrv` + `sqlsrv` → integrazione SQL Server (`src/Infrastructure/SqlServerConnection.php`) — **vedi §3**
- `mbstring`, `xml`/`dom`, `zip`, `gd` → PhpSpreadsheet + dompdf
- `curl` → Fieldwire / Ollama / mailer
- `gd`/`exif` → foto BOB Zone
- `openssl` (incluso) → JWT (`firebase/php-jwt`), TLS mail

### Composer
```bash
cd /tmp
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 3. Driver SQL Server per PHP (`pdo_sqlsrv` + `sqlsrv`)

Serve il driver Microsoft ODBC + le estensioni PECL. Su Ubuntu 22.04:

```bash
# Repo Microsoft
curl https://packages.microsoft.com/keys/microsoft.asc | sudo tee /etc/apt/trusted.gpg.d/microsoft.asc
curl https://packages.microsoft.com/config/ubuntu/22.04/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt update

# ODBC Driver 18
sudo ACCEPT_EULA=Y apt install -y msodbcsql18

# Estensioni PHP via PECL
sudo apt install -y php8.1-dev php-pear
sudo pecl install sqlsrv pdo_sqlsrv

# Abilita le estensioni
echo "extension=sqlsrv.so"     | sudo tee /etc/php/8.1/mods-available/sqlsrv.ini
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.1/mods-available/pdo_sqlsrv.ini
sudo phpenmod sqlsrv pdo_sqlsrv
sudo systemctl restart php8.1-fpm

# Verifica
php -m | grep -i sqlsrv     # deve elencare: pdo_sqlsrv, sqlsrv
```

> La connessione usa DSN `sqlsrv:Server=host,porta;Database=...;Encrypt=...;TrustServerCertificate=...`.
> Se il certificato SQL Server è self-signed, tieni `SQLSRV_TRUST_CERT=true`.

---

## 4. Toolchain DWG (opzionale — BOB Zone / Disegni)

Serve solo per convertire e annotare i `.dwg`. Senza, tutto il resto di BOB Zone
(task, foto, file, moduli, report PDF) funziona ugualmente.

```bash
# ezdxf — a livello di SISTEMA (il web server gira come www-data, non come te)
sudo apt install -y python3-pip
sudo pip3 install ezdxf            # se bloccato: aggiungi --break-system-packages
sudo python3 -c "import ezdxf; print(ezdxf.__version__)"

# LibreDWG da sorgente (il pacchetto apt non esiste su 22.04) → fornisce dwg2dxf
sudo apt install -y build-essential libtool autoconf automake pkg-config
cd /tmp && git clone --depth 1 https://github.com/LibreDWG/libredwg.git && cd libredwg
sh autogen.sh
./configure --enable-release --disable-bindings
make -j"$(nproc)"
sudo make install
sudo ldconfig
dwg2dxf --version
```

> Gli errori `makeinfo`/`*.info` durante `make` sono solo la documentazione: **ignorali**,
> i binari si installano comunque in `/usr/local/bin`.
> Lo script `scripts/dwg/dwg_to_svg.py` trova da solo `dwg2dxf` nei path noti e forza
> `LD_LIBRARY_PATH=/usr/local/lib`. Override opzionale: env `BOB_DWG2DXF=/usr/local/bin/dwg2dxf`.

---

## 5. Storage NFS (`CLOUD_ROOT`)

BOB salva allegati/foto/file/moduli/disegni su uno storage condiviso via NFS, non sul
filesystem locale dell'app. Il percorso radice è la variabile **`CLOUD_ROOT`**.

Montaggio (esempio — adatta host/percorso del NAS):
```bash
sudo mkdir -p /mnt/bob_prod
echo "NAS_HOST:/export/bob_prod  /mnt/bob_prod  nfs  rw,hard,intr,_netdev  0  0" | sudo tee -a /etc/fstab
sudo mount -a
```

Poi in `.env`: `CLOUD_ROOT=/mnt/bob_prod`

Struttura che BOB crea sotto `CLOUD_ROOT` (da `src/Support/CloudPath.php`):
```
<CLOUD_ROOT>/
├── offers/                                     # preventivi
├── Worksites/<Cliente>/<Anno>/<Codice - Nome>/
│   └── Disegni/<categoria>/                     # disegni cantiere
└── BOBZone/<worksiteId>/
    ├── photos/                                  # foto task BOB Zone
    ├── files/                                   # repository file BOB Zone
    └── forms/                                   # firme/foto dei moduli compilati
```

**Permessi:** l'utente del web server (`www-data`) deve avere **scrittura** su queste
cartelle. BOB crea le sottocartelle con `mkdir 0775`, ma la radice montata deve essere
scrivibile da `www-data` (lato NFS, mappa correttamente UID/GID o usa `no_root_squash`/
opzioni concordate col NAS). Se manca la scrittura, l'app lancia errori espliciti del tipo
"Cartella ... non scrivibile".

---

## 6. Codice + dipendenze PHP

```bash
sudo mkdir -p /var/www/bob.csmontaggi.it
sudo chown -R $USER:www-data /var/www/bob.csmontaggi.it
cd /var/www/bob.csmontaggi.it
git clone https://github.com/alionCSM/BOB_ERP.git .   # oppure copia il repo
git fetch --tags
git checkout v3.0.0                                    # ultimo tag di rilascio

composer install --no-dev --optimize-autoloader
```

---

## 7. File `.env`

⚠️ **Posizione:** il `.env` NON sta nella root del repo, ma **un livello sopra** (vedi
`includes/bootstrap.php` → `dirname(APP_ROOT)`). Se il repo è in
`/var/www/bob.csmontaggi.it`, il `.env` va in `/var/www/.env`.

`Config::validate()` fa fallire l'avvio se mancano le variabili **obbligatorie**.

```dotenv
# ── App (OBBLIGATORIE) ──────────────────────────────────────────
APP_ENV=production
APP_URL=https://bob.csmontaggi.it
UPLOAD_MAX_MB=50

# ── MySQL (OBBLIGATORIE) ────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bob_prod
DB_USER=bb_2026_usr
DB_PASS=********

# ── SQL Server / Yard ───────────────────────────────────────────
SQLSRV_HOST=10.0.0.x
SQLSRV_PORT=1433
SQLSRV_DB=Yard
SQLSRV_USER=********
SQLSRV_PASS=********
SQLSRV_ENCRYPT=true
SQLSRV_TRUST_CERT=true

# ── Storage NFS (OBBLIGATORIA per allegati/BOB Zone) ────────────
CLOUD_ROOT=/mnt/bob_prod

# ── Mail SMTP (OBBLIGATORIE: HOST/USER/PASS) ────────────────────
MAIL_HOST=smtp.example.com
MAIL_USER=********
MAIL_PASS=********
MAIL_PORT=587
MAIL_ENCRYPTION=tls
# Mittenti per canale (system|alerts|hr|billing|security)
MAIL_SYSTEM_FROM=noreply@csmontaggi.it
MAIL_SYSTEM_NAME=BOB
MAIL_ALERTS_FROM=alert@csmontaggi.it
MAIL_ALERTS_NAME=BOB Alert
# ... ripeti per HR / BILLING / SECURITY i campi _FROM e _NAME usati

# ── Fieldwire (opzionale: senza, il sync è disattivato) ─────────
FIELDWIRE_API_TOKEN=
FIELDWIRE_REGION=eu

# ── AI / Ollama (opzionale) ─────────────────────────────────────
OLLAMA_URL=http://192.168.1.10:8000/v1/chat/completions
MODEL=
DOC_CHECK_URL=
DOC_CHECK_MODEL=

# ── OnlyOffice (opzionale) ──────────────────────────────────────
ONLYOFFICE_SERVER_URL=
ONLYOFFICE_JWT_SECRET=

# ── Push notifications (opzionale) ──────────────────────────────
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@csmontaggi.it

# ── Varie ───────────────────────────────────────────────────────
ATTESTATO_URL=https://docs.csmontaggi.it
```

Variabili **obbligatorie** (l'app non parte senza): `APP_ENV`, `APP_URL`, `DB_HOST`,
`DB_NAME`, `DB_USER`, `DB_PASS`, `MAIL_HOST`, `MAIL_USER`, `MAIL_PASS`.
`CLOUD_ROOT` è richiesta a runtime appena si usa un allegato/BOB Zone.

---

## 8. Tuning `php.ini` (php-fpm)

In `/etc/php/8.1/fpm/php.ini` allinea i limiti upload a `UPLOAD_MAX_MB` e dai memoria
sufficiente a dompdf/PhpSpreadsheet:

```ini
upload_max_filesize = 60M     ; >= UPLOAD_MAX_MB
post_max_size       = 65M     ; >= upload_max_filesize
memory_limit        = 512M
max_execution_time  = 120
```
```bash
sudo systemctl restart php8.1-fpm
```

---

## 9. Web server (Nginx)

Document root = **`public/`** (front controller `public/index.php`). C'è anche un
`portal/` separato per gli accessi esterni.

```nginx
server {
    listen 443 ssl http2;
    server_name bob.csmontaggi.it;

    root /var/www/bob.csmontaggi.it/public;
    index index.php;

    client_max_body_size 65M;     # >= post_max_size

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    # ssl_certificate / ssl_certificate_key ...
}
```
```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 10. Database — migrazioni (Phinx)

Phinx legge le credenziali dallo stesso `.env` (`phinx.php`). L'ambiente è `production`.

```bash
cd /var/www/bob.csmontaggi.it
vendor/bin/phinx status -e production     # cosa è pending
vendor/bin/phinx migrate -e production    # applica le mancanti (idempotente)
```

> Nota privilegi DB: l'utente `bb_2026_usr` in produzione **non ha il privilegio
> REFERENCES**, quindi le migration non creano FOREIGN KEY (usano indici). Non serve
> alcun grant extra per migrare.

---

## 11. Permessi cartelle locali

Oltre all'NFS, l'app scrive in `storage/` (cache Twig, cache versione, log):
```bash
cd /var/www/bob.csmontaggi.it
sudo chown -R www-data:www-data storage
sudo find storage -type d -exec chmod 775 {} \;
```

---

## 12. Cron (opzionale, funzioni schedulate)

Esempi presenti in `includes/cron/` e `includes/services/`:
```cron
# Verifica AI anomalie carburante / documenti (richiede OLLAMA_URL + MAIL_*)
*/30 * * * *  www-data  php /var/www/bob.csmontaggi.it/includes/cron/ai_anomaly_check.php
0    6 * * *  www-data  php /var/www/bob.csmontaggi.it/includes/cron/ai_document_verifier.php
# Ricalcolo statistiche cantieri
*/15 * * * *  www-data  php /var/www/bob.csmontaggi.it/includes/services/recalculate_worksite_stats.php
# Alert noleggi mezzi: presenze fuori dal calendario di conteggio (email a chi ha il permesso equipment_alerts)
0    7 * * *  www-data  php /var/www/bob.csmontaggi.it/includes/cron/lifting_calendar_check.php
```

---

## 13. Aggiornare un'installazione esistente (deploy nuova versione)

```bash
cd /var/www/bob.csmontaggi.it
git fetch origin --tags
git checkout vX.Y.Z                                # nuovo tag di rilascio
composer install --no-dev --optimize-autoloader
vendor/bin/phinx migrate -e production
rm -f storage/cache/version.txt                    # forza refresh della versione mostrata
sudo systemctl reload php8.1-fpm
```

Se la nuova versione introduce DWG/BOB Zone per la prima volta su questo server,
esegui anche §4 (ezdxf + LibreDWG) e verifica che `CLOUD_ROOT` (NFS) sia montato.

---

## 14. Checklist di verifica post-deploy

- [ ] `php -m | grep -E 'pdo_mysql|pdo_sqlsrv|sqlsrv|gd|zip|mbstring|curl'`
- [ ] `mount | grep bob_prod` → NFS montato e scrivibile da `www-data`
- [ ] `vendor/bin/phinx status -e production` → nessuna migration pending
- [ ] Login funzionante (sessione `BOB_SESSID`, cookie `Secure` → richiede HTTPS)
- [ ] Upload di un allegato → finisce sotto `CLOUD_ROOT`
- [ ] (se DWG) `python3 scripts/dwg/dwg_to_svg.py <file.dwg> /tmp/out` → `{"ok":true,...}`
- [ ] Versione mostrata in app = ultimo tag git
```
