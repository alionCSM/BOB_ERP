<?php
declare(strict_types=1);

namespace App\Service;
use PDO;
use App\Domain\WorksiteStats;

class WorksiteContextBuilder
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function build(int $worksiteId, bool $canSeePrices): array
    {

        /* ============================================================
           WORKSITE BASE
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                id, name, worksite_code, location, descrizione,
                status, order_number, order_date, offer_number,
                yard_worksite_id, created_at,
                is_consuntivo, prezzo_persona
            FROM bb_worksites
            WHERE id = :wid
            LIMIT 1
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$w) {
            return ['error' => 'worksite_not_found'];
        }

        $statsHandler = new WorksiteStats($this->conn, $worksiteId);

        /* ============================================================
           PRESENZE AGGREGATE
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT d) FROM (
                SELECT data AS d FROM bb_presenze WHERE worksite_id = :wid1
                UNION
                SELECT data_presenza AS d FROM bb_presenze_consorziate WHERE worksite_id = :wid2
            ) x
        ");
        $stmt->execute([':wid1' => $worksiteId, ':wid2' => $worksiteId]);
        $giorni = (int)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT MAX(d) FROM (
                SELECT MAX(data) AS d FROM bb_presenze WHERE worksite_id = :wid1
                UNION ALL
                SELECT MAX(data_presenza) AS d FROM bb_presenze_consorziate WHERE worksite_id = :wid2
            ) x
        ");
        $stmt->execute([':wid1' => $worksiteId, ':wid2' => $worksiteId]);
        $lastPresenza = $stmt->fetchColumn() ?: null;

        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(
                CASE turno
                    WHEN 'Intero' THEN 1
                    WHEN 'Mezzo' THEN 0.5
                    ELSE 0
                END
            ),0)
            FROM bb_presenze
            WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $nostriEq = (float)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(quantita),0)
            FROM bb_presenze_consorziate
            WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $consEq = (float)$stmt->fetchColumn();

        /* ============================================================
           WORKERS & COMPANIES
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT worker_id)
            FROM bb_presenze
            WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $distinctWorkers = (int)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT DISTINCT c.name
            FROM bb_presenze_consorziate pc
            JOIN bb_companies c ON c.id = pc.azienda_id
            WHERE pc.worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $aziende = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $aziende[] = $row['name'];
        }

        /* ============================================================
           PRESENZE PER LAVORATORE (nostri)
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                CONCAT(w.last_name, ' ', w.first_name) AS nome,
                p.azienda,
                COUNT(*) AS presenze_totali,
                SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END) AS giornate_eq,
                MIN(p.data) AS prima_presenza,
                MAX(p.data) AS ultima_presenza,
                SUM(CASE WHEN p.pranzo IN ('Noi','Loro') THEN 1 ELSE 0 END) AS pasti_pranzo,
                SUM(CASE WHEN p.cena IN ('Noi','Loro') THEN 1 ELSE 0 END) AS pasti_cena,
                SUM(CASE WHEN p.hotel IS NOT NULL AND p.hotel <> '' AND p.hotel <> '-' THEN 1 ELSE 0 END) AS notti_hotel
            FROM bb_presenze p
            JOIN bb_workers w ON w.id = p.worker_id
            WHERE p.worksite_id = :wid
            GROUP BY w.id, w.last_name, w.first_name, p.azienda
            ORDER BY giornate_eq DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $presenzePerLavoratore = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           PRESENZE PER MESE (nostri + consorziati)
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                mese,
                SUM(nostri_eq) AS nostri_eq,
                SUM(cons_eq) AS consorziati_eq,
                SUM(giorni_distinti) AS giorni_distinti
            FROM (
                SELECT
                    DATE_FORMAT(data, '%Y-%m') AS mese,
                    SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END) AS nostri_eq,
                    0 AS cons_eq,
                    COUNT(DISTINCT data) AS giorni_distinti
                FROM bb_presenze
                WHERE worksite_id = :wid1
                GROUP BY DATE_FORMAT(data, '%Y-%m')

                UNION ALL

                SELECT
                    DATE_FORMAT(data_presenza, '%Y-%m') AS mese,
                    0 AS nostri_eq,
                    SUM(quantita) AS cons_eq,
                    COUNT(DISTINCT data_presenza) AS giorni_distinti
                FROM bb_presenze_consorziate
                WHERE worksite_id = :wid2
                GROUP BY DATE_FORMAT(data_presenza, '%Y-%m')
            ) x
            GROUP BY mese
            ORDER BY mese DESC
        ");
        $stmt->execute([':wid1' => $worksiteId, ':wid2' => $worksiteId]);
        $presenzePerMese = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           PRESENZE CONSORZIATE PER AZIENDA
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                c.name AS azienda,
                COUNT(*) AS righe,
                SUM(pc.quantita) AS giornate_totali,
                MIN(pc.data_presenza) AS prima_presenza,
                MAX(pc.data_presenza) AS ultima_presenza
            FROM bb_presenze_consorziate pc
            JOIN bb_companies c ON c.id = pc.azienda_id
            WHERE pc.worksite_id = :wid
            GROUP BY c.id, c.name
            ORDER BY giornate_totali DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $consorziatePerAzienda = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           ULTIME 20 PRESENZE (nostri) per contesto dettagliato
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                p.data,
                CONCAT(w.last_name, ' ', w.first_name) AS lavoratore,
                p.azienda,
                p.turno,
                p.pranzo,
                p.cena,
                CASE WHEN p.hotel IS NOT NULL AND p.hotel <> '' AND p.hotel <> '-' THEN 'Sì' ELSE 'No' END AS hotel,
                p.note
            FROM bb_presenze p
            JOIN bb_workers w ON w.id = p.worker_id
            WHERE p.worksite_id = :wid
            ORDER BY p.data DESC
            LIMIT 20
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $ultimePresenze = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           ULTIME 10 PRESENZE CONSORZIATE
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                pc.data_presenza AS data,
                c.name AS azienda,
                pc.quantita,
                pc.note
            FROM bb_presenze_consorziate pc
            JOIN bb_companies c ON c.id = pc.azienda_id
            WHERE pc.worksite_id = :wid
            ORDER BY pc.data_presenza DESC
            LIMIT 10
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $ultimeConsorziate = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           MEZZI DI SOLLEVAMENTO
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT
                wsl.id,
                le.descrizione,
                wsl.quantita,
                wsl.stato,
                wsl.data_inizio,
                wsl.data_fine,
                wsl.tipo_noleggio,
                wsl.costo_giornaliero
            FROM bb_worksite_lifting wsl
            JOIN bb_lifting_equipment le ON le.id = wsl.lifting_equipment_id
            WHERE wsl.worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $mezzi = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $m = [
                'descrizione'      => $row['descrizione'],
                'quantita'         => (int)$row['quantita'],
                'stato'            => $row['stato'],
                'data_inizio'      => $row['data_inizio'],
                'data_fine'        => $row['data_fine'],
                'tipo_noleggio'    => $row['tipo_noleggio'],
            ];
            if ($canSeePrices) {
                $m['costo_giornaliero'] = (float)$row['costo_giornaliero'];
            }
            $mezzi[] = $m;
        }

        /* ============================================================
           ORDINI (count always, detail only with prices)
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT o.id, o.order_date, c.name AS azienda, o.total, o.note
            FROM bb_ordini o
            JOIN bb_companies c ON c.id = o.company_id
            WHERE o.worksite_id = :wid
            ORDER BY o.order_date DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $ordiniRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           EXTRA
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT id, data, ordine, descrizione, totale
            FROM bb_extra
            WHERE worksite_id = :wid
            ORDER BY data DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $extraRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
           BILLING
        ============================================================ */

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS documenti, COALESCE(SUM(totale),0) AS totale
            FROM bb_billing
            WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $billing = $stmt->fetch(PDO::FETCH_ASSOC);

        /* ============================================================
           YARD HISTORY
        ============================================================ */

        $yardData = $statsHandler->getYardData();

        /* ============================================================
           BUILD CONTEXT
        ============================================================ */

        $context = [
            'worksite' => [
                'id'              => (int)$w['id'],
                'name'            => $w['name'],
                'code'            => $w['worksite_code'],
                'location'        => $w['location'],
                'descrizione'     => $w['descrizione'],
                'status'          => $w['status'],
                'order_number'    => $w['order_number'],
                'order_date'      => $w['order_date'],
                'created_at_bob'  => $w['created_at'],
                'exists_in_yard'  => $yardData !== null,
                'is_consuntivo'   => (bool)($w['is_consuntivo'] ?? false),
                'prezzo_persona'  => isset($w['prezzo_persona']) ? (float)$w['prezzo_persona'] : null,
            ],

            'activity_summary' => [
                'giorni_lavorati'             => $giorni,
                'last_presenza'               => $lastPresenza,
                'presenze_nostri_eq'          => $nostriEq,
                'presenze_consorziate_eq'     => $consEq,
                'totale_presenze_eq'          => $nostriEq + $consEq,
                'numero_lavoratori_distinti'  => $distinctWorkers,
                'aziende_consorziate'         => $aziende,
            ],

            'presenze_per_lavoratore' => $presenzePerLavoratore,
            'presenze_per_mese'       => $presenzePerMese,
            'consorziate_per_azienda' => $consorziatePerAzienda,
            'ultime_presenze_nostri'  => $ultimePresenze,
            'ultime_presenze_consorziate' => $ultimeConsorziate,

            'mezzi' => [
                'count' => count($mezzi),
                'items' => $mezzi,
            ],

            'yard_history' => $yardData ?? ['exists_in_yard' => false],

            'can_see_prices' => $canSeePrices,
        ];

        /* ============================================================
           FINANCIAL DATA (only if authorized)
        ============================================================ */

        if ($canSeePrices) {
            // Ordini with amounts
            $ordiniDetail = [];
            foreach ($ordiniRows as $o) {
                $ordiniDetail[] = [
                    'data'    => $o['order_date'],
                    'azienda' => $o['azienda'],
                    'totale'  => (float)$o['total'],
                    'note'    => $o['note'],
                ];
            }
            $context['ordini'] = [
                'count'       => count($ordiniDetail),
                'totale'      => array_sum(array_column($ordiniDetail, 'totale')),
                'dettaglio'   => $ordiniDetail,
            ];

            // Extra with amounts
            $extraDetail = [];
            foreach ($extraRows as $e) {
                $extraDetail[] = [
                    'data'        => $e['data'],
                    'ordine'      => $e['ordine'],
                    'descrizione' => $e['descrizione'],
                    'totale'      => (float)$e['totale'],
                ];
            }
            $context['extra'] = [
                'count'     => count($extraDetail),
                'totale'    => array_sum(array_column($extraDetail, 'totale')),
                'dettaglio' => $extraDetail,
            ];

            // Billing
            $context['billing'] = [
                'documenti' => (int)($billing['documenti'] ?? 0),
                'totale'    => (float)($billing['totale'] ?? 0),
            ];

            // Cost breakdown
            $summary = $statsHandler->getSummary();
            $costs   = $summary['costi'];
            $revs    = $summary['ricavi'];
            $ricavi  = (float)($revs['tot_ricavi'] ?? 0);
            $costi   = (float)($costs['tot_costi'] ?? 0);
            $marg    = $ricavi - $costi;
            $perc    = $ricavi > 0 ? ($marg / $ricavi) * 100 : 0;

            $isConsuntivo   = (bool)($revs['is_consuntivo'] ?? false);
            $ricavoStimato  = $isConsuntivo ? (float)($revs['ricavo_stimato'] ?? 0) : null;
            $context['financial'] = [
                'is_consuntivo'       => $isConsuntivo,
                'tipo_ricavo'         => $isConsuntivo ? 'consuntivo (presenze × tariffa)' : 'contratto fisso',
                'tariffa_persona'     => $isConsuntivo ? (float)($revs['prezzo_persona'] ?? 0) : null,
                'ricavo_stimato'      => $ricavoStimato,
                'ricavi_totali'       => $ricavi,
                'ricavi_contratto'    => $isConsuntivo ? 0.0 : (float)($revs['contratto'] ?? 0),
                'ricavi_extra'        => (float)($revs['extra'] ?? 0),
                'costi_totali'        => $costi,
                'costi_presenze_nostri'      => (float)($costs['nostri'] ?? 0),
                'costi_presenze_consorziate' => (float)($costs['consorziate'] ?? 0),
                'costi_pasti_nostri'         => (float)($costs['pasti_nostri'] ?? 0),
                'costi_pasti_consorziate'    => (float)($costs['pasti_cons'] ?? 0),
                'costi_mezzi'                => (float)($costs['mezzi'] ?? 0),
                'costi_ordini'               => (float)($costs['ordini'] ?? 0),
                'costi_hotel'                => (float)($costs['hotel'] ?? 0),
                'margine'             => $marg,
                'margine_percentuale' => round($perc, 2),
            ];

            // Pasti breakdown
            $pasti = $statsHandler->getTotalePasti();
            $context['pasti_dettaglio'] = [
                'noi_quantita'   => $pasti['noi']['qta'],
                'noi_euro'       => $pasti['noi']['euro'],
                'loro_quantita'  => $pasti['loro']['qta'],
                'loro_euro'      => $pasti['loro']['euro'],
                'totale_quantita' => $pasti['totale']['qta'],
                'totale_euro'    => $pasti['totale']['euro'],
            ];

            // Hotel breakdown
            $hotel = $statsHandler->getTotaleHotel();
            $context['hotel_dettaglio'] = [
                'nostri_notti'       => $hotel['nostri']['qta'],
                'nostri_euro'        => $hotel['nostri']['euro'],
                'consorziate_notti'  => $hotel['consorziate']['qta'],
                'consorziate_euro'   => $hotel['consorziate']['euro'],
                'totale_notti'       => $hotel['totale']['qta'],
                'totale_euro'        => $hotel['totale']['euro'],
            ];

            // Presenze consorziate with costs per company
            $stmtCons = $this->conn->prepare("
                SELECT
                    c.name AS azienda,
                    SUM(pc.quantita) AS giornate,
                    SUM(pc.quantita * pc.costo_unitario) AS costo_presenze,
                    SUM(pc.pasti) AS costo_pasti,
                    SUM(CASE WHEN pc.hotel IS NOT NULL AND pc.hotel <> '' AND pc.hotel <> '-' THEN pc.hotel ELSE 0 END) AS costo_hotel
                FROM bb_presenze_consorziate pc
                JOIN bb_companies c ON c.id = pc.azienda_id
                WHERE pc.worksite_id = :wid
                GROUP BY c.id, c.name
                ORDER BY costo_presenze DESC
            ");
            $stmtCons->execute([':wid' => $worksiteId]);
            $context['consorziate_costi_per_azienda'] = $stmtCons->fetchAll(PDO::FETCH_ASSOC);

        } else {
            // No price access — only counts, no amounts
            $context['ordini'] = ['count' => count($ordiniRows)];
            $context['billing'] = ['documenti' => (int)($billing['documenti'] ?? 0)];
        }

        return $context;
    }
}
