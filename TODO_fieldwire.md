# BOB Zone × Fieldwire — Documentazione completa

## Cos'è BOB Zone

BOB Zone è una feature BOB-nativa per la gestione operativa del cantiere.
Ogni cantiere BOB ha la sua pagina BOB Zone (`/worksites/{id}/zone`) — accessibile
dal bottone "BOB Zone" nella pagina cantiere — che si apre in un tab separato con
design dark standalone (non usa il layout BOB con sidebar/topbar).

**Fieldwire è opzionale.** BOB Zone funziona da sola con i dati BOB.
Se il cantiere viene collegato a Fieldwire, i dati si sincronizzano bidirezionalmente.

---

## Fieldwire API — Come funziona

### Autenticazione (due token)

Fieldwire usa un sistema a **due token**:

1. **Refresh token** (permanente)
   - Si chiama anche "API key" o "API token" nel gergo Fieldwire
   - Si ottiene da: `app.fieldwire.com` → Account → API Keys
   - Va salvato in `.env` come `FIELDWIRE_API_TOKEN`
   - Non scade (o scade dopo mesi)

2. **Access token** (temporaneo, minuti/ore)
   - Si genera chiamando: `POST https://client-api.super.fieldwire.com/api_keys/jwt`
   - Body: `{"api_token": "<refresh_token>"}`
   - Response: `{"token": "...", "expires_in": 1800}`
   - Va usato nell'header di ogni chiamata API
   - `FieldwireClient` lo gestisce automaticamente (cache + rinnovo su 401)

### Headers richiesti

```
Authorization: Bearer <access_token>
Content-Type: application/json
Fieldwire-Version: 2023-11-30
```

### Base URL

- EU: `https://client-api.eu.fieldwire.com/api/v3`
- US: `https://client-api.us.fieldwire.com/api/v3`

Configurare `FIELDWIRE_REGION=eu` in `.env`.

### Struttura generale

Tutte le risorse seguono `/projects/{project_id}/resource`.
Fieldwire usa `id` come stringa (UUID), non intero.

---

## Fieldwire — Risorse principali e endpoint

### Progetti
```
POST   /projects                          → crea progetto
GET    /projects                          → lista tutti i progetti dell'account
GET    /projects/{id}                     → dettaglio progetto
PATCH  /projects/{id}                     → aggiorna progetto
DELETE /projects/{id}                     → elimina progetto
```
Body creazione: `{"project": {"name": "...", "description": "..."}}`
Response: `{"id": "uuid", "name": "...", ...}`

### Task
```
GET    /projects/{id}/tasks               → lista task (con filtri: ?status=open)
POST   /projects/{id}/tasks               → crea task
GET    /projects/{id}/tasks/{task_id}     → dettaglio task
PATCH  /projects/{id}/tasks/{task_id}     → aggiorna task
DELETE /projects/{id}/tasks/{task_id}     → elimina task
POST   /projects/{id}/tasks/{task_id}/restore → ripristina task eliminato
```
Campi task: `name`, `description`, `status` (open/in_progress/complete/verified),
`category_name`, `assignee_name`, `due_date`, `start_date`, `priority`

### Check Items (Checklist)
```
GET    /projects/{id}/tasks/{task_id}/check_items
POST   /projects/{id}/tasks/{task_id}/check_items    → {"check_item": {"name": "..."}}
GET    /projects/{id}/tasks/{task_id}/check_items/{ci_id}
PATCH  /projects/{id}/tasks/{task_id}/check_items/{ci_id}  → {"check_item": {"completed": true}}
DELETE /projects/{id}/tasks/{task_id}/check_items/{ci_id}
GET    /projects/{id}/check_items         → tutti i check items del progetto
```
Campi: `name`, `completed` (bool)

### Bubbles (Messaggi/Commenti/Foto su task)
```
GET    /projects/{id}/tasks/{task_id}/bubbles
POST   /projects/{id}/tasks/{task_id}/bubbles   → {"bubble": {"kind": "comment", "text": "..."}}
GET    /projects/{id}/bubbles/{bubble_id}
PATCH  /projects/{id}/bubbles/{bubble_id}
DELETE /projects/{id}/bubbles/{bubble_id}
POST   /projects/{id}/bubbles/{bubble_id}/flatten  → brucia markup sull'immagine
```
Kind possibili: `comment`, `photo`, `video`, `link`, `attachment`
Campi: `kind`, `text`, `file_url`, `creator_name`, `creator_email`

### Floorplans (Tavole/Disegni)
```
GET    /projects/{id}/floorplans
GET    /projects/{id}/floorplans/{fp_id}
PATCH  /projects/{id}/floorplans/{fp_id}
DELETE /projects/{id}/floorplans/{fp_id}
GET    /projects/{id}/floorplans/{fp_id}/sheets
```
Upload disegni — flusso a due step (S3):
1. `POST /projects/{id}/sheet_uploads` → ottieni URL S3 + credenziali
2. Upload diretto su S3 con le credenziali
3. `PATCH /projects/{id}/sheet_uploads/{su_id}` con `{"sheet_upload": {"status": "uploaded"}}`

Export:
- `POST /projects/{id}/floorplans/{fp_id}/exports` → genera PDF
- `POST /projects/{id}/floorplans/batch_exports` → export multiplo

### Markups (Annotazioni su tavole)
```
GET    /projects/{id}/sheets/{sheet_id}/markups
POST   /projects/{id}/sheets/{sheet_id}/markups
PATCH  /projects/{id}/sheets/{sheet_id}/markups/{m_id}
DELETE /projects/{id}/sheets/{sheet_id}/markups/{m_id}
POST   /projects/{id}/sheets/{sheet_id}/markups/batch_update
POST   /projects/{id}/sheets/{sheet_id}/markups/batch_destroy
```
Kind: `arrow`, `cloud`, `drawing`, `ellipse`, `highlighter`, `measurement`,
`polygon`, `rectangle`, `text`
Geometry: formato GeoJSON (`{"type": "Point", "coordinates": [x, y]}`)

### Webhook
Endpoint Fieldwire per registrare webhook (senza auth, usa il token dell'account):
- EU: `https://webhook-api.eu.fieldwire.com`

Registrazione:
```
POST https://webhook-api.eu.fieldwire.com/subscriptions
{
  "subscription": {
    "name": "BOB Zone",
    "url": "https://<dominio>/api/fieldwire/webhook",
    "active": true,
    "entity_filters": [{"type": "task"}, {"type": "check_item"}, {"type": "bubble"}, {"type": "floorplan"}]
  }
}
```

Payload webhook:
```json
{
  "event_type": "task.updated",
  "project_id": "uuid-del-progetto",
  "data": { ...campi della risorsa modificata... }
}
```
Event types: `task.created`, `task.updated`, `task.deleted`,
`check_item.created`, `check_item.updated`, `check_item.deleted`,
`bubble.created`, `bubble.deleted`,
`floorplan.created`, `floorplan.updated`, `floorplan.deleted`

---

## Architettura BOB Zone

### Database BOB

**Tabelle nuove:**

| Tabella | Scopo |
|---------|-------|
| `bb_worksites.fieldwire_project_id` | Link cantiere ↔ progetto Fieldwire |
| `bb_worksites.fieldwire_enabled_at` | Quando è stato collegato |
| `bb_worksites.fieldwire_enabled_by` | Chi l'ha collegato |
| `bb_zone_tasks` | Task BOB-nativi (con `fw_id` opzionale) |
| `bb_zone_task_comments` | Commenti sui task |
| `bb_zone_task_checklist` | Elementi checklist |
| `bb_fw_tasks` | Cache locale dei task Fieldwire (sync) |
| `bb_fw_check_items` | Cache locale checklist Fieldwire |
| `bb_fw_bubbles` | Cache locale messaggi Fieldwire |
| `bb_fw_floorplans` | Cache locale tavole Fieldwire |

**Decisione architetturale:** BOB legge sempre dal suo DB. Fieldwire API viene
usata solo per: scrittura (crea/aggiorna) e sync iniziale. I webhook tengono
il DB aggiornato in tempo reale.

### Flusso connessione Fieldwire

```
Utente clicca "Collega Fieldwire"
    → POST /worksites/{id}/zone/enable
    → ProjectsApi::create() → crea progetto su Fieldwire
    → Salva fieldwire_project_id in bb_worksites
    → InitialSyncService::run()
        → pull tutti i task Fieldwire → bb_fw_tasks
        → pull check items → bb_fw_check_items
        → pull bubbles → bb_fw_bubbles
        → pull floorplans → bb_fw_floorplans
    → Ricarica pagina
```

**DA FARE:** copiare i dati da `bb_fw_tasks` anche in `bb_zone_tasks` durante
la sync iniziale, e fare il push dei task BOB già esistenti verso Fieldwire.

### Flusso scrittura (BOB → Fieldwire)

```
Utente crea task da BOB Zone
    → Salva in bb_zone_tasks
    → Se cantiere ha fieldwire_project_id:
        → TasksApi::create() su Fieldwire
        → Salva fw_id in bb_zone_tasks.fw_id
```

### Flusso sync (Fieldwire → BOB)

```
Qualcuno modifica qualcosa su Fieldwire
    → Fieldwire chiama POST /api/fieldwire/webhook
    → WebhookHandler::dispatch()
    → Aggiorna bb_fw_* tables
    → (DA FARE) Aggiorna anche bb_zone_* tables
```

---

## Codice — Struttura file

```
src/Fieldwire/
├── FieldwireClient.php           → HTTP client (auth, retry, headers)
├── Api/
│   ├── ProjectsApi.php           → CRUD progetti
│   ├── TasksApi.php              → CRUD task
│   ├── CheckItemsApi.php         → CRUD checklist
│   ├── BubblesApi.php            → CRUD messaggi/foto
│   ├── FloorplansApi.php         → tavole + upload S3
│   └── MarkupsApi.php            → annotazioni su tavole
├── Sync/
│   ├── ProjectSync.php           → collega/scollega cantiere ↔ Fieldwire
│   └── InitialSyncService.php    → sync iniziale al momento della connessione
└── Webhook/
    └── WebhookHandler.php        → riceve eventi Fieldwire, aggiorna DB

src/Repository/Fieldwire/
├── ZoneTaskRepository.php        → CRUD bb_zone_tasks/comments/checklist
├── FwTaskRepository.php          → bb_fw_tasks (cache Fieldwire)
├── FwCheckItemRepository.php     → bb_fw_check_items
├── FwBubbleRepository.php        → bb_fw_bubbles
└── FwFloorplanRepository.php     → bb_fw_floorplans

src/Http/Controllers/
└── FieldwireController.php       → tutte le route /worksites/{id}/zone/*

templates/worksites/
├── view.html.twig                → pagina cantiere (bottone BOB Zone)
└── fieldwire.html.twig           → pagina BOB Zone standalone

db/migrations/
├── 20260624000001_fieldwire_worksite_link.php   → aggiunge campi a bb_worksites
├── 20260624000002_fieldwire_sync_tables.php     → crea bb_fw_* tables
└── 20260624000003_create_zone_tasks.php         → crea bb_zone_* tables
```

### Route `/worksites/{id}/zone/*`

```
GET  /worksites/{id}/zone                                    → pagina BOB Zone
POST /worksites/{id}/zone/enable                             → collega Fieldwire
POST /worksites/{id}/zone/disable                            → scollega Fieldwire
GET  /worksites/{id}/zone/tasks                              → lista task (JSON)
POST /worksites/{id}/zone/tasks                              → crea task
POST /worksites/{id}/zone/tasks/{taskId}/status              → aggiorna status
POST /worksites/{id}/zone/tasks/{taskId}/delete              → elimina task
GET  /worksites/{id}/zone/tasks/{taskId}/comments            → lista commenti
POST /worksites/{id}/zone/tasks/{taskId}/comments            → aggiungi commento
GET  /worksites/{id}/zone/tasks/{taskId}/checklist           → lista checklist
POST /worksites/{id}/zone/tasks/{taskId}/checklist           → aggiungi elemento
POST /worksites/{id}/zone/tasks/{taskId}/checklist/{itemId}/complete → spunta
GET  /worksites/{id}/zone/floorplans                         → lista tavole (JSON)
POST /api/fieldwire/webhook                                  → webhook Fieldwire (no auth)
```

---

## TODO — Ordinato per priorità

### 🔴 Prima di poter usare in produzione

- [ ] Configurare `.env`: `FIELDWIRE_API_TOKEN` e `FIELDWIRE_REGION=eu` (opzionale — BOB Zone funziona anche senza)
- [ ] Eseguire `vendor/bin/phinx migrate` su produzione (4 migration)
- [x] In `WorksitesController::show()` passare `fieldwire_project_id` e `fieldwire_enabled` alla view
- [x] Badge "FW" sul bottone BOB Zone quando il cantiere è collegato
- [ ] Testare e verificare che il bottone BOB Zone funzioni su server reale
- [ ] Verificare che la pagina BOB Zone carichi correttamente (task vuoti = 4 colonne Kanban)
- [ ] Testare creazione task da BOB Zone

### 🟠 Per avere Fieldwire funzionante

- [ ] Testare "Collega Fieldwire" end-to-end con token reale
- [ ] Registrare webhook su Fieldwire account:
      `app.fieldwire.com` → Impostazioni account → Webhooks → aggiungi URL
- [ ] Verificare che `InitialSyncService` non vada in timeout (se molti task)
      Soluzione: aggiungere paginazione o eseguire in background
- [x] Fare il push dei task `bb_zone_tasks` esistenti → Fieldwire al momento della connessione (OutboundSyncService)
- [x] `WebhookHandler` aggiornare `bb_zone_*` (riscritto in base alla nuova architettura)
- [x] Architettura unificata: bb_zone_* sono la SoT, bb_fw_tasks/check_items/bubbles deprecate

### 🟡 BOB Zone UX

- [x] **Modifica task** — endpoint `POST /zone/tasks/{id}/update` + modal "Modifica" nel pannello dettaglio
- [x] **Elimina task** — bottone con conferma + push delete su Fieldwire
- [x] **Elimina commento** — endpoint + bottone × + push delete su Fieldwire
- [x] **Elimina elemento checklist** — endpoint + bottone × + push delete su Fieldwire
- [x] **Drag & drop Kanban** — drag card tra colonne aggiorna status
- [x] **Assegnatario** — dropdown utenti BOB via endpoint `/zone/users`
- [x] **Filtri Kanban** — ricerca testo + assegnatario + categoria
- [x] **Foto sui task** — upload + fotocamera mobile, thumbnail nei commenti
- [x] **Notifiche** — notifica BOB all'assegnatario su create/cambio assegnazione
- [x] **Mobile-friendly** — layout responsive (header/toolbar/kanban/detail/annotator)
- [x] **Report PDF / Punch list** — export lista task raggruppata per stato
      con foto incorporate (dompdf + GD downscale)
- [ ] Notifica anche su nuovo commento (oltre assegnazione)

### 🟡 Tavole (Floorplans) / Disegni

- [x] Vista "Disegni" in BOB Zone (riusa i disegni del cantiere, BOB-native)
- [x] Upload / view / download / delete disegni da BOB Zone
- [x] Link "Apri in Fieldwire" punta alla tavola specifica (sheets/{fw_id})
- [x] Push disegno BOB → Fieldwire come sheet (flusso S3, FloorplanSync)
- [x] Viewer interattivo con annotazioni: pin→task, misure, frecce,
      rettangoli, ellissi, nuvole, testo, disegno libero
- [x] Calibrazione scala + misure in metri
- [ ] Sync annotazioni BOB ↔ markup Fieldwire (le annotazioni sono BOB-native;
      il push verso i markup FW richiede il sheet_id reale, da fare quando si
      testa contro Fieldwire live)
- [ ] Visualizzare thumbnail della tavola FW in BOB
- [ ] Multi-pagina: le annotazioni sono già per-pagina, testare PDF multipagina
- [ ] PDF.js worker via CSP: verificare su collaudo (worker da cdnjs)

### 🟢 Nice to have / futuro

- [ ] Validare autenticità webhook con HMAC (Fieldwire manda un secret header)
- [ ] Logging errori API Fieldwire con Monolog
- [ ] Decidere architettura finale: unificare `bb_fw_*` e `bb_zone_*` in un'unica tabella?
- [ ] Upload foto su commento da BOB Zone
- [ ] RFI e Submittals (Richieste di Informazione) — Fieldwire ha endpoint dedicati
- [ ] Form Fieldwire — visualizzare e compilare moduli da BOB Zone
- [ ] Notifiche BOB push quando arriva evento webhook da Fieldwire

---

## Note importanti

### Il `bb_fw_tasks` vs `bb_zone_tasks` — problema da risolvere

Attualmente ci sono due tabelle separate:
- `bb_zone_tasks` — task BOB-nativi, `fw_id` nullable
- `bb_fw_tasks` — cache dei task Fieldwire, `fw_id` obbligatorio (unique key)

La sync iniziale popola `bb_fw_tasks` ma non `bb_zone_tasks`. Questo significa
che se colleghi Fieldwire, i task Fieldwire esistenti non appaiono in BOB Zone.

**Soluzione proposta:** durante `InitialSyncService::run()`, per ogni task in
Fieldwire, creare anche una riga in `bb_zone_tasks` con `fw_id` impostato.
Poi usare solo `bb_zone_tasks` come sorgente di verità e deprecare `bb_fw_*`.

### Timeout sync iniziale

`InitialSyncService` fa chiamate API sincrone per ogni task (task → check items → bubbles).
Su progetti grandi (100+ task) questo può richiedere 30-60 secondi e andare in timeout PHP.

**Soluzione:** lanciare la sync in background dopo aver salvato `fieldwire_project_id`,
oppure paginarla (sync parziale + continua al prossimo accesso).

### Webhook non registrato su Fieldwire

Il codice per ricevere webhook è pronto (`/api/fieldwire/webhook`) ma Fieldwire
non sa dell'URL. Va registrato manualmente dall'account Fieldwire, oppure via API:
```
POST https://webhook-api.eu.fieldwire.com/subscriptions
Authorization: Bearer <access_token>
Fieldwire-Version: 2023-11-30
{"subscription": {"name": "BOB Zone", "url": "https://tuodominio.com/api/fieldwire/webhook", "active": true}}
```

### CSP (Content Security Policy)

La pagina BOB Zone usa `<script>` inline. Se il server ha CSP con `script-src 'self'`
senza `unsafe-inline`, il JS non gira. Tutti gli event handler sono già stati
convertiti da `onclick=""` a `addEventListener` per evitare questo problema.
Se i bottoni non rispondono, verificare i header CSP nelle DevTools del browser.
