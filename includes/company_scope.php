<?php

function getCompanyScopeAllowedNames(PDO $conn, $user): array
{
    $names = [];

    $mapStmt = $conn->query("SHOW TABLES LIKE 'bb_user_company_access'");
    $hasMap = $mapStmt && $mapStmt->fetch(PDO::FETCH_NUM);

    if ($hasMap) {
        $stmt = $conn->prepare("\n            SELECT c.name\n            FROM bb_user_company_access uca\n            INNER JOIN bb_companies c ON c.id = uca.company_id\n            WHERE uca.user_id = :uid\n        ");
        $stmt->execute([':uid' => $user->id]);
        $names = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (empty($names) && !empty($user->company_id)) {
        $stmt = $conn->prepare('SELECT name FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$user->company_id]);
        $fallback = $stmt->fetchColumn();
        if ($fallback) {
            $names[] = (string)$fallback;
        }
    }

    $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($v) => $v !== '')));
    return $names;
}

function getCompanyScopeAllowedIds(PDO $conn, $user): array
{
    $ids = [];

    $mapStmt = $conn->query("SHOW TABLES LIKE 'bb_user_company_access'");
    $hasMap = $mapStmt && $mapStmt->fetch(PDO::FETCH_NUM);

    if ($hasMap) {
        $stmt = $conn->prepare('SELECT company_id FROM bb_user_company_access WHERE user_id = :uid');
        $stmt->execute([':uid' => $user->id]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (empty($ids) && !empty($user->company_id)) {
        $ids[] = (int)$user->company_id;
    }

    return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
}

function isCompanyScopedUserByContext(PDO $conn, $user): bool
{
    if (($user->role ?? '') === 'company_viewer' || !empty($user->client_id)) {
        return true;
    }

    // Internal users are NOT company-scoped - they have full access
    if (!empty($user->internal_id)) {
        return false;
    }

    if (!empty($user->permissions['companies_viewer'])) {
        return !empty(getCompanyScopeAllowedIds($conn, $user));
    }

    return false;
}

function assertCompanyScopeWorkerAccess(PDO $conn, $user, int $workerId): void
{
    if (!isCompanyScopedUserByContext($conn, $user)) {
        return;
    }

    $allowedIds = getCompanyScopeAllowedIds($conn, $user);
    if (empty($allowedIds)) {
        http_response_code(403);
        exit('Access denied to this worker');
    }

    $stmt = $conn->prepare('SELECT company FROM bb_workers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $workerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        exit('Access denied to this worker');
    }

    // Name-based check: resolve worker's company text to a company id
    if (!empty($row['company'])) {
        $cidStmt = $conn->prepare('SELECT id FROM bb_companies WHERE name = :name LIMIT 1');
        $cidStmt->execute([':name' => trim($row['company'])]);
        $resolvedId = $cidStmt->fetchColumn();
        if ($resolvedId !== false && in_array((int)$resolvedId, $allowedIds, true)) {
            return;
        }
    }

    http_response_code(403);
    exit('Access denied to this worker');
}

function assertCompanyScopeCompanyDocAccess(PDO $conn, $user, int $companyId): void
{
    if (!isCompanyScopedUserByContext($conn, $user)) {
        return;
    }

    $allowedIds = getCompanyScopeAllowedIds($conn, $user);
    if (empty($allowedIds) || !in_array($companyId, $allowedIds, true)) {
        http_response_code(403);
        exit('Access denied to this company');
    }
}

/**
 * Validate that the provided uid matches the worker's uid in the database.
 * This prevents unauthorized access by incrementing sequential numeric IDs.
 *
 * @param PDO $conn Database connection
 * @param int $workerId The numeric worker ID
 * @param string $providedUid The uid provided via request parameter
 * @return bool True if uid matches, false otherwise
 */
function validateWorkerUid(PDO $conn, int $workerId, string $providedUid): bool
{
    if (empty($providedUid)) {
        return false;
    }

    $stmt = $conn->prepare('SELECT uid FROM bb_workers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $workerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['uid'])) {
        return false;
    }

    return hash_equals($row['uid'], $providedUid);
}

/**
 * Assert that the provided uid matches the worker's uid.
 * Exits with 403 if validation fails.
 *
 * @param PDO $conn Database connection
 * @param int $workerId The numeric worker ID
 * @param string $providedUid The uid provided via request parameter
 */
function assertWorkerUidValid(PDO $conn, int $workerId, string $providedUid): void
{
    if (!validateWorkerUid($conn, $workerId, $providedUid)) {
        http_response_code(403);
        exit('Access denied: invalid worker identifier');
    }
}
