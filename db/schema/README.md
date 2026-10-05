# Schema delle tabelle nate prima delle migration

Buona parte di BOB e' precedente a Phinx: tabelle come `bb_users`,
`bb_workers`, `bb_companies` e `bb_clients` non sono state create da nessuna
migration, quindi **il loro elenco di colonne non sta scritto da nessuna
parte nel codice**. Si puo' solo dedurlo dalle query, e dedurlo non basta:
una lettura tollerante come `$row['client_id'] ?? null` funziona uguale che
la colonna ci sia o no, e la differenza salta fuori solo quando qualcuno
prova a scriverci.

Qui dentro ci sono degli **scatti datati** dello schema reale di produzione.
Non sono la verita' corrente — lo diventano solo se qualcuno li rigenera —
ma bastano per sapere se una colonna esiste, di che tipo e', e quali valori
accetta un ENUM prima di scriverci dentro.

## Rigenerare

```bash
mysqldump --no-data --skip-comments --compact bob_prod \
    bb_users bb_workers bb_companies bb_clients bb_group_companies \
    > db/schema/legacy.sql
```

## Cose che si scoprono solo guardando lo schema

- `bb_users.role` e `bb_users.type` sono **ENUM**: un valore fuori elenco non
  viene troncato, fa fallire la INSERT. Ogni form che li scrive deve
  proporre un elenco chiuso, non un campo di testo.
- `bb_users` ha delle **foreign key** (`fk_user_company`, `fk_worker_id`),
  quindi la regola "in produzione non si usano le foreign key" vale per le
  tabelle nuove, non per queste.
- `fk_worker_id` e' `ON DELETE NO ACTION`: un operaio con un account BOB non
  si puo' cancellare finche' l'account esiste.
- `client_id` non ha ne' indice ne' foreign key, a differenza di
  `company_id` e `worker_id` che ce le hanno entrambe.
