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
    <div class="modal-dialog">
        <form id="document-upload-form" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h2 class="font-medium text-lg">Carica Nuovo Documento</h2>
                <button type="button" class="btn-close" data-tw-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="worker_id" value="<?= $workerId ?>">

                <div class="mb-3">
                    <label class="form-label">Tipo Documento</label>
                    <input type="text"
                           id="wd-upload-type"
                           name="document_type"
                           class="form-control"
                           placeholder="Tipo documento..."
                           list="document-type-suggestions"
                           autocomplete="off"
                           required>
                </div>
                <datalist id="document-type-suggestions">
                    <option value="Documento d'identità">
                    <option value="Verbale consegna DPI">
                    <option value="Visita medica">
                    <option value="Unilav">
                    <option value="Formazione sicurezza">
                    <option value="Lavori in quota DPI">
                    <option value="Piattaforma">
                    <option value="Carrello elevatore">
                    <option value="Braccio telescopico">
                    <option value="Preposto">
                    <option value="Antincendio">
                    <option value="Primo soccorso">
                    <option value="Gru a torre">
                    <option value="Gru mobile">
                    <option value="Saldatura">
                </datalist>
                <div class="mb-3">
                    <label class="form-label">Data Emissione</label>
                    <input type="text" id="wd-upload-emission" name="date_emission" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Scadenza</label>
                    <input type="text" id="wd-upload-expiry" name="expiry_date" class="form-control">
                    <div id="wd-upload-expiry-hint" style="display:none;font-size:12px;color:#94a3b8;margin-top:4px;"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Carica Documento</label>
                    <input type="file" id="document_file" name="document_file" class="form-control" accept="application/pdf" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" data-tw-dismiss="modal" class="btn btn-outline-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Carica</button>
            </div>
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
    <div class="modal-dialog">
        <form id="edit-document-form" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h2 class="font-medium text-lg">Modifica Documento</h2>
                <button type="button" class="btn-close" data-tw-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-doc-id" name="document_id">

                <div class="mb-3">
                    <label class="form-label">Tipo Documento</label>
                    <input type="text" id="edit-doc-type" name="document_type" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Data Emissione</label>
                    <input type="text" id="edit-doc-date-emission" name="date_emission" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Scadenza</label>
                    <input type="text" id="edit-doc-expiry" name="expiry_date" class="form-control">
                    <div id="wd-edit-expiry-hint" style="display:none;font-size:12px;color:#94a3b8;margin-top:4px;"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Sostituisci Documento (PDF)</label>
                    <input type="file" id="edit-doc-file" name="document_file" class="form-control" accept="application/pdf">
                    <small class="text-slate-500">Lascia vuoto se non vuoi sostituire il file esistente</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" data-tw-dismiss="modal" class="btn btn-outline-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva Modifiche</button>
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

