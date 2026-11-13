<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Le richieste fatte nelle cartelle del link documenti (Documenti azienda,
 * Documenti operai, Altri documenti).
 *
 * Chi segue i documenti di operai e aziende lavora in "Doc condivisi" e in
 * Documenti, non nella BOB Zone. Per questo:
 *   - una richiesta in una di quelle cartelle arriva a chi ha il permesso
 *     Doc condivisi, in BOB e per email, e porta alla pagina del link;
 *   - dalla pagina del link la si segna come completata, con una risposta;
 *   - quando in BOB si carica un documento nuovo per quell'azienda o quel
 *     operaio, le richieste aperte nella sua cartella si completano da sole.
 */
final class RichiesteLink
{
    public const CARTELLE = [LinkDocumenti::AZIENDE, LinkDocumenti::AZIENDA, LinkDocumenti::OPERAI, LinkDocumenti::OPERAIO, LinkDocumenti::ALTRI];

    public function __construct(private PDO $conn, private NotificationService $notifiche) {}

    /** Chi segue i documenti condivisi: il permesso Doc condivisi. @return array<int, array> */
    public function gestori(): array
    {
        try {
            return $this->conn->query("
                SELECT DISTINCT u.id, u.email, u.first_name, u.lingua
                FROM bb_users u
                WHERE u.active = 'Y' AND u.removed = 'N'
                  AND u.id IN (
                      SELECT user_id FROM bb_user_permissions WHERE module = 'share' AND allowed = 1
                      UNION
                      SELECT user_id FROM bb_user_company_permissions WHERE module = 'share' AND allowed = 1
                  )
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /** La richiesta con la sua cartella, se la cartella e' del link. */
    public function richiesta(int $id): ?array
    {
        $st = $this->conn->prepare("
            SELECT r.*, f.name AS cartella, f.sorgente, f.sorgente_id, w.name AS cantiere, w.zone_share_link_id AS link_id,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS creata_da_nome,
                   (SELECT m.testo FROM bb_zone_richieste_messaggi m WHERE m.richiesta_id = r.id AND m.sistema IS NULL ORDER BY m.id LIMIT 1) AS messaggio
            FROM bb_zone_richieste r
            JOIN bb_zone_folders f ON f.id = r.folder_id
            JOIN bb_worksites w ON w.id = r.worksite_id
            LEFT JOIN bb_users u ON u.id = r.created_by
            WHERE r.id = :id
        ");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r && in_array($r['sorgente'], self::CARTELLE, true) ? $r : null;
    }

    /** A chi gestisce i documenti condivisi: notifica in BOB ed email. */
    public function avvisaGestori(int $id, int $daChi): void
    {
        $r = $this->richiesta($id);
        if (!$r) {
            return;
        }
        $titolo = 'Documento richiesto';
        $testo  = $r['cantiere'] . ' · ' . $r['cartella'] . ': ' . $r['creata_da_nome'] . ' chiede «' . $r['titolo'] . '»'
                . (trim((string)$r['messaggio']) !== '' ? "\n" . mb_substr((string)$r['messaggio'], 0, 300) : '');
        $link   = $r['link_id'] ? '/share/' . (int)$r['link_id'] . '/edit#richieste' : '/share';
        foreach ($this->gestori() as $g) {
            if ((int)$g['id'] === $daChi) continue;
            try {
                $this->notifiche->create((int)$g['id'], $titolo, $testo, $link, 'documenti', 'high', $daChi ?: null);
            } catch (\Throwable $e) {
                error_log('[Richieste link] ' . $e->getMessage());
            }
            $this->email($g, $titolo, $testo, $link);
        }
    }

    /** L'email a chi gestisce i documenti (lavora in BOB, ma non nella Zone). */
    private function email(array $g, string $titolo, string $testo, string $link): void
    {
        if (!filter_var((string)($g['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $h = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
            $mailer = new \App\Service\Mailer();
            $mailer->setSender('system');
            $mail = $mailer->getMailer();
            $mail->addAddress((string)$g['email']);
            $mail->Subject = 'BOB – ' . $titolo;
            $url = \App\Service\EmailBob::sito() . $link;
            $nome = trim((string)($g['first_name'] ?? ''));
            $mail->Body = \App\Service\EmailBob::pagina(
                $titolo,
                '<p style="margin:0 0 12px">' . ($nome !== '' ? 'Buongiorno ' . $h($nome) . ',' : 'Buongiorno,') . '</p>'
                . '<div style="padding:14px 16px;background:#F8FAFC;border-left:4px solid #1E40AF;border-radius:8px">' . nl2br($h($testo)) . '</div>'
                . '<p style="margin:12px 0 0">La richiesta si chiude da sola quando il documento viene caricato in BOB; in alternativa si segna come completata dalla pagina del link.</p>',
                ['Apri il link in BOB', $url],
                'Ricevi questa email perché gestisci i documenti condivisi in BOB.'
            );
            $mail->AltBody = $titolo . "\n\n" . $testo . "\n\n" . $url;
            $mail->send();
        } catch (\Throwable $e) {
            error_log('[Richieste link email] ' . $e->getMessage());
        }
    }

    /**
     * Le richieste aperte nelle cartelle del link, di tutti i cantieri a cui
     * e' collegato.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aperteDelLink(int $linkId): array
    {
        $segni = implode(',', array_map(fn($s) => $this->conn->quote($s), self::CARTELLE));
        $st = $this->conn->prepare("
            SELECT r.id, r.titolo, r.created_at, f.name AS cartella, f.sorgente, f.sorgente_id, w.id AS worksite_id, w.name AS cantiere,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS creata_da_nome,
                   (SELECT m.testo FROM bb_zone_richieste_messaggi m WHERE m.richiesta_id = r.id AND m.sistema IS NULL ORDER BY m.id LIMIT 1) AS messaggio
            FROM bb_zone_richieste r
            JOIN bb_zone_folders f ON f.id = r.folder_id
            JOIN bb_worksites w ON w.id = r.worksite_id AND w.zone_share_link_id = :l
            LEFT JOIN bb_users u ON u.id = r.created_by
            WHERE r.stato = 'aperta' AND f.sorgente IN ($segni)
            ORDER BY r.created_at DESC
        ");
        $st->execute([':l' => $linkId]);
        return array_map(function ($r) {
            $r['documenti_url'] = match ($r['sorgente']) {
                LinkDocumenti::AZIENDA => '/companies/' . (int)$r['sorgente_id'] . '/edit',
                LinkDocumenti::OPERAIO => '/users/' . (int)$r['sorgente_id'] . '/edit',
                default                => null,
            };
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, int> link => richieste aperte */
    public function contaPerLink(): array
    {
        $segni = implode(',', array_map(fn($s) => $this->conn->quote($s), self::CARTELLE));
        $fuori = [];
        try {
            foreach ($this->conn->query("
                SELECT w.zone_share_link_id AS l, COUNT(*) AS n
                FROM bb_zone_richieste r JOIN bb_zone_folders f ON f.id = r.folder_id JOIN bb_worksites w ON w.id = r.worksite_id
                WHERE r.stato = 'aperta' AND f.sorgente IN ($segni) AND w.zone_share_link_id IS NOT NULL
                GROUP BY w.zone_share_link_id
            ")->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $fuori[(int)$x['l']] = (int)$x['n'];
            }
        } catch (\PDOException $e) {
            // tabelle della Zone non ancora create
        }
        return $fuori;
    }

    /**
     * Da chiamare dopo aver caricato (o sostituito) un documento di un
     * operaio o di un'azienda in BOB. Best-effort: il caricamento e' gia'
     * salvato, una richiesta che non si chiude non lo deve far fallire.
     *
     * @param int|null $docId il documento; null = l'ultimo caricato per quell'operaio/azienda
     */
    public static function dopoDocumento(PDO $conn, string $chi, int $entita, ?int $docId, int $daChi): void
    {
        try {
            if ($chi === 'azienda') {
                $sql = $docId ? 'SELECT company_id AS e, tipo_documento AS t, scadenza AS s FROM bb_company_documents WHERE id = :x'
                              : 'SELECT company_id AS e, tipo_documento AS t, scadenza AS s FROM bb_company_documents WHERE company_id = :x ORDER BY id DESC LIMIT 1';
            } else {
                $sql = $docId ? 'SELECT worker_id AS e, tipo_documento AS t, scadenza AS s FROM bb_worker_documents WHERE id = :x'
                              : 'SELECT worker_id AS e, tipo_documento AS t, scadenza AS s FROM bb_worker_documents WHERE worker_id = :x ORDER BY id DESC LIMIT 1';
            }
            $st = $conn->prepare($sql);
            $st->execute([':x' => $docId ?: $entita]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d) {
                return;
            }
            $notifiche = new NotificationService($conn, new \App\Infrastructure\Config());
            (new self($conn, $notifiche))->documentoCaricato(
                $chi, (int)$d['e'], (string)$d['t'], $d['s'] ?: null, $daChi, new AvvisiZona($conn, $notifiche)
            );
        } catch (\Throwable $e) {
            error_log('[Richieste link documento] ' . $e->getMessage());
        }
    }

    /** Completa una richiesta del link, con una risposta facoltativa; avvisa chi l'aveva fatta. */
    public function completa(int $id, int $daChi, string $risposta, AvvisiZona $avvisi): void
    {
        $svc = new Richieste($this->conn);
        if (trim($risposta) !== '') {
            $svc->scrivi($id, $daChi, $risposta, []);
        }
        $svc->cambiaStato($id, Richieste::COMPLETATA, $daChi);
        $avvisi->richiestaCompletata($id, $daChi);
    }

    /**
     * Un documento nuovo caricato in BOB per un'azienda o un operaio: le
     * richieste aperte nella sua cartella del link si completano, con la riga
     * che dice quale documento e' arrivato.
     *
     * @param string $chi 'azienda' | 'operaio'
     * @return int quante richieste sono state completate
     */
    public function documentoCaricato(string $chi, int $id, string $tipoDocumento, ?string $scadenza, int $daChi, AvvisiZona $avvisi): int
    {
        $sorgente = $chi === 'azienda' ? LinkDocumenti::AZIENDA : LinkDocumenti::OPERAIO;
        try {
            $st = $this->conn->prepare("
                SELECT r.id FROM bb_zone_richieste r JOIN bb_zone_folders f ON f.id = r.folder_id
                WHERE r.stato = 'aperta' AND f.sorgente = :s AND f.sorgente_id = :i
            ");
            $st->execute([':s' => $sorgente, ':i' => $id]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            return 0;
        }
        $riga = 'Documento aggiornato in BOB: ' . $tipoDocumento
              . ($scadenza ? ', scadenza ' . date('d/m/Y', strtotime($scadenza)) : '') . '.';
        foreach ($ids as $rid) {
            try {
                $this->completa($rid, $daChi, $riga, $avvisi);
            } catch (\Throwable $e) {
                error_log('[Richieste link auto] ' . $e->getMessage());
            }
        }
        return count($ids);
    }
}
