<?php

declare(strict_types=1);

namespace App\Repository\Business;

use PDO;

/**
 * Letture aggregate dei documenti dal gestionale Business (TeamSystem).
 *
 * Modello dati (nrgtestm = testate documenti):
 *   - tm_datdoc              data del documento  → criterio di COMPETENZA
 *   - tm_conto  → anagra.an_conto
 *   - anagra.an_tipo         'C' cliente (entrate) | 'F' fornitore (uscite) | 'S' altro
 *   - tm_imponib_1..8        imponibile per aliquota (vanno sommati)
 *   - tm_imposta_1..8        IVA per aliquota
 *   - tm_totdoc              totale documento
 *   - tm_pagato              quota gia' incassata/pagata
 *   - tm_causale → tabcaus   tipo documento (fattura, nota di credito, ...)
 *   - tm_flprov = 'S'        documento provvisorio: escluso ovunque
 *
 * Le somme le esegue SQL Server: anche su anni di storico tornano poche
 * decine di righe, quindi non serve replicare i dati dentro BOB.
 *
 * SOLA LETTURA: qui non esistono INSERT/UPDATE.
 */
final class BusinessInvoiceRepository
{
    /** Somma dei campi imponibile (8 aliquote per documento). */
    private const IMPONIBILE = '(t.tm_imponib_1 + t.tm_imponib_2 + t.tm_imponib_3 + t.tm_imponib_4
                               + t.tm_imponib_5 + t.tm_imponib_6 + t.tm_imponib_7 + t.tm_imponib_8)';

    public function __construct(
        private PDO $conn,
        private string $codDitta
    ) {}

    /**
     * Totali per anno/mese e tipo controparte, nell'intervallo di anni dato.
     *
     * @return array<int, array{anno:int, mese:int, tipo:string, documenti:int,
     *                          imponibile:float, iva:float, totale:float, pagato:float}>
     */
    public function monthlyTotals(int $fromYear, int $toYear): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                YEAR(t.tm_datdoc)  AS anno,
                MONTH(t.tm_datdoc) AS mese,
                a.an_tipo          AS tipo,
                COUNT(*)                                    AS documenti,
                SUM(" . self::IMPONIBILE . ")               AS imponibile,
                SUM(t.tm_totdoc - " . self::IMPONIBILE . ") AS iva,
                SUM(t.tm_totdoc)                            AS totale,
                SUM(t.tm_pagato)                            AS pagato
            FROM   nrgtestm t
            JOIN   anagra   a ON a.an_conto = t.tm_conto AND a.codditt = t.codditt
            WHERE  t.codditt   = :ditta
              AND  t.tm_flprov = 'N'
              AND  t.tm_datdoc >= :dal
              AND  t.tm_datdoc <  :al
            GROUP BY YEAR(t.tm_datdoc), MONTH(t.tm_datdoc), a.an_tipo
            ORDER BY anno, mese
        ");
        $stmt->execute([
            ':ditta' => $this->codDitta,
            ':dal'   => sprintf('%04d-01-01', $fromYear),
            ':al'    => sprintf('%04d-01-01', $toYear + 1),
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'anno'       => (int)$r['anno'],
                'mese'       => (int)$r['mese'],
                'tipo'       => (string)$r['tipo'],
                'documenti'  => (int)$r['documenti'],
                'imponibile' => (float)$r['imponibile'],
                'iva'        => (float)$r['iva'],
                'totale'     => (float)$r['totale'],
                'pagato'     => (float)$r['pagato'],
            ];
        }
        return $out;
    }

    /**
     * Totali di un periodo libero (per il confronto periodo A / periodo B).
     *
     * @return array<string, array{documenti:int, imponibile:float, totale:float, pagato:float}>
     *         indicizzato per an_tipo
     */
    public function periodTotals(string $from, string $toExclusive): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                a.an_tipo AS tipo,
                COUNT(*)                      AS documenti,
                SUM(" . self::IMPONIBILE . ") AS imponibile,
                SUM(t.tm_totdoc)              AS totale,
                SUM(t.tm_pagato)              AS pagato
            FROM   nrgtestm t
            JOIN   anagra   a ON a.an_conto = t.tm_conto AND a.codditt = t.codditt
            WHERE  t.codditt   = :ditta
              AND  t.tm_flprov = 'N'
              AND  t.tm_datdoc >= :dal
              AND  t.tm_datdoc <  :al
            GROUP BY a.an_tipo
        ");
        $stmt->execute([':ditta' => $this->codDitta, ':dal' => $from, ':al' => $toExclusive]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['tipo']] = [
                'documenti'  => (int)$r['documenti'],
                'imponibile' => (float)$r['imponibile'],
                'totale'     => (float)$r['totale'],
                'pagato'     => (float)$r['pagato'],
            ];
        }
        return $out;
    }

    /**
     * Dettaglio per causale di un periodo.
     *
     * Serve a VERIFICARE i numeri: senza vedere quali causali entrano nei
     * totali non e' possibile sapere se, per esempio, le note di credito
     * stanno sommando invece di sottrarre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byCausale(string $from, string $toExclusive): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                t.tm_causale                  AS causale,
                MAX(c.tb_descaus)             AS descrizione,
                a.an_tipo                     AS tipo,
                COUNT(*)                      AS documenti,
                SUM(" . self::IMPONIBILE . ") AS imponibile,
                SUM(t.tm_totdoc)              AS totale
            FROM   nrgtestm t
            JOIN   anagra   a ON a.an_conto  = t.tm_conto AND a.codditt = t.codditt
            LEFT JOIN tabcaus c ON c.tb_codcaus = t.tm_causale
                               AND c.tb_anno    = YEAR(t.tm_datdoc)
            WHERE  t.codditt   = :ditta
              AND  t.tm_flprov = 'N'
              AND  t.tm_datdoc >= :dal
              AND  t.tm_datdoc <  :al
            GROUP BY t.tm_causale, a.an_tipo
            ORDER BY SUM(t.tm_totdoc) DESC
        ");
        $stmt->execute([':ditta' => $this->codDitta, ':dal' => $from, ':al' => $toExclusive]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'causale'     => (int)$r['causale'],
                'descrizione' => $r['descrizione'] !== null ? trim((string)$r['descrizione']) : null,
                'tipo'        => (string)$r['tipo'],
                'documenti'   => (int)$r['documenti'],
                'imponibile'  => (float)$r['imponibile'],
                'totale'      => (float)$r['totale'],
            ];
        }
        return $out;
    }

    /** Anni per cui esistono documenti, dal piu' recente. */
    public function availableYears(): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT YEAR(tm_datdoc) AS anno
            FROM   nrgtestm
            WHERE  codditt = :ditta AND tm_flprov = 'N' AND tm_datdoc > '1900-01-01'
            ORDER BY anno DESC
        ");
        $stmt->execute([':ditta' => $this->codDitta]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
