SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS feedback_ratings;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS issue_status_history;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS issue_photos;
DROP TABLE IF EXISTS issues;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS issue_categories;
DROP TABLE IF EXISTS areas;

SET FOREIGN_KEY_CHECKS = 1;

-- 1) areas
CREATE TABLE areas (
  area_id INT AUTO_INCREMENT PRIMARY KEY,
  area_name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) issue_categories
CREATE TABLE issue_categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) users
CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  nic VARCHAR(20) NOT NULL UNIQUE,
  dob DATE NULL,
  phone VARCHAR(20) NULL,
  gender ENUM('male','female','other') NULL,
  address VARCHAR(255) NULL,

  area_id INT NULL,
  role ENUM('citizen','worker','authority','admin') NOT NULL DEFAULT 'citizen',
  password_hash VARCHAR(255) NOT NULL,

  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_users_area
    FOREIGN KEY (area_id) REFERENCES areas(area_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_area ON users(area_id);

-- 4) issues
CREATE TABLE issues (
  issue_id INT AUTO_INCREMENT PRIMARY KEY,
  reporter_user_id INT NOT NULL,
  area_id INT NOT NULL,
  category_id INT NULL,

  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,

  status ENUM('PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED')
    NOT NULL DEFAULT 'PENDING',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_issues_reporter
    FOREIGN KEY (reporter_user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT fk_issues_area
    FOREIGN KEY (area_id) REFERENCES areas(area_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT fk_issues_category
    FOREIGN KEY (category_id) REFERENCES issue_categories(category_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_issues_area_status_created ON issues(area_id, status, created_at);
CREATE INDEX idx_issues_reporter_created ON issues(reporter_user_id, created_at);
CREATE INDEX idx_issues_category ON issues(category_id);

-- 5) issue_photos
CREATE TABLE issue_photos (
  photo_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  photo_type ENUM('REPORT','PROOF_BEFORE','PROOF_AFTER') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_photos_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_photos_uploader
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_issue_photos_issue ON issue_photos(issue_id);

-- 6) assignments
CREATE TABLE assignments (
  assignment_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  field_worker_id INT NOT NULL,
  assigned_by_authority_id INT NOT NULL,

  assignment_status ENUM('ASSIGNED','ACCEPTED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'ASSIGNED',

  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,

  CONSTRAINT fk_assign_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_assign_worker
    FOREIGN KEY (field_worker_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT fk_assign_authority
    FOREIGN KEY (assigned_by_authority_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_assign_worker_status ON assignments(field_worker_id, assignment_status);
CREATE INDEX idx_assign_issue ON assignments(issue_id);

-- 7) issue_status_history (timeline)
CREATE TABLE issue_status_history (
  history_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  status ENUM('PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED') NOT NULL,
  changed_by_user_id INT NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_hist_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_hist_user
    FOREIGN KEY (changed_by_user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_hist_issue_created ON issue_status_history(issue_id, created_at);

-- 8) comments
CREATE TABLE comments (
  comment_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  user_id INT NOT NULL,
  comment_text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_comments_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_comments_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_comments_issue_created ON comments(issue_id, created_at);

-- 9) votes (1 user can vote once per issue)
CREATE TABLE votes (
  vote_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  user_id INT NOT NULL,
  value TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_votes_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_votes_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT uq_votes_issue_user UNIQUE (issue_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_votes_issue ON votes(issue_id);

-- 10) feedback_ratings
CREATE TABLE feedback_ratings (
  feedback_id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,

  citizen_user_id INT NOT NULL,
  authority_user_id INT NULL,
  field_worker_id INT NULL,

  authority_rating TINYINT NULL,
  worker_rating TINYINT NULL,
  overall_rating TINYINT NULL,

  feedback_text TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_feedback_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_feedback_citizen
    FOREIGN KEY (citizen_user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT fk_feedback_authority
    FOREIGN KEY (authority_user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_feedback_worker
    FOREIGN KEY (field_worker_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT uq_feedback_one_per_issue UNIQUE (issue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11) notifications 
CREATE TABLE notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  issue_id INT NULL,

  notification_type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,

  action_url VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_notif_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

  CONSTRAINT fk_notif_issue
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_notif_user_read ON notifications(user_id, is_read, created_at);
