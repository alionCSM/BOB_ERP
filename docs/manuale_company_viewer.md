# Manuale d'uso BOB — Aziende Consorziate

**Destinatari:** utenti con ruolo *Visualizzatore Aziendale* (Company Viewer).
**Scopo:** gestire le proprie aziende, gli operai e i documenti obbligatori sul portale BOB.

---

## 1. Accesso al portale

1. Apri il sito BOB indicato dall'amministratore (es. `https://bob.csmontaggi.it`).
2. Inserisci **username** e **password** ricevuti via email.
3. Al **primo accesso** il sistema ti chiede di cambiare la password:
   - minimo 8 caratteri
   - non deve essere una password già nota in violazioni pubbliche
   - usa caratteri misti (lettere, numeri, simboli)
4. Se accedi da un nuovo dispositivo / IP, riceverai via email un **codice a 6 cifre** da inserire per confermare l'accesso. Il codice scade dopo 10 minuti.

> **Suggerimento:** abilita "Ricordami" solo su dispositivi personali. Mai su computer condivisi.

---

## 2. Cosa vedi nel menu

Il menu laterale per un Visualizzatore Aziendale mostra:

| Voce | Cosa fa |
|---|---|
| **Le Mie Aziende** | Elenco delle aziende a cui hai accesso. Cliccando su una si apre la scheda completa. |
| **Operai** | Elenco degli operai delle tue aziende, con scadenze documenti. |
| **Nuovo Operaio** | Inserisci un nuovo operaio. |
| **Logout** | Esci dal portale. |

Sezioni come *Cantieri*, *Fatturazione*, *Presenze*, *Pianificazione* non sono visibili: sono riservate al personale interno.

---

## 3. Le Mie Aziende

Cliccando su un'azienda dall'elenco si apre la **scheda azienda** con quattro aree:

1. **Anagrafica** — nome, codice, tipo (consorziata o no), stato attivo/inattivo. *In sola lettura: solo l'amministratore può modificarli.*
2. **Documenti aziendali** — visura camerale, DURC, polizze, ecc. Vedi §5.
3. **Operai** — lista degli operai associati a questa azienda.
4. **Utenti collegati** — chi può vedere questa azienda dal portale.

---

## 4. Gestione operai

### 4.1 Inserire un nuovo operaio

Menu → **Nuovo Operaio** → compila i campi:

| Campo | Obbligatorio | Note |
|---|---|---|
| Nome | sì | |
| Cognome | sì | |
| Data di nascita | sì | formato `gg/mm/aaaa` |
| Luogo di nascita | sì | |
| Codice fiscale | sì | 16 caratteri, validato dal sistema |
| Email | no | |
| Telefono | no | |
| Azienda | sì | scegli fra le aziende a cui hai accesso |
| Tipologia | sì | vedi tabella sotto |
| Data inizio rapporto | sì | formato `gg/mm/aaaa` |

#### Tipologia operaio (`type_worker`)

| Valore | Quando usarlo |
|---|---|
| **OPERAIO** | Lavoratore manuale standard. |
| **IMPIEGATO** | Personale d'ufficio. |
| **APPRENDISTA** | Contratto di apprendistato. |
| **LEGALE RAPPRESENTANTE** | Titolare / amministratore dell'azienda (non operaio in senso tecnico, ma deve essere presente in BOB per i documenti). |

> **Importante:** il **codice fiscale** non può essere duplicato. Se il sistema dice "operaio già presente", verifica se esiste già nell'azienda corretta — non crearne uno nuovo.

### 4.2 Modificare un operaio

Operai → click sul nome dell'operaio → **Modifica**.

- I dati anagrafici si modificano direttamente.
- Per cambiare azienda usa **Cambia Azienda** (vedi §4.3).
- Per disattivare un operaio (es. dimissioni, fine cantiere) usa il toggle **Stato**.

### 4.3 Cambiare l'azienda di un operaio

Quando un operaio passa da una consorziata all'altra (entrambe a cui hai accesso):

1. Apri la scheda operaio → **Cambia Azienda**.
2. Scegli la nuova azienda dall'elenco.
3. Inserisci:
   - **Ruolo** (es. OPERAIO)
   - **Data inizio** nella nuova azienda — formato `gg/mm/aaaa`
   - **Data fine** nella vecchia azienda — facoltativa, se non la metti il sistema la calcola come *inizio nuova - 1 giorno*
4. Salva.

Il sistema mantiene lo storico: nella scheda operaio vedi sempre la cronologia delle aziende.

> Non puoi spostare un operaio in un'azienda a cui non hai accesso. Se devi farlo, contatta l'amministratore.

### 4.4 Creare un account utente per un operaio

Solo per operai/impiegati che devono accedere al portale (raro per i Visualizzatori Aziendali). Scheda operaio → **Crea Account**. Il sistema genera username e password temporanea da consegnare all'utente.

---

## 5. Documenti

### 5.1 Documenti azienda (DURC, visure, polizze…)

Apri **Le Mie Aziende → [nome azienda] → Documenti aziendali → Carica documento**.

| Campo | Obbligatorio | Note |
|---|---|---|
| Tipo documento | sì | scegli dall'elenco standard |
| Data emissione | sì | `gg/mm/aaaa` |
| Data scadenza | sì | `gg/mm/aaaa` |
| File | sì | PDF, max 10 MB |

**Tipi documenti aziendali tipici:**
- **DURC** — Documento Unico di Regolarità Contributiva. Validità di norma 4 mesi.
- **Visura camerale** — non scade ma va aggiornata almeno annualmente.
- **Polizza RC** — responsabilità civile.
- **Iscrizione cassa edile** — se applicabile.
- **Certificazione SOA** — per appalti pubblici.
- **POS / DVR** — Piano Operativo di Sicurezza, Documento di Valutazione Rischi.
- **Statuto** / **Atto costitutivo** — non scade.

### 5.2 Documenti operaio

Aperta la scheda operaio, ci sono **due sezioni** documenti:

#### **Documenti Aziendali** (anagrafica lavorativa)

- **UNILAV** — Comunicazione Obbligatoria di assunzione. Va caricata per ogni nuova assunzione.
  - **Data emissione**: data di sottoscrizione del contratto.
  - **Data scadenza**: scrivi la **data di fine contratto** se è a tempo determinato. Se il rapporto è a tempo indeterminato, scrivi `INDETERMINATO` nel campo scadenza.
- **CCNL** — copia del contratto collettivo applicato (se richiesto).
- **Lettera di assunzione**.
- **Per LEGALE RAPPRESENTANTE**: nel campo scadenza scrivi `LEGALE RAPPRESENTANTE` (non scade — finché ricopre la carica).

#### **Documenti Personali**

- **Carta d'identità / Passaporto** — `gg/mm/aaaa` di scadenza.
- **Codice fiscale** (tessera sanitaria) — `gg/mm/aaaa` di scadenza.
- **Permesso di soggiorno** (se applicabile) — `gg/mm/aaaa`.
- **Patente di guida** (se richiesta dalle mansioni) — `gg/mm/aaaa`.
- **Visita medica / Idoneità sanitaria** — `gg/mm/aaaa`. Solitamente annuale o biennale a seconda del DVR.
- **Formazione sicurezza generale** (4 ore) — `gg/mm/aaaa` (validità 5 anni).
- **Formazione sicurezza specifica** (varia: 4/8/12 ore in base al rischio) — `gg/mm/aaaa` (validità 5 anni).
- **Formazione preposto / dirigente** (se applicabile) — `gg/mm/aaaa`.
- **Formazione DPI di 3a categoria** (es. lavori in quota, spazi confinati) — `gg/mm/aaaa`.
- **Attestato uso macchine** (PLE, gru, muletto) — se necessario.

> **Formato data:** sempre `gg/mm/aaaa` (es. `15/03/2027`). I valori speciali ammessi nel campo scadenza sono `INDETERMINATO` e `LEGALE RAPPRESENTANTE` (vedi §7 sulla coerenza in arrivo).

### 5.3 Sostituire / aggiornare un documento

Apri il documento dalla lista → **Modifica** → carica il nuovo file e/o aggiorna le date. La versione precedente viene sovrascritta — non c'è storico documenti, quindi se vuoi conservare il vecchio scaricalo prima.

### 5.4 Eliminare un documento

Apri il documento → **Elimina**. *Operazione irreversibile.*

---

## 6. Avvisi e scadenze

BOB monitora automaticamente:

- **Documenti scaduti** — appaiono nella sezione *Documenti scaduti* della dashboard.
- **Documenti in scadenza** — entro 30 giorni dalla scadenza si attiva l'avviso.
- **Operai senza documenti obbligatori** — la pagina *Documenti mancanti* segnala chi non ha UNILAV, visita medica, formazione sicurezza.

Gli avvisi si chiudono automaticamente quando carichi/aggiorni il documento corrispondente.

> Se un documento ha valore "INDETERMINATO" o "LEGALE RAPPRESENTANTE" come scadenza, **non genera avvisi** (è considerato sempre valido).

---

## 7. Note importanti su date e coerenza

Attualmente:
- Per **documenti operaio** il campo scadenza è libero, e accetta sia date `gg/mm/aaaa` sia i valori speciali `INDETERMINATO` / `LEGALE RAPPRESENTANTE`.
- Per **documenti aziendali** il campo scadenza è solo data.

In una prossima versione il sistema unificherà l'interfaccia con un selettore esplicito (Data | Indeterminato | Legale Rappresentante) per evitare errori di battitura. Nel frattempo:

- Scrivi **esattamente** `INDETERMINATO` (tutto maiuscolo) — non "indet.", non "TI".
- Scrivi **esattamente** `LEGALE RAPPRESENTANTE` (tutto maiuscolo, due parole) — non "leg. rapp.", non "amministratore".

Date scritte in altro formato (`2027-03-15`, `15-03-2027`, `15.3.27`) potrebbero non essere interpretate correttamente e gli avvisi non funzionerebbero.

---

## 8. Sicurezza e buone pratiche

- **Non condividere le tue credenziali**, nemmeno con colleghi della stessa azienda. Se serve un altro accesso, l'amministratore creerà un altro utente.
- **Esci sempre** con il pulsante *Logout* quando finisci, soprattutto da computer condivisi.
- **Documenti sensibili** (carte d'identità, codici fiscali) sono conservati in modo cifrato e visibili solo al personale autorizzato. Non duplicarli fuori dal portale.
- Se sospetti un accesso non autorizzato al tuo account, **cambia subito la password** e segnala all'amministratore.

---

## 9. Supporto

| Tipo richiesta | Contatto |
|---|---|
| Problemi tecnici / errori del portale | _da definire dall'amministratore_ |
| Richiesta accesso a una nuova azienda | l'amministratore CSM Montaggi |
| Recupero password | usa il flusso "password dimenticata" oppure contatta l'amministratore |

---

*Versione manuale: 2026-04-29 — riferito a BOB 1.x*
