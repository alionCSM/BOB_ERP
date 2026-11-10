<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Le email di BOB con la faccia di BOB.
 *
 * Prima ognuna aveva la sua: una senza logo, una col logo a un indirizzo
 * che non esiste, una verde acqua, una grigia. Chi le riceveva — un
 * cliente soprattutto — non capiva che venivano dallo stesso posto.
 * Qui una sola cornice: testata blu notte col logo, come la barra del
 * sito, un riquadro bianco, il bottone blu, due righe in fondo.
 *
 * Fatta a tabelle e stili in linea perche' Outlook e Gmail non leggono
 * altro.
 */
final class EmailBob
{
    private const NOTTE = '#0C1929';
    private const BLU   = '#1E40AF';
    private const TESTO = '#1E293B';
    private const GRIGIO = '#64748B';

    /**
     * La pagina intera.
     *
     * @param string      $titolo  il titolo grande, testo semplice
     * @param string      $corpo   HTML gia' pulito
     * @param array{0:string,1:string}|null $bottone [etichetta, indirizzo]
     * @param string      $piede   HTML gia' pulito, sotto la firma
     * @param string      $dopo    HTML gia' pulito, sotto il bottone
     */
    public static function pagina(string $titolo, string $corpo, ?array $bottone = null, string $piede = '', string $dopo = ''): string
    {
        $h    = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
        $sito = self::sito();
        $font = "'Manrope','Segoe UI',Helvetica,Arial,sans-serif";
        $tit  = "'Space Grotesk','Segoe UI',Helvetica,Arial,sans-serif";

        $btn = '';
        if ($bottone) {
            $btn = '
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 6px">
                <tr><td style="background:' . self::BLU . ';border-radius:10px">
                  <a href="' . $h($bottone[1]) . '" style="display:inline-block;padding:13px 26px;font-family:' . $font . ';font-size:15px;font-weight:700;color:#ffffff;text-decoration:none">' . $h($bottone[0]) . '</a>
                </td></tr>
              </table>';
        }

        return '<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">
<title>' . $h($titolo) . '</title></head>
<body style="margin:0;padding:0;background:#EEF2F7">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F7">
<tr><td align="center" style="padding:28px 12px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
    <tr><td style="background:' . self::NOTTE . ';border-radius:16px 16px 0 0;padding:18px 26px">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="background:#ffffff;border-radius:12px;width:46px;height:46px;text-align:center;vertical-align:middle">
          <img src="' . $h($sito) . '/assets/img/logo.png" width="38" alt="BOB" style="display:block;margin:0 auto;border:0">
        </td>
        <td style="padding-left:14px">
          <div style="font-family:' . $tit . ';font-size:22px;font-weight:700;letter-spacing:.5px;color:#ffffff;line-height:1">BOB</div>
          <div style="font-family:' . $font . ';font-size:12px;color:#93C5FD;padding-top:4px">Consorzio Soluzione Montaggi</div>
        </td>
      </tr></table>
    </td></tr>
    <tr><td style="background:#ffffff;border-radius:0 0 16px 16px;padding:30px 26px 26px;font-family:' . $font . ';font-size:15px;line-height:1.6;color:' . self::TESTO . '">
      <div style="font-family:' . $tit . ';font-size:20px;font-weight:700;color:#0F172A;line-height:1.3;margin:0 0 14px">' . $h($titolo) . '</div>
      ' . $corpo . $btn . $dopo . '
    </td></tr>
    <tr><td style="padding:18px 26px 0;font-family:' . $font . ';font-size:12px;line-height:1.6;color:' . self::GRIGIO . ';text-align:center">
      ' . ($piede !== '' ? $piede . '<br>' : '') . '
      Consorzio Soluzione Montaggi · <a href="' . $h($sito) . '" style="color:' . self::GRIGIO . '">' . $h(preg_replace('~^https?://~', '', $sito)) . '</a> · <a href="mailto:info@csmontaggi.it" style="color:' . self::GRIGIO . '">info@csmontaggi.it</a>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>';
    }

    /** Il codice di accesso, grande e separato, facile da copiare. */
    public static function codice(string $codice): string
    {
        return '<div style="margin:20px 0;padding:18px;background:#EFF6FF;border:2px dashed #93C5FD;border-radius:12px;text-align:center;'
             . 'font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:32px;font-weight:700;letter-spacing:8px;color:' . self::BLU . '">'
             . htmlspecialchars($codice, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * L'email col codice per un accesso da un dispositivo nuovo: la stessa
     * dal sito e dall'app, cambia solo dove va scritto.
     */
    public static function codiceAccesso(string $nome, string $codice, string $ip, string $dispositivo, int $minuti, string $dove): string
    {
        $h = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
        $corpo = '<p style="margin:0 0 4px">' . ($nome !== '' ? 'Ciao <strong>' . $h($nome) . '</strong>,' : 'Ciao,') . '<br>'
               . 'stai entrando in BOB da un dispositivo che non conosciamo. Scrivi questo codice ' . $h($dove) . ':</p>'
               . self::codice($codice)
               . self::dettagli([
                   'Indirizzo IP' => $h($ip),
                   'Dispositivo'  => '<span style="font-size:12px;color:#475569">' . $h(mb_substr($dispositivo, 0, 160)) . '</span>',
                   'Ora'          => date('d/m/Y H:i'),
               ]);
        return self::pagina(
            'Il tuo codice di accesso',
            $corpo,
            null,
            'Il codice scade tra ' . $minuti . ' minuti. Se non sei stato tu, ignora questa email e cambia la password.'
        );
    }

    /**
     * Una riga di dettagli "etichetta: valore" (valori gia' puliti).
     *
     * @param array<string,string> $righe
     */
    public static function dettagli(array $righe): string
    {
        $t = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;width:100%;background:#F8FAFC;border-radius:10px">';
        foreach ($righe as $etichetta => $valore) {
            $t .= '<tr><td style="padding:9px 14px;font-size:13px;color:' . self::GRIGIO . ';white-space:nowrap;vertical-align:top">'
                . htmlspecialchars($etichetta, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:9px 14px;font-size:14px">' . $valore . '</td></tr>';
        }
        return $t . '</table>';
    }

    /** I bottoni degli store, solo quelli che ci sono. */
    public static function app(): string
    {
        $h = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
        $link = [];
        foreach (['Android · Google Play' => AppStore::android(), 'iPhone · App Store' => AppStore::ios()] as $nome => $url) {
            if ($url !== '') {
                $link[] = '<a href="' . $h($url) . '" style="display:inline-block;margin:4px 6px 4px 0;padding:8px 14px;border:1px solid #CBD5E1;border-radius:999px;color:#0F172A;text-decoration:none;font-size:13px;font-weight:600">' . $h($nome) . '</a>';
            }
        }
        return $link ? '<p style="margin:22px 0 0">BOB anche sul telefono:<br>' . implode('', $link) . '</p>' : '';
    }

    public static function sito(): string
    {
        return rtrim((string)($_ENV['APP_URL'] ?? 'https://bob.csmontaggi.it'), '/');
    }
}
