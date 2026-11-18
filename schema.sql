-- ------------------------------------------------------------
-- 予約管理システム  データベース定義
-- ※ 提出ドキュメント（事前提出物・追加ドキュメント）から復元したもの。
--    型はドキュメント記載に基づく推定を含みます。
-- ------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS web_db
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE web_db;

-- ユーザ情報（生年月日 birth・電話番号 tel は追加ドキュメントで追加）
-- pass には password_hash() で作ったハッシュを保存する
CREATE TABLE userTable (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  user      VARCHAR(50)  NOT NULL,
  pass      VARCHAR(255) NOT NULL,
  isManager INT  DEFAULT 0,
  birth     DATE,
  tel       VARCHAR(20),
  UNIQUE KEY uq_user (user)
);

-- 予約情報（1 つの時間枠を予約できるのは 1 人だけ）
CREATE TABLE reservationTable (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  date DATE        NOT NULL,
  time INT         NOT NULL,
  user VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_slot (date, time)
);

-- おしらせ（投稿）情報
CREATE TABLE noticeTable (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  date      DATE,
  notice    TEXT,
  addressee VARCHAR(50)
);

-- 最初の管理者は、create_admin.php で作成してください（README を参照）。
