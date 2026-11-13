<?php

declare(strict_types=1);

namespace App\Repository\Business;

use PDO;

/**
 * Letture aggregate del registro IVA dal gestionale Business (TeamSystem).
 *
 * **Perche' il registro IVA e non le fatture.** Prima si leggevano le
 * testate dei documenti (`testmag`): li' dentro pero' ci sono solo i
 * documenti che emettiamo NOI. Le fatture dei fornitori non ci passano, e
 * la pagina mostrava le uscite a zero. Il registro IVA (`moviva`) tiene
 * tutte e due le direzioni, e in piu' parte dal 2017 invece che dal 2019.
 *
 * Modello dati (moviva = movimenti IVA):
 *   - mi_datdoc    data del documento → criterio di COMPETENZA
 *   - mi_datreg    data di registrazione (qui non si usa)
 *   - mi_tregiva   registro: 'V' vendite, 'A' acquisti
 *   - mi_contocf → anagra.an_conto   il cliente o il fornitore
 *   - mi_imponib   imponibile
 *   - mi_importo   imposta
 *   - mi_causale   tipo di documento → tabcaus
 *   - codditt      codice ditta
 *
 * **Il segno.** Le vendite sono registrate in avere, quindi `mi_imponib`
 * e' NEGATIVO; gli acquisti sono positivi. Qui esce sempre un numero
 * positivo, girando il segno alle vendite: chi legge un fatturato non si
 * aspetta un meno davanti. Le note di credito, dentro il loro registro,
 * hanno il segno opposto e quindi si sottraggono da sole — nessuno deve
 * decidere niente.
 *
 * **Entrate e uscite si dividono per REGISTRO**, con un'eccezione: nel
 * registro vendite ci sono anche movimenti con un FORNITORE come
 * controparte. Sono le autofatture / integrazioni del reverse charge: la
 * fattura del fornitore e' registrata negli acquisti (ed e' un costo vero)
 * e, solo per l'IVA, anche nelle vendite. Quelle righe NON sono ricavi:
 * contarle gonfiava il fatturato (gennaio 2026: +38.302 €, causali 5002,
 * 9000, 5001 presenti identiche in tutti e due i registri). Si escludono
 * dalle entrate e non si aggiungono alle uscite, dove il costo c'e' gia'.
 *
 * Le somme le esegue SQL Server: anche su anni di storico tornano poche
 * decine di righe, quindi non serve replicare i dati dentro BOB.
 *
 * SOLA LETTURA: qui non esistono INSERT/UPDATE.
 */
final class BusinessInvoiceRepository
{
    /** Il registro delle vendite: quello che incassiamo. */
    public const VENDITE = 'V';

    /** Il registro degli acquisti: quello che paghiamo. */
    public const ACQUISTI = 'A';

    /**
     * L'imponibile col segno girato per le vendite.
     *
     * Scritto una volta sola e riusato: il giorno che si sbaglia qui si
     * sbaglia dappertutto allo stesso modo, invece che in un posto solo.
     */
    private const IMPONIBILE = "(CASE WHEN m.mi_tregiva = 'V' THEN -m.mi_imponib ELSE m.mi_imponib END)";
    private const IMPOSTA    = "(CASE WHEN m.mi_tregiva = 'V' THEN -m.mi_importo ELSE m.mi_importo END)";

    /**
     * Le righe da contare: tutto, tranne le autofatture del reverse charge
     * (registro vendite con un fornitore come controparte). Vuole l'alias
     * `a` su anagra.
     */
    private const SENZA_AUTOFATTURE = "NOT (m.mi_tregiva = 'V' AND ISNULL(a.an_tipo, '') = 'F')";
    private const JOIN_ANAGRA = "LEFT JOIN anagra a ON a.an_conto = m.mi_contocf AND a.codditt = m.codditt";

    /**
     * @param string $tabella la tabella del registro IVA. Il nome arriva da
     *                        Config, che lo ripulisce: qui finisce dentro la
     *                        query, dove un parametro non si puo' usare.
     */
    public function __construct(
        private PDO $conn,
        private string $codDitta,
        private string $tabella = 'moviva'
    ) {}

    /**
     * Totali per anno/mese e registro, nell'intervallo di anni dato.
     *
     * @return array<int, array{anno:int, mese:int, registro:string,
     *                          documenti:int, imponibile:float, iva:float}>
     */
    public function monthlyTotals(int $fromYear, int $toYear): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                YEAR(m.mi_datdoc)  AS anno,
                MONTH(m.mi_datdoc) AS mese,
                m.mi_tregiva       AS registro,
                COUNT(*)                        AS documenti,
                SUM(" . self::IMPONIBILE . ")   AS imponibile,
                SUM(" . self::IMPOSTA . ")      AS iva
            FROM   {$this->tabella} m
            " . self::JOIN_ANAGRA . "
            WHERE  m.codditt    = :ditta
              AND  m.mi_tregiva IN ('V', 'A')
              AND  m.mi_datdoc >= :dal
              AND  m.mi_datdoc <  :al
              AND  " . self::SENZA_AUTOFATTURE . "
            GROUP BY YEAR(m.mi_datdoc), MONTH(m.mi_datdoc), m.mi_tregiva
            ORDER BY anno, mese
        ");
        $stmt->execute([
            ':ditta' => $this->codDitta,
            ':dal'   => sprintf('%04d-01-01', $fromYear),
            ':al'    => sprintf('%04d-01-01', $toYear + 1),
        ]);

        return array_map(static fn(array $r): array => [
            'anno'       => (int)$r['anno'],
            'mese'       => (int)$r['mese'],
            'registro'   => (string)$r['registro'],
            'documenti'  => (int)$r['documenti'],
            'imponibile' => (float)$r['imponibile'],
            'iva'        => (float)$r['iva'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Chi ci ha pagato, e chi abbiamo pagato: uno per riga.
     *
     * E' la domanda da cui si parte guardando un fatturato — non "quanto"
     * ma "con chi". Una riga per controparte, con quante fatture e quanto.
     *
     * Il nome viene dall'anagrafica e non dalla copia scritta sul
     * documento: se un cliente cambia ragione sociale, la copia lascia la
     * stessa azienda su due righe diverse.
     *
     * @param string $registro 'V' vendite (chi ci ha pagato) | 'A' acquisti
     * @return array<int, array{conto:int, nome:string, piva:string,
     *                          documenti:int, imponibile:float}>
     */
    public function byControparte(string $registro, string $from, string $toExclusive, int $limite = 200): array
    {
        $registro = $registro === self::ACQUISTI ? self::ACQUISTI : self::VENDITE;
        // TOP non accetta un parametro: si limita qui, a un intero
        $limite = max(1, min(1000, $limite));

        $stmt = $this->conn->prepare("
            SELECT TOP {$limite}
                m.mi_contocf                  AS conto,
                MAX(LTRIM(RTRIM(
                    ISNULL(a.an_descr1, '') + ' ' + ISNULL(a.an_descr2, '')
                )))                           AS nome,
                MAX(ISNULL(a.an_pariva, ''))  AS piva,
                COUNT(*)                      AS documenti,
                SUM(" . self::IMPONIBILE . ") AS imponibile
            FROM   {$this->tabella} m
            " . self::JOIN_ANAGRA . "
            WHERE  m.codditt    = :ditta
              AND  m.mi_tregiva = :registro
              AND  m.mi_datdoc >= :dal
              AND  m.mi_datdoc <  :al
              AND  " . self::SENZA_AUTOFATTURE . "
            GROUP BY m.mi_contocf
            ORDER BY SUM(" . self::IMPONIBILE . ") DESC
        ");
        $stmt->execute([
            ':ditta'    => $this->codDitta,
            ':registro' => $registro,
            ':dal'      => $from,
            ':al'       => $toExclusive,
        ]);

        return array_map(static function (array $r): array {
            $nome = trim((string)$r['nome']);
            return [
                'conto' => (int)$r['conto'],
                // un conto senza anagrafica non deve sparire: si vede il
                // numero, e si capisce che manca il nome invece di trovarsi
                // una riga vuota
                'nome'       => $nome !== '' ? $nome : 'Conto ' . (int)$r['conto'],
                'piva'       => trim((string)$r['piva']),
                'documenti'  => (int)$r['documenti'],
                'imponibile' => (float)$r['imponibile'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dettaglio per causale di un periodo.
     *
     * Serve a VERIFICARE i numeri: senza vedere quali causali entrano nei
     * totali non si sa cosa si sta sommando.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byCausale(string $from, string $toExclusive): array
    {
        // la descrizione della causale da qualsiasi anno: filtrando per
        // l'anno del documento non si trovava quasi mai, e restava "cod. 1000"
        $stmt = $this->conn->prepare("
            SELECT
                m.mi_causale                  AS causale,
                MAX(c.descrizione)            AS descrizione,
                m.mi_tregiva                  AS registro,
                ISNULL(a.an_tipo, '')         AS tipo,
                COUNT(*)                      AS documenti,
                SUM(" . self::IMPONIBILE . ") AS imponibile,
                SUM(" . self::IMPOSTA . ")    AS iva
            FROM   {$this->tabella} m
            " . self::JOIN_ANAGRA . "
            LEFT JOIN (SELECT tb_codcaus, MAX(tb_descaus) AS descrizione FROM tabcaus GROUP BY tb_codcaus) c
                   ON c.tb_codcaus = m.mi_causale
            WHERE  m.codditt    = :ditta
              AND  m.mi_tregiva IN ('V', 'A')
              AND  m.mi_datdoc >= :dal
              AND  m.mi_datdoc <  :al
            GROUP BY m.mi_causale, m.mi_tregiva, ISNULL(a.an_tipo, '')
            ORDER BY m.mi_tregiva DESC, SUM(" . self::IMPONIBILE . ") DESC
        ");
        $stmt->execute([':ditta' => $this->codDitta, ':dal' => $from, ':al' => $toExclusive]);

        return array_map(static function (array $r): array {
            $autofattura = $r['registro'] === self::VENDITE && $r['tipo'] === 'F';
            return [
                'causale'     => (int)$r['causale'],
                'descrizione' => $r['descrizione'] !== null ? trim((string)$r['descrizione']) : null,
                'registro'    => (string)$r['registro'],
                'tipo'        => (string)$r['tipo'],      // C cliente, F fornitore, S altro
                'documenti'   => (int)$r['documenti'],
                'imponibile'  => (float)$r['imponibile'],
                'iva'         => (float)$r['iva'],
                // dove finisce nei totali della pagina
                'conteggio'   => $autofattura ? 'esclusa' : ($r['registro'] === self::VENDITE ? 'entrate' : 'uscite'),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Anni per cui esistono movimenti, dal piu' recente. */
    public function availableYears(): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT YEAR(mi_datdoc) AS anno
            FROM   {$this->tabella}
            WHERE  codditt = :ditta
              AND  mi_tregiva IN ('V', 'A')
              AND  mi_datdoc > '1900-01-01'
            ORDER BY anno DESC
        ");
        $stmt->execute([':ditta' => $this->codDitta]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
