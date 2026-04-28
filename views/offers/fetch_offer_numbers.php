<?php
require_once '../../includes/middleware.php';
require_once '../../includes/config/Database.php';
require_once '../../controllers/offers/OfferController.php';

$db = new Database();
$conn = $db->connect();

$offerController = new OfferController($conn);

$query = (string)($_GET['query'] ?? '');
$results = $offerController->searchOfferNumbers($query, (int)$user->getCompanyId());

header('Content-Type: application/json');
echo json_encode($results);
