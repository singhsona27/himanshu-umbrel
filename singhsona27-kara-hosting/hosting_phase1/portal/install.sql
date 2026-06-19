CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  plan VARCHAR(50) NOT NULL,
  payment_reference VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  admin_note TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS hosting_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  plan VARCHAR(50) NOT NULL,
  site_url VARCHAR(255) NOT NULL,
  filebrowser_url VARCHAR(255) NOT NULL,
  phpmyadmin_url VARCHAR(255) NOT NULL,
  site_domain VARCHAR(255) DEFAULT NULL,
  filebrowser_domain VARCHAR(255) DEFAULT NULL,
  local_site_url VARCHAR(255) DEFAULT NULL,
  local_filebrowser_url VARCHAR(255) DEFAULT NULL,
  domain_mode VARCHAR(50) DEFAULT 'port',
  db_name VARCHAR(100) NOT NULL,
  db_user VARCHAR(100) NOT NULL,
  db_password VARCHAR(120) NOT NULL,
  container_name VARCHAR(120) NOT NULL,
  filebrowser_container VARCHAR(120) NOT NULL,
  filebrowser_username VARCHAR(150) DEFAULT NULL,
  filebrowser_password VARCHAR(255) DEFAULT NULL,
  site_port INT DEFAULT NULL,
  filebrowser_port INT DEFAULT NULL,
  storage_limit_mb INT DEFAULT 1024,
  db_limit_mb INT DEFAULT 512,
  expires_at DATE DEFAULT NULL,
  suspended_at TIMESTAMP NULL DEFAULT NULL,
  terminated_at TIMESTAMP NULL DEFAULT NULL,
  last_action VARCHAR(255) DEFAULT NULL,
  backup_status VARCHAR(80) DEFAULT NULL,
  status ENUM('active','suspended','expired','terminated','failed') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  user_id INT NULL,
  hosting_account_id INT NULL,
  action VARCHAR(120) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hosting_account_id INT NOT NULL,
  backup_type VARCHAR(50) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hosting_account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_type ENUM('user','admin') NOT NULL,
  account_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_ip VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX token_lookup(account_type, token_hash),
  INDEX account_lookup(account_type, account_id)
);

CREATE TABLE IF NOT EXISTS custom_domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hosting_account_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL UNIQUE,
  domain_type ENUM('website','filemanager') DEFAULT 'website',
  verification_token VARCHAR(255) NOT NULL,
  verification_status ENUM('pending','verified','failed') DEFAULT 'pending',
  cloudflare_status VARCHAR(80) NULL,
  ssl_status VARCHAR(80) NULL,
  routing_status VARCHAR(80) NULL,
  dns_target VARCHAR(255) NULL,
  last_checked_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hosting_account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS hosting_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  sites INT DEFAULT 1,
  storage_mb INT DEFAULT 1024,
  db_mb INT DEFAULT 512,
  cpu_label VARCHAR(80) DEFAULT 'Shared',
  ram_label VARCHAR(80) DEFAULT '512MB',
  plan_type VARCHAR(80) DEFAULT 'Shared',
  price_label VARCHAR(80) DEFAULT 'Manual Quote',
  is_active TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 100,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  hosting_account_id INT NULL,
  subject VARCHAR(180) NOT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('open','waiting_customer','waiting_admin','resolved','closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS support_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  admin_id INT NULL,
  user_id INT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  order_id INT NULL,
  hosting_account_id INT NULL,
  invoice_number VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) DEFAULT 0,
  currency VARCHAR(10) DEFAULT 'NGN',
  status ENUM('draft','unpaid','paid','void') DEFAULT 'unpaid',
  due_at DATE NULL,
  paid_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS hosting_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hosting_account_id INT NOT NULL,
  check_type VARCHAR(80) NOT NULL,
  status VARCHAR(80) NOT NULL,
  details TEXT NULL,
  checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hosting_account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS migration_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  hosting_account_id INT NULL,
  source_host VARCHAR(180) NULL,
  source_domain VARCHAR(180) NULL,
  notes TEXT NULL,
  status ENUM('requested','in_progress','completed','cancelled') DEFAULT 'requested',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cloudflare_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  api_token_encrypted TEXT NOT NULL,
  zone_id VARCHAR(120) NULL,
  account_email VARCHAR(180) NULL,
  status VARCHAR(80) DEFAULT 'pending',
  last_check_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order)
SELECT 'starter','Starter',1,1024,512,'Shared','512MB','Shared','Manual Quote',10
WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='starter');

INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order)
SELECT 'business','Business',3,5120,1024,'Shared','1GB','Shared','Manual Quote',20
WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='business');

INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order)
SELECT 'pro','Pro',5,10240,2048,'Shared','2GB','Shared','Manual Quote',30
WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='pro');

INSERT INTO admins (name,email,password_hash)
SELECT 'Admin','admin@hosting.local','$2y$12$Zjd3LEpFpTBeA2JnemPyPejtpPe0PWGkQNZ.leVYzdCzemK8QK.Nu'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE email='admin@hosting.local');
