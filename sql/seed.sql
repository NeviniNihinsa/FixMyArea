-- Areas 
INSERT INTO areas (area_id, area_name) VALUES
(1, 'Colombo Fort'),
(2, 'Nugegoda'),
(3, 'Bambalapitiya'),
(4, 'Slave Island');

-- Categories 
INSERT INTO issue_categories (category_id, category_name) VALUES
(1, 'Road'),
(2, 'Water'),
(3, 'Street Light'),
(4, 'Garbage'),
(5, 'Drainage');

-- Demo users 
-- Admin@123
INSERT INTO users (user_id, name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status)
VALUES
(1, 'System Admin', 'admin@fixmyarea.lk', 'ADMIN001', '2000-01-01', '0770000000', 'other', 'HQ', NULL, 'admin',
 '$2b$10$461MkJqM8n5vJL2vEvWo3uFn/Cbo36YoDq8eXBH5Ajc4znvOQY7NC', 'active');

-- Authority@123 (Colombo Fort)
INSERT INTO users (user_id, name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status)
VALUES
(2, 'Colombo Council', 'authority@fixmyarea.lk', 'AUTH001', '1995-05-05', '0771111111', 'other', 'Colombo Fort Office', 1, 'authority',
 '$2b$10$BJmKn8z/CWgadn0ogYlu9eKJzSBaUH7TgAJHLgNj2SR7kKuhO6sIy', 'active');

-- Worker@123 (Colombo Fort)
INSERT INTO users (user_id, name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status)
VALUES
(3, 'Worker A', 'worker@fixmyarea.lk', 'WORK001', '1998-08-08', '0772222222', 'male', 'Colombo Fort Yard', 1, 'worker',
 '$2b$10$mCje41Ggkue7Mp3hMLoQ8Oo02KayaV.by.5g8ISA2Qg5UXbmFN8Qy', 'active');

-- Citizen@123 (Bambalapitiya)
INSERT INTO users (user_id, name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status)t satus
VALUES
(4, 'Citizen One', 'citizen@fixmyarea.lk', 'CIT001', '2002-02-02', '0773333333', 'female', 'Bambalapitiya', 3, 'citizen',
 '$2b$10$o86Ec1.G4fdc/b.MzSWylOTWMN56xdpoBpPI.HwORKZ9vLMcJl1X6', 'active');

-- Demo issue (for immediate testing)
INSERT INTO issues (issue_id, reporter_user_id, area_id, category_id, title, description, lat, lng, status)
VALUES
(1, 4, 3, 1, 'Pothole near main road', 'Large pothole causing traffic issues.', 6.8941000, 79.8559000, 'PENDING');

-- Initial timeline entry
INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note)
VALUES
(1, 'PENDING', 4, 'Issue reported by citizen');
