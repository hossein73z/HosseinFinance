USE test;

CREATE TABLE IF NOT EXISTS `persons`
(
    id         INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    chat_id    BIGINT             NOT NULL UNIQUE,
    first_name TEXT               NOT NULL,
    last_name  TEXT                        DEFAULT NULL,
    username   TEXT                        DEFAULT NULL,
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
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('0', '{"text": "صفحه اصلی"}', 0, null, null, '[["1", "2"], ["3"], ["4"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('1', '{"text": "دارایی‌ها"}', 0, null, '0', '[["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('2', '{"text": "🏦 وام و اقساط"}', 0, null, '0', '[["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('3', '{"text": "ابزارها"}', 0, null, '0', '[["5"], ["6"], ["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('4', '{"text": "بخش مدیریت"}', 1, null, '0', '[["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('5', '{"text": "قیمت‌ها"}', 0, null, '3', '[["s2"], ["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('6', '{"text": "هوش مصنوعی"}', 0, null, '3', '[["s0"]]');
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('s0', '{"text": "🔙 برگشت 🔙"}', 0, null, null, null);
INSERT INTO buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('s1', '{"text": "❌ لغو ❌"}', 0, null, null, null);

CREATE TABLE IF NOT EXISTS `assets`
(
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(191)   NOT NULL UNIQUE,
    asset_type    VARCHAR(20)    NOT NULL,
    price         NUMERIC(18, 8) NOT NULL DEFAULT 0.0,
    base_currency VARCHAR(10)             DEFAULT 'ریال',
    exchange_rate int                     default 1 not null,
    date          VARCHAR(10)             DEFAULT NULL,
    time          VARCHAR(8)              DEFAULT NULL
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `holdings`
(
    id        INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT            NOT NULL,
    asset_id  INT            NOT NULL,
    amount    NUMERIC(18, 8) NOT NULL DEFAULT 0.0,
    note      TEXT,
    avg_price NUMERIC(18, 8) NOT NULL,
    date      TEXT                    DEFAULT NULL,
    time      TEXT                    DEFAULT NULL,

    UNIQUE KEY idx_unique_holding (person_id, asset_id),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS favorites
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    person_id  INT          NOT NULL,
    asset_name VARCHAR(191) NOT NULL,

    UNIQUE KEY idx_unique_favorite (person_id, asset_name),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (asset_name) REFERENCES assets (name) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `alerts`
(
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    person_id      INT                         NOT NULL,
    asset_name     VARCHAR(191)                NOT NULL UNIQUE,
    target_price   NUMERIC(18, 8)              NOT NULL,
    trigger_type   ENUM ('up', 'down', 'both') NOT NULL DEFAULT 'both',
    is_active      BOOLEAN                     NOT NULL DEFAULT 0,
    created_date   VARCHAR(10)                          DEFAULT NULL,
    created_time   VARCHAR(8)                           DEFAULT NULL,
    triggered_date VARCHAR(10)                          DEFAULT NULL,
    triggered_time VARCHAR(8)                           DEFAULT NULL,
    note           TEXT,

    UNIQUE INDEX idx_unique_alert (asset_name, person_id, target_price),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (asset_name) REFERENCES assets (name) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `transactions`
(
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    person_id INT            NOT NULL,
    asset_id  INT            NOT NULL,
    category  VARCHAR(50)    NOT NULL,
    date      VARCHAR(10)    DEFAULT NULL,
    time      VARCHAR(8)     DEFAULT NULL,
    price     NUMERIC(18, 8) NOT NULL,
    amount    NUMERIC(18, 8) NOT NULL,
    fee       NUMERIC(18, 8) DEFAULT 0.0,
    note      TEXT,

    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `loans`
(
    id            INT AUTO_INCREMENT PRIMARY KEY,
    person_id     INT                   NOT NULL,
    name          VARCHAR(191)          NOT NULL,
    total_amount  NUMERIC(18, 8)        NOT NULL,
    received_date VARCHAR(10) DEFAULT NULL,
    alert_offset  INT         DEFAULT 0 NOT NULL,
    created_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `installments`
(
    id       INT AUTO_INCREMENT PRIMARY KEY,
    loan_id  INT            NOT NULL,
    amount   NUMERIC(18, 8) NOT NULL,
    due_date VARCHAR(10)    NOT NULL,
    is_paid  BOOLEAN        NOT NULL DEFAULT 0,

    UNIQUE INDEX idx_unique_installment (loan_id, due_date),
    FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `special_messages`
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    person_id  INT         NOT NULL,
    type       VARCHAR(10) NOT NULL,
    is_active  BOOLEAN     NOT NULL DEFAULT 0,
    message_id NUMERIC(6)  NOT NULL,
    data       text,

    UNIQUE INDEX idx_unique_installment (person_id, type),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

alter table persons
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
