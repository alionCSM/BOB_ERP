<?php
declare(strict_types=1);

namespace App\Repository\Attendance;

use PDO;

/**
 * Ferie e permessi (bb_ferie_permessi).
 * Stesso pattern di AdvanceRepository/FineRepository.
 */
class LeaveRepository
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query("
            SELECT fp.*,
                   CONCAT(w.first_name, ' ', w.last_name) AS operaio_nome,
                   w.company AS operaio_azienda,
                   w.id AS operaio_id,
                   CONCAT(d.first_name, ' ', d.last_name) AS decisa_da_nome
            FROM bb_ferie_permessi fp
            JOIN bb_workers w ON fp.worker_id = w.id
            LEFT JOIN bb_users d ON d.id = fp.decisa_da
            ORDER BY fp.data_inizio DESC, fp.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Le richieste arrivate dall'app e ancora da decidere.
     *
     * Dalla piu' vecchia, al contrario di tutto il resto: una richiesta ferma
     * da una settimana e' quella che scotta, e in fondo a un elenco in ordine
     * di data non la guarda nessuno. Chi ha chiesto il ponte di agosto a
     * marzo puo' aspettare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function daApprovare(): array
    {
        $stmt = $this->conn->query("
            SELECT fp.*,
                   CONCAT(w.first_name, ' ', w.last_name) AS operaio_nome,
                   w.company AS operaio_azienda,
                   w.id AS operaio_id,
                   DATEDIFF(CURDATE(), DATE(fp.created_at)) AS giorni_in_attesa
            FROM bb_ferie_permessi fp
            JOIN bb_workers w ON fp.worker_id = w.id
            WHERE fp.stato = 'in_attesa'
            ORDER BY fp.created_at ASC, fp.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * L'ufficio approva o rifiuta una richiesta.
     *
     * Decide solo quello che e' ancora in attesa: due persone che aprono la
     * stessa pagina e cliccano una per uno non devono ribaltarsi la decisione
     * a vicenda. Chi arriva secondo trova false e se lo vede dire.
     *
     * Approvare non cambia le date: se vanno corrette, si correggono prima
     * col form di modifica. Una approvazione che sposta i giorni di nascosto
     * farebbe tornare l'operaio da ferie il giorno sbagliato.
     */
    public function decidi(int $id, bool $approva, string $motivo, int $decisaDa): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_ferie_permessi
            SET    stato     = :stato,
                   motivo    = :motivo,
                   decisa_at = NOW(),
                   decisa_da = :uid
            WHERE  id = :id AND stato = 'in_attesa'
        ");
        $stmt->execute([
            ':stato'  => $approva ? 'approvata' : 'rifiutata',
            ':motivo' => $approva ? null : ($motivo !== '' ? $motivo : null),
            ':uid'    => $decisaDa ?: null,
            ':id'     => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function getByWorker(int $workerId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_ferie_permessi
            WHERE worker_id = :wid
            ORDER BY data_inizio DESC, id DESC
        ");
        $stmt->execute([':wid' => $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gli anni in cui ha qualcosa, dal piu' recente.
     *
     * Le ferie si contano per anno, non per mese: la domanda e' "quante ne
     * ho prese quest'anno", e nessuno chiede quante ne ha prese a marzo.
     *
     * @return array<int, int>
     */
    public function anniConAssenze(int $workerId): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT YEAR(data_inizio) AS anno
            FROM   bb_ferie_permessi
            WHERE  worker_id = :wid
            ORDER BY anno DESC
        ");
        $stmt->execute([':wid' => $workerId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Le sue assenze di un anno.
     *
     * Chi inizia a dicembre e finisce a gennaio compare in tutti e due gli
     * anni: tagliare a meta' una ferie di Natale per farla stare in un anno
     * solo vorrebbe dire mostrarne meta' e nascondere l'altra.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perWorkerEAnno(int $workerId, int $anno): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_ferie_permessi
            WHERE worker_id = :wid
              AND (YEAR(data_inizio) = :a1 OR YEAR(data_fine) = :a2)
            ORDER BY data_inizio DESC, id DESC
        ");
        // tre segnaposto distinti: le prepared non sono emulate
        $stmt->execute([':wid' => $workerId, ':a1' => $anno, ':a2' => $anno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(int $workerId, string $tipo, string $from, string $to, ?float $ore, string $note, int $createdBy, string $protocollo = ''): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ferie_permessi
                (worker_id, tipo, data_inizio, data_fine, ore, note, created_by,
                 protocollo, stato, decisa_at, decisa_da)
            VALUES (:wid, :tipo, :dal, :al, :ore, :note, :uid,
                    :prot, 'approvata', NOW(), :uid2)
        ");
        $stmt->execute([
            ':wid'  => $workerId,
            ':tipo' => $tipo,
            ':dal'  => $from,
            ':al'   => $to,
            ':ore'  => $ore,
            ':note' => $note !== '' ? $note : null,
            // il numero del certificato vale solo sulla malattia: lasciarlo
            // su una ferie sarebbe un dato che non vuol dire niente
            ':prot' => ($tipo === 'malattia' && $protocollo !== '') ? $protocollo : null,
            // l'assenza messa dall'ufficio nasce gia' approvata: l'ha decisa
            // chi la sta scrivendo, e farla passare da "in attesa" vorrebbe
            // dire chiedergli di approvare se stesso
            ':uid'  => $createdBy ?: null,
            ':uid2' => $createdBy ?: null,
        ]);
    }

    public function update(int $id, int $workerId, string $tipo, string $from, string $to, ?float $ore, string $note, string $protocollo = ''): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_ferie_permessi
            SET worker_id = :wid, tipo = :tipo, data_inizio = :dal,
                data_fine = :al, ore = :ore, note = :note, protocollo = :prot
            WHERE id = :id
        ");
        $stmt->execute([
            ':wid'  => $workerId,
            ':tipo' => $tipo,
            ':dal'  => $from,
            ':al'   => $to,
            ':ore'  => $ore,
            ':note' => $note !== '' ? $note : null,
            ':prot' => ($tipo === 'malattia' && $protocollo !== '') ? $protocollo : null,
            ':id'   => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->conn->prepare("DELETE FROM bb_ferie_permessi WHERE id = :id")
                   ->execute([':id' => $id]);
    }
}
