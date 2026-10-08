-- Techill: customer accounts, orders, progress, chat and files.
-- Import via phpMyAdmin, or open setup.php once and it runs this for you.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(20)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('customer','developer','admin') NOT NULL DEFAULT 'customer',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(16)  NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  stack       VARCHAR(20)  NOT NULL,
  pack        VARCHAR(40)  NOT NULL,
  template    VARCHAR(80)  NOT NULL,
  lines_json  TEXT         NOT NULL,
  total       INT UNSIGNED NOT NULL,
  hours       SMALLINT UNSIGNED NOT NULL,
  products    SMALLINT UNSIGNED NOT NULL,
  pay_method  VARCHAR(20)  NOT NULL,
  pay_sender  VARCHAR(20)  NOT NULL,
  pay_trx     VARCHAR(20)  NOT NULL,
  pay_status  ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  info_json   TEXT         NOT NULL,
  developer_id INT UNSIGNED NULL,
  cancelled   TINYINT(1) NOT NULL DEFAULT 0,
  stage       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  site_url    VARCHAR(255) NULL,
  deadline_at DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_code (code),
  KEY ix_orders_user (user_id),
  KEY ix_orders_dev (developer_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  stage      TINYINT UNSIGNED NOT NULL,
  progress   TINYINT UNSIGNED NOT NULL,
  note       TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_events_order (order_id),
  CONSTRAINT fk_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  kind          ENUM('logo','csv','attachment') NOT NULL DEFAULT 'attachment',
  original_name VARCHAR(200) NOT NULL,
  stored_name   CHAR(40)     NOT NULL,
  mime          VARCHAR(100) NOT NULL,
  size          INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_files_order (order_id),
  CONSTRAINT fk_files_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  from_admin TINYINT(1)   NOT NULL DEFAULT 0,
  body       TEXT         NOT NULL,
  file_id    INT UNSIGNED NULL,
  seen       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_messages_order (order_id, id),
  CONSTRAINT fk_messages_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value settings; 'catalog' holds the packages, prices, add-ons and payment numbers as JSON.
CREATE TABLE IF NOT EXISTS settings (
  k          VARCHAR(40) PRIMARY KEY,
  v          MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visitor analytics. vid is a random id kept in the visitor's browser, never an IP.
CREATE TABLE IF NOT EXISTS visits (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vid        CHAR(32)     NOT NULL,
  path       VARCHAR(200) NOT NULL,
  ref_host   VARCHAR(120) NULL,
  utm        VARCHAR(60)  NULL,
  device     ENUM('mobile','tablet','desktop') NOT NULL DEFAULT 'desktop',
  browser    VARCHAR(30)  NULL,
  os         VARCHAR(20)  NULL,
  country    CHAR(2)      NULL,
  country_name VARCHAR(60) NULL,
  region     VARCHAR(80)  NULL,
  city       VARCHAR(80)  NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_visits_time (created_at),
  KEY ix_visits_vid (vid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vid        CHAR(32)    NOT NULL,
  name       VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tev_name (name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS live_visitors (
  vid       CHAR(32)     PRIMARY KEY,
  path      VARCHAR(200) NOT NULL,
  device    ENUM('mobile','tablet','desktop') NOT NULL DEFAULT 'desktop',
  browser   VARCHAR(30)  NULL,
  city      VARCHAR(80)  NULL,
  country   CHAR(2)      NULL,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_live_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP → location lookups, cached so each visitor IP is looked up at most once a month.
-- The IP itself is never stored, only a salted hash of it.
CREATE TABLE IF NOT EXISTS geo_cache (
  ip_hash    CHAR(40) PRIMARY KEY,
  country    CHAR(2)     NULL,
  country_name VARCHAR(60) NULL,
  region     VARCHAR(80) NULL,
  city       VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
