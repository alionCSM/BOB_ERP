<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Da righe di database a una risposta che si legge.
 *
 * Questa parte non passa dal modello, ed e' una scelta: formattare una
 * tabella e' un lavoro deterministico, e farlo riscrivere a un LLM costa
 * secondi di generazione, puo' sbagliare i numeri e non e' ripetibile. Il
 * modello fa l'unica cosa che solo lui sa fare — capire la domanda e
 * scrivere la query — e il resto lo fa il codice.
 *
 * Prima succedeva una cosa curiosa: al modello si chiedeva di scrivere anche
 * la risposta in italiano, lui la scriveva, e poi veniva buttata via senza
 * guardarla perche' la tabella la rigenerava il PHP. Si pagava la
 * generazione di un testo che nessuno leggeva. Ora al modello si chiede un
 * titolo di una riga, e quel titolo diventa la frase di apertura.
 */
final class Risposta
{
    /** Colonne tecniche che non interessano a nessuno in una risposta. */
    private const COLONNE_NASCOSTE = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'uuid', 'uid',
        'created_by', 'updated_by', 'password', 'remember_token', 'token',
        'is_draft', 'removed',
    ];

    /**
     * Nomi che non possono essere altro che soldi. Usati come ultima rete
     * per chi non ha il permesso `view_prices` — di proposito piu' corti
     * dell'elenco della GuardiaSql, che contiene anche parole ambigue come
     * "totale".
     */
    private const SOLO_SOLDI = [
        'importo', 'costo', 'costo_unitario', 'prezzo', 'prezzo_persona',
        'ricavo', 'margine', 'budget', 'imponibile', 'totale_imponibile',
        'ext_total_offer', 'total_offer',
    ];

    /** Oltre questo numero di colonne la tabella diventa illeggibile. */
    private const MAX_COLONNE = 8;

    /** Caratteri per cella: oltre, si taglia. */
    private const MAX_CELLA = 60;

    /**
     * @param list<string>              $colonne
     * @param list<array<string,mixed>> $righe
     * @return array{testo:string, riassunto:string}
     */
    public static function formatta(
        string $titolo,
        string $tipo,
        array $colonne,
        array $righe,
        bool $vedePrezzi,
    ): array {
        $titolo = trim($titolo) !== '' ? trim($titolo) : 'Risultati';
        $quante = count($righe);

        if ($quante === 0) {
            return [
                'testo' => "**{$titolo}** — nessun risultato.\n\n"
                         . "_Se te ne aspettavi qualcuno: controlla il periodo, il nome esatto "
                         . "(cerco per corrispondenza, non per somiglianza) e se la riga e' ancora attiva._",
                'riassunto' => "{$titolo}: nessun risultato.",
            ];
        }

        $mostrate = self::colonneDaMostrare($colonne, $vedePrezzi);

        // Un numero solo: la risposta e' il numero, non una tabella con una
        // cella dentro. E' il caso piu' frequente ("quanti...?") ed e' quello
        // in cui prima si rispondeva peggio.
        if ($quante === 1 && count($mostrate) === 1) {
            $valore = self::valore($righe[0][$mostrate[0]] ?? null);
            return [
                'testo'     => "**{$titolo}:** {$valore}",
                'riassunto' => "{$titolo}: {$valore}",
            ];
        }

        // Una riga sola, piu' colonne: si legge meglio in verticale.
        if ($quante === 1) {
            $pezzi = ["**{$titolo}**", ''];
            foreach ($mostrate as $col) {
                $pezzi[] = '**' . self::etichetta($col) . ':** ' . self::valore($righe[0][$col] ?? null);
            }

            return [
                'testo'     => implode("\n", $pezzi),
                'riassunto' => "{$titolo}: 1 risultato.",
            ];
        }

        // Piu' righe: tabella.
        $testo  = "**{$titolo}** — {$quante} righe\n\n";
        $testo .= '| ' . implode(' | ', array_map([self::class, 'etichetta'], $mostrate)) . " |\n";
        $testo .= '| ' . implode(' | ', array_fill(0, count($mostrate), '---')) . " |\n";

        foreach ($righe as $riga) {
            $celle = [];
            foreach ($mostrate as $col) {
                $celle[] = self::cella($riga[$col] ?? null);
            }
            $testo .= '| ' . implode(' | ', $celle) . " |\n";
        }

        $nascoste = count($colonne) - count($mostrate);
        if ($nascoste > 0) {
            $testo .= "\n_({$nascoste} colonne in piu' non mostrate)_";
        }
        if ($quante >= GuardiaSql::MAX_RIGHE) {
            $testo .= "\n_(fermato a " . GuardiaSql::MAX_RIGHE . " righe: chiedi un periodo o un filtro piu' stretto)_";
        }

        return [
            'testo'     => $testo,
            'riassunto' => "{$titolo}: {$quante} righe (" . implode(', ', $mostrate) . ').',
        ];
    }

    /**
     * Le colonne da mettere nella tabella.
     *
     * Il filtro sui dati economici qui e' l'ultima rete, non il controllo
     * vero: chi non ha il permesso non arriva fin qui, perche' la GuardiaSql
     * rifiuta la query prima di eseguirla (e rifiuta anche SELECT *, che era
     * il modo di far uscire gli importi senza nominarli).
     *
     * Per questo la rete usa solo i nomi che non possono essere altro che
     * soldi, e non l'elenco intero della guardia: dentro quello ci sono
     * `total` e `totale`, e un `COUNT(*) AS totale` su "quanti cantieri
     * abbiamo" sarebbe finito nascosto perche' si chiama totale.
     *
     * @param list<string> $colonne
     * @return list<string>
     */
    private static function colonneDaMostrare(array $colonne, bool $vedePrezzi): array
    {
        $nascondi = array_map('strtolower', self::COLONNE_NASCOSTE);
        if (!$vedePrezzi) {
            $nascondi = array_merge($nascondi, self::SOLO_SOLDI);
        }

        $tenute = array_values(array_filter(
            $colonne,
            fn(string $c) => !in_array(strtolower($c), $nascondi, true)
        ));

        // Se togliendo le tecniche non resta niente, meglio mostrarle che
        // dare una tabella vuota a chi ha chiesto qualcosa.
        if ($tenute === []) {
            $tenute = $colonne;
        }

        return array_slice($tenute, 0, self::MAX_COLONNE);
    }

    /** `nome_cantiere` → `Nome cantiere` */
    private static function etichetta(string $colonna): string
    {
        $t = str_replace('_', ' ', $colonna);
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    private static function valore(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        $s = (string)$v;

        // Gli importi si scrivono come li scrive un italiano: 12.500,50.
        //
        // Solo i valori con la parte decimale, e qui la riga sottile e'
        // importante: i numeri interi NON si toccano. Un codice cantiere
        // 2026001, un numero di protocollo, un anno, un codice fiscale
        // numerico sono tutti interi, e scriverli 2.026.001 e' sbagliato in
        // un modo che fa sembrare rotto il programma. Un importo che viene
        // da una colonna DECIMAL ha sempre i decimali, quindi non si perde
        // niente a essere prudenti.
        if (preg_match('/^-?\d+\.\d+$/', $s)) {
            return number_format((float)$s, 2, ',', '.');
        }

        return $s;
    }

    private static function cella(mixed $v): string
    {
        $s = self::valore($v);
        if (mb_strlen($s) > self::MAX_CELLA) {
            $s = mb_substr($s, 0, self::MAX_CELLA - 1) . '…';
        }

        // Il pipe spezzerebbe la tabella markdown; i ritorni a capo dentro
        // una cella pure.
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $s);
    }
}
