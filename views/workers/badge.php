<?php
/**
 * Worker ID badge — PDF output via dompdf.
 * Invoked by UsersController::badge(); $workerId is injected via require scope.
 */

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

// $workerId is injected by UsersController::badge() via require scope.
$workerId = (int)($workerId ?? $_GET['id'] ?? 0);
if ($workerId <= 0) {
    http_response_code(400);
    echo 'Operaio non specificato.';
    exit;
}

// ── Worker + company ──────────────────────────────────────────────────────
$db   = new \App\Infrastructure\Database();
$conn = $db->connect();

$stmt = $conn->prepare("
    SELECT w.first_name, w.last_name, w.birthday, w.city_of_birth,
           w.fiscal_code, w.active_from, w.photo, w.type_worker,
           c.name    AS company_name,
           c.address AS company_address
    FROM bb_workers w
    LEFT JOIN bb_companies c ON c.id = w.company_id
    WHERE w.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $workerId]);
$worker = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$worker) {
    http_response_code(404);
    echo 'Operaio non trovato.';
    exit;
}

// ── Photo → base64 ────────────────────────────────────────────────────────
$photoSrc = '';
if (!empty($worker['photo'])) {
    $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
    if ($cloudBase) {
        $photoPath = realpath($cloudBase . '/' . $worker['photo']);
        if ($photoPath && str_starts_with($photoPath, $cloudBase) && is_file($photoPath)) {
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mime     = $finfo->file($photoPath) ?: 'image/jpeg';
            $photoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($photoPath));
        }
    }
}

// ── Logo ──────────────────────────────────────────────────────────────────
$logoSrc = '';
$tplPath = APP_ROOT . '/views/offers/offer_template.php';
if (is_file($tplPath)) {
    $chunk = file_get_contents($tplPath, false, null, 0, 307200);
    if (preg_match('/(data:image\/(?:jpeg|png);base64,[A-Za-z0-9+\/=]+)/', $chunk, $m)) {
        $logoSrc = $m[1];
    }
}

// ── Chevron background via user-supplied PNG ──────────────────────────────
// badge_bg.png is the bottom-left corner decoration.
// We rotate it 180° and place the copy at the top-right corner.
$chevronSrc = '';
$bgPngPath  = APP_ROOT . '/public/assets/img/badge_bg.png';
if (function_exists('imagecreatetruecolor') && is_file($bgPngPath)) {
    $stripe = imagecreatefromstring(file_get_contents($bgPngPath));
    if ($stripe !== false) {
        $sw = imagesx($stripe);
        $sh = imagesy($stripe);

        $iw = 539; $ih = 340;

        // Scale the stripe to ~40% of card width, preserving aspect ratio
        $dw = (int)round($iw * 0.40);
        $dh = (int)round($sh * ($dw / $sw));

        $canvas = imagecreatetruecolor($iw, $ih);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagealphablending($canvas, true);

        // Bottom-left: scaled, anchored to bottom-left corner
        $small = imagecreatetruecolor($dw, $dh);
        imagesavealpha($small, true);
        imagealphablending($small, false);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagealphablending($small, true);
        imagecopyresampled($small, $stripe, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagecopy($canvas, $small, 0, $ih - $dh, 0, 0, $dw, $dh);

        // Top-right: rotate 180°, scaled, anchored to top-right corner
        $rotBg   = imagecolorallocatealpha($stripe, 0, 0, 0, 127);
        $rotated = imagerotate($stripe, 180, $rotBg);
        imagesavealpha($rotated, true);
        $rSmall = imagecreatetruecolor($dw, $dh);
        imagesavealpha($rSmall, true);
        imagealphablending($rSmall, false);
        imagefill($rSmall, 0, 0, imagecolorallocatealpha($rSmall, 0, 0, 0, 127));
        imagealphablending($rSmall, true);
        imagecopyresampled($rSmall, $rotated, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagecopy($canvas, $rSmall, $iw - $dw, 0, 0, 0, $dw, $dh);
        imagedestroy($rSmall);

        imagedestroy($stripe);
        imagedestroy($rotated);

        ob_start();
        imagepng($canvas);
        $pngData = ob_get_clean();
        imagedestroy($canvas);
        $chevronSrc = 'data:image/png;base64,' . base64_encode($pngData);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function badgeFmtDate(?string $d): string
{
    if (!$d) { return '—'; }
    $dt = \DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : $d;
}

$firstName   = trim($worker['first_name'] ?? '');
$lastName    = trim($worker['last_name'] ?? '');
$fullName    = trim($lastName . ' ' . $firstName);
$role        = strtoupper($worker['type_worker'] ?? '');
$birthday    = badgeFmtDate($worker['birthday']);
$cityBirth   = $worker['city_of_birth'] ?? '—';
$hireDate    = badgeFmtDate($worker['active_from']);
$fiscalCode  = strtoupper($worker['fiscal_code'] ?? '');
$companyName = $worker['company_name'] ?? '';
$companyAddr = $worker['company_address'] ?? '';
$qrSrc       = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=4&data=' . urlencode($fiscalCode);

// ── Company name PNG with white stroke (GD) ───────────────────────────────
// dompdf does not reliably render text-shadow, so we bake the white outline
// into an image using imagettftext (falls back to imagestring).
function badgeTextPng(string $text, int $ptSize, string $hexFill, string $hexStroke = '#ffffff'): string
{
    if (!function_exists('imagecreatetruecolor') || $text === '') {
        return '';
    }

    $pad   = (int)ceil($ptSize * 0.25);   // outline thickness ≈ 25% of font size
    $scale = 3;                            // render at 3× then down-sample for smoothness

    // Try a Windows TTF font for best quality
    $fontCandidates = [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ];
    $font = null;
    foreach ($fontCandidates as $f) {
        if (is_file($f)) { $font = $f; break; }
    }

    if ($font) {
        $sz  = $ptSize * $scale;
        $box = imagettfbbox($sz, 0, $font, $text);
        $tw  = abs($box[4] - $box[0]);
        $th  = abs($box[5] - $box[1]);
        $ox  = -min($box[0], $box[6]);
        $oy  = -min($box[1], $box[7]);

        $w = $tw + $pad * 2 * $scale + $ox;
        $h = $th + $pad * 2 * $scale;
        $canvas = imagecreatetruecolor((int)$w, (int)$h);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagealphablending($canvas, true);

        [$rs, $gs, $bs] = sscanf(ltrim($hexStroke, '#'), '%02x%02x%02x');
        [$rf, $gf, $bf] = sscanf(ltrim($hexFill,   '#'), '%02x%02x%02x');
        $stroke = imagecolorallocate($canvas, (int)$rs, (int)$gs, (int)$bs);
        $fill   = imagecolorallocate($canvas, (int)$rf, (int)$gf, (int)$bf);

        $tx = $pad * $scale + $ox;
        $ty = $pad * $scale + $oy;

        // Draw white stroke by offsetting in 8 directions
        $r = $pad * $scale;
        for ($dx = -$r; $dx <= $r; $dx++) {
            for ($dy = -$r; $dy <= $r; $dy++) {
                if ($dx * $dx + $dy * $dy <= $r * $r) {
                    imagettftext($canvas, $sz, 0, (int)($tx + $dx), (int)($ty + $dy), $stroke, $font, $text);
                }
            }
        }
        imagettftext($canvas, $sz, 0, (int)$tx, (int)$ty, $fill, $font, $text);

        // Down-sample to 1×
        $dw = (int)ceil($w / $scale);
        $dh = (int)ceil($h / $scale);
        $out = imagecreatetruecolor($dw, $dh);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $dw, $dh, (int)$w, (int)$h);
        imagedestroy($canvas);
    } else {
        // Bitmap font fallback
        $fid = 5;
        $cw  = imagefontwidth($fid);
        $ch  = imagefontheight($fid);
        $tw  = strlen($text) * $cw;
        $dw  = $tw + $pad * 2;
        $dh  = $ch + $pad * 2;
        $out = imagecreatetruecolor($dw, $dh);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagealphablending($out, true);

        [$rs, $gs, $bs] = sscanf(ltrim($hexStroke, '#'), '%02x%02x%02x');
        [$rf, $gf, $bf] = sscanf(ltrim($hexFill,   '#'), '%02x%02x%02x');
        $stroke = imagecolorallocate($out, (int)$rs, (int)$gs, (int)$bs);
        $fill   = imagecolorallocate($out, (int)$rf, (int)$gf, (int)$bf);

        foreach (range(-$pad, $pad) as $dx) {
            foreach (range(-$pad, $pad) as $dy) {
                if ($dx !== 0 || $dy !== 0) {
                    imagestring($out, $fid, $pad + $dx, $pad + $dy, $text, $stroke);
                }
            }
        }
        imagestring($out, $fid, $pad, $pad, $text, $fill);
    }

    ob_start();
    imagepng($out);
    $data = ob_get_clean();
    imagedestroy($out);
    return 'data:image/png;base64,' . base64_encode($data);
}

$companyNameSrc = badgeTextPng($companyName, 14, '#1a3a6b', '#ffffff');
$companyAddrSrc = badgeTextPng(strtolower($companyAddr), 7, '#4a6fa5', '#ffffff');

// ── NFC icon ──────────────────────────────────────────────────────────────
$nfcSrc  = '';
$nfcPath = APP_ROOT . '/public/assets/img/nfc.png';
if (is_file($nfcPath)) {
    $nfcSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($nfcPath));
}

// ── HTML ──────────────────────────────────────────────────────────────────
ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: Arial, Helvetica, sans-serif;
    width:  539pt;
    height: 340pt;
    overflow: hidden;
    background: #ffffff;
    position: relative;
}
.role-badge {
    display: inline-block;
    background: #111;
    color: #fff;
    font-size: 11pt;
    font-weight: bold;
    letter-spacing: 2pt;
    padding: 3.5pt 10pt;
    border-radius: 4pt;
    margin-bottom: 10pt;
}
.info-label {
    font-size: 9pt;
    color: #555;
    padding-right: 6pt;
    white-space: nowrap;
    vertical-align: top;
    padding-bottom: 4pt;
}
.info-value {
    font-size: 11pt;
    font-weight: bold;
    color: #111;
    vertical-align: top;
    padding-bottom: 4pt;
}
.qr-box {
    border: 2.5pt solid #222;
    border-radius: 5pt;
    padding: 4pt;
    display: inline-block;
    margin-bottom: 8pt;
}
.nfc-box {
    border: 2.5pt solid #222;
    border-radius: 5pt;
    width:  66pt;
    text-align: center;
    padding: 5pt 4pt;
}
</style>
</head>
<body>

<?php if ($chevronSrc): ?>
<!-- Chevron background — absolutely positioned PNG, behind all content -->
<img src="<?= $chevronSrc ?>"
     style="position:absolute; top:0; left:0; width:539pt; height:340pt; z-index:0;"
     alt="">
<?php endif; ?>

<!-- All card content sits on top of the background -->
<div style="position:absolute; top:0; left:0; right:0; bottom:0;
            padding:20pt 22pt 16pt 22pt; z-index:1;">

    <!-- Header: logo | company -->
    <table style="width:100%; border-collapse:collapse; margin-bottom:8pt;">
        <tr>
            <td style="vertical-align:top; width:140pt;">
                <?php if ($logoSrc): ?>
                    <img src="<?= $logoSrc ?>" style="height:52pt; width:auto;" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="vertical-align:middle; text-align:right;">
                <?php if ($companyNameSrc): ?>
                    <img src="<?= $companyNameSrc ?>" style="display:block; margin-left:auto; height:18pt; width:auto;" alt="<?= htmlspecialchars($companyName) ?>">
                <?php else: ?>
                    <div style="font-size:14pt; font-weight:900; color:#1a3a6b;"><?= htmlspecialchars($companyName) ?></div>
                <?php endif; ?>
                <?php if ($companyAddr && $companyAddrSrc): ?>
                    <img src="<?= $companyAddrSrc ?>" style="display:block; margin-left:auto; height:10pt; width:auto; margin-top:2pt;" alt="<?= htmlspecialchars($companyAddr) ?>">
                <?php elseif ($companyAddr): ?>
                    <div style="font-size:6.5pt; color:#4a6fa5; margin-top:2pt;"><?= htmlspecialchars($companyAddr) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- Role badge -->
    <?php if ($role): ?>
        <div class="role-badge"><?= htmlspecialchars($role) ?></div>
    <?php endif; ?>

    <!-- Body: photo | info | QR + NFC -->
    <table style="width:100%; border-collapse:collapse;">
        <tr>

            <!-- Photo -->
            <td style="width:88pt; vertical-align:top; padding-right:14pt;">
                <div style="width:88pt; height:110pt; border:2pt solid #aaa;
                            border-radius:3pt; overflow:hidden;">
                    <?php if ($photoSrc): ?>
                        <img src="<?= $photoSrc ?>"
                             style="width:88pt; height:110pt;" alt="Foto">
                    <?php else: ?>
                        <div style="width:88pt; height:110pt; background:#eee;
                                    text-align:center; padding-top:44pt;
                                    font-size:7pt; color:#aaa;">
                            Foto non<br>disponibile
                        </div>
                    <?php endif; ?>
                </div>
            </td>

            <!-- Info -->
            <td style="vertical-align:top;">
                <table style="border-collapse:collapse; margin-bottom:8pt;">
                    <tr>
                        <td class="info-label">Cognome:</td>
                        <td style="font-size:13pt; font-weight:900; color:#111; padding-bottom:2pt;">
                            <?= htmlspecialchars($lastName) ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="info-label">Nome:</td>
                        <td style="font-size:13pt; font-weight:900; color:#111;">
                            <?= htmlspecialchars($firstName) ?>
                        </td>
                    </tr>
                </table>
                <table style="border-collapse:collapse;">
                    <tr>
                        <td class="info-label">Data di Nascita:</td>
                        <td class="info-value"><?= htmlspecialchars($birthday) ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Luogo di Nascita:</td>
                        <td class="info-value"><?= htmlspecialchars($cityBirth) ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Data di Assunzione:</td>
                        <td class="info-value"><?= htmlspecialchars($hireDate) ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">CF:</td>
                        <td class="info-value"><?= htmlspecialchars($fiscalCode) ?></td>
                    </tr>
                </table>
            </td>

            <!-- QR + NFC -->
            <td style="width:90pt; vertical-align:top; text-align:center; padding-left:10pt;">
                <!-- QR with border -->
                <div class="qr-box">
                    <img src="<?= $qrSrc ?>"
                         style="width:74pt; height:74pt; display:block;"
                         alt="QR">
                </div>
                <!-- NFC icon -->
                <div class="nfc-box">
                    <?php if ($nfcSrc): ?>
                        <img src="<?= $nfcSrc ?>"
                             style="width:28pt; height:28pt; display:block; margin:0 auto 3pt;"
                             alt="NFC">
                    <?php endif; ?>
                    <div style="font-size:9pt; font-weight:bold;
                                letter-spacing:3pt; color:#222;">
                        NFC
                    </div>
                </div>
            </td>

        </tr>
    </table>

</div><!-- /card -->
</body>
</html>
<?php
$html = ob_get_clean();

// ── Dompdf ────────────────────────────────────────────────────────────────
$options = new Options();
$options->setIsRemoteEnabled(true);
$options->setIsHtml5ParserEnabled(true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
// 190 mm × 120 mm → 539 pt × 340 pt
$dompdf->setPaper([0, 0, 539, 340]);
$dompdf->render();

$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fullName);
$dompdf->stream("Badge_{$safeName}.pdf", ['Attachment' => false]);
exit;
