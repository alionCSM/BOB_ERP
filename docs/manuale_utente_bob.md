<div style="page-break-after: always; text-align: center; font-family: 'Segoe UI', 'Helvetica', Arial, sans-serif; padding: 120px 40px 0 40px;">
<img src="logo-bob.png" alt="BOB" style="width: 130px; margin-bottom: 44px;" />
<div style="font-size: 68px; font-weight: 800; letter-spacing: -3px; color: #0f172a; line-height: 1; margin-bottom: 12px;">BOB</div>
<div style="font-size: 15px; font-weight: 600; letter-spacing: 6px; color: #475569; text-transform: uppercase;">Manuale Utente</div>
<div style="width: 60px; height: 3px; background: #1e3a5f; margin: 44px auto 44px auto;"></div>
<img src="logo-consorzio.jpg" alt="Consorzio Soluzione Montaggi" style="width: 300px; max-width: 85%;" />
<div style="font-size: 12.5px; color: #64748b; margin-top: 110px; line-height: 2;">
<span style="color: #94a3b8;">Versione</span>&nbsp; 2.0 &nbsp;&middot;&nbsp; <span style="color: #94a3b8;">Data</span>&nbsp; 18 agosto 2026<br />
<span style="color: #94a3b8;">Gestionale operativo del</span> <strong style="color: #0f172a;">Consorzio Soluzione Montaggi</strong><br />
<span style="color: #94a3b8;">Sviluppato da</span>&nbsp; <strong style="color: #0f172a;">Alion</strong>
</div>
</div>

# BOB — Manuale Utente

Guida d'uso del gestionale operativo **BOB** del Consorzio Soluzione Montaggi (CS Montaggi).
Il documento descrive ogni modulo dell'applicazione, le schermate disponibili e le operazioni
che un utente può svolgere. Le voci del menu e le funzioni visibili dipendono dal profilo
dell'utente e dai permessi assegnati (vedi §2.4 e §11.4).

**Versione documento:** 2.0 — **Data:** 18 agosto 2026
**Versione applicativa:** quella mostrata in fondo al menu laterale (tag di rilascio corrente).

# Indice

[TOC]

# 1. Premessa

## 1.1 Cos'è BOB

**BOB** è il gestionale operativo sviluppato internamente per il **Consorzio Soluzione
Montaggi** (CS Montaggi). Copre l'intero ciclo operativo dell'attività di montaggi industriali:

- **Cantieri** — anagrafica, stati, presenze, extra, fatturazione, disegni, attività, BOB Zone;
- **Offerte e ordini** — preventivi verso i clienti e ordini alle consorziate/aziende;
- **Fatturazione** — fatture da emettere ed emesse per cliente, bozze di fatturazione,
  pagamenti alle consorziate, andamento fatturato;
- **Presenze** — registrazione giornaliera (nostri e consorziate), anticipi, rimborsi,
  multe, ferie/permessi, export Excel;
- **Anagrafiche** — operai, aziende consorziate, clienti, documenti con scadenze;
- **Mezzi** — flotta aziendale (veicoli, carte Q8, telepass, riconciliazione fuel),
  mezzi di sollevamento, potì/noleggi (autocarrate e macchine);
- **Organizzazione** — pianificazione squadre, programmazione annuale, prenotazioni
  ristoranti/hotel, bigliettini pasto;
- **Condivisione** — link pubblici protetti per documenti, attestati operai;
- **BOB AI** — interrogazione del dato aziendale in linguaggio naturale.

## 1.2 Rapporto con gli altri sistemi

BOB **non è il sistema contabile**. I tre sistemi coinvolti sono:

| Sistema | Ruolo |
|---|---|
| **BOB** | Gestionale operativo: tutto il dato gestionale e di cantiere. |
| **Yard** | Database SQL Server che fa da *middleware*: riceve da BOB le righe di fatturazione e ne tiene lo stato (es. "emessa"). |
| **Business** | Gestionale contabile: emette formalmente le fatture. |

Le fatture sono **emesse da Business**, non da BOB. In BOB si vedono le fatture
"da emettere" (righe preparate) e quelle "emesse" (letture in sola lettura da Yard,
inclusi gli importi "Emesse reale" per mese).

## 1.3 Destinatari

- Personale interno (amministrazione, cantiere, direzione);
- Operai (accesso ridotto al proprio profilo e ai propri cantieri);
- Aziende consorziate (accesso ridotto ai propri dati);
- Clienti (accesso ai cantieri che gli sono stati assegnati);
- Personale IT incaricato della manutenzione.

# 2. Accesso al sistema

## 2.1 Login

All'indirizzo dell'applicazione (solo HTTPS) si accede con **nome utente** e **password**.
Spuntando **"Ricordami su questo dispositivo"** la sessione resta valida anche dopo aver
chiuso il browser, tramite cookie di remembered session.

## 2.2 Prima accesso e password

- Se l'account è appena stato creato o la password è stata resetata, al primo login il
  sistema **obbliga al cambio password** (`/change-password`) prima di qualsiasi altra pagina.
- Se l'account richiede la conferma, il sistema chiede la **conferma email**
  (`/confirm-email`) con il link ricevuto.
- La password può essere cambiata in qualsiasi momento dal menu utente → **Cambia Password**.

## 2.3 Profilo utente

Dal menu utente (in alto a destra) → **Profilo** si possono aggiornare i propri dati
anagrafici e la foto profilo.

## 2.4 Tipi di utente

| Profilo | Cosa vede |
|---|---|
| **Staff interno** | I moduli a cui è stato esplicitamente concesso l'accesso (permessi, §11.4). Di default un nuovo modulo è invisibile a tutti. |
| **Operaio** | Solo il proprio profilo e i cantieri a cui è assegnato ("I miei cantieri"). |
| **Cliente** | I cantieri che gli sono stati assegnati. |
| **Utente a scope aziendale** (consorziata) | Solo la propria azienda: "Le mie aziende", "Operai", "Nuovo Operaio", "Scadenze". |
| **SuperAdmin** | Accesso completo a tutti i moduli, più gestione permessi e invio notifiche. |

## 2.5 Società del gruppo

Se l'utente ha accesso a più **società del gruppo** (aziende del gruppo, *non* le aziende
consorziate), in alto a sinistra è visibile un selettore: si può **cambiare società** a
tutto tondo. Ogni società ha dati, moduli e utenti propri; se la pagina aperta non fa
parte della società attiva, la dashboard lo segnala e basta cambiare società dalla
barra in alto.

## 2.6 Il menu principale

Il menu laterale (staff) comprende, a seconda dei permessi:

- **Home → Dashboard**
- **Autocarrate** e **Mezzi sollevamento** (Potì Noleggi)
- **BOB AI**
- **Offerte** (Crea Offerta, Lista Offerte)
- **Ordini Consorziata** (Nuovo Ordine, Lista Ordini)
- **Ordini Aziende** (Nuovo Ordine, Lista Ordini)
- **Clienti** (Nuovo Cliente, Lista Clienti)
- **Cantieri** (Crea Cantiere, Lista Cantieri, Bozze cantieri)
- **Fatturazione** (Cantieri Movimentati, Fatturazione Clienti, Pagamenti Consorziate,
  Andamento Fatturato)
- **Presenze** (Inserisci Presenze, Cerca, Anticipi, Rimborsi, Multe, Ferie/Permessi)
- **Mezzi Sollevamento** (Inserisci Mezzi, Noleggi, Mezzi Sollevamento)
- **Flotta**
- **Prenotazioni**
- **Squadre** (pianificazione)
- **Programmazione**
- **Bigliettini**
- **Compliance** (Operai, Aziende, Documenti Scaduti)
- **Doc Condivisi** (Crea Link, Lista Link)

In fondo al menu è sempre visibile la **versione** dell'applicazione in esecuzione.

# 3. Dashboard

La dashboard cambia in base al ruolo:

## 3.1 Dashboard amministratore

- **Contatori**: utenti registrati (e attivi), cantieri attivi (e totali), presenze di
  oggi (nostri + consorziate), documenti scaduti e in scadenza a 30 giorni.
- **Stato Sistema**: stato di database MySQL, servizio mail, cloud storage NFS
  (con latenza), runtime PHP (memoria).
- **Risorse Server**: CPU load, RAM, disco server e disco cloud con barre di riempimento.
- **Utenti online**: chi è in questo momento nel sistema e la pagina che sta usando.
- **Top utenti oggi** e **Attività recenti**.

Nelle società diverse dal Consorzio i contatori vengono sostituiti dai contatori
della singola società (utenti, cantieri, presenze, documenti).

## 3.2 Dashboard responsabile documenti

- Contatori: documenti scaduti operai/aziende, in scadenza a 7 e 30 giorni,
  link condivisi attivi, download ultimi 30 giorni.
- **In scadenza prossimi 7 giorni** (elenco urgente), **Aziende con più scaduti**
  (grafico a barre).
- **Link Condivisi Recenti** e **Azioni Rapide** (scaduti, in scadenza, link, nuovo link,
  anagrafica operai).
- **Cronologia Notifiche**.

## 3.3 Dashboard dinamica (standard)

Per gli altri utenti la dashboard è costruita sui permessi:

- **Le tue aree**: scorciatoie dirette ai moduli assegnati.
- **Cronologia Notifiche**.
- Se non ha moduli assegnati appare un avviso: chiedere a un amministratore
  di configurare i permessi.

# 4. Funzioni comuni

## 4.1 Notifiche

La **campanella** in alto a destra mostra le notifiche non letta (con puntino se ce ne
sono). Ogni notifica indica mittente, data/ora e messaggio, con pulsanti
**"Segna come letta"** e **"Apri"** (se c'è un link).

- **Cronologia**: modale con tutte le notifiche lette.
- **Notifiche browser**: con *"Attiva notifiche browser"* si abilitano le notifiche
  push anche a sistema chiuso (Web Push).
- Le **notifiche di priorità alta** compaiono in un popup a schermo intero alla
  pagina caricata ("Notifiche Importanti — richiedono la tua attenzione").
- Il **SuperAdmin** può inviare notifiche a tutti da menu utente → **Invia Notifica**.

## 4.2 Servizi (barra superiore)

Il pannello a forma di ingranaggio offre servizi on-demand:

- **Calcola margini cantiere** — ricalcolo costi e margini BOB/Yard di tutti i cantieri;
- **Stato cantiere su Yard** — verifica dello stato dei cantieri sul gestionale Yard.

Ogni voce ha il link **"Avvia"** con esito mostrato in place.

## 4.3 Job automatici (cron)

Il pannello a forma di battito cardiaco elenca i **job automatici** (verifica AI anomalie
carburante, verifica documenti AI, ricalcolo statistiche cantieri, alert noleggi,
sync stato fatture da Yard): stato di ogni job, ultima esecuzione, storico e pulsante
**"Esegui ora"** per ogni job.

## 4.4 Messaggi di feedback

Le operazioni producono messaggi temporanei in alto alla pagina (successo verde,
errore rosso, informazione blu) che scompaiono al successivo caricamento.


# 5. Cantieri

I cantieri sono il cuore del sistema: qui si collegano offerte, presenze, mezzi,
extra, fatturazione, disegni e BOB Zone.

## 5.1 Lista Cantieri

In alto: contatori **In Corso / Completati / Sospesi / A Rischio** e pulsante
**Nuovo Cantiere** (+ menu con **Excel Presenze** per esportare le presenze di un
cantiere in un intervallo di date).

**Filtri**: stato (tutti gli stati), anno, ricerca libera (nome, codice, località).

**Tabella**: nome (con badge *Presenze* / *No presenze* e *Mezzi* / *No mezzi*),
codice, n. ordine, data ordine, cliente, totale (solo per chi vede i prezzi),
località, stato, azioni (apri / modifica).

**Indicatore di rischio**: a sinistra del nome, un triangolo **rosso** segnala un
cantiere in corso **in perdita** (margine negativo); un triangolo **ambra** segnala
**margine basso** (≤ 30%). Il contatore "A Rischio" in alto li riepiloga.

## 5.2 Creare un cantiere

**Sezione Informazioni Cantiere**: nome cantiere (*), cliente (*) — per il Consorzio;
per le altre società del gruppo si seleziona il numero offerta —, località,
numero offerta, descrizione.

**Sezione Contratto e Valore** (Consorzio): scegliere il tipo di contratto:

- **Contratto fisso** — valore totale definito → campo *Totale Contratto (€)* (*);
- **Consuntivo** — ricavo da presenze × tariffa → campo *Tariffa/persona (€/giorno)* (*).

Poi: numero ordine (*), commessa, data ordine.

Le altre società del gruppo vedono invece **Dati Economici** (totale offerta,
numero ordine, data ordine).

La data di inizio del cantiere viene impostata automaticamente alla data di creazione.

## 5.3 Scheda cantiere

In alto: **Dettagli Cantiere** (cliente, nome, codice, commessa, n. ordine, data
ordine, offerta — cliccabile per il PDF —, località, descrizione) con link **Modifica**,
e **Riassunto** (contratto o tariffa per persona, extra, totale lordo o ricavo stimato,
**Fatturato** — somma delle righe effettivamente emesse su Yard —, **Margine**,
presenze, mezzi).

## 5.4 Le schede del cantiere (tab)

**Presenze** — presenze dei lavoratori *nostri*: filtro per data (colonna a sinistra),
ricerca libera, tabella (data, operaio, azienda, turno, pranzo, cena, hotel, auto, note).
Azioni per riga: **Duplica** (copia la riga su un'altra data), **Modifica**, **Elimina**.
In alto a destra: **Aggiungi Presenza** (apre la registrazione, §10.1).

**Presenze Cons.** — presenze a consorziata (numero persone, costi, pasti, hotel)
registrate sul cantiere.

**Ordini** — ordini collegati al cantiere: aggiungi/modifica/elimina (modale).

**Mezzi** — mezzi di sollevamento in noleggio sul cantiere (gestiti da §16.2).

**Statistiche** — andamento costi e presenze, con **Dettaglio costi**
(presenze, pasti, hotel, altre voci).

**Assistente AI** — chat dedicata al singolo cantiere ("BOB AI — Assistente del
cantiere"): *Domande rapide* in un clic (presenze totali, giorni lavorati, top
lavoratori, presenze per mese, consorziate, mezzi in cantiere, e — per chi vede i
prezzi — economia, dettaglio costi, fatturazione, riepilogo completo) o domanda
libera scritta. Le risposte riguardano solo il cantiere aperto.

**Attività** (per chi vede i prezzi) — attività/lavorazioni eseguite con totale
giornate-uomo (G/U); **Aggiungi Attività** con foto.

**Extra** (per chi vede i prezzi) — costi extra del cantiere: aggiungi/modifica/elimina.

**Fatturazione** (per chi vede i prezzi) — righe di fatturazione del cantiere
(fatture da emettere verso il cliente): aggiungi/modifica/elimina.

**Note** (per chi vede i prezzi) — **note finanziarie**: note con importo e
giustificativo, con ciclo di vita *aggiunta → applicata → riaperta* (o eliminata).
Le note applicate incidono sul margine del cantiere.

**Disegni** — repository disegni del cantiere (PDF, DWG, PNG, JPG): caricamento con
**versionamento** (ogni nuovo caricamento con lo stesso nome crea una nuova versione),
consultazione, **versioni** storiche, **condivisione** (link), eliminazione;
disegni raggruppati per categoria.

## 5.5 Accesso utenti al cantiere

Dalla scheda cantiere si può **Assegnare utente** (chi può vedere/gestire il cantiere)
e consultare gli **Utenti con accesso**.

## 5.6 Bozze cantiere

I cantieri possono essere creati come **bozza** (es. da offerta non ancora attiva):
la voce di menu **Bozze cantieri** (permesso dedicato) elenca le bozze; da qui si
**modifica** la bozza o si **attiva** il cantiere (passa in lista cantieri).

## 5.7 Stato del cantiere

Gli stati sono: **In corso**, **Completato**, **Sospeso**, **A rischio**.
Lo stato su Yard può essere verificato/aggiornato dal pannello *Servizi* (§4.2).

## 5.8 Vista operaio

L'operaio vede dal menu **I miei cantieri**: lista dei cantieri a cui è assegnato;
la scheda del cantiere è semplificata (solo nome, codice, località) e mostra le
sezioni a lui consentite.

# 6. BOB Zone

**BOB Zone** è l'area collaborativa di cantiere: task, disegni, foto, file e moduli
per il lavoro di squadra, raggiungibile dal link **BOB Zone** in alto nella scheda
cantiere. Può essere **abilitata/disabilitata** per ogni cantiere.

## 6.1 Attività (task)

Tre viste, commutabili in alto:

- **Kanban** — colonne per stato (aperti / in corso / completati) con card drag&drop;
- **Gantt** — barra temporale per task con date;
- **Calendario** — mese navigabile (Oggi / avanti / indietro).

**Nuovo Task** (pulsante laterale o modale): nome, descrizione, assegnatari,
categoria, date (inizio/ scadenza). Filtri: ricerca, assegnatario, categoria.

**Dettaglio task**: stato (cambiabile), info, **Checklist** (aggiungi elemento,
completa, elimina), **Commenti** (scrivi, elimina), **Foto** (carica).
Il task si può **modificare** o **eliminare** (conferma: persi commenti e checklist).

Contatori sempre visibili: task aperti / in corso / fatti.

## 6.2 Disegni

Elenco dei disegni del cantiere con caricamento (**PDF, DWG, PNG, JPG**).
I file **DWG** vengono convertiti in SVG per la consultazione; si possono creare
**annotazioni** grafiche sul disegno, impostare la **calibrazione** (scala reale) e
**spingere il disegno su Fieldwire** se collegato. Sono supportati anche i
**piani di campo** (floor plans) di Fieldwire.

## 6.3 Foto, File, Moduli

- **Foto** — galleria foto del cantiere.
- **File** — repository file: cartelle (crea/elimina), caricamento, download,
  commenti per file.
- **Moduli** — moduli compilabili (template): creazione template, **compilazione**
  (submit) con firma/foto, elenco compilazioni, visualizzazione.

## 6.4 Report e Fieldwire

- **Report PDF** (pulsante laterale): report stampabile dello stato delle attività
  del cantiere.
- **Fieldwire**: se il token Fieldwire è configurato, il cantiere può essere
  **collegato a Fieldwire** per sincronizzazione bidirezionale (task, piani,
  annotazioni). Senza configurazione appare "Fieldwire non configurato".

# 7. Offerte

Le offerte (preventivi) verso i clienti, con ciclo di vita completo.

## 7.1 Lista Offerte

Schede filtro per stato: **Tutte, Bozza, Inviata, In Trattativa, Approvata, Rifiutata,
Scaduta**. La tabella mostra data, numero, cliente, oggetto, stato (cambiabile
direttamente da un menu a tendina nella riga) e azioni: **Revisione**, **Scarica PDF**.

## 7.2 Creare / modificare un'offerta

- **Cliente** (*), **Data** (*), **Numero Offerta**;
- **Oggetto** (*), **Riferimento Richiesta**, **Alla cortese attenzione di**;
- **Voci** dell'offerta (descrizione e importi);
- **Info Aggiuntive**, **Termini e Pagamento**, **Condizioni**;
- **Note Interne** (private, non stampate nel PDF) e **Allega PDF**
  (allegato alternativo, max 10 MB).

## 7.3 Dettaglio offerta

- Cambio di **stato** (bozza → inviata → in trattativa → approvata / rifiutata / scaduta);
- **Anteprima PDF** integrata (e download);
- **Allegato PDF** (se presente, consultabile);
- **Follow-up**: registro dei solleciti/ricordatori (data + nota), aggiungi/elimina;
- **Revisione di …**: se l'offerta è una revisione, link all'originale.

## 7.4 Revisioni

Da una qualsiasi offerta si può creare una **revisione** (*Crea Revisione*): si
riprende il contenuto dell'originale con **Data Revisione** (*) e **Numero Revisione**
opzionale; la nuova offerta è collegata all'originale ("Revisione di #…").

# 8. Fatturazione

## 8.1 Cantieri Movimentati

Elenco dei cantieri con attività di fatturazione (righe da/emesse), con export
**Excel** dell'insieme.

## 8.2 Fatturazione Clienti

Riepilogo per cliente delle fatture **da emettere** ed **emesse**.

**Contatori in alto**: *Emesse reale* mese corrente e mese precedente (letture da
**Yard** — cliccabili per aprire il dettaglio delle righe del mese), *Emesse*
(anno corrente) e totale da emettere + emesse. Il pulsante **"Apri dettaglio emesse
di un altro mese"** permette di interrogare qualsiasi mese passato (non i futuri).

**Tabella clienti**: importo da emettere, numero importi emessi, e la colonna
**Prospetto** per il periodo suggerito (mese/anno): se il prospetto è già stato
"mandato" appare un chip verde con l'ultimo periodo marcato (con indicazione se si
è in ritardo, "behind"); altrimenti un pulsante per marcare.

**Marcare il prospetto**: dal modale *"Prospetto fatto e mandato"* si conferma il
periodo (mese/anno, modificabile); si può anche **rimuovere** la marcatura.
Il prospetto segnala che il documento riepilogativo del periodo è stato spedito
al cliente.

## 8.3 Dettaglio cliente

- Contatori: da emettere (anno), emesse (anno), totale;
- Pulsante **Excel Da Emettere** (export righe da emettere del cliente);
- Pulsante **Crea bozza fatturazione** (§8.4);
- Se c'è una **bozza attiva** per il cliente: banner con stato e **Apri bozza**;
- Sezione **Da emettere**: righe (cantiere, descrizione, importo, IVA, stato —
  incluso il badge *applicata · bozza #… · data* per le righe già corrette via bozza
  ma non ancora emesse su Yard —, Yard ID);
- Sezione **Emesse**: storico fatture emesse (con caricamento progressivo).

## 8.4 Bozza di fatturazione

La **bozza** è lo strumento per correggere le righe da emettere prima dell'emissione:

1. **Crea bozza fatturazione** dal dettaglio cliente: le righe "da emettere" del
   periodo vengono copiate nella bozza (stato **bozza**, modificabile).
2. Nella bozza si possono: modificare le righe, **escludere** una riga dalla
   fattura (torna disponibile in "da emettere" per un'altra bozza) o ri-includerla.
   Le righe **aggiunte** dal cantiere dopo l'apertura della bozza e quelle
   **eliminate** vengono evidenziate; le modifiche fatte dall'utente sono in giallo.
3. **Export Excel** della bozza (per revisione esterna).
4. **Applica modifiche ai cantieri** (finalizza): le modifiche vengono scritte sulle
   righe dei cantieri e **sincronizzate su Yard**. La bozza passa a sola lettura.
5. Banner **Yard**: esito della sincronizzazione (righe sincronizzate / fallite /
   senza yard_id) con pulsante **Riprova sync Yard** per i fallimenti.
6. In alternativa **Annulla bozza**: le righe tornano disponibili in "da emettere".

Le bozze possono essere riportate in modifica (transizione a *bozza*) per
riapplicare con nuovi valori.

## 8.5 Pagamenti Consorziate

Gestione dei **pagamenti alle aziende consorziate**: elenco per consorziata,
dettaglio con fatture/importi, registrazione **pagamenti**, flag **Già pagato**
per le fatture saldate, eliminazione pagamenti, **export** per consorziata.

## 8.6 Andamento Fatturato

Vista **sola lettura** dell'andamento del fatturato letta dal gestionale contabile
**Business**, con dettaglio per **causale**. Visibile solo a chi ha il permesso
di direzione (report_business).


# 9. Ordini

## 9.1 Ordini Consorziata

Ordini emessi **alle aziende consorziate**.

**Stati**: *Bozza → Inviato → Accettato / Rifiutato* (cambiabili dalla lista o
dal dettaglio).

**Nuovo Ordine**: cantiere, **consorziata (destinatario)**, data ordine, oggetto,
IVA, termini di pagamento, note.

**Lista Ordini**: schede filtro per stato, tabella con stato, azioni (dettaglio,
modifica, PDF, elimina).

**Dettaglio**: dati ordine, cambio stato, **PDF** dell'ordine, eliminazione.

## 9.2 Ordini Aziende

Ordini verso **aziende non consorziate** (fornitori/terze parti):

**Nuovo Ordine**: azienda, **mese** e **anno** (riferimento documento), data ordine,
imponibile, IVA, totale documento, descrizione (precompilata dal sistema) e note.

Ciclo CRUD completo (lista, dettaglio, modifica, elimina) con **PDF** per ordine.

# 10. Presenze

## 10.1 Inserisci Presenze

Registrazione **giornaleira** per operai nostri e consorziate:

- **Data Inizio / Data Fine** (intervallo) e **Cantiere** (se si arriva dalla scheda
  cantiere il cantiere è già fissato; la funzione *Duplica* di una riga precompila
  la stessa data per il giorno successivo).
- Tab **Nostri**: una riga per operaio con *Tipo Turno, Pranzo, Cena, Hotel, Auto,
  Note*. L'interruttore **"Copia valori prima riga"** replica i valori della prima
  riga sulle nuove (utile per giornate uguali).
- Tab **Consorziate**: una riga per consorziata con *N. Persone, Costo/Persona,
  Pasti TOT, Auto, Hotel TOT, Note*.
- **Anteprima Presenze**: modale di riepilogo delle modifiche; si conferma con
  **Salva Modifiche** (se esistono presenze già presenti si può scegliere
  **"Salva sovrascrivendo"**).

## 10.2 Cerca Presenze

Ricerca e consultazione delle presenze registrate, con filtri e azioni per riga
(modifica/eliminazione) ed **export Excel**:

- **Esporta Excel Operaio** (singolo operaio, periodo);
- **Excel Bulk Operai (ZIP)** (più operai, un file per ciascuno);
- **Esporta Excel Azienda**;
- **Excel Committente** (vista cliente).

## 10.3 Movimenti accessori

Quattro sezioni dedicate (lista + registrazione):

- **Anticipi** — anticipi su stipendio/rimborsi versati all'operaio;
- **Rimborsi** — rimborsi (spese, trasferte, ecc.);
- **Multe** — sanzioni a carico dell'operaio;
- **Ferie/Permessi** — registrazioni di ferie e permessi.

I movimenti accedono ai riepiloghi di presenza e agli export.

# 11. Operai e utenti

## 11.1 Lista Operai

**Gestione Operai — Anagrafica completa dei lavoratori**: contatori
*totale / attivi / inattivi*, ricerca libera (nome, azienda, codice fiscale),
tabella (operaio con foto, azienda, stato attivo/inattivo, azioni **Profilo** /
**Elimina** con conferma). Paginazione con 10/25/50/100 righe. La foto si apre
in anteprima a schermo intero.

## 11.2 Profilo operaio

Schede:

- **Informazioni Personali** — nome, cognome, data e luogo di nascita, email,
  telefono, azienda, tipo operaio, data assunzione, codice fiscale, foto profilo;
- **Cambio Azienda** — trasferimento a nuova azienda con *ruolo*, *data inizio
  nuova azienda* e *ultimo giorno azienda attuale* (lo storico resta consultabile);
- **Presenze** — storico presenze dell'operaio;
- **Ferie e Permessi** — storico;
- **Documenti Aziendali** e **Documenti Personali** — documenti caricati, con
  scadenze (rinnova/elimina);
- **Storico Aziende** — aziende precedenti con periodi.

## 11.3 Nuovo Operaio

Creazione anagrafica completa con documento d'identità/foto e documenti personali;
l'operaio può essere associato a un'azienda (nostra o consorziata) e, se richiesto,
dotato di **account di accesso** (per il portale/portal operai).

## 11.4 Permessi (staff)

Da menu utente (SuperAdmin) → **Permissions** (o *Utenti → Permessi*):

- **Elenco permessi**: tutti i moduli del sistema (offerte, cantieri, fatturazione,
  presenze, mezzi, flotta, prenotazioni, biglietti, compliance, doc condivisi,
  BOB AI, società del gruppo, ecc.);
- **Permessi di un utente**: griglia modulo → accesso. Il principio è
  **default-deny**: se non è spuntato, il modulo non è visibile; il SuperAdmin
  (id 1) ha sempre tutto.

## 11.5 Audit log

Registro delle attività degli utenti (pagine visitate, azioni) con filtri per
utente/periodo — utile per verifiche e sicurezza.

## 11.6 Altre funzioni

- **Invia Notifica** (SuperAdmin): notifica in-app (e push) a un utente/tutti;
- **Badge**: generazione badge/diamante identificativo dell'utente;
- **Nuovo utente BOB**: creazione account staff con permessi iniziali.

# 12. Aziende (consorziate)

## 12.1 Gestione Aziende

Lista di tutte le aziende registrate (consorziate e nostre), con ricerca e
contatori; creazione/modifica anagrafica (denominazione, P.IVA, contatti,
documenti aziendali, stato attiva/inattiva).

## 12.2 Scheda azienda

Tab:

- **Informazioni** — anagrafica completa;
- **Documenti** — documenti aziendali (polizze, DURC, iscrizioni…):
  caricamento, aggiornamento scadenza, eliminazione;
- **Operai** — operai associati all'azienda (con rimozione);
- **Cantieri** — cantieri in cui l'azienda ha lavorato.

Azioni: **accesso utente** (creazione di un utente a *scope aziendale* per il
personale della consorziata, §2.4), **crea utente azienda**, **attiva/disattiva**,
**elimina** (con verifica dei vincoli), **export consorziata** (esportazione dati
dell'azienda).

## 12.3 Esperienza della consorziata (scope aziendale)

L'utente della consorziata vede un menu ridotto: **Le Mie Aziende**, **Operai**,
**Nuovo Operaio**, **Scadenze** (documenti scaduti dei propri operai).
Nella scheda azienda gli sono accessibili solo le tab *Documenti* e *Cantieri*;
non vede dati di altre aziende, né prezzi.

# 13. Clienti

Anagrafica **clienti** (committenti):

- **Nuovo Cliente** e **Lista Clienti** (ricerca);
- **Scheda cliente**: dati anagrafici, **statistiche** (cantieri, importi),
  cantieri associati;
- Modifica e **eliminazione** (con controllo: se il cliente ha cantieri/relazioni
  il sistema lo segnala prima di procedere).

# 14. Documenti (compliance)

I documenti di operai e aziende hanno **scadenza** e sono monitorati in continuo.

## 14.1 Documenti

I documenti si caricano dal **profilo operaio** (documenti personali: carta
d'identità, patente, abilitazioni, corso sicurezza…) o dalla **scheda azienda**
(documenti aziendali). Ogni documento ha tipo, file allegato e data di scadenza.

## 14.2 Scaduti e in scadenza

- **Documenti Scaduti** (*Compliance → Documenti Scaduti*): panoramica dei
  documenti che richiedono attenzione immediata, distinti operai/aziende;
- **In scadenza**: a 30 giorni, con evidenza **urgente** a 7 giorni
  (dashboard responsabile documenti, §3.2);
- Per gli utenti a scope aziendale: **Scadenze** (documenti dei propri operai).

## 14.3 Documenti obbligatori

Il sistema definisce i **documenti obbligatori** per operaio e per azienda
(es. corso di aggiornamento, DURC, polizza RC…). Il controllo
*check-mandatory* (e la dashboard) evidenziano chi **non è conforme**, così da
sollecitare il rinnovo prima di assegnare l'operaio a un cantiere.

## 14.4 Suggerimenti AI

Al caricamento di un documento il sistema (se configurato) può **suggerire in AI**
tipo documento e data di scadenza leggendo il file: si conferma o corregge.

## 14.5 Consultazione

Ogni documento è consultabile/scaricabile da dove è stato caricato (profilo
operaio, scheda azienda) e dai **Doc Condivisi** (§21).


# 15. Flotta

Gestione della **flotta aziendale**: veicoli, carte carburante (Q8) e telepass,
con riconciliazione delle spese fuel.

## 15.1 Le sezioni

Tab in alto: **Veicoli**, **Carte Q8**, **Telepass**, **Tratte (GPS)**, **Carburante**,
**Anomalie**, **Importazioni** + pulsante **Carica file flotta**.

- **Veicoli**: elenco con targa, modello, assegnatario, carta Q8 e telepass associati.
  *Nuovo veicolo*; per ogni veicolo/carta/telepass: **storicizzazione** assegnazioni
  e **reassign** (cambio assegnatario) con storico completo.
- **Carte Q8** e **Telepass**: gestione e assegnazione ai veicoli/operai, storico.
- **Tratte** e **Carburante**: dati delle tratte GPS e dei rifornimenti importati.

## 15.2 Import file (reconciliation)

**Carica file flotta**: caricamento dei file estratti dal gestionale carburante:

1. **Riepilogo tratte GPS** e **Fattura/transazioni Q8** (anteprima prima dell'analisi);
2. **Mappatura suggerita carte → veicoli**: l'AI suggerisce quale carta corrisponde
   a quale veicolo; si **accetta** (o corregge) la mappatura;
3. **Lancia analisi riconciliazione**: il sistema confronta transazioni e tratte.

## 15.3 Anomalie (AI)

L'analisi AI segnala **anomalie sul carburante** (es. rifornimento fuori tratta,
importi anomali): ogni anomalia può essere **scartata** (dismiss) con motivazione o
**analizzata** nel dettaglio. Gli alert periodici arrivano anche via cron (email
a chi ha il permesso *equipment_alerts*).

# 16. Mezzi Sollevamento

## 16.1 Catalogo

**Mezzi Sollevamento** (catalogo): elenco mezzi con descrizione e **costo
giornaliero (€)**; *Aggiungi Nuovo Mezzo* / modifica / elimina.

## 16.2 Inserisci Mezzi (assegnazione a cantiere)

Per ogni noleggio: **seleziona cantiere**, mezzo, tipo, quantità,
**costo (€/giorno)**, data inizio, **giorni conteggiati** e **festivi nazionali**
(esclusi dal conteggio). I mezzi così inseriti compaiono nella scheda cantiere
(tab *Mezzi*) e nella lista cantieri (badge *Mezzi*).

## 16.3 Noleggi

**Noleggi**: elenco noleggi per cantiere; per ogni cantiere si può **modificare**
il dettaglio (giorni, costi) e segnare il noleggio **completato**.
Un job automatico (cron) invia **alert noleggi** quando le presenze escono dal
calendario di conteggio (es. festivi non esclusi).

# 17. Potì Noleggi

Gestione del parco **autocarrate** (poti) e delle **macchine di sollevamento**
a noleggio, con disponibilità e prenotazioni.

## 17.1 Autocarrate

- **Mezzi**: elenco autocarrate con *targa, modello, altezza, portata, stato, note*;
  inserimento/modifica.
- **Prenotazioni**: prenotazioni per mezzo con *cliente, luogo, dal/al, giorni,
  contratto, tariffa/giorno, totale, commerciale, stato, pagamento, note*;
  creazione/eliminazione; vista **Occupati** (mezzi prenotati nel periodo).
- **Disponibilità**: calendario di disponibilità dei mezzi.
- **Registro**: registro storico delle uscite; con **ripristino** delle voci
  eliminate per errore.

## 17.2 Mezzi sollevamento (macchine)

- **Macchine**: *tipo, matricola, modello, altezza, portata, stato, note*;
- **Elenco** prenotazioni, **Occupate** (macchine impegnate), **Disponibilità**;
- **Registro** con ripristino.

# 18. Squadre e Programmazione

## 18.1 Squadre (Pianificazione)

**Pianificazione Squadre**: pianificazione **giornaliera** delle squadre sui cantieri.

- Sidebar **Operai nostri** con contatore assegnati/disponibili e ricerca;
- Elenco **cantieri pianificati** per il giorno (navigabile avanti/indietro):
  per ogni cantiere si assegnano operai nostri (trascinando/scegliendo dalla
  sidebar) e si indicano i **consorziati** (numero persone per consorziata);
- Contatori: cantieri, assegnati, consorziati, disponibili;
- **Copia da ieri** (replica la pianificazione del giorno precedente),
  **Salva**, **Stampa** (piano del giorno per il cantiere).

## 18.2 Programmazione

**Programmazione**: vista **annuale** (12 mesi) della pianificazione lavori.

- Riga per lavoro: *data, indirizzo, committente, mezzi necessari, durata,
  referente + info, capo squadra, totale persone, trasferta, info*;
- **Nuova Riga** / modifica / eliminazione;
- In alto: banner **allarmi** — "cantieri in scadenza con attività incomplete"
  (espandibile) per non perdere le scadenze operative.

# 19. Prenotazioni

Prenotazioni **ristoranti** e **hotel** per le trasferte di cantiere.

- **Nuova Prenotazione** → tipo **Ristorante** o **Hotel**;
- **Cerca struttura** (creazione/ricerca della struttura: nome*, telefono,
  indirizzo, città, provincia, paese, ragione sociale);
- Dati prenotazione: **cantiere**, **capo squadra**, **pasti inclusi**, **regime**,
  note, consorziata interessata;
- Elenco con ricerca (struttura, città, cantiere) e filtri
  (tutti / ristorante / hotel, attive, pagato/non pagato);
- Per ogni prenotazione: **fatture** allegabili (caricamento, consultazione,
  eliminazione) con flag **Pagato**; modifica/eliminazione prenotazione;
- **Override** per casi particolari di conteggio.

# 20. Bigliettini (pasto)

Gestione **biglietti pasto** per gli operai:

- **Stampa Bigliettino**: selezione di **uno o più operai** + data → stampa dei
  biglietti;
- Contatori: **pasti mese corrente**, **pasti mese precedente**, **totale stampati**;
- **Genera Report** (riepilogo periodico dei biglietti emessi);
- Lista bigliettini emessi con modifica/eliminazione.

# 21. Doc Condivisi

Condivisione **sicura e tracciata** di documenti (aziendali o di operai) con
soggetti esterni, tramite **link pubblici protetti** serviti dal portale
documenti (docs.csmontaggi.it).

## 21.1 Creare un link

**Doc Condivisi → Crea Link**:

- **Titolo** (*) e **scadenza** opzionale (a scadenza il link si disattiva da solo);
- **Password** generata automaticamente (condivisa con il destinatario);
- Selezione delle **aziende** (documenti aziendali) e degli **operai**
  (documenti personali) da includere, oppure caricamento di **file manuali**
  (upload a chunk per file grandi);
- Il link è **vivo**: i documenti inclusi sono quelli correnti del sistema
  (un rinnovo documento si riflette automaticamente nel link).

## 21.2 Lista Link

Elenco link con stato (attivo/disattivo/scaduto), numero di operai/aziende
inclusi, data creazione; azioni: **attiva/disattiva**, **cambia password**,
**elimina**. La dashboard responsabile documenti mostra i link recenti e i
download (30 gg).

## 21.3 Il portale del destinatario

Chi riceve il link (senza account BOB) vede:

- Pagina con **password** (e captcha) se il link è protetto;
- Albero: **azienda → Documenti Aziendali / Operai → documenti**, con download
  singolo o **"Scarica Tutto"** (ZIP);
- **Scadenza** visibile in testa; a link scaduto il portale lo segnala
  (HTTP 410) e invita a contattare il mittente;
- Ogni download viene tracciato (chi/quando) nel pannello BOB.

## 21.4 Attestati operai

Il portale pubblica anche la pagina **attestati** di ogni operaio
(`/attestati/<id>`): i certificati/dichiarazioni dell'operaio, accessibili
solo con l'identificativo personale.

# 22. BOB AI

**BOB AI** (menu, permesso *ai_chat*) è l'assistente per interrogare il
**database aziendale in linguaggio naturale**:

- Si scrive la domanda ("Chiedi qualcosa sui dati…"); il modello (LLM locale,
  **Ollama** — i dati **non escono dalla rete aziendale**) genera e esegue una
  interrogazione **sola lettura** sul database MySQL;
- La risposta riporta i dati richiesti in forma leggibile, con possibilità di
  **esportare la tabella** dei risultati;
- L'accesso è limitato agli utenti con il permesso *ai_chat*;
- A livello di cantiere esiste l'**Assistente AI** dedicato (§5.4).

*Avvertenza: le risposte sono generate da un modello AI sulle tabelle aziendali;
verificare i dati critici prima di usarli in decisioni operative.*

# 23. Sicurezza e protezione (riassunto)

- **Accesso** solo su HTTPS, con sessione protetta e token anti-CSRF su tutti i
  form; cookie di sessione *Secure*.
- **Password**: cambio obbligato a primo accesso, cambio in qualsiasi momento dal
  profilo; politica di robustezza applicata.
- **Permessi per modulo** (default-deny) e **scope dati** per tipo di utente
  (operaio/cliente/consorziata vedono solo i propri dati).
- **Audit log** di pagine e azioni degli utenti.
- **Documenti condivisi** protetti da token + password opzionale, con scadenza,
  captcha anti-abuso e tracciamento dei download.
- **AI**: esegue solo interrogazioni in lettura; i modelli girano in rete
  interna (nessun dato in cloud).

# 24. Glossario

| Termine | Significato |
|---|---|
| **BOB** | Il gestionale operativo del Consorzio. |
| **Yard** | DB SQL Server middleware: riceve le righe di fatturazione da BOB e ne riporta lo stato (emessa). |
| **Business** | Gestionale contabile: emette formalmente le fatture. |
| **Cantiere** | Sito di lavoro (commessa), con presenze, costi, fatturazione e documenti. |
| **Consuntivo** | Cantiere pagato a consuntivo: ricavo = presenze × tariffa/persona/giorno. |
| **Contratto fisso** | Cantiere con valore totale definito in contratto. |
| **Riga da emettere** | Voce di fatturazione preparata in BOB, non ancora emessa su Business. |
| **Emesse reale** | Importo effettivamente fatturato, letto da Yard. |
| **Bozza fatturazione** | Copia modificabile delle righe da emettere di un cliente, da applicare ai cantieri e sincronizzare su Yard. |
| **Prospetto** | Documento riepilogativo periodale mandato al cliente; BOB traccia se è stato "fatto e mandato". |
| **Note finanziarie** | Note con importo che incidono sul margine del cantiere (ciclo aggiunta→applicata→riaperta). |
| **G/U** | Giornata-uomo (unità di misura delle attività). |
| **BOB Zone** | Area collaborativa di cantiere (task, disegni, foto, file, moduli). |
| **Fieldwire** | Piattaforma esterna di cantiere opzionale, collegabile a BOB Zone. |
| **Consorziata** | Azienda partner del consorzio; i suoi operai lavorano sui cantieri. |
| **Scope aziendale** | Profilo utente limitato ai dati di una sola azienda (personale consorziata). |
| **Società del gruppo** | Aziende del gruppo (es. Consorzio + altre società): dati/utenti separati, commutabili in topbar. |
| **Q8** | Carta carburante usata dalla flotta. |
| **Trasferta** | Soggiorno fuori sede per lavoro (hotel, pasti). |
| **Attestato** | Certificato/dichiarazione dell'operaio, pubblicata sul portale documenti. |
