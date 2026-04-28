<?php
require_once '../../includes/middleware.php';
require_once '../../controllers/offers/OfferController.php';

$db = new Database();
$conn = $db->connect();

$user->id = $authenticated_user['user_id'];

// Sicurezza: blocca accesso se company_id è null
if ($user->getCompanyId() === null) {
    header("Location: ../auth/logout.php");
    exit();
}

$offerController = new OfferController($conn);

// Recupera solo le offerte visibili all'utente (accesso OOP)
$offers = $offerController->getVisibleOffers((int)$user->getCompanyId());

$pageTitle = "Lista Offerte";
include_once '../../includes/template/template.php';
?>

<div class="grid grid-cols-12 gap-6 mt-5">
    <div class="intro-y col-span-12 flex flex-wrap xl:flex-nowrap items-center mt-2">
        <a href="create_offer.php" class="btn btn-primary shadow-md mr-2"> <i class="fa fa-plus"></i>&nbsp Crea Offerta</a>
        <input type="text" id="search-offer" placeholder="Cerca offerta..."
               class="form-control w-64 shadow-md mr-2 mt-2 xl:mt-0" />
    </div>
    <div class="intro-y col-span-12 overflow-auto 2xl:overflow-visible">
        <table class="table table-report -mt-2">
            <thead>
            <tr>
                <th class="whitespace-nowrap">N° Offerta</th>
                <th>Cliente</th>
                <th>Oggetto Offerta</th>
                <th class="text-center whitespace-nowrap">Totale (€)</th>
                <th class="text-center whitespace-nowrap">Data Creazione</th>
                <?php if ($user->getCompanyId() == 1): ?>
                    <th class="text-center whitespace-nowrap">Azienda</th>
                <?php endif; ?>
                <th class="text-center whitespace-nowrap">Utente</th>
                <th class="text-center whitespace-nowrap">Azioni</th>
            </tr>
            </thead>
            <tbody id="offerTable">
            <?php foreach ($offers as $offer): ?>
                <tr class="intro-x">
                    <td class="!py-3.5 font-semibold text-gray-700">
                        <a href="offer_edit.php?offer_id=<?= $offer['id'] ?>" class="font-medium whitespace-nowrap">
                            <?= htmlspecialchars($offer['offer_number']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($offer['client_name']) ?></td>
                    <td>
                        <div class="text-xs text-slate-600 italic" title="<?= htmlspecialchars($offer['subject']) ?>">
                            <?= htmlspecialchars(strlen($offer['subject']) > 80 ? substr($offer['subject'], 0, 77) . '...' : $offer['subject']) ?>
                        </div>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($offer['total_amount']) ?></td>
                    <td class="text-center"><?= date("d/m/Y H:i", strtotime($offer['created_at'])) ?></td>
                    <?php if ($user->getCompanyId() == 1): ?>
                        <td class="text-center"><?= htmlspecialchars($offer['company_name']) ?></td>
                    <?php endif; ?>
                    <td class="text-center"><?= htmlspecialchars($offer['creator_name']) ?></td>

                    <td class="table-report__action w-56">
                        <div class="flex justify-center items-center gap-x-2">
                            <a class="flex items-center font-semibold hover:underline mr-2"
                               style="color: #1d4ed8;"
                               href="offer_edit.php?offer_id=<?= $offer['id'] ?>">
                                <i data-lucide="eye" class="w-4 h-4 mr-1"></i> Visualizza
                            </a>
                            <a class="flex items-center font-semibold hover:underline mr-2"
                               style="color: #cca700;"
                               href="revisione_offerta.php?offer_id=<?= $offer['id'] ?>">
                                <i data-lucide="edit" class="w-4 h-4 mr-1"></i> Revisione
                            </a>
                            <a class="flex items-center font-semibold hover:underline"
                               style="color: #15803d;"
                               target="_blank" href="genera_pdf.php?offer_id=<?= $offer['id'] ?>">
                                <i data-lucide="download" class="w-4 h-4 mr-1"></i> Scarica
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.getElementById("search-offer").addEventListener("input", function () {
        const filtro = this.value.toLowerCase();
        const righe = document.querySelectorAll("#offerTable tr");

        righe.forEach(riga => {
            const contenuto = riga.textContent.toLowerCase();
            riga.style.display = contenuto.includes(filtro) ? "" : "none";
        });
    });
</script>
<?php include "../../includes/template/footer.php"; ?>
