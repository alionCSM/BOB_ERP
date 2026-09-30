<?php

declare(strict_types=1);

namespace App\Service;

/**
 * I testi che gli operai leggono, nella loro lingua.
 *
 * Solo quello che arriva sul telefono di un operaio: notifiche e poco altro.
 * BOB resta in italiano — chi lo usa sta in ufficio e l'italiano lo parla —
 * e tradurre tutto il gestionale per cinque lingue sarebbe un lavoro
 * enorme che non serve a nessuno.
 *
 * Un array e non un file di traduzioni per lingua: sono una decina di frasi,
 * e tenerle una sotto l'altra fa vedere a colpo d'occhio se ne manca una.
 * Quando saranno cinquanta varra' la pena spostarle.
 *
 * Il moldavo e il rumeno sono la stessa lingua con due nomi: i testi sono
 * identici e "mo" ricade su "ro". Restano due voci distinte perche' e' cosi'
 * che le persone si riconoscono, e obbligare un moldavo a scegliere "rumeno"
 * e' un modo gratuito di dargli fastidio.
 */
final class Lingua
{
    public const DISPONIBILI = [
        'it' => 'Italiano',
        'en' => 'English',
        'sq' => 'Shqip',
        'ro' => 'Romana',
        'mo' => 'Moldoveneasca',
    ];

    private const RIPIEGO = 'it';

    /**
     * @var array<string, array<string, string>>
     */
    private const TESTI = [
        'it' => [
            'promemoria_titolo'    => 'Presenza da segnare',
            'promemoria_primo'     => 'Non hai ancora segnato la presenza di oggi. Bastano pochi secondi.',
            'promemoria_secondo'   => 'Ultimo promemoria: la presenza di oggi non risulta ancora segnata.',
            'presenza_approvata'   => 'La presenza del {data} e\' stata approvata.',
            'presenza_rifiutata'   => 'La presenza del {data} e\' stata rifiutata: {motivo}',
            'ferie_approvate'      => 'La richiesta di ferie e\' stata approvata.',
            'ferie_rifiutate'      => 'La richiesta di ferie e\' stata rifiutata: {motivo}',
        ],
        'en' => [
            'promemoria_titolo'    => 'Attendance missing',
            'promemoria_primo'     => 'You have not logged today\'s attendance yet. It only takes a moment.',
            'promemoria_secondo'   => 'Last reminder: today\'s attendance is still not logged.',
            'presenza_approvata'   => 'Your attendance for {data} has been approved.',
            'presenza_rifiutata'   => 'Your attendance for {data} was rejected: {motivo}',
            'ferie_approvate'      => 'Your leave request has been approved.',
            'ferie_rifiutate'      => 'Your leave request was rejected: {motivo}',
        ],
        'sq' => [
            'promemoria_titolo'    => 'Prezenca mungon',
            'promemoria_primo'     => 'Nuk e ke shenuar ende prezencen e sotme. Merr vetem pak sekonda.',
            'promemoria_secondo'   => 'Kujtesa e fundit: prezenca e sotme ende nuk eshte shenuar.',
            'presenza_approvata'   => 'Prezenca e dates {data} u aprovua.',
            'presenza_rifiutata'   => 'Prezenca e dates {data} u refuzua: {motivo}',
            'ferie_approvate'      => 'Kerkesa jote per pushime u aprovua.',
            'ferie_rifiutate'      => 'Kerkesa jote per pushime u refuzua: {motivo}',
        ],
        'ro' => [
            'promemoria_titolo'    => 'Prezenta lipseste',
            'promemoria_primo'     => 'Nu ai inregistrat inca prezenta de azi. Dureaza doar cateva secunde.',
            'promemoria_secondo'   => 'Ultima reamintire: prezenta de azi nu este inca inregistrata.',
            'presenza_approvata'   => 'Prezenta din {data} a fost aprobata.',
            'presenza_rifiutata'   => 'Prezenta din {data} a fost respinsa: {motivo}',
            'ferie_approvate'      => 'Cererea ta de concediu a fost aprobata.',
            'ferie_rifiutate'      => 'Cererea ta de concediu a fost respinsa: {motivo}',
        ],
    ];

    /** La lingua di un utente, ridotta a una che esiste davvero. */
    public static function normalizza(?string $lingua): string
    {
        $l = strtolower(trim((string)$lingua));

        // moldavo e rumeno sono la stessa lingua: stessi testi
        if ($l === 'mo') {
            return 'ro';
        }
        return isset(self::TESTI[$l]) ? $l : self::RIPIEGO;
    }

    /**
     * Un testo nella lingua chiesta.
     *
     * Se manca, si ripiega sull'italiano invece di restituire la chiave: una
     * notifica che dice "promemoria_primo" e' peggio di una in una lingua
     * sbagliata, perche' sembra un guasto.
     *
     * @param array<string, string|int> $valori sostituzioni per {segnaposto}
     */
    public static function testo(string $chiave, ?string $lingua, array $valori = []): string
    {
        $l = self::normalizza($lingua);

        $testo = self::TESTI[$l][$chiave]
              ?? self::TESTI[self::RIPIEGO][$chiave]
              ?? $chiave;

        foreach ($valori as $nome => $valore) {
            $testo = str_replace('{' . $nome . '}', (string)$valore, $testo);
        }
        return $testo;
    }
}
