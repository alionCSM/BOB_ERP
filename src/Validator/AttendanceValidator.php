<?php
declare(strict_types=1);

namespace App\Validator;
use Exception;
use App\Repository\AttendanceRepository;

final class AttendanceValidator
{
    private AttendanceRepository $repo;

    public function __construct(AttendanceRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Validates conflict rules for internal workers.
     * Throws Exception 422 if conflicts found.
     */
    public function validateInternalConflicts(array $data, array $period): void
    {
        $workerIds   = $data['worker_id'] ?? [];
        $turni       = $data['turno'] ?? [];
        $existingIds = $data['existing_id'] ?? [];

        $errors = [];

        foreach ($workerIds as $idx => $wid) {
            $wid = (int)$wid;
            if ($wid <= 0) continue;

            $turnoInput = $turni[$idx] ?? 'Intero';
            $turnoInput = in_array($turnoInput, ['Intero','Mezzo'], true)
                ? $turnoInput
                : 'Intero';

            foreach ($period as $day) {

                $dayStr = $day->format('Y-m-d');
                $existing = $this->repo
                    ->getExistingPresencesByWorkerAndDate($wid, $dayStr);

                $mezzi = 0;
                $interi = 0;
                $cantieri = [];

                foreach ($existing as $row) {

                    if (!empty($existingIds[$idx]) &&
                        (int)$row['id'] === (int)$existingIds[$idx]) {
                        continue;
                    }

                    if (($row['turno'] ?? '') === 'Mezzo') $mezzi++;
                    else $interi++;

                    $cantieri[] = $this->repo
                        ->getWorksiteLabel((int)$row['worksite_id']);
                }

                if ($turnoInput === 'Mezzo')  $mezzi++;
                if ($turnoInput === 'Intero') $interi++;

                if ($interi > 1 || $mezzi > 2 || ($mezzi && $interi)) {
                    $errors[] = [
                        'index' => $idx,
                        'msg'   => "Conflitto con cantiere: "
                            . implode(', ', array_unique($cantieri))
                    ];
                    break;
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(json_encode($errors), 422);
        }
    }
}