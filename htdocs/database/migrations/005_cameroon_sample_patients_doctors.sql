-- ---------------------------------------------------------------------------
-- HMS — Sample patients & doctors (Cameroonian names, multiple regions)
-- Run after base schema (hms_db.sql). Uses information_schema once to detect
-- tbl_patient.facility_id (same pattern as 001). Hosts that block
-- information_schema: run the patient INSERT manually from the file body
-- with or without facility_id to match your table.
--
-- If you use multi-site (001), keep facility_id = 1 (primary site) or change
-- @hms_facility_id below. tbl_user_facility requires migration 001.
--
-- Idempotent: removes prior seed rows keyed by seed usernames / emails,
-- then re-inserts. Backup your database before running on production.
--
-- Demo doctor login: username = seed.doc.<login> (see each row), password
-- stored as plain text for legacy verify: HMS-seed-2025!
-- Change passwords immediately after demo use.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @hms_facility_id = 1;

SET @hms_has_uf := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_user_facility'
);

-- Remove old seed doctors (facility links first, only if multi-site table exists)
SET @hms_sql := IF(
  @hms_has_uf > 0,
  'DELETE uf FROM tbl_user_facility uf INNER JOIN tbl_employee e ON e.id = uf.employee_id WHERE e.username LIKE ''seed.doc.%''',
  'SELECT 1'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;

DELETE FROM tbl_employee WHERE username LIKE 'seed.doc.%';

-- Remove old seed patients (may fail if FKs from clinical tables — drop FK rows in dev or skip DELETE)
DELETE FROM tbl_patient WHERE email LIKE '%@seed-hms.cam';

SET FOREIGN_KEY_CHECKS = 1;

-- Ensure patient rows get facility_id when column exists (required NOT NULL on many installs)
SET @hms_has_pfac := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_patient' AND COLUMN_NAME = 'facility_id'
);

-- ---------------------------------------------------------------------------
-- Doctors (tbl_employee role 2): general medicine vs specialist in bio field
-- Password: HMS-seed-2025! (legacy plaintext; app accepts until upgraded)
-- ---------------------------------------------------------------------------

INSERT INTO tbl_employee (
  first_name, last_name, username, emailid, password, dob, gender, address, bio,
  employee_id, joining_date, phone, role, status
) VALUES
-- Généralistes (family / general internal medicine)
('Marie-Claire', 'Fotsing', 'seed.doc.fotsing', 'mcfotsing@seed-hms.cam', 'HMS-seed-2025!', '12/03/1982', 'Female',
 'Ndogpassi III, Douala (Littoral)', 'Généraliste — médecine de famille. Formation FM/FACS.', 'CAM-DOC-G01', '01/02/2018', '6771000101', '2', 1),
('Samuel', 'Atangana', 'seed.doc.atangana', 'satangana@seed-hms.cam', 'HMS-seed-2025!', '05/11/1979', 'Male',
 'Bastos, Yaoundé (Centre)', 'Généraliste — soins primaires et suivi chronique.', 'CAM-DOC-G02', '15/06/2016', '6992000202', '2', 1),
('Angeline', 'Kuété', 'seed.doc.kuete', 'akuete@seed-hms.cam', 'HMS-seed-2025!', '22/07/1990', 'Female',
 'Djeleng, Bafoussam (Ouest)', 'Généraliste — cabinet de proximité, pédiatrie de base.', 'CAM-DOC-G03', '10/01/2020', '6833000303', '2', 1),
('Patrick', 'Metogo', 'seed.doc.metogo', 'pmetogo@seed-hms.cam', 'HMS-seed-2025!', '18/09/1985', 'Male',
 'New Bell, Douala (Littoral)', 'Généraliste — urgences légères et médecine générale.', 'CAM-DOC-G04', '03/09/2019', '6904000404', '2', 1),
('Chantal', 'Mvondo', 'seed.doc.mvondo', 'cmvondo@seed-hms.cam', 'HMS-seed-2025!', '30/01/1988', 'Female',
 'Mfandena, Yaoundé (Centre)', 'Généraliste — santé reproductive et suivi adulte.', 'CAM-DOC-G05', '20/04/2017', '6775000505', '2', 1),
('Daniel', 'Owona', 'seed.doc.owona', 'dowona@seed-hms.cam', 'HMS-seed-2025!', '14/05/1983', 'Male',
 'Limbe Down Beach (Sud-Ouest)', 'Généraliste — médecine générale communautaire.', 'CAM-DOC-G06', '11/11/2015', '6776000606', '2', 1),

-- Spécialistes
('Jean-Pierre', 'Nguimfack', 'seed.doc.nguimfack', 'jpguimfack@seed-hms.cam', 'HMS-seed-2025!', '09/04/1976', 'Male',
 'Bertoua (Est)', 'Cardiologue — diplômes internes + imagerie cardiaque.', 'CAM-DOC-S01', '01/03/2010', '6997000707', '2', 1),
('Estelle', 'Essama', 'seed.doc.essama', 'eessama@seed-hms.cam', 'HMS-seed-2025!', '25/08/1981', 'Female',
 'Akwa, Douala (Littoral)', 'Gynécologue-obstétricienne — suivi grossesse & césariennes.', 'CAM-DOC-S02', '12/07/2014', '6778000808', '2', 1),
('Brice', 'Mvogo', 'seed.doc.mvogo', 'bmvogo@seed-hms.cam', 'HMS-seed-2025!', '03/12/1980', 'Male',
 'Ebolowa (Sud)', 'Pédiatre — néonatologie et croissance.', 'CAM-DOC-S03', '05/05/2013', '6839000909', '2', 1),
('Yvette', 'Oumarou', 'seed.doc.oumarou', 'youmarou@seed-hms.cam', 'HMS-seed-2025!', '17/02/1977', 'Female',
 'Garoua (Nord)', 'Néphrologue — dialyse et HTA secondaire.', 'CAM-DOC-S04', '18/10/2009', '6990001010', '2', 1),
('Emmanuel', 'Ndzana', 'seed.doc.ndzana', 'endzana@seed-hms.cam', 'HMS-seed-2025!', '11/06/1984', 'Male',
 'Yaoundé Melen (Centre)', 'Chirurgien digestif — viscéral & laparoscopie.', 'CAM-DOC-S05', '22/08/2016', '6771011111', '2', 1),
('Hortense', 'Akum', 'seed.doc.akum', 'hakum@seed-hms.cam', 'HMS-seed-2025!', '08/10/1986', 'Female',
 'Kumba (Sud-Ouest)', 'Psychiatre — adultes & addictologie.', 'CAM-DOC-S06', '14/02/2018', '6772012121', '2', 1);

-- Multi-site: link seed doctors to primary facility (id=1)
SET @hms_sql := IF(
  @hms_has_uf > 0,
  'INSERT IGNORE INTO tbl_user_facility (employee_id, facility_id, is_default) SELECT e.id, @hms_facility_id, 0 FROM tbl_employee e WHERE e.username LIKE ''seed.doc.%'' AND e.role = ''2''',
  'SELECT 1'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;

-- ---------------------------------------------------------------------------
-- Patients: names & addresses across Cameroon regions (sample demographics)
-- Requires tbl_patient.facility_id when that column exists (migration 001).
-- ---------------------------------------------------------------------------

SET @hms_sql := IF(
  @hms_has_pfac > 0,
  'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status, facility_id) VALUES
(''Grace'', ''Tchakounté'', ''g.tchakounte@seed-hms.cam'', ''14/02/1995'', ''Female'', ''OutPatient'', ''Bandjoun, Hauts-Plateaux (Ouest)'', ''6773013131'', 1, @hms_facility_id),
(''Martin'', ''Kamga'', ''m.kamga@seed-hms.cam'', ''03/09/1987'', ''Male'', ''InPatient'', ''Yaoundé Nsimeyong (Centre)'', ''6994024242'', 1, @hms_facility_id),
(''Sarah'', ''Bissek'', ''s.bissek@seed-hms.cam'', ''21/11/2001'', ''Female'', ''OutPatient'', ''Bonabéri, Douala (Littoral)'', ''6905035353'', 1, @hms_facility_id),
(''Jacques'', ''Nguema'', ''j.nguema@seed-hms.cam'', ''07/04/1972'', ''Male'', ''InPatient'', ''Mvog-Ada, Yaoundé (Centre)'', ''6776046464'', 1, @hms_facility_id),
(''Bénédicte'', ''Abena'', ''b.abena@seed-hms.cam'', ''30/06/1999'', ''Female'', ''OutPatient'', ''Deido, Douala (Littoral)'', ''6837057575'', 1, @hms_facility_id),
(''Raymond'', ''Amadou'', ''r.amadou@seed-hms.cam'', ''19/01/1965'', ''Male'', ''OutPatient'', ''Maroua Domayo (Extrême-Nord)'', ''6998068686'', 1, @hms_facility_id),
(''Florence'', ''Bilogo'', ''f.bilogo@seed-hms.cam'', ''12/08/1992'', ''Female'', ''OutPatient'', ''Édéa (Littoral)'', ''6779079797'', 1, @hms_facility_id),
(''Simon'', ''Idrissou'', ''s.idrissou@seed-hms.cam'', ''25/05/1989'', ''Male'', ''InPatient'', ''Ngaoundéré (Adamaoua)'', ''6901080808'', 1, @hms_facility_id),
(''Pauline'', ''Dibongue'', ''p.dibongue@seed-hms.cam'', ''02/12/2004'', ''Female'', ''OutPatient'', ''Bertoua (Est)'', ''6772091919'', 1, @hms_facility_id),
(''Richard'', ''Mohamadou'', ''r.mohamadou@seed-hms.cam'', ''16/03/1958'', ''Male'', ''InPatient'', ''Garoua (Nord)'', ''6993102020'', 1, @hms_facility_id),
(''Josephine'', ''Nguemo'', ''j.nguemo@seed-hms.cam'', ''09/07/1990'', ''Female'', ''OutPatient'', ''Foumban (Ouest)'', ''6834112121'', 1, @hms_facility_id),
(''Yannick'', ''Bello'', ''y.bello@seed-hms.cam'', ''28/10/1996'', ''Male'', ''OutPatient'', ''Bonanjo, Douala (Littoral)'', ''6775123232'', 1, @hms_facility_id),
(''Marthe'', ''Etoa'', ''m.etoa@seed-hms.cam'', ''11/01/1984'', ''Female'', ''InPatient'', ''Soa, Yaoundé (Centre)'', ''6906134343'', 1, @hms_facility_id),
(''Loïc'', ''Tsala'', ''l.tsala@seed-hms.cam'', ''05/04/2008'', ''Male'', ''OutPatient'', ''Abong-Mbang (Est)'', ''6777145454'', 1, @hms_facility_id),
(''Rose'', ''Moukouri'', ''r.moukouri@seed-hms.cam'', ''23/09/1978'', ''Female'', ''OutPatient'', ''Kribi (Sud)'', ''6998156565'', 1, @hms_facility_id),
(''Bertrand'', ''Hayatou'', ''b.hayatou@seed-hms.cam'', ''17/06/1993'', ''Male'', ''OutPatient'', ''Bafoussam (Ouest)'', ''6839167676'', 1, @hms_facility_id),
(''Adeline'', ''Egbe'', ''a.egbe@seed-hms.cam'', ''08/02/2000'', ''Female'', ''OutPatient'', ''Buea Molyko (Sud-Ouest)'', ''6770178787'', 1, @hms_facility_id),
(''Gaston'', ''Obame'', ''g.obame@seed-hms.cam'', ''31/12/1981'', ''Male'', ''InPatient'', ''Ebolowa (Sud)'', ''6901189898'', 1, @hms_facility_id),
(''Clarisse'', ''Njie'', ''c.njie@seed-hms.cam'', ''04/05/1997'', ''Female'', ''OutPatient'', ''Bamenda Commercial Avenue (Nord-Ouest)'', ''6772190909'', 1, @hms_facility_id),
(''Thierry'', ''Koumne'', ''t.koumne@seed-hms.cam'', ''22/08/1986'', ''Male'', ''OutPatient'', ''Yaoundé Odza (Centre)'', ''6993201010'', 1, @hms_facility_id)',
  'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status) VALUES
(''Grace'', ''Tchakounté'', ''g.tchakounte@seed-hms.cam'', ''14/02/1995'', ''Female'', ''OutPatient'', ''Bandjoun, Hauts-Plateaux (Ouest)'', ''6773013131'', 1),
(''Martin'', ''Kamga'', ''m.kamga@seed-hms.cam'', ''03/09/1987'', ''Male'', ''InPatient'', ''Yaoundé Nsimeyong (Centre)'', ''6994024242'', 1),
(''Sarah'', ''Bissek'', ''s.bissek@seed-hms.cam'', ''21/11/2001'', ''Female'', ''OutPatient'', ''Bonabéri, Douala (Littoral)'', ''6905035353'', 1),
(''Jacques'', ''Nguema'', ''j.nguema@seed-hms.cam'', ''07/04/1972'', ''Male'', ''InPatient'', ''Mvog-Ada, Yaoundé (Centre)'', ''6776046464'', 1),
(''Bénédicte'', ''Abena'', ''b.abena@seed-hms.cam'', ''30/06/1999'', ''Female'', ''OutPatient'', ''Deido, Douala (Littoral)'', ''6837057575'', 1),
(''Raymond'', ''Amadou'', ''r.amadou@seed-hms.cam'', ''19/01/1965'', ''Male'', ''OutPatient'', ''Maroua Domayo (Extrême-Nord)'', ''6998068686'', 1),
(''Florence'', ''Bilogo'', ''f.bilogo@seed-hms.cam'', ''12/08/1992'', ''Female'', ''OutPatient'', ''Édéa (Littoral)'', ''6779079797'', 1),
(''Simon'', ''Idrissou'', ''s.idrissou@seed-hms.cam'', ''25/05/1989'', ''Male'', ''InPatient'', ''Ngaoundéré (Adamaoua)'', ''6901080808'', 1),
(''Pauline'', ''Dibongue'', ''p.dibongue@seed-hms.cam'', ''02/12/2004'', ''Female'', ''OutPatient'', ''Bertoua (Est)'', ''6772091919'', 1),
(''Richard'', ''Mohamadou'', ''r.mohamadou@seed-hms.cam'', ''16/03/1958'', ''Male'', ''InPatient'', ''Garoua (Nord)'', ''6993102020'', 1),
(''Josephine'', ''Nguemo'', ''j.nguemo@seed-hms.cam'', ''09/07/1990'', ''Female'', ''OutPatient'', ''Foumban (Ouest)'', ''6834112121'', 1),
(''Yannick'', ''Bello'', ''y.bello@seed-hms.cam'', ''28/10/1996'', ''Male'', ''OutPatient'', ''Bonanjo, Douala (Littoral)'', ''6775123232'', 1),
(''Marthe'', ''Etoa'', ''m.etoa@seed-hms.cam'', ''11/01/1984'', ''Female'', ''InPatient'', ''Soa, Yaoundé (Centre)'', ''6906134343'', 1),
(''Loïc'', ''Tsala'', ''l.tsala@seed-hms.cam'', ''05/04/2008'', ''Male'', ''OutPatient'', ''Abong-Mbang (Est)'', ''6777145454'', 1),
(''Rose'', ''Moukouri'', ''r.moukouri@seed-hms.cam'', ''23/09/1978'', ''Female'', ''OutPatient'', ''Kribi (Sud)'', ''6998156565'', 1),
(''Bertrand'', ''Hayatou'', ''b.hayatou@seed-hms.cam'', ''17/06/1993'', ''Male'', ''OutPatient'', ''Bafoussam (Ouest)'', ''6839167676'', 1),
(''Adeline'', ''Egbe'', ''a.egbe@seed-hms.cam'', ''08/02/2000'', ''Female'', ''OutPatient'', ''Buea Molyko (Sud-Ouest)'', ''6770178787'', 1),
(''Gaston'', ''Obame'', ''g.obame@seed-hms.cam'', ''31/12/1981'', ''Male'', ''InPatient'', ''Ebolowa (Sud)'', ''6901189898'', 1),
(''Clarisse'', ''Njie'', ''c.njie@seed-hms.cam'', ''04/05/1997'', ''Female'', ''OutPatient'', ''Bamenda Commercial Avenue (Nord-Ouest)'', ''6772190909'', 1),
(''Thierry'', ''Koumne'', ''t.koumne@seed-hms.cam'', ''22/08/1986'', ''Male'', ''OutPatient'', ''Yaoundé Odza (Centre)'', ''6993201010'', 1)'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;
