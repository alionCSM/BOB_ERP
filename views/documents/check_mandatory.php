<?php
// $conn, $workerId (int), $user provided by DocumentsController::checkMandatory()
assertCompanyScopeWorkerAccess($conn, $user, $workerId);

$mandatoryDocs = [
    "Documento d'identità",
    "Verbale consegna DPI",
    "Visita medica",
    "Unilav",
    "Formazione sicurezza",
    "Lavori in quota DPI",
    "Piattaforma",
    "Carrello elevatore",
    "Braccio telescopico",
    "Preposto",
    "Antincendio",
    "Primo soccorso",
    "Gru a torre",
    "Gru mobile",
    "Saldatura"
];

$query = "SELECT tipo_documento, scadenza FROM bb_worker_documents WHERE worker_id = ?";
$stmt = $conn->prepare($query);
$stmt->execute([$workerId]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$workerDocs = [];
foreach ($docs as $d) {
    $workerDocs[$d['tipo_documento']] = $d['scadenza'];
}

// Funzione per controllare se una stringa è una data valida dd/mm/yyyy
function isValidDateDMY($date) {
    return preg_match('/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}$/', $date);
}

echo "<table class='table-auto w-full border-collapse border border-gray-300'>";
echo "<thead class='bg-gray-100'>
        <tr>
            <th class='border p-3 text-left'>Documento</th>
            <th class='border p-3 text-left'>Scadenza</th>
            <th class='border p-3 text-left'>Stato</th>
        </tr>
      </thead><tbody>";

$today = strtotime(date('Y-m-d'));

foreach ($mandatoryDocs as $doc) {

    $raw = $workerDocs[$doc] ?? "";  // valore originale VARCHAR
    $formattedDate = "-";
    $dotColor = "#6b7280"; // default grey
    $statusText = "<span class='text-gray-600 font-semibold'>Nessuna scadenza</span>";

    // 1️⃣ SE NON ESISTE PROPRIO → documento mancante
    if ($raw === "") {
        $formattedDate = "-";
        $dotColor = "#ef4444"; // red
        $statusText = "<span class='text-red-600 font-semibold'>Da inserire</span>";
    }
    // 2️⃣ SE È UNA DATA VALIDA dd/mm/yyyy
    elseif (isValidDateDMY($raw)) {

        $formattedDate = $raw; // mostriamo la data com’è
        $dt = DateTime::createFromFormat("d/m/Y", $raw);

        if ($dt) {
            $timestamp = $dt->getTimestamp();
            $days = ($timestamp - $today) / 86400;

            if ($days < 0) {
                $dotColor = "#ef4444";
                $statusText = "<span class='text-red-600 font-semibold'>Scaduto</span>";
            } elseif ($days <= 30) {
                $dotColor = "#CCB000";
                $statusText = "<span class='text-amber-500 font-semibold'>In scadenza</span>";
            } else {
                $dotColor = "#22c55e";
                $statusText = "<span class='text-green-600 font-semibold'>Valido</span>";
            }
        }
    }
    // 3️⃣ SE È QUALSIASI ALTRA STRINGA → consideriamo "senza scadenza"
    else {
        $formattedDate = $raw;
        $dotColor = "#6b7280"; // grey
        $statusText = "<span class='text-gray-600 font-semibold'>Nessuna scadenza</span>";
    }

    echo "<tr>
            <td class='border p-3'>$doc</td>
            <td class='border p-3'>$formattedDate</td>
            <td class='border p-3 flex items-center gap-2'>
                <span style='width:12px;height:12px;border-radius:50%;background-color:$dotColor;'></span>
                $statusText
            </td>
          </tr>";
}

echo "</tbody></table>";
?>
