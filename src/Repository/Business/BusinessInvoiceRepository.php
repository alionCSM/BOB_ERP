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
 *   - mi_datreg    data di registrazione (solo mostrata nell'elenco documenti)
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
 * **Cosa NON e'.** E' il fatturato da registro IVA: non e' l'incassato e
 * non e' un conto economico. Negli acquisti mancano personale, contributi
 * e ogni costo senza fattura, quindi vendite meno acquisti non e' un
 * margine: la pagina non lo mostra apposta.
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

    /** @var string[]|null le colonne del registro IVA, lette una volta */
    private ?array $colonne = null;

    /**
     * Le colonne del registro IVA. I nomi di numero documento, serie e
     * protocollo cambiano fra le versioni di Business: si usano solo quelle
     * che ci sono, cosi' la query non si rompe.
     *
     * @return string[]
     */
    private function colonne(): array
    {
        if ($this->colonne === null) {
            try {
                $st = $this->conn->prepare('SELECT LOWER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t');
                $st->execute([':t' => $this->tabella]);
                $this->colonne = $st->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                $this->colonne = [];
            }
        }
        return $this->colonne;
    }

    /**
     * L'espressione che identifica un documento, per contarli.
     *
     * Il registro IVA ha una riga per aliquota: COUNT(*) contava le righe,
     * e una fattura con due aliquote risultava due fatture. Un documento e'
     * registro + data + controparte + numero (e serie). Se la colonna del
     * numero non c'e', il conteggio si basa su registro + data + controparte
     * + causale: due fatture allo stesso cliente nello stesso giorno con la
     * stessa causale contano come una. E' un'approssimazione per difetto, e
     * riguarda solo il conteggio, mai gli importi.
     */
    private function chiaveDocumento(): string
    {
        $parti = ["m.mi_tregiva", "CONVERT(varchar(10), m.mi_datdoc, 23)", "CAST(m.mi_contocf AS varchar(20))"];
        $numero = $this->colonna(['mi_numdoc', 'mi_ndoc', 'mi_numerodoc']);
        if ($numero) {
            $parti[] = "CAST(m.$numero AS varchar(30))";
            if ($serie = $this->colonna(['mi_alfdoc', 'mi_serdoc', 'mi_serie'])) $parti[] = "CAST(m.$serie AS varchar(30))";
        } else {
            $parti[] = "CAST(m.mi_causale AS varchar(20))";
        }
        return 'COUNT(DISTINCT CONCAT(' . implode(", '|', ", $parti) . '))';
    }

    /** La prima colonna esistente fra quelle proposte, o null. */
    private function colonna(array $candidate): ?string
    {
        foreach ($candidate as $c) {
            if (in_array($c, $this->colonne(), true)) return $c;
        }
        return null;
    }

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
                " . $this->chiaveDocumento() . " AS documenti,
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
     * Fatturato e acquisti fra due date, per il confronto fra anni a pari
     * giorno (dal 1 gennaio a oggi, contro lo stesso intervallo dell'anno
     * prima). Stesse regole di monthlyTotals.
     *
     * @return array{vendite:float, acquisti:float}
     */
    public function totaliFra(string $from, string $toExclusive): array
    {
        $stmt = $this->conn->prepare("
            SELECT m.mi_tregiva AS registro, SUM(" . self::IMPONIBILE . ") AS imponibile
            FROM   {$this->tabella} m
            " . self::JOIN_ANAGRA . "
            WHERE  m.codditt    = :ditta
              AND  m.mi_tregiva IN ('V', 'A')
              AND  m.mi_datdoc >= :dal
              AND  m.mi_datdoc <  :al
              AND  " . self::SENZA_AUTOFATTURE . "
            GROUP BY m.mi_tregiva
        ");
        $stmt->execute([':ditta' => $this->codDitta, ':dal' => $from, ':al' => $toExclusive]);
        $fuori = ['vendite' => 0.0, 'acquisti' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[$r['registro'] === self::VENDITE ? 'vendite' : 'acquisti'] = (float)$r['imponibile'];
        }
        return $fuori;
    }

    /**
     * Principali clienti e fornitori per fatturato: uno per riga.
     * (Fatturato, non incassato: i pagamenti stanno nello scadenzario.)
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
                " . $this->chiaveDocumento() . " AS documenti,
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
                " . $this->chiaveDocumento() . " AS documenti,
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

    /**
     * Ogni documento del periodo, uno per riga (il registro IVA ha una riga
     * per aliquota: qui si sommano). Con data, numero, protocollo,
     * controparte, causale, registro e dove viene conteggiato. Serve a
     * confrontare con l'amministrazione documento per documento.
     *
     * @param array{causale?:int, registro?:string, tipo?:string} $filtro
     * @return array<int, array<string, mixed>>
     */
    public function documenti(string $from, string $toExclusive, array $filtro = [], int $limite = 3000): array
    {
        $limite = max(1, min(50001, $limite));
        $numero = $this->colonna(['mi_numdoc', 'mi_ndoc', 'mi_numerodoc']);
        $serie  = $this->colonna(['mi_alfdoc', 'mi_serdoc', 'mi_serie']);
        $proto  = $this->colonna(['mi_numpro', 'mi_protoc', 'mi_nprot', 'mi_numprot', 'mi_protocollo']);
        $dataReg = in_array('mi_datreg', $this->colonne(), true);

        $extra = [];
        if ($numero) $extra[] = "m.$numero AS numero";
        if ($serie)  $extra[] = "m.$serie AS serie";
        if ($proto)  $extra[] = "m.$proto AS protocollo";
        if ($dataReg) $extra[] = 'm.mi_datreg AS data_reg';
        $gruppo = array_merge(['m.mi_datdoc', 'm.mi_causale', 'm.mi_tregiva', 'm.mi_contocf', "ISNULL(a.an_tipo, '')"],
            array_map(fn($e) => explode(' AS ', $e)[0], $extra));

        $dove = ["m.codditt = :ditta", "m.mi_tregiva IN ('V', 'A')", "m.mi_datdoc >= :dal", "m.mi_datdoc < :al"];
        $par  = [':ditta' => $this->codDitta, ':dal' => $from, ':al' => $toExclusive];
        if (isset($filtro['causale'])) { $dove[] = 'm.mi_causale = :causale'; $par[':causale'] = (int)$filtro['causale']; }
        if (isset($filtro['registro']) && in_array($filtro['registro'], ['V', 'A'], true)) { $dove[] = 'm.mi_tregiva = :registro'; $par[':registro'] = $filtro['registro']; }
        if (isset($filtro['tipo'])) { $dove[] = "ISNULL(a.an_tipo, '') = :tipo"; $par[':tipo'] = (string)$filtro['tipo']; }

        $stmt = $this->conn->prepare("
            SELECT TOP {$limite}
                m.mi_datdoc AS data_doc, m.mi_causale AS causale, m.mi_tregiva AS registro, m.mi_contocf AS conto,
                ISNULL(a.an_tipo, '') AS tipo,
                MAX(LTRIM(RTRIM(ISNULL(a.an_descr1, '') + ' ' + ISNULL(a.an_descr2, '')))) AS nome,
                MAX(ISNULL(a.an_pariva, '')) AS piva,
                MAX(c.descrizione) AS descrizione,
                COUNT(*) AS righe,
                SUM(" . self::IMPONIBILE . ") AS imponibile,
                SUM(" . self::IMPOSTA . ") AS iva
                " . ($extra ? ', ' . implode(', ', $extra) : '') . "
            FROM   {$this->tabella} m
            " . self::JOIN_ANAGRA . "
            LEFT JOIN (SELECT tb_codcaus, MAX(tb_descaus) AS descrizione FROM tabcaus GROUP BY tb_codcaus) c
                   ON c.tb_codcaus = m.mi_causale
            WHERE  " . implode(' AND ', $dove) . "
            GROUP BY " . implode(', ', $gruppo) . "
            ORDER BY m.mi_tregiva DESC, m.mi_datdoc" . ($numero ? ", m.$numero" : '') . "
        ");
        $stmt->execute($par);

        return array_map(function (array $r): array {
            $autofattura = $r['registro'] === self::VENDITE && $r['tipo'] === 'F';
            $data = static fn($d) => $d ? substr((string)$d, 0, 10) : null;
            $nome = trim((string)$r['nome']);
            return [
                'data'        => $data($r['data_doc']),
                'data_reg'    => $data($r['data_reg'] ?? null),
                'numero'      => isset($r['numero']) ? trim((string)$r['numero']) : null,
                'serie'       => isset($r['serie']) ? trim((string)$r['serie']) : null,
                'protocollo'  => isset($r['protocollo']) ? trim((string)$r['protocollo']) : null,
                'causale'     => (int)$r['causale'],
                'descrizione' => $r['descrizione'] !== null ? trim((string)$r['descrizione']) : null,
                'registro'    => (string)$r['registro'],
                'tipo'        => (string)$r['tipo'],
                'controparte' => $nome !== '' ? $nome : 'Conto ' . (int)$r['conto'],
                'piva'        => trim((string)$r['piva']),
                'righe'       => (int)$r['righe'],
                'imponibile'  => round((float)$r['imponibile'], 2),
                'iva'         => round((float)$r['iva'], 2),
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
