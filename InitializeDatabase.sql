USE test;

CREATE TABLE IF NOT EXISTS `users`
(
    id         INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    first_name TEXT               NOT NULL,
    last_name  TEXT                        DEFAULT NULL,
    username   TEXT                        DEFAULT NULL,
    settings   TEXT                        DEFAULT NULL,
    progress   TEXT                        DEFAULT NULL,
    is_admin   BOOLEAN            NOT NULL DEFAULT 0,
    last_btn   VARCHAR(10)        NOT NULL DEFAULT '0'
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `buttons`
(
    id        VARCHAR(36) PRIMARY KEY,
    attrs     TEXT    NOT NULL,
    admin_key BOOLEAN NOT NULL DEFAULT 0,
    messages  TEXT             DEFAULT NULL,
    belong_to VARCHAR(36)      DEFAULT NULL,
    keyboards TEXT             DEFAULT NULL
) DEFAULT CHARSET = utf8mb4;
INSERT INTO `buttons` (`id`, `attrs`, `admin_key`, `messages`, `belong_to`, `keyboards`)
VALUES ('0', '{\"text\": \"🏠 صفحه اصلی\"}', 0, NULL, NULL,
        '[[\"1\", \"2\"], [\"9\", \"11\"], [\"3\"], [\"4\", \"7\"]]'),
       ('1', '{\"text\": \"💼 دارایی‌ها\"}', 0, NULL, '0', '[[\"s0\"]]'),
       ('2', '{\"text\": \"🏦 وام و اقساط\"}', 0, NULL, '0', '[[\"s0\"]]'),
       ('3', '{\"text\": \"🛠 ابزارها\"}', 0, NULL, '0', '[[\"5\", \"8\"], [\"6\"], [\"s0\"]]'),
       ('4', '{\"text\": \"👑 بخش مدیریت\"}', 1, NULL, '0', '[[\"s0\"]]'),
       ('5', '{\"text\": \"💰 قیمت‌ها\"}', 0, NULL, '3', '[[\"s2\"], [\"s0\"]]'),
       ('6', '{\"text\": \"🤖 هوش مصنوعی\"}', 0, NULL, '3', '[[\"s0\"]]'),
       ('7', '{\"text\": \"⚙ تنظیمات\"}', 0, NULL, '0', '[[\"s4\"], [\"s0\"]]'),
       ('8', '{\"text\": \"🔔 هشدارها\"}', 0, NULL, '3', '[[\"s0\"]]'),
       ('9', '{\"text\": \"🧾 حساب‌ها\"}', 0, NULL, '0', '[[\"10\"], [\"s0\"]]'),
       ('10', '{\"text\": \"➕ افزودن حساب جدید\"}', 0, NULL, '9', '[[\"s0\", \"s1\"]]'),
       ('11', '{\"text\": \"🔃 تراکنش‌ها\"}', 0, NULL, '0', '[[\"12\"], [\"s0\"]]'),
       ('12', '{\"text\": \"➕ افزودن تراکنش جدید\"}', 0, NULL, '11', '[[\"s0\", \"s1\"]]'),
       ('s0', '{\"text\": \"🔙 برگشت 🔙\"}', 0, NULL, NULL, NULL),
       ('s1', '{\"text\": \"❌ لغو ❌\"}', 0, NULL, NULL, NULL),
       ('s2', '{\"text\": \"❤ علاقه‌مندی‌ها ❤\"}', 0, NULL, NULL, NULL),
       ('s3', '{\"text\": \"Empty Button\"}', 0, NULL, NULL, NULL),
       ('s4', '{\"text\": \"💲 ارز پایه\"}', 0, NULL, '7', null);

CREATE TABLE IF NOT EXISTS `assets`
(
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(191)   NOT NULL UNIQUE,
    emoji         VARCHAR(2)              DEFAULT NULL,
    asset_type    VARCHAR(20)    NOT NULL,
    price         NUMERIC(20, 8) NOT NULL DEFAULT 0.0,
    base_currency VARCHAR(10)             DEFAULT 'ریال',
    date          VARCHAR(10)             DEFAULT NULL,
    time          VARCHAR(8)              DEFAULT NULL
) DEFAULT CHARSET = utf8mb4;

INSERT INTO assets (name, emoji, asset_type, price, base_currency, date, time)
VALUES ('ریال', '🇮🇷', 'ارزهای آزاد', 1, 'ریال', '1357-11-22', '00:00');

CREATE TABLE IF NOT EXISTS `holdings`
(
    id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id   INT            NOT NULL,
    asset_id  INT            NOT NULL,
    amount    NUMERIC(18, 8) NOT NULL DEFAULT 0.0,
    note      TEXT,
    avg_price NUMERIC(18, 8) NOT NULL,
    date      TEXT                    DEFAULT NULL,
    time      TEXT                    DEFAULT NULL,

    UNIQUE KEY idx_unique_holding (user_id, asset_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS favorites
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    asset_name VARCHAR(191) NOT NULL,

    UNIQUE KEY idx_unique_favorite (user_id, asset_name),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (asset_name) REFERENCES assets (name) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `alerts`
(
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT                         NOT NULL,
    asset_name     VARCHAR(191)                NOT NULL UNIQUE,
    target_price   NUMERIC(18, 8)              NOT NULL,
    trigger_type   ENUM ('up', 'down', 'both') NOT NULL DEFAULT 'both',
    is_active      BOOLEAN                     NOT NULL DEFAULT 0,
    created_date   VARCHAR(10)                          DEFAULT NULL,
    created_time   VARCHAR(8)                           DEFAULT NULL,
    triggered_date VARCHAR(10)                          DEFAULT NULL,
    triggered_time VARCHAR(8)                           DEFAULT NULL,
    note           TEXT,

    UNIQUE INDEX idx_unique_alert (asset_name, user_id, target_price),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (asset_name) REFERENCES assets (name) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `accounts`
(
    id               INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    user_id          INT                NOT NULL,
    type             VARCHAR(100)       NOT NULL,
    name             VARCHAR(255)       NOT NULL,
    starting_balance NUMERIC(18, 8)     NOT NULL DEFAULT 0,
    current_balance  NUMERIC(18, 8)     NOT NULL DEFAULT 0,
    note             TEXT,

    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `transactions`
(
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT                                    NOT NULL,
    account_id  INT                                    NOT NULL,
    new_balance NUMERIC(18, 8)                         NOT NULL DEFAULT 0,
    amount      NUMERIC(18, 8)                         NOT NULL,
    category    VARCHAR(50)                            NOT NULL DEFAULT 'دسته‌بندی نشده',
    type        ENUM ('outward', 'inward', 'transfer') NOT NULL DEFAULT 'outward',
    date        VARCHAR(10)                                     DEFAULT NULL,
    time        VARCHAR(8)                                      DEFAULT NULL,
    note        TEXT,

    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `loans`
(
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT                 NOT NULL,
    name          VARCHAR(191)        NOT NULL,
    total_amount  NUMERIC(18, 8)      NOT NULL,
    received_date DATE      DEFAULT NULL,
    alert_offset  INT       DEFAULT 0 NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `installments`
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    loan_id    INT            NOT NULL,
    amount     NUMERIC(18, 8) NOT NULL,
    due_date   DATE           NOT NULL,
    alert_date DATE                    DEFAULT NULL,
    is_paid    BOOLEAN        NOT NULL DEFAULT 0,

    UNIQUE INDEX idx_unique_installment (loan_id, due_date),
    FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `special_messages`
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    type       VARCHAR(10) NOT NULL,
    is_active  BOOLEAN     NOT NULL DEFAULT 0,
    message_id NUMERIC(6)  NOT NULL,
    data       text,

    UNIQUE INDEX idx_unique_installment (user_id, type),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARSET = utf8mb4;

alter table users
    auto_increment 0;
alter table buttons
    auto_increment 0;
alter table assets
    auto_increment 0;
alter table holdings
    auto_increment 0;
alter table favorites
    auto_increment 0;
alter table alerts
    auto_increment 0;
alter table transactions
    auto_increment 0;
alter table loans
    auto_increment 0;
alter table installments
    auto_increment 0;
alter table special_messages
    auto_increment 0;
