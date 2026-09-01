<?php

declare(strict_types=1);

namespace App\Service\Poti;

/**
 * La giornata dei tecnici, in un formato solo.
 *
 * Autocarrate e mezzi di sollevamento raccontano la stessa storia — cosa
 * esce, cosa rientra, cosa e' ancora fuori — ma le tabelle sotto sono
 * diverse: nelle autocarrate l'unita' e' la prenotazione, nei noleggi e' la
 * singola riga (ogni macchina parte e torna per conto suo). Prima questo
 * costringeva a due pagine gemelle, con due macro quasi identiche destinate
 * a divergere al primo ritocco.
 *
 * Qui le due forme diventano una sola "scheda", e la pagina e' una sola.
 * Chi aggiunge un campo lo aggiunge in un posto.
 *
 * Niente importi: al tecnico serve sapere se e' pagato, non quanto.
 */
final class Giornata
{
    public const AUTOCARRATA = 'autocarrata';
    public const MACCHINA    = 'macchina';

    /**
     * I quattro blocchi della giornata, gia' in schede.
     *
     * L'ordine e' quello in cui servono: prima cio' su cui bisogna fare
     * qualcosa (i ritardi), poi il lavoro del giorno, infine cio' che si
     * guarda e basta.
     *
     * @param array{ritardo:array, escono:array, rientrano:array, fuori:array} $giornata
     * @return array<string, array{etichetta:string, schede:array}>
     */
    /**
     * Gli id di tutte le righe della giornata, blocchi compresi.
     *
     * Serve a chiedere le foto in una volta sola invece che scheda per
     * scheda: la stessa riga non compare in due blocchi, ma l'unique regge
     * comunque se un domani cambiasse.
     *
     * @param array<string, array<int, array<string,mixed>>> $giornata
     * @return int[]
     */
    public static function idRighe(array $giornata): array
    {
        $ids = [];
        foreach ($giornata as $righe) {
            foreach ($righe as $r) {
                if (!empty($r['id'])) {
                    $ids[] = (int)$r['id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, array<int, array<string,mixed>>>> $foto
     *        Tutte le foto della giornata, per id di riga. Arrivano da fuori
     *        gia' pronte: e' l'unico modo di leggerle in una query sola.
     */
    public static function blocchi(array $giornata, string $tipo, array $foto = []): array
    {
        $definizione = [
            'ritardo'   => 'In ritardo',
            'escono'    => 'Escono oggi',
            'rientrano' => 'Rientrano oggi',
            'fuori'     => 'Fuori',
        ];

        $out = [];
        foreach ($definizione as $chiave => $etichetta) {
            $schede = [];
            foreach ($giornata[$chiave] ?? [] as $riga) {
                $schede[] = self::scheda(
                    $riga, $chiave, $tipo, $foto[(int)($riga['id'] ?? 0)] ?? []
                );
            }
            $out[$chiave] = ['etichetta' => $etichetta, 'schede' => $schede];
        }
        return $out;
    }

    /**
     * Una riga di database diventa una scheda.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    /**
     * @param array<string, array<int, array<string,mixed>>> $foto
     *        Le foto di QUESTA riga, gia' divise per momento.
     */
    public static function scheda(array $r, string $blocco, string $tipo, array $foto = []): array
    {
        $autocarrata = $tipo === self::AUTOCARRATA;

        // Sui mezzi di sollevamento si scrive il numero dell'adesivo, che
        // e' quello attaccato sulla macchina e quello che il tecnico legge in
        // piazzale. La matricola resta il ripiego per le macchine non ancora
        // etichettate. Le autocarrate non hanno adesivo: si vanno a targa.
        // Un mezzo preso da un altro noleggiatore non ha ne' adesivo ne'
        // matricola: al suo posto si scrive com'e' fatto. In cantiere e'
        // comunque un mezzo da consegnare e da ritirare come gli altri, e
        // lasciare la scheda senza nome vorrebbe dire non sapere cosa
        // caricare sul camion.
        $mezzo = (string)($autocarrata
            ? ($r['targa'] ?? '')
            : (($r['numero'] ?? '') !== ''   ? $r['numero']
             : (($r['matricola'] ?? '') !== '' ? $r['matricola']
             : ($r['mezzo_esterno'] ?? ''))));

        // sotto la targa: sull'autocarrata basta il modello, sul mezzo di
        // sollevamento serve prima il tipo (piattaforma, telescopico...),
        // che e' quello che il tecnico cerca davvero. Sul mezzo a nolo si
        // scrive chi ce l'ha dato: e' a loro che si telefona se non parte.
        $sotto = $autocarrata
            ? (string)($r['modello'] ?? '')
            : (($r['macchina_id'] ?? null) === null
                ? trim('a nolo' . (($r['fornitore'] ?? '') !== '' ? ' · ' . $r['fornitore'] : ''))
                : trim((string)($r['tipo'] ?? '') . ($r['modello'] ? ' · ' . $r['modello'] : '')));

        // Consegna e rientro riguardano la riga; la firma del contratto
        // riguarda il noleggio intero. Nelle autocarrate coincidono.
        $campi = $autocarrata
            ? ['id' => (int)$r['id']]
            : ['riga_id' => (int)$r['id'], 'noleggio_id' => (int)($r['noleggio_id'] ?? 0)];

        $consegnato = !empty($r['consegnato_at']);
        $rientrato  = !empty($r['rientrato_at']);

        // Ogni blocco ha UNA cosa da fare, ed e' quella che finisce sul
        // pulsante grande. "Fuori" non ne ha: quei mezzi non si muovono oggi.
        $azione = match ($blocco) {
            'escono'               => 'consegnato',
            'rientrano', 'ritardo' => 'rientrato',
            default                => null,
        };
        $fatta = $azione === 'consegnato' ? $consegnato : ($azione === 'rientrato' ? $rientrato : false);

        $telefono = trim((string)($r['telefono'] ?? ''));
        $note     = trim((string)($r['note'] ?? $r['note_noleggio'] ?? ''));

        return [
            'blocco'      => $blocco,
            'mezzo'       => $mezzo,
            'sotto'       => $sotto,
            'cliente'     => (string)($r['cliente'] ?? ''),
            'telefono'    => $telefono,
            // per il link tel: via spazi e punti, il telefono non li digerisce
            'telefonoUrl' => preg_replace('/[^0-9+]/', '', $telefono) ?: '',
            'luogo'       => (string)($r['luogo'] ?? ''),
            'dal'         => (string)($r['data_inizio'] ?? ''),
            'al'          => (string)($r['data_fine'] ?? ''),
            'giorni'      => (int)($r['giorni'] ?? 0),
            'pagata'      => ($r['pagamento'] ?? '') === 'pagata',
            'firmato'     => !empty($r['contratto_firmato']),
            'contratto'   => (string)($r['contratto'] ?? ''),
            'consegnato'  => $consegnato,
            'rientrato'   => $rientrato,
            'consegnatoAt'=> (string)($r['consegnato_at'] ?? ''),
            'rientratoAt' => (string)($r['rientrato_at'] ?? ''),
            // Livello del carburante com'e' stato scritto: tacche,
            // percentuale o litri a seconda del mezzo. Non si normalizza
            // niente — l'unita' la sceglie chi guarda lo strumento.
            'carbUscita'  => (string)($r['carburante_uscita'] ?? ''),
            'carbRientro' => (string)($r['carburante_rientro'] ?? ''),
            // Le foto arrivano gia' raggruppate da chi chiama: qui non si
            // interroga il database, altrimenti una giornata da trenta
            // schede farebbe trenta viaggi per mostrare due miniature.
            'foto'        => $foto,
            'note'        => $note,
            'azione'      => $azione,
            'fatta'       => $fatta,
            'campi'       => $campi,
            // la ricerca dal vivo guarda questa stringa: ci sta dentro tutto
            // quello che un tecnico puo' avere in mente (targa, cliente,
            // paese, numero di contratto)
            'cerca'       => mb_strtolower(trim(implode(' ', array_filter([
                $mezzo, $sotto, $r['cliente'] ?? '', $r['luogo'] ?? '',
                $r['contratto'] ?? '', $telefono,
            ])))),
        ];
    }

    /**
     * Il riepilogo in cima: quanti movimenti ha la giornata e quanti ne
     * restano da fare.
     *
     * "Fuori" non entra nel conto dei movimenti: quei mezzi oggi non si
     * toccano, e contarli farebbe sembrare la giornata piu' pesante di
     * quello che e'.
     *
     * @param array<string, array{etichetta:string, schede:array}> $blocchi
     */
    public static function riepilogo(array $blocchi): array
    {
        $conta = [];
        foreach ($blocchi as $chiave => $b) {
            $conta[$chiave] = count($b['schede']);
        }

        $daFare = 0;
        $fatti  = 0;
        foreach (['ritardo', 'escono', 'rientrano'] as $chiave) {
            foreach ($blocchi[$chiave]['schede'] ?? [] as $s) {
                $daFare++;
                if ($s['fatta']) {
                    $fatti++;
                }
            }
        }

        return [
            'conta'      => $conta,
            'totale'     => array_sum($conta),
            'movimenti'  => $daFare,
            'fatti'      => $fatti,
            'mancanti'   => max(0, $daFare - $fatti),
            'percentuale'=> $daFare > 0 ? (int)round($fatti / $daFare * 100) : 100,
        ];
    }

    /**
     * Le partenze dei prossimi giorni, per preparare i mezzi in anticipo.
     *
     * @param array<string, array<int, array<string,mixed>>> $prossime
     * @return array<string, array<int, array{mezzo:string, cliente:string, luogo:string, giorni:int}>>
     */
    public static function prossime(array $prossime, string $tipo): array
    {
        $autocarrata = $tipo === self::AUTOCARRATA;

        $out = [];
        foreach ($prossime as $giorno => $righe) {
            foreach ($righe as $r) {
                $out[$giorno][] = [
                    'mezzo'   => (string)($autocarrata
                        ? ($r['targa'] ?? '')
                        : (($r['numero'] ?? '') !== ''   ? $r['numero']
                         : (($r['matricola'] ?? '') !== '' ? $r['matricola']
                         : ($r['mezzo_esterno'] ?? '')))),
                    'cliente' => (string)($r['cliente'] ?? ''),
                    'luogo'   => (string)($r['luogo'] ?? ''),
                    'giorni'  => (int)($r['giorni'] ?? 0),
                ];
            }
        }
        return $out;
    }
}
