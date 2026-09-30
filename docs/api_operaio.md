# API dell'app operaio

Endpoint per l'app che useranno gli operai sul proprio telefono. Tutti sotto
`/api/v1/me/`, tutti con `Authorization: Bearer <token>`.

## Come funziona l'accesso

Qui non si controlla nessun permesso di modulo: un operaio non ne ha. Conta
una sola cosa — **sono i tuoi dati?** — e la risposta viene dal `worker_id`
dell'utente collegato, mai da un id passato nella richiesta.

Se l'utente non e' collegato a nessun operaio (`bb_users.worker_id` vuoto)
ogni endpoint risponde **403**. E' il caso di un account creato senza
scegliere l'operaio: succede, e l'app deve saperlo dire chiaramente invece
di mostrare una schermata vuota.

Tutte le risposte hanno `success`, come il resto di `/api/v1`.

---

## GET /api/v1/me/cantieri

I cantieri su cui l'operaio puo' dichiarare. Sono quelli a cui e' assegnato
(`bb_worksite_assignments`), non tutti.

```json
{ "success": true,
  "cantieri": [
    { "id": 5059, "worksite_code": "C-2026-014", "name": "Via Roma", "location": "Lecco" }
  ] }
```

Se l'elenco e' vuoto l'operaio non e' assegnato a niente: l'app dovrebbe
dirlo, non lasciare una tendina vuota.

## GET /api/v1/me/documenti

I suoi documenti, con i giorni che mancano alla scadenza **contati dal
server**: sul telefono l'orologio e' spesso sbagliato, e un documento
scaduto che risulta valido e' l'errore che non ci si puo' permettere.

```json
{ "success": true,
  "documenti": [
    { "id": 12, "tipo_documento": "Visita medica",
      "data_emissione": "2025-03-10", "scadenza": "2026-03-10",
      "giorni_alla_scadenza": -204 }
  ] }
```

`giorni_alla_scadenza` e' `null` se il documento non scade, negativo se e'
gia' scaduto. Ordinati per scadenza, i senza scadenza in fondo.

## GET /api/v1/me/presenze

Le sue dichiarazioni. Senza parametri: da un mese fa a un mese avanti.

Parametri: `dal`, `al` (`aaaa-mm-gg`).

```json
{ "success": true, "dal": "2026-08-30", "al": "2026-10-30",
  "presenze": [
    { "id": 8, "data": "2026-09-29", "turno": "Intero",
      "stato": "in_attesa", "note": null, "motivo": null,
      "worksite_id": 5059, "cantiere_nome": "Via Roma",
      "cantiere_codice": "C-2026-014", "presenza_id": null }
  ] }
```

`stato`: `in_attesa` | `approvata` | `rifiutata`.
`motivo` e' valorizzato solo sulle rifiutate — e' quello che l'operaio deve
leggere per capire cosa correggere.
`presenza_id` compare sulle approvate: e' la presenza vera nata da li'.

## POST /api/v1/me/presenze

Dichiara una giornata. **Non crea una presenza**: crea una dichiarazione che
l'ufficio guarda, eventualmente corregge e approva.

```json
{ "worksite_id": 5059, "data": "2026-09-29", "turno": "Intero", "note": "" }
```

`turno`: `Intero` | `Mezzo`. Risposta: `{ "success": true, "id": 8, "stato": "in_attesa" }`.

Rifiuti possibili:

| Codice | Quando |
|---|---|
| 422 | manca il cantiere o il giorno |
| 422 | giorno futuro: si dichiara quello che si e' fatto |
| 403 | non risulti assegnato a quel cantiere |
| 409 | hai gia' una dichiarazione in attesa per quel giorno e cantiere |

Il 409 vale solo sulle **in attesa**: una rifiutata si puo' rimandare
corretta.

## POST /api/v1/me/presenze/{id}/ritira

Ritira una dichiarazione, finche' l'ufficio non ha deciso. Dopo risponde
**409**: cancellarla vorrebbe dire togliere una decisione altrui, e se era
approvata la presenza vera e' gia' nata.

## GET /api/v1/me/ferie

Le sue ferie e permessi, richiesti o registrati dall'ufficio.

```json
{ "success": true,
  "ferie": [
    { "id": 3, "tipo": "ferie", "data_inizio": "2026-08-10",
      "data_fine": "2026-08-24", "ore": null, "note": null,
      "stato": "approvata", "richiesta_da_operaio": 0, "motivo": null }
  ] }
```

Le righe inserite dall'ufficio prima di questa funzione risultano
`approvata`: le ha messe chi decide, e ritrovarsele da approvare il giorno
del rilascio sarebbe stato un disastro.

## POST /api/v1/me/ferie

```json
{ "tipo": "ferie", "dal": "2026-12-23", "al": "2027-01-06", "ore": null, "note": "" }
```

`tipo`: `ferie` | `permesso`. `al` si puo' omettere per un giorno solo.
`ore` serve ai permessi di poche ore. Nasce `in_attesa`.

---

## Cosa manca ancora

- **La schermata dell'ufficio** per approvare, correggere e rifiutare. Senza,
  le dichiarazioni si accumulano e nessuno le vede. Il repository e' pronto
  (`RichiestaPresenzaRepository::daApprovare`, `approva`, `rifiuta`).
- **Una notifica** all'operaio quando l'ufficio decide: oggi deve riaprire
  l'app per scoprirlo.
- **BOB Zone** e' gia' esposto altrove (`/api/v1/zone/...`) e nell'app ci
  sono gia' Cantieri e Zone. Manca la sezione Disegni.

## Sul deploy

`ApiV1OperaioController` e' una classe nuova nel namespace globale, caricata
dal classmap di composer: dopo il deploy serve

```
composer dump-autoload -o
```

altrimenti le rotte rispondono 500 perche' la classe non si trova.
