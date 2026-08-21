<?php

/**
 * Pipeline pre-controller delle rotte /api/v1/* (app Android).
 *
 * Stessa logica di includes/middleware.php adattata a un client API:
 *   - autenticazione solo con Bearer token (ApiAuthMiddleware);
 *   - risposte in JSON (401/403/409) invece di redirect HTML;
 *   - nessun CSRF (il client non usa cookie);
 *   - societa' del gruppo attiva: stessa regola del web — con una sola
 *     societa' disponibile si sceglie da sola, con piu' di una il client
 *     deve chiamare /api/v1/switch-company (risposta 409).
 *
 * Le rotte /api/* gia' esistenti (AJAX del sito) non toccano questo file.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Http\Middleware\ApiAuthMiddleware;

$db = new Database();
$connection = $db->connect();
$GLOBALS['connection'] = $connection;

function api_v1_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Authentication (Bearer) ───────────────────────────────────────────────────
(new ApiAuthMiddleware($connection))->handle();

/** @var \User $user */
$user = $GLOBALS['user'];

// ── Forced password change → 403 JSON (il client mostra la schermata dedicata)
if (!empty($user->must_change_password)) {
    api_v1_json([
        'success' => false,
        'code'    => 'must_change_password',
        'message' => "La password e' scaduta: devi cambiarla prima di continuare.",
        'url'     => rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . '/change-password',
    ], 403);
}

// ── Societa' del gruppo attiva ────────────────────────────────────────────────
// ATTENZIONE: il client mobile NON conserva la sessione PHP (OkHttp senza
// cookie jar), quindi ogni richiesta arriva con $_SESSION vuoto. La societa'
// "vera" e' quella persistita sul token (bb_api_tokens.group_company_id,
// scritta da /api/v1/switch-company); qui la riapplico alla sessione della
// richiesta cosi' TUTTI i lettori (CurrentCompany::id(), User::companyAllows,
// filtri per societa') vedono lo stesso valore del web.
$currentCompany = new \App\Service\CurrentCompany($connection);
$GLOBALS['currentCompany'] = $currentCompany;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}

// operai e clienti restano fuori dalla scelta societa' (come nel web);
// /api/v1/switch-company e' la rotta che FA la scelta
$skipSelection = in_array($user->type, ['worker', 'client'], true)
    || $uri === '/api/v1/switch-company';

if (!$skipSelection && !isset($_SESSION[\App\Service\CurrentCompany::SESSION_KEY])) {
    $stored = (int)($GLOBALS['api_token_company'] ?? 0);

    // 1) Scelta persistita sul token: ancora valida?
    if (!($stored > 0 && $currentCompany->select((int)$user->id, $stored))) {
        // Scelta revocata (es. tolto dalla societa'): la spengo sul token
        // e riparto dalla regola classica.
        if ($stored > 0) {
            try {
                $connection->prepare("
                    UPDATE bb_api_tokens
                    SET    group_company_id = NULL
                    WHERE  user_id = :uid AND group_company_id = :c
                ")->execute([':uid' => (int)$user->id, ':c' => $stored]);
            } catch (\Throwable $e) {
                // colonna assente (pre-migrazione): ignora
            }
        }

        // 2) Regola classica (stessa del web): una sola societa' si sceglie da sola
        $auto = $currentCompany->autoSelectOnLogin((int)$user->id);
        if (!$auto) {
            api_v1_json([
                'success'    => false,
                'code'       => 'needs_company_selection',
                'message'    => 'Seleziona la societa\' del gruppo con cui vuoi lavorare.',
                'companies'  => $currentCompany->availableFor((int)$user->id),
            ], 409);
        }
    }
}

// I permessi sono per societa': ApiAuthMiddleware li ha caricati prima che la
// societa' fosse applicata, quindi vanno riletti adesso (stesso passaggio del
// middleware web).
$user->loadPermissions();
