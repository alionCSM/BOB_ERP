<?php
/**
 * Email template for document expiry alerts.
 *
 * Variables available:
 * @var string $companyName
 * @var array  $expired     — expired documents
 * @var array  $sevenDay    — documents expiring in 7 days
 * @var array  $thirtyDay   — documents expiring in 30 days
 * @var DateTime $today
 * @var string $appUrl      — base URL (e.g. https://bob.csmontaggi.it)
 */

$appUrl = $this->appUrl ?? 'https://bob.csmontaggi.it';
$logoUrl = $appUrl . '/includes/template/dist/images/logo.png';

// Split docs into worker and company
if (!function_exists('splitBySource')) {
    function splitBySource(array $docs): array {
        $worker = [];
        $company = [];
        foreach ($docs as $doc) {
            if (($doc['_source'] ?? '') === 'company') {
                $company[] = $doc;
            } else {
                $worker[] = $doc;
            }
        }
        return ['worker' => $worker, 'company' => $company];
    }
}

$expiredSplit = splitBySource($expired);
$sevenDaySplit = splitBySource($sevenDay);
$thirtyDaySplit = splitBySource($thirtyDay);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

    <!-- Header -->
    <tr>
        <td style="background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#7c3aed 100%);padding:32px 32px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="BOB" style="height:36px;width:auto;margin-bottom:16px;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:rgba(255,255,255,0.6);margin-bottom:8px;">Riepilogo Documenti</div>
                        <div style="font-size:24px;font-weight:800;color:#ffffff;line-height:1.2;"><?= htmlspecialchars($companyName) ?></div>
                        <div style="font-size:13px;color:rgba(255,255,255,0.5);margin-top:6px;"><?= date('d/m/Y') ?></div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Body -->
    <tr>
        <td style="padding:28px 32px 36px;">

            <?php if (!empty($expired)): ?>
            <!-- EXPIRED SECTION -->
            <div style="margin-bottom:28px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:0;">
                    <tr>
                        <td style="padding:12px 16px;background:#fef2f2;border-radius:10px 10px 0 0;border-left:4px solid #dc2626;">
                            <span style="font-size:15px;font-weight:700;color:#dc2626;">&#10060; Documenti Scaduti (<?= count($expired) ?>)</span>
                        </td>
                    </tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fecaca;border-top:none;border-radius:0 0 10px 10px;overflow:hidden;">

                    <?php if (!empty($expiredSplit['worker'])): ?>
                    <!-- Worker docs -->
                    <tr style="background:#fef2f2;">
                        <td colspan="4" style="padding:10px 14px 4px;font-size:11px;font-weight:800;color:#7f1d1d;text-transform:uppercase;letter-spacing:0.5px;">
                            &#128100; Documenti Operai
                        </td>
                    </tr>
                    <tr style="background:#fef2f2;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Operaio</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Scadenza</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;text-align:right;">Giorni</td>
                    </tr>
                    <?php foreach ($expiredSplit['worker'] as $doc):
                        $expDate = new DateTime($doc['scadenza_norm']);
                        $daysAgo = $today->diff($expDate)->days;
                    ?>
                    <tr style="border-top:1px solid #fee2e2;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#dc2626;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#dc2626;font-weight:700;text-align:right;">-<?= $daysAgo ?>gg</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($expiredSplit['company'])): ?>
                    <!-- Company docs -->
                    <tr style="background:#fef2f2;">
                        <td colspan="4" style="padding:<?= !empty($expiredSplit['worker']) ? '14px' : '10px' ?> 14px 4px;font-size:11px;font-weight:800;color:#7f1d1d;text-transform:uppercase;letter-spacing:0.5px;<?= !empty($expiredSplit['worker']) ? 'border-top:2px solid #fecaca;' : '' ?>">
                            &#127970; Documenti Aziendali
                        </td>
                    </tr>
                    <tr style="background:#fef2f2;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Azienda</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Scadenza</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;text-align:right;">Giorni</td>
                    </tr>
                    <?php foreach ($expiredSplit['company'] as $doc):
                        $expDate = new DateTime($doc['scadenza_norm']);
                        $daysAgo = $today->diff($expDate)->days;
                    ?>
                    <tr style="border-top:1px solid #fee2e2;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#dc2626;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#dc2626;font-weight:700;text-align:right;">-<?= $daysAgo ?>gg</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($sevenDay)): ?>
            <!-- 7 DAY SECTION -->
            <div style="margin-bottom:28px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:0;">
                    <tr>
                        <td style="padding:12px 16px;background:#fff7ed;border-radius:10px 10px 0 0;border-left:4px solid #ea580c;">
                            <span style="font-size:15px;font-weight:700;color:#ea580c;">&#9888;&#65039; In Scadenza tra 7 giorni (<?= count($sevenDay) ?>)</span>
                        </td>
                    </tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fed7aa;border-top:none;border-radius:0 0 10px 10px;overflow:hidden;">

                    <?php if (!empty($sevenDaySplit['worker'])): ?>
                    <tr style="background:#fff7ed;">
                        <td colspan="3" style="padding:10px 14px 4px;font-size:11px;font-weight:800;color:#7c2d12;text-transform:uppercase;letter-spacing:0.5px;">
                            &#128100; Documenti Operai
                        </td>
                    </tr>
                    <tr style="background:#fff7ed;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Operaio</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Scadenza</td>
                    </tr>
                    <?php foreach ($sevenDaySplit['worker'] as $doc): ?>
                    <tr style="border-top:1px solid #fed7aa;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#ea580c;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($sevenDaySplit['company'])): ?>
                    <tr style="background:#fff7ed;">
                        <td colspan="3" style="padding:<?= !empty($sevenDaySplit['worker']) ? '14px' : '10px' ?> 14px 4px;font-size:11px;font-weight:800;color:#7c2d12;text-transform:uppercase;letter-spacing:0.5px;<?= !empty($sevenDaySplit['worker']) ? 'border-top:2px solid #fed7aa;' : '' ?>">
                            &#127970; Documenti Aziendali
                        </td>
                    </tr>
                    <tr style="background:#fff7ed;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Azienda</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;">Scadenza</td>
                    </tr>
                    <?php foreach ($sevenDaySplit['company'] as $doc): ?>
                    <tr style="border-top:1px solid #fed7aa;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#ea580c;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($thirtyDay)): ?>
            <!-- 30 DAY SECTION -->
            <div style="margin-bottom:28px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:0;">
                    <tr>
                        <td style="padding:12px 16px;background:#fffbeb;border-radius:10px 10px 0 0;border-left:4px solid #d97706;">
                            <span style="font-size:15px;font-weight:700;color:#d97706;">&#128339; In Scadenza tra 30 giorni (<?= count($thirtyDay) ?>)</span>
                        </td>
                    </tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fde68a;border-top:none;border-radius:0 0 10px 10px;overflow:hidden;">

                    <?php if (!empty($thirtyDaySplit['worker'])): ?>
                    <tr style="background:#fffbeb;">
                        <td colspan="3" style="padding:10px 14px 4px;font-size:11px;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:0.5px;">
                            &#128100; Documenti Operai
                        </td>
                    </tr>
                    <tr style="background:#fffbeb;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Operaio</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Scadenza</td>
                    </tr>
                    <?php foreach ($thirtyDaySplit['worker'] as $doc): ?>
                    <tr style="border-top:1px solid #fde68a;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#d97706;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($thirtyDaySplit['company'])): ?>
                    <tr style="background:#fffbeb;">
                        <td colspan="3" style="padding:<?= !empty($thirtyDaySplit['worker']) ? '14px' : '10px' ?> 14px 4px;font-size:11px;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:0.5px;<?= !empty($thirtyDaySplit['worker']) ? 'border-top:2px solid #fde68a;' : '' ?>">
                            &#127970; Documenti Aziendali
                        </td>
                    </tr>
                    <tr style="background:#fffbeb;">
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Documento</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Azienda</td>
                        <td style="padding:4px 14px 8px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Scadenza</td>
                    </tr>
                    <?php foreach ($thirtyDaySplit['company'] as $doc): ?>
                    <tr style="border-top:1px solid #fde68a;">
                        <td style="padding:10px 14px;font-size:13px;color:#334155;font-weight:600;"><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#334155;"><?= htmlspecialchars($doc['_entity_name']) ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:#d97706;font-weight:600;"><?= date('d/m/Y', strtotime($doc['scadenza_norm'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                </table>
            </div>
            <?php endif; ?>

            <!-- CTA -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:4px;">
                <tr>
                    <td align="center">
                        <a href="<?= htmlspecialchars($appUrl . ($ctaLink ?? '/expired.php')) ?>"
                           style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#312e81,#7c3aed);color:#ffffff;text-decoration:none;border-radius:10px;font-size:14px;font-weight:700;letter-spacing:0.3px;">
                            Visualizza su BOB
                        </a>
                    </td>
                </tr>
            </table>

        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size:11px;color:#94a3b8;line-height:1.5;">
                        Questa email è stata generata automaticamente da BOB.<br>
                        Non rispondere a questo messaggio.
                    </td>
                    <td align="right" style="font-size:11px;color:#94a3b8;">
                        &copy; <?= date('Y') ?> Consorzio Soluzione Montaggi
                    </td>
                </tr>
            </table>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>
