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

**BOB** è il gestionale operativo sviluppato internamente per il **Consorzio Soluzione Montaggi** e per la società operativa **CS Montaggi** (P.IVA 03584711208 — Via Bruno Tosarelli 322, 40055 Villanova di Castenaso BO). L'applicativo nasce per coprire l'intero ciclo operativo dell'attività di montaggi industriali, dalla preventivazione fino alla fatturazione e al pagamento delle consorziate.

BOB **non sostituisce** il sistema contabile (denominato internamente *Yard*, basato su SQL Server). I due sistemi coesistono in modo sinergico: BOB è il livello operativo, Yard è il livello contabile-amministrativo. La comunicazione fra i due è gestita tramite sincronizzazione bidirezionale dei dati di fatturazione.

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
| Persistenza accessoria | **SQL Server** (Yard) | Lettura stato fatturazione + scrittura brogliacci |
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

CS Montaggi è la società operativa che fattura ai clienti. Il **Consorzio Soluzione Montaggi** è l'aggregato giuridico cui aderiscono diverse imprese partner ("**consorziate**"), che mettono a disposizione personale specializzato per i cantieri di montaggio. Il modello operativo prevede:

1. CS Montaggi acquisisce la commessa dal cliente attraverso un'**offerta**.
2. La commessa diventa un **cantiere** con un proprio importo contrattuale.
3. Sul cantiere lavorano operai dipendenti CS Montaggi, operai delle consorziate, e talvolta personale di **aziende non consorziate** (fornitori esterni di manodopera o servizi).
4. CS Montaggi **fattura il cliente** secondo SAL o consuntivo.
5. CS Montaggi **emette ordini** alle consorziate (per le quote di loro competenza) e alle aziende non consorziate (per servizi resi).
6. CS Montaggi **paga** consorziate e aziende secondo gli ordini emessi, dedotte eventuali spese a loro carico.

### 3.2 Flusso ricavi (verso clienti)

```
Offerta (bb_offers)
   ↓ (accettazione)
Cantiere (bb_worksites) — total_offer
   ↓ (esecuzione)
Presenze (bb_presenze + bb_presenze_consorziate)
   ↓ (eventuali integrazioni di scope)
Extra (bb_extra)
   ↓ (emissione)
Fatture (bb_billing) → emessa flag (BOB)
                    → emessa_reale (Yard, contabilità)
```

Il valore della fattura emessa "reale" risiede nel sistema **Yard** (tabella `CNT_cantieri_brogliacci`). BOB legge questa informazione e la mostra accanto al flag interno per consentire il riallineamento.

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
- **Dati** — anagrafica (cliente, codice cantiere, importo offerta, date di inizio/fine).
- **Presenze** — registrazione giornaliera operai dipendenti e consorziate.
- **Squadra** — operatori assegnati.
- **Fatturazione** — fatture emesse (BOB + Yard).
- **Extra** — voci aggiuntive fatturabili.
- **Documenti** — disegni e allegati cantiere.
- **Statistiche** — margine, costi, ricavi.
- **Versioning** — storico modifiche.

**Attivazione cantiere:** dalla bozza si emette un'email all'amministrazione con link di attivazione che, cliccato, sposta lo stato a "In corso" e crea il record nel sistema Yard.

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

Modulo per il monitoraggio delle fatture verso clienti.

**Sezione "Cantieri Movimentati":** elenca i cantieri con presenze nel mese selezionato e mostra:
- Importo offerta + extra.
- Fatturato (somma fatture emessa=1).
- Residuo da fatturare.
- Stato fatturazione reale (Yard).

**Sezione "Fatture per Cliente":** raggruppa le fatture per cliente con KPI:
- **Emesse reale (mese corrente)** — fatture emesse in Yard nel mese corrente, con drill-down dettagliato.
- **Emesse reale (mese precedente)** — confronto periodo.
- **Da emettere YTD** — fatture programmate ma non ancora emesse.
- **Emesse YTD** — fatture emesse anno corrente.

Il selettore mese permette di consultare il dettaglio di qualsiasi mese passato attraverso un modale che raggruppa le voci di brogliaccio (Yard) per numero fattura, mostrando per ciascuna fattura i cantieri toccati e il dettaglio delle voci.

### 5.5 Pagamenti consorziate

**Path:** `/fatturazione/consorziate`
**Permesso:** `billing`

Modulo dedicato alla gestione dei pagamenti verso le aziende consorziate.

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

### 7.1 Yard ERP (SQL Server)

Il sistema contabile dell'azienda è basato su SQL Server (database denominato Yard). BOB legge e scrive su due tabelle principali:

- **`CNT_cantieri_brogliacci`** — voci di brogliaccio (precursori delle fatture).
- Altre tabelle di consultazione (clienti, IVA, articoli).

**Interazioni:**
- Sincronizzazione bidirezionale dello stato `emessa` delle fatture.
- Inserimento brogliacci alla creazione di una fattura.
- Aggiornamento brogliacci alla modifica di una fattura.
- Soft-delete tramite flag `obsoleto=1`.

La connessione SQL Server è separata dalla connessione MySQL. In caso di indisponibilità del server SQL, BOB resta operativo (le funzionalità che dipendono da Yard mostrano valori zero o messaggi di indisponibilità).

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

| Script | Frequenza tipica | Funzione |
|---|---|---|
| `includes/cron/ai_anomaly_check.php` | giornaliera, mattino | Controlla anomalie e invia digest email per modulo |
| `includes/services/recalculate_worksite_stats.php` | giornaliera, mattino | Ricalcola margini cantieri e invia alert "cantieri a rischio" |
| `includes/cron/yard_worksite_status_check.php` | oraria | Sincronizza stato cantieri da Yard |
| `includes/cron/document_expiry_alerts.php` | giornaliera | Allerta documenti in scadenza |
| `includes/cron/programmazione_deadline_check.php` | giornaliera | Allerta scadenze programmazione |

I cron utilizzano l'utente di sistema `www-data` o equivalente, con percorso PHP CLI assoluto.

### 8.4 Backup

Politica raccomandata:
- **Database MySQL**: dump giornaliero completo + binlog incrementale.
- **Cloud storage** (`/cloud`): rsync giornaliero su NAS/disco esterno.
- **Yard SQL Server**: backup gestito separatamente dall'IT contabile.
- **Codice sorgente**: hosting su repository remoto (GitHub).

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
| **Yard** | Sistema contabile esterno (SQL Server) |
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
              │    CANTIERE    │◄────────│  EXTRA / SAL     │
              └────────┬───────┘         └──────────────────┘
                       │
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
  ┌──────────┐ ┌──────────────┐ ┌──────────────┐
  │ PRESENZE │ │  PRESENZE    │ │  PRESENZE    │
  │  CSM     │ │ CONSORZIATE  │ │ AZIENDE NON  │
  │          │ │              │ │ CONSORZIATE  │
  └──────────┘ └──────┬───────┘ └──────┬───────┘
                      │                │
                      ▼                ▼
              ┌──────────────┐ ┌──────────────────┐
              │   ORDINE     │ │  ORDINE AZIENDA  │
              │ CONSORZIATA  │ │   (mensile)      │
              └──────┬───────┘ └──────┬───────────┘
                     │                │
                     ▼                ▼
              ┌──────────────┐ ┌──────────────────┐
              │ PAGAMENTO    │ │   PAGAMENTO      │
              │ CONSORZIATA  │ │   AZIENDA        │
              └──────────────┘ └──────────────────┘

                       │
                       ▼
              ┌────────────────┐         ┌──────────────────┐
              │  FATTURA       │────────►│   YARD (SQL)     │
              │  CLIENTE       │  sync   │  brogliaccio     │
              └────────────────┘         └──────────────────┘
```

### 11.2 Tipologie documento operaio

| Categoria | Documento | Validità tipica |
|---|---|---|
| Anagrafica | UNILAV | Durata contratto |
| Anagrafica | CCNL | Aggiornato a CCNL applicato |
| Personale | Carta d'identità | 10 anni |
| Personale | Passaporto | 10 anni |
| Personale | Codice fiscale (tessera sanitaria) | 6 anni |
| Personale | Permesso di soggiorno | Variabile |
| Personale | Patente di guida | 10 anni (B), 5 anni (C/D) |
| Sanità | Visita medica / Idoneità sanitaria | Annuale o biennale (dipende da DVR) |
| Sicurezza | Formazione generale (4 ore) | 5 anni |
| Sicurezza | Formazione specifica (4/8/12 ore) | 5 anni |
| Sicurezza | Formazione preposto | 5 anni |
| Sicurezza | Formazione DPI 3a categoria | Variabile |
| Macchine | Patentino PLE / Gru / Muletto | 5 anni |

### 11.3 Tipologie documento azienda

| Documento | Validità tipica |
|---|---|
| DURC | 4 mesi |
| Visura camerale | Aggiornamento annuale raccomandato |
| Polizza RC | Annuale |
| Iscrizione cassa edile | Variabile |
| Certificazione SOA | Triennale + revisione quinquennale |
| POS / DVR | Aggiornamento al variare delle condizioni |
| Statuto / Atto costitutivo | Permanente |

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
| Numero offerta | Sequenziale annuale | `45/2026` |
| Numero ordine consorziata | Sequenziale annuale | `127/2026` |
| Numero ordine azienda | `OA_YYYY_NNNN` | `OA_2026_0008` |
| Numero fattura cliente | Sequenziale Yard | gestito da contabilità |
| Codice cantiere | Operatore-definito | `2026-MILANO-001` |

---

*Fine del documento.*

*Per richieste di integrazione, segnalazione di errori o proposte di modifica al presente manuale, contattare il responsabile IT interno.*
