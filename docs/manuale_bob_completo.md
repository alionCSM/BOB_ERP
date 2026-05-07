# BOB — Manuale di Sistema

**Gestionale operativo del Consorzio Soluzione Montaggi / CS Montaggi**

*Documento tecnico-funzionale*

---

**Versione documento:** 1.0
**Data:** 2026-05-07
**Riferimento applicativo:** BOB v2.x

---

## Sommario

1. [Premessa](#1-premessa)
2. [Architettura e stack tecnologico](#2-architettura-e-stack-tecnologico)
3. [Modello di business e flussi operativi](#3-modello-di-business-e-flussi-operativi)
4. [Ruoli, profili utente e permessi](#4-ruoli-profili-utente-e-permessi)
5. [Moduli funzionali](#5-moduli-funzionali)
   - 5.1 Dashboard
   - 5.2 Cantieri
   - 5.3 Offerte
   - 5.4 Fatturazione clienti
   - 5.5 Pagamenti consorziate
   - 5.6 Ordini consorziata
   - 5.7 Ordini aziende
   - 5.8 Presenze
   - 5.9 Operai e utenti
   - 5.10 Aziende
   - 5.11 Clienti
   - 5.12 Documenti
   - 5.13 Pianificazione e Programmazione
   - 5.14 Mezzi e attrezzature
   - 5.15 Prenotazioni alloggi
   - 5.16 Buoni pasto
   - 5.17 Doc condivisi
   - 5.18 BOB AI
6. [Sicurezza e protezione dei dati](#6-sicurezza-e-protezione-dei-dati)
7. [Integrazioni esterne](#7-integrazioni-esterne)
8. [Operazioni di sistema](#8-operazioni-di-sistema)
9. [Audit, monitoraggio e notifiche](#9-audit-monitoraggio-e-notifiche)
10. [Glossario](#10-glossario)
11. [Allegati](#11-allegati)

---

## 1. Premessa

### 1.1 Cos'è BOB

**BOB** è il gestionale operativo sviluppato internamente per il **Consorzio Soluzione Montaggi** (sede legale: Via Bruno Tosarelli 322, 40055 Villanova di Castenaso BO — P.IVA 03584711208), spesso indicato in forma abbreviata come **CS Montaggi**. Le due denominazioni si riferiscono alla stessa entità giuridica.

L'applicativo copre l'intero ciclo operativo dell'attività di montaggi industriali, dalla preventivazione al pagamento delle consorziate.

**BOB non sostituisce il sistema contabile.** La contabilità ufficiale dell'azienda risiede in **Business** (gestionale di contabilità di terzi, sviluppato esternamente). Fra BOB e Business si interpone **Yard** (database SQL Server), che svolge il ruolo di *middleware*: BOB scrive i dati di fatturazione in Yard, Yard li propaga a Business. Le fatture sono emesse formalmente da Business, non da BOB né da Yard.

### 1.2 Obiettivi del sistema

- **Centralizzare** la gestione di cantieri, presenze, documenti, ordini, fatture, pagamenti e personale.
- **Automatizzare** i controlli quotidiani (margini cantieri a rischio, scadenze documentali, anomalie operative).
- **Tracciare** ogni movimento operativo per finalità di audit interno e conformità.
- **Distribuire** in modo controllato l'accesso ai dati ai partner consorziati attraverso un modulo dedicato.
- **Fornire** strumenti di analisi (BOB AI) per interrogare il dato in linguaggio naturale, riducendo la dipendenza da estrazioni manuali.

### 1.3 Destinatari del presente documento

- Direzione aziendale e responsabili operativi.
- Personale amministrativo e di cantiere.
- Eventuali consulenti tecnici incaricati di manutenere o estendere il sistema.

---

## 2. Architettura e stack tecnologico

### 2.1 Componenti applicativi

| Livello | Tecnologia | Note |
|---|---|---|
| Linguaggio backend | **PHP 8.x** | Codice in `strict_types`, namespace PSR-4 sotto `App\` |
| Framework | Custom MVC su pattern leggero | Routing, middleware e DI container interni |
| Templating | **Twig 3.x** | Auto-escape HTML; nonce CSP per script inline |
| Persistenza primaria | **MySQL 8.x** (database `bob_csm`) | Tutto il dato operativo |
| Persistenza accessoria | **SQL Server** (Yard, middleware verso Business) | Lettura stato fatturazione + scrittura brogliacci |
| ORM / Query layer | PDO + Repository pattern | Nessun ORM full-fledged |
| Migrazioni schema | **Phinx 0.16** | File sotto `db/migrations/` |
| Frontend | Tailwind / Midone admin theme + Vanilla JS | Nessun framework SPA |
| PDF | **dompdf 3.x** | Rendering documenti (offerte, ordini) |
| Excel | **PhpSpreadsheet 2.x** | Export presenze, fatturazione |
| Email | **PHPMailer 6.x** | SMTP autenticato (PEC e standard) |
| Sessione / sicurezza | Session PHP nativa, JWT (firebase/php-jwt) | Vedi cap. 6 |
| AI | **Ollama** (LLM locale) | Modello Qwen3-30B; non transita in cloud |
| Containerizzazione | Nessuna — deploy bare-metal su Linux | Apache/PHP-FPM |

### 2.2 Struttura della repository

```
BOB_ERP/
├── public/                  # Document root web (index.php = front controller)
├── src/
│   ├── Domain/              # Modelli di dominio (User, Worksite, Billing, ...)
│   ├── Repository/          # Strato di accesso dati
│   ├── Service/             # Logica di business
│   ├── Http/
│   │   ├── Controllers/     # Action handler per ogni modulo
│   │   └── Middleware/      # Autenticazione, CSRF, ecc.
│   ├── Security/            # Autorizzazione, profili di accesso
│   ├── Infrastructure/      # Config, connessioni DB
│   └── View/                # Renderer Twig, layout data provider
├── templates/               # Template Twig per ogni modulo
├── views/                   # View legacy PHP (esportazioni Excel/PDF)
├── includes/
│   ├── bootstrap.php        # Caricamento ENV + classe alias
│   ├── middleware.php       # Pipeline pre-controller
│   ├── helpers/             # Funzioni globali (CSRF, 404)
│   └── cron/                # Script schedulati (anomalie, statistiche)
├── db/migrations/           # Migrazioni Phinx
├── docs/                    # Documentazione (questo file)
├── storage/                 # Cache Twig, log, cache versione
└── composer.json            # Dipendenze PHP
```

### 2.3 Front controller e routing

Tutte le richieste HTTP transitano per `public/index.php`, che agisce da front controller. Per ogni prefisso URL (es. `/worksites`, `/billing`, `/users`) viene caricato il middleware (sessione, autenticazione, autorizzazione, CSRF) e poi instradata la richiesta al controller competente.

Il middleware applica in sequenza:

1. **Autenticazione** (cookie sessione `BOB_SESSID` o token persistente).
2. **Header di sicurezza** (CSP, HSTS, X-Frame-Options).
3. **Forzatura cambio password** al primo accesso.
4. **Controllo route ammesse** per utenti scope-aziendale (consorziati).
5. **Validazione CSRF** sui metodi non sicuri (POST).
6. **Logging attività** (page view).
7. **Controllo permessi modulo**.
8. **Risoluzione scope cantiere** (per ruoli con perimetro ristretto).

### 2.4 Database principale (MySQL)

Il database `bob_csm` ospita oltre 50 tabelle. Le principali sono:

| Tabella | Contenuto |
|---|---|
| `bb_users` | Account utente |
| `bb_workers` | Anagrafica operai |
| `bb_companies` | Aziende (consorziate e non) |
| `bb_worksites` | Cantieri / commesse |
| `bb_clients` | Clienti / committenti |
| `bb_offers` / `bb_offer_items` | Offerte commerciali e voci |
| `bb_billing` | Fatture emesse a clienti |
| `bb_extra` | Extra fatturabili su cantiere |
| `bb_ordini` | Ordini alle consorziate |
| `bb_ordini_aziende` | Ordini ad aziende non consorziate |
| `bb_pagamenti_consorziate` | Pagamenti effettuati alle consorziate |
| `bb_presenze` | Presenze operai dipendenti |
| `bb_presenze_consorziate` | Presenze operai consorziate |
| `bb_worker_documents` | Documenti operai (UNILAV, visita medica, ecc.) |
| `bb_company_documents` | Documenti aziende (DURC, polizze, visure) |
| `bb_user_permissions` | Permessi per modulo per utente |
| `bb_user_sessions` / `bb_user_remember_tokens` | Sessioni attive e token "ricordami" |
| `bb_login_attempts` / `bb_login_verifications` | Tentativi e verifica 2FA |
| `bb_user_activity` / `bb_audit_log` | Tracciamento attività e audit |
| `bb_ai_sql_logs` | Log delle interrogazioni BOB AI |
| `phinxlog` | Stato migrazioni applicate |

---

## 3. Modello di business e flussi operativi

### 3.1 Inquadramento giuridico-operativo

Il **Consorzio Soluzione Montaggi** (CS Montaggi) è l'entità che fattura al cliente finale. Al consorzio aderiscono diverse imprese partner ("**consorziate**") che mettono a disposizione personale specializzato per i cantieri di montaggio.

Internamente la base aziende è suddivisa in tre categorie operative, distinte attraverso flag e tabelle dedicate:

| Categoria | Cosa gestisce BOB |
|---|---|
| **Consorziate gestite direttamente** | Tracciamento al singolo operaio: presenze, costi, pasti, alloggi, ordini formali e pagamenti dettagliati. |
| **Consorziate non gestite direttamente** | Solo totali aggregati: importo dell'ordine emesso e pagamento, senza tracciamento delle persone presenti in cantiere. |
| **Aziende non consorziate** | Fornitori esterni (manodopera, servizi). Un ordine mensile di sintesi che riepiloga i cantieri toccati. |

Il modello operativo standard prevede:

1. Acquisizione commessa attraverso un'**offerta**.
2. La commessa diventa un **cantiere** con un proprio importo contrattuale.
3. Sul cantiere lavorano operai dipendenti CS Montaggi, operai delle consorziate, e personale di aziende non consorziate.
4. CS Montaggi **fattura il cliente** secondo SAL o consuntivo (passando dal flusso BOB → Yard → Business).
5. CS Montaggi **emette ordini** alle consorziate (per le quote di competenza) e alle aziende non consorziate (per servizi resi).
6. CS Montaggi **paga** consorziate e aziende secondo gli ordini emessi, dedotte eventuali spese a loro carico (vitto/alloggio).

### 3.2 Flusso ricavi (verso clienti)

```
Offerta (bb_offers)
   ↓ (accettazione)
Cantiere (bb_worksites) — total_offer
   ↓ (esecuzione)
Presenze (bb_presenze + bb_presenze_consorziate)
   ↓ (eventuali integrazioni di scope)
Extra (bb_extra)
   ↓ (preparazione fattura in BOB)
bb_billing (BOB) — flag emessa
   ↓ (push a Yard)
CNT_cantieri_brogliacci (Yard, SQL Server)
   ↓ (sync di Yard verso Business)
Fattura emessa in Business (gestionale contabile ufficiale)
```

La cosiddetta **"emessa reale"** è la presenza della fattura su Yard con flag `emessa=1`, che corrisponde a una fattura ormai propagata e contabilizzata in Business. BOB mostra in interfaccia entrambi gli stati (interno e reale) per consentire il riallineamento operativo: se il flag interno è acceso ma quello reale no, l'amministrazione deve ancora completare l'emissione lato Yard/Business.

### 3.3 Flusso costi (verso fornitori e consorziate)

#### Consorziate

```
Ordine (bb_ordini) — per cantiere
   ↓
Presenze consorziata (bb_presenze_consorziate)
   ↓
Pagamenti (bb_pagamenti_consorziate)
   - importo per (azienda, cantiere)
   - scontati di "spese a carico" (alloggi)
   - opzionalmente associati a un ordine specifico
```

#### Aziende non consorziate

```
Ordine mensile (bb_ordini_aziende) — uno per (azienda, mese)
   ↓ descrizione precompilata con elenco cantieri
   ↓ totale deciso manualmente dall'operatore
   ↓ generazione PDF per invio cartaceo / PEC
```

### 3.4 Flussi di supporto

- **Documenti operai e aziende** — tracciamento scadenze e generazione avvisi automatici.
- **Pianificazione e programmazione** — assegnazione squadre e mezzi ai cantieri.
- **Prenotazioni alloggi** — gestione vitto e alloggio per operai in trasferta.

### 3.5 Riepilogo entità di dominio

| Entità | Cardinalità | Descrizione |
|---|---|---|
| Cliente | 1:N → cantieri | Soggetto a cui CS Montaggi emette fattura |
| Cantiere | 1:N → presenze, fatture, ordini, extra | Commessa specifica |
| Offerta | 1:1 → cantiere (in caso di accettazione) | Documento commerciale preventivo |
| Operaio | 1:N → presenze, documenti | Persona fisica che lavora sui cantieri |
| Azienda | 1:N → operai, documenti | Datore di lavoro dell'operaio (consorziata o no) |
| Fattura | N:1 → cantiere | Documento contabile verso cliente |
| Ordine | N:1 → cantiere o azienda | Documento contabile verso fornitore/consorziata |

---

## 4. Ruoli, profili utente e permessi

### 4.1 Tipologie di utente

BOB distingue quattro profili di accesso:

| Profilo | Descrizione | Accesso |
|---|---|---|
| **Superadmin** | Account fondatore (ID=1) | Accesso totale, bypassa permessi |
| **Internal staff** | Personale CS Montaggi | Accesso modulato dai permessi assegnati |
| **company_viewer** | Referente di una consorziata | Vede solo le proprie aziende e operai |
| **worker** | Operaio con accesso limitato | Solo dashboard personale e cambio password |

### 4.2 Sistema di permessi modulo per modulo

I permessi sono gestiti in modo granulare attraverso la tabella `bb_user_permissions` e configurabili dalla pagina **Utenti → Permessi**. Sono raggruppati per area funzionale:

| Gruppo | Permessi |
|---|---|
| **Generale** | dashboard, users, chat |
| **Visibilità Dati** | view_prices (vede prezzi e costi) |
| **Contabilità e Clienti** | billing, companies, clients, offers, ordini, ordini_aziende, tickets |
| **Cantieri e Operai** | worksites, attendance, pianificazione, bookings |
| **Documenti e Files** | documents, document_alerts, files, share |
| **Mezzi e Attrezzature** | equipment |
| **Programmazione** | programmazione, notif_mezzi_*, notif_trasferta_*, notif_beppe_* |
| **BOB AI - Anomalie** | anomaly_presenze, anomaly_mezzi, anomaly_documenti, anomaly_fatturazione, anomaly_cantieri, anomaly_programmazione, anomaly_squadre, anomaly_statistiche |

L'attivazione/disattivazione del singolo permesso è immediata: alla pagina successiva l'utente vede applicate le nuove regole, senza necessità di logout.

### 4.3 Consorziate (`company_viewer`)

I consorziati che accedono a BOB hanno un'esperienza dedicata:
- Vedono **solo** le aziende a cui sono associati (tabella `bb_user_company_access`).
- Possono gestire i propri operai e documenti, ma non vedono operai di altre consorziate.
- Le richieste verso risorse fuori dal proprio perimetro restituiscono **404** (non 403), per non rivelare l'esistenza di risorse altrui.

### 4.4 Default di sicurezza

- I nuovi utenti partono **senza alcun permesso** (deny-by-default).
- Al primo accesso è obbligatorio impostare una nuova password (flag `must_change_password=1`).
- L'amministratore deve assegnare esplicitamente ogni permesso.

---

## 5. Moduli funzionali

Questo capitolo descrive uno per uno i moduli applicativi presenti in BOB.

### 5.1 Dashboard

**Path:** `/dashboard`
**Permesso:** `dashboard`

Pagina di ingresso con riepilogo operativo della giornata. Mostra:
- Avvisi anomalie pertinenti all'utente (in funzione dei permessi `anomaly_*`).
- Documenti in scadenza.
- Cantieri attivi.
- Statistiche di sintesi.

I consorziati visualizzano un dashboard semplificato: numero di operai attivi e documenti in scadenza per la propria azienda.

### 5.2 Cantieri

**Path:** `/worksites`
**Permesso:** `worksites`

Modulo centrale del sistema. Ogni cantiere rappresenta una commessa accettata dal cliente.

**Stati cantiere:**
- `Bozza` — cantiere creato ma non ancora attivato.
- `In corso` — cantiere attivo.
- `Chiuso` — lavori completati e fatturazione conclusa.

**Tab presenti nella vista dettaglio cantiere:**
- **Dati** — anagrafica (cliente, codice cantiere, importo offerta, date di inizio/fine, modalità a corpo o a consuntivo).
- **Presenze** — registrazione giornaliera operai dipendenti e consorziate.
- **Squadra** — operatori assegnati.
- **Fatturazione** — fatture emesse (BOB + stato reale Yard/Business).
- **Extra** — voci aggiuntive fatturabili.
- **Documenti** — disegni e allegati cantiere.
- **Statistiche** — margine, costi, ricavi (dettagliato in §5.2.1).
- **Versioning** — storico modifiche.

**Attivazione cantiere:** dalla bozza si emette un'email all'amministrazione con link di attivazione che, cliccato, sposta lo stato a "In corso" e crea il record nel flusso Yard.

#### 5.2.1 Tab Statistiche — modello dei costi e dei ricavi

La tab Statistiche calcola in tempo reale **margine** e **andamento economico** del cantiere mediante la classe `WorksiteStats`. Le voci sono raggruppate come segue.

**Costi (sette categorie):**

| Voce | Sorgente | Logica |
|---|---|---|
| **Presenze nostri** | `bb_presenze` (dipendenti CSM e tutto ciò che non è gestito al singolo operaio) | Giornata intera = 1, mezza = 0,5. Valorizzazione: **€ 230 per giornata equivalente**. *Eccezione anti-doppio-conteggio:* se sull'azienda dell'operaio esiste già un ordine formale (`bb_ordini`) per quel cantiere, la presenza non viene conteggiata — l'ordine assorbe già la voce. |
| **Presenze consorziate** | `bb_presenze_consorziate` (solo consorziate gestite direttamente) | Per ogni riga: `quantita × costo_unitario`. Stessa eccezione anti-doppio-conteggio della voce precedente, per `azienda_id`. |
| **Pasti nostri** | `bb_presenze` colonne `pranzo` e `cena` | Per ogni presenza: pranzo/cena `Noi` → prezzo memorizzato sulla presenza; pranzo/cena `Loro` → tariffa fissa **€ 10** a pasto. Esclusione anti-doppio-conteggio come sopra. |
| **Pasti consorziate** | `bb_presenze_consorziate` colonna `pasti` | Somma diretta del valore già memorizzato. |
| **Mezzi sollevamento** | `bb_worksite_lifting` | Tipo "Una Tantum" → `costo_giornaliero × quantità`. Tipo continuativo → conteggio dei giorni distinti di presenza dal `data_inizio` del mezzo, moltiplicato per costo giornaliero e quantità. |
| **Ordini** | `bb_ordini` | Somma di tutti i totali ordini consorziata sul cantiere. |
| **Hotel / alloggi** | `bb_bookings` + `bb_booking_periods` | Per ciascun periodo: giorni × persone × `prezzo_persona`. |

**Ricavi:**

- Cantiere **a corpo** (`is_consuntivo=0`): ricavo = `total_offer` + extra.
- Cantiere **a consuntivo** (`is_consuntivo=1`): ricavo = lavoratori × `prezzo_persona` + extra.

**Indicatori derivati:**
- **Andamento** = ricavi totali − costi totali.
- **Margine percentuale** = andamento ÷ contratto × 100.
- Cantieri "In corso" con margine **negativo** o **inferiore al 10 %** vengono segnalati nell'email automatica giornaliera "Cantieri a rischio" (vedi §9.2).

### 5.3 Offerte

**Path:** `/offers`
**Permesso:** `offers`

Gestione delle offerte commerciali. Ogni offerta include:
- Anagrafica cliente.
- Dettagli importi a corpo (descrizione + prezzo per ogni voce).
- Note e condizioni.
- Posizione (ordinamento delle voci).

**Funzionalità:**
- Creazione, modifica e revisione (revisione conserva lo storico, modifica sovrascrive).
- Esportazione PDF per invio al cliente.
- Possibilità di aggiungere righe dinamicamente.
- Avviso se vengono salvate righe incomplete (descrizione senza prezzo o viceversa).

**Stato:**
- `Bozza` / `Inviata` / `Accettata` / `Rifiutata`.

L'accettazione di un'offerta porta alla generazione di un cantiere collegato.

### 5.4 Fatturazione clienti

**Path:** `/billing` e `/billing/clients`
**Permesso:** `billing`

Modulo che gestisce l'intero ciclo di vita delle fatture verso i clienti, dalla preparazione in BOB fino alla verifica dello stato reale in Yard/Business.

#### Ciclo di vita di una fattura

```
1. Preparazione         → riga in bb_billing (emessa = 0)
2. Push a Yard          → bb_billing.yard_id valorizzato; brogliaccio creato in CNT_cantieri_brogliacci
3. Emissione in Business → Yard contrassegna la voce come emessa = 1
4. Sync verso BOB        → bb_billing.emessa allineato (cron + on-demand)
```

Su una fattura in BOB sono memorizzati: `worksite_id`, `data`, `numero`, `totale_imponibile`, `aliquota_iva`, `articolo_id`, `iva_id`, `descrizione`. Quando l'operatore conferma, BOB chiama `YardWorksiteBilling::insertToBrogliaccio()` che crea la voce contabile in SQL Server e restituisce un `yard_id` salvato in `bb_billing`. Da quel momento il record è "agganciato" al sistema contabile.

#### Sezione "Cantieri Movimentati" — `/billing`

Vista mensile orientata alla **produzione fatture**. Elenca i cantieri che hanno avuto presenze nel mese selezionato con:

- Importo contratto (`total_offer`) ed extra accumulati.
- Fatturato anno corrente (somma `bb_billing.emessa = 1`).
- Residuo teorico da fatturare = (contratto + extra) − fatturato emesso.
- Stato fatturazione **reale** (Yard) per riconciliazione.
- Pulsanti rapidi: nuovo brogliaccio, vai al cantiere, esporta Excel del periodo.

L'operatore tipicamente apre questa sezione a fine mese, identifica i cantieri da fatturare e prepara i brogliacci.

#### Sezione "Fatture per Cliente" — `/billing/clients`

Vista aggregata orientata al **monitoraggio**. Per ciascun cliente:

- Numero fatture **da emettere** YTD e relativo importo.
- Numero fatture **emesse** YTD e relativo importo.

Sopra la lista, quattro card di sintesi:

| Card | Cosa mostra | Sorgente |
|---|---|---|
| **Emesse reale (mese corrente)** | Conteggio + imponibile delle fatture realmente emesse in Yard nel mese corrente | Yard `CNT_cantieri_brogliacci.emessa = 1` |
| **Emesse reale (mese precedente)** | Stesso del precedente, mese passato | Yard |
| **Da emettere YTD** | Fatture in `bb_billing` con `emessa = 0`, anno corrente | BOB |
| **Emesse YTD** | Fatture in `bb_billing` con `emessa = 1`, anno corrente | BOB |

Le card "Emesse reale" sono **cliccabili**: aprono un modale che elenca le fatture del mese, raggruppate per numero documento. Per ogni fattura sono visibili i cantieri toccati e il dettaglio delle voci. Un selettore Anno/Mese consente di consultare qualsiasi periodo passato (la richiesta è AJAX e non ricarica la pagina).

#### Riconciliazione BOB ↔ Yard

Il flag `emessa` in BOB e quello reale in Yard possono divergere temporaneamente (es. la fattura è stata emessa in Business ma BOB non ha ancora ricevuto il sync). Per tenere i due sistemi allineati:

- Un cron orario (`yard_worksite_status_check.php`) sincronizza i flag.
- All'apertura di `/billing` o `/billing/clients` viene chiamato `syncEmessaForClient()` o `syncEmessaFromYardForMovedWorksites()` come refresh on-demand.
- L'operatore vede in interfaccia la differenza e può intervenire.

#### Dettaglio cliente — `/billing/client/{id}`

Pagina di drill-down sul singolo cliente con:
- **Tab "Da emettere"**: lista delle fatture in attesa di emissione, con possibilità di modificarle o emetterle.
- **Tab "Emesse"**: storico paginato delle fatture emesse, con link al brogliaccio Yard.
- **Statistiche annuali**: totali per anno (emesse + da emettere) per analisi.
- **Esportazione Excel** delle fatture da emettere per condivisione con la contabilità.

### 5.5 Pagamenti consorziate

**Path:** `/fatturazione/consorziate`
**Permesso:** `billing`

Modulo di **tracciamento** dei pagamenti verso le aziende consorziate. È importante chiarire la natura del modulo:

> **Nota.** I pagamenti registrati in BOB hanno **scopo gestionale e di reportistica interna**. BOB non emette bonifici, non si interfaccia con sistemi bancari, non genera flussi SEPA e non ha integrazione contabile diretta. La registrazione qui serve solo a **sapere quanto è stato pagato a chi e per cosa**, da confrontare con i totali che la contabilità (Business) ha effettivamente liquidato.

**Schermata principale:** elenca tutte le consorziate con totali aggregati storici (presenze, costo, pagato).

**Schermata di dettaglio (per consorziata + periodo):**
- Riepilogo presenze e costi nel periodo selezionato.
- Per ciascun cantiere movimentato:
  - Presenze (giorni) e costo presenze.
  - Valore ordini emessi.
  - Data dell'ultimo ordine.
  - Già pagato.
  - Spese a carico (alloggi, ecc.).
  - Residuo (ordini − pagato − spese).
  - Importo da pagare (input operatore).
  - **Ordine specifico** a cui imputare il pagamento (selettore).
- Storico pagamenti registrati.
- Esportazione Excel del periodo.

Ogni pagamento può essere collegato esplicitamente a un ordine, per un tracciamento più preciso del residuo.

### 5.6 Ordini consorziata

**Path:** `/ordini`
**Permesso:** `ordini`

Generazione e gestione degli ordini formali emessi alle consorziate.

**Caratteristiche dell'ordine:**
- Riferimento cantiere e consorziata.
- Voci di articolo con quantità, prezzo, unità di misura.
- IVA configurabile (0% / 4% / 10% / 22%).
- Termini di pagamento e note.
- Stato: Bozza / Inviato / Accettato / Rifiutato.

Genera PDF intestato e numerato in modo sequenziale annuale.

### 5.7 Ordini aziende

**Path:** `/ordini-aziende`
**Permesso:** `ordini_aziende`

Modulo dedicato agli ordini emessi ad **aziende non consorziate** (fornitori, prestatori di manodopera esterni).

> **Nota.** Anche questo modulo, come Pagamenti consorziate, è di **tracciamento gestionale**: produce il documento d'ordine in PDF (utilizzabile per invio cartaceo/PEC) e tiene memoria storica, ma non ha effetti contabili automatici.

**Caratteristiche distintive rispetto agli ordini consorziata:**
- Un ordine per (azienda, mese) — non per singolo cantiere.
- Descrizione precompilata automaticamente con l'elenco dei cantieri su cui l'azienda ha lavorato nel mese (estratto da `bb_presenze`).
- Totale deciso manualmente dall'operatore (no voci di articolo).
- Numerazione `OA_YYYY_NNNN`.
- IVA configurabile.

**Funzionalità:**
- Wizard a due step (selezione azienda+periodo → compilazione).
- Pulsante "Rigenera dai cantieri attuali" in modifica per riallineare la descrizione qualora siano state aggiunte presenze successive al primo salvataggio.
- Esportazione PDF intestata Consorzio Soluzione Montaggi.

### 5.8 Presenze

**Path:** `/attendance`
**Permesso:** `attendance`

Cuore operativo del sistema. Registrazione giornaliera per ogni cantiere di:
- Operai presenti (dipendenti CS Montaggi e operai delle consorziate).
- Turno (Intero / Mezzo).
- Pranzo e cena (Loro / Nostri).
- Eventuali anticipi, rimborsi e multe.

**Esportazioni Excel disponibili:**
- **Per Operaio** — riepilogo del singolo dipendente in un periodo.
- **Per Azienda** — riepilogo presenze di tutti gli operai di un'azienda (ordinati per cognome A→Z).
- **Per Committente** — riepilogo per cliente.
- **Bulk operai** — esportazione massiva.

I template Excel utilizzano un layout aziendale predefinito con calcolo automatico di giorni festivi italiani, ferie residue, totali pasti.

### 5.9 Operai e utenti

**Path:** `/users` e `/users/workers`
**Permesso:** `users`

Gestione anagrafica degli operai e degli utenti BOB.

**Operazioni supportate:**
- Inserimento nuovo operaio (dati anagrafici, codice fiscale, azienda, tipologia).
- Modifica anagrafica.
- Cambio azienda (mantiene storico).
- Caricamento foto.
- Creazione di un account utente per operaio (qualora debba accedere al portale).
- Attivazione/disattivazione.
- Eliminazione (soft delete, flag `removed='Y'`).

**Tipologie operaio:**
- `OPERAIO` — manuale standard.
- `IMPIEGATO` — personale d'ufficio.
- `APPRENDISTA` — contratto di apprendistato.
- `LEGALE RAPPRESENTANTE` — titolare/amministratore.

**Pagina Permessi:** `/users/permissions` — assegnazione granulare dei permessi modulo per modulo.

**Pagina Audit Log:** `/users/audit-log` — visualizzazione dello storico azioni.

### 5.10 Aziende

**Path:** `/companies`
**Permesso:** `companies`

Gestione delle aziende registrate. Distinzione fra:
- **Consorziate** (`consorziata=1`) — partner del Consorzio.
- **Non consorziate** (`consorziata=0`) — fornitori esterni.

**Schede azienda:**
- Anagrafica (nome, codice, P.IVA, indirizzo, contatti).
- Documenti aziendali (DURC, visure, polizze).
- Operai associati.
- Utenti collegati (consorziati che possono accedere a BOB).

I consorziati vedono solo le aziende a cui sono associati (vista dedicata `/companies/my`).

### 5.11 Clienti

**Path:** `/clients`
**Permesso:** `clients`

Anagrafica dei committenti. Per ogni cliente:
- Dati fiscali (P.IVA / Codice Fiscale).
- Dati di contatto.
- Cantieri storici.
- Fatture emesse.

### 5.12 Documenti

**Path:** `/documents`
**Permesso:** `documents`

Sistema documentale a due livelli:

**Documenti operaio:**
- *Documenti aziendali (anagrafica lavorativa)*: UNILAV, CCNL, lettera di assunzione.
- *Documenti personali*: carta d'identità, codice fiscale, permesso di soggiorno, patente, visita medica, formazione sicurezza generale e specifica, formazione preposto, attestati uso macchine.

**Documenti azienda:**
- DURC, visura camerale, polizza RC, iscrizione cassa edile, certificazione SOA, POS/DVR, statuto.

**Funzionalità:**
- Caricamento PDF (max 50 MB per file).
- Validazione MIME type.
- Storage cifrato fuori da `public/`.
- Download protetto da controllo permessi.
- Tracciamento date emissione e scadenza.
- Avvisi automatici per documenti scaduti o in scadenza nei 30 giorni.

**Valori speciali per scadenza documento operaio:**
- `INDETERMINATO` — per UNILAV a tempo indeterminato (non scade).
- `LEGALE RAPPRESENTANTE` — per documenti del titolare (validi finché ricopre la carica).

### 5.13 Pianificazione e Programmazione

**Path:** `/pianificazione` e `/programmazione`
**Permessi:** `pianificazione`, `programmazione`

Due moduli complementari:

- **Pianificazione (squadre):** organizzazione delle squadre operaie per cantiere su base settimanale/mensile.
- **Programmazione:** schedulazione di mezzi, trasferte, e annotazioni operative ("info Beppe", "mezzi da scrivere/gestire", "trasferta da scrivere/gestire").

I permessi sono molto granulari (8 sotto-permessi per le notifiche programmazione) per consentire una distribuzione mirata.

### 5.14 Mezzi e attrezzature

**Path:** `/equipment`
**Permesso:** `equipment`

Inventario di mezzi di sollevamento, attrezzature di cantiere e dispositivi. Funzionalità:
- Anagrafica mezzo.
- Storico assegnazioni a cantieri.
- Documenti correlati (libretti, certificazioni).

### 5.15 Prenotazioni alloggi

**Path:** `/bookings`
**Permesso:** `bookings`

Gestione del vitto e alloggio per operai in trasferta su cantieri lontani.

**Per ogni prenotazione:**
- Cantiere e periodo.
- Numero persone.
- Prezzo per persona / giorno.
- Consorziata di riferimento (se a carico della consorziata).
- Flag `a_carico_consorziata=1` se le spese sono dedotte dai pagamenti.

### 5.16 Buoni pasto

**Path:** `/tickets`
**Permesso:** `tickets`

Gestione bigliettini pasto per i dipendenti.

### 5.17 Doc condivisi

**Path:** `/share`
**Permesso:** `share`

Sistema di condivisione documentale interna ed esterna. Permette di generare link condivisibili (con scadenza) per consegnare documenti a soggetti esterni senza accesso diretto a BOB. Upload con strategia chunked (file di grandi dimensioni divisi in blocchi).

### 5.18 BOB AI

**Path:** `/ai/chat`
**Accesso:** allowlist esplicita di utenti

Modulo di interrogazione del database in linguaggio naturale, alimentato da un LLM locale (Ollama, modello Qwen3-30B). Il sistema converte la domanda dell'utente in una query SQL `SELECT`, la esegue, e restituisce il risultato in formato tabellare.

**Funzionalità:**
- Conversazione multi-turno.
- Riconoscimento del contesto utente (ruolo, permessi, visibilità dati economici).
- Esportazione del risultato in Excel formattato.

**Vincoli di sicurezza:**
- Accesso ristretto a una whitelist di username (più Superadmin).
- Tabelle critiche bloccate server-side: `bb_users`, `bb_user_permissions`, `bb_user_sessions`, `bb_login_*`, `bb_audit_log`, `bb_settings`, `bb_user_company_access`, schema interni MySQL.
- Colonne credenziali bloccate (`password`, `token`, `remember_token`, `secret`, `api_key`) anche se rinominate via alias.
- Solo statement `SELECT` ammessi.
- Funzioni di esfiltrazione (`EXTRACTVALUE`, `UPDATEXML`, `BENCHMARK`, `SLEEP`, `OUTFILE`, `LOAD_FILE`) bloccate.
- Messaggi di errore PDO mascherati al chiamante.
- Rate limiting (30 richieste / 5 minuti per utente).

Tutte le interazioni vengono registrate in `bb_ai_sql_logs` per finalità di audit.

---

## 6. Sicurezza e protezione dei dati

### 6.1 Autenticazione

**Login:**
- Password con hash `bcrypt` e verifica contro database HaveIBeenPwned alla creazione.
- Lunghezza minima 8 caratteri.
- Cambio obbligatorio al primo accesso.

**Rate limiting:**
- 5 tentativi falliti per IP per 15 minuti.
- 10 tentativi falliti per username per 15 minuti (defesa contro attacchi distribuiti).
- Backoff esponenziale fra tentativi consecutivi.

**Verifica IP / 2FA:**
- Al primo accesso da un nuovo IP, l'utente riceve via email un codice numerico di 6 cifre.
- Il codice scade in 10 minuti.
- Massimo 5 tentativi per IP per 15 minuti.

**Sessioni:**
- Cookie sessione `BOB_SESSID` (host-only, HttpOnly, Secure, SameSite=Strict).
- Durata: 8 ore.
- Token sessione rotato ad ogni `must_change_password`.
- "Ricordami" supportato con token persistente separato (selector + hash) e rotazione ad ogni utilizzo.

### 6.2 Autorizzazione

**Modello:**
- Permessi modulari archiviati in `bb_user_permissions`.
- Profili di accesso (internal / company_viewer / worker / superadmin) calcolati in `AccessProfileResolver`.
- Visibilità prezzi gestita tramite il permesso dedicato `view_prices`.

**Consorziate (`company_viewer`):**
- Accesso ristretto a un sottoinsieme di route (whitelist URI).
- Verifica scope su ogni risorsa (cantiere, operaio, documento) tramite `assertCompanyScope*Access`.
- Risposte 404 (non 403) sui mancati accessi per non rivelare l'esistenza delle risorse.

### 6.3 CSRF

Tutte le richieste POST sono protette da token CSRF generato per sessione e validato dal middleware `CsrfMiddleware`. Eccezioni puntuali (es. heartbeat analytics) sono dichiarate esplicitamente.

### 6.4 Content Security Policy

L'applicativo emette un header CSP restrittivo:
- `script-src 'self' 'nonce-...'` — nessuno script inline non firmato.
- `style-src 'self' 'unsafe-inline'` — necessario per la libreria template.
- `frame-src` limitato a domini fidati.
- `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`.

### 6.5 Protezione dei file

- Upload validati lato server (MIME type effettivo, non solo estensione).
- Limite 50 MB per upload (configurabile via `UPLOAD_MAX_MB` in `.env`).
- File memorizzati fuori dalla document root, in directory cifrata.
- Download autenticato passa attraverso controlli di scope.

### 6.6 Logging e audit

| Tabella | Cosa registra |
|---|---|
| `bb_user_activity` | Page view dell'utente |
| `bb_audit_log` | Azioni significative (creazione/modifica/cancellazione utenti, permessi, documenti) |
| `bb_login_attempts` | Tentativi falliti di login |
| `bb_login_verifications` | Codici 2FA emessi |
| `bb_ai_sql_logs` | Interrogazioni BOB AI (utente, domanda, query generata, righe restituite) |
| `bb_anomaly_email_log` | Email inviate dal sistema anomalie |

I log sono interrogabili dalla pagina **Utenti → Audit Log** con filtri per data, utente e tipo azione.

### 6.7 Rendering 404 vs 403

I controller di risorse accessibili a `company_viewer` (documenti, foto operai, foto utenti) restituiscono in ogni caso una pagina 404 personalizzata, sia quando la risorsa non esiste sia quando il chiamante non ha accesso. Questo evita la divulgazione di informazioni sull'esistenza di ID validi a utenti potenzialmente ostili.

### 6.8 Migrazione e gestione schema

Le modifiche allo schema avvengono esclusivamente attraverso **Phinx**:
- File migrazione versionati in `db/migrations/<timestamp>_<descrizione>.php`.
- Tracking automatico in `phinxlog`.
- Convenzione: ogni migrazione è idempotente (uso di `hasColumn`, `hasIndexByName`, `hasTable`).
- Comandi:
  - `composer migrate` — applica le migrazioni pending.
  - `composer migrate:status` — mostra stato.
  - `composer migrate:rollback` — annulla l'ultima.

---

## 7. Integrazioni esterne

### 7.1 Catena Yard → Business

La contabilità ufficiale dell'azienda è gestita da **Business**, gestionale di terzi. BOB non scrive direttamente in Business: scrive in un database SQL Server intermedio chiamato **Yard**, che funge da middleware. È Yard a propagare le voci di brogliaccio verso Business e a riportare a BOB lo stato di emissione effettiva.

**Tabelle Yard utilizzate:**
- `CNT_cantieri_brogliacci` — voci di brogliaccio (riga di fattura preliminare).
- Anagrafiche di consultazione (clienti, codici IVA, articoli).

**Interazioni BOB ↔ Yard:**
- Inserimento di una nuova voce brogliaccio quando in BOB si registra una fattura programmata.
- Aggiornamento del brogliaccio se la fattura BOB viene modificata.
- Soft-delete (`obsoleto=1`) anziché cancellazione fisica.
- Lettura del flag `emessa` per determinare lo stato reale di una fattura.

**Resilienza:** la connessione SQL Server è separata dalla connessione MySQL. Se Yard è irraggiungibile, BOB resta pienamente operativo: le sezioni che dipendono da Yard mostrano zero o un messaggio di indisponibilità, senza bloccare le altre funzionalità.

### 7.2 Email (SMTP)

Tutte le comunicazioni email passano da PHPMailer su connessione SMTP autenticata. Il sistema supporta:
- Email transazionali (verifica IP, cambio password).
- Email schedulate (avvisi documenti, anomalie giornaliere).
- Email operative (attivazione cantiere, allerta cantieri a rischio).

Mittenti differenziati per tipologia di mail (`alerts`, `notifications`, `transactional`).

### 7.3 Ollama (LLM locale)

BOB AI utilizza un'istanza locale di Ollama, raggiungibile via API REST. Configurabile tramite variabili d'ambiente:
- `OLLAMA_URL` — endpoint del servizio.
- `MODEL` — nome del modello (default: Qwen3-30B-A3B-Q4_K_M).

L'utilizzo di un LLM locale garantisce che **nessun dato aziendale lasci mai l'infrastruttura interna**.

---

## 8. Operazioni di sistema

### 8.1 Variabili d'ambiente

Configurazione tramite file `.env` (caricato da Dotenv). Variabili principali:

```ini
APP_ENV=production           # production / dev / staging
APP_URL=https://bob.csmontaggi.it

DB_HOST=localhost
DB_NAME=bob_csm
DB_USER=...
DB_PASS=...
DB_PORT=3306

SQLSRV_HOST=...              # Yard
SQLSRV_DB=...
SQLSRV_USER=...
SQLSRV_PASS=...

MAIL_HOST=...
MAIL_USER=...
MAIL_PASS=...

OLLAMA_URL=http://192.168.1.10:8000/v1/chat/completions
MODEL=Qwen3-30B-A3B-Q4_K_M.gguf

UPLOAD_MAX_MB=50
CLOUD_ROOT=/var/www/cloud
```

### 8.2 Procedura di deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
composer migrate
rm -f storage/cache/version.txt
rm -rf storage/twig-cache/*
sudo systemctl reload php8-fpm
```

### 8.3 Cron jobs

Tutti i job schedulati di BOB partono **alle ore 06:00** della mattina, in modo che le email di sintesi siano già nella casella dei destinatari all'inizio della giornata lavorativa.

| Script | Funzione |
|---|---|
| `includes/cron/ai_anomaly_check.php` | Controlla anomalie operative e invia digest email per modulo |
| `includes/services/recalculate_worksite_stats.php` | Ricalcola margini cantieri e invia l'alert "Cantieri a rischio" |
| `includes/cron/yard_worksite_status_check.php` | Sincronizza stato cantieri da Yard |
| `includes/cron/document_expiry_alerts.php` | Allerta documenti scaduti o in scadenza |
| `includes/cron/programmazione_deadline_check.php` | Allerta scadenze programmazione |

I cron utilizzano l'utente di sistema `www-data` (o equivalente in base alla configurazione del server) con percorso PHP CLI assoluto.

### 8.4 Backup

Politica attualmente in essere:

- **Backup completo del database MySQL** ogni notte dei giorni feriali alle **ore 23:00**.
- I dump vengono scritti in una cartella **NFS** dedicata, raggiungibile **esclusivamente dal server di produzione** (l'host non espone la share ad altre macchine, e l'NFS export è limitato al singolo IP).
- I file aziendali (cartella `cloud/` con documenti e disegni) sono inclusi nello stesso ciclo di backup.
- Il sistema Yard / Business è gestito separatamente dall'amministrazione contabile, con la propria politica di backup.
- Il codice sorgente è ospitato su repository remoto (GitHub).

I backup nei weekend non vengono eseguiti perché l'attività di sistema è marginale; l'ultimo backup utile resta quindi quello del venerdì sera, sufficiente a garantire continuità in caso di incidente.

### 8.5 Versioning applicativo

Il numero di versione mostrato nella sidebar è ricavato automaticamente da `git describe --tags --always`. Le versioni sono taggate semanticamente (`vX.Y.Z`) sul branch `main`.

---

## 9. Audit, monitoraggio e notifiche

### 9.1 Email automatiche giornaliere

Sistema di anomalie quotidiane (mattina) costruito su `AnomalyCheckerService`:

- **Anomalie presenze** — operai con presenze incoerenti, doppie, o sospette.
- **Anomalie mezzi** — mezzi in cantiere senza assegnazione formale.
- **Anomalie documenti** — documenti scaduti o in scadenza imminente.
- **Anomalie fatturazione** — cantieri con presenze ma senza fatture da molto tempo.
- **Anomalie cantieri** — cantieri "In corso" inattivi o con problemi anagrafici.
- **Anomalie programmazione** — squadre o trasferte non programmate.
- **Anomalie squadre** — operai non assegnati a squadre attive.
- **Statistiche cantiere** — cantieri con margine negativo o margine basso (< 10%).

Ogni utente riceve solo le anomalie per cui ha il permesso `anomaly_*` attivo. Il primo invio assoluto a un nuovo utente è una mail di benvenuto introduttiva.

### 9.2 Email "Cantieri a rischio"

Email separata generata da `WorksiteMarginService` che evidenzia:
- Cantieri "In corso" con margine **negativo** (rosso).
- Cantieri "In corso" con margine **basso** (< 10%, arancione).

Destinatario:
- In **produzione**: `info@csmontaggi.it`.
- In **dev/staging**: `alion@csmontaggi.it`.

L'email include link diretti al dettaglio cantiere e una tabella con codice, cliente, contratto, margine e percentuale.

### 9.3 Notifiche in-app

Le anomalie sono visualizzate anche in dashboard come notifiche, con possibilità di:
- Marcatura come letta.
- Filtro per modulo.
- Storico paginato.

---

## 10. Glossario

| Termine | Definizione |
|---|---|
| **BOB** | Acronimo del gestionale (denominazione interna) |
| **Yard** | Database SQL Server intermedio fra BOB e Business — middleware contabile |
| **Business** | Gestionale di contabilità ufficiale (terze parti) — emette le fatture reali |
| **Cantiere** | Commessa attiva su cui si registrano presenze |
| **Cliente / Committente** | Soggetto a cui CS Montaggi emette fattura |
| **Consorziata** | Azienda partner del Consorzio Soluzione Montaggi |
| **Azienda non consorziata** | Fornitore esterno (servizi, manodopera) |
| **Operaio** | Persona fisica registrata in `bb_workers` |
| **Utente** | Account di accesso a BOB (`bb_users`) |
| **Offerta** | Documento commerciale preventivo |
| **Cantiere in bozza / in corso / chiuso** | Stati del cantiere |
| **Brogliaccio** | Voce contabile su SQL Server (Yard) precursore della fattura |
| **emessa (BOB)** | Flag che indica l'avvenuta registrazione della fattura in BOB |
| **emessa reale (Yard)** | Flag della fattura effettivamente emessa nel sistema contabile |
| **SAL** | Stato Avanzamento Lavori |
| **DURC** | Documento Unico di Regolarità Contributiva |
| **UNILAV** | Comunicazione Obbligatoria di assunzione |
| **Squadra** | Gruppo di operai assegnati a un cantiere |
| **Presenza** | Registrazione giornaliera di lavoro su un cantiere |
| **Extra** | Voce aggiuntiva fatturabile su un cantiere |
| **Residuo** | Differenza fra valore ordini e somma pagamenti |
| **Spese a carico** | Costi (es. alloggio) decurtati dai pagamenti consorziata |
| **Permesso modulo** | Autorizzazione granulare per accedere a una funzionalità |
| **company_viewer** | Profilo utente per consorziate |
| **Superadmin** | Account fondatore (ID=1), accesso totale |
| **Phinx** | Strumento di migrazione schema database |
| **CSP** | Content Security Policy (protezione XSS) |
| **CSRF** | Cross-Site Request Forgery (token di sicurezza POST) |
| **2FA** | Autenticazione a due fattori (codice email per nuovo IP) |

---

## 11. Allegati

### 11.1 Schema dei flussi principali

```
                ┌─────────────┐
                │   CLIENTE   │
                └──────┬──────┘
                       │ accetta offerta
                       ▼
              ┌────────────────┐
              │    OFFERTA     │
              └────────┬───────┘
                       │
                       ▼
              ┌────────────────┐         ┌──────────────────┐
              │    CANTIERE    │◄────────│      EXTRA       │
              └────────┬───────┘         └──────────────────┘
                       │
                       │ esecuzione lavori
                       │
        ┌──────────────┴──────────────┐
        ▼                             ▼
  ┌──────────────────┐        ┌────────────────────────┐
  │   bb_presenze    │        │ bb_presenze_consorziate│
  │ (dipendenti CSM, │        │ (consorziate gestite   │
  │  + tutto ciò che │        │  direttamente, con     │
  │  non è gestito a │        │  tracciamento operai)  │
  │  livello operaio)│        │                        │
  └──────────┬───────┘        └────────────┬───────────┘
             │                             │
             ▼                             ▼
   ┌──────────────────┐          ┌──────────────────────┐
   │  ORDINE AZIENDA  │          │ ORDINE CONSORZIATA   │
   │ (mensile, totale)│          │ (per cantiere, voci) │
   └─────────┬────────┘          └──────────┬───────────┘
             │                             │
             ▼                             ▼
   ┌──────────────────┐          ┌──────────────────────┐
   │  PAGAMENTO ext.  │          │ PAGAMENTO CONSORZIATA│
   │   (tracking)     │          │  (tracking)          │
   └──────────────────┘          └──────────────────────┘

                       ▼ (in parallelo, lato ricavi)
              ┌────────────────┐
              │   bb_billing   │ ← preparazione fattura in BOB
              └────────┬───────┘
                       │ scrittura
                       ▼
              ┌────────────────┐
              │ Yard SQL Server│ ← middleware
              │ (brogliaccio)  │
              └────────┬───────┘
                       │ propagazione
                       ▼
              ┌────────────────┐
              │    BUSINESS    │ ← gestionale contabile
              │ (fattura reale)│
              └────────────────┘
```

### 11.2 Validità documenti operaio (default applicativo)

Defaults precompilati al caricamento di un nuovo documento operaio (definiti in `public/assets/js/views/documents/documenti_aziendali.js`). L'operatore può sempre sovrascrivere il valore.

| Tipologia documento | Validità default |
|---|---|
| Verbale consegna DPI | 12 mesi |
| Visita medica | 12 mesi |
| Formazione sicurezza | 60 mesi (5 anni) |
| Lavori in quota DPI | 60 mesi |
| Piattaforma (PLE) | 60 mesi |
| Carrello elevatore | 60 mesi |
| Braccio telescopico | 60 mesi |
| Preposto | 24 mesi |
| Antincendio | 60 mesi |
| Primo soccorso | 36 mesi |
| Gru a torre | 60 mesi |
| Gru mobile | 60 mesi |
| Saldatura | 60 mesi |

Per documenti senza una scadenza fissa (UNILAV a tempo indeterminato, documenti del legale rappresentante) il campo scadenza ammette i valori speciali `INDETERMINATO` o `LEGALE RAPPRESENTANTE`, esclusi dagli avvisi di scadenza.

### 11.3 Validità documenti azienda (default applicativo)

Defaults precompilati al caricamento di un nuovo documento aziendale (definiti in `public/assets/js/views/companies/company_details.js`).

| Tipologia documento | Validità default |
|---|---|
| RLS (Rappresentante Lavoratori Sicurezza) | Senza scadenza fissa (31/12/2099) |
| RSPP (Responsabile Servizio Prevenzione Protezione) | Senza scadenza fissa |
| Attestato RSPP | 60 mesi |
| Attestato RLS | 12 mesi |
| DVR (Documento Valutazione Rischi) | Senza scadenza fissa |
| Visura camerale | 6 mesi |
| Patente a crediti | Senza scadenza fissa |
| Nomina primo soccorso | 12 mesi |
| Nomina medico competente | Senza scadenza fissa |
| Nomina preposto | 12 mesi |
| Nomina antincendio | 12 mesi |
| DURC | 4 mesi |
| DOMA | 12 mesi |
| Dichiarazione possesso requisiti tecnico-professionali | 12 mesi |
| Dichiarazione informazione e formazione | 12 mesi |
| Dichiarazione conformità attrezzature | 12 mesi |
| Dichiarazione art. 14 | 12 mesi |
| Assicurazione | 12 mesi |

I documenti senza scadenza vengono memorizzati con data fittizia `31/12/2099` per uniformità tecnica; l'interfaccia mostra "nessuna scadenza fissa" e gli avvisi non li includono mai.

### 11.4 Riepilogo permessi disponibili

Elenco completo dei permessi configurabili dalla pagina **Utenti → Permessi**:

```
Generale
  - dashboard
  - users
  - chat

Visibilità Dati
  - view_prices

Contabilità e Clienti
  - billing, companies, clients, offers, ordini
  - ordini_aziende, tickets

Cantieri e Operai
  - worksites, attendance, presenze (legacy)
  - pianificazione, bookings

Documenti e Files
  - documents, document_alerts, files, share

Mezzi e Attrezzature
  - equipment

Programmazione
  - programmazione
  - notif_mezzi_scrivere, notif_mezzi_azione
  - notif_trasferta_scrivere, notif_trasferta_azione
  - notif_beppe_scrivere, notif_beppe_azione

BOB AI - Anomalie
  - anomaly_presenze, anomaly_mezzi, anomaly_documenti
  - anomaly_fatturazione, anomaly_cantieri
  - anomaly_programmazione, anomaly_squadre
  - anomaly_statistiche
```

### 11.5 Convenzioni di numerazione

| Documento | Formato | Esempio |
|---|---|---|
| Numero offerta | `N.YY` (sequenza per anno, anno a due cifre) | `42.26` |
| Revisione offerta | `N.YY R<k>` (revisioni successive di un'offerta esistente) | `42.26 R1`, `42.26 R2` |
| Numero ordine consorziata | Numerico sequenziale gestito dal modulo Ordini | — |
| Numero ordine azienda | `OA_YYYY_NNNN` (sequenza per anno, zero-padded) | `OA_2026_0008` |
| Numero fattura cliente | Assegnato lato Yard / Business (contabilità) | — |
| Codice cantiere | `C{YY}-{NNN}` generato automaticamente alla creazione (sequenza per anno) | `C26-042` |

---

*Fine del documento.*

*Per richieste di integrazione, segnalazione di errori o proposte di modifica al presente manuale, contattare il responsabile IT interno.*
