-- Fieldwire integration: link a worksite to a Fieldwire project
-- Run once on the production DB before deploying the Fieldwire feature.

ALTER TABLE bb_worksites
    ADD COLUMN fieldwire_project_id  VARCHAR(64)  NULL DEFAULT NULL AFTER yard_worksite_id,
    ADD COLUMN fieldwire_enabled_at  TIMESTAMP    NULL DEFAULT NULL AFTER fieldwire_project_id,
    ADD COLUMN fieldwire_enabled_by  INT UNSIGNED NULL DEFAULT NULL AFTER fieldwire_enabled_at;
