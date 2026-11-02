# Prove della BOB Zone

Quattro prove che esercitano la Zone come la usano i tre tipi di persone che
ci stanno dentro: **ufficio**, **cantiere** (capo e operaio), **cliente**.

```bash
php tests/zone/esegui.php
```

## Cosa serve

Un MySQL dove poter creare un database. Le prove **si creano il loro**
(`bob_zone_test`) usando host, utente e password del `.env`
dell'installazione: non c'e' niente da configurare, e non possono finire
sul database dell'applicazione.

```bash
php tests/zone/esegui.php
```

Il database delle prove viene **svuotato e ricostruito a ogni prova**. Due
sicure lo tengono lontano da quello vero: il nome non puo' essere quello del
`.env`, e deve contenere `test`. Per usarne un altro:

```bash
ZONE_TEST_DB=zone_test_mio php tests/zone/esegui.php
```

Se l'utente del `.env` non puo' fare `CREATE DATABASE`, si crea a mano una
volta sola:

```sql
CREATE DATABASE bob_zone_test CHARACTER SET utf8mb4;
GRANT ALL ON bob_zone_test.* TO 'utente_di_bob'@'%';
FLUSH PRIVILEGES;
```

L'utente e l'host li dice il messaggio d'errore, che stampa le righe gia'
pronte. Attenzione all'host nella GRANT: se l'utente di BOB e'
`...'@'%'`, la GRANT va data su `%`, non su `localhost` — sono due utenti
diversi per MySQL.

**Dove lanciarle.** Sul server di collaudo va bene: si fanno il database
loro e non toccano `bob_collaudo`. In produzione no — non c'e' motivo di
creare database su quella macchina.

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
