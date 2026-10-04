<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Attendance\LeaveRepository;
use App\Repository\Attendance\RichiestaPresenzaRepository;
use App\Service\Operaio\Dati;

/**
 * BOB per chi sta in cantiere.
 *
 * Le stesse cose dell'app — dove vado, con chi, le mie presenze, le mie
 * assenze — ma aperte nel browser del telefono. Non e' un ripiego in attesa
 * dell'app: e' quello che funziona su centoquaranta telefoni diversi,
 * Android e iPhone, senza installare niente e senza passare da uno store.
 *
 * I dati li legge da {@see Dati}, gli stessi dell'API: due query uguali in
 * due posti divergono, una si corregge e l'altra no, e per mesi la stessa
 * giornata si legge in due modi a seconda di dove la guardi.
 *
 * Nessun controllo di modulo: un operaio non ne ha nessuno. Conta una cosa
 * sola — sono i tuoi dati? — e la risposta viene dal lavoratore collegato
 * all'utente, mai da un id nella richiesta.
 */
final class OperaioController
{
    public function __construct(private \PDO $conn) {}

    /**
     * Un messaggio nella lingua di chi lo legge.
     *
     * Le pagine sono tradotte: lasciare in italiano proprio la frase che
     * dice cos'e' andato storto vorrebbe dire tradurre tutto tranne la
     * parte che serve capire.
     *
     * @param array<string, string|int> $valori
     */
    private function dire(Request $request, string $chiave, array $valori = []): string
    {
        return \App\Service\Lingua::testo($chiave, $request->user()->lingua ?? null, $valori);
    }

    // ── GET /io ──────────────────────────────────────────────────────────────

    /**
     * La prima schermata: dove vai e con chi.
     *
     * Oggi e domani insieme. La sera si guarda domani, la mattina si guarda
     * oggi, e tenerle sulla stessa pagina evita di far cercare proprio
     * nell'ora in cui uno ha fretta.
     */
    public function oggi(Request $request): void
    {
        $operaio = $this->operaio($request);

        $giorni = (new Dati($this->conn))->pianificazione(
            $operaio,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+1 day'))
        );

        $daDichiarare = (new RichiestaPresenzaRepository($this->conn))
            ->giorniSenzaNiente($operaio, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));

        Response::view('operaio/oggi.html.twig', $request, [
            'pageTitle'    => 'Oggi',
            'giorni'       => $giorni,
            'oggi'         => date('Y-m-d'),
            'daDichiarare' => $daDichiarare,
            'lingue'       => \App\Service\Lingua::DISPONIBILI,
            'linguaOra'    => \App\Service\Lingua::normalizza($request->user()->lingua ?? null),
        ]);
    }

    // ── POST /io/lingua ──────────────────────────────────────────────────────

    /**
     * In che lingua gli parla BOB.
     *
     * Sta in fondo a /io e non dentro il profilo, che e' una pagina
     * dell'ufficio piena di roba che a un operaio non serve. I nomi delle
     * lingue sono scritti ognuno nella sua — Shqip, Romana — cosi' uno li
     * riconosce anche se tutto il resto della pagina e' in una lingua che
     * non capisce. E' il caso di chi apre BOB la prima volta.
     */
    public function cambiaLingua(Request $request): never
    {
        $lingua = strtolower(trim((string)($_POST['lingua'] ?? '')));

        if (isset(\App\Service\Lingua::DISPONIBILI[$lingua])) {
            $stmt = $this->conn->prepare('UPDATE bb_users SET lingua = :l WHERE id = :id');
            $stmt->execute([':l' => $lingua, ':id' => (int)($request->user()->id ?? 0)]);
        }

        Response::redirect('/io');
    }

    // ── GET /io/presenze ─────────────────────────────────────────────────────

    public function presenze(Request $request): void
    {
        $operaio = $this->operaio($request);
        $repo    = new RichiestaPresenzaRepository($this->conn);

        // Un mese per volta, non due a scorrimento: la domanda vera e'
        // "quante giornate ho fatto a settembre", e con un elenco continuo
        // uno si mette a contare a mano.
        $mese = (string)($_GET['mese'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $mese)) {
            $mese = date('Y-m');
        }

        $dal = $mese . '-01';
        $al  = date('Y-m-t', strtotime($dal));

        $righe = $repo->diarioOperaio($operaio, $dal, $al);

        Response::view('operaio/presenze.html.twig', $request, [
            'pageTitle'  => 'Le mie presenze',
            'righe'      => $righe,
            'mese'       => $mese,
            'mesi'       => $repo->mesiConGiornate($operaio),
            'totali'     => $this->totali($righe),
            // I giorni che gli mancano, col cantiere dove risultava: quelli
            // si segnano con un tocco solo. Il modulo serve per il resto.
            'daSegnare'  => $repo->giorniSenzaNiente(
                                $operaio,
                                date('Y-m-d', strtotime('-13 days')),
                                date('Y-m-d')
                            ),
            // i cantieri non si mandano con la pagina: li chiede il
            // telefono quando servono, uno alla volta
            'oggi'       => date('Y-m-d'),
            'successMsg' => $this->presoDallaSessione('success'),
            'errorMsg'   => $this->presoDallaSessione('error'),
        ]);
    }

    // ── GET /io/cantiere-del-giorno ──────────────────────────────────────────

    /**
     * Dove risultava quel giorno, secondo la pianificazione.
     *
     * E' la risposta giusta nove volte su dieci, e chiederla invece di farla
     * cercare cambia tutto: uno apre, vede "quel giorno eri a Via Roma",
     * conferma e ha finito. La ricerca resta per il decimo caso.
     */
    public function cantiereDelGiorno(Request $request): never
    {
        $data = trim((string)($_GET['data'] ?? ''));

        if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            Response::json(['trovato' => false]);
        }

        $giorni = (new Dati($this->conn))
            ->pianificazione($this->operaio($request), $data, $data);

        // Senza worksite_id non si puo' precompilare niente: sono le righe
        // pianificate prima che la commessa fosse aperta, dove il cantiere
        // e' solo un testo scritto a mano.
        foreach ($giorni as $g) {
            if (!empty($g['worksite_id'])) {
                Response::json([
                    'trovato' => true,
                    'id'      => (int)$g['worksite_id'],
                    'codice'  => (string)($g['worksite_code'] ?? ''),
                    'nome'    => (string)($g['cantiere_nome'] ?? ''),
                    'luogo'   => (string)($g['location'] ?? ''),
                ]);
            }
        }

        Response::json(['trovato' => false]);
    }

    // ── GET /io/cantieri ─────────────────────────────────────────────────────

    /**
     * Cerca fra i cantieri aperti.
     *
     * Solo su richiesta e solo dopo che ha scritto qualcosa: mandare
     * trecento cantieri a un telefono perche' uno ha aperto una tendina e'
     * sprecare la linea di chi sta in cantiere, dove va gia' piano.
     */
    public function cercaCantieri(Request $request): never
    {
        $q = trim((string)($_GET['q'] ?? ''));

        if (mb_strlen($q) < 2) {
            Response::json([]);
        }

        $elenchi = (new Dati($this->conn))->cantieri($this->operaio($request), $q);

        Response::json(array_map(static fn(array $c): array => [
            'id'    => (int)$c['id'],
            'testo' => trim(($c['worksite_code'] ?? '') . ' — ' . ($c['name'] ?? '')
                       . ($c['location'] ? ' (' . $c['location'] . ')' : '')),
        ], $elenchi['aperti']));
    }

    // ── POST /io/presenze ────────────────────────────────────────────────────

    /**
     * Dichiara una giornata.
     *
     * Non nasce una presenza: nasce una dichiarazione che l'ufficio guarda,
     * eventualmente corregge e approva. Da bb_presenze escono i costi e le
     * buste paga, e una riga non verificata li' dentro falserebbe i conti di
     * un cantiere finche' qualcuno non se ne accorge.
     */
    public function dichiara(Request $request): never
    {
        $operaio = $this->operaio($request);
        $dati    = new Dati($this->conn);
        $repo    = new RichiestaPresenzaRepository($this->conn);

        $worksiteId = (int)($_POST['worksite_id'] ?? 0);
        $data       = trim((string)($_POST['data'] ?? ''));

        try {
            if (!$worksiteId || $data === '') {
                throw new RuntimeException($this->dire($request, 'msg_scegli_tutto'));
            }
            // Si dichiara quello che si e' fatto, non quello che si fara':
            // una giornata futura non e' una presenza, e' un proposito.
            if ($data > date('Y-m-d')) {
                throw new RuntimeException($this->dire($request, 'msg_giorno_futuro'));
            }
            // L'unico limite su quale cantiere: non quelli assegnati, che
            // sono un'altra cosa, ma uno aperto. Il middleware lascia
            // passare la scelta proprio perche' il controllo e' qui.
            if (!$dati->cantiereAperto($worksiteId)) {
                throw new RuntimeException($this->dire($request, 'msg_cantiere_chiuso'));
            }
            if ($repo->giaInAttesa($operaio, $data, $worksiteId)) {
                throw new RuntimeException($this->dire($request, 'msg_gia_mandata'));
            }

            $repo->crea($operaio, [
                'worksite_id' => $worksiteId,
                'data'        => $data,
                'turno'       => (string)($_POST['turno'] ?? 'Intero'),
                'pranzo'      => $dati->chiHaPagato($_POST['pranzo'] ?? ''),
                'cena'        => $dati->chiHaPagato($_POST['cena'] ?? ''),
                'hotel'       => trim((string)($_POST['hotel'] ?? '')),
                'targa_auto'  => trim((string)($_POST['targa_auto'] ?? '')),
                'trasferta'   => !empty($_POST['trasferta']),
                'note'        => trim((string)($_POST['note'] ?? '')),
            ]);

            // Nomina il giorno: "fatto" non dice se e' andata quella
            // giusta, e chi ne manda tre di fila non ha modo di saperlo.
            $_SESSION['success'] = $this->dire($request, 'msg_mandata', [
                'data' => date('d/m/Y', strtotime($data)),
            ]);
        } catch (\RuntimeException $e) {
            // i messaggi che scriviamo noi sono gia' nella sua lingua
            $_SESSION['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            // tutto il resto e' un guasto: la frase attorno almeno si
            // capisce, anche se il dettaglio resta tecnico e in inglese
            $_SESSION['error'] = $this->dire($request, 'msg_errore', ['errore' => $e->getMessage()]);
        }

        Response::redirect('/io/presenze');
    }

    // ── POST /io/presenze/{id}/ritira ────────────────────────────────────────

    public function ritira(Request $request): never
    {
        $operaio = $this->operaio($request);
        $id      = (int)$request->param('id');

        $fatto = (new RichiestaPresenzaRepository($this->conn))->ritira($operaio, $id);

        $_SESSION[$fatto ? 'success' : 'error'] = $this->dire(
            $request, $fatto ? 'msg_ritirata' : 'msg_non_ritirabile'
        );

        Response::redirect('/io/presenze');
    }

    // ── GET /io/assenze ──────────────────────────────────────────────────────

    public function assenze(Request $request): void
    {
        $operaio = $this->operaio($request);
        $repo    = new LeaveRepository($this->conn);

        $anno = (int)($_GET['anno'] ?? 0);
        if ($anno < 2000 || $anno > (int)date('Y') + 1) {
            $anno = (int)date('Y');
        }

        $righe = $repo->perWorkerEAnno($operaio, $anno);

        Response::view('operaio/assenze.html.twig', $request, [
            'pageTitle'  => 'Ferie e assenze',
            'righe'      => $righe,
            'anno'       => $anno,
            'anni'       => $repo->anniConAssenze($operaio),
            'contiAnno'  => $this->contiAssenze($righe),
            'oggi'       => date('Y-m-d'),
            'successMsg' => $this->presoDallaSessione('success'),
            'errorMsg'   => $this->presoDallaSessione('error'),
        ]);
    }

    // ── POST /io/assenze ─────────────────────────────────────────────────────

    /**
     * Chiede ferie, un permesso, o avvisa di una malattia.
     *
     * La malattia non si "chiede" — uno sta male e basta — ma passa dalla
     * stessa porta perche' l'ufficio deve comunque vederla e riscontrare il
     * certificato. In attesa li' non vuol dire "forse", vuol dire "non
     * ancora guardata".
     */
    public function chiediAssenza(Request $request): never
    {
        $operaio = $this->operaio($request);

        $tipo = in_array($_POST['tipo'] ?? '', ['ferie', 'permesso', 'malattia'], true)
            ? (string)$_POST['tipo'] : '';
        $dal  = trim((string)($_POST['dal'] ?? ''));
        $al   = trim((string)($_POST['al']  ?? ''));

        try {
            if ($tipo === '' || $dal === '') {
                throw new RuntimeException($this->dire($request, 'msg_scegli_tipo'));
            }

            // un giorno solo: chiedere di ripetere la stessa data e' un campo
            // in piu' che non aggiunge niente
            if ($al === '') {
                $al = $dal;
            }
            if ($al < $dal) {
                [$dal, $al] = [$al, $dal];
            }

            $ore = ($_POST['ore'] ?? '') !== '' ? (float)$_POST['ore'] : null;

            // il numero del certificato vale solo sulla malattia, e non si
            // pretende: chi sta a letto con la febbre deve poter avvisare
            // anche senza averlo sotto mano
            $prot = $tipo === 'malattia' ? trim((string)($_POST['protocollo'] ?? '')) : '';

            $stmt = $this->conn->prepare("
                INSERT INTO bb_ferie_permessi
                    (worker_id, tipo, data_inizio, data_fine, ore, note,
                     protocollo, stato, richiesta_da_operaio, created_by)
                VALUES (:wid, :tipo, :dal, :al, :ore, :note,
                        :prot, 'in_attesa', 1, NULL)
            ");
            $stmt->execute([
                ':wid'  => $operaio,
                ':tipo' => $tipo,
                ':dal'  => $dal,
                ':al'   => $al,
                ':ore'  => $ore,
                ':note' => trim((string)($_POST['note'] ?? '')) ?: null,
                ':prot' => $prot !== '' ? $prot : null,
            ]);

            $quando = $dal === $al
                ? $this->dire($request, 'per_il', ['data' => date('d/m/Y', strtotime($dal))])
                : $this->dire($request, 'da_a', [
                    'dal' => date('d/m/Y', strtotime($dal)),
                    'al'  => date('d/m/Y', strtotime($al)),
                  ]);

            $_SESSION['success'] = $this->dire($request, 'msg_richiesta_mandata', [
                'cosa'   => $this->dire($request, $tipo),
                'quando' => $quando,
            ]);
        } catch (\RuntimeException $e) {
            // i messaggi che scriviamo noi sono gia' nella sua lingua
            $_SESSION['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            // tutto il resto e' un guasto: la frase attorno almeno si
            // capisce, anche se il dettaglio resta tecnico e in inglese
            $_SESSION['error'] = $this->dire($request, 'msg_errore', ['errore' => $e->getMessage()]);
        }

        Response::redirect('/io/assenze');
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * I conti dell'anno: ferie, permessi, malattia, e cosa aspetta risposta.
     *
     * I giorni sono quelli del calendario, da data_inizio a data_fine
     * compresi: per chi li ha presi, due settimane di ferie sono quattordici
     * giorni via da casa. Se in ufficio li contano come giornate lavorative
     * il numero sara' piu' basso del loro, ed e' una riga da cambiare.
     *
     * I permessi a ore stanno nelle ore e non nei giorni: sommarli ai giorni
     * farebbe un totale che non vuol dire niente.
     *
     * Contano solo le approvate — cioe' quelle che ha davvero preso. Quello
     * che aspetta risposta ha un suo numero a parte, perche' e' un'altra
     * domanda: "mi hanno risposto?".
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array<string, int|float>
     */
    private function contiAssenze(array $righe): array
    {
        $c = ['ferie' => 0, 'permessi_ore' => 0.0, 'permessi_giorni' => 0,
              'malattia' => 0, 'attesa' => 0];

        foreach ($righe as $r) {
            if (($r['stato'] ?? '') === 'in_attesa') {
                $c['attesa']++;
                continue;
            }
            if (($r['stato'] ?? '') === 'rifiutata') {
                continue;
            }

            $giorni = $this->giorniFra((string)$r['data_inizio'], (string)$r['data_fine']);

            if ($r['tipo'] === 'permesso') {
                if ($r['ore'] !== null && $r['ore'] !== '') {
                    $c['permessi_ore'] += (float)$r['ore'];
                } else {
                    $c['permessi_giorni'] += $giorni;
                }
            } elseif ($r['tipo'] === 'malattia') {
                $c['malattia'] += $giorni;
            } else {
                $c['ferie'] += $giorni;
            }
        }

        return $c;
    }

    /**
     * Giorni fra due date, compresi tutti e due.
     *
     * Con i secondi divisi per 86400 non torna due volte l'anno: dal 28 al
     * 30 marzo passano 47 ore e non 48, perche' le lancette vanno avanti, e
     * il conto dava due giorni invece di tre. A ottobre, quando tornano
     * indietro, succede il contrario.
     *
     * Le date si leggono in UTC, dove le ore non si spostano mai, cosi' il
     * conto e' sempre quello che vede una persona sul calendario.
     */
    private function giorniFra(string $dal, string $al): int
    {
        try {
            $utc = new \DateTimeZone('UTC');
            $a = new \DateTimeImmutable($dal, $utc);
            $b = new \DateTimeImmutable($al,  $utc);
        } catch (\Throwable $e) {
            return 0;
        }

        if ($b < $a) {
            return 0;
        }
        return (int)$a->diff($b)->days + 1;
    }

    /**
     * I conti del mese, fatti dove si fanno gli altri conti.
     *
     * Le giornate si sommano a mezzi: una mezza giornata vale 0,5, ed e'
     * cosi' che le conta chi fa le buste paga. Un elenco che dice "12 righe"
     * quando due sono mezze giornate fa litigare a fine mese.
     *
     * Le rifiutate non contano: non sono giornate, sono richieste respinte.
     * Restano nell'elenco perche' uno deve vedere cos'e' stato rifiutato e
     * perche', ma nel totale no.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array<string, int|float>
     */
    private function totali(array $righe): array
    {
        $t = ['giornate' => 0.0, 'attesa' => 0, 'rifiutate' => 0, 'trasferte' => 0];

        foreach ($righe as $r) {
            if ($r['stato'] === 'rifiutata') {
                $t['rifiutate']++;
                continue;
            }
            $t['giornate'] += ($r['turno'] ?? '') === 'Mezzo' ? 0.5 : 1.0;
            if ($r['stato'] === 'in_attesa')  { $t['attesa']++; }
            if (!empty($r['trasferta']))      { $t['trasferte']++; }
        }

        return $t;
    }

    /**
     * L'operaio collegato all'utente.
     *
     * Dall'utente e mai dalla richiesta: un id passato nella pagina sarebbe
     * modificabile, e basterebbe cambiare un numero per leggere le presenze
     * di un collega.
     */
    private function operaio(Request $request): int
    {
        $workerId = (int)($request->user()->worker_id ?? 0);

        if (!$workerId) {
            // Succede agli account creati senza scegliere il lavoratore.
            // Dirlo chiaramente e' meglio di una schermata vuota: chi lo
            // legge sa cosa chiedere in ufficio.
            $_SESSION['error'] = \App\Service\Lingua::testo(
                'msg_non_collegato', $request->user()->lingua ?? null
            );
            Response::redirect('/dashboard');
        }
        return $workerId;
    }

    private function presoDallaSessione(string $chiave): ?string
    {
        $v = $_SESSION[$chiave] ?? null;
        unset($_SESSION[$chiave]);
        return $v;
    }
}
