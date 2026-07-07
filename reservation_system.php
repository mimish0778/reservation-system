<!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Reservation System</title>
        </head>
<?php
require_once __DIR__ . '/config.php';
$user_tablename = 'userTable';
$reservation_tablename = 'reservationTable';
$notice_tablename = 'noticeTable';

$open_time = 9;
$close_time = 18;

mysqli_report(MYSQLI_REPORT_OFF);

$is_member = 0;
$is_manager = 0;

//認証
if (array_key_exists('user', $_POST))
{
    auth_signup();
    auth_login();
}

//DB操作
if(array_key_exists('operation', $_POST))
{
    if ($_POST['operation'] == "user_delete")
    {
        user_delete();
    }
    if ($_POST['operation'] == "user_edit")
    {
        user_edit();
    }
    if ($_POST['operation'] == "reservation_add")
    {
        reservation_add();
    }
    if ($_POST['operation'] == "reservation_delete")
    {
        reservation_delete();
    }
    if ($_POST['operation'] == "notice_add")
    {
        notice_add();
    }
    if ($_POST['operation'] == "notice_delete")
    {
        notice_delete();
    }
    if ($_POST['operation'] == "manager_add")
    {
        manager_add();
    }
    if ($_POST['operation'] == "manager_delete")
    {
        manager_delete();
    }
    
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
        echo_page_signup("このユーザ名はすでに使用されています");
    }
}
else
{
    if ($_POST['transition'] == "trans_main")
    {
        echo_page_main();
    }
    elseif ($_POST['transition'] == "trans_reservation_add")
    {
        echo_page_reservation_add();
    }
    elseif ($_POST['transition'] == "trans_reservation_delete")
    {
        echo_page_reservation_delete();
    }
    elseif ($_POST['transition'] == "trans_user_edit")
    {
        echo_page_user_edit();
    }
    elseif ($_POST['transition'] == "trans_user_manage")
    {
        echo_page_user_manage();
    }
    elseif ($_POST['transition'] == "trans_reservation_manage")
    {
        echo_page_reservation_manage();
    }
    elseif ($_POST['transition'] == "trans_notice_manage")
    {
        echo_page_notice_manage();
    }
    elseif ($_POST['transition'] == "trans_notice_add")
    {
        echo_page_notice_add();
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
            user_add();
        }

        mysqli_free_result($result);
        mysqli_close($link);
    }
}

//ログイン画面表示
function echo_page_login($msg)
{
    echo <<<EOT
        <body>
            <h1>Login</h1><hr><hr>
            <font color="red">$msg</font><br>
            <form method="POST" action="reservation_system.php">
                NAME <input type="text" name="user"><br>
                PASS <input type="password" name="pass"><br>
                <button type="submit" name="btn_login">Login</button>
                <input type="hidden" name="transition" value="trans_login_re">
            </form>
            <br>
            <hr><hr>
            <form method="POST" action="reservation_system.php">
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
            <h1>Sign Up</h1><hr><hr>
            <font color="red">$msg</font><br>
            <form method="POST" action="reservation_system.php">
                NAME <input type="text" name="user"><br>
                PASS <input type="password" name="pass"><br>
                BIRTH <input type="text" name="birth"><br>
                TEL <input type="text" name="tel"><br>
                <button type="submit" name="btn_signup">Sign Up</button>
                <input type="hidden" name="transition" value="trans_signup_re">
            </form>
            <br>
            <hr><hr>
            <form method="POST" action="reservation_system.php">
                <button type="submit" name="btn_login">Login</button>
                <input type="hidden" name="transition" value="trans_login">
            </form>
        </body>
EOT;
}

//メイン画面表示
function echo_page_main()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename, $notice_tablename;
    global $is_manager;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>こんにちは、'.$user.'さん</h1><hr>';
    if ($is_manager == 0)
    {
        echo '<hr><h3><現在のご予約></h3>';

        $link = mysqli_connect($hostname, $username, $password, $dbname);
        if (!$link){ exit("Connect error!"); }
        $result = mysqli_query($link, "SELECT * FROM $reservation_tablename WHERE user = '$user'");
        if (!$result){ exit("Select error on table ($reservation_tablename)!"); } 

        if (mysqli_num_rows($result) == 0)
        {
            echo 'ご予約はありません';
        } 
        while ($row_assoc = mysqli_fetch_assoc($result))
        {
            $id = $row_assoc['id'];
            echo $row_assoc['date'].'<br>';
            echo $row_assoc['time'].':00~'.($row_assoc['time']+1).':00<br><br>';
        }
        mysqli_free_result($result);
        mysqli_close($link);

        echo '<hr><h3><メニュー></h3>';
        echo <<<EOT
            <form method="post" action="reservation_system.php">
                <button type="submit" name="btn_reservation_add">予約を追加する</button>
                <input type="hidden" name="transition" value="trans_reservation_add">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
            <br>
            <form method="post" action="reservation_system.php">
                <button type="submit" name="btn_reservation_delete">予約を削除する</button>
                <input type="hidden" name="transition" value="trans_reservation_delete">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
EOT;
        echo '<hr><h3><おしらせ></h3>';

        $link = mysqli_connect($hostname, $username, $password, $dbname);
        if (!$link){ exit("Connect error!"); }
        $result = mysqli_query($link, "SELECT * FROM $notice_tablename WHERE (addressee = '$user') OR (addressee = 'everyone')");
        if (!$result){ exit("Select error on table ($notice_tablename)!"); } 

        while ($row_assoc = mysqli_fetch_assoc($result))
        {
            echo $row_assoc['date'].'<br>';
            echo $row_assoc['notice'].'<br><br>';
        }
        mysqli_free_result($result);
        mysqli_close($link);
    }
    else
    {
        echo '<hr><h3><管理者メニュー></h3>';
        echo <<<EOT
            <form method="post" action="reservation_system.php">
                <button type="submit" name="btn_user_manage">ユーザを管理する</button>
                <input type="hidden" name="transition" value="trans_user_manage">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
            <br>
            <form method="post" action="reservation_system.php">
                <button type="submit" name="btn_reservation_manage">予約を管理する</button>
                <input type="hidden" name="transition" value="trans_reservation_manage">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
            <br>
            <form method="post" action="reservation_system.php">
                <button type="submit" name="btn_notice_manage">投稿を管理する</button>
                <input type="hidden" name="transition" value="trans_notice_manage">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
EOT;
    }
    echo_footer();
}

//予約追加画面表示
function echo_page_reservation_add()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename;
    global $open_time, $close_time;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>予約の追加</h1><hr>';
    if (!array_key_exists('date', $_POST))
    {
        echo '<hr><h3>予約する日付を入力してください</h3>';
        echo <<<EOT
            <form method="POST" action="reservation_system.php">
                yyyymmdd <input type="text" name="date"><br>
                <button type="submit" name="btn_reservation_add">検索する</button>
                <input type="hidden" name="transition" value="trans_reservation_add">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
            </form>
EOT;
    }
    else
    {
        $date = $_POST['date'];

        $reservation_list;
        for ($i = $open_time; $i <= $close_time; $i++)
        {
            $reservation_list[$i] = 0;
        }

        $link = mysqli_connect($hostname, $username, $password, $dbname);
        if (!$link){ exit("Connect error!"); }
        $result = mysqli_query($link, "SELECT * FROM $reservation_tablename where date = $date");
        if (!$result){ exit("Select error on table ($reservation_tablename)!"); } 
        
        while ($row_assoc = mysqli_fetch_assoc($result))
        {
            $reservation_list[$row_assoc['time']] = 1;
        }

        $cnt = 0;
        echo '<hr><h3>予約する時間帯を選択してください</h3>';
        for ($i = $open_time; $i <= $close_time; $i++)
        {
            if ($reservation_list[$i] == 0)
            {
                $cnt++;
                echo '<hr>';
                echo $i.':00~'.($i+1).':00<br>';
                echo <<<EOT
            <form method="POST" action="reservation_system.php">
                <button type="submit" name="btn_main">予約する</button>
                <input type="hidden" name="transition" value="trans_main">
                <input type="hidden" name="operation" value="reservation_add">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
                <input type="hidden" name="date" value=$date>
                <input type="hidden" name="time" value=$i>
            </form>
EOT;
            }
        }
        if ($cnt == 0)
        {
            echo 'ご希望の日はすでにご予約で埋まっています';
        } 

        mysqli_free_result($result);
        mysqli_close($link);
    }
    echo_footer();
}

//予約削除画面表示
function echo_page_reservation_delete()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>予約の削除</h1><hr>';
    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $reservation_tablename WHERE user = '$user'");
    if (!$result){ exit("Select error on table ($reservation_tablename)!"); } 

    echo '<hr><h3>削除する予約を選択してください</h3>';
    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        $id = $row_assoc['id'];
        echo '<hr>';
        echo $row_assoc['date'].'<br>';
        echo $row_assoc['time'].':00~'.($row_assoc['time']+1).':00<br>';
        echo <<<EOT
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn_main">削除する</button>
            <input type="hidden" name="transition" value="trans_reservation_delete">
            <input type="hidden" name="operation" value="reservation_delete">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="reservation_id" value=$id>
        </form>
EOT;
    }
    mysqli_free_result($result);
    mysqli_close($link);
    echo_footer();
}

//予約管理画面表示
function echo_page_reservation_manage()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>予約の管理</h1><hr>';
    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $reservation_tablename");
    if (!$result){ exit("Select error on table ($reservation_tablename)!"); } 

    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        $id = $row_assoc['id'];
        echo '<hr>';
        echo 'NAME：'.$row_assoc['user'].'<br><br>';
        echo $row_assoc['date'].'<br>';
        echo $row_assoc['time'].':00~'.($row_assoc['time']+1).':00<br>';
        echo <<<EOT
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">削除する</button>
            <input type="hidden" name="transition" value="trans_reservation_manage">
            <input type="hidden" name="operation" value="reservation_delete">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="reservation_id" value=$id>
        </form>
EOT;
    }
    mysqli_free_result($result);
    mysqli_close($link);
    echo_footer();
}

//ユーザ管理画面表示
function echo_page_user_manage()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>ユーザの管理</h1><hr>';
    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $user_tablename");
    if (!$result){ exit("Select error on table ($user_tablename)!"); } 

    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        $id = $row_assoc['id'];
        echo '<hr>';
        echo 'NAME ：'.$row_assoc['user'];
        if ($row_assoc['isManager'] == 1)
        {
            echo '　　★';
        }
        echo '<br>';
        echo 'BIRTH：'.$row_assoc['birth'].'<br>';
        echo 'TEL  ：'.$row_assoc['tel'].'<br>';
        echo <<<EOT
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">削除する</button>
            <input type="hidden" name="transition" value="trans_user_manage">
            <input type="hidden" name="operation" value="user_delete">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="user_id" value=$id>
        </form>
EOT;
        if ($row_assoc['isManager'] == 0)
        {
            echo <<<EOT
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">管理者権限を有効にする</button>
            <input type="hidden" name="transition" value="trans_user_manage">
            <input type="hidden" name="operation" value="manager_add">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="user_id" value=$id>
        </form>
EOT;
        }
        else
        {
            echo <<<EOT
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">管理者権限を無効にする</button>
            <input type="hidden" name="transition" value="trans_user_manage">
            <input type="hidden" name="operation" value="manager_delete">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="user_id" value=$id>
        </form>
EOT;
        }
    }
    echo_footer();
}

//投稿管理画面表示
function echo_page_notice_manage()
{
    global $hostname, $username, $password, $dbname, $notice_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>投稿の管理</h1><hr>';
    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $notice_tablename");
    if (!$result){ exit("Select error on table ($notice_tablename)!"); } 

    echo '<hr>';
    echo <<<EOT
        <br>
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">追加する</button>
            <input type="hidden" name="transition" value="trans_notice_add">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
        </form>
EOT;
    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        $id = $row_assoc['id'];
        echo '<hr>';
        echo '宛先:'.$row_assoc['addressee'].'<br>';
        echo $row_assoc['date'].'<br>';
        echo $row_assoc['notice'].'<br>';
        echo <<<EOT
        <br>
        <form method="POST" action="reservation_system.php">
            <button type="submit" name="btn">削除する</button>
            <input type="hidden" name="transition" value="trans_notice_manage">
            <input type="hidden" name="operation" value="notice_delete">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
            <input type="hidden" name="notice_id" value=$id>
        </form>
EOT;
    }
    echo_footer();
}

//投稿追加画面表示
function echo_page_notice_add()
{
    global $hostname, $username, $password, $dbname, $notice_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>投稿の追加</h1><hr>';

    echo '<hr>';
    echo <<<EOT
    <form method="POST" action="reservation_system.php">
        宛先<textarea name="addressee" rows="1" cols="10">everyone</textarea><br>
        日付<input type="text" name="date"><br>
        記事<textarea name="notice" rows="5" cols="30"></textarea>
        <br>
        <button type="submit" name="btn_user_edit">投稿する</button>
        <input type="hidden" name="transition" value="trans_notice_manage">
        <input type="hidden" name="operation" value="notice_add">
        <input type="hidden" name="user" value=$user>
        <input type="hidden" name="pass" value=$pass>
    </form>
EOT;
    echo_footer();
}

//ユーザ編集画面表示
function echo_page_user_edit()
{
    global $hostname, $username, $password, $dbname, $user_tablename;
    global $is_manager;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo '<body>';
    echo '<h1>アカウントの管理</h1><hr>';
    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link, "SELECT * FROM $user_tablename WHERE user = '$user'");
    if (!$result){ exit("Select error on table ($user_tablename)!"); } 
    
    $row_assoc = mysqli_fetch_assoc($result);

    $pass = $row_assoc['pass'];
    
    echo '<hr>';
    echo 'PASS：'.$row_assoc['pass'];
    echo <<<EOT
    <form method="POST" action="reservation_system.php">
        <input type="text" name="new_pass"><br>
        <button type="submit" name="btn_user_edit">変更する</button>
        <input type="hidden" name="transition" value="trans_user_edit">
        <input type="hidden" name="operation" value="user_edit">
        <input type="hidden" name="user" value=$user>
        <input type="hidden" name="pass" value=$pass>
    </form>
    <br>
EOT;
    echo 'BIRTH：'.$row_assoc['birth'];
    echo <<<EOT
    <form method="POST" action="reservation_system.php">
        <input type="text" name="new_birth"><br>
        <button type="submit" name="btn_user_edit">変更する</button>
        <input type="hidden" name="transition" value="trans_user_edit">
        <input type="hidden" name="operation" value="user_edit">
        <input type="hidden" name="user" value=$user>
        <input type="hidden" name="pass" value=$pass>
    </form>
    <br>
EOT;
    echo 'TEL：'.$row_assoc['tel'];
    echo <<<EOT
    <form method="POST" action="reservation_system.php">
        <input type="text" name="new_tel"><br>
        <button type="submit" name="btn_user_edit">変更する</button>
        <input type="hidden" name="transition" value="trans_user_edit">
        <input type="hidden" name="operation" value="user_edit">
        <input type="hidden" name="user" value=$user>
        <input type="hidden" name="pass" value=$pass>
    </form>
    EOT;
    mysqli_free_result($result);
    mysqli_close($link);
    echo_footer();
}

//フッター表示
function echo_footer()
{
    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo <<<EOT
        <br>
        <hr><hr>
        <form method="POST" action="reservation_system.php">
                <button type="submit" name="btn_main">ホームへ戻る</button>
                <input type="hidden" name="transition" value="trans_main">
                <input type="hidden" name="user" value=$user>
                <input type="hidden" name="pass" value=$pass>
        </form>
        <br><br>
        <form method="post" action="reservation_system.php">
            <button type="submit" name="btn_user_edit">Account</button>
            <input type="hidden" name="transition" value="trans_user_edit">
            <input type="hidden" name="user" value=$user>
            <input type="hidden" name="pass" value=$pass>
        </form>
        <form method="POST" action="reservation_system.php">
                <button type="submit" name="btn_logout">Logout</button>
                <input type="hidden" name="transition" value="trans_login">
        </form>
        </body>
EOT;
}

//ユーザ追加
function user_add()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $user = $_POST['user'];
    $pass = $_POST['pass'];
    $tel = $_POST['tel'];
    $birth = $_POST['birth'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"INSERT INTO $user_tablename SET user = '$user', pass = '$pass', tel = '$tel', birth = '$birth', isManager = 0");
    if (!$result){ exit("Insert error on table ($user_tablename)!"); } 

    mysqli_close($link);
}

//ユーザ削除
function user_delete()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $id = $_POST['user_id'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"DELETE FROM $user_tablename WHERE id = $id");
    if (!$result){ exit("Delete error on table ($user_tablename)!"); } 

    mysqli_close($link);
}

//ユーザ編集
function user_edit()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $user = $_POST['user'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }

    if (array_key_exists('new_pass', $_POST))
    {
        $new_pass = $_POST['new_pass'];

        $result = mysqli_query($link,"UPDATE $user_tablename SET pass = '$new_pass' WHERE user = '$user'");
        if (!$result){ exit("Update error on table ($user_tablename)!"); } 
    }
    if (array_key_exists('new_birth', $_POST))
    {
        $new_birth = $_POST['new_birth'];

        $result = mysqli_query($link,"UPDATE $user_tablename SET birth = '$new_birth' WHERE user = '$user'");
        if (!$result){ exit("Update error on table ($user_tablename)!"); } 
    }
    if (array_key_exists('new_tel', $_POST))
    {
        $new_tel = $_POST['new_tel'];

        $result = mysqli_query($link,"UPDATE $user_tablename SET tel = '$new_tel' WHERE user = '$user'");
        if (!$result){ exit("Update error on table ($user_tablename)!"); } 
    }
    mysqli_close($link);
}

//予約追加
function reservation_add()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename;

    $date = $_POST['date'];
    $time = $_POST['time'];
    $user = $_POST['user'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"INSERT INTO $reservation_tablename SET date = '$date', time = $time, user = '$user'");
    if (!$result){ exit("Insert error on table ($reservation_tablename)!"); } 

    mysqli_close($link);
}

//予約削除
function reservation_delete()
{
    global $hostname, $username, $password, $dbname, $reservation_tablename;

    $id = $_POST['reservation_id'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"DELETE FROM $reservation_tablename WHERE id = $id");
    if (!$result){ exit("Delete error on table ($reservation_tablename)!"); } 

    mysqli_close($link);
}

//投稿追加
function notice_add()
{
    global $hostname, $username, $password, $dbname, $notice_tablename;

    $date = $_POST['date'];
    $notice = $_POST['notice'];
    $addressee = $_POST['addressee'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"INSERT INTO $notice_tablename SET date = '$date', notice = '$notice', addressee = '$addressee'");
    if (!$result){ exit("Insert error on table ($notice_tablename)!"); } 

    mysqli_close($link);
}

//投稿削除
function notice_delete()
{
    global $hostname, $username, $password, $dbname, $notice_tablename;

    $id = $_POST['notice_id'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"DELETE FROM $notice_tablename WHERE id = $id");
    if (!$result){ exit("Delete error on table ($notice_tablename)!"); } 

    mysqli_close($link);
}

//管理者権限追加
function manager_add()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $id = $_POST['user_id'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"UPDATE $user_tablename SET isManager = 1 WHERE id = '$id'");
    if (!$result){ exit("Update error on table ($user_tablename)!"); } 
    
    mysqli_close($link);
}

//管理者権限削除
function manager_delete()
{
    global $hostname, $username, $password, $dbname, $user_tablename;

    $id = $_POST['user_id'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    $result = mysqli_query($link,"UPDATE $user_tablename SET isManager = 0 WHERE id = '$id'");
    if (!$result){ exit("Update error on table ($user_tablename)!"); } 
    
    mysqli_close($link);
}

?>
</html>