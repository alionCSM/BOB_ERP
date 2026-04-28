<?php
/**
 * Programmazione Deadline Check — Cron Job
 *
 * For cantieri starting within 7 days, sends daily reminders using the 6-permission system:
 *   - notif_{field}_scrivere → field is empty + not completato (needs to be written)
 *   - notif_{field}_azione   → field has text + not completato (needs action)
 *
 * Run daily via cron (e.g. 8:00 AM):
 *   0 8 * * * /usr/bin/php /path/to/includes/cron/programmazione_deadline_check.php
 *
 * Safe to re-run: daily dedup via bb_programmazione_notifiche_log.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$logger = \App\Infrastructure\LoggerFactory::app();

$db   = new Database();
$conn = $db->connect();

$today    = date('Y-m-d');
$todayKey = '_' . $today;

function wasSentToday(PDO $conn, int $progId, string $key): bool {
    $stmt = $conn->prepare("SELECT 1 FROM bb_programmazione_notifiche_log WHERE programmazione_id = :pid AND campo = :c");
    $stmt->execute([':pid' => $progId, ':c' => $key]);
    return (bool)$stmt->fetchColumn();
}

function markSentToday(PDO $conn, int $progId, string $key): void {
    $conn->prepare("
        INSERT IGNORE INTO bb_programmazione_notifiche_log (programmazione_id, campo) VALUES (:pid, :c)
    ")->execute([':pid' => $progId, ':c' => $key]);
}

function getUsersWithPerm(PDO $conn, string $module): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id FROM bb_users u
        INNER JOIN bb_user_permissions p ON p.user_id = u.id
        WHERE p.module = :mod AND u.active = 1
    ");
    $stmt->execute([':mod' => $module]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function sendToUsers(PDO $conn, array $userIds, string $title, string $msg, string $category): void {
    if (empty($userIds)) return;
    $ins = $conn->prepare("
        INSERT INTO bb_notifications (user_id, title, message, link, category, priority, created_by, is_read, created_at)
        VALUES (:uid, :title, :msg, '/programmazione', :cat, 'high', 0, 0, NOW())
    ");
    foreach ($userIds as $uid) {
        $ins->execute([':uid' => $uid, ':title' => $title, ':msg' => $msg, ':cat' => $category]);
    }
}

function buildRowInfo(array $row, string $today): array {
    $d = DateTime::createFromFormat('Y-m-d', $row['data']);
    $dateStr = $d ? $d->format('d/m/Y') : $row['data'];
    $daysLeft = $d ? (int)(new DateTime($today))->diff($d)->days : 0;
    $dayWord = $daysLeft === 0 ? 'OGGI' : ($daysLeft === 1 ? 'domani' : "tra {$daysLeft} giorni");
    $urgency = $daysLeft <= 2 ? 'URGENTE — ' : '';
    $committente = trim($row['committente'] ?? '');
    $indirizzo = trim($row['indirizzo'] ?? '');
    return compact('dateStr', 'daysLeft', 'dayWord', 'urgency', 'committente', 'indirizzo');
}

function buildMessage(array $info, string $detail): string {
    $lines = ["Partenza: {$info['dateStr']} ({$info['dayWord']})"];
    if ($info['committente'] !== '') $lines[] = "Committente: {$info['committente']}";
    if ($info['indirizzo'] !== '') $lines[] = "Indirizzo: {$info['indirizzo']}";
    $lines[] = '';
    $lines[] = $detail;
    return implode("\n", $lines);
}

try {

// Find rows starting within 7 days where any status is not completato
$stmt = $conn->prepare("
    SELECT p.id, p.data, p.indirizzo, p.committente,
           p.mezzi, p.stato_mezzi,
           p.trasferta, p.stato_trasferta,
           p.info_beppe, p.stato_beppe
    FROM bb_programmazione p
    WHERE p.data IS NOT NULL
      AND p.data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND (
          (p.stato_mezzi IS NULL OR p.stato_mezzi != 'completato')
          OR (p.stato_trasferta IS NULL OR p.stato_trasferta != 'completato')
          OR (p.stato_beppe IS NULL OR p.stato_beppe != 'completato')
      )
    ORDER BY p.data ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No deadlines found.\n";
    exit(0);
}

$sent = 0;

// Field configuration: maps each field to its permissions and labels
$fields = [
    'mezzi' => [
        'stato'    => 'stato_mezzi',
        'scrivere' => 'notif_mezzi_scrivere',
        'azione'   => 'notif_mezzi_azione',
        'label'    => 'Mezzi',
        'cat'      => 'programmazione_mezzi',
    ],
    'trasferta' => [
        'stato'    => 'stato_trasferta',
        'scrivere' => 'notif_trasferta_scrivere',
        'azione'   => 'notif_trasferta_azione',
        'label'    => 'Trasferta',
        'cat'      => 'programmazione_trasferta',
    ],
    'info_beppe' => [
        'stato'    => 'stato_beppe',
        'scrivere' => 'notif_beppe_scrivere',
        'azione'   => 'notif_beppe_azione',
        'label'    => 'Info Beppe',
        'cat'      => 'programmazione_info',
    ],
];

foreach ($rows as $row) {
    $progId = (int)$row['id'];
    $info = buildRowInfo($row, $today);

    foreach ($fields as $field => $cfg) {
        $stato    = $row[$cfg['stato']] ?? null;
        $hasText  = trim($row[$field] ?? '') !== '';

        // Skip if completato
        if ($stato === 'completato') continue;

        $dedupKey = $field . $todayKey;

        if (wasSentToday($conn, $progId, $dedupKey)) continue;

        if (!$hasText) {
            // Field empty + not completato → notify scrivere holders
            $users = getUsersWithPerm($conn, $cfg['scrivere']);
            if (!empty($users)) {
                $title = "{$info['urgency']}{$cfg['label']} da compilare — {$info['dayWord']}";
                $msg = buildMessage($info, "{$cfg['label']} ancora da scrivere per questo cantiere.");
                sendToUsers($conn, $users, $title, $msg, $cfg['cat']);
                markSentToday($conn, $progId, $dedupKey);
                $sent++;
                echo "{$cfg['label']} scrivere: {$info['committente']} ({$info['dateStr']}) → " . count($users) . " users\n";
            }
        } else {
            // Field has text + not completato → notify azione holders
            $users = getUsersWithPerm($conn, $cfg['azione']);
            if (!empty($users)) {
                $short = strlen($row[$field]) > 80 ? substr($row[$field], 0, 77) . '...' : $row[$field];
                $title = "{$info['urgency']}{$cfg['label']} da completare — {$info['dayWord']}";
                $msg = buildMessage($info, "{$cfg['label']}: {$short}");
                sendToUsers($conn, $users, $title, $msg, $cfg['cat']);
                markSentToday($conn, $progId, $dedupKey);
                $sent++;
                echo "{$cfg['label']} azione: {$info['committente']} ({$info['dateStr']}) → " . count($users) . " users\n";
            }
        }
    }
}

echo "Done. Sent {$sent} notification groups.\n";
$logger->info('programmazione_deadline_check: completed', ['sent' => $sent]);

} catch (Throwable $e) {
    $logger->error('programmazione_deadline_check: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
