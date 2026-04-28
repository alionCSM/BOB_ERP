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

        $body = '
<html><body style="margin:0;padding:20px;background:#f6f7f9;">
<div style="max-width:950px;margin:auto;background:#fff;padding:20px;border-radius:6px;">
<h2 style="margin-top:0;color:#111;">⚠️ BOB – Cantieri a rischio (In corso)</h2>
<p style="color:#555;">Data: ' . date('d/m/Y H:i') . '</p>
<h3 style="color:#c0392b;">❌ Margine negativo</h3>
' . $this->buildTable($riskNegative, '#c0392b', $baseUrl) . '
<h3 style="color:#e67e22;margin-top:30px;">⚠️ Margine inferiore al 10%</h3>
' . $this->buildTable($riskLowMargin, '#e67e22', $baseUrl) . '
<p style="margin-top:30px;font-size:11px;color:#888;">
    Email generata automaticamente da BOB – solo cantieri <strong>In corso</strong>.
</p>
</div></body></html>';

        try {
            $this->mailer->setSender('alerts');
            $mail = $this->mailer->getMailer();
            $mail->addAddress('alion@csmontaggi.it');
            $mail->Subject = 'BOB – Cantieri a rischio (In corso)';
            $mail->Body    = $body;
            $mail->send();
            echo "Email sent.\n";
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
