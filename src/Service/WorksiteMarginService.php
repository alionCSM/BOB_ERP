<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\WorksiteStats;
use PDO;

/**
 * Recalculates BOB + Yard margins for all active worksites and persists
 * results to bb_worksite_financial_status.
 * Sends a risk-alert email when worksites with negative or low margin exist.
 */
final class WorksiteMarginService
{
    public function __construct(
        private readonly PDO     $conn,
        private readonly Mailer  $mailer,
        private readonly string  $appUrl,
    ) {}

    public function run(): void
    {
        $ids = $this->getActiveWorksiteIds();

        $riskNegative  = [];
        $riskLowMargin = [];

        foreach ($ids as $id) {
            echo "Recalculating worksite #{$id}\n";

            $margin = $this->calculateMargin($id);
            $this->upsertFinancialStatus($id, $margin);

            $info = $this->getWorksiteInfo($id);
            if (!$info || (float) $info['total_offer'] <= 0) {
                continue;
            }

            $totalOffer  = (float) $info['total_offer'];
            $percentuale = round(($margin / $totalOffer) * 100, 2);

            $row = [
                'id'       => $id,
                'worksite' => htmlspecialchars((string) $info['worksite_name']),
                'client'   => htmlspecialchars((string) $info['client_name']),
                'contract' => number_format($totalOffer, 2, ',', '.'),
                'margin'   => number_format($margin, 2, ',', '.'),
                'perc'     => $percentuale,
            ];

            if ($margin < 0) {
                $riskNegative[] = $row;
            } elseif ($percentuale < 10) {
                $riskLowMargin[] = $row;
            }
        }

        echo 'Done. Negative: ' . count($riskNegative) . ', Low margin: ' . count($riskLowMargin) . "\n";

        if ($riskNegative || $riskLowMargin) {
            $this->sendRiskEmail($riskNegative, $riskLowMargin);
        } else {
            echo "No risky worksites. No email sent.\n";
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function getActiveWorksiteIds(): array
    {
        $stmt = $this->conn->query(
            "SELECT id FROM bb_worksites WHERE status = 'In corso' AND is_draft = 0"
        );
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function calculateMargin(int $id): float
    {
        $stats  = new WorksiteStats($this->conn, $id);
        $margin = (float) ($stats->getSummary()['andamento'] ?? 0);

        // Subtract Yard costs if available
        $stmt = $this->conn->prepare("
            SELECT totale_complessivo
            FROM bb_cantiere_stats_2025
            WHERE cantiere_id_sqlsrv = (
                SELECT yard_worksite_id FROM bb_worksites WHERE id = :id LIMIT 1
            )
        ");
        $stmt->execute([':id' => $id]);
        $yard = $stmt->fetchColumn();

        if ($yard !== false) {
            $margin -= (float) $yard;
        }

        return $margin;
    }

    private function upsertFinancialStatus(int $id, float $margin): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_financial_status (worksite_id, margin, last_calculated_at)
            VALUES (:id, :margin, NOW())
            ON DUPLICATE KEY UPDATE margin = VALUES(margin), last_calculated_at = NOW()
        ");
        $stmt->execute([':id' => $id, ':margin' => $margin]);
    }

    /** @return array<string,mixed>|null */
    private function getWorksiteInfo(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT w.name AS worksite_name, c.name AS client_name, w.total_offer
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            WHERE w.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function sendRiskEmail(array $riskNegative, array $riskLowMargin): void
    {
        $baseUrl = rtrim($this->appUrl, '/') . '/worksites/';

        $countNeg  = count($riskNegative);
        $countLow  = count($riskLowMargin);
        $totalRisk = $countNeg + $countLow;
        $isProd    = (new \App\Infrastructure\Config())->isProduction();

        $body = '
<html><body style="margin:0;padding:20px;background:#f6f7f9;font-family:Arial,sans-serif;">
<div style="max-width:950px;margin:auto;background:#fff;padding:24px;border-radius:8px;">
  <p style="font-size:15px;color:#1e293b;margin:0 0 8px;">Buongiorno,</p>
  <p style="color:#475569;margin:0 0 18px;">questi sono i miei appunti di oggi sui cantieri "In corso" (' . $totalRisk . ' in tutto):</p>

  ' . ($countNeg > 0 ? '
  <h3 style="color:#b45309;margin:24px 0 8px;">Margine negativo &mdash; ' . $countNeg . '</h3>
  ' . $this->buildTable($riskNegative, '#b45309', $baseUrl) : '') . '

  ' . ($countLow > 0 ? '
  <h3 style="color:#d97706;margin:32px 0 8px;">Margine sotto il 10% &mdash; ' . $countLow . '</h3>
  ' . $this->buildTable($riskLowMargin, '#d97706', $baseUrl) : '') . '

  <p style="margin-top:32px;color:#94a3b8;font-size:12px;">&mdash; BOB</p>
</div></body></html>';

        // Recipient depends on environment: shared ops mailbox in prod,
        // personal mailbox on dev/staging so test runs don't spam the team.
        $recipient = $isProd ? 'info@csmontaggi.it' : 'alion@csmontaggi.it';

        // Subject neutro
        $word    = $totalRisk === 1 ? 'cantiere' : 'cantieri';
        $prefix  = $isProd ? 'BOB' : 'BOB DEV';
        $subject = "{$prefix} · margini cantieri ({$totalRisk} {$word})";

        try {
            $this->mailer->setSender('alerts');
            $mail = $this->mailer->getMailer();
            $mail->addAddress($recipient);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            echo "Email sent to {$recipient}.\n";
        } catch (\Throwable $e) {
            echo 'Email error: ' . $e->getMessage() . "\n";
        }
    }

    private function buildTable(array $rows, string $color, string $baseUrl): string
    {
        if (!$rows) {
            return '<p style="color:#666;">Nessun cantiere</p>';
        }

        $html = '<table width="100%" cellpadding="6" cellspacing="0"
                       style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">
            <tr style="background:' . $color . ';color:#fff;">
                <th align="left">Cantiere</th><th align="left">Cliente</th>
                <th align="right">Contratto</th><th align="right">Margine</th><th align="right">%</th>
            </tr>';

        foreach ($rows as $r) {
            $html .= '<tr style="border-bottom:1px solid #ddd;">
                <td><a href="' . $baseUrl . (int) $r['id'] . '" target="_blank"
                        style="color:#2c3e50;text-decoration:none;">' . $r['worksite'] . '</a></td>
                <td>' . $r['client'] . '</td>
                <td align="right">' . $r['contract'] . '</td>
                <td align="right"><strong>' . $r['margin'] . '</strong></td>
                <td align="right">' . $r['perc'] . '%</td>
            </tr>';
        }

        return $html . '</table>';
    }
}
