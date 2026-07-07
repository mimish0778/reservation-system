<!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Questioner</title>
        </head>
<?php
require_once dirname(__DIR__) . '/config.php';
$user_tablename = 'userTable';
$question_tablename = 'questionTable';
mysqli_report(MYSQLI_REPORT_OFF);

$is_member = 0;
$is_manager = 0;

//認証
if (array_key_exists('user', $_POST))
{
    auth_signup();
    auth_login();
}

//ページ遷移
if ($is_member == 0)
{
    if (!array_key_exists('transition', $_POST))
    {
        echo_page_login("");
    }
    elseif ($_POST['transition'] == "trans_login")
    {
        echo_page_login("");
    }
    elseif ($_POST['transition'] == "trans_login_re")
    {
        echo_page_login("パスワードが違います");
    }
    elseif ($_POST['transition'] == "trans_signup")
    {
        echo_page_signup("");
    }
    elseif ($_POST['transition'] == "trans_signup_re")
    {
        echo_page_signup("すでにユーザ名が使用されています");
    }
}
else
{
    if ($_POST['transition'] == "trans_main")
    {
        echo_page_main();
    }
    else
    {
        echo_page_main();
    }
}


//ログイン認証
function auth_login()
{
    global $hostname, $username, $password, $dbname, $user_tablename;
    global $is_member, $is_manager;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $user_tablename");
    if (!$result){ exit("Select error on table ($user_tablename)!"); } 

    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        if (($row_assoc['user'] == $user) && ($row_assoc['pass'] == $pass))
        {
            $is_member = 1;
            $is_manager = $row_assoc['isManager'];
        }
    }

    mysqli_free_result($result);
    mysqli_close($link);
}

//サインアップ認証
function auth_signup()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $is_valid = 1;
    if ($_POST['transition'] == "trans_signup_re")
    {
        $user = $_POST['user'];
        $pass = $_POST['pass'];

        $link = mysqli_connect($hostname, $username, $password, $dbname);
        if (!$link){ exit("Connect error!"); }
        $result = mysqli_query($link, "SELECT * FROM $user_tablename");
        if (!$result){ exit("Select error on table ($user_tablename)!"); } 

        while ($row_assoc = mysqli_fetch_assoc($result))
        {
            if ($row_assoc['user'] == $user)
            {
                $is_valid = 0;
            }
        }

        if ($is_valid == 1)
        {
            insert_user();
        }

        mysqli_free_result($result);
        mysqli_close($link);
    }
}

//ユーザ追加
function insert_user()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"INSERT INTO $user_tablename SET user = '$user', pass = '$pass', isManager = 0");
    if (!$result){ exit("Insert error on table ($user_tablename)!"); } 

    mysqli_close($link);
}

//ログイン画面表示
function echo_page_login($msg)
{
    echo <<<EOT
        <body>
            <h1>Login</h1><hr>
            $msg<br>
            <form method="POST" action="questioner.php">
                username <input type="text" name="user"><br>
                password <input type="password" name="pass"><br>
                <button type="submit" name="btn_login">Login</button>
                <input type="hidden" name="transition" value="trans_login_re">
            </form>
            <br>
            <hr>
            <form method="POST" action="questioner.php">
                <button type="submit" name="btn_signup">Sign Up</button>
                <input type="hidden" name="transition" value="trans_signup">
            </form>
        </body>
EOT;
}

//サインアップ画面表示
function echo_page_signup($msg)
{
    echo <<<EOT
        <body>
            <h1>Sign Up</h1><hr>
            $msg<br>
            <form method="POST" action="questioner.php">
                username <input type="text" name="user"><br>
                password <input type="password" name="pass"><br>
                <button type="submit" name="btn_signup">Sign Up</button>
                <input type="hidden" name="transition" value="trans_signup_re">
            </form>
            <br>
            <hr>
            <form method="POST" action="questioner.php">
                <button type="submit" name="btn_login">Login</button>
                <input type="hidden" name="transition" value="trans_login">
            </form>
        </body>
EOT;
}

//メイン画面表示
function echo_page_main()
{
    global $is_manager;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo <<<EOT
        <body>
            <h1>Hello $user!!</h1><hr>
            <form method="post" action="questioner.php">
                <button type="submit" name="btn_question">問題出題</button>
                <input type="hidden" name="transition" value="trans_question">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
            <br>
        </body>
EOT;
}



?>
</html>