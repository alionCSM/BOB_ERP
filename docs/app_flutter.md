# L'app degli operai — da dove ripartire

Questo file esiste perche' il lavoro comincia su Windows e continua su un
Mac. Chi riprende da qui trova deciso tutto quello che era gia' stato
deciso, invece di ridiscuterlo.

---

## Cosa si costruisce

Una app sola, Android e iOS, **solo per gli operai e i capi squadra**.

Fuori dallo scopo, per ora: ufficio, clienti, consorziate. Quelle cose si
fanno da BOB sul computer, nessuno le apre in cantiere, e l'API per loro non
esiste ancora.

| Schermata | Chi la usa | API |
|---|---|---|
| Apertura: dove vai, con chi | tutti, ogni giorno | pronta |
| Le mie presenze, segna una giornata | tutti, ogni giorno | pronta |
| Ferie, permessi, malattia | tutti, ogni tanto | pronta |
| I miei documenti | tutti, raro ma importante | pronta |
| BOB Zone del proprio cantiere | capi squadra e assegnati | pronta |

## Perche' Flutter

Una base di codice sola per i due telefoni, ed e' quello che e' stato
chiesto: **stesso aspetto su Android e iPhone**, non un'app che su iPhone
sembra iOS e su Android sembra Android.

Kotlin Multiplatform era l'altra strada e avrebbe riusato il vecchio
BOB_Android, ma quello va riscritto comunque, quindi il vantaggio spariva.

## Le cose che devono essere native

Sono anche il motivo per cui Apple accetta l'app invece di rifiutarla come
"un sito in una scatola" (regola 4.2):

- notifiche push (FCM e APNs)
- fotocamera, per le foto delle attivita'
- accesso con impronta o Face ID, token nel portachiavi
- coda offline: una presenza mandata senza campo parte quando torna la linea

---

## L'API

Base: `https://bob.csmontaggi.it/api/v1`. Bearer token su tutto tranne il
login. Dettaglio completo di ogni risposta: `docs/api_operaio.md`.

### Il giro all'avvio

```
POST /auth/login              → token
     ↓ 403 code=must_change_password?
POST /me/password             → l'unico endpoint che passa con quel blocco
     ↓
GET  /me/home                 → utente, lingua, menu, prossima giornata, cosa manca
POST /devices/fcm             → registra il telefono, se no niente notifiche
```

**Il menu lo manda il server, gia' tradotto.** L'app disegna le voci che
riceve e **salta quelle che non conosce**: cosi' una sezione nuova in BOB non
rompe le installazioni vecchie, e un permesso cambiato non obbliga
centoquaranta persone ad aggiornare.

### Il resto

```
GET  /me/pianificazione            dove vai, con chi, chi e' il capo
GET  /me/presenze?mese=            le giornate
POST /me/presenze                  dichiara una giornata
POST /me/presenze/{id}/ritira
GET  /me/cantieri?q=               cerca, solo dopo due lettere
GET  /me/ferie                     ferie, permessi, malattia
POST /me/ferie
GET  /me/documenti
GET  /me/documenti/{id}/file       il PDF
POST /me/lingua
GET  /me/zone                      i cantieri assegnati, coi livelli
GET  /zone/{id}/...                BOB Zone, 33 rotte
GET  /notifications
```

### Due cose da non sbagliare

**I pasti si mandano con le parole dell'operaio.** L'app manda `io`,
`azienda` o vuoto; la traduzione in `Loro` / `Noi` la fa il server. "Ho
pagato io" diventa "Loro" — dal punto di vista di chi tiene i conti — ed e'
un'inversione che sbagliata sposta i costi senza lasciare traccia. **Non
tradurla nell'app.**

**I livelli della Zone sono tre**: 0 non vede, 1 vede, 2 vede e modifica.
L'app disegna solo le sezioni sopra 0 e nasconde i bottoni di scrittura sotto
il 2. Il server rifiuta comunque, ma un bottone che risponde 403 sembra un
guasto.

---

## Le lingue

Cinque: `it` `en` `sq` `ro` `mo` (il moldavo usa i testi rumeni). Stanno in
`src/Service/Lingua.php`, oltre 130 chiavi.

Le etichette del menu arrivano gia' tradotte da `/me/home`. Le stringhe
dell'app invece stanno nell'app: conviene riusare le stesse chiavi di
`Lingua.php` cosi' si confrontano a colpo d'occhio.

**Le traduzioni albanesi e rumene non sono state riviste da madrelingua.**
Prima di distribuire vanno fatte leggere a qualcuno che le parla.

---

## L'aspetto

Il concept delle schermate e' su un canvas condiviso; sotto, i valori.

L'idea: **una cosa sola domina ogni schermata**. Il blocco scuro in alto dice
dove vai, il chiaro sotto dice tutto il resto. Chi apre l'app la sera vuole
leggere il nome del cantiere, non cercarlo.

### Colori

| | | |
|---|---|---|
| Nero | `#11151C` | il blocco di apertura, il testo |
| Nero piu' chiaro | `#1D242E` | le pillole dentro il nero |
| Arancione | `#FF7A1A` | **solo per quello che devi fare**, mai decorazione |
| Arancione scuro | `#2A1B10` / `#FFB37A` | trasferta, sul nero |
| Fondo | `#F4F5F7` | |
| Schede | `#FFFFFF` | ombra `0 1px 2px rgba(17,21,28,.05)` |
| Grigio testo | `#6B7686` su chiaro, `#8A94A6` su scuro | |
| Verde | `#1B9E5A` barra, `#14724A` testo | registrata |
| Ambra | `#FFB020` barra, `#946200` testo | in attesa |
| Rosso | `#D83A32` barra, `#B32B24` testo | rifiutata |

Sul bottone arancione il testo e' **nero**, non bianco: si legge al sole.

Gli stati non si distinguono solo dal colore — ognuno ha anche la sua icona.

### Caratteri

- **Space Grotesk** 700 — numeri, nomi dei cantieri, date. Ha un'aria tecnica
  e tiene bene i numeri grossi.
- **Manrope** 400/500/700/800 — tutto il resto.

Niente Inter o Roboto: sono il carattere di default di ogni app fatta in
fretta.

### Misure

- Nome del cantiere in apertura: 40px, interlinea 1.04, spaziatura -.04em
- Etichette piccole: 11px, 800, maiuscole, spaziatura .16em
- Angoli: 30px il blocco di apertura, 18-20px le schede, 11-13px le pillole
- **Niente da toccare sotto i 44px**, i bottoni principali 54px
- Barra in fondo a quattro voci, non il bottone "indietro"

---

## Sul Mac

```
flutter doctor -v
```

Devono essere verdi Flutter, Android toolchain e Xcode. Java **21**, non la
25: Gradle con quella litiga.

Il progetto va fuori da questo repo, tipo `~/Progetti/bob_app`. Questo repo
serve per leggere `docs/api_operaio.md` e `src/Service/Lingua.php`.

### Prima di scrivere codice

Serve un utente operaio vero su cui provare: un account collegato a un
lavoratore, assegnato a un cantiere, con qualche giornata e una richiesta in
attesa. Senza, si sviluppa alla cieca.

### Quello che non e' mai stato provato

**Nessuna di queste API ha mai girato contro il database vero.** Sono state
verificate su dati finti e leggendo le migration. La prima cosa da fare, prima
di costruirci sopra, e' chiamarle una per una e guardare cosa tornano
davvero.

---

## Per gli store

| | |
|---|---|
| Apple Developer | un account aziendale esisteva gia' anni fa: ritrovarlo evita settimane di attesa per il D-U-N-S |
| Google Play | 25$ una volta. **Come azienda**: un account personale non pubblica prima di due settimane, per regolamento loro |

Per pubblicare lo stesso giorno: si manda prima ad Apple (revisione 1-3
giorni), e quando approva **non si rilascia** — Apple tiene l'app approvata
finche' non premi tu. Intanto Google approva in qualche ora. Poi i due
bottoni insieme.
