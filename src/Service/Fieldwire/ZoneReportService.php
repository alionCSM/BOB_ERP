<?php
declare(strict_types=1);

namespace App\Service\Fieldwire;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;

/**
 * Il rapporto di cantiere della Zone, in PDF, per un periodo.
 *
 * Prima era una "Punch List": l'elenco delle attivita' per stato, in
 * inglese, senza date, e nient'altro della Zone. Al cliente non diceva
 * cosa era successo in settimana, che e' la sola cosa che un rapporto
 * deve dire.
 *
 * Adesso, per il periodo scelto:
 *   1. in breve: attivita' fatte e aperte, in ritardo, segnalazioni,
 *      giornate di lavoro, moduli compilati;
 *   2. cosa e' stato fatto, con le foto;
 *   3. cosa resta aperto, in ritardo per primo;
 *   4. le segnalazioni aperte o chiuse nel periodo;
 *   5. le presenze giorno per giorno;
 *   6. i moduli compilati.
 *
 * Il rapporto lo scaricano anche squadra e cliente: chi chiama passa le
 * regole della Zone (cosa vede chi scarica), e qui dentro non si guarda
 * niente che non sia passato da quelle.
 */
final class ZoneReportService
{
    /** Le presenze nel riepilogo: no per il committente, se CSM non le condivide. */
    public bool $conPresenze = true;

    private const STATO = [
        'open' => 'Da fare', 'in_progress' => 'In corso',
        'complete' => 'Fatta', 'verified' => 'Verificata',
    ];
    private const COLORE = [
        'open' => '#d97706', 'in_progress' => '#2563eb',
        'complete' => '#16a34a', 'verified' => '#7c3aed',
    ];
    private const TIPO_SEGN = [
        'sicurezza' => 'Sicurezza', 'materiale' => 'Materiale', 'danno' => 'Danno',
        'ritardo' => 'Ritardo', 'qualita' => 'Qualità', 'altro' => 'Altro',
    ];
    private const STATO_SEGN = ['aperta' => 'Aperta', 'presa' => 'Presa in carico', 'risolta' => 'Risolta'];
    private const FOTO_PER_ATTIVITA = 4;
    private const FOTO_PX = 600;

    public function __construct(private PDO $conn) {}

    /**
     * @param string                    $dal         Y-m-d
     * @param string                    $al          Y-m-d
     * @param callable(array): bool     $vedeTask    quali attivita' entrano
     * @param callable(array): array    $filtraFoto  quali foto di un'attivita' entrano
     * @param callable(array): bool     $vedeRisposta quali compilazioni entrano
     * @param array<int, array>         $segnalazioni quelle che chi scarica vede
     * @param string                    $perChi      "CSM", "Committente"... per l'intestazione
     */
    public function generate(
        int $worksiteId,
        array $worksite,
        string $dal,
        string $al,
        callable $vedeTask,
        callable $filtraFoto,
        callable $vedeRisposta,
        array $segnalazioni,
        string $perChi,
    ): string {
        $html = $this->html($worksiteId, $worksite, $dal, $al, $vedeTask, $filtraFoto, $vedeRisposta, $segnalazioni, $perChi);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /** L'HTML del rapporto: separato dal PDF per poterlo provare senza Dompdf. */
    public function html(
        int $worksiteId,
        array $worksite,
        string $dal,
        string $al,
        callable $vedeTask,
        callable $filtraFoto,
        callable $vedeRisposta,
        array $segnalazioni,
        string $perChi,
    ): string {
        $oggi    = date('Y-m-d');
        $attivita = array_values(array_filter($this->attivita($worksiteId), $vedeTask));

        $nelPeriodo = static fn(?string $quando): bool =>
            $quando !== null && substr($quando, 0, 10) >= $dal && substr($quando, 0, 10) <= $al;

        $fatte  = array_values(array_filter($attivita, fn($t) =>
            in_array($t['status'], ['complete', 'verified'], true) && $nelPeriodo($t['updated_at'] ?? null)));
        $aperte = array_values(array_filter($attivita, fn($t) =>
            !in_array($t['status'], ['complete', 'verified'], true)));
        $ritardo = array_values(array_filter($aperte, fn($t) =>
            !empty($t['due_date']) && substr((string)$t['due_date'], 0, 10) < $oggi));
        // in ritardo per prime, poi per scadenza
        usort($aperte, function ($a, $b) use ($oggi) {
            $ra = !empty($a['due_date']) && substr((string)$a['due_date'], 0, 10) < $oggi;
            $rb = !empty($b['due_date']) && substr((string)$b['due_date'], 0, 10) < $oggi;
            if ($ra !== $rb) return $ra ? -1 : 1;
            return strcmp((string)($a['due_date'] ?: '9999'), (string)($b['due_date'] ?: '9999'));
        });

        $segn = array_values(array_filter($segnalazioni, fn($s) =>
            $nelPeriodo($s['created_at'] ?? null) || $nelPeriodo($s['risolta_at'] ?? null)
            || ($s['stato'] ?? '') !== 'risolta'));
        $segnAperte = count(array_filter($segnalazioni, fn($s) => ($s['stato'] ?? '') !== 'risolta'));

        $presenze = $this->conPresenze ? $this->presenze($worksiteId, $dal, $al) : [];
        $giornate = array_sum(array_column($presenze, 'giornate'));

        $risposte = array_values(array_filter($this->risposte($worksiteId, $dal, $al), $vedeRisposta));

        $e   = static fn($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        $d   = static fn(?string $x): string => $x ? date('d/m/Y', strtotime($x)) : '';
        $num = static fn(float $x): string => rtrim(rtrim(number_format($x, 1, ',', '.'), '0'), ',');

        $nome    = $e($worksite['name'] ?? ('Cantiere #' . $worksiteId));
        $codice  = $e($worksite['worksite_code'] ?? '');
        $cliente = $e($this->cliente((int)($worksite['client_id'] ?? 0)));

        $h  = "<html><head><meta charset='utf-8'><style>" . $this->css() . "</style></head><body>";
        $h .= "<div class='testa'><div class='marchio'>Consorzio Soluzione Montaggi · BOB</div>"
            . "<h1>Riepilogo lavori</h1>"
            . "<div class='cantiere'>{$nome}" . ($codice ? " · {$codice}" : '') . "</div>"
            . (!empty($worksite['riferimento'])
                ? "<div class='sub'>Riferimento del cliente: " . $e(implode(' · ', array_filter([$worksite['riferimento']['nome'] ?? '', $worksite['riferimento']['codice'] ?? '']))) . "</div>"
                : '')
            . "<div class='sub'>" . ($cliente ? "Cliente: {$cliente} · " : '')
            . "Periodo: {$d($dal)} – {$d($al)} · Generato il " . date('d/m/Y H:i') . " · Copia per: {$e($perChi)}</div></div>";

        // 1. in breve
        $h .= "<table class='breve'><tr>"
            . $this->riquadro((string)count($fatte), 'attività fatte', '#16a34a')
            . $this->riquadro((string)count($aperte), 'ancora aperte', '#2563eb')
            . $this->riquadro((string)count($ritardo), 'in ritardo', count($ritardo) ? '#dc2626' : '#64748b')
            . $this->riquadro((string)$segnAperte, 'segnalazioni aperte', $segnAperte ? '#d97706' : '#64748b')
            . ($this->conPresenze ? $this->riquadro($num((float)$giornate), 'giornate di lavoro', '#0f172a') : '')
            . $this->riquadro((string)count($risposte), 'moduli compilati', '#0f172a')
            . "</tr></table>";

        // 2. fatto nel periodo
        $h .= "<div class='sez'>Fatto nel periodo</div>";
        if (!$fatte) {
            $h .= "<p class='vuoto'>Nessuna attività chiusa in questo periodo.</p>";
        }
        foreach ($fatte as $t) {
            $h .= $this->attivitaHtml($t, $filtraFoto, true);
        }

        // 3. aperte
        $h .= "<div class='sez'>Da fare</div>";
        if (!$aperte) {
            $h .= "<p class='vuoto'>Nessuna attività aperta.</p>";
        }
        foreach ($aperte as $t) {
            $h .= $this->attivitaHtml($t, $filtraFoto, false);
        }

        // 4. segnalazioni
        $h .= "<div class='sez'>Segnalazioni</div>";
        if (!$segn) {
            $h .= "<p class='vuoto'>Nessuna segnalazione aperta o chiusa nel periodo.</p>";
        } else {
            $h .= "<table class='tab'><tr><th>Data</th><th>Tipo</th><th>Problema</th><th>Stato</th></tr>";
            foreach ($segn as $s) {
                $stato = self::STATO_SEGN[$s['stato'] ?? ''] ?? $s['stato'];
                if (($s['stato'] ?? '') === 'risolta' && !empty($s['risolta_at'])) {
                    $stato .= ' il ' . $d($s['risolta_at']);
                }
                $grave = ($s['gravita'] ?? '') === 'blocca' ? " <b class='rosso'>blocca il lavoro</b>" : '';
                $h .= "<tr><td>{$d($s['created_at'] ?? null)}</td><td>" . $e(self::TIPO_SEGN[$s['tipo'] ?? ''] ?? '') . "</td>"
                    . "<td>" . $e(mb_substr((string)($s['testo'] ?? ''), 0, 160)) . "{$grave}"
                    . (!empty($s['esito']) ? "<div class='meta'>Esito: " . $e($s['esito']) . "</div>" : '') . "</td>"
                    . "<td>" . $e($stato) . "</td></tr>";
            }
            $h .= "</table>";
        }

        // 5. presenze
        if (!$this->conPresenze) {
            // non condivise con chi scarica il riepilogo
        } elseif (!$presenze) {
            $h .= "<div class='sez'>Presenze</div>";
            $h .= "<p class='vuoto'>Nessuna presenza registrata nel periodo.</p>";
        } else {
            $h .= "<div class='sez'>Presenze</div>";
            $h .= "<table class='tab'><tr><th>Giorno</th><th>Persone</th><th>Giornate</th></tr>";
            foreach ($presenze as $p) {
                $h .= "<tr><td>{$d($p['data'])}</td><td>" . (int)$p['persone'] . "</td><td>" . $num((float)$p['giornate']) . "</td></tr>";
            }
            $h .= "<tr class='tot'><td>Totale</td><td></td><td>" . $num((float)$giornate) . "</td></tr></table>";
        }

        // 6. moduli
        $h .= "<div class='sez'>Moduli compilati</div>";
        if (!$risposte) {
            $h .= "<p class='vuoto'>Nessun modulo compilato nel periodo.</p>";
        } else {
            $h .= "<table class='tab'><tr><th>Data</th><th>Modulo</th><th>Compilato da</th></tr>";
            foreach ($risposte as $r) {
                $chi = trim((string)($r['user_name'] ?? '')) ?: (string)($r['submitter_name'] ?? '');
                $h .= "<tr><td>{$d($r['created_at'])}</td><td>" . $e($r['template_name']) . "</td><td>" . $e($chi) . "</td></tr>";
            }
            $h .= "</table>";
        }

        $h .= "<div class='piede'>Riepilogo generato da BOB per il cantiere {$nome}. Contiene solo ciò che "
            . "è condiviso con chi l'ha scaricato.</div>";
        return $h . "</body></html>";
    }

    // ── Dati ────────────────────────────────────────────────────────────────

    private function attivita(int $worksiteId): array
    {
        $st = $this->conn->prepare("SELECT * FROM bb_zone_tasks WHERE worksite_id = :w ORDER BY priority DESC, updated_at DESC");
        $st->execute([':w' => $worksiteId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);

        $foto = $this->conn->prepare("
            SELECT id, task_id, text, file_url, author_name, created_at, interna, per_cliente, author_user_id
            FROM   bb_zone_task_comments
            WHERE  task_id = :t AND file_url IS NOT NULL AND file_url <> ''
            ORDER BY created_at DESC
        ");
        foreach ($tasks as &$t) {
            $foto->execute([':t' => $t['id']]);
            $t['photos'] = $foto->fetchAll(PDO::FETCH_ASSOC);
        }
        return $tasks;
    }

    /** @return array<int, array{data:string, persone:int, giornate:float}> */
    private function presenze(int $worksiteId, string $dal, string $al): array
    {
        $per = [];
        // quelle scritte a mano da CSM nella Zone
        try {
            $m = $this->conn->prepare("
                SELECT data,
                       SUM(CASE WHEN TRIM(COALESCE(nome,'')) <> '' THEN 1 ELSE GREATEST(persone, 1) END) AS persone,
                       SUM(CASE WHEN TRIM(COALESCE(nome,'')) <> '' THEN 1 ELSE GREATEST(persone, 1) END
                           * (CASE WHEN mezza THEN 0.5 ELSE 1 END)) AS giornate
                FROM bb_zone_presenze_manuali WHERE worksite_id = :w AND data BETWEEN :dal AND :al
                GROUP BY data
            ");
            $m->execute([':w' => $worksiteId, ':dal' => $dal, ':al' => $al]);
            foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $per[$r['data']] = ['data' => $r['data'], 'persone' => (int)$r['persone'], 'giornate' => (float)$r['giornate']];
            }
        } catch (\PDOException $e) {
            // tabella non ancora creata
        }
        // il registro di BOB solo se la Zone lo legge (vedi Presenze::DAL_REGISTRO)
        if (!\App\Service\Zone\Presenze::DAL_REGISTRO) {
            ksort($per);
            return array_values($per);
        }
        $st = $this->conn->prepare("
            SELECT data, COUNT(*) AS persone,
                   SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END) AS giornate
            FROM   bb_presenze WHERE worksite_id = :w AND data BETWEEN :dal AND :al
            GROUP BY data
        ");
        $st->execute([':w' => $worksiteId, ':dal' => $dal, ':al' => $al]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $per[$r['data']] ??= ['data' => $r['data'], 'persone' => 0, 'giornate' => 0.0];
            $per[$r['data']]['persone']  += (int)$r['persone'];
            $per[$r['data']]['giornate'] += (float)$r['giornate'];
        }
        $st = $this->conn->prepare("
            SELECT data_presenza AS data, SUM(quantita) AS persone
            FROM   bb_presenze_consorziate WHERE worksite_id = :w AND data_presenza BETWEEN :dal AND :al
            GROUP BY data_presenza
        ");
        $st->execute([':w' => $worksiteId, ':dal' => $dal, ':al' => $al]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $per[$r['data']] ??= ['data' => $r['data'], 'persone' => 0, 'giornate' => 0.0];
            $per[$r['data']]['persone']  += (int)$r['persone'];
            $per[$r['data']]['giornate'] += (float)$r['persone'];
        }
        ksort($per);
        return array_values($per);
    }

    private function risposte(int $worksiteId, string $dal, string $al): array
    {
        $st = $this->conn->prepare("
            SELECT s.id, s.template_name, s.submitter_name, s.submitted_by, s.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS user_name
            FROM   bb_zone_form_submissions s LEFT JOIN bb_users u ON u.id = s.submitted_by
            WHERE  s.worksite_id = :w AND DATE(s.created_at) BETWEEN :dal AND :al
            ORDER BY s.created_at
        ");
        $st->execute([':w' => $worksiteId, ':dal' => $dal, ':al' => $al]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cliente(int $clientId): string
    {
        if ($clientId <= 0) return '';
        $st = $this->conn->prepare('SELECT name FROM bb_clients WHERE id = :id');
        $st->execute([':id' => $clientId]);
        return (string)($st->fetchColumn() ?: '');
    }

    // ── Pezzi ───────────────────────────────────────────────────────────────

    private function riquadro(string $numero, string $cosa, string $colore): string
    {
        return "<td><div class='n' style='color:{$colore}'>{$numero}</div><div class='l'>{$cosa}</div></td>";
    }

    private function attivitaHtml(array $t, callable $filtraFoto, bool $conFoto): string
    {
        $e = static fn($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        $stato  = (string)($t['status'] ?? 'open');
        $oggi   = date('Y-m-d');
        $meta   = [];
        $chi = !empty($t['assignee_name']) ? $t['assignee_name'] : match ((string)($t['assegnata_a'] ?? '')) {
            'ufficio' => 'CSM', 'cliente' => 'Committente', 'persona' => '', default => 'Squadra',
        };
        if ($chi !== '') $meta[] = 'Assegnata a ' . $e($chi);
        if (!empty($t['due_date'])) {
            $tardi  = !in_array($stato, ['complete', 'verified'], true) && substr((string)$t['due_date'], 0, 10) < $oggi;
            $meta[] = ($tardi ? "<b class='rosso'>in ritardo, " : '') . 'scadenza ' . date('d/m/Y', strtotime((string)$t['due_date'])) . ($tardi ? '</b>' : '');
        }
        if (!empty($t['category'])) $meta[] = $e($t['category']);
        if (in_array($stato, ['complete', 'verified'], true) && !empty($t['updated_at'])) {
            $meta[] = 'chiusa il ' . date('d/m/Y', strtotime((string)$t['updated_at']));
        }

        $h  = "<div class='att'><span class='badge' style='background:" . (self::COLORE[$stato] ?? '#64748b') . "'>"
            . (self::STATO[$stato] ?? $e($stato)) . "</span> <b>" . $e($t['name'] ?? '') . "</b>";
        if ($meta) $h .= "<div class='meta'>" . implode(' · ', $meta) . "</div>";
        if (!empty($t['description'])) {
            $h .= "<div class='desc'>" . nl2br($e(mb_substr((string)$t['description'], 0, 600))) . "</div>";
        }
        if ($conFoto) {
            $foto = array_slice($filtraFoto($t['photos'] ?? []), 0, self::FOTO_PER_ATTIVITA);
            if ($foto) {
                $h .= "<div class='foto'>";
                foreach ($foto as $p) {
                    $dati = $this->fotoDataUri((string)($p['file_url'] ?? ''));
                    if ($dati) $h .= "<img src='{$dati}'>";
                }
                $h .= "</div>";
            }
        }
        return $h . "</div>";
    }

    private function css(): string
    {
        return '
            * { font-family: "DejaVu Sans", sans-serif; }
            body { color:#1e293b; font-size:10.5px; }
            .testa { border-bottom:3px solid #1E40AF; padding-bottom:8px; margin-bottom:12px; }
            .marchio { color:#1E40AF; font-size:9px; font-weight:bold; letter-spacing:.5px; text-transform:uppercase; }
            h1 { font-size:19px; margin:2px 0 0; color:#0C1929; }
            .cantiere { font-size:13px; font-weight:bold; margin-top:2px; }
            .sub { color:#64748b; font-size:9px; margin-top:3px; }
            .breve { width:100%; border-collapse:separate; border-spacing:5px; margin:0 -5px 6px; }
            .breve td { background:#f1f5f9; border-radius:6px; padding:7px 4px; text-align:center; width:16.6%; }
            .breve .n { font-size:17px; font-weight:bold; }
            .breve .l { font-size:8.5px; color:#475569; }
            .sez { font-size:13px; font-weight:bold; color:#0C1929; margin:14px 0 6px; padding-bottom:3px; border-bottom:1.5px solid #e2e8f0; }
            .att { border:1px solid #e2e8f0; border-radius:6px; padding:6px 9px; margin-bottom:6px; page-break-inside:avoid; }
            .badge { display:inline-block; padding:1px 7px; border-radius:9px; color:#fff; font-size:8.5px; }
            .meta { color:#64748b; font-size:9px; margin-top:2px; }
            .desc { margin-top:3px; font-size:9.5px; }
            .foto img { width:118px; height:auto; border:1px solid #cbd5e1; border-radius:4px; margin:4px 4px 0 0; }
            .tab { width:100%; border-collapse:collapse; }
            .tab th { text-align:left; font-size:9px; color:#475569; border-bottom:1px solid #cbd5e1; padding:3px 4px; }
            .tab td { font-size:9.5px; border-bottom:1px solid #eef2f7; padding:3px 4px; vertical-align:top; }
            .tab .tot td { font-weight:bold; border-top:1.5px solid #cbd5e1; }
            .vuoto { color:#94a3b8; font-size:9.5px; margin:2px 0 6px; }
            .rosso { color:#dc2626; }
            .piede { margin-top:18px; color:#94a3b8; font-size:8px; border-top:1px solid #e2e8f0; padding-top:5px; }
        ';
    }

    /** Legge la foto dal disco e la restituisce come data URI (rimpicciolita se c'e' GD). */
    private function fotoDataUri(string $fileUrl): ?string
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

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $raw = @file_get_contents($real);
            $img = $raw ? @imagecreatefromstring($raw) : false;
            if ($img !== false) {
                $w = imagesx($img); $hh = imagesy($img);
                $scale = min(1, self::FOTO_PX / max($w, $hh));
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
        $bin = @file_get_contents($real);
        if ($bin === false) return null;
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
}
