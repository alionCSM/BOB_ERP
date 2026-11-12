DROP DATABASE IF EXISTS zonatest; CREATE DATABASE zonatest; USE zonatest;
CREATE TABLE bb_clients(id INT PRIMARY KEY, name VARCHAR(80));
CREATE TABLE bb_users(id INT PRIMARY KEY, username VARCHAR(40), first_name VARCHAR(40), last_name VARCHAR(40), email VARCHAR(80),
  type VARCHAR(10), role VARCHAR(20) DEFAULT 'user', client_id INT NULL, worker_id INT NULL, company VARCHAR(80) DEFAULT '',
  active CHAR(1) DEFAULT 'Y', removed CHAR(1) DEFAULT 'N', lingua VARCHAR(2) DEFAULT 'it');
CREATE TABLE bb_user_permissions(user_id INT, module VARCHAR(40), allowed TINYINT);
CREATE TABLE bb_user_company_permissions(user_id INT, module VARCHAR(40), allowed TINYINT);
CREATE TABLE bb_worksites(id INT PRIMARY KEY, name VARCHAR(80), worksite_code VARCHAR(20), location VARCHAR(40), status VARCHAR(20),
  client_id INT NULL, zone_cliente TINYINT DEFAULT 0, zone_presenze_cliente TINYINT DEFAULT 0, is_draft TINYINT DEFAULT 0, fieldwire_project_id VARCHAR(40) NULL);
CREATE TABLE bb_zone_accessi(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, user_id INT, ruolo VARCHAR(10), created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(worksite_id,user_id));
CREATE TABLE bb_worksite_users(worksite_id INT, user_id INT, PRIMARY KEY(worksite_id,user_id));
CREATE TABLE bb_zone_condivisioni(tipo VARCHAR(10), oggetto_id INT, user_id INT, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tipo,oggetto_id,user_id));
CREATE TABLE bb_zone_condivisioni_ruoli(worksite_id INT, tipo VARCHAR(10), oggetto_id INT, ruolo VARCHAR(10), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tipo,oggetto_id,ruolo));
CREATE TABLE bb_zone_tasks(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, fw_id VARCHAR(40) NULL, name VARCHAR(200), description TEXT NULL,
  status VARCHAR(20) DEFAULT 'open', category VARCHAR(80) NULL, assignee_name VARCHAR(80) NULL, assignee_user_id INT NULL,
  assegnata_a VARCHAR(10) DEFAULT 'squadra', visibilita VARCHAR(10) NULL, luogo VARCHAR(160) NULL, start_date DATE NULL, due_date DATE NULL, priority INT DEFAULT 0,
  created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_task_comments(id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, fw_id VARCHAR(40) NULL, text TEXT NULL, file_url TEXT NULL,
  author_name VARCHAR(80) NULL, author_user_id INT NULL, interna TINYINT DEFAULT 0, per_cliente TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_file_comments(id INT AUTO_INCREMENT PRIMARY KEY, file_id INT, text TEXT, author_name VARCHAR(80), author_user_id INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_fw_floorplans(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, fw_id VARCHAR(40), name VARCHAR(100), sheets_count INT DEFAULT 0);
CREATE TABLE bb_zone_task_checklist(id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, fw_id VARCHAR(40) NULL, name VARCHAR(200), completed TINYINT DEFAULT 0, position INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_folders(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, name VARCHAR(200), created_by INT NULL, visibilita VARCHAR(10) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_files(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, folder_id INT NULL, file_name VARCHAR(200), file_path VARCHAR(300), file_type VARCHAR(10),
  size_bytes BIGINT DEFAULT 0, uploaded_by INT NULL, visibilita VARCHAR(10) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_form_templates(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT NULL, client_id INT NULL, name VARCHAR(200), description TEXT NULL, fields TEXT, active TINYINT DEFAULT 1,
  created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_form_submissions(id INT AUTO_INCREMENT PRIMARY KEY, template_id INT, worksite_id INT, template_name VARCHAR(200), `values` TEXT,
  submitter_name VARCHAR(80) NULL, submitted_by INT NULL, source VARCHAR(20) DEFAULT 'internal', assegnazione_id INT NULL, visibilita VARCHAR(10) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_form_assegnazioni(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, template_id INT, a_ruolo VARCHAR(10) NULL, a_user_id INT NULL,
  frequenza VARCHAR(20) DEFAULT 'una_volta', scadenza DATE NULL, attiva TINYINT DEFAULT 1, visibilita VARCHAR(10) NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_worksite_documents(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, file_name VARCHAR(200), file_path VARCHAR(300), file_type VARCHAR(10),
  note TEXT NULL, subcategory VARCHAR(40) NULL, category VARCHAR(40) NULL, is_deleted TINYINT DEFAULT 0, current_version INT DEFAULT 1, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_worksite_document_versions(id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, version_number INT, file_name VARCHAR(200), file_path VARCHAR(300), file_type VARCHAR(10), uploaded_by INT NULL, note TEXT NULL, created_at DATETIME);
CREATE TABLE bb_zone_disegno_sync(document_id INT, fw_sheet_upload_id VARCHAR(40) NULL, pushed_at DATETIME NULL);
CREATE TABLE bb_zone_dwg_render(document_id INT, svg_path VARCHAR(500) NULL, status VARCHAR(10), error TEXT NULL);
CREATE TABLE bb_zone_doc_calibration(id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, page INT DEFAULT 1, m_per_wfrac DECIMAL(14,6), created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(document_id, page));
CREATE TABLE bb_zone_file_compilazioni(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, file_id INT, richiesto_da INT NULL, per_ruolo VARCHAR(16), nota TEXT NULL, stato VARCHAR(16) DEFAULT 'da_compilare', compilato_da INT NULL, compilato_at DATETIME NULL, versione_id INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_file_versioni(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, file_id INT, file_path VARCHAR(500), size_bytes INT NULL, compilazione_id INT NULL, creato_da INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_annotations(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, document_id INT, page INT, segnalazione_id INT NULL, task_id INT NULL, created_by INT NULL, geom TEXT NULL, type VARCHAR(20) NULL, text TEXT NULL, color VARCHAR(20) NULL);
CREATE TABLE bb_zone_messaggi(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, canale VARCHAR(10) NULL, segnalazione_id INT NULL, user_id INT NULL, testo TEXT NULL,
  rapido VARCHAR(30) NULL, foto VARCHAR(300) NULL, sistema TINYINT DEFAULT 0, fissato TINYINT DEFAULT 0, fissato_da INT NULL, eliminato_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_letture(user_id INT, worksite_id INT, chiave VARCHAR(20), ultimo_id INT DEFAULT 0, letto_at DATETIME NULL, avvisato_at DATETIME NULL, PRIMARY KEY(user_id,worksite_id,chiave));
CREATE TABLE bb_zone_segnalazioni(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, tipo VARCHAR(20), gravita VARCHAR(10), testo TEXT, visibilita VARCHAR(10), stato VARCHAR(10) DEFAULT 'aperta',
  created_by INT, presa_da INT NULL, presa_at DATETIME NULL, risolta_da INT NULL, risolta_at DATETIME NULL, esito TEXT NULL, task_id INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_notifications(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(200), message TEXT, link VARCHAR(300), category VARCHAR(20), priority VARCHAR(10),
  created_by INT NULL, is_read TINYINT DEFAULT 0, created_at DATETIME, group_company_id INT NULL);
CREATE TABLE bb_push_devices(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, fcm_token VARCHAR(200), is_active TINYINT DEFAULT 1);
CREATE TABLE bb_presenze(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, worker_id INT, data DATE, turno VARCHAR(10));
CREATE TABLE bb_presenze_consorziate(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, azienda_id INT, data_presenza DATE, quantita INT);
CREATE TABLE bb_workers(id INT PRIMARY KEY, first_name VARCHAR(40), last_name VARCHAR(40), company VARCHAR(80));
CREATE TABLE bb_companies(id INT PRIMARY KEY, name VARCHAR(80));
CREATE TABLE bb_pianificazione(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, worker_id INT, data DATE);
CREATE TABLE bb_zone_riferimenti_cliente(worksite_id INT, client_id INT, nome VARCHAR(120) NULL, codice VARCHAR(60) NULL, updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(worksite_id,client_id));
CREATE TABLE test_email(id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(80), oggetto VARCHAR(200), corpo TEXT);

INSERT INTO bb_clients VALUES (1,'Rossi Logistica Spa'),(2,'Bianchi Srl');
-- 1 superadmin escluso apposta; 5 ufficio; 6 ufficio senza accesso; 30 capo; 10/11 cliente Rossi; 20 cliente Bianchi
INSERT INTO bb_users(id,username,first_name,last_name,email,type,client_id,worker_id) VALUES
 (5,'giulia','Giulia','Ufficio','giulia@test.it','user',NULL,NULL),
 (6,'marco','Marco','Ufficio','marco@test.it','user',NULL,NULL),
 (30,'arben','Arben','Hoxha','arben@test.it','worker',NULL,300),
 (10,'rossi1','Paolo','Rossi','paolo@rossi.it','client',1,NULL),
 (11,'rossi2','Anna','Rossi','anna@rossi.it','client',1,NULL),
 (20,'bianchi','Luca','Bianchi','luca@bianchi.it','client',2,NULL);
INSERT INTO bb_workers VALUES (300,'Arben','Hoxha','CSM'),(301,'Ion','Popescu','Edilnord');
INSERT INTO bb_worksites(id,name,worksite_code,client_id,zone_cliente) VALUES (100,'Magazzino Nord','C-118',1,1),(200,'Bianchi Torino','C-200',2,1);
INSERT INTO bb_zone_accessi(worksite_id,user_id,ruolo) VALUES (100,5,'ufficio'),(100,30,'cantiere'),(100,10,'cliente'),(200,5,'ufficio'),(200,20,'cliente');
INSERT INTO bb_worksite_users VALUES (100,5),(100,30),(100,10),(200,5),(200,20);
INSERT INTO bb_presenze(worksite_id,worker_id,data,turno) VALUES (100,300,CURDATE(),'Intero'),(100,301,CURDATE(),'Mezzo'),(100,300,CURDATE()-INTERVAL 1 DAY,'Intero');
INSERT INTO bb_zone_form_templates(id,worksite_id,name,fields,created_by) VALUES (1,NULL,'Checklist DPI','[{"id":"f1","type":"yesno","label":"Casco"}]',5),(2,100,'Verbale giornaliero','[{"id":"f1","type":"text","label":"Note"}]',5),(3,200,'Modulo Bianchi','[]',5);

INSERT INTO bb_worksite_documents(id,worksite_id,file_name,file_path,file_type,subcategory,created_by,current_version) VALUES (1,100,'Pianta piano terra.pdf','C-118/Disegni/piante/pianta.pdf','pdf','piante',5,2);
INSERT INTO bb_worksite_document_versions(document_id,version_number,file_name,file_path,file_type,uploaded_by,created_at) VALUES (1,1,'Pianta piano terra v1.pdf','x','pdf',5,NOW() - INTERVAL 10 DAY);
INSERT INTO bb_zone_condivisioni_ruoli VALUES (100,'disegno',1,'cantiere',NOW()),(100,'disegno',1,'cliente',NOW());
CREATE TABLE bb_zone_presenze_manuali(id INT AUTO_INCREMENT PRIMARY KEY, worksite_id INT, data DATE, nome VARCHAR(120) NULL, azienda VARCHAR(120) NULL,
  persone INT DEFAULT 1, mezza TINYINT DEFAULT 0, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE bb_zone_task_eventi(id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, worksite_id INT, user_id INT NULL, stato_da VARCHAR(20) NULL, stato_a VARCHAR(20), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
