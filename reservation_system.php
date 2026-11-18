<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const USER_TABLE        = 'userTable';
const RESERVATION_TABLE = 'reservationTable';
const NOTICE_TABLE      = 'noticeTable';

const OPEN_TIME  = 9;   // 最初の枠の開始時刻
const CLOSE_TIME = 18;  // 最後の枠の開始時刻

const PASSWORD_MIN_LENGTH = 8;

// 「今日」や「過ぎた時間帯」の判定に使うタイムゾーン。PHP の既定（UTC のことが多い）のままだと
// 日本時間の 0〜9 時に日付が 1 日ずれる
const TIMEZONE = 'Asia/Tokyo';
date_default_timezone_set(TIMEZONE);
const NAME_MAX_LENGTH     = 50;

// ------------------------------------------------------------
// セッションとレスポンスヘッダー
// ------------------------------------------------------------
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: same-origin');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ------------------------------------------------------------
// 共通処理
// ------------------------------------------------------------

// 画面に出す値はすべてこの関数でエスケープする
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): mysqli
{
    static $link = null;
    if ($link === null) {
        global $hostname, $username, $password, $dbname;
        $link = new mysqli($hostname, $username, $password, $dbname);
        $link->set_charset('utf8mb4');
    }
    return $link;
}

// プリペアドステートメントで SQL を実行する。入力値を SQL 文に直接埋め込まない
function query(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function fetch_all(string $sql, string $types = '', array $params = []): array
{
    return query($sql, $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
}

function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// CSRF 対策のトークン。すべてのフォームに埋め込み、POST のたびに照合する
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

// フォームの開始タグと、画面遷移・CSRF 用の hidden 項目を出力する
function form_open(string $transition, string $operation = ''): string
{
    $html = '<form method="POST" action="reservation_system.php">'
          . '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">'
          . '<input type="hidden" name="transition" value="' . h($transition) . '">';
    if ($operation !== '') {
        $html .= '<input type="hidden" name="operation" value="' . h($operation) . '">';
    }
    return $html;
}

function hidden(string $name, $value): string
{
    return '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
}

// その時間枠がすでに始まっている（過去の日付、または今日の過ぎた時間帯）か
function slot_started(string $date, int $time): bool
{
    $today = date('Y-m-d');
    return $date < $today || ($date === $today && $time <= (int)date('G'));
}

// yyyymmdd または yyyy-mm-dd を受け付け、yyyy-mm-dd に正規化する。不正なら null
function normalize_date(string $input): ?string
{
    foreach (['Ymd', 'Y-m-d'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $input);
        if ($date !== false && $date->format($format) === $input) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function valid_tel(string $tel): bool
{
    return $tel === '' || preg_match('/\A[0-9+\-]{1,20}\z/', $tel) === 1;
}

function str_length(string $value): int
{
    return mb_strlen($value, 'UTF-8');
}

// ------------------------------------------------------------
// 認証
// ------------------------------------------------------------

// ログイン中のユーザーを毎回データベースから読み直す（権限の変更や削除をすぐに反映するため）
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $rows = fetch_all('SELECT id, user, isManager, birth, tel FROM ' . USER_TABLE . ' WHERE id = ?',
                      'i', [$_SESSION['user_id']]);
    if (!$rows) {
        logout();
        return null;
    }
    $rows[0]['isManager'] = (int)$rows[0]['isManager'];
    return $rows[0];
}

function login(string $name, string $pass): bool
{
    $rows = fetch_all('SELECT id, pass FROM ' . USER_TABLE . ' WHERE user = ?', 's', [$name]);
    if (!$rows) {
        // ユーザーの有無で応答時間が変わらないよう、ダミーの照合を行う
        password_verify($pass, '$2y$10$3BZVzgI8qDsEwzk/yC1bRO4nNlVoH0kzhdsmTORQPZ50gX93JHGZ.');
        return false;
    }
    $id     = (int)$rows[0]['id'];
    $stored = (string)$rows[0]['pass'];

    if (empty(password_get_info($stored)['algo'])) {
        // 旧バージョンで平文保存されたパスワード。一致したらハッシュに置き換える
        if (!hash_equals($stored, $pass)) {
            return false;
        }
        update_password($id, $pass);
    } elseif (!password_verify($pass, $stored)) {
        return false;
    } elseif (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
        update_password($id, $pass);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function update_password(int $id, string $pass): void
{
    query('UPDATE ' . USER_TABLE . ' SET pass = ? WHERE id = ?', 'si',
          [password_hash($pass, PASSWORD_DEFAULT), $id]);
}

// 新規登録。成功したら null、失敗したらエラーメッセージを返す
function signup(): ?string
{
    $name  = post('user');
    $pass  = $_POST['pass'] ?? '';
    $birth = post('birth');
    $tel   = post('tel');

    if ($name === '' || str_length($name) > NAME_MAX_LENGTH) {
        return 'ユーザ名は1〜' . NAME_MAX_LENGTH . '文字で入力してください';
    }
    if (!is_string($pass) || strlen($pass) < PASSWORD_MIN_LENGTH) {
        return 'パスワードは' . PASSWORD_MIN_LENGTH . '文字以上にしてください';
    }
    $birth_date = null;
    if ($birth !== '') {
        $birth_date = normalize_date($birth);
        if ($birth_date === null) {
            return '生年月日は yyyymmdd の形式で入力してください';
        }
    }
    if (!valid_tel($tel)) {
        return '電話番号は数字とハイフンで入力してください';
    }
    if (fetch_all('SELECT id FROM ' . USER_TABLE . ' WHERE user = ?', 's', [$name])) {
        return 'このユーザ名はすでに使用されています';
    }

    try {
        query('INSERT INTO ' . USER_TABLE . ' (user, pass, isManager, birth, tel) VALUES (?, ?, 0, ?, ?)',
              'ssss', [$name, password_hash($pass, PASSWORD_DEFAULT), $birth_date, $tel]);
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) { // 同時に同じ名前で登録された場合
            return 'このユーザ名はすでに使用されています';
        }
        throw $e;
    }
    login($name, $pass);
    return null;
}

// ------------------------------------------------------------
// データ操作（すべて呼び出し側でログインを確認済み）
// ------------------------------------------------------------

function reservation_add(array $me): ?string
{
    $date = normalize_date(post('date'));
    $time = filter_var(post('time'), FILTER_VALIDATE_INT,
                       ['options' => ['min_range' => OPEN_TIME, 'max_range' => CLOSE_TIME]]);
    if ($date === null || $time === false) {
        return '予約する日時が正しくありません';
    }
    if ($date < date('Y-m-d')) {
        return '過去の日付は予約できません';
    }
    if (slot_started($date, $time)) {
        return 'すでに始まっている時間帯は予約できません';
    }
    try {
        query('INSERT INTO ' . RESERVATION_TABLE . ' (date, time, user) VALUES (?, ?, ?)',
              'sis', [$date, $time, $me['user']]);
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) { // 同じ枠がすでに予約されている
            return 'その時間枠はすでに予約されています';
        }
        throw $e;
    }
    return null;
}

function reservation_delete(array $me): void
{
    $id = (int)post('reservation_id');
    if ($me['isManager']) {
        query('DELETE FROM ' . RESERVATION_TABLE . ' WHERE id = ?', 'i', [$id]);
    } else {
        // 一般ユーザーは自分の予約だけを削除できる
        query('DELETE FROM ' . RESERVATION_TABLE . ' WHERE id = ? AND user = ?', 'is', [$id, $me['user']]);
    }
}

function user_edit(array $me): ?string
{
    if (array_key_exists('new_pass', $_POST)) {
        $current = $_POST['current_pass'] ?? '';
        $new     = $_POST['new_pass'];
        $stored  = fetch_all('SELECT pass FROM ' . USER_TABLE . ' WHERE id = ?', 'i', [$me['id']])[0]['pass'];
        if (!is_string($current) || !password_verify($current, $stored)) {
            return '現在のパスワードが違います';
        }
        if (!is_string($new) || strlen($new) < PASSWORD_MIN_LENGTH) {
            return 'パスワードは' . PASSWORD_MIN_LENGTH . '文字以上にしてください';
        }
        update_password((int)$me['id'], $new);
        session_regenerate_id(true);
        return 'パスワードを変更しました';
    }
    if (array_key_exists('new_birth', $_POST)) {
        $birth = normalize_date(post('new_birth'));
        if ($birth === null) {
            return '生年月日は yyyymmdd の形式で入力してください';
        }
        query('UPDATE ' . USER_TABLE . ' SET birth = ? WHERE id = ?', 'si', [$birth, $me['id']]);
    }
    if (array_key_exists('new_tel', $_POST)) {
        $tel = post('new_tel');
        if (!valid_tel($tel)) {
            return '電話番号は数字とハイフンで入力してください';
        }
        query('UPDATE ' . USER_TABLE . ' SET tel = ? WHERE id = ?', 'si', [$tel, $me['id']]);
    }
    return null;
}

// 以下は管理者だけが実行できる操作

function user_delete(array $me): ?string
{
    $id = (int)post('user_id');
    if ($id === (int)$me['id']) {
        return '自分自身は削除できません';
    }
    query('DELETE FROM ' . USER_TABLE . ' WHERE id = ?', 'i', [$id]);
    return null;
}

function manager_set(array $me, int $flag): ?string
{
    $id = (int)post('user_id');
    if ($id === (int)$me['id']) {
        return '自分自身の管理者権限は変更できません';
    }
    query('UPDATE ' . USER_TABLE . ' SET isManager = ? WHERE id = ?', 'ii', [$flag, $id]);
    return null;
}

function notice_add(): ?string
{
    $date      = normalize_date(post('date'));
    $notice    = post('notice');
    $addressee = post('addressee');
    if ($date === null) {
        return '日付は yyyymmdd の形式で入力してください';
    }
    if ($notice === '' || $addressee === '' || str_length($addressee) > NAME_MAX_LENGTH) {
        return '宛先と記事を入力してください';
    }
    query('INSERT INTO ' . NOTICE_TABLE . ' (date, notice, addressee) VALUES (?, ?, ?)',
          'sss', [$date, $notice, $addressee]);
    return null;
}

function notice_delete(): void
{
    query('DELETE FROM ' . NOTICE_TABLE . ' WHERE id = ?', 'i', [(int)post('notice_id')]);
}

// ------------------------------------------------------------
// 画面
// ------------------------------------------------------------

function message_html(string $msg): string
{
    return $msg === '' ? '' : '<font color="red">' . h($msg) . '</font><br>';
}

function echo_page_login(string $msg): void
{
    echo '<h1>Login</h1><hr><hr>';
    echo message_html($msg);
    echo form_open('trans_login_re');
    echo '    NAME <input type="text" name="user" autocomplete="username"><br>';
    echo '    PASS <input type="password" name="pass" autocomplete="current-password"><br>';
    echo '    <button type="submit">Login</button>';
    echo '</form><br><hr><hr>';
    echo form_open('trans_signup');
    echo '    <button type="submit">Sign Up</button>';
    echo '</form>';
}

function echo_page_signup(string $msg): void
{
    echo '<h1>Sign Up</h1><hr><hr>';
    echo message_html($msg);
    echo form_open('trans_signup_re');
    echo '    NAME <input type="text" name="user" maxlength="' . NAME_MAX_LENGTH . '" autocomplete="username"><br>';
    echo '    PASS <input type="password" name="pass" minlength="' . PASSWORD_MIN_LENGTH . '" autocomplete="new-password">'
       . '（' . PASSWORD_MIN_LENGTH . '文字以上）<br>';
    echo '    BIRTH <input type="text" name="birth" placeholder="yyyymmdd"><br>';
    echo '    TEL <input type="text" name="tel"><br>';
    echo '    <button type="submit">Sign Up</button>';
    echo '</form><br><hr><hr>';
    echo form_open('trans_login');
    echo '    <button type="submit">Login</button>';
    echo '</form>';
}

function format_slot(array $row): string
{
    $time = (int)$row['time'];
    return h($row['date']) . '<br>' . $time . ':00~' . ($time + 1) . ':00<br>';
}

function echo_page_main(array $me, string $msg): void
{
    echo '<h1>こんにちは、' . h($me['user']) . 'さん</h1><hr>';
    echo message_html($msg);

    if (!$me['isManager']) {
        echo '<hr><h3>&lt;現在のご予約&gt;</h3>';
        $rows = fetch_all('SELECT date, time FROM ' . RESERVATION_TABLE . ' WHERE user = ? ORDER BY date, time',
                          's', [$me['user']]);
        if (!$rows) {
            echo 'ご予約はありません';
        }
        foreach ($rows as $row) {
            echo format_slot($row) . '<br>';
        }

        echo '<hr><h3>&lt;メニュー&gt;</h3>';
        echo form_open('trans_reservation_add') . '<button type="submit">予約を追加する</button></form><br>';
        echo form_open('trans_reservation_delete') . '<button type="submit">予約を削除する</button></form>';

        echo '<hr><h3>&lt;おしらせ&gt;</h3>';
        $rows = fetch_all('SELECT date, notice FROM ' . NOTICE_TABLE
                        . " WHERE addressee = ? OR addressee = 'everyone' ORDER BY date DESC",
                          's', [$me['user']]);
        foreach ($rows as $row) {
            echo h($row['date']) . '<br>' . nl2br(h($row['notice'])) . '<br><br>';
        }
    } else {
        echo '<hr><h3>&lt;管理者メニュー&gt;</h3>';
        echo form_open('trans_user_manage') . '<button type="submit">ユーザを管理する</button></form><br>';
        echo form_open('trans_reservation_manage') . '<button type="submit">予約を管理する</button></form><br>';
        echo form_open('trans_notice_manage') . '<button type="submit">投稿を管理する</button></form>';
    }
    echo_footer();
}

function echo_page_reservation_add(string $msg): void
{
    echo '<h1>予約の追加</h1><hr>';
    echo message_html($msg);
    $date = normalize_date(post('date'));

    if ($date === null) {
        if (post('date') !== '') {
            echo message_html('日付は yyyymmdd の形式で入力してください');
        }
        echo '<hr><h3>予約する日付を入力してください</h3>';
        echo form_open('trans_reservation_add');
        echo '    yyyymmdd <input type="text" name="date"><br>';
        echo '    <button type="submit">検索する</button>';
        echo '</form>';
        echo_footer();
        return;
    }

    $reserved = [];
    foreach (fetch_all('SELECT time FROM ' . RESERVATION_TABLE . ' WHERE date = ?', 's', [$date]) as $row) {
        $reserved[(int)$row['time']] = true;
    }

    echo '<hr><h3>' . h($date) . ' の予約する時間帯を選択してください</h3>';
    $count = 0;
    for ($i = OPEN_TIME; $i <= CLOSE_TIME; $i++) {
        if (isset($reserved[$i]) || slot_started($date, $i)) {
            continue;
        }
        $count++;
        echo '<hr>' . $i . ':00~' . ($i + 1) . ':00<br>';
        echo form_open('trans_main', 'reservation_add') . hidden('date', $date) . hidden('time', $i)
           . '<button type="submit">予約する</button></form>';
    }
    if ($count === 0) {
        echo 'ご希望の日に予約できる時間帯はありません';
    }
    echo_footer();
}

function echo_page_reservation_delete(array $me): void
{
    echo '<h1>予約の削除</h1><hr>';
    echo '<hr><h3>削除する予約を選択してください</h3>';
    $rows = fetch_all('SELECT id, date, time FROM ' . RESERVATION_TABLE . ' WHERE user = ? ORDER BY date, time',
                      's', [$me['user']]);
    foreach ($rows as $row) {
        echo '<hr>' . format_slot($row);
        echo form_open('trans_reservation_delete', 'reservation_delete') . hidden('reservation_id', $row['id'])
           . '<button type="submit">削除する</button></form>';
    }
    echo_footer();
}

function echo_page_reservation_manage(): void
{
    echo '<h1>予約の管理</h1><hr>';
    $rows = fetch_all('SELECT id, date, time, user FROM ' . RESERVATION_TABLE . ' ORDER BY date, time');
    foreach ($rows as $row) {
        echo '<hr>NAME：' . h($row['user']) . '<br><br>' . format_slot($row);
        echo form_open('trans_reservation_manage', 'reservation_delete') . hidden('reservation_id', $row['id'])
           . '<button type="submit">削除する</button></form>';
    }
    echo_footer();
}

function echo_page_user_manage(array $me, string $msg): void
{
    echo '<h1>ユーザの管理</h1><hr>';
    echo message_html($msg);
    $rows = fetch_all('SELECT id, user, isManager, birth, tel FROM ' . USER_TABLE . ' ORDER BY id');
    foreach ($rows as $row) {
        $is_self = (int)$row['id'] === (int)$me['id'];
        echo '<hr>NAME ：' . h($row['user']);
        if ((int)$row['isManager'] === 1) {
            echo '　　★';
        }
        echo '<br>BIRTH：' . h($row['birth']) . '<br>';
        echo 'TEL  ：' . h($row['tel']) . '<br>';
        if ($is_self) {
            echo '（ログイン中のユーザ）<br>';
            continue;
        }
        echo form_open('trans_user_manage', 'user_delete') . hidden('user_id', $row['id'])
           . '<button type="submit">削除する</button></form>';
        if ((int)$row['isManager'] === 0) {
            echo form_open('trans_user_manage', 'manager_add') . hidden('user_id', $row['id'])
               . '<button type="submit">管理者権限を有効にする</button></form>';
        } else {
            echo form_open('trans_user_manage', 'manager_delete') . hidden('user_id', $row['id'])
               . '<button type="submit">管理者権限を無効にする</button></form>';
        }
    }
    echo_footer();
}

function echo_page_notice_manage(string $msg): void
{
    echo '<h1>投稿の管理</h1><hr>';
    echo message_html($msg);
    echo '<hr><br>';
    echo form_open('trans_notice_add') . '<button type="submit">追加する</button></form>';
    $rows = fetch_all('SELECT id, date, notice, addressee FROM ' . NOTICE_TABLE . ' ORDER BY date DESC');
    foreach ($rows as $row) {
        echo '<hr>宛先:' . h($row['addressee']) . '<br>' . h($row['date']) . '<br>' . nl2br(h($row['notice'])) . '<br><br>';
        echo form_open('trans_notice_manage', 'notice_delete') . hidden('notice_id', $row['id'])
           . '<button type="submit">削除する</button></form>';
    }
    echo_footer();
}

function echo_page_notice_add(): void
{
    echo '<h1>投稿の追加</h1><hr><hr>';
    echo form_open('trans_notice_manage', 'notice_add');
    echo '    宛先<textarea name="addressee" rows="1" cols="10">everyone</textarea><br>';
    echo '    日付<input type="text" name="date" placeholder="yyyymmdd"><br>';
    echo '    記事<textarea name="notice" rows="5" cols="30"></textarea><br>';
    echo '    <button type="submit">投稿する</button>';
    echo '</form>';
    echo_footer();
}

function echo_page_user_edit(array $me, string $msg): void
{
    echo '<h1>アカウントの管理</h1><hr>';
    echo message_html($msg);
    echo '<hr>PASS：********';
    echo form_open('trans_user_edit', 'user_edit');
    echo '    現在のパスワード <input type="password" name="current_pass" autocomplete="current-password"><br>';
    echo '    新しいパスワード <input type="password" name="new_pass" minlength="' . PASSWORD_MIN_LENGTH
       . '" autocomplete="new-password"><br>';
    echo '    <button type="submit">変更する</button>';
    echo '</form><br>';
    echo 'BIRTH：' . h($me['birth']);
    echo form_open('trans_user_edit', 'user_edit');
    echo '    <input type="text" name="new_birth" placeholder="yyyymmdd"><br>';
    echo '    <button type="submit">変更する</button>';
    echo '</form><br>';
    echo 'TEL：' . h($me['tel']);
    echo form_open('trans_user_edit', 'user_edit');
    echo '    <input type="text" name="new_tel"><br>';
    echo '    <button type="submit">変更する</button>';
    echo '</form>';
    echo_footer();
}

function echo_footer(): void
{
    echo '<br><hr><hr>';
    echo form_open('trans_main') . '<button type="submit">ホームへ戻る</button></form><br><br>';
    echo form_open('trans_user_edit') . '<button type="submit">Account</button></form>';
    echo form_open('trans_logout') . '<button type="submit">Logout</button></form>';
}

// ------------------------------------------------------------
// リクエストの処理
// ------------------------------------------------------------

// 操作を実行し、画面に出すメッセージを返す。権限のない操作は実行しない
function run_operation(array $me, string $operation): string
{
    $user_operations = ['reservation_add', 'reservation_delete', 'user_edit'];
    $manager_operations = ['user_delete', 'manager_add', 'manager_delete', 'notice_add', 'notice_delete'];

    if (in_array($operation, $manager_operations, true) && !$me['isManager']) {
        return 'この操作を行う権限がありません';
    }
    if (!in_array($operation, $user_operations, true) && !in_array($operation, $manager_operations, true)) {
        return '';
    }

    switch ($operation) {
        case 'reservation_add':    return reservation_add($me) ?? '';
        case 'reservation_delete': reservation_delete($me); return '';
        case 'user_edit':          return user_edit($me) ?? '';
        case 'user_delete':        return user_delete($me) ?? '';
        case 'manager_add':        return manager_set($me, 1) ?? '';
        case 'manager_delete':     return manager_set($me, 0) ?? '';
        case 'notice_add':         return notice_add() ?? '';
        case 'notice_delete':      notice_delete(); return '';
    }
    return '';
}

function handle_request(): void
{
    $is_post    = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $transition = $is_post ? post('transition') : '';
    $msg        = '';

    if ($is_post && !csrf_valid()) {
        echo_page_login('セッションの有効期限が切れました。もう一度ログインしてください');
        return;
    }

    if ($transition === 'trans_logout') {
        logout();
        echo_page_login('');
        return;
    }
    if ($transition === 'trans_login_re') {
        $pass = $_POST['pass'] ?? '';
        if (!is_string($pass) || !login(post('user'), $pass)) {
            echo_page_login('ユーザ名またはパスワードが違います');
            return;
        }
        $transition = 'trans_main';
    }
    if ($transition === 'trans_signup_re') {
        $error = signup();
        if ($error !== null) {
            echo_page_signup($error);
            return;
        }
        $transition = 'trans_main';
    }

    $me = current_user();
    if ($me === null) {
        if ($transition === 'trans_signup') {
            echo_page_signup('');
        } else {
            echo_page_login('');
        }
        return;
    }

    if ($is_post && post('operation') !== '') {
        $msg = run_operation($me, post('operation'));
        $me  = current_user() ?? $me;
    }

    $manager_pages = ['trans_user_manage', 'trans_reservation_manage', 'trans_notice_manage', 'trans_notice_add'];
    if (in_array($transition, $manager_pages, true) && !$me['isManager']) {
        echo_page_main($me, 'この画面を表示する権限がありません');
        return;
    }

    switch ($transition) {
        case 'trans_reservation_add':    echo_page_reservation_add($msg); break;
        case 'trans_reservation_delete': echo_page_reservation_delete($me); break;
        case 'trans_user_edit':          echo_page_user_edit($me, $msg); break;
        case 'trans_user_manage':        echo_page_user_manage($me, $msg); break;
        case 'trans_reservation_manage': echo_page_reservation_manage(); break;
        case 'trans_notice_manage':      echo_page_notice_manage($msg); break;
        case 'trans_notice_add':         echo_page_notice_add(); break;
        default:                         echo_page_main($me, $msg); break;
    }
}

ob_start();
try {
    handle_request();
    $body = ob_get_clean();
} catch (mysqli_sql_exception $e) {
    ob_end_clean();
    error_log('reservation_system: ' . $e->getMessage());
    http_response_code(500);
    $body = '<h1>エラー</h1><p>データベースの処理中にエラーが発生しました。</p>';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Reservation System</title>
</head>
<body>
<?= $body ?>
</body>
</html>
