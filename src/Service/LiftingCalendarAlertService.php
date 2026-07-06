<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Segnala via email le presenze registrate in giorni che il noleggio
 * mezzi NON sta contando.
 *
 * Esempio: noleggio Giornaliero Lun-Ven, ma BOB vede presenze (nostri o
 * consorziate) di sabato/domenica/festivo → probabilmente il mezzo e'
 * stato usato davvero quel giorno. Chi gestisce i mezzi riceve una mail
 * e puo' ignorare oppure aggiungere manualmente i "giorni extra" dalla
 * pagina Modifica noleggi.
 *
 * - Destinatari: utenti con permesso modulo 'equipment_alerts'
 *   (bb_user_permissions, stesso meccanismo di document_alerts).
 * - Anti-spam: ogni coppia (noleggio, data) viene notificata UNA volta
 *   (registro bb_lifting_calendar_alerts).
 * - Lookback: controlla solo le presenze degli ultimi N giorni
 *   (default 30, override con env LIFTING_ALERT_LOOKBACK_DAYS) per non
 *   inondare di alert storici alla prima esecuzione.
 */
class LiftingCalendarAlertService
{
    public function __construct(private PDO $conn) {}

    /**
     * @return array{findings:int, emails_sent:int}
     */
    public function run(): array
    {
        $lookback = max(1, (int)($_ENV['LIFTING_ALERT_LOOKBACK_DAYS'] ?? 30));
        $from     = date('Y-m-d', strtotime("-{$lookback} days"));
        $today    = date('Y-m-d');

        $findings = $this->detect($from, $today);
        if (empty($findings)) {
            return ['findings' => 0, 'emails_sent' => 0];
        }

        $sent = $this->notify($findings);

        // registra le segnalazioni SOLO se almeno una mail e' partita,
        // cosi' un problema SMTP non "brucia" gli alert.
        if ($sent > 0) {
            $this->markNotified($findings);
        }

        return ['findings' => count($findings), 'emails_sent' => $sent];
    }

    /**
     * Trova le presenze fuori dal calendario dei noleggi Giornalieri.
     *
     * @return array<int, array{rental:array, data:string, presenze:int}>
     */
    public function detect(string $from, string $to): array
    {
        // Noleggi giornalieri il cui periodo tocca la finestra di controllo.
        $stmt = $this->conn->prepare("
            SELECT wl.*, le.descrizione AS mezzo, w.name AS cantiere, w.worksite_code
            FROM bb_worksite_lifting wl
            JOIN bb_lifting_equipment le ON le.id = wl.lifting_equipment_id
            JOIN bb_worksites w         ON w.id  = wl.worksite_id
            WHERE wl.tipo_noleggio = 'Giornaliero'
              AND wl.data_inizio <= :to
              AND (wl.data_fine IS NULL OR wl.data_fine >= :from)
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rentals)) return [];

        $findings = [];
        $presCache = []; // worksite_id => [data => n. presenze]

        foreach ($rentals as $r) {
            $wid = (int)$r['worksite_id'];
            if (!isset($presCache[$wid])) {
                $presCache[$wid] = $this->presenzePerDay($wid, $from, $to);
            }

            // finestra effettiva del noleggio dentro il lookback
            $rFrom = max($r['data_inizio'], $from);
            $rTo   = min($r['data_fine'] ?: $to, $to);

            $extraDays = $this->extraDaysForRental((int)$r['id']);

            foreach ($presCache[$wid] as $day => $qta) {
                if ($day < $rFrom || $day > $rTo) continue;

                // il giorno e' gia' conteggiato dal noleggio? allora ok
                if (RentalCostCalculator::dayCounts($day, (string)$r['calendario'], !empty($r['festivi_inclusi']))) {
                    continue;
                }
                // gia' aggiunto a mano come giorno extra? gestito, niente alert
                if (isset($extraDays[$day])) continue;
                // gia' segnalato in passato?
                if ($this->alreadyNotified((int)$r['id'], $day)) continue;

                $findings[] = ['rental' => $r, 'data' => $day, 'presenze' => $qta];
            }
        }

        return $findings;
    }

    /** @return array<string,int> data => numero presenze (nostri + consorziate) */
    private function presenzePerDay(int $worksiteId, string $from, string $to): array
    {
        $out = [];
        $stmt = $this->conn->prepare("
            SELECT d, SUM(n) AS n FROM (
                SELECT data AS d, COUNT(*) AS n
                FROM bb_presenze
                WHERE worksite_id = :w1 AND data BETWEEN :f1 AND :t1
                GROUP BY data

                UNION ALL

                SELECT data_presenza AS d, COALESCE(SUM(quantita), 0) AS n
                FROM bb_presenze_consorziate
                WHERE worksite_id = :w2 AND data_presenza BETWEEN :f2 AND :t2
                GROUP BY data_presenza
            ) x GROUP BY d
        ");
        $stmt->execute([
            ':w1' => $worksiteId, ':f1' => $from, ':t1' => $to,
            ':w2' => $worksiteId, ':f2' => $from, ':t2' => $to,
        ]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[substr((string)$row['d'], 0, 10)] = (int)$row['n'];
        }
        return $out;
    }

    /** @return array<string,bool> date extra gia' registrate per il rental */
    private function extraDaysForRental(int $rentalId): array
    {
        $stmt = $this->conn->prepare("SELECT data FROM bb_lifting_extra_days WHERE rental_id = :r");
        $stmt->execute([':r' => $rentalId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $out[substr((string)$d, 0, 10)] = true;
        }
        return $out;
    }

    private function alreadyNotified(int $rentalId, string $day): bool
    {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM bb_lifting_calendar_alerts WHERE rental_id = :r AND data = :d LIMIT 1
        ");
        $stmt->execute([':r' => $rentalId, ':d' => $day]);
        return (bool)$stmt->fetchColumn();
    }

    private function markNotified(array $findings): void
    {
        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO bb_lifting_calendar_alerts (rental_id, worksite_id, data, presenze_qta)
            VALUES (:r, :w, :d, :q)
        ");
        foreach ($findings as $f) {
            $stmt->execute([
                ':r' => (int)$f['rental']['id'],
                ':w' => (int)$f['rental']['worksite_id'],
                ':d' => $f['data'],
                ':q' => (int)$f['presenze'],
            ]);
        }
    }

    // ── Email ───────────────────────────────────────────────────────────────────

    /** Utenti con permesso 'equipment_alerts'. */
    private function recipients(): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
            FROM bb_users u
            INNER JOIN bb_user_permissions p ON p.user_id = u.id
            WHERE p.module = 'equipment_alerts'
              AND p.allowed = 1
              AND u.active = 'Y'
              AND u.removed = 'N'
              AND u.email IS NOT NULL AND u.email != ''
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una mail digest per destinatario con tutte le segnalazioni. */
    private function notify(array $findings): int
    {
        $recipients = $this->recipients();
        if (empty($recipients)) {
            error_log('[LiftingCalendarAlert] nessun utente con permesso equipment_alerts: alert non inviati');
            return 0;
        }

        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $html   = $this->buildEmailHtml($findings, $appUrl);
        $sent   = 0;

        foreach ($recipients as $rec) {
            try {
                $mailer = new Mailer();
                $mailer->setSender('alerts');
                $mail = $mailer->getMailer();
                $mail->addAddress($rec['email'], trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')));
                $mail->Subject = '[BOB] Mezzi a noleggio: presenze fuori calendario (' . count($findings) . ')';
                $mail->Body    = $html;
                $mail->AltBody = strip_tags(str_replace(['</tr>', '<br>'], "\n", $html));
                $mail->send();
                $sent++;
            } catch (\Throwable $e) {
                error_log('[LiftingCalendarAlert] invio a ' . $rec['email'] . ' fallito: ' . $e->getMessage());
            }
        }
        return $sent;
    }

    private function buildEmailHtml(array $findings, string $appUrl): string
    {
        // raggruppa per cantiere → noleggio
        $byWorksite = [];
        foreach ($findings as $f) {
            $byWorksite[(int)$f['rental']['worksite_id']][] = $f;
        }

        $giorni = ['Mon'=>'Lunedì','Tue'=>'Martedì','Wed'=>'Mercoledì','Thu'=>'Giovedì','Fri'=>'Venerdì','Sat'=>'Sabato','Sun'=>'Domenica'];

        $h  = "<div style='font-family:Arial,sans-serif;font-size:14px;color:#1e293b'>";
        $h .= "<h2 style='color:#b45309'>⚠️ Presenze fuori dal calendario di noleggio</h2>";
        $h .= "<p>BOB ha rilevato presenze in giorni che i seguenti noleggi <b>non stanno conteggiando</b>. ";
        $h .= "Se il mezzo è stato usato davvero, aggiungi i giorni extra dalla pagina di modifica del noleggio; altrimenti ignora questa segnalazione.</p>";

        foreach ($byWorksite as $wid => $items) {
            $first = $items[0]['rental'];
            $h .= "<h3 style='margin-bottom:4px'>{$first['worksite_code']} — " . htmlspecialchars((string)$first['cantiere']) . "</h3>";
            $h .= "<table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse;border-color:#e2e8f0;font-size:13px'>";
            $h .= "<tr style='background:#f1f5f9'><th align='left'>Mezzo</th><th align='left'>Calendario</th><th align='left'>Giorno</th><th align='left'>Presenze</th></tr>";
            foreach ($items as $f) {
                $r    = $f['rental'];
                $cal  = RentalCostCalculator::weekdayLabel((string)$r['calendario']);
                $cal .= !empty($r['festivi_inclusi']) ? ' (festivi inclusi)' : ' (festivi esclusi)';
                $dow  = $giorni[date('D', strtotime($f['data']))] ?? '';
                $isHol = RentalCostCalculator::isItalianHoliday(new \DateTimeImmutable($f['data'])) ? ' 🎌 festivo' : '';
                $h .= "<tr>"
                    . "<td>" . htmlspecialchars((string)$r['mezzo']) . " (x{$r['quantita']})</td>"
                    . "<td>{$cal}</td>"
                    . "<td><b>{$dow} " . date('d/m/Y', strtotime($f['data'])) . "</b>{$isHol}</td>"
                    . "<td>{$f['presenze']} presenze</td>"
                    . "</tr>";
            }
            $h .= "</table>";
            if ($appUrl !== '') {
                $h .= "<p style='margin-top:6px'><a href='{$appUrl}/equipment/rentals/{$wid}/edit' style='color:#2563eb'>→ Modifica noleggi: aggiungi le date come \"Giorni extra\" se il mezzo e' stato usato</a></p>";
            }
        }

        $h .= "<p style='color:#64748b;font-size:12px;margin-top:16px'>Ogni situazione viene segnalata una sola volta. Email automatica — BOB.</p></div>";
        return $h;
    }
}
