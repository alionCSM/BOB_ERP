<?php

namespace App\Domain;
use PDO;

class WorksiteStats
{
    private PDO $conn;
    private int $worksiteId;

    private ?int $distinctPresenceDays = null;


    public function __construct(PDO $conn, int $worksiteId)
    {
        $this->conn       = $conn;
        $this->worksiteId = $worksiteId;
    }

    /* ============================================================
       PUBLIC API
    ============================================================ */

    public function getSummary(): array
    {
        $costi    = $this->getCosts();
        $ricavi   = $this->getRevenues();

        return [
            'costi'     => $costi,
            'ricavi'    => $ricavi,
            'andamento' => $ricavi['tot_ricavi'] - $costi['tot_costi'],
        ];
    }

    public function getGiorniLavorati(): int
    {
        return $this->getDistinctPresenceDays();
    }

    /* ============================================================
       COSTI
    ============================================================ */

    public function getCosts(): array
    {
        $costiNostri    = $this->calcPresenzeNostri();
        $costiCons      = $this->calcPresenzeConsorziate();
        $pastiNostri    = $this->calcPastiNostri();
        $pastiCons      = $this->calcPastiConsorziate();
        $mezzi          = $this->calcMezziSollevamento();
        $ordini         = $this->calcOrdini();
        $hotel          = $this->getTotaleHotel();


        $tot = $costiNostri
            + $costiCons
            + $pastiNostri
            + $pastiCons
            + $mezzi
            + $ordini
            + $hotel['totale']['euro'];


        return [
            'nostri'        => $costiNostri,
            'consorziate'   => $costiCons,
            'pasti_nostri'  => $pastiNostri,
            'pasti_cons'    => $pastiCons,
            'mezzi'         => $mezzi,
            'ordini'        => $ordini,
            'hotel'         => $hotel['totale']['euro'],
            'tot_costi'     => $tot,
        ];
    }


    /* ============================================================
       RICAVI
    ============================================================ */

    public function getRevenues(): array
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(total_offer,0) AS total_offer,
                   COALESCE(is_consuntivo,0) AS is_consuntivo,
                   prezzo_persona
            FROM bb_worksites
            WHERE id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);
        $wsRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $isConsuntivo  = (bool)($wsRow['is_consuntivo'] ?? false);
        $prezzoPersona = (float)($wsRow['prezzo_persona'] ?? 0);
        $contratto     = (float)($wsRow['total_offer'] ?? 0);

        // extra always applies
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(totale),0)
            FROM bb_extra
            WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);
        $extra = (float)$stmt->fetchColumn();

        if ($isConsuntivo && $prezzoPersona > 0) {
            $lavoratori    = $this->getTotaleLavoratori();
            $ricavoStimato = round($lavoratori['totale'] * $prezzoPersona, 2);
            return [
                'contratto'      => 0.0,
                'extra'          => $extra,
                'ricavo_stimato' => $ricavoStimato,
                'tot_ricavi'     => $ricavoStimato + $extra,
                'is_consuntivo'  => true,
                'prezzo_persona' => $prezzoPersona,
            ];
        }

        return [
            'contratto'      => $contratto,
            'extra'          => $extra,
            'ricavo_stimato' => null,
            'tot_ricavi'     => $contratto + $extra,
            'is_consuntivo'  => false,
            'prezzo_persona' => null,
        ];
    }

    /* ============================================================
       CORE HELPERS
    ============================================================ */

    private function getCompanyIdByName(string $name): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT id FROM bb_companies WHERE name = :name LIMIT 1
        ");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function hasOrdineByCompanyName(string $companyName): bool
    {
        $companyId = $this->getCompanyIdByName($companyName);
        if (!$companyId) {
            return false;
        }

        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM bb_ordini
            WHERE worksite_id = :wid
              AND company_id  = :cid
        ");
        $stmt->execute([
            'wid' => $this->worksiteId,
            'cid' => $companyId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasOrdineByCompanyId(int $companyId): bool
    {
        $stmt = $this->conn->prepare("
        SELECT COUNT(*)
        FROM bb_ordini
        WHERE worksite_id = :wid
          AND company_id  = :cid
    ");
        $stmt->execute([
            'wid' => $this->worksiteId,
            'cid' => $companyId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }


    private function getDistinctPresenceDays(): int
    {
        if ($this->distinctPresenceDays !== null) {
            return $this->distinctPresenceDays;
        }

        $stmt = $this->conn->prepare("
        SELECT COUNT(DISTINCT d) FROM (
            SELECT data AS d 
            FROM bb_presenze 
            WHERE worksite_id = :wid1

            UNION

            SELECT data_presenza AS d 
            FROM bb_presenze_consorziate 
            WHERE worksite_id = :wid2
        ) x
    ");

        $stmt->execute([
            'wid1' => $this->worksiteId,
            'wid2' => $this->worksiteId,
        ]);

        return $this->distinctPresenceDays = (int)$stmt->fetchColumn();
    }

    /* ============================================================
       COSTI DETTAGLIO
    ============================================================ */

    private function calcPresenzeNostri(): float
    {
        $stmt = $this->conn->prepare("
        SELECT
            azienda,
            SUM(
                CASE turno
                    WHEN 'Intero' THEN 1
                    WHEN 'Mezzo'  THEN 0.5
                    ELSE 0
                END
            ) AS giornate_eq
        FROM bb_presenze
        WHERE worksite_id = :wid
        GROUP BY azienda
    ");
        $stmt->execute(['wid' => $this->worksiteId]);

        $tot = 0.0;

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {

            // Se esiste un ordine per questa azienda, non conteggio i costi "nostri"
            if ($this->hasOrdineByAziendaName((string)$r['azienda'])) {
                continue;
            }

            $giornateEq = (float)$r['giornate_eq'];

            // 230€/giorno pieno → mezzo = 0.5
            $tot += $giornateEq * 230.0;
        }

        return $tot;
    }


    private function hasOrdineByAziendaName(string $azienda): bool
    {
        $stmt = $this->conn->prepare("
        SELECT COUNT(*)
        FROM bb_ordini o
        JOIN bb_companies c ON c.id = o.company_id
        WHERE o.worksite_id = :wid
          AND c.name LIKE :azienda
    ");

        $stmt->execute([
            'wid'     => $this->worksiteId,
            'azienda' => '%' . trim($azienda) . '%'
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }


    private function calcPresenzeConsorziate(): float
    {
        $stmt = $this->conn->prepare("
            SELECT azienda_id, quantita, costo_unitario
            FROM bb_presenze_consorziate
            WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);

        $tot = 0.0;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($this->hasOrdineByCompanyId((int)$r['azienda_id'])) {
                continue;
            }
            $tot += $r['quantita'] * $r['costo_unitario'];
        }

        return $tot;
    }

    private function calcPastiNostri(): float
    {


        $stmt = $this->conn->prepare("
            SELECT azienda, pranzo, pranzo_prezzo, cena, cena_prezzo
            FROM bb_presenze
            WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);

        $tot = 0.0;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {

            if ($this->hasOrdineByAziendaName($r['azienda'])) {
                continue;
            }


            if ($r['pranzo'] === 'Noi') $tot += (float)$r['pranzo_prezzo'];
            if ($r['pranzo'] === 'Loro') $tot += 10;
            if ($r['cena'] === 'Noi')   $tot += (float)$r['cena_prezzo'];
            if ($r['cena'] === 'Loro')  $tot += 10;
        }

        return $tot;
    }

    private function calcPastiConsorziate(): float
    {
        $stmt = $this->conn->prepare("
            SELECT azienda_id, pasti
            FROM bb_presenze_consorziate
            WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);

        $tot = 0.0;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($this->hasOrdineByCompanyId((int)$r['azienda_id'])) {
                continue;
            }
            $tot += (float)$r['pasti'];
        }

        return $tot;
    }

    private function calcMezziSollevamento(): float
    {
        $stmt = $this->conn->prepare("
        SELECT *
        FROM bb_worksite_lifting
        WHERE worksite_id = :wid
    ");
        $stmt->execute(['wid' => $this->worksiteId]);

        $tot = 0.0;

        while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {

            if ($m['tipo_noleggio'] === 'Una Tantum') {
                $tot += $m['costo_giornaliero'] * $m['quantita'];
                continue;
            }

            // 🔹 Count presence days AFTER mezzo start date
            $giorniStmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT d) FROM (
                SELECT data AS d 
                FROM bb_presenze
                WHERE worksite_id = :wid
                  AND data >= :start

                UNION

                SELECT data_presenza AS d
                FROM bb_presenze_consorziate
                WHERE worksite_id = :wid2
                  AND data_presenza >= :start2
            ) x
        ");

            $giorniStmt->execute([
                'wid'    => $this->worksiteId,
                'wid2'   => $this->worksiteId,
                'start'  => $m['data_inizio'],
                'start2' => $m['data_inizio']
            ]);

            $giorni = (int)$giorniStmt->fetchColumn();

            $tot += $m['costo_giornaliero'] * $giorni * $m['quantita'];
        }

        return $tot;
    }

    private function calcOrdini(): float
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(total),0)
            FROM bb_ordini
            WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $this->worksiteId]);

        return (float)$stmt->fetchColumn();
    }

    public function getTotaleLavoratori(): array
    {
        $nostri = 0.0;
        $cons   = 0.0;

        // NOSTRI → equivalenti
        $stmt = $this->conn->prepare("
        SELECT
            azienda,
            SUM(
                CASE turno
                    WHEN 'Intero' THEN 1
                    WHEN 'Mezzo'  THEN 0.5
                    ELSE 0
                END
            ) AS qta_eq
        FROM bb_presenze
        WHERE worksite_id = :wid
        GROUP BY azienda
    ");
        $stmt->execute(['wid' => $this->worksiteId]);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($this->hasOrdineByAziendaName($r['azienda'])) {
                continue;
            }
            $nostri += (float)$r['qta_eq'];
        }

        // CONSORZIATE (quantità già numerica)
        $stmt = $this->conn->prepare("
        SELECT SUM(quantita)
        FROM bb_presenze_consorziate
        WHERE worksite_id = :wid
    ");
        $stmt->execute(['wid' => $this->worksiteId]);
        $cons = (float)$stmt->fetchColumn();

        return [
            'nostri'      => $nostri,
            'consorziate' => $cons,
            'totale'      => $nostri + $cons
        ];
    }

    public function getTotalePasti(): array
    {
        $noi_qta   = 0;
        $noi_euro  = 0.0;
        $loro_qta  = 0;
        $loro_euro = 0.0;

        /* -------- NOSTRI (per azienda) -------- */
                $stmt = $this->conn->prepare("
            SELECT azienda, pranzo, pranzo_prezzo, cena, cena_prezzo
            FROM bb_presenze
            WHERE worksite_id = :wid
        ");
                $stmt->execute(['wid' => $this->worksiteId]);

                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($this->hasOrdineByAziendaName($r['azienda'])) {
                        continue;
                    }


                    if ($r['pranzo'] === 'Noi') {
                           $noi_qta++;
                           $noi_euro += (float)$r['pranzo_prezzo'];
                       }
            if ($r['pranzo'] === 'Loro') {
                           $loro_qta++;
                            $loro_euro += 10;
                       }

            if ($r['cena'] === 'Noi') {
                            $noi_qta++;
                           $noi_euro += (float)$r['cena_prezzo'];
                       }
            if ($r['cena'] === 'Loro') {
                            $loro_qta++;
                            $loro_euro += 10;
                        }
        }

        /* -------- CONSORZIATE -------- */
        $stmt = $this->conn->prepare("
    SELECT azienda_id, quantita, pasti
    FROM bb_presenze_consorziate
    WHERE worksite_id = :wid
");
        $stmt->execute(['wid' => $this->worksiteId]);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($this->hasOrdineByCompanyId((int)$r['azienda_id'])) {
                continue;
            }

            $noi_qta  += (int)$r['quantita'];
            $noi_euro += (float)$r['pasti'];
        }


        return [
            'noi' => [
                'qta'  => $noi_qta,      // solo nostri
                'euro' => $noi_euro
            ],
            'loro' => [
                'qta'  => $loro_qta,
                'euro' => $loro_euro
            ],
            'totale' => [
                'qta'  => $noi_qta + $loro_qta, // solo pasti conteggiabili
                'euro' => $noi_euro + $loro_euro
            ]
        ];
    }

    public function getTotaleHotel(): array
    {
        $nostri_qta  = 0;
        $nostri_euro = 0.0;

        $cons_qta  = 0;
        $cons_euro = 0.0;

        /* =========================
                   NOSTRI (per azienda)
                   hotel = costo per persona
                ========================= */
                $stmt = $this->conn->prepare("
            SELECT azienda, hotel
            FROM bb_presenze
            WHERE worksite_id = :wid
              AND hotel IS NOT NULL
              AND hotel <> ''
              AND hotel <> '-'
        ");
                $stmt->execute(['wid' => $this->worksiteId]);

                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($this->hasOrdineByAziendaName($r['azienda'])) {
                        continue;
                    }

                    $nostri_qta++;
            $nostri_euro += (float)$r['hotel'];
        }

        /* =========================
           CONSORZIATE
           hotel = costo TOTALE
           quantita = persone
        ========================= */
        $stmt = $this->conn->prepare("
        SELECT azienda_id, quantita, hotel
        FROM bb_presenze_consorziate
        WHERE worksite_id = :wid
          AND hotel IS NOT NULL
          AND hotel <> ''
          AND hotel <> '-'
    ");
        $stmt->execute(['wid' => $this->worksiteId]);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {

            if ($this->hasOrdineByCompanyId((int)$r['azienda_id'])) {
                continue;
            }

            // persone = quantita
            $cons_qta += (int)$r['quantita'];

            // costo = hotel (already total)
            $cons_euro += (float)$r['hotel'];
        }

        return [
            'nostri' => [
                'qta'  => $nostri_qta,
                'euro' => $nostri_euro
            ],
            'consorziate' => [
                'qta'  => $cons_qta,
                'euro' => $cons_euro
            ],
            'totale' => [
                'qta'  => $nostri_qta + $cons_qta,
                'euro' => $nostri_euro + $cons_euro
            ]
        ];
    }

    public function getYardData(): ?array
    {
        $stmt = $this->conn->prepare("
        SELECT *
        FROM bb_cantiere_stats_2025
        WHERE cantiere_id_sqlsrv = (
            SELECT yard_worksite_id
            FROM bb_worksites
            WHERE id = :wid
            LIMIT 1
        )
    ");

        $stmt->execute(['wid' => $this->worksiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'exists_in_yard'            => true,
            'presenze_nostri_qta'       => (int)($row['presenze_consorzio_qta'] ?? 0),
            'presenze_consorziate_qta'  => (int)($row['presenze_consorziate_qta'] ?? 0),
            'mezzi_costo'               => (float)($row['mezzi_costo'] ?? 0),
            'hotel_costo'               => (float)($row['hotel_costo'] ?? 0),
            'pasti_costo'               => (float)($row['pasti_costo'] ?? 0),
            'totale_complessivo'        => (float)($row['totale_complessivo'] ?? 0),
        ];
    }




}
