CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chapters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  region VARCHAR(150) NULL,
  state VARCHAR(150) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  member_type ENUM('FULL','ASSOCIATE','LIFE') NOT NULL,
  status ENUM('PENDING','ACTIVE','LAPSED','INACTIVE') NOT NULL DEFAULT 'PENDING',
  member_number_base INT NOT NULL,
  member_number_suffix INT NOT NULL DEFAULT 0,
  full_member_id INT NULL,
  chapter_id INT NULL,
  stripe_customer_id VARCHAR(100) NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NULL,
  address_line1 VARCHAR(150) NULL,
  address_line2 VARCHAR(150) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postal_code VARCHAR(20) NULL,
  country VARCHAR(100) NULL,
  wings_preference VARCHAR(50) NOT NULL DEFAULT 'digital',
  privacy_level CHAR(1) NOT NULL DEFAULT 'A',
  assist_ute TINYINT(1) NOT NULL DEFAULT 0,
  assist_phone TINYINT(1) NOT NULL DEFAULT 0,
  assist_bed TINYINT(1) NOT NULL DEFAULT 0,
  assist_tools TINYINT(1) NOT NULL DEFAULT 0,
  exclude_printed TINYINT(1) NOT NULL DEFAULT 0,
  exclude_electronic TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (full_member_id) REFERENCES members(id),
  FOREIGN KEY (chapter_id) REFERENCES chapters(id),
  UNIQUE KEY uniq_member_number (member_number_base, member_number_suffix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE membership_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  member_type ENUM('FULL','ASSOCIATE','LIFE') NOT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  notes TEXT NULL,
  rejection_reason VARCHAR(255) NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  rejected_by INT NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (rejected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE membership_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  term VARCHAR(10) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('PENDING_PAYMENT','ACTIVE','LAPSED') NOT NULL DEFAULT 'PENDING_PAYMENT',
  payment_id VARCHAR(100) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NULL,
  type ENUM('membership','store') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(50) NOT NULL,
  stripe_payment_id VARCHAR(100) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  status VARCHAR(50) NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  success TINYINT(1) NOT NULL,
  ip_address VARCHAR(50) NULL,
  attempted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_logins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ip_address VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) UNIQUE NOT NULL,
  title VARCHAR(150) NOT NULL,
  html_content MEDIUMTEXT NOT NULL,
  visibility ENUM('public','member','admin') NOT NULL DEFAULT 'public',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_key VARCHAR(60) NOT NULL UNIQUE,
  menu_id INT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  menu_id INT NOT NULL,
  page_id INT NULL,
  custom_url VARCHAR(255) NULL,
  label VARCHAR(150) NOT NULL,
  parent_id INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  open_in_new_tab TINYINT(1) NOT NULL DEFAULT 0,
  use_page_title TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL,
  FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  INDEX idx_menu_items_tree (menu_id, parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE page_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  html_content MEDIUMTEXT NOT NULL,
  created_by INT NOT NULL,
  change_summary VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (page_id) REFERENCES pages(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE page_ai_revisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  user_id INT NOT NULL,
  provider VARCHAR(40) NULL,
  model VARCHAR(80) NULL,
  summary VARCHAR(255) NULL,
  diff_text MEDIUMTEXT NULL,
  files_changed TEXT NULL,
  before_content MEDIUMTEXT NOT NULL,
  after_content MEDIUMTEXT NOT NULL,
  reverted_from_revision_id INT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (page_id) REFERENCES pages(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (reverted_from_revision_id) REFERENCES page_ai_revisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_provider_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) UNIQUE NOT NULL,
  api_key_encrypted TEXT NOT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  category ENUM('notice','advert','announcement') NOT NULL DEFAULT 'notice',
  visibility ENUM('public','member','admin') NOT NULL DEFAULT 'member',
  audience_scope ENUM('all','state','chapter') NOT NULL DEFAULT 'all',
  audience_state VARCHAR(100) NULL,
  audience_chapter_id INT NULL,
  attachment_url VARCHAR(255) NULL,
  attachment_type ENUM('image','pdf') NULL,
  published_at DATETIME NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (audience_chapter_id) REFERENCES chapters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notice_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notice_id INT NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_by INT NOT NULL,
  change_summary VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (notice_id) REFERENCES notices(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  event_date DATETIME NOT NULL,
  location VARCHAR(150) NULL,
  chapter_scope VARCHAR(50) NOT NULL DEFAULT 'all',
  visibility ENUM('public','member') NOT NULL DEFAULT 'public',
  description MEDIUMTEXT NULL,
  attachment_url VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  description MEDIUMTEXT NOT NULL,
  created_by INT NOT NULL,
  change_summary VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (event_id) REFERENCES events(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  path VARCHAR(255) NOT NULL,
  embed_html MEDIUMTEXT NULL,
  thumbnail_url VARCHAR(255) NULL,
  tags VARCHAR(255) NULL,
  visibility ENUM('public','member','admin') NOT NULL DEFAULT 'member',
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wings_issues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  pdf_url VARCHAR(255) NOT NULL,
  cover_image_url VARCHAR(255) NULL,
  is_latest TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATE NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  role VARCHAR(20) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  target_id INT NULL,
  slug VARCHAR(120) NULL,
  proposed_content MEDIUMTEXT NOT NULL,
  change_summary VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  applied_by INT NULL,
  applied_at DATETIME NULL,
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (applied_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(150) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  sent TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings_global (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(50) NOT NULL,
  key_name VARCHAR(100) NOT NULL,
  value_json JSON NOT NULL,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_settings_global (category, key_name),
  FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings_user (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  key_name VARCHAR(100) NOT NULL,
  value_json JSON NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_settings_user (user_id, key_name),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT NULL,
  diff_json JSON NULL,
  ip_address VARCHAR(50) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chapter_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  requested_chapter_id INT NOT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  rejection_reason TEXT NULL,
  requested_at DATETIME NOT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (requested_chapter_id) REFERENCES chapters(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE member_bikes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  make VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  colour VARCHAR(100) NULL,
  year INT NULL,
  rego VARCHAR(50) NULL,
  image_url VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fallen_wings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  year_of_passing INT NOT NULL,
  member_number VARCHAR(120) NULL,
  tribute TEXT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  submitted_by INT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE member_of_year_nominations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  submission_year INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by_user_id INT NULL,
  nominator_first_name VARCHAR(100) NOT NULL,
  nominator_last_name VARCHAR(100) NOT NULL,
  nominator_email VARCHAR(255) NOT NULL,
  nominee_first_name VARCHAR(100) NOT NULL,
  nominee_last_name VARCHAR(100) NOT NULL,
  nominee_chapter VARCHAR(150) NOT NULL,
  nomination_details TEXT NOT NULL,
  status ENUM('new','reviewed','shortlisted','winner') NOT NULL DEFAULT 'new',
  admin_notes TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  INDEX idx_member_of_year_year (submission_year),
  INDEX idx_member_of_year_submitted_at (submitted_at),
  INDEX idx_member_of_year_submitted_by (submitted_by_user_id),
  INDEX idx_member_of_year_nominator_email (nominator_email),
  INDEX idx_member_of_year_nominee_chapter (nominee_chapter),
  INDEX idx_member_of_year_status (status),
  UNIQUE KEY uniq_member_of_year_nomination (submission_year, nominator_email, nominee_first_name, nominee_last_name),
  FOREIGN KEY (submitted_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE renewal_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  period_id INT NOT NULL,
  reminder_type ENUM('60','30') NOT NULL,
  sent_at DATETIME NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (period_id) REFERENCES membership_periods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
