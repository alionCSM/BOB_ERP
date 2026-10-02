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

**Tutte le sue giornate**, non solo quelle che ha mandato lui dall'app.
Senza parametri: da un mese fa a un mese avanti.

Parametri: `dal`, `al` (`aaaa-mm-gg`).

```json
{ "success": true, "dal": "2026-08-30", "al": "2026-10-30",
  "presenze": [
    { "origine": "dichiarata", "stato": "in_attesa",
      "id": null, "richiesta_id": 8,
      "data": "2026-09-29", "turno": "Intero",
      "note": null, "motivo": null,
      "pranzo": "Noi", "cena": "-", "hotel": null,
      "targa_auto": "AB123CD", "trasferta": 0,
      "worksite_id": 5059, "cantiere_nome": "Via Roma",
      "cantiere_codice": "C-2026-014" },
    { "origine": "registrata", "stato": "registrata",
      "id": 41022, "richiesta_id": null,
      "data": "2026-09-26", "turno": "Intero",
      "note": null, "motivo": null,
      "pranzo": "-", "cena": "-", "hotel": null,
      "targa_auto": null, "trasferta": 0,
      "worksite_id": 5059, "cantiere_nome": "Via Roma",
      "cantiere_codice": "C-2026-014" }
  ] }
```

`origine`: `registrata` (una riga vera di `bb_presenze`) | `dichiarata`
(una richiesta ancora non approvata).

`stato`: `registrata` | `in_attesa` | `rifiutata`.

Non esiste `approvata` in questo elenco, ed e' voluto: appena l'ufficio
approva, nasce la presenza vera e la giornata ricompare come `registrata`.
Mandare tutte e due le righe farebbe vedere lo stesso giorno due volte.

`motivo` e' valorizzato solo sulle rifiutate — e' quello che l'operaio deve
leggere per capire cosa correggere. `richiesta_id` serve per ritirarla.

Ordinate dal giorno piu' recente. Lo storico di chi lavorava prima dell'app
c'e' tutto: sono presenze registrate dall'ufficio, e un elenco che mostrasse
solo le dichiarazioni gli direbbe di non aver mai lavorato.

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

Le sue assenze — ferie, permessi e malattie — richieste o registrate
dall'ufficio.

```json
{ "success": true,
  "ferie": [
    { "id": 3, "tipo": "ferie", "data_inizio": "2026-08-10",
      "data_fine": "2026-08-24", "ore": null, "note": null,
      "protocollo": null,
      "stato": "approvata", "richiesta_da_operaio": 0, "motivo": null }
  ] }
```

`protocollo` e' il numero del certificato, e vale solo sulle malattie.

Le righe inserite dall'ufficio prima di questa funzione risultano
`approvata`: le ha messe chi decide, e ritrovarsele da approvare il giorno
del rilascio sarebbe stato un disastro.

## POST /api/v1/me/ferie

```json
{ "tipo": "ferie", "dal": "2026-12-23", "al": "2027-01-06", "ore": null,
  "note": "", "protocollo": "" }
```

`tipo`: `ferie` | `permesso` | `malattia`. `al` si puo' omettere per un
giorno solo. `ore` serve ai permessi di poche ore. Nasce `in_attesa`.

`protocollo` e' il numero del certificato e si legge **solo** con
`tipo: malattia`: su una ferie viene buttato via, perche' un numero di
certificato attaccato a una ferie non vuol dire niente.

**Non e' obbligatorio.** Molti il certificato lo mandano su WhatsApp e lo
mette l'ufficio: bloccare la dichiarazione per un campo vuoto vorrebbe dire
che uno a letto con la febbre non riesce ad avvisare. Si aggiunge dopo.

La malattia non si "chiede" — uno sta male e basta — ma passa dalla stessa
porta perche' l'ufficio deve comunque vederla e riscontrare il certificato.
`in_attesa` li' non vuol dire "forse", vuol dire "non ancora guardata".

L'ufficio la decide da `/attendance/leaves`, in cima alla pagina Assenze. Finche' e' in attesa non e' un'assenza: non conta negli "assenti
oggi" e non esclude l'operaio dal promemoria della sera, perche' quel giorno
li' lui e' al lavoro finche' nessuno gli risponde.

## POST /api/v1/me/lingua

```json
{ "lingua": "sq" }
```

`it` | `en` | `sq` | `ro` | `mo`. Maiuscole accettate. Qualsiasi altra cosa
e' **422**, con l'elenco di quelle che esistono.

Unico endpoint di `/me/` che non chiede un operaio collegato: la lingua sta
sull'utente, non sul lavoratore.

La mette gia' l'ufficio quando crea l'account — centoquaranta persone che
entrano nel web ad aggiustarsela non succede, e in ufficio sanno gia' chi e'
albanese e chi rumeno. Questo serve a chi se la ritrova sbagliata.

**Per gli account che esistono gia' non c'e' una schermata lato ufficio**:
o se la cambia l'operaio da qui o dal profilo web, oppure la si mette a
mano sul database. Vale la pena che l'app la chieda al primo avvio.

## GET /api/v1/me/zone

I cantieri di cui puo' aprire la Zone, e fin dove su ognuno.

```json
{ "success": true,
  "cantieri": [
    { "id": 5059, "worksite_code": "C-2026-014", "name": "Via Roma",
      "location": "Lecco", "status": "In corso",
      "accessi": { "attivita": 2, "file": 1, "moduli": 1,
                   "disegni": 1, "foto": 2, "report": 0 } }
  ],
  "famiglie": { "attivita": "Attivita", "file": "File e documenti",
                "moduli": "Moduli", "disegni": "Disegni",
                "foto": "Foto", "report": "Report" } }
```

Livelli: **0** non vede, **1** vede, **2** vede e modifica.

L'app disegna solo le schede con livello > 0 e nasconde i bottoni di
scrittura sotto il 2. Il server rifiuta comunque — ogni endpoint della Zone
si controlla da solo — ma mostrare una scheda che poi risponde 403 sembra un
guasto, e chi la trova continua a cliccarci.

Senza questo elenco l'app non sa da dove cominciare: gli endpoint sanno dire
di no, non sanno dire quali cantieri provare.

Non chiede un operaio collegato: la Zone si da' all'utente.

Chi ha il modulo `zone` in BOB non compare qui con tutti i cantieri: questo
elenco sono le **assegnazioni**. Per l'ufficio il resto di BOB fa gia' il
suo mestiere.

**Gli endpoint della Zone restano quelli di sempre** (`/api/v1/zone/{id}/...`):
non cambia niente nelle chiamate, cambia solo chi riceve 403.

### Come si assegna

Dalla scheda del cantiere, **Assegna utente**. Assegnare il cantiere e dare
la sua Zone sono la stessa cosa: non si assegna qualcuno a un cantiere per
poi non fargli vedere niente. Nasce vedendo tutto senza toccare niente, e da
"Chi accede" si alza o si abbassa sezione per sezione.

### Il resto del cantiere non si apre

Preventivi, fatture, noleggi, ordini stanno sotto il modulo `worksites`, che
un operaio non ha, e **tutto il prefisso `/worksites` e' chiuso dal
middleware** prima ancora di arrivare al controller. Un operaio assegnato non
puo' aprire la pagina web del cantiere nemmeno scrivendo l'indirizzo a mano.

Dall'app arriva solo alla Zone, perche' solo quella passa da `/api/v1/zone/`,
e li' ogni endpoint guarda i suoi livelli.

Dove va e con chi, invece, lo dice `/me/pianificazione`, che e' un'altra
strada e non c'entra niente con questa: li' si vede la squadra del giorno e
chi e' il capo.

---

## Le notifiche all'operaio

Arrivano da `NotificationService`, quindi dentro BOB e come push sui
telefoni registrati, nella lingua scelta in `bb_users.lingua`.

| Quando | Titolo | Priorita' |
|---|---|---|
| presenza approvata | Presenza approvata | normal |
| presenza rifiutata | Presenza rifiutata | high |
| ferie o permesso approvati | Richiesta approvata | normal |
| ferie o permesso rifiutati | Richiesta rifiutata | high |

Il rifiuto e' `high` perche' chiede di fare qualcosa — ridichiarare il
giorno, riproporre altre date — e se arriva silenzioso l'operaio lo scopre
quando ormai non serve piu'.

Il **motivo del rifiuto resta nella lingua in cui l'ufficio l'ha scritto**:
e' testo libero e nessuno lo puo' tradurre. Il resto della frase arriva
tradotto, quindi un operaio albanese legge "Prezenca e dates 29/09/2026 u
refuzua: cantiere sbagliato". Vale la pena saperlo quando si scrive il
motivo: poche parole semplici si capiscono comunque.

Si manda **dopo** che la decisione e' salvata e la transazione e' chiusa. Un
push spedito dentro la transazione resterebbe spedito anche se il
salvataggio si ribalta, e il push non si richiama indietro. Se la notifica
fallisce la decisione resta valida e l'ufficio non se ne accorge: e' finita
nel log, e l'operaio lo scopre riaprendo l'app, che e' dove eravamo prima.

Chi non ha un telefono registrato riceve comunque la riga dentro BOB.

## Cosa manca ancora

- **La malattia non si vede in pianificazione**: chi e' a casa malato non
  riceve piu' il promemoria della sera, ma nella schermata delle squadre
  resta li' come tutti. Chi programma deve ricordarselo.
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

Due pagine, tutte e due sotto **Presenze** nel menu.

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

`/attendance/leaves` — ferie, permessi e malattie (nel menu: **Assenze**),
con le richieste arrivate dall'app in cima, dalla piu' vecchia: quella ferma da una settimana e' quella che
scotta, e dopo tre giorni passa in evidenza da sola.

Le date non si cambiano approvando. Se vanno corrette si usa Modifica
nell'elenco e poi si approva: un'approvazione che sposta i giorni di nascosto
farebbe tornare l'operaio dalle ferie il giorno sbagliato.

Il rifiuto vuole un motivo, bloccato lato pagina. Finisce nell'app, ed e' la
differenza fra "no" e "no, in quella settimana siamo in tre a Lecco": col
secondo uno ripropone altre date invece di venire in ufficio a chiedere
perche'.
