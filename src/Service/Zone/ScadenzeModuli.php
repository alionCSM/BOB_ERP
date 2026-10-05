<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * I moduli assegnati: chi li ha fatti, chi manca, e il promemoria a chi manca.
 *
 * Un "da compilare" senza seguito vale poco: l'ufficio lo assegna, il primo
 * giorno lo fanno tutti, dal terzo nessuno. Servono due cose:
 *   - all'ufficio, per ogni modulo, chi l'ha fatto e chi no in questo periodo
 *     (oggi, questa settimana, o una volta per tutte);
 *   - a chi manca, un promemoria nel pomeriggio, uno solo con tutti i suoi.
 *
 * Ognuno compila il suo: assegnato "agli operai" vuol dire uno per operaio.
 * Assegnato "all'ufficio" non ha un elenco di persone (l'ufficio e' chi ha il
 * modulo Zone in BOB): conta se qualcuno l'ha fatto, e non si ricorda a nessuno.
 *
 * Il promemoria va solo a chi serve davvero:
 *   - giornaliero: a capi e operai solo se oggi sono in pianificazione su
 *     quel cantiere. Il DPI di un cantiere dove oggi non sei non ti riguarda;
 *   - settimanale: il venerdi', a chi in settimana e' passato da li';
 *   - una volta: il giorno prima della scadenza, il giorno stesso e il giorno
 *     dopo; senza scadenza, il lunedi', finche' non e' fatto.
 * Un giornaliero o settimanale con la scadenza passata e' finito: niente.
 * Il cliente solo dove l'ufficio ha acceso "Condividi col cliente".
 */
final class ScadenzeModuli
{
    public function __construct(private PDO $conn) {}

    /** Da quando conta una compilazione: oggi, da lunedi', o da sempre. */
    public static function inizioPeriodo(string $frequenza, string $oggi): ?string
    {
        return match ($frequenza) {
            'giornaliera' => $oggi,
            'settimanale' => date('Y-m-d', strtotime($oggi . ' -' . ((int)date('N', strtotime($oggi)) - 1) . ' days')),
            default       => null,
        };
    }

    /**
     * Per ogni modulo attivo del cantiere: chi l'ha fatto nel periodo e chi
     * manca. Per la pagina dell'ufficio.
     *
     * @return array<int, array{periodo:?string, fatti:array<int,array{nome:string,quando:string}>, mancano:string[]}>
     */
    public function stato(int $worksiteId, ?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');
        $fuori = [];
        foreach ($this->assegnazioni($worksiteId) as $a) {
            $dal   = self::inizioPeriodo((string)$a['frequenza'], $oggi);
            $fatti = $this->fattiDa((int)$a['id'], $dal);

            $mancano = [];
            foreach ($this->chiDeve($a) as $p) {
                if (!isset($fatti[(int)$p['user_id']])) {
                    $mancano[] = $p['nome'];
                }
            }
            $fuori[(int)$a['id']] = [
                'periodo' => $dal,
                'fatti'   => array_values(array_map(
                    fn(array $f) => ['nome' => $f['nome'], 'quando' => $f['quando']],
                    $fatti
                )),
                'mancano' => $mancano,
            ];
        }
        return $fuori;
    }

    /**
     * Manda il promemoria del pomeriggio: uno per persona, con tutti i suoi
     * moduli che mancano.
     *
     * @return array{avvisati:int, moduli:int}
     */
    public function ricorda(NotificationService $notifiche, ?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');
        $per = [];
        foreach ($this->daRicordare($oggi) as $r) {
            $id = (int)$r['user_id'];
            $per[$id] ??= ['lingua' => $r['lingua'], 'moduli' => []];
            $per[$id]['moduli'][] = $r['modulo'] . ' (' . $r['cantiere'] . ')';
        }

        $moduli = 0;
        foreach ($per as $userId => $p) {
            $quanti = count($p['moduli']);
            $notifiche->create(
                $userId,
                Lingua::testo('zona_promemoria_t', $p['lingua']),
                Lingua::testo(
                    $quanti > 1 ? 'zona_promemoria_piu' : 'zona_promemoria_uno',
                    $p['lingua'],
                    ['elenco' => implode(', ', $p['moduli'])]
                ),
                '/io',
                'zone',
                'normal',
            );
            $moduli += $quanti;
        }
        return ['avvisati' => count($per), 'moduli' => $moduli];
    }

    /**
     * Chi oggi va avvisato e per cosa: una riga per persona e modulo.
     *
     * @return array<int, array{user_id:int, lingua:?string, modulo:string, cantiere:string}>
     */
    public function daRicordare(string $oggi): array
    {
        $ieri   = date('Y-m-d', strtotime($oggi . ' -1 day'));
        $domani = date('Y-m-d', strtotime($oggi . ' +1 day'));
        $dow    = (int)date('N', strtotime($oggi));

        $fuori = [];
        foreach ($this->assegnazioni(null) as $a) {
            $freq     = (string)$a['frequenza'];
            $scadenza = $a['scadenza'] ?: null;

            if ($freq !== 'una_volta' && $scadenza !== null && $scadenza < $oggi) {
                continue; // finito
            }
            if ($freq === 'settimanale' && $dow !== 5) {
                continue;
            }
            if ($freq === 'una_volta') {
                $tocca = $scadenza !== null
                    ? in_array($scadenza, [$ieri, $oggi, $domani], true)
                    : $dow === 1;
                if (!$tocca) {
                    continue;
                }
            }

            $dal   = self::inizioPeriodo($freq, $oggi);
            $fatti = $this->fattiDa((int)$a['id'], $dal);
            $chi   = $this->chiDeve($a);
            if (!$chi) {
                continue;
            }

            // in cantiere oggi (giornaliero) o in settimana (settimanale)
            $presenti = $freq === 'una_volta'
                ? null
                : $this->pianificati((int)$a['worksite_id'], $dal ?? $oggi, $oggi);

            foreach ($chi as $p) {
                if (isset($fatti[(int)$p['user_id']])) continue;
                if ($presenti !== null && $p['ruolo'] !== Accesso::CLIENTE
                    && !isset($presenti[(int)$p['user_id']])) continue;
                $fuori[] = [
                    'user_id'  => (int)$p['user_id'],
                    'lingua'   => $p['lingua'],
                    'modulo'   => (string)$a['modulo'],
                    'cantiere' => (string)$a['cantiere'],
                ];
            }
        }
        return $fuori;
    }

    /** @return array<int, array<string, mixed>> */
    private function assegnazioni(?int $worksiteId): array
    {
        $st = $this->conn->prepare("
            SELECT fa.*, t.name AS modulo, w.name AS cantiere, w.zone_cliente
            FROM   bb_zone_form_assegnazioni fa
            JOIN   bb_zone_form_templates t ON t.id = fa.template_id
            JOIN   bb_worksites w ON w.id = fa.worksite_id
            WHERE  fa.attiva = 1" . ($worksiteId !== null ? ' AND fa.worksite_id = :w' : '') . "
            ORDER BY fa.id
        ");
        $st->execute($worksiteId !== null ? [':w' => $worksiteId] : []);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Le persone che lo devono compilare: quella nominata, o chi ha quel
     * ruolo nel cantiere. Solo chi e' ancora attivo e ancora nel cantiere.
     *
     * @return array<int, array{user_id:int, nome:string, lingua:?string, ruolo:string}>
     */
    private function chiDeve(array $a): array
    {
        $ruolo = $a['a_ruolo'] ?? null;
        if (empty($a['a_user_id']) && ($ruolo === null || $ruolo === 'ufficio')) {
            return [];
        }
        $st = $this->conn->prepare("
            SELECT a.user_id, a.ruolo, u.lingua,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
            FROM   bb_zone_accessi a
            JOIN   bb_users u ON u.id = a.user_id
            WHERE  a.worksite_id = :w AND u.active = 'Y' AND u.removed = 'N'
              AND  " . (!empty($a['a_user_id']) ? 'a.user_id = :x' : 'a.ruolo = :x') . "
            ORDER BY nome
        ");
        $st->execute([':w' => (int)$a['worksite_id'], ':x' => !empty($a['a_user_id']) ? (int)$a['a_user_id'] : $ruolo]);

        return array_values(array_filter(
            $st->fetchAll(PDO::FETCH_ASSOC),
            fn(array $p) => $p['ruolo'] !== Accesso::CLIENTE || !empty($a['zone_cliente'])
        ));
    }

    /**
     * Chi l'ha compilato dal giorno dato (null = da sempre), con l'ultima volta.
     *
     * @return array<int, array{nome:string, quando:string}>
     */
    private function fattiDa(int $assegnazioneId, ?string $dal): array
    {
        $st = $this->conn->prepare("
            SELECT s.submitted_by AS user_id, MAX(s.created_at) AS quando,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
            FROM   bb_zone_form_submissions s
            LEFT JOIN bb_users u ON u.id = s.submitted_by
            WHERE  s.assegnazione_id = :a" . ($dal !== null ? ' AND s.created_at >= :dal' : '') . "
            GROUP BY s.submitted_by, nome
        ");
        $par = [':a' => $assegnazioneId];
        if ($dal !== null) {
            $par[':dal'] = $dal . ' 00:00:00';
        }
        $st->execute($par);

        $fuori = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[(int)$r['user_id']] = ['nome' => (string)$r['nome'], 'quando' => (string)$r['quando']];
        }
        return $fuori;
    }

    /**
     * Gli utenti pianificati sul cantiere fra due date.
     *
     * @return array<int, true> user_id => true
     */
    private function pianificati(int $worksiteId, string $dal, string $al): array
    {
        $st = $this->conn->prepare("
            SELECT DISTINCT u.id
            FROM   bb_pianificazione p
            JOIN   bb_pianificazione_nostri pn ON pn.pianificazione_id = p.id
            JOIN   bb_users u ON u.worker_id = pn.worker_id
            WHERE  p.worksite_id = :w AND p.data BETWEEN :dal AND :al
        ");
        $st->execute([':w' => $worksiteId, ':dal' => $dal, ':al' => $al]);
        return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
