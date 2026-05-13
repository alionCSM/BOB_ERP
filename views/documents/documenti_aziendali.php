<?php
// $workerId and $user are already in scope from UsersController::show()
// $connection is set from $GLOBALS in the render method

assertCompanyScopeWorkerAccess($connection, $user, $workerId);

// Fetch aziendali documents
$query = "SELECT id, tipo_documento, data_emissione, scadenza, path FROM bb_worker_documents WHERE worker_id = :worker_id";
$stmt = $connection->prepare($query);
$stmt->bindParam(':worker_id', $workerId, PDO::PARAM_INT);
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Worker ID meta for JavaScript -->
<meta id="doc-worker-meta" data-worker-id="<?= $workerId ?>">

<div id="documenti-aziendali">
    <h3 class="text-lg font-medium mb-4">Documenti Aziendali</h3>

    <!-- Button to Open Modal -->
    <button class="btn btn-primary mb-4" data-tw-toggle="modal" data-tw-target="#upload-document-modal">
        + Aggiungi Nuovo Documento
    </button>

    <button id="btn-open-check-modal" class="btn btn-primary mb-4" data-tw-toggle="modal" data-tw-target="#check-document-modal">
        Controllo Documenti
    </button>

    <!-- Uploaded Documents Table -->
    <table class="table-auto w-full border-collapse border border-gray-300">
        <thead>
        <tr class="bg-gray-200">
            <th class="border p-3 text-left">Tipo Documento</th>
            <th class="border p-3 text-left">Data Emissione</th>
            <th class="border p-3 text-left">Scadenza</th>
            <th class="border p-3 text-center">Azione</th>
        </tr>
        </thead>
        <tbody id="document-list">
        <?php if (!empty($documents)): ?>
            <?php foreach ($documents as $doc): ?>
                <tr>
                    <td class="border p-3 text-left"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                    <td class="border p-3 text-left">
                        <?= htmlspecialchars($doc['data_emissione']) ?>
                    </td>
                    <td class="border p-3 text-left">
                        <?php
                        $raw = trim($doc['scadenza']); // valore originale VARCHAR
                        $oggi = strtotime(date('Y-m-d'));
                        $colore = '#6b7280'; // colore grigio default (per "nessuna scadenza")

                        // 1. Se scadenza è vuota o stringa speciale → nessuna scadenza
                        if ($raw === '' ||
                                strtolower($raw) === 'indeterminato' ||
                                strtolower($raw) === 'senza scadenza' ||
                                strtolower($raw) === 'n/a' ||
                                $raw === '00/00/0000') {

                            $colore = '#6b7280'; // grigio
                        }
// 2. Se è una data valida (dd/mm/YYYY)
                        elseif (preg_match('/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}$/', $raw)) {

                            $scadenza = DateTime::createFromFormat('d/m/Y', $raw);

                            if ($scadenza !== false) {
                                $timestamp_scadenza = $scadenza->getTimestamp();
                                $differenza = ($timestamp_scadenza - $oggi) / 86400;

                                if ($differenza < 0) {
                                    $colore = '#ef4444'; // rosso
                                } elseif ($differenza <= 30) {
                                    $colore = '#CCB000'; // arancione
                                } else {
                                    $colore = '#22c55e'; // verde
                                }
                            }
                        }
// 3. Se è un formato diverso → nessuna scadenza
                        else {
                            $colore = '#6b7280'; // grigio
                        }

                        ?>
                        <div class="flex items-center gap-2">
                            <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background-color:<?= $colore ?>;"></span>
                            <span><?= htmlspecialchars($doc['scadenza']) ?></span>
                        </div>
                    </td>

                    <td class="border p-3 text-center">
                        <a href="/documents/serve?id=<?= $doc['id'] ?>" target="_blank">
                            <i class="fas fa-file-pdf fa-lg text-blue-500"></i>
                        </a>

                        <a href="#" class="mx-2 text-yellow-500 wd-edit-btn" title="Modifica"
                           data-doc-id="<?= $doc['id'] ?>"
                           data-doc-type="<?= htmlspecialchars($doc['tipo_documento'], ENT_QUOTES) ?>"
                           data-doc-emission="<?= htmlspecialchars((string)($doc['data_emissione'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           data-doc-expiry="<?= htmlspecialchars((string)($doc['scadenza'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-edit fa-lg"></i>
                        </a>

                        <a href="#" class="mx-2 text-red-500 wd-delete-btn" title="Elimina"
                           data-doc-id="<?= $doc['id'] ?>">
                            <i class="fas fa-trash-alt fa-lg"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4" class="text-center p-3">Nessun documento trovato.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal for Uploading Documents -->
<div id="upload-document-modal"
     class="modal"
     tabindex="-1"
     aria-hidden="true"
     data-tw-backdrop="static"
     data-tw-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <form id="document-upload-form" enctype="multipart/form-data" class="modal-content wd-modal">
            <!-- Header con icona + sottotitolo -->
            <div class="modal-header wd-modal-header">
                <div class="wd-modal-header-left">
                    <div class="wd-modal-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="12" y1="18" x2="12" y2="12"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="wd-modal-title">Nuovo documento</h2>
                        <p class="wd-modal-sub">Carica un PDF — BOB ti aiuta a compilare i campi</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-tw-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body wd-modal-body">
                <input type="hidden" name="worker_id" value="<?= $workerId ?>">

                <!-- Dropzone-style file input -->
                <label class="wd-dropzone" for="document_file">
                    <div class="wd-dropzone-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <div class="wd-dropzone-text">
                        <strong id="wd-dropzone-filename">Scegli un file PDF</strong>
                        <span>oppure trascinalo qui</span>
                    </div>
                    <input type="file" id="document_file" name="document_file" accept="application/pdf" required>
                </label>

                <!-- Banner BOB (compatto, sotto la dropzone) -->
                <div id="wd-ai-banner" class="wd-ai-banner" style="display:none;">
                    <img class="wd-ai-icon" src="/includes/template/dist/images/logo.png" alt="BOB" />
                    <span id="wd-ai-banner-text">BOB sta leggendo…</span>
                </div>

                <!-- Campi metadata -->
                <div class="wd-fields">
                    <div class="wd-field">
                        <label for="wd-upload-type">Tipo documento <span class="wd-req">*</span></label>
                        <input type="text"
                               id="wd-upload-type"
                               name="document_type"
                               placeholder="Es. Visita medica, Unilav, Patente…"
                               list="document-type-suggestions"
                               autocomplete="off"
                               required>
                    </div>
                    <datalist id="document-type-suggestions">
                        <?php foreach (\App\Domain\WorkerDocumentTypes::all() as $_type): ?>
                            <option value="<?= htmlspecialchars($_type, ENT_QUOTES) ?>">
                        <?php endforeach; ?>
                    </datalist>

                    <div class="wd-field-row">
                        <div class="wd-field">
                            <label for="wd-upload-emission">Data emissione <span class="wd-req">*</span></label>
                            <input type="text" id="wd-upload-emission" name="date_emission" placeholder="gg/mm/aaaa" required>
                        </div>
                        <div class="wd-field">
                            <label for="wd-upload-expiry">Scadenza</label>
                            <input type="text" id="wd-upload-expiry" name="expiry_date" placeholder="gg/mm/aaaa o INDETERMINATO">
                            <div id="wd-upload-expiry-hint" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer wd-modal-footer">
                <button type="button" data-tw-dismiss="modal" class="btn btn-outline-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary wd-btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="margin-right:6px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Carica documento
                </button>
            </div>

            <style>
                /* ── Modal restyle ───────────────────────────────────────── */
                .wd-modal { border-radius: 14px; overflow: hidden; }
                .wd-modal-header {
                    background: linear-gradient(135deg, #fafafa 0%, #f5f3ff 100%);
                    border-bottom: 1px solid #e2e8f0;
                    padding: 18px 22px;
                    align-items: flex-start;
                }
                .wd-modal-header-left { display: flex; gap: 14px; align-items: center; flex: 1; }
                .wd-modal-icon {
                    width: 38px; height: 38px; flex-shrink: 0;
                    background: #ede9fe;
                    border-radius: 10px;
                    display: flex; align-items: center; justify-content: center;
                    color: #6d28d9;
                }
                .wd-modal-icon svg { width: 20px; height: 20px; }
                .wd-modal-title { font-size: 17px; font-weight: 600; margin: 0; color: #0f172a; }
                .wd-modal-sub { font-size: 12px; color: #64748b; margin: 2px 0 0; }

                .wd-modal-body { padding: 22px; }

                /* Dropzone */
                .wd-dropzone {
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    padding: 18px 18px;
                    border: 2px dashed #cbd5e1;
                    border-radius: 10px;
                    background: #f8fafc;
                    cursor: pointer;
                    transition: all .15s ease;
                    margin: 0;
                }
                .wd-dropzone:hover {
                    border-color: #8b5cf6;
                    background: #f5f3ff;
                }
                .wd-dropzone.is-file-selected {
                    border-style: solid;
                    border-color: #10b981;
                    background: #ecfdf5;
                }
                .wd-dropzone-optional {
                    /* Modal di modifica: file opzionale, look meno "obbligatorio" */
                    padding: 14px 16px;
                    border-color: #e2e8f0;
                    background: #fafafa;
                }
                .wd-dropzone-optional .wd-dropzone-icon { width: 36px; height: 36px; }
                .wd-dropzone-optional .wd-dropzone-icon svg { width: 18px; height: 18px; }
                .wd-dropzone-optional .wd-dropzone-text strong { font-weight: 500; color: #64748b; }
                .wd-dropzone-optional .wd-dropzone-text span { color: #94a3b8; }
                .wd-dropzone input[type=file] {
                    position: absolute;
                    width: 1px; height: 1px;
                    opacity: 0;
                }
                .wd-dropzone-icon {
                    width: 42px; height: 42px; flex-shrink: 0;
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    display: flex; align-items: center; justify-content: center;
                    color: #64748b;
                }
                .wd-dropzone-icon svg { width: 22px; height: 22px; }
                .wd-dropzone.is-file-selected .wd-dropzone-icon { color: #059669; border-color: #a7f3d0; }
                .wd-dropzone-text { display: flex; flex-direction: column; }
                .wd-dropzone-text strong { color: #0f172a; font-size: 14px; font-weight: 600; }
                .wd-dropzone-text span { color: #64748b; font-size: 12px; margin-top: 2px; }

                /* Fields */
                .wd-fields { margin-top: 14px; display: flex; flex-direction: column; gap: 14px; }
                .wd-field { display: flex; flex-direction: column; gap: 6px; }
                .wd-field label {
                    font-size: 12px; font-weight: 500; color: #475569;
                    text-transform: uppercase; letter-spacing: .4px;
                }
                .wd-field input {
                    height: 38px;
                    padding: 0 12px;
                    border: 1px solid #cbd5e1;
                    border-radius: 7px;
                    font-size: 14px;
                    color: #0f172a;
                    background: #fff;
                    transition: border-color .12s ease;
                }
                .wd-field input:focus {
                    outline: 2px solid rgba(99,102,241,.18);
                    border-color: #6366f1;
                }
                .wd-field input::placeholder { color: #94a3b8; }
                .wd-field-row {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 14px;
                }
                .wd-req { color: #ef4444; }

                /* Footer */
                .wd-modal-footer {
                    border-top: 1px solid #e2e8f0;
                    padding: 14px 22px;
                    background: #fafafa;
                }
                .wd-btn-submit { display: inline-flex; align-items: center; }

                /* Banner BOB compatto */
                .wd-ai-banner {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    background: #f5f3ff;
                    border-left: 3px solid #8b5cf6;
                    border-radius: 4px;
                    padding: 7px 10px;
                    margin-top: 10px;
                    font-size: 12px;
                    color: #475569;
                    line-height: 1.4;
                }
                .wd-ai-banner.is-loading {
                    background: linear-gradient(90deg, #f5f3ff 0%, #ede9fe 50%, #f5f3ff 100%);
                    background-size: 200% 100%;
                    animation: wdAiShimmer 1.6s ease-in-out infinite;
                }
                .wd-ai-banner.is-done    { background: #ecfdf5; border-left-color: #10b981; }
                .wd-ai-banner.is-error   { background: #fef2f2; border-left-color: #ef4444; }
                .wd-ai-banner.is-name-warning { background: #fef2f2; border-left-color: #ef4444; }
                .wd-ai-icon { width: 24px; height: 24px; object-fit: contain; flex-shrink: 0; }
                @keyframes wdAiShimmer {
                    0%, 100% { background-position: 0% 0%; }
                    50%      { background-position: -100% 0%; }
                }

                /* Chip suggerimento sotto al campo */
                .wd-ai-chip {
                    font-size: 11px;
                    color: #6d28d9;
                    background: #f5f3ff;
                    border: 1px dashed #c4b5fd;
                    border-radius: 4px;
                    padding: 5px 9px;
                    margin-top: 6px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    line-height: 1.4;
                }
                .wd-ai-chip-icon {
                    width: 18px;
                    height: 18px;
                    object-fit: contain;
                    flex-shrink: 0;
                }
                .wd-ai-chip-text { flex: 1; min-width: 0; }
                .wd-ai-chip strong { color: #4c1d95; font-weight: 600; white-space: nowrap; }
                .wd-ai-chip-apply {
                    color: #6d28d9;
                    text-decoration: underline;
                    cursor: pointer;
                    font-weight: 500;
                    white-space: nowrap;
                }
                .wd-ai-chip-apply:hover { color: #4c1d95; }
            </style>
        </form>
    </div>
</div>

<!-- Modal Modifica Documenti -->
<div id="edit-document-modal"
     class="modal"
     tabindex="-1"
     aria-hidden="true"
     data-tw-backdrop="static"
     data-tw-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <form id="edit-document-form" enctype="multipart/form-data" class="modal-content wd-modal">
            <div class="modal-header wd-modal-header">
                <div class="wd-modal-header-left">
                    <div class="wd-modal-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="wd-modal-title">Modifica documento</h2>
                        <p class="wd-modal-sub">Aggiorna i dati. Se carichi un nuovo PDF, BOB lo legge e suggerisce.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-tw-dismiss="modal" aria-label="Chiudi"></button>
            </div>

            <div class="modal-body wd-modal-body">
                <input type="hidden" id="edit-doc-id" name="document_id">

                <!-- Dropzone: file opzionale (per sostituire il PDF) -->
                <label class="wd-dropzone wd-dropzone-optional" for="edit-doc-file">
                    <div class="wd-dropzone-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <div class="wd-dropzone-text">
                        <strong id="edit-dropzone-filename">Sostituisci il PDF (opzionale)</strong>
                        <span>lascia vuoto per tenere il file esistente</span>
                    </div>
                    <input type="file" id="edit-doc-file" name="document_file" accept="application/pdf">
                </label>

                <!-- Banner BOB (solo se l'utente carica un nuovo file) -->
                <div id="edit-ai-banner" class="wd-ai-banner" style="display:none;">
                    <img class="wd-ai-icon" src="/includes/template/dist/images/logo.png" alt="BOB" />
                    <span id="edit-ai-banner-text">BOB sta leggendo…</span>
                </div>

                <div class="wd-fields">
                    <div class="wd-field">
                        <label for="edit-doc-type">Tipo documento <span class="wd-req">*</span></label>
                        <input type="text"
                               id="edit-doc-type"
                               name="document_type"
                               list="document-type-suggestions"
                               autocomplete="off"
                               required>
                    </div>

                    <div class="wd-field-row">
                        <div class="wd-field">
                            <label for="edit-doc-date-emission">Data emissione <span class="wd-req">*</span></label>
                            <input type="text" id="edit-doc-date-emission" name="date_emission" placeholder="gg/mm/aaaa" required>
                        </div>
                        <div class="wd-field">
                            <label for="edit-doc-expiry">Scadenza</label>
                            <input type="text" id="edit-doc-expiry" name="expiry_date" placeholder="gg/mm/aaaa o INDETERMINATO">
                            <div id="wd-edit-expiry-hint" style="display:none;font-size:12px;color:#94a3b8;margin-top:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer wd-modal-footer">
                <button type="button" data-tw-dismiss="modal" class="btn btn-outline-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary wd-btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="margin-right:6px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Salva modifiche
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Controllo Documenti -->
<div id="check-document-modal"
     class="modal modal-xl"
     tabindex="-1"
     aria-hidden="true"
     data-tw-backdrop="static"
     data-tw-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="font-medium text-lg">Controllo Documenti</h2>
                <button type="button" class="btn-close" data-tw-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body" id="check-documents-body">
                <div class="text-center py-10 text-slate-500">
                    <i data-lucide="loader" class="w-8 h-8 animate-spin mx-auto mb-3"></i>
                    Caricamento in corso...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" data-tw-dismiss="modal" class="btn btn-outline-secondary">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/views/documents/documenti_aziendali.js?v=2"></script>

