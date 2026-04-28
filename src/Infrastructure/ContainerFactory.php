<?php

declare(strict_types=1);

namespace App\Infrastructure;

use DI\ContainerBuilder;
use App\Repository\Contracts\AdvanceRepositoryInterface;
use App\Repository\Contracts\AttendanceRepositoryInterface;
use App\Repository\Contracts\BookingRepositoryInterface;
use App\Repository\Contracts\ClientRepositoryInterface;
use App\Repository\Contracts\CompanyRepositoryInterface;
use App\Repository\Contracts\FineRepositoryInterface;
use App\Repository\Contracts\MealTicketRepositoryInterface;
use App\Repository\Contracts\OfferRepositoryInterface;
use App\Repository\Contracts\RefundRepositoryInterface;
use App\Repository\Contracts\SharedLinkRepositoryInterface;
use App\Repository\Contracts\StrutturaRepositoryInterface;
use App\Repository\Contracts\WorkerDocumentRepositoryInterface;
use App\Repository\Contracts\WorkerRepositoryInterface;
use App\Repository\Attendance\AdvanceRepository;
use App\Repository\AttendanceRepository;
use App\Repository\Bookings\BookingRepository;
use App\Repository\Clients\ClientRepository;
use App\Repository\Companies\CompanyRepository;
use App\Repository\Attendance\FineRepository;
use App\Repository\Tickets\MealTicketRepository;
use App\Repository\Offers\OfferRepository;
use App\Repository\Attendance\RefundRepository;
use App\Repository\Share\SharedLinkRepository;
use App\Repository\Bookings\StrutturaRepository;
use App\Repository\Documents\WorkerDocumentRepository;
use App\Repository\Workers\WorkerRepository;
use App\Repository\Contracts\OrdineRepositoryInterface;
use App\Repository\Ordini\OrdineRepository;

final class ContainerFactory
{
    public static function build(\PDO $conn): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            // ── Core ──────────────────────────────────────────────────────────
            \PDO::class   => $conn,
            Config::class => \DI\create(Config::class),

            // ── Repository interface bindings ─────────────────────────────────
            AttendanceRepositoryInterface::class     => \DI\get(AttendanceRepository::class),
            AdvanceRepositoryInterface::class        => \DI\get(AdvanceRepository::class),
            FineRepositoryInterface::class           => \DI\get(FineRepository::class),
            RefundRepositoryInterface::class         => \DI\get(RefundRepository::class),
            BookingRepositoryInterface::class        => \DI\get(BookingRepository::class),
            StrutturaRepositoryInterface::class      => \DI\get(StrutturaRepository::class),
            ClientRepositoryInterface::class         => \DI\get(ClientRepository::class),
            CompanyRepositoryInterface::class        => \DI\get(CompanyRepository::class),
            WorkerDocumentRepositoryInterface::class => \DI\get(WorkerDocumentRepository::class),
            OfferRepositoryInterface::class          => \DI\get(OfferRepository::class),
            OrdineRepositoryInterface::class         => \DI\get(OrdineRepository::class),
            SharedLinkRepositoryInterface::class     => \DI\get(SharedLinkRepository::class),
            MealTicketRepositoryInterface::class     => \DI\get(MealTicketRepository::class),
            WorkerRepositoryInterface::class         => \DI\get(WorkerRepository::class),
        ]);
        return $builder->build();
    }
}
