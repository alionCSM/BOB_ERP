# Fieldwire Integration — TODO

## Critico (da fare prima del go-live)

- [ ] **Run migrations on production**
  - `vendor/bin/phinx migrate` (migration 001, 002, 003)

- [ ] **Configurare `.env` production**
  - `FIELDWIRE_API_TOKEN=<refresh_token_da_app.fieldwire.com>`
  - `FIELDWIRE_REGION=eu`

- [ ] **Passare `fieldwire_project_id` e `fieldwire_enabled` a `view.html.twig`**
  - `WorksitesController::show()` deve aggiungere queste variabili al render
  - Attualmente il bottone BOB Zone appare sempre ma non sa se il cantiere è già collegato

- [ ] **Testare il flusso "Collega Fieldwire" end-to-end**
  - Clicca "Collega Fieldwire" → crea progetto su Fieldwire → sync iniziale → ricarica pagina
  - Verificare che `fieldwire_project_id` venga salvato in `bb_worksites`

- [ ] **Testare autenticazione Fieldwire**
  - Verificare che il refresh token → access token funzioni correttamente
  - Verificare che i rate limit vengano gestiti (HTTP 429)

---

## BOB Zone — Features mancanti

- [ ] **Modifica task** — form per aggiornare nome, assegnatario, date, categoria
  - Endpoint `POST /worksites/{id}/zone/tasks/{taskId}/update` da aggiungere
  - UI: bottone "Modifica" nel pannello dettaglio

- [ ] **Elimina task** — bottone elimina con conferma nel pannello dettaglio
  - Endpoint già presente (`/zone/tasks/{taskId}/delete`)
  - UI mancante

- [ ] **Elimina commento** — bottone elimina su ogni commento

- [ ] **Elimina elemento checklist** — bottone X su ogni riga checklist

- [ ] **Upload foto su commento** — allegare immagine a un commento (bubble con foto)
  - Richiede upload file al server + salvataggio URL

- [ ] **Drag & drop Kanban** — spostare card tra colonne aggiorna lo status
  - Attualmente il status si cambia solo dal pannello dettaglio

- [ ] **Task assegnatario** — collegare a utenti BOB invece di testo libero
  - Dropdown utenti del cantiere invece di input text

- [ ] **Filtri task** — filtro per status, assegnatario, data nel Kanban/Gantt/Calendario

- [ ] **Priorità task** — visualizzare e modificare la priorità (già in DB, non visibile in UI)

---

## Sync Fieldwire ↔ BOB Zone

- [ ] **Push task BOB → Fieldwire al momento della connessione**
  - Se il cantiere ha già task in `bb_zone_tasks` prima di collegare Fieldwire,
    mandarli tutti a Fieldwire durante `enable()`

- [ ] **Pull task Fieldwire → `bb_zone_tasks` durante sync iniziale**
  - `InitialSyncService` popola `bb_fw_tasks` ma non `bb_zone_tasks`
  - Decidere: usare `bb_fw_tasks` come sorgente oppure copiare in `bb_zone_tasks`

- [ ] **Webhook — registrare l'endpoint su Fieldwire**
  - Andare su Fieldwire → Account → Webhooks → aggiungere:
    `https://<dominio>/api/fieldwire/webhook`
  - Selezionare eventi: `task.*`, `check_item.*`, `bubble.*`, `floorplan.*`

- [ ] **Webhook — aggiornare `bb_zone_tasks` quando arriva evento da Fieldwire**
  - Attualmente `WebhookHandler` aggiorna solo `bb_fw_*` tables
  - Aggiungere sync su `bb_zone_tasks` / `bb_zone_task_comments` / `bb_zone_task_checklist`

- [ ] **Gestire conflitti di sync** — cosa succede se stesso task viene modificato sia su BOB che su Fieldwire contemporaneamente?

---

## Tavole (Floorplans)

- [ ] **Visualizzare tavole Fieldwire nel tab "Tavole"** — attualmente carica da `bb_fw_floorplans`
  - Link "Apri in Fieldwire" funziona ma porta alla homepage Fieldwire, non alla tavola specifica
  - Usare l'URL diretto: `https://app.fieldwire.com/projects/{fw_project_id}/sheets/{fw_floorplan_id}`

- [ ] **Upload tavola da BOB → Fieldwire**
  - `FloorplansApi::createUpload()` già implementato (flusso S3)
  - Manca UI e gestione del flusso di upload a due step

---

## Infrastruttura

- [ ] **Webhook autenticità** — validare che le chiamate arrivino davvero da Fieldwire
  - Aggiungere verifica HMAC o IP allowlist

- [ ] **Gestione token scaduto** — il client già fa retry su 401, testare in produzione

- [ ] **Logging chiamate Fieldwire** — loggare errori API con Monolog (già usato in BOB)

- [ ] **Job asincrono per sync iniziale** — `InitialSyncService::run()` può essere lento
  - Se il progetto Fieldwire ha molti task, la richiesta HTTP va in timeout
  - Soluzioni: background job, oppure sync paginata

- [ ] **Rimuovere `bb_fw_*` tables o integrarle con `bb_zone_*`**
  - `bb_fw_tasks` e `bb_zone_tasks` hanno dati sovrapposti
  - Decidere architettura finale: una tabella sola o due distinte

---

## Testing

- [ ] Testare Kanban con task reali
- [ ] Testare Gantt con task con date
- [ ] Testare Calendario con task con scadenza
- [ ] Testare commenti (invia, visualizza)
- [ ] Testare checklist (aggiungi, spunta)
- [ ] Testare "Collega Fieldwire" su cantiere reale
- [ ] Testare ricezione webhook da Fieldwire
