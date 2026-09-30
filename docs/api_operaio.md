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

## GET /api/v1/me/pianificazione

**L'endpoint principale.** Risponde alla domanda che l'operaio si fa
davvero: non "quale cantiere cerco", ma **"domani dove vado"**.

Senza parametri: oggi e domani. La sera si guarda domani, la mattina si
guarda oggi, e mandarli insieme evita la seconda chiamata proprio nell'ora
in cui la linea in cantiere e' peggiore.

```json
{ "success": true, "dal": "2026-09-30", "al": "2026-10-01",
  "pianificazione": [
    { "id": 77, "data": "2026-10-01",
      "worksite_id": 5059, "worksite_code": "C-2026-014",
      "cantiere_nome": "Via Roma", "location": "Lecco",
      "cantiere": "Via Roma Lecco",
      "auto_targa": "AB123CD",
      "sei_capo": true,
      "squadra": [
        { "nome": "Rossi Mario", "auto_targa": "AB123CD", "capo_squadra": 1 },
        { "nome": "Bianchi Luca", "auto_targa": "", "capo_squadra": 0 }
      ] } ] }
```

`worksite_id` puo' essere `null` sulle righe pianificate prima di questa
funzione, o su un lavoro programmato prima che la commessa fosse aperta: in
quel caso vale solo `cantiere`, il testo. Senza `worksite_id` l'app non puo'
precompilare la dichiarazione di presenza — mostra il nome e lascia scegliere.

`squadra` arriva **solo al capo squadra**: agli altri non serve sapere chi
altro c'e', e mandare l'elenco dei colleghi a tutti vuol dire spargere i dati
di centoquaranta persone su centoquaranta telefoni.

Parametri: `dal`, `al`.

## GET /api/v1/me/cantieri

Serve solo quando la pianificazione non dice niente: se l'operaio e'
pianificato, il cantiere lo sa gia' da `/me/pianificazione` e non deve
cercare niente.

`recenti` sono i cantieri dove ha lavorato negli ultimi sei mesi, dal piu'
recente; `aperti` sono tutti quelli in corso, per quando lo mandano da
un'altra parte. Parametro `q` per cercare fra gli aperti.

```json
{ "success": true,
  "recenti": [ { "id": 5059, "worksite_code": "C-2026-014", "name": "Via Roma",
                 "location": "Lecco", "ultima_presenza": "2026-09-26" } ],
  "aperti":  [ { "id": 5060, "worksite_code": "C-2026-015", "name": "Via Verdi",
                 "location": "Bergamo" } ] }
```

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
{ "worksite_id": 5059, "data": "2026-09-29", "turno": "Intero",
  "pranzo": "azienda", "cena": "", "hotel": "", "targa_auto": "AB123CD",
  "trasferta": false, "note": "" }
```

`turno`: `Intero` | `Mezzo`.

**`pranzo` e `cena`: manda le parole dell'operaio, non quelle dell'ufficio.**

| L'app mostra | L'app manda | A database diventa |
|---|---|---|
| Ho pagato io | `io` | `Loro` |
| Ha pagato l'azienda | `azienda` | `Noi` |
| Non ho mangiato | `` (vuoto) | `-` |

La traduzione la fa il server, di proposito. "Ho pagato io" diventa "Loro" —
dal punto di vista di chi tiene i conti, loro sono gli operai — ed e'
un'inversione facilissima da sbagliare. Sbagliata, sposta i costi dei pasti
da una parte all'altra senza che nessuno se ne accorga.

Tenendola nel server, chiunque scriva un client — questa app, quella per
iPhone, qualsiasi cosa domani — manda quello che ha scelto l'operaio e non
puo' invertirla.

Qualsiasi altro valore diventa `-`: meglio una riga che dice "non pervenuto"
di una che afferma qualcosa che nessuno ha detto.

**L'importo non si chiede all'operaio.** Lui sa di aver mangiato, non sa
quanto l'azienda ha pagato quel pasto: chiederglielo vuol dire raccogliere
numeri inventati che poi qualcuno deve correggere uno per uno. Il prezzo lo
mette l'ufficio in approvazione, dove ci sono le fatture.

`hotel` e `targa_auto` sono testo libero, come in `bb_presenze`.

`trasferta` serve al confronto: se arriva cena o albergo su un giorno che in
pianificazione non era in trasferta, l'ufficio se lo vede segnalato.

Risposta: `{ "success": true, "id": 8, "stato": "in_attesa" }`.

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

- **Una notifica** all'operaio quando l'ufficio decide: oggi deve riaprire
  l'app per scoprirlo.
- **Il suggerimento dei giorni non dichiarati**: il problema vero non e'
  trovare il cantiere, e' ricordarsi di dichiarare. Dopo tre giorni uno non
  sa piu' dove e' stato.
- **BOB Zone** e' gia' esposto altrove (`/api/v1/zone/...`) e nell'app ci
  sono gia' Cantieri e Zone. Manca la sezione Disegni.

## Sul deploy

`ApiV1OperaioController` e' una classe nuova nel namespace globale, caricata
dal classmap di composer: dopo il deploy serve

```
composer dump-autoload -o
```

altrimenti le rotte rispondono 500 perche' la classe non si trova.


---

## Lato ufficio

`/attendance/richieste` — le dichiarazioni da guardare, con i campi
correggibili prima di approvare: quello che finisce in `bb_presenze` e'
quello che l'ufficio ha davanti dopo averlo sistemato, non per forza quello
che aveva scritto l'operaio. La dichiarazione resta com'era, cosi' resta la
traccia di cosa e' cambiato.

Gli avvisi non bloccano niente — decide l'ufficio — ma fanno cadere l'occhio
sulle righe che meritano un secondo sguardo:

- **cena o albergo senza trasferta** in pianificazione: sono le voci che costano
- **cantiere diverso** da quello pianificato: puo' essere giusto, capita di
  spostare qualcuno all'ultimo, ma va guardato
- **non era in pianificazione** quel giorno: non e' un errore, ma nessuno lo
  aspettava li'
