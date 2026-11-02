<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

/**
 * L'app per l'ufficio, la parte "da scrivania": offerte, clienti, aziende,
 * prenotazioni, ordini alle aziende, fatturato e la rubrica degli operai.
 *
 * Dal telefono si guardano e basta: si creano e si modificano dal sito.
 * Servono a rispondere in piedi a "a che punto e' l'offerta per Rossi?" o
 * "in che hotel dorme la squadra di Bologna?".
 *
 * Stessi repository e stessi filtri delle pagine web, stessi permessi della
 * societa' attiva (/api/v1/switch-company).
 */
final class ApiV1ScrivaniaController
{
    public function __construct(private \PDO $conn) {}

    // ── Offerte ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/ufficio/offerte?q=&stato=
     * Come la lista del sito: chi lavora nella societa' 1 le vede tutte,
     * gli altri solo quelle della loro.
     */
    public function offerte(Request $request): never
    {
        $this->pretende($request, 'offers');
        $azienda = (int)($request->user()->getCompanyId() ?? 0);
        if ($azienda <= 0) {
            Response::json(['success' => true, 'offerte' => []]);
        }
        $righe = (new \App\Service\Offers\OfferController($this->conn))->getVisibleOffers($azienda);

        $q     = mb_strtolower(trim((string)($_GET['q'] ?? '')));
        $stato = trim((string)($_GET['stato'] ?? ''));
        $out   = [];
        foreach ($righe as $o) {
            if ($stato !== '' && ($o['status'] ?? '') !== $stato) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower(
                $o['offer_number'] . ' ' . $o['subject'] . ' ' . $o['client_name'] . ' ' . $o['company_name']
            ), $q)) {
                continue;
            }
            $out[] = [
                'id'       => (int)$o['id'],
                'numero'   => $o['offer_number'],
                'oggetto'  => $o['subject'],
                'cliente'  => $o['client_name'],
                'societa'  => $o['company_name'],
                'stato'    => $o['status'],
                'totale'   => self::numero($o['total_amount']),
                'creata'   => substr((string)$o['created_at'], 0, 10),
                'da'       => $o['creator_name'],
            ];
            if (count($out) >= 300) {
                break;
            }
        }
        Response::json(['success' => true, 'offerte' => $out]);
    }

    // ── Clienti ──────────────────────────────────────────────────────────────

    /** GET /api/v1/ufficio/clienti — sul sito solo per la societa' 1. */
    public function clienti(Request $request): never
    {
        $this->pretende($request, 'clients');
        $this->soloSede($request);
        $righe = (new \App\Repository\Clients\ClientRepository($this->conn))->getAllWithStats();
        Response::json(['success' => true, 'clienti' => array_map(fn($c) => [
            'id'        => (int)$c['id'],
            'nome'      => $c['name'],
            'indirizzo' => trim(implode(' ', array_filter([$c['via'], $c['cap'], $c['localita']]))),
            'filiale'   => $c['filiale'],
            'piva'      => $c['vat'],
            'email'     => $c['email'],
            'offerte'   => (int)$c['total_offers'],
            'cantieri'  => (int)$c['total_worksites'],
        ], $righe)]);
    }

    /** GET /api/v1/ufficio/clienti/{id} — il cliente, i suoi cantieri e le offerte. */
    public function cliente(Request $request): never
    {
        $this->pretende($request, 'clients');
        $this->soloSede($request);
        $id = (int)$request->param('id');

        $st = $this->conn->prepare('SELECT id, name, via, cap, localita, filiale, vat, email FROM bb_clients WHERE id = :id');
        $st->execute([':id' => $id]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) {
            Response::json(['success' => false, 'message' => 'Cliente non trovato'], 404);
        }

        $st = $this->conn->prepare('SELECT id, worksite_code AS codice, name AS nome FROM bb_worksites WHERE client_id = :id ORDER BY id DESC LIMIT 100');
        $st->execute([':id' => $id]);
        $cantieri = $st->fetchAll(\PDO::FETCH_ASSOC);

        $st = $this->conn->prepare('SELECT id, offer_number AS numero, subject AS oggetto, status AS stato, total_amount, DATE(created_at) AS creata
                                    FROM bb_offers WHERE client_id = :id ORDER BY created_at DESC LIMIT 100');
        $st->execute([':id' => $id]);
        $offerte = array_map(function ($o) {
            $o['totale'] = self::numero($o['total_amount']);
            unset($o['total_amount']);
            return $o;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));

        Response::json([
            'success'  => true,
            'cliente'  => [
                'id'        => (int)$c['id'],
                'nome'      => $c['name'],
                'indirizzo' => trim(implode(' ', array_filter([$c['via'], $c['cap'], $c['localita']]))),
                'filiale'   => $c['filiale'],
                'piva'      => $c['vat'],
                'email'     => $c['email'],
            ],
            'cantieri' => $cantieri,
            'offerte'  => $offerte,
        ]);
    }

    // ── Aziende ──────────────────────────────────────────────────────────────

    /** GET /api/v1/ufficio/aziende — le aziende e quanti operai attivi hanno. */
    public function aziende(Request $request): never
    {
        $this->pretende($request, 'companies');
        $operai = $this->conn->query("SELECT company, COUNT(*) FROM bb_workers
                                      WHERE active = 'Y' AND removed = 'N' AND company IS NOT NULL
                                      GROUP BY company")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $righe = (new \App\Service\Companies\CompanyController($this->conn))->listCompanies();
        Response::json(['success' => true, 'aziende' => array_map(fn($a) => [
            'id'          => (int)$a['id'],
            'nome'        => $a['name'],
            'codice'      => $a['codice'] ?? null,
            'consorziata' => (int)($a['consorziata'] ?? 0) === 1,
            'attiva'      => (int)($a['active'] ?? 1) === 1,
            'operai'      => (int)($operai[$a['name']] ?? 0),
        ], $righe)]);
    }

    // ── Prenotazioni ─────────────────────────────────────────────────────────

    /** GET /api/v1/ufficio/prenotazioni?tipo=hotel|restaurant&attive=1|0&q= */
    public function prenotazioni(Request $request): never
    {
        $this->pretende($request, 'bookings');
        $filtri = [];
        $tipo = (string)($_GET['tipo'] ?? '');
        if (in_array($tipo, ['hotel', 'restaurant'], true)) {
            $filtri['type'] = $tipo;
        }
        if (in_array($_GET['attive'] ?? '', ['0', '1'], true)) {
            $filtri['active'] = $_GET['attive'];
        }
        if (trim((string)($_GET['q'] ?? '')) !== '') {
            $filtri['search'] = trim((string)$_GET['q']);
        }
        $righe = (new \App\Service\Bookings\BookingService($this->conn))->getAllBookings($filtri);
        Response::json(['success' => true, 'prenotazioni' => array_map(fn($b) => [
            'id'        => (int)$b['id'],
            'tipo'      => $b['type'],
            'struttura' => $b['struttura_nome'],
            'citta'     => $b['struttura_citta'],
            'indirizzo' => $b['struttura_indirizzo'],
            'telefono'  => $b['struttura_telefono'],
            'cantiere'  => $b['cantiere_nome'],
            'capo'      => trim((string)$b['capo_squadra_nome']),
            'persone'   => $b['current_persone'] !== null ? (int)$b['current_persone'] : null,
            'prezzo'    => $b['current_prezzo'] !== null ? (float)$b['current_prezzo'] : null,
            'dal'       => $b['first_date'],
            'al'        => $b['last_date'],
            'totale'    => (float)($b['booking_total'] ?? 0),
            'attiva'    => (bool)$b['is_active'],
            'pagata'    => (int)($b['pagato'] ?? 0) === 1,
        ], array_slice($righe, 0, 300))]);
    }

    // ── Ordini alle aziende ──────────────────────────────────────────────────

    /** GET /api/v1/ufficio/ordini-aziende?anno= */
    public function ordiniAziende(Request $request): never
    {
        $this->pretende($request, 'ordini_aziende');
        $anno  = (int)($_GET['anno'] ?? 0) ?: (int)date('Y');
        $righe = (new \App\Repository\OrdiniAziende\OrdineAziendaRepository($this->conn))->listOrdini($anno);
        Response::json(['success' => true, 'anno' => $anno, 'ordini' => array_map(fn($o) => [
            'id'          => (int)$o['id'],
            'azienda'     => $o['azienda_name'],
            'mese'        => (int)$o['mese'],
            'numero'      => $o['order_number'],
            'data'        => $o['order_date'],
            'totale'      => (float)$o['total'],
            'iva'         => $o['iva_percentage'] !== null ? (float)$o['iva_percentage'] : null,
            'descrizione' => $o['descrizione'],
        ], $righe)]);
    }

    // ── Fatturato ────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/ufficio/fatturato?anno=
     * L'anno chiesto e quello prima, mese per mese, da Business: lo stesso
     * calcolo della pagina "Andamento fatturato" (per competenza).
     */
    public function fatturato(Request $request): never
    {
        $this->pretende($request, 'report_business');
        require_once APP_ROOT . '/src/Http/Controllers/BusinessReportController.php';
        $anno   = (int)($_GET['anno'] ?? 0) ?: (int)date('Y');
        $config = new \App\Infrastructure\Config();
        if (!$config->businessConfigured()) {
            Response::json(['success' => false, 'message' => 'Collegamento a Business non configurato.'], 503);
        }
        try {
            $repo = new \App\Repository\Business\BusinessInvoiceRepository(
                (new \App\Infrastructure\BusinessConnection($config))->connect(),
                $config->businessCodDitta()
            );
            $dati = \BusinessReportController::buildSeries($repo->monthlyTotals($anno - 1, $anno), $anno - 1, $anno);
        } catch (\Throwable $e) {
            error_log('[ApiV1 fatturato] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Impossibile leggere i dati da Business in questo momento.'], 503);
        }
        Response::json([
            'success' => true,
            'anno'    => $anno,
            'serie'   => $dati['serie'],
            'totali'  => $dati['totali'],
        ]);
    }

    // ── Rubrica ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/ufficio/rubrica?q=
     * Gli operai con telefono ed email, per chiamarli dal telefono. Chi vede
     * solo alcune aziende (consorziate) dal sito ha un'altra pagina: qui no.
     */
    public function rubrica(Request $request): never
    {
        $this->pretende($request, 'users');
        require_once APP_ROOT . '/includes/company_scope.php';
        if (isCompanyScopedUserByContext($this->conn, $request->user())) {
            Response::json(['success' => false, 'message' => 'Non hai questo permesso in questa societa\''], 403);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['success' => true, 'persone' => []]);
        }
        $st = $this->conn->prepare("
            SELECT id, first_name, last_name, company, phone, email, active
            FROM   bb_workers
            WHERE  removed = 'N'
              AND  (CONCAT(last_name, ' ', first_name) LIKE :q OR CONCAT(first_name, ' ', last_name) LIKE :q2 OR company LIKE :q3)
            ORDER BY (active = 'Y') DESC, last_name, first_name
            LIMIT 50
        ");
        $like = '%' . $q . '%';
        $st->execute([':q' => $like, ':q2' => $like, ':q3' => $like]);
        Response::json(['success' => true, 'persone' => array_map(fn($w) => [
            'id'       => (int)$w['id'],
            'nome'     => trim($w['last_name'] . ' ' . $w['first_name']),
            'azienda'  => $w['company'],
            'telefono' => $w['phone'],
            'email'    => $w['email'],
            'attivo'   => in_array($w['active'], ['Y', '1'], true),
        ], $st->fetchAll(\PDO::FETCH_ASSOC))]);
    }

    // ── Aiuti ────────────────────────────────────────────────────────────────

    /** "1.272,00" (come sta nelle offerte) o 1272.5 → 1272.0 / 1272.5 */
    private static function numero(mixed $v): float
    {
        $s = trim((string)$v);
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return (float)$s;
    }

    private function pretende(Request $request, string $modulo): void
    {
        $u = $request->user();
        if ($u && ((int)$u->id === 1 || $u->canAccess($modulo))) {
            return;
        }
        Response::json(['success' => false, 'message' => 'Non hai questo permesso in questa societa\''], 403);
    }

    /** Clienti: sul sito li vede solo chi lavora nella societa' 1. */
    private function soloSede(Request $request): void
    {
        if ((int)($request->user()->getCompanyId() ?? 0) !== 1) {
            Response::json(['success' => false, 'message' => 'Non hai questo permesso in questa societa\''], 403);
        }
    }
}
