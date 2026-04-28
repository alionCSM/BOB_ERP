<?php
// $conn, $companyId (int), $user provided by DocumentsController::checkMandatoryCompany()

// Company documents list (same as datalist in upload modal)
$mandatoryDocs = [
    "RLS",
    "RSPP",
    "RSPP Attestato",
    "RLS Attestato",
    "DVR",
    "Visura",
    "Patente a crediti",
    "Nomina primo soccorso",
    "Nomina medico competente",
    "Nomina preposto",
    "Nomina antincendio",
    "DURC",
    "DOMA",
    "Dichiarazione possesso requisiti tecnico professionali",
    "Dichiarazione informazione e formazione",
    "Dichiarazione conformità attrezzature",
    "Dichiarazione art.14",
    "C.I. datore di lavoro",
    "Assicurazione"
];

// Fetch company documents from database
$query = "SELECT tipo_documento, scadenza FROM bb_company_documents WHERE company_id = ?";
$stmt = $conn->prepare($query);
$stmt->execute([$companyId]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$companyDocs = [];
foreach ($docs as $d) {
    $companyDocs[$d['tipo_documento']] = $d['scadenza'];
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

    $raw = $companyDocs[$doc] ?? "";  // valore originale VARCHAR
    $formattedDate = "-";
    $dotColor = "#6b7280"; // default grey
    $statusText = "<span class='text-gray-600 font-semibold'>Nessuna scadenza</span>";

    // 1️⃣ SE NON ESISTE PROPRIO → documento mancante
    if ($raw === "") {
        $formattedDate = "-";
        $dotColor = "#ef4444"; // red
        $statusText = "<span class='text-red-600 font-semibold'>Da inserire</span>";
    }
    // 2️⃣ SE È UNA DATA VALIDA (various formats: Y-m-d, d/m/Y, d-m-Y)
    elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        // Format: YYYY-MM-DD
        $formattedDate = DateTime::createFromFormat("Y-m-d", $raw)->format("d/m/Y");
        $dt = DateTime::createFromFormat("Y-m-d", $raw);

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
    } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        // Format: DD/MM/YYYY
        $formattedDate = $raw; // mostriamo la data com'è
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
    } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $raw)) {
        // Format: DD-MM-YYYY
        $formattedDate = str_replace('-', '/', $raw);
        $dt = DateTime::createFromFormat("d-m-Y", $raw);

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
