<?php

declare(strict_types=1);

namespace App\Service\Poti;

use PDO;

/**
 * Registro delle modifiche dei moduli Poti.
 *
 * Salva lo stato prima e dopo di ogni operazione: da li' si ricava sia chi
 * ha cambiato cosa, sia il contenuto di una riga eliminata quando la si
 * vuole rimettere indietro.
 *
 * Le scritture sono avvolte in un try: se il registro non e' disponibile
 * (migration non ancora applicata, tabella piena) il lavoro dell'utente non
 * deve fallire per questo. Un registro incompleto e' un problema minore di
 * una prenotazione che non si salva.
 */
final class Audit
{
    /** Campi che non vale la pena mostrare nel confronto. */
    private const IGNORATI = ['created_at', 'created_by', 'group_company_id', 'id',
                              'origine_id', 'eliminato_at', 'eliminato_da'];

    /** Etichette leggibili al posto dei nomi delle colonne. */
    private const NOMI = [
        'targa'            => 'Targa',
        'matricola'        => 'Matricola',
        'tipo'             => 'Tipo',
        'modello'          => 'Modello',
        'altezza_max_m'    => 'Altezza max',
        'portata_kg'       => 'Portata',
        'stato'            => 'Stato',
        'note'             => 'Note',
        'cliente'          => 'Cliente',
        'telefono'         => 'Telefono',
        'luogo'            => 'Luogo',
        'contratto'        => 'Contratto',
        'data_inizio'      => 'Dal',
        'data_fine'        => 'Al',
        'tariffa_giorno'   => 'Tariffa al giorno',
        'totale'           => 'Totale',
        'trasporto'        => 'Trasporto',
        'pagamento'        => 'Pagamento',
        'commerciale_testo'=> 'Commerciale',
        'righe'            => 'Macchine',
    ];

    public function __construct(private PDO $conn) {}

    /**
     * Annota un'operazione.
     *
     * @param array|null $prima stato precedente, null se la riga non c'era
     * @param array|null $dopo  stato successivo, null se e' stata eliminata
     */
    public function registra(
        int $companyId,
        string $entita,
        int $entitaId,
        string $azione,
        ?array $prima,
        ?array $dopo,
        ?int $userId,
        string $etichetta = ''
    ): void {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO pn_audit
                    (group_company_id, entita, entita_id, azione, etichetta,
                     user_id, user_nome, dati_prima, dati_dopo)
                VALUES (:cid, :ent, :eid, :az, :etic, :uid, :unome, :prima, :dopo)
            ");
            $stmt->execute([
                ':cid'   => $companyId,
                ':ent'   => $entita,
                ':eid'   => $entitaId,
                ':az'    => $azione,
                ':etic'  => $etichetta !== '' ? mb_substr($etichetta, 0, 200) : null,
                ':uid'   => $userId,
                // il nome si copia qui: se l'utente viene rinominato o
                // cancellato lo storico deve restare leggibile
                ':unome' => $this->nomeUtente($userId),
                ':prima' => $prima !== null ? json_encode($prima, JSON_UNESCAPED_UNICODE) : null,
                ':dopo'  => $dopo  !== null ? json_encode($dopo,  JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Audit Poti] ' . $e->getMessage());
        }
    }

    /**
     * Voci del registro, dalla piu' recente.
     *
     * @param string[] $entita quali entita' mostrare (le due sezioni hanno le proprie)
     */
    public function voci(int $companyId, array $entita, array $filtri = []): array
    {
        if (!$entita) {
            return [];
        }

        $segnaposti = [];
        $args       = [':cid' => $companyId];
        foreach ($entita as $i => $e) {
            $segnaposti[]    = ':e' . $i;
            $args[':e' . $i] = $e;
        }

        $sql = 'SELECT * FROM pn_audit
                WHERE group_company_id = :cid
                  AND entita IN (' . implode(',', $segnaposti) . ')';

        if (!empty($filtri['azione'])) {
            $sql .= ' AND azione = :az';
            $args[':az'] = $filtri['azione'];
        }
        if (!empty($filtri['utente'])) {
            $sql .= ' AND user_id = :uid';
            $args[':uid'] = (int)$filtri['utente'];
        }
        if (!empty($filtri['dal'])) {
            $sql .= ' AND created_at >= :dal';
            $args[':dal'] = $filtri['dal'] . ' 00:00:00';
        }
        if (!empty($filtri['al'])) {
            $sql .= ' AND created_at <= :al';
            $args[':al'] = $filtri['al'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($args);
            $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[Audit Poti] ' . $e->getMessage());
            return [];
        }

        foreach ($righe as &$r) {
            $prima = $r['dati_prima'] ? json_decode($r['dati_prima'], true) : null;
            $dopo  = $r['dati_dopo']  ? json_decode($r['dati_dopo'],  true) : null;
            $r['cambi'] = self::differenze($prima, $dopo);
        }
        return $righe;
    }

    /** Utenti che hanno operato, per il filtro. */
    public function utenti(int $companyId, array $entita): array
    {
        if (!$entita) {
            return [];
        }
        $segnaposti = [];
        $args       = [':cid' => $companyId];
        foreach ($entita as $i => $e) {
            $segnaposti[]    = ':e' . $i;
            $args[':e' . $i] = $e;
        }

        try {
            $stmt = $this->conn->prepare('
                SELECT DISTINCT user_id, user_nome
                FROM   pn_audit
                WHERE  group_company_id = :cid
                  AND  entita IN (' . implode(',', $segnaposti) . ')
                  AND  user_id IS NOT NULL
                ORDER BY user_nome
            ');
            $stmt->execute($args);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Campi cambiati fra due stati.
     *
     * @return array<int, array{campo:string, prima:string, dopo:string}>
     */
    public static function differenze(?array $prima, ?array $dopo): array
    {
        $chiavi = array_unique(array_merge(
            array_keys($prima ?? []),
            array_keys($dopo ?? [])
        ));

        $out = [];
        foreach ($chiavi as $k) {
            if (in_array($k, self::IGNORATI, true)) {
                continue;
            }
            $a = self::testo($prima[$k] ?? null);
            $b = self::testo($dopo[$k] ?? null);

            if ($a === $b) {
                continue;
            }
            $out[] = [
                'campo' => self::NOMI[$k] ?? $k,
                'prima' => $a,
                'dopo'  => $b,
            ];
        }
        return $out;
    }

    /** Valore leggibile: le righe di un noleggio diventano un elenco corto. */
    private static function testo(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_array($v)) {
            $pezzi = [];
            foreach ($v as $r) {
                if (!is_array($r)) {
                    $pezzi[] = (string)$r;
                    continue;
                }
                $pezzi[] = trim(sprintf(
                    '%s %s→%s',
                    $r['matricola'] ?? ($r['macchina_id'] ?? ''),
                    isset($r['data_inizio']) ? date('d/m/y', strtotime((string)$r['data_inizio'])) : '',
                    isset($r['data_fine']) ? date('d/m/y', strtotime((string)$r['data_fine'])) : ''
                ));
            }
            return $pezzi ? implode(', ', $pezzi) : '—';
        }
        return (string)$v;
    }

    private function nomeUtente(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        try {
            $stmt = $this->conn->prepare("
                SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), ''),
                                username) AS nome
                FROM bb_users WHERE id = :id
            ");
            $stmt->execute([':id' => $userId]);
            return $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
