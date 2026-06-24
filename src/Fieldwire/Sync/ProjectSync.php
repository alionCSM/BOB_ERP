<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\ProjectsApi;
use PDO;
use RuntimeException;

class ProjectSync
{
    public function __construct(
        private ProjectsApi $projects,
        private PDO         $db
    ) {}

    /**
     * Create a Fieldwire project for a BOB worksite row and save the link.
     * $worksite: array from WorksiteRepository::findById()
     * Throws if already linked.
     */
    public function enable(array $worksite, int $userId): string
    {
        if (!empty($worksite['fieldwire_project_id'])) {
            throw new RuntimeException("Cantiere #{$worksite['id']} è già collegato a Fieldwire.");
        }

        $fw = $this->projects->create($worksite['name'], $worksite['worksite_code'] ?? '');

        $projectId = $fw['id'] ?? throw new RuntimeException('Fieldwire non ha restituito un project ID.');

        $this->db->prepare("
            UPDATE bb_worksites
               SET fieldwire_project_id = :fwid,
                   fieldwire_enabled_at = NOW(),
                   fieldwire_enabled_by = :uid
             WHERE id = :id
        ")->execute([
            ':fwid' => $projectId,
            ':uid'  => $userId,
            ':id'   => $worksite['id'],
        ]);

        return $projectId;
    }

    /**
     * Remove the Fieldwire link (does NOT delete the Fieldwire project).
     * $worksite: array from WorksiteRepository::findById()
     */
    public function disable(array $worksite): void
    {
        $this->db->prepare("
            UPDATE bb_worksites
               SET fieldwire_project_id = NULL,
                   fieldwire_enabled_at = NULL,
                   fieldwire_enabled_by = NULL
             WHERE id = :id
        ")->execute([':id' => $worksite['id']]);
    }
}
