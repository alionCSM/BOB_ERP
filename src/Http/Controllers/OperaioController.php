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
        ]);
    }

    // ── GET /io/presenze ─────────────────────────────────────────────────────

    public function presenze(Request $request): void
    {
        $operaio = $this->operaio($request);
        $repo    = new RichiestaPresenzaRepository($this->conn);

        $dal = date('Y-m-d', strtotime('-2 months'));
        $al  = date('Y-m-d');

        Response::view('operaio/presenze.html.twig', $request, [
            'pageTitle'  => 'Le mie presenze',
            'righe'      => $repo->diarioOperaio($operaio, $dal, $al),
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
                throw new RuntimeException('Scegli il cantiere e il giorno.');
            }
            // Si dichiara quello che si e' fatto, non quello che si fara':
            // una giornata futura non e' una presenza, e' un proposito.
            if ($data > date('Y-m-d')) {
                throw new RuntimeException("Non puoi dichiarare un giorno che deve ancora arrivare.");
            }
            // L'unico limite su quale cantiere: non quelli assegnati, che
            // sono un'altra cosa, ma uno aperto. Il middleware lascia
            // passare la scelta proprio perche' il controllo e' qui.
            if (!$dati->cantiereAperto($worksiteId)) {
                throw new RuntimeException("Quel cantiere non e' aperto. Se sbaglio, dillo in ufficio.");
            }
            if ($repo->giaInAttesa($operaio, $data, $worksiteId)) {
                throw new RuntimeException("Hai gia' mandato questa giornata: e' in attesa.");
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
            $_SESSION['success'] = 'Giornata del ' . date('d/m/Y', strtotime($data))
                . ' mandata in ufficio. La trovi qui sotto come "in attesa".';
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        Response::redirect('/io/presenze');
    }

    // ── POST /io/presenze/{id}/ritira ────────────────────────────────────────

    public function ritira(Request $request): never
    {
        $operaio = $this->operaio($request);
        $id      = (int)$request->param('id');

        $fatto = (new RichiestaPresenzaRepository($this->conn))->ritira($operaio, $id);

        $_SESSION[$fatto ? 'success' : 'error'] = $fatto
            ? 'Giornata ritirata. Puoi rimandarla quando vuoi.'
            : "Non si puo' piu' ritirare: l'ufficio l'ha gia' guardata.";

        Response::redirect('/io/presenze');
    }

    // ── GET /io/assenze ──────────────────────────────────────────────────────

    public function assenze(Request $request): void
    {
        Response::view('operaio/assenze.html.twig', $request, [
            'pageTitle'  => 'Ferie e assenze',
            'righe'      => (new LeaveRepository($this->conn))->getByWorker($this->operaio($request)),
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
                throw new RuntimeException('Scegli il tipo e il giorno di inizio.');
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

            $parole = ['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia'];
            $quando = $dal === $al
                ? 'del ' . date('d/m/Y', strtotime($dal))
                : 'dal ' . date('d/m/Y', strtotime($dal)) . ' al ' . date('d/m/Y', strtotime($al));

            $_SESSION['success'] = $parole[$tipo] . ' ' . $quando
                . ': richiesta mandata in ufficio.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        Response::redirect('/io/assenze');
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

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
            $_SESSION['error'] = "Il tuo utente non e' collegato a nessun operaio. Dillo in ufficio.";
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
