<?php
declare(strict_types=1);

namespace App\Service;
use DateTime;
use Exception;
use PDO;
use PDOException;
use App\Service\OllamaClient;
use App\Service\Mailer;
use App\Domain\WorksiteStats;

/**
 * AI-Powered Anomaly Checker for BOB
 *
 * Collects data from the DB, detects anomalies via SQL + AI analysis,
 * and sends email + in-app notifications to relevant users based on permissions.
 */
class AnomalyCheckerService
{
    private PDO $conn;
    private ?OllamaClient $ai;
    private ?Mailer $mailer;
    private string $today;
    private array $findings = [];
    private int $notificationsSent = 0;

    public function __construct(PDO $conn, ?OllamaClient $ai = null, ?Mailer $mailer = null)
    {
        $this->conn   = $conn;
        $this->ai     = $ai;
        $this->mailer = $mailer;
        $this->today  = date('Y-m-d');
    }

    public function run(): array
    {
        echo "=== BOB AI Anomaly Check — " . date('Y-m-d H:i:s') . " ===\n\n";

        $this->checkPresenze();
        $this->checkMezziSollevamento();
        $this->checkDocumenti();
        $this->checkLogin();
        $this->checkFatturazione();
        $this->checkCantieri();
        $this->checkProgrammazione();
        $this->checkSquadre();
        $this->checkStatistiche();

        // Log anomalies to history for trend analysis
        $this->logAnomaliesToHistory();

        // Analyze trends (recurring issues, deteriorating worksites, etc.)
        $this->checkTrends();

        if (!empty($this->findings) && $this->ai) {
            $this->aiAnalyze();
        }

        $this->sendNotifications();

        echo "\n=== Done. Found " . count($this->findings) . " anomalies, sent {$this->notificationsSent} notifications ===\n";
        return $this->findings;
    }

    // ═══════════════════════════════════════════
    //  PRESENZE CHECKS
    // ═══════════════════════════════════════════

    private function checkPresenze(): void
    {
        echo "── Checking Presenze...\n";

        // 1. Cantieri active >1 month with zero presenze
        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.start_date, w.status,
                   DATEDIFF(CURDATE(), w.start_date) AS days_active,
                   (SELECT COUNT(*) FROM bb_presenze p WHERE p.worksite_id = w.id) AS presenze_nostri,
                   (SELECT COUNT(*) FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id) AS presenze_cons
            FROM bb_worksites w
            WHERE w.start_date IS NOT NULL
              AND w.start_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND w.status IN ('attivo','in_corso','Attivo','In Corso','aperto')
              AND w.is_draft = 0
            HAVING presenze_nostri = 0 AND presenze_cons = 0
            ORDER BY days_active DESC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->addFinding('presenze', 'warning', 'anomaly_presenze',
                "Il cantiere \"{$row['name']}\" ({$row['worksite_code']}) risulta attivo da {$row['days_active']} giorni ma non ha nessuna presenza registrata. Potrebbe essere da verificare.",
                ['worksite_id' => $row['id'], 'days_active' => $row['days_active']]
            );
        }

        // 2. Presenze in the future (more than 1 day ahead)
        $stmt = $this->conn->prepare("
            SELECT p.id, p.data, p.worker_id, w.name AS worksite_name,
                   CONCAT(wr.last_name, ' ', wr.first_name) AS worker_name
            FROM bb_presenze p
            JOIN bb_worksites w ON w.id = p.worksite_id
            LEFT JOIN bb_workers wr ON wr.id = p.worker_id
            WHERE p.data > DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ORDER BY p.data ASC
        ");
        $stmt->execute();
        $futurePresenze = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($futurePresenze)) {
            $count = count($futurePresenze);
            $details = array_map(fn($p) => "  - {$p['worker_name']} il {$p['data']} in \"{$p['worksite_name']}\"", $futurePresenze);
            $this->addFinding('presenze', 'warning', 'anomaly_presenze',
                "Attenzione: ci sono {$count} presenze inserite per date future. Potrebbe trattarsi di un errore di inserimento:\n" .
                implode("\n", $details),
                ['count' => $count]
            );
        }

        // 3. Presenze consorziate with quantità = 0
        $stmt = $this->conn->prepare("
            SELECT pc.id, pc.data_presenza, pc.quantita, pc.azienda_id,
                   w.name AS worksite_name, c.name AS azienda_name
            FROM bb_presenze_consorziate pc
            JOIN bb_worksites w ON w.id = pc.worksite_id
            LEFT JOIN bb_companies c ON c.id = pc.azienda_id
            WHERE pc.quantita = 0
              AND pc.data_presenza >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ORDER BY pc.data_presenza DESC
        ");
        $stmt->execute();
        $zeroQty = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($zeroQty)) {
            $count = count($zeroQty);
            $details = array_map(fn($p) => "  - {$p['azienda_name']} il {$p['data_presenza']} in \"{$p['worksite_name']}\"", $zeroQty);
            $this->addFinding('presenze', 'warning', 'anomaly_presenze',
                "Ci sono {$count} presenze consorziate con quantità 0. Probabilmente un errore di inserimento:\n" .
                implode("\n", $details),
                ['count' => $count]
            );
        }

        // 4. Same worker in 2+ cantieri on same day (skip type_worker = 'mezzo')
        // Two "Mezzo" (half day) shifts in different cantieri = 1 full day = OK
        // Only flag if total shifts > 1 full day equivalent
        $stmt = $this->conn->prepare("
            SELECT p.worker_id, p.data, COUNT(DISTINCT p.worksite_id) AS cantieri_count,
                   CONCAT(wr.last_name, ' ', wr.first_name) AS worker_name,
                   GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS cantieri_names,
                   SUM(CASE WHEN p.turno = 'Mezzo' THEN 0.5 ELSE 1 END) AS total_shifts
            FROM bb_presenze p
            JOIN bb_workers wr ON wr.id = p.worker_id
            JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.data >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND (wr.type_worker IS NULL OR wr.type_worker != 'mezzo')
            GROUP BY p.worker_id, p.data
            HAVING cantieri_count > 1 AND total_shifts > 1
            ORDER BY p.data DESC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->addFinding('presenze', 'alert', 'anomaly_presenze',
                "L'operaio {$row['worker_name']} risulta presente in {$row['cantieri_count']} cantieri il {$row['data']}: {$row['cantieri_names']}. Potrebbe essere un doppio inserimento.",
                ['worker_id' => $row['worker_id'], 'date' => $row['data']]
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'presenze')) . " presenze anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  MEZZI SOLLEVAMENTO CHECKS
    // ═══════════════════════════════════════════

    private function checkMezziSollevamento(): void
    {
        echo "── Checking Mezzi Sollevamento...\n";

        // 1. Noleggio active but cantiere has no presenze for 2+ weeks — GROUPED BY CANTIERE
        $stmt = $this->conn->prepare("
            SELECT wl.worksite_id, w.name AS worksite_name, w.worksite_code,
                   GROUP_CONCAT(DISTINCT le.descrizione SEPARATOR ', ') AS equipment_list,
                   COUNT(*) AS num_mezzi,
                   SUM(wl.costo_giornaliero) AS total_daily_cost,
                   (SELECT MAX(p.data) FROM bb_presenze p WHERE p.worksite_id = wl.worksite_id) AS last_presenza_nostri,
                   (SELECT MAX(pc.data_presenza) FROM bb_presenze_consorziate pc WHERE pc.worksite_id = wl.worksite_id) AS last_presenza_cons
            FROM bb_worksite_lifting wl
            JOIN bb_worksites w ON w.id = wl.worksite_id
            LEFT JOIN bb_lifting_equipment le ON le.id = wl.lifting_equipment_id
            WHERE wl.stato IN ('Attivo','attivo')
              AND (wl.data_fine IS NULL OR wl.data_fine >= CURDATE())
            GROUP BY wl.worksite_id
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastPresenza = max($row['last_presenza_nostri'] ?? '2000-01-01', $row['last_presenza_cons'] ?? '2000-01-01');
            if ($lastPresenza === '2000-01-01') $lastPresenza = null;

            $daysSincePresenza = $lastPresenza
                ? (int)(new DateTime($this->today))->diff(new DateTime($lastPresenza))->days
                : 999;

            if ($daysSincePresenza >= 14) {
                $equipList = $row['equipment_list'] ?: 'N/D';
                $msg = "Il cantiere \"{$row['worksite_name']}\" ha {$row['num_mezzi']} noleggi attivi ({$equipList})";
                if ($lastPresenza) {
                    $msg .= " ma l'ultima presenza registrata risale al {$lastPresenza} ({$daysSincePresenza} giorni fa).";
                } else {
                    $msg .= " ma non risulta nessuna presenza registrata.";
                }
                if ($row['total_daily_cost'] > 0) {
                    $msg .= " Costo giornaliero totale: " . $this->eur((float)$row['total_daily_cost']) . ".";
                }
                $msg .= " Potrebbe essere il caso di verificare se i mezzi sono ancora necessari.";

                $this->addFinding('mezzi', 'warning', 'anomaly_mezzi', $msg,
                    ['worksite_id' => $row['worksite_id']]
                );
            }
        }

        // 2. Price anomalies — same equipment type, very different prices
        // Skip "trasporto" unless the difference is huge (>€1000)
        $stmt = $this->conn->prepare("
            SELECT le.descrizione AS equipment_name, le.id AS equip_id,
                   MIN(wl.costo_giornaliero) AS min_price,
                   MAX(wl.costo_giornaliero) AS max_price,
                   AVG(wl.costo_giornaliero) AS avg_price,
                   COUNT(*) AS count
            FROM bb_worksite_lifting wl
            JOIN bb_lifting_equipment le ON le.id = wl.lifting_equipment_id
            WHERE wl.costo_giornaliero > 0
              AND wl.data_inizio >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY le.id
            HAVING count >= 2 AND max_price > min_price * 1.5
            ORDER BY (max_price - min_price) DESC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = strtolower($row['equipment_name'] ?? '');
            $priceDiff = (float)$row['max_price'] - (float)$row['min_price'];

            // Trasporto prices vary a lot by distance — only flag if difference is >€1000
            if (str_contains($name, 'trasporto') && $priceDiff < 1000) {
                continue;
            }

            $this->addFinding('mezzi', 'info', 'anomaly_mezzi',
                "Abbiamo notato una variazione significativa di prezzo per \"{$row['equipment_name']}\": da " .
                $this->eur((float)$row['min_price']) . " a " . $this->eur((float)$row['max_price']) .
                " (media " . $this->eur((float)$row['avg_price']) .
                ") su {$row['count']} noleggi nell'ultimo anno. Potrebbe valere la pena verificare.",
                ['equipment_id' => $row['equip_id']]
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'mezzi')) . " mezzi anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  DOCUMENTI CHECKS
    // ═══════════════════════════════════════════

    private function checkDocumenti(): void
    {
        echo "── Checking Documenti...\n";

        // 1. ALL expired worker documents for active workers (scadenza normalized)
        $stmt = $this->conn->prepare("
            SELECT wd.id, wd.worker_id, wd.tipo_documento,
                   COALESCE(
                       STR_TO_DATE(wd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(wd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(wd.scadenza, '%d-%m-%Y')
                   ) AS scadenza_norm,
                   CONCAT(wr.last_name, ' ', wr.first_name) AS worker_name,
                   DATEDIFF(CURDATE(), COALESCE(
                       STR_TO_DATE(wd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(wd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(wd.scadenza, '%d-%m-%Y')
                   )) AS days_expired
            FROM bb_worker_documents wd
            JOIN bb_workers wr ON wr.id = wd.worker_id
            WHERE wd.scadenza IS NOT NULL
              AND wd.scadenza != ''
              AND wr.active = 'Y'
              AND COALESCE(wd.nascondere, 'N') != 'Y'
            HAVING scadenza_norm IS NOT NULL AND scadenza_norm < CURDATE()
            ORDER BY days_expired DESC
        ");
        $stmt->execute();
        $expiredDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($expiredDocs)) {
            $grouped = [];
            foreach ($expiredDocs as $doc) {
                $grouped[$doc['worker_id']][] = $doc;
            }
            foreach ($grouped as $workerId => $docs) {
                $name = $docs[0]['worker_name'];
                $details = array_map(fn($d) => "  - {$d['tipo_documento']} (scaduto da {$d['days_expired']} giorni)", $docs);
                $this->addFinding('documenti', 'alert', 'anomaly_documenti',
                    "L'operaio {$name} ha " . count($docs) . " document" . (count($docs) === 1 ? 'o scaduto' : 'i scaduti') . ":\n" . implode("\n", $details),
                    ['worker_id' => $workerId]
                );
            }
        }

        // 2. Expired COMPANY documents (consorziate)
        $stmt = $this->conn->prepare("
            SELECT cd.id, cd.company_id, cd.tipo_documento,
                   COALESCE(
                       STR_TO_DATE(cd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(cd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(cd.scadenza, '%d-%m-%Y')
                   ) AS scadenza_norm,
                   c.name AS company_name,
                   DATEDIFF(CURDATE(), COALESCE(
                       STR_TO_DATE(cd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(cd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(cd.scadenza, '%d-%m-%Y')
                   )) AS days_expired
            FROM bb_company_documents cd
            JOIN bb_companies c ON c.id = cd.company_id
            WHERE cd.scadenza IS NOT NULL
              AND cd.scadenza != ''
              AND c.active = 1
              AND c.consorziata = 1
            HAVING scadenza_norm IS NOT NULL AND scadenza_norm < CURDATE()
            ORDER BY days_expired DESC
        ");
        $stmt->execute();
        $expiredCompDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($expiredCompDocs)) {
            $grouped = [];
            foreach ($expiredCompDocs as $doc) {
                $grouped[$doc['company_id']][] = $doc;
            }
            foreach ($grouped as $companyId => $docs) {
                $name = $docs[0]['company_name'];
                $details = array_map(fn($d) => "  - {$d['tipo_documento']} (scaduto da {$d['days_expired']} giorni)", $docs);
                $this->addFinding('documenti', 'alert', 'anomaly_documenti',
                    "L'azienda {$name} ha " . count($docs) . " document" . (count($docs) === 1 ? 'o scaduto' : 'i scaduti') . ":\n" . implode("\n", $details),
                    ['company_id' => $companyId]
                );
            }
        }

        // 3. Companies with zero documents
        $stmt = $this->conn->prepare("
            SELECT c.id, c.name, c.consorziata
            FROM bb_companies c
            LEFT JOIN bb_company_documents cd ON cd.company_id = c.id
            WHERE cd.id IS NULL
              AND c.active = 1
              AND c.consorziata = 1
            ORDER BY c.name
        ");
        $stmt->execute();
        $noDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($noDocs)) {
            $names = array_map(fn($c) => "  - {$c['name']}", $noDocs);
            $this->addFinding('documenti', 'warning', 'anomaly_documenti',
                "Ci sono " . count($noDocs) . " aziende consorziate che non hanno ancora nessun documento caricato:\n" . implode("\n", $names),
                ['count' => count($noDocs)]
            );
        }

        // 4. Critical worker docs expiring within 30 days
        $stmt = $this->conn->prepare("
            SELECT wd.id, wd.worker_id, wd.tipo_documento,
                   COALESCE(
                       STR_TO_DATE(wd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(wd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(wd.scadenza, '%d-%m-%Y')
                   ) AS scadenza_norm,
                   CONCAT(wr.last_name, ' ', wr.first_name) AS worker_name,
                   DATEDIFF(COALESCE(
                       STR_TO_DATE(wd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(wd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(wd.scadenza, '%d-%m-%Y')
                   ), CURDATE()) AS days_left
            FROM bb_worker_documents wd
            JOIN bb_workers wr ON wr.id = wd.worker_id
            WHERE wd.scadenza IS NOT NULL
              AND wd.scadenza != ''
              AND wr.active = 'Y'
              AND COALESCE(wd.nascondere, 'N') != 'Y'
            HAVING scadenza_norm IS NOT NULL
               AND scadenza_norm BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY scadenza_norm ASC
        ");
        $stmt->execute();
        $expiringSoon = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($expiringSoon)) {
            $details = array_map(fn($d) => "  - {$d['worker_name']}: {$d['tipo_documento']} - scade tra {$d['days_left']} giorni", $expiringSoon);
            $this->addFinding('documenti', 'warning', 'anomaly_documenti',
                "Ci sono " . count($expiringSoon) . " documenti operai in scadenza entro 30 giorni:\n" . implode("\n", $details),
                ['count' => count($expiringSoon)]
            );
        }

        // 5. Company docs expiring within 30 days
        $stmt = $this->conn->prepare("
            SELECT cd.id, cd.company_id, cd.tipo_documento,
                   COALESCE(
                       STR_TO_DATE(cd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(cd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(cd.scadenza, '%d-%m-%Y')
                   ) AS scadenza_norm,
                   c.name AS company_name,
                   DATEDIFF(COALESCE(
                       STR_TO_DATE(cd.scadenza, '%Y-%m-%d'),
                       STR_TO_DATE(cd.scadenza, '%d/%m/%Y'),
                       STR_TO_DATE(cd.scadenza, '%d-%m-%Y')
                   ), CURDATE()) AS days_left
            FROM bb_company_documents cd
            JOIN bb_companies c ON c.id = cd.company_id
            WHERE cd.scadenza IS NOT NULL
              AND cd.scadenza != ''
              AND c.active = 1
              AND c.consorziata = 1
            HAVING scadenza_norm IS NOT NULL
               AND scadenza_norm BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY scadenza_norm ASC
        ");
        $stmt->execute();
        $expiringSoonComp = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($expiringSoonComp)) {
            $details = array_map(fn($d) => "  - {$d['company_name']}: {$d['tipo_documento']} - scade tra {$d['days_left']} giorni", $expiringSoonComp);
            $this->addFinding('documenti', 'warning', 'anomaly_documenti',
                "Ci sono " . count($expiringSoonComp) . " documenti aziendali in scadenza entro 30 giorni:\n" . implode("\n", $details),
                ['count' => count($expiringSoonComp)]
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'documenti')) . " document anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  LOGIN CHECKS
    // ═══════════════════════════════════════════

    private function checkLogin(): void
    {
        echo "── Checking Login Activity...\n";

        // Find inactive users who have at least 1 permission in BOB (they should be using it)
        $stmt = $this->conn->prepare("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.type,
                   MAX(a.created_at) AS last_session,
                   DATEDIFF(CURDATE(), MAX(a.created_at)) AS days_inactive
            FROM bb_users u
            LEFT JOIN bb_user_activity a ON a.user_id = u.id
            WHERE u.active = 1
              AND u.type = 'internal'
              AND EXISTS (SELECT 1 FROM bb_user_permissions p WHERE p.user_id = u.id AND p.allowed = 1)
            GROUP BY u.id
            HAVING last_session IS NULL
               OR last_session < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY days_inactive DESC
        ");
        $stmt->execute();
        $inactiveUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($inactiveUsers)) {
            foreach ($inactiveUsers as $user) {
                $lastDate = $user['last_session'] ? date('d/m/Y', strtotime($user['last_session'])) : 'mai';
                $daysText = $user['last_session']
                    ? "l'ultimo accesso risale al {$lastDate} ({$user['days_inactive']} giorni fa)"
                    : "non hai ancora mai effettuato l'accesso";

                $this->addFinding('login', 'info', '_direct_user',
                    "Ciao {$user['first_name']}! E' da un po' che non ci vediamo su BOB - {$daysText}. " .
                    "Se hai bisogno di una mano o hai dimenticato la password, parlane con il tuo responsabile!",
                    ['user_id' => $user['id'], 'email' => $user['email'], 'first_name' => $user['first_name'],
                     'days_inactive' => (int)($user['days_inactive'] ?? 999)]
                );
            }
        }

        echo "   Found " . count($inactiveUsers) . " inactive users\n";
    }

    // ═══════════════════════════════════════════
    //  FATTURAZIONE CHECKS
    // ═══════════════════════════════════════════

    private function checkFatturazione(): void
    {
        echo "── Checking Fatturazione...\n";

        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code,
                   MIN(p.data) AS prima_presenza,
                   MAX(p.data) AS ultima_presenza,
                   COUNT(DISTINCT DATE_FORMAT(p.data, '%Y-%m')) AS mesi_attivi,
                   (SELECT COUNT(*) FROM bb_billing b WHERE b.worksite_id = w.id) AS fatture_count
            FROM bb_worksites w
            JOIN bb_presenze p ON p.worksite_id = w.id
            WHERE p.data >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              AND p.data < DATE_FORMAT(CURDATE(), '%Y-%m-01')
            GROUP BY w.id
            HAVING fatture_count = 0 AND mesi_attivi >= 2
            ORDER BY mesi_attivi DESC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->addFinding('fatturazione', 'alert', 'anomaly_fatturazione',
                "Il cantiere \"{$row['name']}\" ({$row['worksite_code']}) ha {$row['mesi_attivi']} mesi di attività registrata " .
                "(dal {$row['prima_presenza']} al {$row['ultima_presenza']}) ma non risulta nessuna fattura emessa.",
                ['worksite_id' => $row['id']]
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'fatturazione')) . " billing anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  CANTIERI CHECKS
    // ═══════════════════════════════════════════

    private function checkCantieri(): void
    {
        echo "── Checking Cantieri...\n";

        // 1. Many cantieri without n° offerta
        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.start_date, w.status
            FROM bb_worksites w
            WHERE (w.offer_number IS NULL OR w.offer_number = '')
              AND w.is_draft = 0
              AND w.status IN ('attivo','in_corso','Attivo','In Corso','aperto')
            ORDER BY w.start_date DESC
        ");
        $stmt->execute();
        $noOffer = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($noOffer) >= 3) {
            $details = array_map(fn($w) => "  - {$w['name']} ({$w['worksite_code']})", $noOffer);
            $this->addFinding('cantieri', 'warning', 'anomaly_cantieri',
                "Ci sono " . count($noOffer) . " cantieri attivi senza numero offerta associato:\n" .
                implode("\n", $details),
                ['count' => count($noOffer)]
            );
        }

        // 2. Cantieri still marked active but started >30 days ago — might need to be closed
        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.start_date, w.status,
                   DATEDIFF(CURDATE(), w.start_date) AS days_active,
                   (SELECT MAX(p.data) FROM bb_presenze p WHERE p.worksite_id = w.id) AS last_presenza,
                   (SELECT COUNT(*) FROM bb_presenze p WHERE p.worksite_id = w.id) AS tot_presenze
            FROM bb_worksites w
            WHERE w.is_draft = 0
              AND w.status IN ('attivo','in_corso','Attivo','In Corso','aperto')
              AND w.start_date IS NOT NULL
              AND w.start_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY days_active DESC
        ");
        $stmt->execute();
        $oldActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($oldActive)) {
            // Group by age ranges for clarity
            $veryOld = array_filter($oldActive, fn($w) => (int)$w['days_active'] > 180);
            $old     = array_filter($oldActive, fn($w) => (int)$w['days_active'] > 60 && (int)$w['days_active'] <= 180);

            if (!empty($veryOld)) {
                $details = array_map(fn($w) => "  - {$w['name']} ({$w['worksite_code']}) - attivo da {$w['days_active']} giorni" .
                    ($w['last_presenza'] ? ", ultima presenza: {$w['last_presenza']}" : ", nessuna presenza"), $veryOld);
                $this->addFinding('cantieri', 'alert', 'anomaly_cantieri',
                    "Ci sono " . count($veryOld) . " cantieri attivi da piu' di 6 mesi. Potrebbero essere da chiudere o aggiornare:\n" .
                    implode("\n", $details),
                    ['count' => count($veryOld)]
                );
            }

            if (!empty($old)) {
                $details = array_map(fn($w) => "  - {$w['name']} ({$w['worksite_code']}) - {$w['days_active']} giorni", $old);
                $this->addFinding('cantieri', 'info', 'anomaly_cantieri',
                    count($old) . " cantieri attivi da 2-6 mesi. Verifica che siano ancora in corso:\n" .
                    implode("\n", $details),
                    ['count' => count($old)]
                );
            }
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'cantieri')) . " cantieri anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  PROGRAMMAZIONE CHECKS
    // ═══════════════════════════════════════════

    private function checkProgrammazione(): void
    {
        echo "── Checking Programmazione...\n";

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total,
                   MAX(created_at) AS last_created,
                   MAX(updated_at) AS last_updated
            FROM bb_programmazione
            WHERE anno = :anno AND mese >= :mese
        ");
        $currentMonth = (int)date('n');
        $currentYear  = (int)date('Y');
        $stmt->execute([':anno' => $currentYear, ':mese' => max(1, $currentMonth - 1)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)$row['total'] === 0) {
            $this->addFinding('programmazione', 'info', 'anomaly_programmazione',
                "Il modulo Programmazione non sembra essere utilizzato di recente - nessuna riga trovata per gli ultimi 2 mesi. Se serve una mano a configurarlo, fai sapere!",
                []
            );
        } elseif ($row['last_updated'] && (new DateTime($row['last_updated']))->diff(new DateTime($this->today))->days > 14) {
            $days = (new DateTime($row['last_updated']))->diff(new DateTime($this->today))->days;
            $this->addFinding('programmazione', 'info', 'anomaly_programmazione',
                "La Programmazione non viene aggiornata da {$days} giorni (ultimo aggiornamento: " .
                date('d/m/Y', strtotime($row['last_updated'])) . ").",
                []
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'programmazione')) . " programmazione anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  SQUADRE (PIANIFICAZIONE) CHECKS
    // ═══════════════════════════════════════════

    private function checkSquadre(): void
    {
        echo "── Checking Squadre...\n";

        $stmt = $this->conn->prepare("
            SELECT MAX(data) AS last_plan_date,
                   DATEDIFF(CURDATE(), MAX(data)) AS days_since
            FROM bb_pianificazione
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row['last_plan_date'] || (int)$row['days_since'] > 7) {
            $daysSince = $row['last_plan_date'] ? $row['days_since'] : 'mai';
            $this->addFinding('squadre', 'info', 'anomaly_squadre',
                "La pianificazione squadre non viene aggiornata da {$daysSince} giorni. " .
                "Ultimo piano inserito: " . ($row['last_plan_date'] ? date('d/m/Y', strtotime($row['last_plan_date'])) : 'nessuno') . ".",
                []
            );
        }

        // Check upcoming weekdays without pianificazione
        $stmt = $this->conn->prepare("
            SELECT cal.d AS missing_date
            FROM (
                SELECT DATE_ADD(CURDATE(), INTERVAL n DAY) AS d
                FROM (SELECT 0 AS n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) nums
            ) cal
            LEFT JOIN bb_pianificazione p ON p.data = cal.d
            WHERE DAYOFWEEK(cal.d) BETWEEN 2 AND 6
              AND cal.d >= CURDATE()
              AND p.id IS NULL
            ORDER BY cal.d
        ");
        $stmt->execute();
        $missingDays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($missingDays) >= 3) {
            $formatted = array_map(fn($d) => date('D d/m', strtotime($d)), $missingDays);
            $this->addFinding('squadre', 'warning', 'anomaly_squadre',
                "Ci sono " . count($missingDays) . " giorni lavorativi nei prossimi 7 giorni senza pianificazione squadre: " .
                implode(', ', $formatted) . ". Ricordati di compilarla!",
                ['missing_days' => $missingDays]
            );
        }

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'squadre')) . " squadre anomalies\n";
    }

    // ═══════════════════════════════════════════
    //  STATISTICHE CANTIERE CHECKS
    // ═══════════════════════════════════════════

    private function checkStatistiche(): void
    {
        echo "── Checking Statistiche Cantiere...\n";


        // Get active worksites: fixed contract OR consuntivo
        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.total_offer,
                   w.is_consuntivo, w.prezzo_persona,
                   c.name AS client_name
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            WHERE w.status = 'In corso'
              AND w.is_draft = 0
              AND (w.total_offer > 0 OR w.is_consuntivo = 1)
            ORDER BY w.name
        ");
        $stmt->execute();
        $worksites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $riskNegative  = [];
        $riskLowMargin = [];

        foreach ($worksites as $ws) {
            try {
                $stats   = new WorksiteStats($this->conn, (int)$ws['id']);
                $summary = $stats->getSummary();

                $isConsuntivo = (bool)($ws['is_consuntivo'] ?? false);

                $bobMargin = (float)($summary['andamento'] ?? 0);
                $finalMargin = $bobMargin;

                // Apply Yard historical costs if available
                $stmtYard = $this->conn->prepare("
                    SELECT totale_complessivo
                    FROM bb_cantiere_stats_2025
                    WHERE cantiere_id_sqlsrv = (
                        SELECT yard_worksite_id FROM bb_worksites WHERE id = :wid LIMIT 1
                    )
                ");
                $stmtYard->execute(['wid' => $ws['id']]);
                $yardTotale = $stmtYard->fetchColumn();
                $yardCosts = ($yardTotale !== false) ? (float)$yardTotale : 0;
                $finalMargin -= $yardCosts;

                $totalCosts = (float)($summary['costi']['tot_costi'] ?? 0) + $yardCosts;

                // For consuntivo: use estimated revenue as the base; skip if no activity yet
                $totaleRicavi = (float)($summary['ricavi']['tot_ricavi'] ?? 0);
                if ($isConsuntivo && $totaleRicavi <= 0) {
                    continue; // no presenze yet — nothing meaningful to check
                }

                $totaleContratto = $isConsuntivo ? $totaleRicavi : (float)$ws['total_offer'];
                $percentuale     = $totaleContratto > 0
                    ? round(($finalMargin / $totaleContratto) * 100, 2)
                    : 0;

                $row = [
                    'id'           => $ws['id'],
                    'name'         => $ws['name'],
                    'code'         => $ws['worksite_code'] ?? '',
                    'client'       => $ws['client_name'] ?? '',
                    'contract'     => $totaleContratto,
                    'costs'        => $totalCosts,
                    'margin'       => $finalMargin,
                    'perc'         => $percentuale,
                    'is_consuntivo'=> $isConsuntivo,
                ];

                if ($finalMargin < 0) {
                    $riskNegative[] = $row;
                } elseif ($percentuale < 10) {
                    $riskLowMargin[] = $row;
                }
            } catch (\Throwable $e) {
                echo "   !! Error calculating stats for worksite #{$ws['id']}: {$e->getMessage()}\n";
            }
        }

        // Negative margin findings
        if (!empty($riskNegative)) {
            usort($riskNegative, fn($a, $b) => $a['margin'] <=> $b['margin']);
            $this->addFinding('statistiche', 'alert', 'anomaly_statistiche',
                count($riskNegative) . " cantieri In Corso con margine negativo:",
                ['count' => count($riskNegative), 'type' => 'negative', 'html' => $this->buildStatsTable($riskNegative, '#dc2626')]
            );
        }

        // Low margin findings
        if (!empty($riskLowMargin)) {
            usort($riskLowMargin, fn($a, $b) => $a['perc'] <=> $b['perc']);
            $this->addFinding('statistiche', 'warning', 'anomaly_statistiche',
                count($riskLowMargin) . " cantieri In Corso con margine inferiore al 10%:",
                ['count' => count($riskLowMargin), 'type' => 'low_margin', 'html' => $this->buildStatsTable($riskLowMargin, '#d97706')]
            );
        }

        // Summary for AI
        $totalChecked = count($worksites);
        $totalAtRisk = count($riskNegative) + count($riskLowMargin);
        if ($totalChecked > 0 && $totalAtRisk === 0) {
            $this->addFinding('statistiche', 'info', 'anomaly_statistiche',
                "Tutti i {$totalChecked} cantieri In Corso con contratto hanno un margine sano (>10%). Ottimo lavoro!",
                ['total_checked' => $totalChecked]
            );
        }

        echo "   Checked {$totalChecked} worksites, found " . count($riskNegative) . " negative + " . count($riskLowMargin) . " low margin\n";
    }

    // ═══════════════════════════════════════════
    //  AI ANALYSIS (optional enhancement)
    // ═══════════════════════════════════════════

    private function aiAnalyze(): void
    {
        echo "\n── Running AI analysis...\n";

        // Group findings by module for nice formatting
        $anomaliesByModule = [];
        foreach ($this->findings as $f) {
            if (!isset($anomaliesByModule[$f['module']])) {
                $anomaliesByModule[$f['module']] = [];
            }
            $anomaliesByModule[$f['module']][] = $f;
        }

        // Build conversational prompt with anomalies formatted nicely
        $anomaliesText = "\n\n=== ANOMALIE RILEVATE OGGI ===\n";
        $moduleLabels = $this->getModuleLabels();
        foreach ($anomaliesByModule as $module => $findings) {
            $moduleLabel = $moduleLabels[$module] ?? ucfirst($module);
            $anomaliesText .= "\n📁 {$moduleLabel}:\n";
            foreach ($findings as $f) {
                $icon = $f['severity'] === 'alert' ? '🚨' : ($f['severity'] === 'warning' ? '⚠️' : 'ℹ️');
                $anomaliesText .= "  {$icon} " . strip_tags($f['message']) . "\n";
            }
        }

        $prompt = "Sei l'assistente AI intelligente del sistema BOB (ERP per CS Montaggi). \n" .
            "Hai appena completato un controllo quotidiano e hai rilevato alcune anomalie.\n\n" .
            "Il tuo compito: scrivi un messaggio in italiano (massimo 150-200 parole) per il responsabile \n" .
            "che sintetizza la situazione in modo naturale, umano e utile.\n\n" .
            "REGOLE: \n" .
            "- Sii diretto ma cordiale, come un collega che avvisa dell'andamento\n" .
            "- Evidenzia cosa è urgente vs cosa può aspettare\n" .
            "- Se tutto ok, dì che va bene (ma non essere banale)\n" .
            "- Puoi usare emoji con moderazione per rendere il testo più vivace\n" .
            "- Non usare JSON, non usare elenchi puntati formali, scrivi come una mail veloce\n" .
            "- Se noti pattern strani (es. sempre problemi documenti), accennaci\n" .
            "- Massimo 150-200 parole, sii sintetico\n\n" .
            $anomaliesText .
            "\n\nScrivi il tuo messaggio qui sotto (solo il messaggio, niente spiegazioni):\n";

        $result = $this->ai->generate($prompt);

        if ($result['ok']) {
            echo "   AI response received ({$result['latency_ms']}ms)\n";
            // Clean up response - remove code blocks if present
            $aiMessage = $result['response'];
            if (preg_match('/```(?:markdown|text)?\s*(.+?)\s*```/s', $aiMessage, $m)) {
                $aiMessage = trim($m[1]);
            }
            // Trim any leading/trailing whitespace
            $aiMessage = trim($aiMessage);
            if (!empty($aiMessage)) {
                $this->addFinding('ai_summary', 'info', '_admin',
                    "🤖 **Riassunto AI:**\n\n{$aiMessage}"
                );
            }
        } else {
            echo "   AI analysis failed: {$result['error']}\n";
        }
    }

    // ═══════════════════════════════════════════
    //  NOTIFICATION DISPATCH
    // ═══════════════════════════════════════════

    private function sendNotifications(): void
    {
        echo "\n── Sending notifications...\n";

        $grouped = [];
        foreach ($this->findings as $f) {
            $grouped[$f['permission']][] = $f;
        }

        foreach ($grouped as $permission => $findings) {
            if ($permission === '_admin') {
                $this->sendToAdmin($findings);
                continue;
            }

            // Direct user emails (e.g. login reminders sent personally)
            if ($permission === '_direct_user') {
                foreach ($findings as $f) {
                    if (empty($f['data']['email']) || empty($f['data']['user_id'])) continue;
                    $userId = (int)$f['data']['user_id'];
                    $firstName = $f['data']['first_name'] ?? 'utente';
                    $isFirstTime = $this->isFirstEmailEver($userId);
                    $body = $isFirstTime
                        ? $this->buildFirstTimeEmail($firstName, $f['message'])
                        : $this->wrapInHtmlTemplate('Promemoria', '<p style="font-size: 15px; color: #1e293b;">' . nl2br(htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8')) . '</p>');

                    $this->sendEmailToUser($f['data']['email'], 'BOB AI - Un piccolo promemoria', $body, $userId, 'login');
                    $this->notificationsSent++;
                }
                echo "   _direct_user: sent to " . count($findings) . " users\n";
                continue;
            }

            // Sort by severity: alert first, then warning, then info
            $severityOrder = ['alert' => 0, 'warning' => 1, 'info' => 2];
            usort($findings, fn($a, $b) => ($severityOrder[$a['severity']] ?? 9) <=> ($severityOrder[$b['severity']] ?? 9));

            $users = $this->getUsersWithPermission($permission);
            if (empty($users)) {
                echo "   No users with permission '{$permission}', skipping " . count($findings) . " findings\n";
                continue;
            }

            $moduleLabel = $this->getModuleLabel($findings[0]['module']);

            // If too many findings, generate Excel attachment
            $attachment = null;
            if (count($findings) > 10) {
                $attachment = $this->generateExcelReport($moduleLabel, $findings);
            }

            foreach ($users as $user) {
                $userId = (int)$user['id'];
                $isFirstTime = $this->isFirstEmailEver($userId);

                // For large reports: email has summary + Excel attached
                if ($attachment) {
                    $emailFindings = array_slice($findings, 0, 10);
                    $body = $this->buildEmailBody($moduleLabel, $emailFindings, $user['first_name'], $isFirstTime,
                        count($findings) - 10);
                } else {
                    $body = $this->buildEmailBody($moduleLabel, $findings, $user['first_name'], $isFirstTime);
                }

                if (!empty($user['email'])) {
                    $this->sendEmailToUser(
                        $user['email'],
                        "BOB AI - {$moduleLabel}: " . count($findings) . " segnalazioni",
                        $body,
                        $userId,
                        $findings[0]['module'],
                        $attachment
                    );
                }
                $this->notificationsSent++;
            }

            // Clean up temp file
            if ($attachment && file_exists($attachment)) {
                unlink($attachment);
            }

            echo "   {$permission}: sent to " . count($users) . " users (" . count($findings) . " findings)\n";
        }
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function addFinding(string $module, string $severity, string $permission, string $message, array $data): void
    {
        $this->findings[] = [
            'module'     => $module,
            'severity'   => $severity,
            'permission' => $permission,
            'message'    => $message,
            'data'       => $data,
            'timestamp'  => $this->today,
        ];
    }

    private function getUsersWithPermission(string $module): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
            FROM bb_users u
            INNER JOIN bb_user_permissions p ON p.user_id = u.id
            WHERE p.module = :mod AND p.allowed = 1 AND u.active = 1
        ");
        $stmt->execute([':mod' => $module]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if this is the first anomaly email ever sent to this user
     */
    private function isFirstEmailEver(int $userId): bool
    {
        try {
            $stmt = $this->conn->prepare("SELECT 1 FROM bb_anomaly_email_log WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            return !$stmt->fetchColumn();
        } catch (PDOException $e) {
            // Table might not exist yet
            return true;
        }
    }

    /**
     * Log that an anomaly email was sent
     */
    private function logEmailSent(int $userId, string $email, string $module, string $content): void
    {
        try {
            $hash = hash('sha256', $content);
            $stmt = $this->conn->prepare("
                INSERT INTO bb_anomaly_email_log (user_id, email, module, findings_hash, sent_at)
                VALUES (:uid, :email, :mod, :hash, NOW())
            ");
            $stmt->execute([':uid' => $userId, ':email' => $email, ':mod' => $module, ':hash' => $hash]);
        } catch (PDOException $e) {
            // Table might not exist yet — silently skip
        }
    }

    private function buildFirstTimeEmail(string $firstName, string $content): string
    {
        return $this->wrapInHtmlTemplate(
            'Benvenuto!',
            '<p style="font-size: 16px; color: #1e293b;">Ciao <strong>' . htmlspecialchars($firstName) . '</strong>! 👋</p>
            <p>Sono <strong>BOB AI</strong>, l\'assistente intelligente del gestionale BOB.</p>
            <p>Il mio compito e\' controllare ogni giorno che tutto funzioni correttamente: verifico presenze, documenti, cantieri, mezzi e molto altro.
            Se trovo qualcosa di strano o che richiede attenzione, te lo segnalo via email cosi\' puoi intervenire subito.</p>
            <p>Non ti mandero\' spam, solo segnalazioni utili quando serve davvero. 🙂</p>
            <div style="background: #f8fafc; border-left: 4px solid #3b82f6; padding: 16px 20px; margin: 24px 0; border-radius: 0 8px 8px 0;">
                <p style="margin: 0; font-weight: 600; color: #1e40af; margin-bottom: 8px;">Ecco la prima segnalazione:</p>
                <p style="margin: 0; white-space: pre-line;">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</p>
            </div>
            <p style="color: #64748b;">Se hai domande o qualcosa non ti torna, parlane con il tuo responsabile.</p>
            <p style="color: #64748b;">Buon lavoro! 🤖</p>'
        );
    }

    private function buildEmailBody(string $moduleLabel, array $findings, string $firstName, bool $isFirstTime, int $moreCount = 0): string
    {
        $severityConfig = [
            'alert'   => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fca5a5', 'label' => 'Urgente',      'dot' => '🔴'],
            'warning' => ['color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fcd34d', 'label' => 'Attenzione',   'dot' => '🟡'],
            'info'    => ['color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#93c5fd', 'label' => 'Informazione', 'dot' => '🔵'],
        ];

        // Intro section
        if ($isFirstTime) {
            $intro = '<p style="font-size: 16px; color: #1e293b;">Ciao <strong>' . htmlspecialchars($firstName) . '</strong>! 👋</p>
                <p>Sono <strong>BOB AI</strong>, l\'assistente intelligente del gestionale BOB.</p>
                <p>Il mio compito e\' controllare ogni giorno che tutto funzioni correttamente: verifico presenze, documenti, cantieri, mezzi e molto altro. Se trovo qualcosa di strano o che richiede attenzione, te lo segnalo via email cosi\' puoi intervenire subito.</p>
                <p>Non ti mandero\' spam, solo segnalazioni utili quando serve davvero. 🙂</p>
                <p style="font-weight: 600;">Ecco il primo report:</p>';
        } else {
            $intro = '<p style="font-size: 16px; color: #1e293b;">Ciao <strong>' . htmlspecialchars($firstName) . '</strong>,</p>
                <p>ecco le segnalazioni di oggi per <strong>' . htmlspecialchars($moduleLabel) . '</strong>:</p>';
        }

        // Header badge
        $totalCount = count($findings) + $moreCount;
        $header = '<div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 16px 24px; border-radius: 12px; margin: 24px 0 16px;">
            <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                <td style="color: white; font-size: 18px; font-weight: 700;">' . htmlspecialchars($moduleLabel) . '</td>
                <td style="color: rgba(255,255,255,0.8); font-size: 14px; text-align: right;">' . date('d/m/Y') . '</td>
            </tr></table>
            <p style="margin: 8px 0 0; font-size: 13px; color: rgba(255,255,255,0.7);">' . $totalCount . ' segnalazion' . ($totalCount === 1 ? 'e' : 'i') . '</p>
        </div>';

        // Findings cards
        $cards = '';
        foreach ($findings as $i => $f) {
            $cfg = $severityConfig[$f['severity']] ?? $severityConfig['info'];
            $message = nl2br(htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8'));

            // If finding has custom HTML (e.g. stats table), append it after the message
            $extraHtml = !empty($f['data']['html']) ? $f['data']['html'] : '';

            $cards .= '<div style="background: ' . $cfg['bg'] . '; border: 1px solid ' . $cfg['border'] . '; border-left: 4px solid ' . $cfg['color'] . '; border-radius: 0 10px 10px 0; padding: 16px 20px; margin-bottom: 12px;">
                <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                    <td style="vertical-align: top; width: 30px; font-size: 16px; padding-top: 2px;">' . $cfg['dot'] . '</td>
                    <td>
                        <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: ' . $cfg['color'] . '; background: white; padding: 2px 8px; border-radius: 4px; margin-bottom: 8px;">' . $cfg['label'] . '</span>
                        <p style="margin: 8px 0 0; color: #334155; font-size: 14px; line-height: 1.6;">' . $message . '</p>
                        ' . $extraHtml . '
                    </td>
                </tr></table>
            </div>';
        }

        // Attachment note
        $attachNote = '';
        if ($moreCount > 0) {
            $attachNote = '<div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 16px 20px; margin: 16px 0; text-align: center;">
                <p style="margin: 0; color: #0369a1; font-weight: 600;">📎 Altre ' . $moreCount . ' segnalazioni nel file Excel allegato</p>
                <p style="margin: 6px 0 0; color: #0c4a6e; font-size: 13px;">Apri l\'allegato per il report completo con tutti i dettagli.</p>
            </div>';
        }

        // Footer
        $footer = '<p style="color: #64748b;">Se qualcosa non ti torna o hai bisogno di una mano, parlane con il tuo responsabile.</p>
            <p style="color: #64748b;">Buon lavoro! 🤖</p>';

        return $this->wrapInHtmlTemplate($moduleLabel, $intro . $header . $cards . $attachNote . $footer);
    }

    private function sendToAdmin(array $findings): void
    {
        $stmt = $this->conn->prepare("
            SELECT id, email, first_name, last_name
            FROM bb_users
            WHERE role = 'admin' AND active = 1
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $isFirstTime = $this->isFirstEmailEver((int)$admin['id']);
            $body = $this->buildEmailBody('Riassunto AI', $findings, $admin['first_name'], $isFirstTime);

            if (!empty($admin['email'])) {
                $this->sendEmailToUser($admin['email'], 'BOB AI - Report Anomalie Giornaliero', $body, (int)$admin['id'], 'ai_summary');
            }
            $this->notificationsSent++;
        }
    }

    private function sendEmailToUser(string $email, string $subject, string $htmlBody, int $userId = 0, string $module = '', ?string $attachmentPath = null): void
    {
        if (!$this->mailer) return;

        try {
            $mailer = new Mailer();
            $mailer->setSender('alerts');
            $mail = $mailer->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));

            if ($attachmentPath && file_exists($attachmentPath)) {
                $mail->addAttachment($attachmentPath, basename($attachmentPath));
            }

            $mail->send();

            // Log the email
            if ($userId > 0 && $module) {
                $this->logEmailSent($userId, $email, $module, $htmlBody);
            }
        } catch (Exception $e) {
            echo "   !! Email failed to {$email}: {$e->getMessage()}\n";
        }
    }

    private function wrapInHtmlTemplate(string $title, string $bodyContent): string
    {
        $logoUrl = 'https://bob.csmontaggi.it/includes/template/dist/images/logo.png';
        $year = date('Y');

        return '<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f1f5f9; padding: 32px 16px;">
<tr><td align="center">
<table cellpadding="0" cellspacing="0" border="0" width="640" style="max-width: 640px; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);">

<!-- Logo Header -->
<tr><td style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 28px 32px; text-align: center;">
    <img src="' . $logoUrl . '" alt="BOB" width="120" style="display: inline-block; max-width: 120px; height: auto;">
    <p style="margin: 12px 0 0; color: rgba(255,255,255,0.6); font-size: 12px; letter-spacing: 1px; text-transform: uppercase;">Anomaly Report</p>
</td></tr>

<!-- Content -->
<tr><td style="padding: 32px 32px 24px; font-size: 14px; line-height: 1.7; color: #334155;">
    ' . $bodyContent . '
</td></tr>

<!-- Divider -->
<tr><td style="padding: 0 32px;"><div style="border-top: 1px solid #e2e8f0;"></div></td></tr>

<!-- Footer -->
<tr><td style="padding: 20px 32px 28px; text-align: center;">
    <p style="margin: 0 0 4px; font-size: 12px; color: #94a3b8;">Questo messaggio e\' stato generato automaticamente da BOB AI</p>
    <p style="margin: 0; font-size: 12px; color: #94a3b8;">&copy; ' . $year . ' CS Montaggi &mdash; <a href="https://bob.csmontaggi.it" style="color: #3b82f6; text-decoration: none;">bob.csmontaggi.it</a></p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }

    private function eur(float $amount): string
    {
        return "\xe2\x82\xac" . number_format($amount, 2, ',', '.');
    }

    private function buildStatsTable(array $rows, string $headerColor): string
    {
        $html = '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse: collapse; font-size: 13px; margin-top: 12px;">
            <tr style="background: ' . $headerColor . ';">
                <th style="padding: 10px 12px; color: #fff; text-align: left; font-weight: 600; border-radius: 8px 0 0 0;">Cantiere</th>
                <th style="padding: 10px 12px; color: #fff; text-align: left; font-weight: 600;">Cliente</th>
                <th style="padding: 10px 12px; color: #fff; text-align: right; font-weight: 600;">Contratto</th>
                <th style="padding: 10px 12px; color: #fff; text-align: right; font-weight: 600;">Costi</th>
                <th style="padding: 10px 12px; color: #fff; text-align: right; font-weight: 600; border-radius: 0 8px 0 0;">Margine</th>
            </tr>';

        foreach ($rows as $i => $r) {
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $marginColor = $r['margin'] < 0 ? '#dc2626' : ($r['perc'] < 10 ? '#d97706' : '#16a34a');

            $html .= '<tr style="background: ' . $bg . '; border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 10px 12px; color: #1e293b; font-weight: 500;">' . htmlspecialchars($r['name']) .
                    ($r['code'] ? ' <span style="color: #94a3b8; font-size: 11px;">(' . htmlspecialchars($r['code']) . ')</span>' : '') . '</td>
                <td style="padding: 10px 12px; color: #64748b;">' . htmlspecialchars($r['client'] ?: '-') . '</td>
                <td style="padding: 10px 12px; text-align: right; color: #1e293b;">' . $this->eur($r['contract']) . '</td>
                <td style="padding: 10px 12px; text-align: right; color: #1e293b;">' . $this->eur($r['costs']) . '</td>
                <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: ' . $marginColor . ';">' . $this->eur($r['margin']) . ' <span style="font-size: 11px; font-weight: 400;">(' . $r['perc'] . '%)</span></td>
            </tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Generate an Excel report for findings when there are too many to put in the email
     */
    private function generateExcelReport(string $moduleLabel, array $findings): ?string
    {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(mb_substr($moduleLabel, 0, 31));

            // Header style
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e40af']],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            ];

            $severityColors = [
                'alert'   => 'fef2f2',
                'warning' => 'fffbeb',
                'info'    => 'eff6ff',
            ];

            $severityLabels = [
                'alert'   => 'Urgente',
                'warning' => 'Attenzione',
                'info'    => 'Informazione',
            ];

            // Headers
            $sheet->setCellValue('A1', '#');
            $sheet->setCellValue('B1', 'Livello');
            $sheet->setCellValue('C1', 'Modulo');
            $sheet->setCellValue('D1', 'Segnalazione');
            $sheet->setCellValue('E1', 'Data');
            $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(28);

            // Column widths
            $sheet->getColumnDimension('A')->setWidth(6);
            $sheet->getColumnDimension('B')->setWidth(16);
            $sheet->getColumnDimension('C')->setWidth(20);
            $sheet->getColumnDimension('D')->setWidth(80);
            $sheet->getColumnDimension('E')->setWidth(14);

            // Data rows
            foreach ($findings as $i => $f) {
                $row = $i + 2;
                $sheet->setCellValue("A{$row}", $i + 1);
                $sheet->setCellValue("B{$row}", $severityLabels[$f['severity']] ?? $f['severity']);
                $sheet->setCellValue("C{$row}", $this->getModuleLabel($f['module']));
                $sheet->setCellValue("D{$row}", $f['message']);
                $sheet->setCellValue("E{$row}", date('d/m/Y'));

                // Color row by severity
                $bgColor = $severityColors[$f['severity']] ?? 'ffffff';
                $sheet->getStyle("A{$row}:E{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($bgColor);

                $sheet->getStyle("D{$row}")->getAlignment()->setWrapText(true);
                $sheet->getRowDimension($row)->setRowHeight(-1);
            }

            // Auto-filter
            $lastRow = count($findings) + 1;
            $sheet->setAutoFilter("A1:E{$lastRow}");

            // Freeze header
            $sheet->freezePane('A2');

            // Save to temp file
            $tmpFile = sys_get_temp_dir() . '/bob_ai_' . preg_replace('/[^a-z0-9]/i', '_', $moduleLabel) . '_' . date('Y-m-d') . '.xlsx';
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tmpFile);
            $spreadsheet->disconnectWorksheets();

            echo "   Excel report generated: {$tmpFile} (" . count($findings) . " rows)\n";
            return $tmpFile;
        } catch (\Exception $e) {
            echo "   !! Excel generation failed: {$e->getMessage()}\n";
            return null;
        }
    }

    private function maxSeverity(array $findings): string
    {
        $priority = ['info' => 0, 'warning' => 1, 'alert' => 2];
        $max = 0;
        foreach ($findings as $f) {
            $max = max($max, $priority[$f['severity']] ?? 0);
        }
        return array_search($max, $priority) ?: 'info';
    }

    private function getModuleLabel(string $module): string
    {
        return $this->getModuleLabels()[$module] ?? ucfirst($module);
    }

    private function getModuleLabels(): array
    {
        return [
            'presenze'       => 'Presenze',
            'mezzi'          => 'Mezzi Sollevamento',
            'documenti'      => 'Documenti',
            'login'          => 'Accessi',
            'fatturazione'   => 'Fatturazione',
            'cantieri'       => 'Cantieri',
            'programmazione' => 'Programmazione',
            'squadre'        => 'Squadre',
            'statistiche'    => 'Statistiche Cantiere',
            'ai_summary'     => 'Riassunto AI',
            'tendenze'       => 'Tendenze & Pattern',
        ];
    }

    // ═══════════════════════════════════════════
    //  ANOMALY HISTORY LOGGING
    // ═══════════════════════════════════════════

    private function logAnomaliesToHistory(): void
    {
        if (empty($this->findings)) {
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_anomaly_history
                (run_date, module, anomaly_type, severity, worksite_id, worksite_name, message, details)
                VALUES (:run_date, :module, :anomaly_type, :severity, :worksite_id, :worksite_name, :message, :details)
            ");

            foreach ($this->findings as $f) {
                $worksiteId = $f['data']['worksite_id'] ?? null;
                $worksiteName = null;

                // Fetch worksite name if worksite_id exists
                if ($worksiteId) {
                    $wsStmt = $this->conn->prepare("SELECT name FROM bb_worksites WHERE id = ?");
                    $wsStmt->execute([$worksiteId]);
                    $worksiteName = $wsStmt->fetchColumn();
                }

                $stmt->execute([
                    ':run_date'     => $this->today,
                    ':module'       => $f['module'],
                    ':anomaly_type' => $f['anomaly_type'],
                    ':severity'     => $f['severity'],
                    ':worksite_id'  => $worksiteId,
                    ':worksite_name'=> $worksiteName,
                    ':message'      => $f['message'],
                    ':details'      => json_encode($f['data']),
                ]);
            }

            echo "   Logged " . count($this->findings) . " anomalies to history\n";
        } catch (\Exception $e) {
            echo "   !! Failed to log anomalies: {$e->getMessage()}\n";
        }
    }

    // ═══════════════════════════════════════════
    //  TREND & PATTERN ANALYSIS
    // ═══════════════════════════════════════════

    private function checkTrends(): void
    {
        echo "── Checking Trends & Patterns...\n";

        // 1. Worksites with recurring anomalies (same type appearing multiple times)
        $this->checkRecurringAnomalies();

        // 2. Escalating severity (warning → alert progression)
        $this->checkEscalatingSeverity();

        // 3. Module-level trend (are certain modules consistently problematic?)
        $this->checkModuleTrends();

        // 4. Anomaly velocity (increasing/decreasing rate)
        $this->checkAnomalyVelocity();

        echo "   Found " . count(array_filter($this->findings, fn($f) => $f['module'] === 'tendenze')) . " trend findings\n";
    }

    private function checkRecurringAnomalies(): void
    {
        // Find anomaly types that appear 3+ times in last 14 days for same worksite
        $stmt = $this->conn->prepare("
            SELECT
                ah.worksite_id,
                ah.worksite_name,
                ah.anomaly_type,
                COUNT(*) AS occurrence_count,
                MIN(ah.run_date) AS first_occurrence,
                MAX(ah.run_date) AS last_occurrence,
                GROUP_CONCAT(DISTINCT ah.module ORDER BY ah.module) AS modules
            FROM bb_anomaly_history ah
            WHERE ah.run_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND ah.worksite_id IS NOT NULL
            GROUP BY ah.worksite_id, ah.anomaly_type
            HAVING occurrence_count >= 3
            ORDER BY occurrence_count DESC, last_occurrence DESC
        ");
        $stmt->execute();
        $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recurring as $r) {
            $daysSpan = (int)date_diff(date_create($r['first_occurrence']), date_create($r['last_occurrence']))->days + 1;
            $this->addFinding('tendenze', 'warning', 'anomaly_tendenze',
                "⚠️\n **Cantiere con problema ricorrente**: \"{$r['worksite_name']}\" ha lo stesso tipo di anomalia " .
                "({$r['anomaly_type']}) ripetuto {$r['occurrence_count']} volte negli ultimi {$daysSpan} giorni. " .
                "Questo suggerisce un problema strutturale non risolto.",
                ['worksite_id' => $r['worksite_id'], 'occurrence_count' => $r['occurrence_count'], 'anomaly_type' => $r['anomaly_type']]
            );
        }
    }

    private function checkEscalatingSeverity(): void
    {
        // Find worksites that went from warning → alert in last 7 days
        $stmt = $this->conn->prepare("
            SELECT
                ah.worksite_id,
                ah.worksite_name,
                ah.anomaly_type,
                MIN(CASE WHEN ah.severity = 'warning' THEN ah.run_date END) AS first_warning,
                MIN(CASE WHEN ah.severity = 'alert' THEN ah.run_date END) AS first_alert,
                COUNT(CASE WHEN ah.severity = 'alert' THEN 1 END) AS alert_count
            FROM bb_anomaly_history ah
            WHERE ah.run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND ah.worksite_id IS NOT NULL
            GROUP BY ah.worksite_id, ah.anomaly_type
            HAVING first_warning IS NOT NULL AND first_alert IS NOT NULL
               AND first_warning < first_alert
            ORDER BY first_alert DESC
        ");
        $stmt->execute();
        $escalated = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($escalated as $e) {
            $this->addFinding('tendenze', 'alert', 'anomaly_tendenze',
                "🚨\n **Problema escalato per \"{$e['worksite_name']}\"**: " .
                "L'anomalia {$e['anomaly_type']} è passata da WARNING ad ALERT il {$e['first_alert']}. " .
                "La situazione si sta aggravando.",
                ['worksite_id' => $e['worksite_id'], 'anomaly_type' => $e['anomaly_type']]
            );
        }
    }

    private function checkModuleTrends(): void
    {
        // Are certain modules consistently problematic? (e.g., always documents expiring)
        $stmt = $this->conn->prepare("
            SELECT
                module,
                COUNT(*) AS total_anomalies,
                COUNT(CASE WHEN severity = 'alert' THEN 1 END) AS alert_count,
                COUNT(CASE WHEN severity = 'warning' THEN 1 END) AS warning_count,
                COUNT(DISTINCT worksite_id) AS affected_worksites
            FROM bb_anomaly_history
            WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY module
            HAVING total_anomalies >= 5
            ORDER BY alert_count DESC, warning_count DESC
        ");
        $stmt->execute();
        $moduleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alertHeavyModules = array_filter($moduleStats, fn($m) => (int)$m['alert_count'] >= 3);

        if (!empty($alertHeavyModules)) {
            $details = [];
            foreach ($alertHeavyModules as $m) {
                $moduleLabel = $this->getModuleLabel($m['module']);
                $details[] = "- **{$moduleLabel}**: {$m['alert_count']} ALERT, {$m['warning_count']} WARNING su {$m['affected_worksites']} cantieri";
            }

            $this->addFinding('tendenze', 'warning', 'anomaly_tendenze',
                "📊\n **Pattern problematico rilevato**: I seguenti moduli hanno molte anomalie negli ultimi 30 giorni:\n\n" .
                implode("\n", $details) .
                "\n\n⚠️ Potrebbe indicare un problema sistemico da affrontare.",
                ['modules' => array_column($alertHeavyModules, 'module')]
            );
        }
    }

    private function checkAnomalyVelocity(): void
    {
        // Is the total number of anomalies increasing or decreasing?
        $stmt = $this->conn->prepare("
            SELECT
                run_date,
                COUNT(*) AS anomaly_count,
                COUNT(CASE WHEN severity = 'alert' THEN 1 END) AS alert_count
            FROM bb_anomaly_history
            WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            GROUP BY run_date
            ORDER BY run_date ASC
        ");
        $stmt->execute();
        $dailyCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($dailyCounts) >= 3) {
            $firstHalf = array_slice($dailyCounts, 0, max(1, count($dailyCounts) / 2));
            $secondHalf = array_slice($dailyCounts, count($dailyCounts) / 2);

            $firstAvg = array_sum(array_column($firstHalf, 'anomaly_count')) / count($firstHalf);
            $secondAvg = array_sum(array_column($secondHalf, 'anomaly_count')) / count($secondHalf);

            $changePercent = round((($secondAvg - $firstAvg) / $firstAvg) * 100, 1);

            if ($changePercent > 20) {
                $this->addFinding('tendenze', 'warning', 'anomaly_tendenze',
                    "📈\n **Velocità anomalie in aumento**: La media giornaliera di anomalie è salita da " .
                    round($firstAvg, 1) . " a " . round($secondAvg, 1) . " (+{$changePercent}%). " .
                    "Qualcosa sta peggiorando globalmente.",
                    ['first_avg' => $firstAvg, 'second_avg' => $secondAvg, 'change_percent' => $changePercent]
                );
            } elseif ($changePercent < -20) {
                $this->addFinding('tendenze', 'info', 'anomaly_tendenze',
                    "✅\n **Tendenza positiva**: Le anomalie sono diminuite del " . abs($changePercent) . ". " .
                    "Le azioni correttive stanno funzionando!",
                    ['first_avg' => $firstAvg, 'second_avg' => $secondAvg, 'change_percent' => $changePercent]
                );
            }
        }
    }
}
