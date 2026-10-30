# Prove della BOB Zone

Quattro prove che esercitano la Zone come la usano i tre tipi di persone che
ci stanno dentro: **ufficio**, **capo/operaio**, **cliente**.

```bash
php tests/zone/esegui.php
```

## Cosa serve

Un MySQL (o MariaDB) vuoto dove scrivere, **separato da quello di lavoro**.
Quello di XAMPP va benissimo, su una porta sua e con un datadir suo:

```bash
mysql_install_db --datadir=/tmp/bobtest/data
mysqld --datadir=/tmp/bobtest/data --port=3307 --skip-grant-tables
mysql -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE bob_test"
```

La connessione sta in `schema.php` (`DSN`). Il database viene **svuotato e
ricostruito a ogni prova**: non puntarlo mai su BOB vero. C'e' una sicura —
il nome del database deve contenere `test`, se no le prove si rifiutano di
partire — ma e' l'ultima difesa, non la prima.

**Non si lanciano sul server di produzione.** Sono uno strumento da
sviluppo: vogliono un MySQL da buttare, e sul server non c'e'.

## Com'e' fatto

Lo schema non e' scritto a mano: `miniphinx.php` e' un Phinx ridotto
all'osso che fa girare **le migrazioni vere** di `db/migrations`. Cosi' le
tabelle della Zone nascono dalle stesse istruzioni che girano in produzione,
e se domani una migrazione cambia, le prove la seguono. Le tabelle vecchie
(`bb_users`, `bb_worksites`, ...) non hanno migrazioni — sono nate col
gestionale — e per quelle `schema.php` tiene le colonne che la Zone tocca.

`zona.php` semina un cantiere e sette persone:

| chi | ruolo nel cantiere | modulo Zone |
|---|---|---|
| super | ufficio (sempre, ovunque) | si |
| Anna | ufficio | si |
| Luca | **nessuno** | si |
| Marco | capo squadra | no |
| Ilir | operaio | no |
| Geom. Neri | cliente | no |
| Tizio | (sta su un altro cantiere) | no |

Luca e' il caso che conta: **ha il modulo ma non e' nell'elenco**. Il modulo
dice chi puo' dare gli accessi, non chi li ha.

## Le quattro prove

1. **`t1_accessi`** — i 57 endpoint della Zone per ognuno dei sette.
   La mappa dei permessi si legge dal sorgente di `FieldwireController`: se
   qualcuno aggiunge un endpoint e si scorda la riga, la prova lo dice.
2. **`t2_contenuti`** — creare, condividere, modificare, compilare. Gira sui
   repository veri, quindi la SQL e' quella.
3. **`t3_notifiche`** — chi riceve cosa. Usa il `NotificationService` vero e
   conta i destinatari leggendo `bb_notifications`.
4. **`t4_cliente`** — il cliente da fuori a dentro: dove puo' navigare, quali
   cantieri sono suoi, cosa trova nella Zone.

## Cosa NON coprono

Non c'e' `vendor/` e il PHP locale e' 8.0 (il progetto e' 8.1), quindi
l'applicazione vera non si avvia qui. Restano fuori:

- il routing e il middleware web (provati leggendo le loro regole dai file,
  non eseguendoli);
- i corpi dei controller: si prova la regola d'accesso di ogni endpoint, non
  le venti righe che vengono dopo;
- Twig, e quindi le pagine.

Chi ha un PHP 8.1 e `composer install` puo' alzare l'asticella: stesso
database, l'app vera dietro `php -S`, e le prove diventano richieste HTTP.
