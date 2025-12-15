CREATE TABLE IF NOT EXISTS `test`.`persons`
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

CREATE TABLE IF NOT EXISTS `test`.`buttons`
(
    id        VARCHAR(36) PRIMARY KEY,
    attrs     TEXT    NOT NULL,
    admin_key BOOLEAN NOT NULL DEFAULT 0,
    messages  TEXT             DEFAULT NULL,
    belong_to VARCHAR(36)      DEFAULT NULL,
    keyboards TEXT             DEFAULT NULL
) DEFAULT CHARSET = utf8mb4;
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('0', '{"text": "صفحه اصلی"}', 0, null, null, '[["1", "5"], ["3"], ["a1"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('1', '{"text": "دارایی‌ها"}', 0, null, '0', '[["2"], ["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('2', '{"text": "افزودن دارایی جدید"}', 0, null, '1', '[["s1", "s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('3', '{"text": "ابزارها"}', 0, null, '0', '[["4"], ["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('4', '{"text": "قیمت‌ها"}', 0, null, '3', '[["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('5', '{"text": "🏦 وام و اقساط"}', 0, null, '0', '[["w1"], ["6"], ["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('6', '{"text": "📋 لیست وام‌های من"}', 0, null, '5', '[["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('s0', '{"text": "🔙 برگشت 🔙"}', 0, null, null, null);
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('s1', '{"text": "❌ لغو ❌"}', 0, null, null, null);
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('w1', '{"text": "➕ ثبت وام جدید", "web_app": {"url": "https://hossein-development.vercel.app/WebInterfaces/loans/add.html"}}', 0, null, '5', '[["s0"]]');
INSERT INTO `test`.buttons (id, attrs, admin_key, messages, belong_to, keyboards)
VALUES ('a1', '{"text": "بخش مدیریت"}', 1, null, 0, '[["s0"]]');

CREATE TABLE IF NOT EXISTS `test`.`assets`
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

CREATE TABLE IF NOT EXISTS `test`.`prices`
(
    id       BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT            NOT NULL,
    price    NUMERIC(18, 8) NOT NULL,
    date     VARCHAR(10) DEFAULT NULL,
    time     VARCHAR(8)  DEFAULT NULL,

    UNIQUE INDEX idx_unique_price (asset_id, date),
    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `test`.`holdings`
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

CREATE TABLE IF NOT EXISTS `test`.favorites
(
    id        INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    asset_id  INT NOT NULL,

    UNIQUE KEY idx_unique_favorite (person_id, asset_id),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

alter table `test`.favorites
    auto_increment 0;

CREATE TABLE IF NOT EXISTS `test`.`alerts`
(
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    person_id      INT                         NOT NULL,
    asset_id       INT                         NOT NULL,
    target_price   NUMERIC(18, 8)              NOT NULL,
    trigger_type   ENUM ('up', 'down', 'both') NOT NULL DEFAULT 'both',
    is_active      BOOLEAN                     NOT NULL DEFAULT 0,
    created_date   VARCHAR(10)                          DEFAULT NULL,
    created_time   VARCHAR(8)                           DEFAULT NULL,
    triggered_date VARCHAR(10)                          DEFAULT NULL,
    triggered_time VARCHAR(8)                           DEFAULT NULL,
    note           TEXT,

    UNIQUE INDEX idx_unique_alert (asset_id, person_id, target_price),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `test`.`transactions`
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

CREATE TABLE IF NOT EXISTS `test`.`loans`
(
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    person_id          INT            NOT NULL,
    name               VARCHAR(191)   NOT NULL,  -- نام وام (مثلا وام مسکن)
    total_amount       NUMERIC(18, 8) NOT NULL,  -- مبلغ کل وام
    received_date      VARCHAR(10) DEFAULT NULL, -- تاریخ دریافت وام
    total_installments INT            NOT NULL,  -- تعداد اقساط
    created_at         TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS `test`.`installments`
(
    id       INT AUTO_INCREMENT PRIMARY KEY,
    loan_id  INT            NOT NULL,
    amount   NUMERIC(18, 8) NOT NULL,           -- مبلغ قسط
    due_date VARCHAR(10)    NOT NULL,           -- تاریخ سررسید
    is_paid  BOOLEAN        NOT NULL DEFAULT 0, -- وضعیت پرداخت

    FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) DEFAULT CHARSET = utf8mb4;

alter table `test`.persons
    auto_increment 0;
alter table `test`.buttons
    auto_increment 0;
alter table `test`.assets
    auto_increment 0;
alter table `test`.holdings
    auto_increment 0;
alter table `test`.prices
    auto_increment 0;
alter table `test`.favorites
    auto_increment 0;
alter table `test`.alerts
    auto_increment 0;
alter table `test`.transactions
    auto_increment 0;
alter table `test`.loans
    auto_increment 0;
alter table `test`.installments
    auto_increment 0;
