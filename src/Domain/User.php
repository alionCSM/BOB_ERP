<?php
namespace App\Domain;
use RuntimeException;
use Exception;
use PDO;
use PDOException;
use App\Service\Mailer;

class User {
    private $conn; // Connessione al DB
    private $table = 'bb_users';

    public $id;
    public $username;
    public $email;
    public $password;
    public $token;
    public $company_id;
    public $type;
    public $access_profile;


    public function __construct($db, $id = null) {
        $this->conn = $db;

        if ($id !== null) {
            $this->id = (int)$id;
            $this->loadById($id);
        }
    }

    public function loadById($id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->id = (int)$row['id'];
            $this->username = $row['username'];
            $this->email = $row['email'];
            $this->company_id = $row['company_id'];

            $this->type = $row['type'];
            $this->role = $row['role'] ?? null;
            $this->access_profile = $row['access_profile'] ?? null;
            $this->worker_id = $row['worker_id'] ?? null;
            $this->client_id = $row['client_id'] ?? null;
            $this->must_change_password = (int)($row['must_change_password'] ?? 0);
        }

    }

    public function getAssignableUsers(): array
    {
        $stmt = $this->conn->prepare("
        SELECT 
            id,
            first_name,
            last_name,
            username,
            type
        FROM bb_users
        WHERE type IN ('worker', 'client')
          AND active = 'Y'
          AND removed = 'N'
        ORDER BY type, first_name, last_name
    ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function getUsersByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.username,
            u.type
        FROM bb_worksite_users wu
        JOIN bb_users u ON u.id = wu.user_id
        WHERE wu.worksite_id = :wid
          AND u.active = 'Y'
          AND u.removed = 'N'
        ORDER BY u.type, u.first_name, u.last_name
    ");

        $stmt->execute([
            ':wid' => $worksiteId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // Registrazione nuovo utente
    // User class - register method
    public function register() {
        $query = "INSERT INTO " . $this->table . " (username, email, password, first_name, last_name, company, created_by, created_at) 
              VALUES (:username, :email, :password, :first_name, :last_name, :company, :created_by, :created_at)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password', $this->password);
        $stmt->bindParam(':first_name', $this->first_name);
        $stmt->bindParam(':last_name', $this->last_name);
        $stmt->bindParam(':company', $this->company);
        $stmt->bindParam(':created_by', $this->created_by);
        $stmt->bindParam(':created_at', $this->created_at);

        return $stmt->execute(); // Returns true on success
    }

    public function confirmEmail($email) {
        $query = "UPDATE " . $this->table . " SET confirmed = 1 WHERE email = :email";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);

        return $stmt->execute(); // Returns true on success
    }


    // Login
    public function login() {
        $query = "SELECT id, password, company_id, must_change_password FROM " . $this->table . " WHERE username = :username";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $this->username);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($this->password, $user['password'])) {
            $this->id = $user['id'];
            $this->company_id = $user['company_id'] ?? null;
            $this->must_change_password = (int)$user['must_change_password'];
            // Prevent session fixation: regenerate ID on successful login
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            // Update last login timestamp (silently skip if column doesn't exist yet)
            try {
                $this->conn->prepare("UPDATE bb_users SET last_login_at = NOW() WHERE id = :id")
                    ->execute([':id' => $this->id]);
            } catch (PDOException $e) {}
            return true;
        }
        return false;
    }

    // Token generation
    public function generateToken() {
        return bin2hex(random_bytes(32));
    }



    public function load() {
        $query = "SELECT * FROM bb_users WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['id' => $this->id]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->full_name = $row['first_name'] . ' ' . $row['last_name'];
            $this->worker_id = $row['worker_id'] ?? null;
            $this->company_id = $row['company_id'] ?? null;
        }
    }

    public function createRememberToken(string $userAgent, string $domain = ''): void
    {
        // --- generate selector + token ---
        $selector = bin2hex(random_bytes(6));      // public part
        $token    = bin2hex(random_bytes(32));     // secret
        $hash     = hash('sha256', $token);

        // --- expiration (30 days) ---
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

        // --- store in DB ---
        $stmt = $this->conn->prepare("
        INSERT INTO bb_user_remember_tokens
        (user_id, selector, token_hash, user_agent, expires_at, created_at)
        VALUES (:uid, :selector, :hash, :ua, :exp, NOW())
    ");

        $stmt->execute([
            ':uid'      => $this->id,
            ':selector' => $selector,
            ':hash'     => $hash,
            ':ua'       => substr($userAgent, 0, 255),
            ':exp'      => $expires
        ]);

        // --- set cookie (selector:token) ---
        setcookie(
            'remember_me',
            $selector . ':' . $token,
            time() + (60 * 60 * 24 * 30),
            '/',
            $domain,
            true,   // Secure
            true    // HttpOnly
        );
    }


    public function revokeRememberToken(string $cookieValue): void
    {
        if (empty($cookieValue) || !str_contains($cookieValue, ':')) {
            return;
        }

        [$selector, $token] = explode(':', $cookieValue, 2);

        $stmt = $this->conn->prepare("
        DELETE FROM bb_user_remember_tokens
        WHERE selector = :selector
    ");

        $stmt->execute([
            ':selector' => $selector
        ]);
    }



    // Salva sessione utente
    public function storeSession($ip_address, $device_info, $expires_at) {
        $query = "INSERT INTO bb_user_activity (user_id, username, token, ip_address, device_info, expires_at, created_at) 
                  VALUES (:user_id, :username, :token, :ip_address, :device_info, :expires_at, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $this->id);
        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':token', $this->token);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->bindParam(':device_info', $device_info);
        $stmt->bindParam(':expires_at', $expires_at);

        return $stmt->execute();
    }

    public function validateToken($token) {
        $query = "SELECT * FROM bb_user_activity WHERE token = :token AND expires_at > NOW()";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function invalidateToken($token) {
        $query = "DELETE FROM bb_user_activity WHERE token = :token";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount();  // ritorna quante righe ha eliminato
    }

    public function getUserByToken($token) {
        $stmt = $this->conn->prepare("
        SELECT user_id, username
        FROM bb_user_activity
        WHERE token = :token
          AND expires_at > NOW()
        LIMIT 1
    ");
        $stmt->execute([':token' => $token]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function isKnownLogin(string $ip): bool
    {
        $stmt = $this->conn->prepare("
        SELECT 1
        FROM bb_user_login_history
        WHERE user_id = :uid
          AND ip_address = :ip
          AND is_trusted = 'Y'
        LIMIT 1
    ");

        $stmt->execute([
            ':uid' => $this->id,
            ':ip'  => $ip
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function createLoginVerification(string $ip, string $code): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $stmt = $this->conn->prepare("
        INSERT INTO bb_login_verifications
        (user_id, token, verify_code, ip_address, expires_at)
        VALUES (:uid, :token, :code, :ip, :exp)
    ");

        $stmt->execute([
            ':uid'   => $this->id,
            ':token' => $token,
            ':code'  => $code,
            ':ip'    => $ip,
            ':exp'   => $expires
        ]);

        return $token;
    }

    public function verifyLoginToken(string $token): bool
    {
        $stmt = $this->conn->prepare("
        SELECT id
        FROM bb_login_verifications
        WHERE token = :token
          AND user_id = :uid
          AND used = 'N'
          AND expires_at > NOW()
        LIMIT 1
    ");

        $stmt->execute([
            ':token' => $token,
            ':uid'   => $this->id
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        // marca come usato
        $upd = $this->conn->prepare("
        UPDATE bb_login_verifications
        SET used = 'Y'
        WHERE id = :id
    ");
        $upd->execute([':id' => $row['id']]);

        return true;
    }

    public function trustLoginIp(string $ip, string $ua): void
    {
        $stmt = $this->conn->prepare("
        INSERT INTO bb_user_login_history
        (user_id, ip_address, user_agent, is_trusted)
        VALUES (:uid, :ip, :ua, 'Y')
        ON DUPLICATE KEY UPDATE
            last_seen = NOW(),
            is_trusted = 'Y'
    ");

        $stmt->execute([
            ':uid' => $this->id,
            ':ip'  => $ip,
            ':ua'  => substr($ua, 0, 255)
        ]);
    }


    /**
     * Seconds to wait before the next attempt is accepted, given N prior failures.
     * Formula: 2^attempts, capped at 60s. 0 attempts = no wait.
     */
    private function verificationBackoffSeconds(int $attempts): int
    {
        if ($attempts <= 0) {
            return 0;
        }
        return (int) min(60, 2 ** $attempts);
    }

    /**
     * Returns false on failure, or throws \RuntimeException with a user-safe message
     * when the caller should surface a specific reason (backoff, exhausted).
     */
    public function verifyLoginCode(string $token, string $code): bool
    {
        $this->conn->beginTransaction();
        try {
            // Lock the row to prevent concurrent attempt-counter races
            $chk = $this->conn->prepare("
                SELECT id, attempts, verify_code, user_id, last_attempt_at
                FROM bb_login_verifications
                WHERE token = :token
                  AND used = 'N'
                  AND expires_at > NOW()
                LIMIT 1
                FOR UPDATE
            ");
            $chk->execute([':token' => $token]);
            $rec = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                $this->conn->rollBack();
                return false;
            }

            $attempts = (int)$rec['attempts'];

            if ($attempts >= 10) {
                $this->conn->rollBack();
                return false;
            }

            // Enforce exponential backoff based on prior failed attempts
            $waitSeconds = $this->verificationBackoffSeconds($attempts);
            if ($waitSeconds > 0 && $rec['last_attempt_at'] !== null) {
                $nextAllowed = strtotime($rec['last_attempt_at']) + $waitSeconds;
                if (time() < $nextAllowed) {
                    $this->conn->rollBack();
                    return false;
                }
            }

            $codeMatches = hash_equals((string)$rec['verify_code'], $code)
                        && (int)$rec['user_id'] === (int)$this->id;

            if (!$codeMatches) {
                // Wrong code — increment attempt counter and record time atomically
                $this->conn->prepare("
                    UPDATE bb_login_verifications
                    SET attempts = attempts + 1, last_attempt_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => $rec['id']]);
                $this->conn->commit();
                return false;
            }

            // Correct — mark as used
            $this->conn->prepare("
                UPDATE bb_login_verifications SET used = 'Y' WHERE id = :id
            ")->execute([':id' => $rec['id']]);

            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }



    public function getAllUsers() {
        $query = "
            SELECT u.id, u.username, u.email, u.company_id,
                   c.name AS company_name,
                   (SELECT GROUP_CONCAT(p.module)
                    FROM bb_user_permissions p
                    WHERE p.user_id = u.id AND p.allowed = 1) AS active_modules
            FROM " . $this->table . " u
            LEFT JOIN bb_companies c ON u.company_id = c.id
            ORDER BY u.username ASC
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyId() {
        return $this->company_id ?? null;
    }

    public function getWorkerId()
    {
        return $this->worker_id;
    }

    public function loadCompany() {
        $query = "SELECT company_id FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->company_id = $row['company_id'];
            return true;
        }
        return false;
    }
    public function getPermissions(): array
    {
        $stmt = $this->conn->prepare("
        SELECT module, allowed 
        FROM bb_user_permissions 
        WHERE user_id = :uid
    ");
        $stmt->execute([':uid' => $this->id]);

        $permissions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $permissions[$row['module']] = $row['allowed'] == 1;
        }
        return $permissions;
    }

    public function canAccess(string $module): bool
    {
        // SuperAdmin (ID 1) → accesso totale
        if ($this->id == 1) return true;

        return !empty($this->permissions[$module]);
    }


    public function savePermissions(array $data): void
    {
        // Rimuovi permessi esistenti
        $del = $this->conn->prepare("DELETE FROM bb_user_permissions WHERE user_id = :uid");
        $del->execute([':uid' => $this->id]);

        // Inserisci nuovi
        $ins = $this->conn->prepare("
        INSERT INTO bb_user_permissions (user_id, module, allowed)
        VALUES (:uid, :module, :allowed)
    ");

        foreach ($data as $module => $allowed) {
            $ins->execute([
                ':uid'     => $this->id,
                ':module'  => $module,
                ':allowed' => $allowed ? 1 : 0
            ]);
        }
    }

    public function loadPermissions() {
        $sql = "SELECT module, allowed FROM bb_user_permissions WHERE user_id = :uid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':uid' => $this->id]);

        $this->permissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->permissions[$row['module']] = (bool)$row['allowed'];
        }
    }


    public function getByWorkerId(int $workerId): ?array
    {
        $stmt = $this->conn->prepare("
        SELECT *
        FROM bb_users
        WHERE worker_id = :wid
          AND removed = 'N'
        LIMIT 1
    ");
        $stmt->execute([':wid' => $workerId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createFromWorker(array $worker, int $createdBy): int
    {
        // --- VALIDATION ---
        if ($worker['active'] !== 'Y') {
            throw new Exception('Worker is not active');
        }

        if (empty($worker['email'])) {
            throw new Exception('Worker email is missing');
        }

        if ($this->getByWorkerId((int)$worker['id'])) {
            throw new Exception('User already exists for this worker');
        }

        // --- PASSWORD ---
        $tempPassword = bin2hex(random_bytes(6));
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $username = strtolower(trim($worker['email']));

        // --- INSERT ---
        $stmt = $this->conn->prepare("
        INSERT INTO bb_users (
            username,
            password,
            first_name,
            last_name,
            email,
            phone,
            company,
            type,
            role,
            worker_id,
            company_id,
            active,
            confirmed,
            must_change_password,
            created_by,
            created_at
        ) VALUES (
            :username,
            :password,
            :first_name,
            :last_name,
            :email,
            :phone,
            :company,
            'worker',
            'user',
            :worker_id,
            :company_id,
            'Y',
            0,
            1,
            :created_by,
            NOW()
        )
    ");

        $stmt->execute([
            ':username'   => $username,
            ':password'   => $passwordHash,
            ':first_name' => $worker['first_name'],
            ':last_name'  => $worker['last_name'],
            ':email'      => $worker['email'],
            ':phone'      => $worker['phone'],
            ':company'    => $worker['company'],
            ':worker_id'  => $worker['id'],
            ':company_id' => $this->company_id,
            ':created_by' => $createdBy,
        ]);

        // --- EMAIL ---
        $mailer = new Mailer();
        $mailer->setSender('system');

        $mail = $mailer->getMailer();
        $mail->addAddress($worker['email']);
        $mail->Subject = 'Accesso BOB';
        $mail->Body = "
        <p>Il tuo account <strong>BOB</strong> è stato creato.</p>
        <p>
            <strong>Username:</strong> {$worker['email']}<br>
            <strong>Password temporanea:</strong> {$tempPassword}
        </p>
        <p>Al primo accesso dovrai cambiare la password.</p>
    ";
        $mail->send();

        return (int)$this->conn->lastInsertId();
    }





}
?>
