-- Scatto dello schema di produzione al 30/09/2026.
-- Vedi README.md in questa cartella per come rigenerarlo.
--
-- Attenzione ai due ENUM: `type` e `role` non accettano valori fuori
-- elenco, e chi ci scrive dentro deve proporre un elenco chiuso.

CREATE TABLE `bb_users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NULL DEFAULT NULL,
    `password` VARCHAR(255) NULL DEFAULT NULL,
    `first_name` VARCHAR(255) NOT NULL,
    `last_name` VARCHAR(255) NOT NULL,
    `company` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NULL DEFAULT NULL,
    `phone` VARCHAR(255) NULL DEFAULT NULL,
    `photo` VARCHAR(255) NULL DEFAULT NULL,
    `fiscal_code` VARCHAR(255) NULL DEFAULT NULL,
    `uid` VARCHAR(255) NULL DEFAULT NULL,
    `type` ENUM('user','worker','client') NOT NULL DEFAULT 'worker',
    `active` ENUM('Y','N') NOT NULL DEFAULT 'Y',
    `active_from` DATE NULL DEFAULT NULL,
    `active_until` DATE NULL DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL,
    `modified_by` INT NULL DEFAULT NULL,
    `modified_at` DATETIME NULL DEFAULT NULL,
    `removed` ENUM('Y','N') NOT NULL DEFAULT 'N',
    `role` ENUM('admin','manager','user','offerte','document_manager','company_viewer')
           NOT NULL DEFAULT 'admin',
    `access_profile` ENUM('INTERNAL','CLIENT','COMPANY','WORKER') NULL DEFAULT NULL,
    `company_id` INT NULL DEFAULT NULL,
    `worker_id` INT NULL DEFAULT NULL,
    `confirmed` TINYINT(1) NULL DEFAULT '0',
    `client_id` INT NULL DEFAULT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT '0',
    `last_modal_date` DATE NULL DEFAULT NULL,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uid` (`uid`),
    UNIQUE INDEX `username` (`username`),
    UNIQUE INDEX `email` (`email`),
    INDEX `fk_user_company` (`company_id`),
    INDEX `fk_worker_id` (`worker_id`),
    CONSTRAINT `fk_user_company` FOREIGN KEY (`company_id`) REFERENCES `bb_companies` (`id`)
        ON UPDATE NO ACTION ON DELETE SET NULL,
    CONSTRAINT `fk_worker_id` FOREIGN KEY (`worker_id`) REFERENCES `bb_workers` (`id`)
        ON UPDATE NO ACTION ON DELETE NO ACTION
) ENGINE=InnoDB COLLATE='utf8mb4_0900_ai_ci';
