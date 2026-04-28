<?php

namespace App\Service;
use PDO;
use App\Service\Mailer;

class YardWorksiteStatusService
{
    private PDO $mysql;
    private PDO $sqlsrv;

    public function __construct(PDO $mysql, PDO $sqlsrv)
    {
        $this->mysql  = $mysql;
        $this->sqlsrv = $sqlsrv;
    }

    public function run(): void
    {
        $worksites = $this->getOpenLinkedWorksites();

        $closed = [];
        foreach ($worksites as $w) {
            $yardEndDate = $this->getYardEndDate($w['yard_worksite_id']);

            if ($yardEndDate === null) {
                continue;
            }

            $closed[] = [
                'worksite_code' => $w['worksite_code'],
                'name'          => $w['name'],
                'client_name'   => $w['client_name'],
                'yard_end_date' => $yardEndDate,
            ];
        }

        if (!empty($closed)) {
            $this->sendClosedListEmail($closed);
        }
    }

    private function getOpenLinkedWorksites(): array
    {
        $stmt = $this->mysql->prepare("
            SELECT w.id, w.yard_worksite_id, w.worksite_code, w.name,
                   c.name AS client_name
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            WHERE w.yard_worksite_id IS NOT NULL
              AND w.yard_closed_at IS NULL
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getYardEndDate(int $yardId): ?string
    {
        $stmt = $this->sqlsrv->prepare("
            SELECT data_fine
            FROM dbo.CNT_cantieri
            WHERE id = :id
        ");
        $stmt->execute([':id' => $yardId]);

        $date = $stmt->fetchColumn();
        return $date ?: null;
    }

    private function sendClosedListEmail(array $closed): void
    {
        $mailer = new Mailer();
        $mailer->setSender('billing');

        $mail = $mailer->getMailer();
        $mail->addAddress('alion@csmontaggi.it');

        $mail->Subject = 'Cantieri chiusi in Yard (non ancora in BOB) – ' . count($closed) . ' trovato/i';

        $rows = '';
        foreach ($closed as $c) {
            $rows .= "
            <tr>
                <td>{$c['worksite_code']}</td>
                <td>{$c['name']}</td>
                <td>{$c['client_name']}</td>
                <td>{$c['yard_end_date']}</td>
            </tr>";
        }

        $mail->Body = "
        <h3>Cantieri chiusi in Yard</h3>
        <p>Dei cantieri collegati a Yard risultano chiusi ma ancora aperti in BOB.</p>
        <table cellpadding='6' cellspacing='0' border='1'>
            <thead>
                <tr>
                    <th>Codice</th><th>Nome</th><th>Cliente</th><th>Data fine (Yard)</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
    ";

        $mail->send();
    }


}
