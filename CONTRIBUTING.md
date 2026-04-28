# Contributing — Adding a New Module

This guide shows you how to create a new module using the modern architecture (Twig templates, controllers, services, repositories).

---

## Architecture Overview

```
View (Twig) --> Controller --> Service --> Repository --> Database
```

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **View** | Presentation only, HTML/Twig templates | `templates/<module>/` |
| **Controller** | Request handling, parameter extraction, authorization | `src/Http/Controllers/` |
| **Service** | Business logic, validation, orchestration | `src/Service/<Module>/` |
| **Repository** | Data access, SQL queries only | `src/Repository/<Module>/` |

---

## Step-by-Step: Create a New Module

We'll create a complete example module following the pattern used by the **Ordini Consorziata** module.

### Step 1: Create the Repository Interface

**File:** `src/Repository/Contracts/YourModuleRepositoryInterface.php`

```php
<?php
declare(strict_types=1);

namespace App\Repository\Contracts;

interface YourModuleRepositoryInterface
{
    public function getAll(int $companyId): array;
    public function getById(int $id, int $companyId): ?array;
    public function create(array $data, int $companyId): int;
    public function update(array $data, int $id, int $companyId): bool;
    public function delete(int $id, int $companyId): bool;
}
```


### Step 2: Create the Repository Implementation

**File:** `src/Repository/YourModule/YourModuleRepository.php`

```php
<?php
declare(strict_types=1);

namespace App\Repository\YourModule;

use PDO;
use App\Repository\Contracts\YourModuleRepositoryInterface;

class YourModuleRepository implements YourModuleRepositoryInterface
{
    public function __construct(private PDO $conn) {}

    public function getAll(int $companyId): array
    {
        $sql = "SELECT * FROM bb_your_table";
        $params = [];
        
        if ($companyId !== 1) {
            $sql .= ' WHERE company_id = :company_id';
            $params[':company_id'] = $companyId;
        }
        
        $sql .= ' ORDER BY created_at DESC';
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $companyId): ?array
    {
        $sql = "SELECT * FROM bb_your_table WHERE id = :id";
        $params = [':id' => $id];
        
        if ($companyId !== 1) {
            $sql .= ' AND company_id = :company_id';
            $params[':company_id'] = $companyId;
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data, int $companyId): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_your_table (column1, column2, company_id, created_at)
            VALUES (:column1, :column2, :company_id, NOW())
        ");
        
        $stmt->execute([
            ':column1'   => $data['column1'],
            ':column2'   => $data['column2'],
            ':company_id' => $companyId,
        ]);
        
        return (int)$this->conn->lastInsertId();
    }

    public function update(array $data, int $id, int $companyId): bool
    {
        $sql = "UPDATE bb_your_table SET column1 = :column1, column2 = :column2 WHERE id = :id";
        $params = [':column1' => $data['column1'], ':column2' => $data['column2'], ':id' => $id];
        
        if ($companyId !== 1) {
            $sql .= ' AND company_id = :company_id';
            $params[':company_id'] = $companyId;
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $companyId): bool
    {
        $sql = 'DELETE FROM bb_your_table WHERE id = :id';
        $params = [':id' => $id];
        
        if ($companyId !== 1) {
            $sql .= ' AND company_id = :company_id';
            $params[':company_id'] = $companyId;
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
}
```

