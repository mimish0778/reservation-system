<?php
require_once dirname(__DIR__) . '/config.php';
$user_tablename = 'userTable';
$question_tablename = 'questionTable';
mysqli_report(MYSQLI_REPORT_OFF);

$isMember = auth_login();

//ページ遷移
if (!array_key_exists('transition', $_POST))
{
    echo_page_main();
}
elseif ($_POST['transition'] == "trans_main")
{
    echo_page_main();
}
elseif ($_POST['transition'] == "trans_login")
{
    echo_page_login();
}

//ログイン認証
function auth_login()
{
    global $hostname, $username, $password, $dbname, $user_tablename;
    global $isMember;

    $isMember = false;
    if (array_key_exists('user', $_POST))
    {
        $user = $_POST['user'];
        $pass = $_POST['pass'];

        $link = mysqli_connect($hostname, $username, $password, $dbname);
        if (!$link){ exit("Connect error!"); }
        $result = mysqli_query($link, "SELECT * FROM $user_tablename");
        if (!$result){ exit("Select error on table ($user_tablename)!"); } 

        while ($row_assoc = mysqli_fetch_assoc($result))
        {
            $passlist[$row_assoc['user']] = $row_assoc['pass'];
        }

        if ((array_key_exists($user, $passlist)) && $passlist[$user] == $pass)
        {
            $isMember = true;
        }

        mysqli_free_result($result);
        mysqli_close($link);
    }
    return $isMember;
}

//ログイン画面表示
function echo_page_login($msg)
{
    echo <<<EOT
    <!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Questioner</title>
        </head>
        <body>
            <h1>Login Page</h1><hr>
            $msg<br>
            <form method="POST" action="main.php">
                username <input type="text" name="user"><br>
                password <input type="password" name="pass"><br>
                <button type="submit" name="btn_login">Login</button>
            </form>
            <br>
            <hr>
            <form method="POST" action="signup.php">
                <button type="submit" name="btn_signup">Sign Up</button>
            </form>
        </body>
    </html>
EOT;
}

//メイン画面表示
function echo_page_main()
{
    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo <<<EOT
    <!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Questioner</title>
        </head>
        <body>
            <form method="post" action="main.php">
                <button type="submit" name="btn_login">ログイン</button>
                <input type="hidden" name="transition" value="trans_login">
            </form>
            <h1>Main Page</h1><hr>
            <form method="post" action="main.php">
                <button type="submit" name="btn_question">出題</button>
                <input type="hidden" name="transition" value="trans_question">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
            <br>
        </body>
    </html>
EOT;
}

?>