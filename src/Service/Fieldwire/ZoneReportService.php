<?php
declare(strict_types=1);

namespace App\Service\Fieldwire;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;

/**
 * Genera un report PDF (punch list) dei task BOB Zone di un cantiere:
 * raggruppati per stato, con assegnatario/scadenza/categoria e le foto
 * allegate (incorporate come data URI, downscalate con GD se disponibile).
 */
final class ZoneReportService
{
    private const STATUS_LABEL = [
        'open' => 'Aperto', 'in_progress' => 'In corso',
        'complete' => 'Completato', 'verified' => 'Verificato',
    ];
    private const STATUS_COLOR = [
        'open' => '#d97706', 'in_progress' => '#2563eb',
        'complete' => '#16a34a', 'verified' => '#7c3aed',
    ];
    private const MAX_PHOTOS_PER_TASK = 6;
    private const PHOTO_MAX_PX = 600;

    public function __construct(private PDO $conn) {}

    public function generate(int $worksiteId, array $worksite): string
    {
        $tasks = $this->loadTasks($worksiteId);
        $html  = $this->buildHtml($worksite, $tasks);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private function loadTasks(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_zone_tasks WHERE worksite_id = :w
            ORDER BY FIELD(status,'open','in_progress','complete','verified'), priority DESC, created_at ASC
        ");
        $stmt->execute([':w' => $worksiteId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // foto per task (commenti con file_url)
        $cstmt = $this->conn->prepare("
            SELECT task_id, text, file_url, author_name, created_at
            FROM bb_zone_task_comments
            WHERE task_id = :t AND file_url IS NOT NULL AND file_url <> ''
            ORDER BY created_at ASC
        ");
        foreach ($tasks as &$t) {
            $cstmt->execute([':t' => $t['id']]);
            $t['photos'] = $cstmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $tasks;
    }

    private function buildHtml(array $ws, array $tasks): string
    {
        $counts = ['open' => 0, 'in_progress' => 0, 'complete' => 0, 'verified' => 0];
        foreach ($tasks as $t) {
            $s = $t['status'] ?? 'open';
            if (isset($counts[$s])) $counts[$s]++;
        }

        $wsName = htmlspecialchars($ws['name'] ?? ('Cantiere #' . ($ws['id'] ?? '')));
        $wsCode = htmlspecialchars($ws['worksite_code'] ?? '');
        $date   = date('d/m/Y H:i');

        $css = '
            * { font-family: "DejaVu Sans", sans-serif; }
            body { color:#1e293b; font-size:11px; }
            h1 { font-size:18px; margin:0 0 2px; }
            .sub { color:#64748b; font-size:10px; margin-bottom:12px; }
            .stats { margin-bottom:14px; }
            .stat { display:inline-block; padding:4px 10px; border-radius:4px; color:#fff; font-size:10px; margin-right:6px; }
            .task { border:1px solid #e2e8f0; border-radius:6px; padding:8px 10px; margin-bottom:8px; }
            .task-name { font-size:12px; font-weight:bold; }
            .badge { display:inline-block; padding:1px 7px; border-radius:10px; color:#fff; font-size:9px; }
            .meta { color:#64748b; font-size:10px; margin-top:3px; }
            .desc { margin-top:4px; font-size:10px; }
            .photos { margin-top:6px; }
            .photos img { width:120px; height:auto; border:1px solid #cbd5e1; border-radius:4px; margin:0 4px 4px 0; }
            .sec { font-size:13px; font-weight:bold; margin:14px 0 6px; padding-bottom:3px; border-bottom:2px solid #e2e8f0; }
        ';

        $h = "<html><head><meta charset='utf-8'><style>{$css}</style></head><body>";
        $h .= "<h1>Punch List — {$wsName}</h1>";
        $h .= "<div class='sub'>" . ($wsCode ? "Codice {$wsCode} · " : '') . "Generato il {$date} · " . count($tasks) . " task</div>";

        $h .= "<div class='stats'>";
        foreach ($counts as $s => $n) {
            $h .= "<span class='stat' style='background:" . self::STATUS_COLOR[$s] . "'>" . self::STATUS_LABEL[$s] . ": {$n}</span>";
        }
        $h .= "</div>";

        // raggruppa per stato
        $byStatus = ['open' => [], 'in_progress' => [], 'complete' => [], 'verified' => []];
        foreach ($tasks as $t) {
            $s = $t['status'] ?? 'open';
            $byStatus[$s][] = $t;
        }

        foreach ($byStatus as $s => $list) {
            if (empty($list)) continue;
            $h .= "<div class='sec'>" . self::STATUS_LABEL[$s] . " (" . count($list) . ")</div>";
            foreach ($list as $t) {
                $h .= $this->taskHtml($t, $s);
            }
        }

        $h .= "</body></html>";
        return $h;
    }

    private function taskHtml(array $t, string $status): string
    {
        $name = htmlspecialchars($t['name'] ?? '');
        $color = self::STATUS_COLOR[$status];
        $label = self::STATUS_LABEL[$status];

        $meta = [];
        if (!empty($t['assignee_name'])) $meta[] = '👤 ' . htmlspecialchars($t['assignee_name']);
        if (!empty($t['category']))      $meta[] = htmlspecialchars($t['category']);
        if (!empty($t['due_date']))      $meta[] = 'Scad. ' . date('d/m/Y', strtotime($t['due_date']));

        $h = "<div class='task'>";
        $h .= "<span class='task-name'>{$name}</span> <span class='badge' style='background:{$color}'>{$label}</span>";
        if ($meta) $h .= "<div class='meta'>" . implode(' · ', $meta) . "</div>";
        if (!empty($t['description'])) $h .= "<div class='desc'>" . nl2br(htmlspecialchars($t['description'])) . "</div>";

        // foto incorporate
        $photos = array_slice($t['photos'] ?? [], 0, self::MAX_PHOTOS_PER_TASK);
        if ($photos) {
            $h .= "<div class='photos'>";
            foreach ($photos as $p) {
                $data = $this->photoDataUri($p['file_url'] ?? '');
                if ($data) $h .= "<img src='{$data}'>";
            }
            $h .= "</div>";
        }
        $h .= "</div>";
        return $h;
    }

    /** Legge la foto dal disco e la restituisce come data URI (downscalata se GD c'è). */
    private function photoDataUri(string $fileUrl): ?string
    {
        // file_url = /worksites/{id}/zone/photo?f=<rel-encoded>
        $q = parse_url($fileUrl, PHP_URL_QUERY);
        if (!$q) return null;
        parse_str($q, $params);
        $rel = $params['f'] ?? '';
        if ($rel === '') return null;

        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $rel;
        $real = realpath($abs);
        $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
            return null;
        }

        // downscale con GD se disponibile (riduce molto la dimensione del PDF)
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $raw = @file_get_contents($real);
            $img = $raw ? @imagecreatefromstring($raw) : false;
            if ($img !== false) {
                $w = imagesx($img); $hh = imagesy($img);
                $scale = min(1, self::PHOTO_MAX_PX / max($w, $hh));
                if ($scale < 1) {
                    $nw = (int)($w * $scale); $nh = (int)($hh * $scale);
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $hh);
                    imagedestroy($img); $img = $dst;
                }
                ob_start(); imagejpeg($img, null, 78); $bin = ob_get_clean();
                imagedestroy($img);
                return 'data:image/jpeg;base64,' . base64_encode($bin);
            }
        }
        // fallback: incorpora il file così com'è
        $bin = @file_get_contents($real);
        if ($bin === false) return null;
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
}
