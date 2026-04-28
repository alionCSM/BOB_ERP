<?php
/**
 * Portal — Privacy Policy (IT + EN)
 */

declare(strict_types=1);

// ── Bootstrap: autoloader + env + class aliases ──
$_portalDir = realpath(__DIR__);
$repoRoot   = null;
for ($_up = $_portalDir, $_i = 0; $_i < 4; $_up = dirname($_up), $_i++) {
    if (file_exists($_up . '/includes/bootstrap.php')) {
        $repoRoot = $_up;
        break;
    }
}
unset($_portalDir, $_up, $_i);

if ($repoRoot === null) {
    http_response_code(500);
    exit('Portal bootstrap error: cannot locate repo root.');
}

defined('APP_ROOT') || define('APP_ROOT', $repoRoot);

require_once $repoRoot . '/includes/bootstrap.php';

$_assetBase = rtrim($_ENV['APP_URL'] ?? '', '/');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Consorzio Soluzione Montaggi</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($_assetBase) ?>/assets/css/portal/privacy.css">
</head>
<body>
    <div class="topbar">
        <img src="https://bob.csmontaggi.it/includes/template/dist/images/logo.png" alt="Bob Logo" class="topbar-logo">
        <div class="topbar-divider"></div>
        <div class="topbar-brand">
            Consorzio Soluzione Montaggi
            <span>Portale Documenti</span>
        </div>
    </div>

    <div class="container">
        <a href="javascript:history.back()" class="back-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Torna indietro
        </a>

        <div class="top-row">
            <div class="lang-tabs">
                <button class="lang-tab active" onclick="switchLang('it', this)">Italiano</button>
                <button class="lang-tab" onclick="switchLang('en', this)">English</button>
            </div>
        </div>

        <div class="policy-card">
            <div class="policy-accent"></div>
            <div class="policy-body">

                <!-- ITALIAN -->
                <div id="lang-it" class="lang-section active">
                    <h1>Informativa sulla Privacy</h1>
                    <p class="last-update">Ultimo aggiornamento: <?= date('d/m/Y') ?></p>

                    <h2>1. Titolare del Trattamento</h2>
                    <p>Il titolare del trattamento dei dati personali raccolti attraverso questo portale
                       di condivisione documenti è:</p>
                    <p><strong>Consorzio Soluzione Montaggi</strong><br>
                       Via Bruno Tosarelli 322, 40055 Villanova di Castenaso (BO)<br>
                       P. IVA: IT03584711208<br>
                       Email: <a href="mailto:info@csmontaggi.it">info@csmontaggi.it</a></p>

                    <h2>2. Dati Raccolti</h2>
                    <p>Questo portale raccoglie le seguenti informazioni in modo automatico
                       durante l'utilizzo del servizio:</p>
                    <ul>
                        <li><strong>Indirizzo IP</strong> — registrato ai fini di sicurezza e per tracciare
                            i download dei documenti condivisi.</li>
                        <li><strong>Data e ora di accesso</strong> — relativi ai download effettuati.</li>
                        <li><strong>Nome del file scaricato</strong> — per finalità di audit e verifica.</li>
                    </ul>
                    <p>Non vengono raccolti dati personali aggiuntivi quali nome, cognome, email o
                       altri identificativi personali dell'utente che accede al portale.</p>

                    <h2>3. Finalità del Trattamento</h2>
                    <p>I dati raccolti vengono utilizzati esclusivamente per le seguenti finalità:</p>
                    <ul>
                        <li>Garantire la sicurezza del servizio e prevenire accessi non autorizzati.</li>
                        <li>Monitorare e verificare i download dei documenti condivisi (audit trail).</li>
                        <li>Rispettare eventuali obblighi di legge.</li>
                    </ul>

                    <h2>4. Base Giuridica</h2>
                    <p>Il trattamento dei dati si basa sul legittimo interesse del Titolare a
                       garantire la sicurezza del servizio e a tracciare l'accesso ai documenti
                       condivisi (art. 6, par. 1, lett. f, GDPR).</p>

                    <h2>5. Conservazione dei Dati</h2>
                    <p>I dati di accesso e download vengono conservati per un periodo massimo
                       di <strong>12 mesi</strong> dalla data di registrazione, salvo diversi
                       obblighi di legge che ne richiedano una conservazione più lunga.</p>

                    <h2>6. Condivisione dei Dati</h2>
                    <p>I dati raccolti non vengono condivisi con terze parti, ad eccezione di:</p>
                    <ul>
                        <li>Provider di hosting e infrastruttura tecnica necessari al funzionamento
                            del servizio.</li>
                        <li>Cloudflare, Inc. — utilizzato come servizio di proxy e protezione, che
                            potrebbe processare temporaneamente l'indirizzo IP dell'utente.</li>
                        <li>Google reCAPTCHA — utilizzato per la protezione anti-bot nelle pagine
                            protette da password. Si rimanda all'<a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Informativa Privacy di Google</a>.</li>
                        <li>Autorità competenti, ove richiesto dalla legge.</li>
                    </ul>

                    <h2>7. Diritti dell'Interessato</h2>
                    <p>Ai sensi del Regolamento (UE) 2016/679 (GDPR), l'utente ha diritto di:</p>
                    <ul>
                        <li>Accedere ai propri dati personali.</li>
                        <li>Richiederne la rettifica o la cancellazione.</li>
                        <li>Opporsi al trattamento o richiederne la limitazione.</li>
                        <li>Proporre reclamo all'Autorità Garante per la protezione dei dati personali.</li>
                    </ul>
                    <p>Per esercitare tali diritti, contattare il Titolare all'indirizzo
                       <a href="mailto:info@csmontaggi.it">info@csmontaggi.it</a>.</p>

                    <h2>8. Cookie</h2>
                    <p>Questo portale utilizza esclusivamente <strong>cookie tecnici di sessione</strong>,
                       necessari per il funzionamento del servizio (ad esempio, per mantenere
                       lo stato di autenticazione dopo l'inserimento della password).
                       Non vengono utilizzati cookie di profilazione o di tracciamento.</p>

                    <h2>9. Modifiche</h2>
                    <p>Il Titolare si riserva il diritto di aggiornare la presente informativa
                       in qualsiasi momento. Le modifiche saranno effettive dalla data di
                       pubblicazione su questa pagina.</p>
                </div>

                <!-- ENGLISH -->
                <div id="lang-en" class="lang-section">
                    <h1>Privacy Policy</h1>
                    <p class="last-update">Last updated: <?= date('d/m/Y') ?></p>

                    <h2>1. Data Controller</h2>
                    <p>The data controller for personal data collected through this document sharing
                       portal is:</p>
                    <p><strong>Consorzio Soluzione Montaggi</strong><br>
                       Via Bruno Tosarelli 322, 40055 Villanova di Castenaso (BO), Italy<br>
                       VAT: IT03584711208<br>
                       Email: <a href="mailto:info@csmontaggi.it">info@csmontaggi.it</a></p>

                    <h2>2. Data Collected</h2>
                    <p>This portal automatically collects the following information during use
                       of the service:</p>
                    <ul>
                        <li><strong>IP Address</strong> — recorded for security purposes and to track
                            shared document downloads.</li>
                        <li><strong>Date and time of access</strong> — related to downloads performed.</li>
                        <li><strong>Downloaded file name</strong> — for audit and verification purposes.</li>
                    </ul>
                    <p>No additional personal data such as name, surname, email address, or other
                       personal identifiers of the portal user are collected.</p>

                    <h2>3. Purpose of Processing</h2>
                    <p>The collected data is used exclusively for the following purposes:</p>
                    <ul>
                        <li>Ensuring the security of the service and preventing unauthorized access.</li>
                        <li>Monitoring and verifying downloads of shared documents (audit trail).</li>
                        <li>Complying with any legal obligations.</li>
                    </ul>

                    <h2>4. Legal Basis</h2>
                    <p>Data processing is based on the legitimate interest of the Data Controller
                       in ensuring the security of the service and tracking access to shared
                       documents (Art. 6(1)(f) GDPR).</p>

                    <h2>5. Data Retention</h2>
                    <p>Access and download data is retained for a maximum period of
                       <strong>12 months</strong> from the date of recording, unless different
                       legal obligations require longer retention.</p>

                    <h2>6. Data Sharing</h2>
                    <p>Collected data is not shared with third parties, except for:</p>
                    <ul>
                        <li>Hosting and technical infrastructure providers necessary for the
                            operation of the service.</li>
                        <li>Cloudflare, Inc. — used as a proxy and protection service, which may
                            temporarily process the user's IP address.</li>
                        <li>Google reCAPTCHA — used for anti-bot protection on password-protected
                            pages. Please refer to <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google's Privacy Policy</a>.</li>
                        <li>Competent authorities, where required by law.</li>
                    </ul>

                    <h2>7. User Rights</h2>
                    <p>Under Regulation (EU) 2016/679 (GDPR), users have the right to:</p>
                    <ul>
                        <li>Access their personal data.</li>
                        <li>Request rectification or erasure.</li>
                        <li>Object to processing or request restriction.</li>
                        <li>Lodge a complaint with the competent Data Protection Authority.</li>
                    </ul>
                    <p>To exercise these rights, please contact the Data Controller at
                       <a href="mailto:info@csmontaggi.it">info@csmontaggi.it</a>.</p>

                    <h2>8. Cookies</h2>
                    <p>This portal uses only <strong>technical session cookies</strong>, necessary
                       for the operation of the service (e.g., to maintain authentication state
                       after password entry). No profiling or tracking cookies are used.</p>

                    <h2>9. Changes</h2>
                    <p>The Data Controller reserves the right to update this privacy policy at
                       any time. Changes will be effective from the date of publication on
                       this page.</p>
                </div>

            </div>
        </div>
    </div>

    <footer class="portal-footer">
        &copy; <?= date('Y') ?> Consorzio Soluzione Montaggi &mdash; Tutti i diritti riservati
    </footer>

    <script src="<?= htmlspecialchars($_assetBase) ?>/assets/js/portal/privacy.js"></script>
</body>
</html>
