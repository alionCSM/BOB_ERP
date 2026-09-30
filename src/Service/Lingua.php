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
     * @var array<string, array<string, string|string[]>>
     */
    private const TESTI = [
        'it' => [
            'giorni_settimana'     => ['lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica'],
            'oggi'                 => 'oggi',
            'e_cong'               => 'e',
            'promemoria_titolo'    => 'Presenza da segnare',
            'promemoria_uno'       => 'Ti manca la presenza di {giorni}. Bastano pochi secondi.',
            'promemoria_piu'       => 'Ti mancano le presenze di {giorni}. Bastano pochi secondi.',
            'promemoria_uno_2'     => 'Ultimo promemoria: ti manca ancora la presenza di {giorni}.',
            'promemoria_piu_2'     => 'Ultimo promemoria: ti mancano ancora le presenze di {giorni}.',
            'presenza_approvata'   => 'La presenza del {data} e\' stata approvata.',
            'presenza_rifiutata'   => 'La presenza del {data} e\' stata rifiutata: {motivo}',
            'ferie_approvate'      => 'La richiesta di ferie e\' stata approvata.',
            'ferie_rifiutate'      => 'La richiesta di ferie e\' stata rifiutata: {motivo}',
        ],
        'en' => [
            'giorni_settimana'     => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'oggi'                 => 'today',
            'e_cong'               => 'and',
            'promemoria_titolo'    => 'Attendance missing',
            'promemoria_uno'       => 'You still have to log {giorni}. It only takes a moment.',
            'promemoria_piu'       => 'You still have to log {giorni}. It only takes a moment.',
            'promemoria_uno_2'     => 'Last reminder: {giorni} is still not logged.',
            'promemoria_piu_2'     => 'Last reminder: {giorni} are still not logged.',
            'presenza_approvata'   => 'Your attendance for {data} has been approved.',
            'presenza_rifiutata'   => 'Your attendance for {data} was rejected: {motivo}',
            'ferie_approvate'      => 'Your leave request has been approved.',
            'ferie_rifiutate'      => 'Your leave request was rejected: {motivo}',
        ],
        'sq' => [
            'giorni_settimana'     => ['e hene', 'e marte', 'e merkure', 'e enjte', 'e premte', 'e shtune', 'e diel'],
            'oggi'                 => 'sot',
            'e_cong'               => 'dhe',
            'promemoria_titolo'    => 'Prezenca mungon',
            'promemoria_uno'       => 'Te mungon prezenca: {giorni}. Merr vetem pak sekonda.',
            'promemoria_piu'       => 'Te mungojne prezencat: {giorni}. Merr vetem pak sekonda.',
            'promemoria_uno_2'     => 'Kujtesa e fundit: te mungon ende prezenca: {giorni}.',
            'promemoria_piu_2'     => 'Kujtesa e fundit: te mungojne ende prezencat: {giorni}.',
            'presenza_approvata'   => 'Prezenca e dates {data} u aprovua.',
            'presenza_rifiutata'   => 'Prezenca e dates {data} u refuzua: {motivo}',
            'ferie_approvate'      => 'Kerkesa jote per pushime u aprovua.',
            'ferie_rifiutate'      => 'Kerkesa jote per pushime u refuzua: {motivo}',
        ],
        'ro' => [
            'giorni_settimana'     => ['luni', 'marti', 'miercuri', 'joi', 'vineri', 'sambata', 'duminica'],
            'oggi'                 => 'azi',
            'e_cong'               => 'si',
            'promemoria_titolo'    => 'Prezenta lipseste',
            'promemoria_uno'       => 'Iti lipseste prezenta de {giorni}. Dureaza doar cateva secunde.',
            'promemoria_piu'       => 'Iti lipsesc prezentele de {giorni}. Dureaza doar cateva secunde.',
            'promemoria_uno_2'     => 'Ultima reamintire: iti lipseste inca prezenta de {giorni}.',
            'promemoria_piu_2'     => 'Ultima reamintire: iti lipsesc inca prezentele de {giorni}.',
            'presenza_approvata'   => 'Prezenta din {data} a fost aprobata.',
            'presenza_rifiutata'   => 'Prezenta din {data} a fost respinsa: {motivo}',
            'ferie_approvate'      => 'Cererea ta de concediu a fost aprobata.',
            'ferie_rifiutate'      => 'Cererea ta de concediu a fost respinsa: {motivo}',
        ],
    ];

    /**
     * Il nome di un giorno della settimana.
     *
     * @param int $iso 1 = lunedi ... 7 = domenica, come date('N')
     */
    public static function giorno(int $iso, ?string $lingua): string
    {
        $l = self::normalizza($lingua);
        $nomi = self::TESTI[$l]['giorni_settimana'] ?? self::TESTI[self::RIPIEGO]['giorni_settimana'];

        return $nomi[$iso - 1] ?? '';
    }

    /**
     * Un elenco di giorni scritto come lo direbbe una persona.
     *
     * "lunedi, martedi e oggi" invece di "2026-09-28, 2026-09-29, 2026-09-30":
     * il promemoria si legge di sfuggita sulla schermata di blocco, e tre date
     * in cifre non dicono niente a nessuno.
     *
     * Il giorno corrente si chiama "oggi" e non col suo nome: e' quello che
     * uno ha in testa la sera, e sentirselo chiamare "mercoledi" costringe a
     * fermarsi un attimo a pensare che giorno e'.
     *
     * @param string[] $date elenco di aaaa-mm-gg, in ordine
     */
    public static function elencoGiorni(array $date, ?string $lingua, string $oggi): string
    {
        $nomi = [];
        foreach ($date as $d) {
            $nomi[] = $d === $oggi
                ? self::testo('oggi', $lingua)
                : self::giorno((int)date('N', strtotime($d)), $lingua);
        }

        if (count($nomi) <= 1) {
            return $nomi[0] ?? '';
        }

        $ultimo = array_pop($nomi);
        return implode(', ', $nomi) . ' ' . self::testo('e_cong', $lingua) . ' ' . $ultimo;
    }

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

        // i giorni della settimana stanno nella stessa tabella ma sono un
        // elenco: chiederli come testo e' un errore di chi chiama, e
        // restituire "Array" sarebbe peggio del silenzio
        if (is_array($testo)) {
            return '';
        }

        foreach ($valori as $nome => $valore) {
            $testo = str_replace('{' . $nome . '}', (string)$valore, $testo);
        }
        return $testo;
    }
}
