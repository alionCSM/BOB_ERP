<?php

declare(strict_types=1);

namespace App\Service\Operaio;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Gli avvisi all'operaio che non nascono da una sua richiesta: il programma
 * di domani, un documento nuovo. Arrivano nella sua lingua, dentro BOB e
 * come push sul telefono.
 *
 * Come per gli esiti delle richieste (AvvisoDecisione), l'avviso parte
 * dopo che il dato e' salvato, e un guasto qui non deve mai far fallire il
 * salvataggio: chi chiama lo avvolge in un try.
 */
final class AvvisiOperaio
{
    public function __construct(
        private PDO $conn,
        private NotificationService $notifiche,
    ) {}

    // ── Pianificazione ───────────────────────────────────────────────────────

    /**
     * Manda il programma di un giorno a chi e' in squadra.
     *
     * Solo a chi ha la giornata nuova o cambiata dall'ultimo invio: chi
     * ha gia' ricevuto lo stesso messaggio non lo riceve di nuovo. A chi era
     * stato avvisato e adesso non e' piu' in nessuna squadra si dice anche
     * quello, se no si presenta in un cantiere dove non lo aspetta nessuno.
     *
     * @return array{avvisati:int, invariati:int, senza_account:int}
     */
    public function pianificazione(string $data, int $inviataDa): array
    {
        $giornate = $this->giornateDelGiorno($data);
        $giaInviate = $this->firmeInviate($data);

        $avvisati = 0;
        $invariati = 0;
        $senzaAccount = 0;

        foreach ($giornate as $workerId => $g) {
            $firma = sha1(json_encode($g['firma'], JSON_UNESCAPED_UNICODE));
            if (($giaInviate[$workerId] ?? null) === $firma) {
                $invariati++;
                continue;
            }

            // Senza account nell'app non c'e' nessuno da avvisare. Si segna
            // comunque come inviata: se no resterebbe per sempre "da
            // avvisare" e il pulsante non si spegnerebbe mai.
            $utenti = $this->utentiDi($workerId);
            if (!$utenti) {
                $senzaAccount++;
                $this->segnaInviata($data, $workerId, $firma, $inviataDa);
                continue;
            }
            foreach ($utenti as $u) {
                $lingua = $u['lingua'];
                $this->notifiche->create(
                    (int)$u['user_id'],
                    Lingua::testo('avviso_pian_titolo', $lingua, ['data' => $this->giorno($data)]),
                    $this->testoGiornata($g, $lingua),
                    '/io',
                    'pianificazione',
                    'normal',
                    $inviataDa ?: null,
                );
            }
            $this->segnaInviata($data, $workerId, $firma, $inviataDa);
            $avvisati++;
        }

        // avvisati prima, e adesso fuori da tutte le squadre di quel giorno
        foreach (array_diff_key($giaInviate, $giornate) as $workerId => $_) {
            $utenti = $this->utentiDi((int)$workerId);
            foreach ($utenti as $u) {
                $this->notifiche->create(
                    (int)$u['user_id'],
                    Lingua::testo('avviso_pian_tolto_t', $u['lingua']),
                    Lingua::testo('avviso_pian_tolto', $u['lingua'], ['data' => $this->giorno($data)]),
                    '/io',
                    'pianificazione',
                    'high',
                    $inviataDa ?: null,
                );
            }
            $this->conn->prepare('DELETE FROM bb_pianificazione_invii WHERE data = :d AND worker_id = :w')
                ->execute([':d' => $data, ':w' => $workerId]);
            if ($utenti) {
                $avvisati++;
            }
        }

        return ['avvisati' => $avvisati, 'invariati' => $invariati, 'senza_account' => $senzaAccount];
    }

    /**
     * Quanti operai riceverebbero un avviso se si premesse adesso "Invia":
     * la pagina lo scrive sul pulsante, cosi' l'ufficio sa se c'e' ancora
     * qualcosa da mandare.
     *
     * @return array{da_avvisare:int, ultimo_invio:?string}
     */
    public function statoInvio(string $data): array
    {
        $giornate = $this->giornateDelGiorno($data);
        $giaInviate = $this->firmeInviate($data);

        $da = 0;
        foreach ($giornate as $workerId => $g) {
            if (($giaInviate[$workerId] ?? null) !== sha1(json_encode($g['firma'], JSON_UNESCAPED_UNICODE))) {
                $da++;
            }
        }
        $da += count(array_diff_key($giaInviate, $giornate));

        $stmt = $this->conn->prepare('SELECT MAX(inviata_at) FROM bb_pianificazione_invii WHERE data = :d');
        $stmt->execute([':d' => $data]);
        $ultimo = $stmt->fetchColumn();

        return ['da_avvisare' => $da, 'ultimo_invio' => $ultimo ?: null];
    }

    /**
     * Per ogni operaio pianificato quel giorno, quello che deve sapere.
     *
     * @return array<int, array{cantiere:string, luogo:string, capo:?string, sei_capo:bool,
     *                          trasferta:bool, rientro:?string, firma:array<string,mixed>}>
     */
    private function giornateDelGiorno(string $data): array
    {
        $stmt = $this->conn->prepare("
            SELECT pn.worker_id, pn.capo_squadra, pn.trasferta, pn.auto_targa,
                   p.id AS pid, p.cantiere, p.rientro_previsto,
                   w.name AS cantiere_nome, w.location
            FROM   bb_pianificazione_nostri pn
            JOIN   bb_pianificazione p ON p.id = pn.pianificazione_id
            LEFT JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE  p.data = :d AND pn.worker_id IS NOT NULL
            ORDER BY p.sort_order, p.id
        ");
        $stmt->execute([':d' => $data]);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // il capo di ogni cantiere, e chi c'e' in squadra (per la firma)
        $capi = [];
        $squadre = [];
        foreach ($righe as $r) {
            $squadre[(int)$r['pid']][] = (int)$r['worker_id'];
        }
        $nomi = $this->nomiOperai(array_merge([], ...array_values($squadre)));
        foreach ($righe as $r) {
            if ((int)$r['capo_squadra'] === 1) {
                $capi[(int)$r['pid']] = $nomi[(int)$r['worker_id']] ?? null;
            }
        }

        $out = [];
        foreach ($righe as $r) {
            $wid = (int)$r['worker_id'];
            if (isset($out[$wid])) {
                continue; // due cantieri lo stesso giorno: vale il primo
            }
            $pid = (int)$r['pid'];
            $cantiere = trim((string)($r['cantiere_nome'] ?: $r['cantiere']));
            $trasferta = (bool)$r['trasferta'];
            $rientro = $trasferta ? ($r['rientro_previsto'] ?: null) : null;
            $squadra = $squadre[$pid];
            sort($squadra);

            $out[$wid] = [
                'cantiere'  => $cantiere,
                'luogo'     => trim((string)($r['location'] ?? '')),
                'capo'      => $capi[$pid] ?? null,
                'sei_capo'  => (int)$r['capo_squadra'] === 1,
                'trasferta' => $trasferta,
                'rientro'   => $rientro,
                'firma'     => [
                    'cantiere'  => $cantiere,
                    'squadra'   => $squadra,
                    'capo'      => $capi[$pid] ?? null,
                    'auto'      => trim((string)$r['auto_targa']),
                    'trasferta' => $trasferta,
                    'rientro'   => $rientro,
                ],
            ];
        }
        return $out;
    }

    /** "MADE ITALIA SPA, Moncalieri. Capo squadra: Alliu Igli. Trasferta, rientro previsto il 17/10/2026." */
    private function testoGiornata(array $g, ?string $lingua): string
    {
        $parti = [$g['cantiere'] . ($g['luogo'] !== '' ? ', ' . $g['luogo'] : '') . '.'];

        if ($g['sei_capo']) {
            $parti[] = Lingua::testo('avviso_pian_sei_capo', $lingua);
        } elseif ($g['capo']) {
            $parti[] = Lingua::testo('avviso_pian_capo', $lingua, ['nome' => $g['capo']]);
        }

        if ($g['trasferta']) {
            $parti[] = $g['rientro']
                ? Lingua::testo('avviso_pian_rientro', $lingua, ['data' => $this->giorno((string)$g['rientro'])])
                : Lingua::testo('avviso_pian_trasf', $lingua);
        }

        return implode(' ', $parti);
    }

    /** @return array<int, string> worker_id => firma */
    private function firmeInviate(string $data): array
    {
        $stmt = $this->conn->prepare('SELECT worker_id, firma FROM bb_pianificazione_invii WHERE data = :d');
        $stmt->execute([':d' => $data]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['worker_id']] = (string)$r['firma'];
        }
        return $out;
    }

    private function segnaInviata(string $data, int $workerId, string $firma, int $da): void
    {
        $this->conn->prepare("
            INSERT INTO bb_pianificazione_invii (data, worker_id, firma, inviata_da, inviata_at)
            VALUES (:d, :w, :f, :u, NOW())
            ON DUPLICATE KEY UPDATE firma = VALUES(firma), inviata_da = VALUES(inviata_da), inviata_at = NOW()
        ")->execute([':d' => $data, ':w' => $workerId, ':f' => $firma, ':u' => $da ?: null]);
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    private function nomiOperai(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("SELECT id, CONCAT(last_name, ' ', first_name) FROM bb_workers WHERE id IN ($in)");
        $stmt->execute($ids);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // ── Documenti ────────────────────────────────────────────────────────────

    /** Un documento suo e' stato caricato o sostituito. */
    public function documento(int $workerId, string $tipo, bool $nuovo): void
    {
        foreach ($this->utentiDi($workerId) as $u) {
            $this->notifiche->create(
                (int)$u['user_id'],
                Lingua::testo($nuovo ? 'avviso_doc_nuovo_t' : 'avviso_doc_agg_t', $u['lingua']),
                Lingua::testo($nuovo ? 'avviso_doc_nuovo' : 'avviso_doc_agg', $u['lingua'], ['tipo' => $tipo]),
                '/io',
                'documenti',
                'normal',
            );
        }
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /** @return array<int, array{user_id:int, lingua:?string}> */
    private function utentiDi(int $workerId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id AS user_id, lingua
            FROM   bb_users
            WHERE  worker_id = :w AND active = 'Y' AND removed = 'N'
        ");
        $stmt->execute([':w' => $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function giorno(string $data): string
    {
        $t = strtotime($data);
        return $t ? date('d/m/Y', $t) : $data;
    }
}
