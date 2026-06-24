-- BOB Zone: local copies of Fieldwire data.
-- Run after 001_fieldwire.sql.

CREATE TABLE bb_fw_tasks (
    id             INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    worksite_id    INT UNSIGNED     NOT NULL,
    fw_id          VARCHAR(64)      NOT NULL,
    name           VARCHAR(500)     NOT NULL DEFAULT '',
    description    TEXT             NULL,
    status         VARCHAR(64)      NULL,
    category_name  VARCHAR(128)     NULL,
    assignee_name  VARCHAR(128)     NULL,
    due_date       DATE             NULL,
    fw_created_at  TIMESTAMP        NULL,
    fw_updated_at  TIMESTAMP        NULL,
    synced_at      TIMESTAMP        NULL,
    created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fw_task (fw_id),
    KEY idx_worksite (worksite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bb_fw_check_items (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    worksite_id INT UNSIGNED  NOT NULL,
    fw_task_id  VARCHAR(64)   NOT NULL,
    fw_id       VARCHAR(64)   NOT NULL,
    name        VARCHAR(500)  NOT NULL DEFAULT '',
    completed   TINYINT(1)    NOT NULL DEFAULT 0,
    synced_at   TIMESTAMP     NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fw_check_item (fw_id),
    KEY idx_fw_task (fw_task_id),
    KEY idx_worksite (worksite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bb_fw_bubbles (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    worksite_id    INT UNSIGNED  NOT NULL,
    fw_task_id     VARCHAR(64)   NOT NULL,
    fw_id          VARCHAR(64)   NOT NULL,
    kind           VARCHAR(32)   NULL,
    text           TEXT          NULL,
    creator_name   VARCHAR(128)  NULL,
    creator_email  VARCHAR(128)  NULL,
    file_url       TEXT          NULL,
    fw_created_at  TIMESTAMP     NULL,
    synced_at      TIMESTAMP     NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fw_bubble (fw_id),
    KEY idx_fw_task (fw_task_id),
    KEY idx_worksite (worksite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bb_fw_floorplans (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    worksite_id   INT UNSIGNED  NOT NULL,
    fw_id         VARCHAR(64)   NOT NULL,
    name          VARCHAR(500)  NOT NULL DEFAULT '',
    sheets_count  INT           NULL,
    fw_updated_at TIMESTAMP     NULL,
    synced_at     TIMESTAMP     NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fw_floorplan (fw_id),
    KEY idx_worksite (worksite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
