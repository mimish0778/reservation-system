<?php
// ------------------------------------------------------------
// 予約管理システムの動作とセキュリティ対策を、HTTP 経由で確認するテスト
//
//   1. schema.sql でデータベースを作り、config.php を用意する
//   2. php -S 127.0.0.1:8000 でサーバーを起動する
//   3. php tests/run_tests.php を実行する
//
// テストのたびにテーブルの中身を空にします。本番のデータベースでは実行しないでください。
// ------------------------------------------------------------
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$base_url = getenv('APP_URL') ?: 'http://127.0.0.1:8000/reservation_system.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($hostname, $username, $password, $dbname);
$db->set_charset('utf8mb4');

function scalar(string $sql)
{
    global $db;
    $row = $db->query($sql)->fetch_row();
    return $row === null ? null : $row[0];
}

// ブラウザ 1 つ分（Cookie と直前のページ）を表すクライアント
class Client
{
    private string $cookie_file;
    public string $html = '';

    public function __construct()
    {
        $this->cookie_file = tempnam(sys_get_temp_dir(), 'rs-cookie-');
        $this->open();
    }

    public function open(): string
    {
        return $this->request(null);
    }

    // 直前のページの CSRF トークンを付けて POST する（$token に値を渡すとそれを使う）
    public function post(array $form, ?string $token = null): string
    {
        $form['csrf_token'] = $token ?? $this->token();
        return $this->request($form);
    }

    public function token(): string
    {
        preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $this->html, $m);
        return $m[1] ?? '';
    }

    public function text(): string
    {
        return html_entity_decode(strip_tags($this->html), ENT_QUOTES, 'UTF-8');
    }

    private function request(?array $form): string
    {
        global $base_url;
        $ch = curl_init($base_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookie_file,
            CURLOPT_COOKIEFILE     => $this->cookie_file,
        ]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $this->html = (string)curl_exec($ch);
        curl_close($ch);
        return $this->html;
    }
}

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
}

function login(string $name, string $pass): Client
{
    $c = new Client();
    $c->post(['transition' => 'trans_login_re', 'user' => $name, 'pass' => $pass]);
    return $c;
}

// --- 準備: テーブルを空にし、管理者と、旧バージョンの平文パスワードのユーザーを用意する
foreach (['userTable', 'reservationTable', 'noticeTable'] as $table) {
    $db->query("DELETE FROM $table");
}
$stmt = $db->prepare('INSERT INTO userTable (user, pass, isManager) VALUES (?, ?, ?)');
foreach ([['admin', password_hash('adminpass1', PASSWORD_DEFAULT), 1], ['legacy', 'plainpass', 0]] as $u) {
    $stmt->bind_param('ssi', ...$u);
    $stmt->execute();
}
$admin_id  = (int)scalar("SELECT id FROM userTable WHERE user = 'admin'");
$future    = date('Ymd', strtotime('+30 days'));
$future_db = date('Y-m-d', strtotime('+30 days'));

// --- サインアップ
$xss = '<img src=x onerror=alert(1)>';
$alice = new Client();
$alice->post(['transition' => 'trans_signup_re', 'user' => 'alice', 'pass' => 'short']);
check('短いパスワードは登録できない', str_contains($alice->text(), '8文字以上'));
$alice->post(['transition' => 'trans_signup_re', 'user' => $xss, 'pass' => 'alicepass1', 'birth' => '20000101']);
check('登録するとそのままログインする', str_contains($alice->text(), "こんにちは、{$xss}さん"));
check('ユーザ名の HTML はエスケープされる', !str_contains($alice->html, '<img') && str_contains($alice->html, '&lt;img'));
check('パスワードはハッシュで保存される', str_starts_with((string)scalar("SELECT pass FROM userTable WHERE birth = '2000-01-01'"), '$2y$'));
$alice_id = (int)scalar("SELECT id FROM userTable WHERE birth = '2000-01-01'");
$dup = new Client();
$dup->post(['transition' => 'trans_signup_re', 'user' => 'admin', 'pass' => 'anotherpass']);
check('同じユーザ名では登録できない', str_contains($dup->text(), 'すでに使用されています'));

// --- 予約
$alice->post(['transition' => 'trans_reservation_add', 'date' => $future]);
check('空いている時間枠が 10 個表示される', substr_count($alice->html, '>予約する</button>') === 10);
$alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $future, 'time' => '9']);
check('予約が一覧に表示される', str_contains($alice->text(), $future_db));
$alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $future, 'time' => '9']);
check('同じ時間枠は二重に予約できない', str_contains($alice->text(), 'すでに予約されています'));
$alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $future, 'time' => '9; DROP TABLE userTable']);
check('不正な時刻は受け付けない', str_contains($alice->text(), '日時が正しくありません') && scalar('SELECT COUNT(*) FROM userTable') >= 3);
$alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => '20000101', 'time' => '10']);
check('過去の日付は予約できない', str_contains($alice->text(), '過去の日付'));
// 今日の時間枠は、過ぎた時間帯（日本時間）を除いて表示され、過ぎた時間帯は予約できない
$tokyo_now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$today      = $tokyo_now->format('Ymd');
$hour       = (int)$tokyo_now->format('G');
$open_slots = count(array_filter(range(9, 18), fn($h) => $h > $hour));
$alice->post(['transition' => 'trans_reservation_add', 'date' => $today]);
check('今日は過ぎた時間帯を除いて表示する', substr_count($alice->html, '>予約する</button>') === $open_slots);
if ($hour >= 9) {
    $alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $today, 'time' => '9']);
    check('今日の過ぎた時間帯は予約できない', str_contains($alice->text(), 'すでに始まっている時間帯'));
}
$alice->post(['transition' => 'trans_reservation_add', 'date' => "{$future}' OR '1'='1"]);
check('不正な日付では検索しない', str_contains($alice->text(), 'yyyymmdd の形式'));

// --- 権限
$alice->post(['transition' => 'trans_main', 'operation' => 'user_delete', 'user_id' => (string)$admin_id]);
check('一般ユーザはユーザを削除できない', str_contains($alice->text(), '権限がありません') && scalar("SELECT COUNT(*) FROM userTable WHERE id = $admin_id") == 1);
$alice->post(['transition' => 'trans_main', 'operation' => 'manager_add', 'user_id' => (string)$alice_id]);
check('一般ユーザは自分を管理者にできない', scalar("SELECT isManager FROM userTable WHERE id = $alice_id") == 0);
$alice->post(['transition' => 'trans_user_manage']);
check('一般ユーザは管理画面を開けない', str_contains($alice->text(), 'この画面を表示する権限がありません'));
$alice->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $future, 'time' => '10'], 'invalid-token');
check('CSRF トークンが違うと操作できない', str_contains($alice->text(), 'セッションの有効期限') && scalar("SELECT COUNT(*) FROM reservationTable WHERE time = 10") == 0);

$reservation_id = (int)scalar('SELECT id FROM reservationTable LIMIT 1');
$anonymous = new Client();
$anonymous->post(['transition' => 'trans_main', 'operation' => 'reservation_delete', 'reservation_id' => (string)$reservation_id]);
check('ログインしていないと操作できない', scalar("SELECT COUNT(*) FROM reservationTable WHERE id = $reservation_id") == 1);

// --- ログイン
$legacy = login('legacy', 'plainpass');
check('旧形式の平文パスワードでもログインできる', str_contains($legacy->text(), 'こんにちは、legacyさん'));
check('ログインすると平文パスワードがハッシュに置き換わる', str_starts_with((string)scalar("SELECT pass FROM userTable WHERE user = 'legacy'"), '$2y$'));
$legacy->post(['transition' => 'trans_main', 'operation' => 'reservation_delete', 'reservation_id' => (string)$reservation_id]);
check('他人の予約は削除できない', scalar("SELECT COUNT(*) FROM reservationTable WHERE id = $reservation_id") == 1);
$injection = login("admin' -- ", "x' OR '1'='1");
check('SQL インジェクションではログインできない', str_contains($injection->text(), 'ユーザ名またはパスワードが違います'));

// --- アカウント
$alice->post(['transition' => 'trans_user_edit']);
check('アカウント画面にパスワードを表示しない', str_contains($alice->text(), 'PASS：********') && !str_contains($alice->html, 'alicepass1'));
$alice->post(['transition' => 'trans_user_edit', 'operation' => 'user_edit', 'current_pass' => 'wrongpass', 'new_pass' => 'newalicepass']);
check('現在のパスワードが違うと変更できない', str_contains($alice->text(), '現在のパスワードが違います'));
$alice->post(['transition' => 'trans_user_edit', 'operation' => 'user_edit', 'current_pass' => 'alicepass1', 'new_pass' => 'newalicepass']);
check('パスワードを変更できる', str_contains($alice->text(), 'パスワードを変更しました'));

// --- 管理者
$admin = login('admin', 'adminpass1');
check('管理者メニューが表示される', str_contains($admin->text(), '管理者メニュー'));
$admin->post(['transition' => 'trans_notice_manage', 'operation' => 'notice_add', 'addressee' => 'everyone', 'date' => $future, 'notice' => '<script>alert(1)</script>お知らせ']);
check('お知らせを投稿でき、エスケープして表示する', str_contains($admin->text(), '<script>alert(1)</script>お知らせ') && !str_contains($admin->html, '<script>'));
$admin->post(['transition' => 'trans_user_manage', 'operation' => 'user_delete', 'user_id' => (string)$admin_id]);
check('管理者は自分を削除できない', str_contains($admin->text(), '自分自身は削除できません'));
$admin->post(['transition' => 'trans_user_manage', 'operation' => 'manager_add', 'user_id' => (string)$alice_id]);
check('管理者は権限を付与できる', scalar("SELECT isManager FROM userTable WHERE id = $alice_id") == 1);
$alice->post(['transition' => 'trans_main']);
check('権限の変更は相手の次の操作から反映される', str_contains($alice->text(), '管理者メニュー'));

// --- ログアウト
$legacy->post(['transition' => 'trans_logout']);
$legacy->post(['transition' => 'trans_main', 'operation' => 'reservation_add', 'date' => $future, 'time' => '11']);
check('ログアウト後は操作できない', scalar('SELECT COUNT(*) FROM reservationTable WHERE time = 11') == 0);
$legacy = login('legacy', 'plainpass');
check('一般ユーザに全員宛てのお知らせが表示される', str_contains($legacy->text(), '<script>alert(1)</script>お知らせ'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
