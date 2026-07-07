-- ------------------------------------------------------------
-- 予約管理システム  データベース定義
-- ※ 提出ドキュメント（事前提出物・追加ドキュメント）から復元したもの。
--    型はドキュメント記載に基づく推定を含みます。
-- ------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS web_db
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE web_db;

-- ユーザ情報（生年月日 birth・電話番号 tel は追加ドキュメントで追加）
CREATE TABLE userTable (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  user      TEXT,
  pass      TEXT,
  isManager INT  DEFAULT 0,
  birth     DATE,
  tel       TEXT
);

-- 予約情報
CREATE TABLE reservationTable (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  date DATE,
  time INT,
  user TEXT
);

-- おしらせ（投稿）情報
CREATE TABLE noticeTable (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  date      DATE,
  notice    TEXT,
  addressee TEXT
);

-- 動作確認用サンプル（任意）
-- INSERT INTO userTable (user, pass, isManager, birth, tel)
--   VALUES ('master', 'pass', 1, '2000-01-01', '0120111222');
