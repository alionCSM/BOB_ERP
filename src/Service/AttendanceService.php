<?php
declare(strict_types=1);

namespace App\Service;
use DateInterval;
use DateTime;
use Exception;
use PDO;
use App\Repository\AttendanceRepository;
use App\Validator\AttendanceValidator;

final class AttendanceService
{
    private AttendanceRepository $repo;
    private PDO $conn;

    private AttendanceValidator $validator;

    public function __construct(
        AttendanceRepository $repo,
        AttendanceValidator $validator
    ) {
        $this->repo = $repo;
        $this->validator = $validator;
        $this->conn = $repo->getConnection();
    }

    public function saveBulk(array $data, int $userId, bool $overwrite = false): void
    {
        $worksiteId = isset($data['cantiere']) ? (int)$data['cantiere'] : 0;
        $startDate  = $data['start_date'] ?? null;
        $endDate    = $data['end_date']   ?? null;

        if ($worksiteId <= 0 || empty($startDate)) {
            throw new Exception("Cantiere o data mancante.");
        }

        $this->conn->beginTransaction();

        try {
            $period = $this->buildPeriod($startDate, $endDate);

            // 1) Conflitti (solo per "nostri" e solo se non overwrite)
            if (!$overwrite && (!empty($data['worker_id']) || !empty($data['deleted_nostri']))) {
                $this->validator->validateInternalConflicts($data, $period);            }

            // 2) Salvataggio "nostri"
            if (!empty($data['worker_id']) || !empty($data['deleted_nostri'])) {
                $this->saveInternalWorkers($data, $worksiteId, $period, $userId, $overwrite);
            }

            // 3) Salvataggio "consorziate"
            if (array_key_exists('consorziate', $data) || !empty($data['deleted_consorziate'])) {
                $this->saveConsorziate($data, $worksiteId, $period, $userId);
            }

            $this->conn->commit();

        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /* ============================================================
       Period
       ============================================================ */

    private function buildPeriod(string $startDate, ?string $endDate): array
    {
        $start = new DateTime($startDate);

        if (empty($endDate) || $endDate === $startDate) {
            return [$start];
        }

        $end = new DateTime($endDate);

        $period = new \DatePeriod(
            $start,
            new \DateInterval('P1D'),
            $end->modify('+1 day')
        );

        $days = [];
        foreach ($period as $d) {
            $days[] = $d;
        }
        return $days;
    }

    /* ============================================================
       SALVATAGGIO NOSTRI
       ============================================================ */

    private function saveInternalWorkers(
        array $data,
        int $worksiteId,
        array $period,
        int $userId,
        bool $overwrite
    ): void {
        $workerIds     = $data['worker_id'] ?? [];
        $turni         = $data['turno'] ?? [];
        $pranzi        = $data['pranzo'] ?? [];
        $cene          = $data['cena'] ?? [];
        $pranzoPrezzi  = $data['pranzo_prezzo'] ?? [];
        $cenaPrezzi    = $data['cena_prezzo'] ?? [];
        $hotel         = $data['hotel'] ?? [];
        $auto          = $data['auto'] ?? [];
        $note          = $data['note'] ?? [];
        $existingIds   = $data['existing_id'] ?? [];

        // elimina righe rimosse dall’utente
        $deleted = $data['deleted_nostri'] ?? [];
        if (!empty($deleted)) {
            $this->repo->deleteByIds($deleted);
        }

        // cache per ridurre query su workers
        $workerCompanyCache = [];
        foreach (array_unique(array_map('intval', $workerIds)) as $wid) {
            if ($wid <= 0) continue;

            $info = $this->repo->getWorkerInfo($wid);
            if (!$info) continue;

            $workerCompanyCache[$wid] = [
                'fiscal_code'   => $info['fiscal_code'] ?? null,
                'azienda_nome'  => $info['company'] ?? null,
                'active_from'   => !empty($info['active_from']) ? new DateTime((string)$info['active_from']) : null,
            ];
        }

        foreach ($workerIds as $idx => $widRaw) {
            $wid = (int)$widRaw;
            if ($wid <= 0) continue;

            $turnoInput = $turni[$idx] ?? 'Intero';
            $turnoInput = in_array($turnoInput, ['Intero','Mezzo'], true) ? $turnoInput : 'Intero';

            foreach ($period as $day) {
                $dayStr = $day->format('Y-m-d');

                if ($overwrite) {
                    // commento: overwrite = resetto eventuali presenze dello stesso operaio nello stesso giorno
                    $this->repo->deleteByWorkerAndDate($wid, $dayStr);
                }

                $aziendaNome = $this->resolveWorkerCompanyName($wid, $day, $workerCompanyCache);

                $params = [
                    ':turno'         => $turnoInput,
                    ':pranzo'        => $pranzi[$idx] ?? '',
                    ':pranzo_prezzo' => !empty($pranzoPrezzi[$idx]) ? (float)$pranzoPrezzi[$idx] : null,
                    ':cena'          => $cene[$idx] ?? '',
                    ':cena_prezzo'   => !empty($cenaPrezzi[$idx]) ? (float)$cenaPrezzi[$idx] : null,
                    ':hotel'         => $hotel[$idx] ?? '',
                    ':auto'          => $auto[$idx] ?? '',
                    ':note'          => $note[$idx] ?? '',
                    ':uid'           => $userId,
                ];

                if (!empty($existingIds[$idx])) {
                    // update
                    $this->repo->updatePresenza((int)$existingIds[$idx], $params);
                } else {
                    // insert
                    $params[':wid']     = $wid;
                    $params[':wsid']    = $worksiteId;
                    $params[':day']     = $dayStr;
                    $params[':azienda'] = $aziendaNome;

                    $this->repo->insertPresenza($params);
                }
            }
        }
    }

    private function resolveWorkerCompanyName(int $workerId, DateTime $day, array $cache): string
    {
        $info = $cache[$workerId] ?? null;
        $aziendaNome = null;

        if ($info) {
            $activeFrom = $info['active_from'] ?? null;

            if ($activeFrom instanceof DateTime && $day >= $activeFrom) {
                $aziendaNome = $info['azienda_nome'] ?? null;
            } else {
                $fiscal = $info['fiscal_code'] ?? null;
                if (!empty($fiscal)) {
                    $aziendaNome = $this->repo->getWorkerCompanyFromHistory((string)$fiscal, $day->format('Y-m-d'));
                }
            }
        }

        if (empty($aziendaNome)) {
            $workerName = $this->repo->getWorkerFullName($workerId);
            throw new Exception(
                "Errore: impossibile trovare l'azienda per l'operaio {$workerName} in data " . $day->format('Y-m-d')
            );
        }

        return (string)$aziendaNome;
    }

    /* ============================================================
       SALVATAGGIO CONSORZIATE
       ============================================================ */

    private function saveConsorziate(
        array $data,
        int $worksiteId,
        array $period,
        int $userId
    ): void {
        // elimina righe rimosse dall’utente
        $deletedCons = $data['deleted_consorziate'] ?? [];
        if (!empty($deletedCons)) {
            $this->repo->deleteConsorziateByIdsForWorksite($deletedCons, $worksiteId);
        }

        $consorziateData = $data['consorziate'] ?? [];
        $existingConsRaw = $data['existing_cons_id'] ?? [];
        $mode            = $data['consorziate_mode'] ?? 'insert'; // insert | edit

        $days = array_map(fn(DateTime $d) => $d->format('Y-m-d'), $period);

        // insert mode: se non arriva nulla → wipe day(s)
        if ($mode === 'insert' && empty($consorziateData)) {
            foreach ($days as $day) {
                $this->repo->deleteConsorziateByWorksiteAndDay($worksiteId, $day);
            }
            return;
        }

        foreach ($consorziateData as $idx => $row) {
            $nomeOrId = trim((string)($row['nome'] ?? ''));
            if ($nomeOrId === '') continue;

            $aziendaId = $this->repo->resolveCompanyId($nomeOrId);
            $existingId = $existingConsRaw[$idx] ?? null;

            foreach ($days as $day) {
                if (!empty($existingId)) {
                    $this->repo->updateConsorziata(
                        (int)$existingId,
                        $worksiteId,
                        $day,
                        [
                            'quantita'    => (float)($row['numero'] ?? 0),
                            'costo'       => (float)($row['costo'] ?? 0),
                            'pasti'       => (int)($row['pasti'] ?? 0),
                            'auto'        => (string)($row['auto'] ?? ''),
                            'hotel'       => (string)($row['hotel'] ?? ''),
                            'note'        => (string)($row['note'] ?? ''),
                            'updated_by'  => $userId,
                        ]
                    );
                } else {
                    $this->repo->insertConsorziata(
                        $worksiteId,
                        $day,
                        [
                            'azienda_id'  => $aziendaId,
                            'quantita'    => (float)($row['numero'] ?? 0),
                            'costo'       => (float)($row['costo'] ?? 0),
                            'pasti'       => (int)($row['pasti'] ?? 0),
                            'auto'        => (string)($row['auto'] ?? ''),
                            'hotel'       => (string)($row['hotel'] ?? ''),
                            'note'        => (string)($row['note'] ?? ''),
                            'created_by'  => $userId,
                            'updated_by'  => $userId,
                        ]
                    );
                }
            }
        }
    }
}