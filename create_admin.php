<?php
// ------------------------------------------------------------
// 最初の管理者アカウントを作るコマンドラインスクリプト
//
//     php create_admin.php <ユーザ名>
//
// パスワードは画面に表示せずに入力し、ハッシュにしてから保存します。
// Web サーバーからは実行できません。
// ------------------------------------------------------------
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$name = $argv[1] ?? '';
if ($name === '' || mb_strlen($name, 'UTF-8') > 50) {
    fwrite(STDERR, "使い方: php create_admin.php <ユーザ名（50文字以内）>\n");
    exit(1);
}

function read_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $value;
}

$pass = read_password('パスワード（8文字以上）: ');
if (strlen($pass) < 8) {
    fwrite(STDERR, "パスワードは8文字以上にしてください\n");
    exit(1);
}
if (read_password('パスワード（確認）: ') !== $pass) {
    fwrite(STDERR, "パスワードが一致しません\n");
    exit(1);
}

$link = new mysqli($hostname, $username, $password, $dbname);
$link->set_charset('utf8mb4');
$stmt = $link->prepare('INSERT INTO userTable (user, pass, isManager) VALUES (?, ?, 1)');
$hash = password_hash($pass, PASSWORD_DEFAULT);
$stmt->bind_param('ss', $name, $hash);
try {
    $stmt->execute();
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, $e->getCode() === 1062 ? "このユーザ名はすでに使用されています\n" : $e->getMessage() . "\n");
    exit(1);
}
echo "管理者 {$name} を作成しました\n";
