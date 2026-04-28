<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

final class SupportController
{
    private \PDO $conn;

    public function __construct(\PDO $conn)
    {
        $this->conn = $conn;
    }

    // ── GET|POST /support/tickets/create ──────────────────────────────────────

    public function createTicket(Request $request): void
    {
        $userId   = (int)(($GLOBALS['authenticated_user'] ?? [])['user_id'] ?? 0);
        $ticket   = new \App\Domain\Ticket($this->conn);
        $autoText = '';

        if ($request->method() === 'POST') {
            $ticketId = $ticket->create(
                $userId,
                (string)($_POST['title']       ?? ''),
                (string)($_POST['description'] ?? ''),
                (string)($_POST['priority']    ?? 'normal')
            );
            Response::redirect("/support/tickets/{$ticketId}");
        }

        $pageTitle = 'Nuovo Ticket';
        Response::view('support/ticket_create.html.twig', $request, compact('autoText', 'pageTitle'));
    }
}
