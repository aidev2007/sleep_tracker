<?php
mb_internal_encoding("UTF-8");

define('DATA_DIR', __DIR__ . '/sleep_data');
define('PASSWORD_FILE', DATA_DIR . '/.password');
define('LOG_FILE', DATA_DIR . '/log.csv');
define('HTACCESS_FILE', DATA_DIR . '/.htaccess');
const LOAD_LIMIT = 30;

// データディレクトリ・ファイルの初期化、.htaccess自動生成
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
if (!file_exists(HTACCESS_FILE)) {
    file_put_contents(HTACCESS_FILE, "Require all denied\n");
}

// パスワードファイルがなければ初回セットアップ画面
if (!file_exists(PASSWORD_FILE)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_password'])) {
        $pw = trim($_POST['setup_password']);
        if ($pw !== '') {
            file_put_contents(PASSWORD_FILE, $pw . "\n");
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $setup_error = 'パスワードを入力してください。';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>パスワード初期設定</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <?php echo get_common_css(); ?>
    </head>
    <body>
        <header>
            <a href="./"><h1><i class="fas fa-bed"></i> 睡眠時間ログ</h1></a>
        </header>

        <div class="container">
            <div class="card setup-card">
                <h2>パスワード初期設定</h2>
                <?php if (!empty($setup_error)) echo '<div class="alert alert-danger">' . htmlspecialchars($setup_error) . '</div>'; ?>
                <form method="post" action="">
                    <div class="form-group">
                        <p class="setup_message">
                            この画面は初回起動時のみ表示されます。<br>
                            <b>パスワード</b>を設定してください。<br>
                            ここで設定したパスワードを知っている方のみ、ロックを解除して書き込みが行えるようになります。<br><br>
                            <b>ファイル構成とその説明:</b><br>
                            <code>sleep_data/</code><br>
                            ├ <code>.password</code> &nbsp;ここで設定したパスワードが保存されます。<br>
                            ├ <code>log.csv</code> &nbsp;睡眠記録データが保存されます。<br>
                            └ <code>.htaccess</code> &nbsp;sleep_dataディレクトリ内をWebから閲覧できないようにします。
                        </p>
                        <p class="addtion">※これらのファイルは自動生成されます。通常は手動で編集・削除する必要はありません。初期化するにはディレクトリごと削除してください。</p>
                        <label for="setup_password">パスワード</label>
                        <input type="password" id="setup_password" name="setup_password" class="form-control setup-input" required>
                    </div>
                    <button type="submit" class="btn setup-btn">設定</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// パスワード読み込み
$PASSWORD = trim(file_get_contents(PASSWORD_FILE));

function load_data($offset = 0, $limit = LOAD_LIMIT) {
    if (!file_exists(LOG_FILE)) return [];
    
    try {
        $lines = array_reverse(file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $records = [];
        foreach ($lines as $i => $line) {
            if ($i < $offset) continue;
            if (count($records) >= $limit) break;
            
            try {
                $fields = str_getcsv(mb_convert_encoding($line, 'UTF-8', 'SJIS'));
                if (count($fields) >= 2) {
                    // 日付の妥当性チェック
                    $sleep = trim($fields[0]);
                    $wake = trim($fields[1]);
                    
                    if ($sleep === '') continue; // 就寝時間が空の行はスキップ
                    
                    try {
                        $sleep_dt = new DateTime($sleep);
                        $wake_dt = $wake !== '' ? new DateTime($wake) : null;
                        $hours = $wake_dt ? round(($wake_dt->getTimestamp() - $sleep_dt->getTimestamp()) / 3600, 2) : '-';
                        
                        $records[] = [
                            'sleep' => $sleep,
                            'wake' => $wake,
                            'hours' => $hours
                        ];
                    } catch (Exception $e) {
                        // 日付形式が不正な場合はその行をスキップ
                        continue;
                    }
                }
            } catch (Exception $e) {
                // CSVの解析に失敗した場合はその行をスキップ
                continue;
            }
        }
        return $records;
    } catch (Exception $e) {
        // ファイル読み込みに失敗した場合は空の配列を返す
        return [];
    }
}

function calculate_stats() {
    $data = load_data(0, 1000); // 十分な量のデータを取得
    if (empty($data)) return [
        'average' => 0,
        'min' => 0,
        'max' => 0,
        'total_records' => 0,
        'complete_records' => 0,
        'start_date' => null,
        'days_count' => 0
    ];
    
    $hours = [];
    $complete_records = 0;
    $start_date = null;
    
    foreach ($data as $record) {
        if ($record['wake'] && $record['hours'] !== '-') {
            $hours[] = $record['hours'];
            $complete_records++;
        }
        
        // 開始日を更新
        $sleep_dt = new DateTime($record['sleep']);
        if ($start_date === null || $sleep_dt < $start_date) {
            $start_date = $sleep_dt;
        }
    }
    
    // 経過日数の計算
    $days_count = 0;
    if ($start_date) {
        $now = new DateTime();
        $interval = $now->diff($start_date);
        $days_count = $interval->days;
    }
    
    return [
        'average' => !empty($hours) ? round(array_sum($hours) / count($hours), 2) : 0,
        'min' => !empty($hours) ? min($hours) : 0,
        'max' => !empty($hours) ? max($hours) : 0,
        'total_records' => count($data),
        'complete_records' => $complete_records,
        'start_date' => $start_date,
        'days_count' => $days_count
    ];
}

function calculate_daily_sleep_stats() {
    $data = load_data(0, 1000);
    if (empty($data)) return [];

    $daily_sleep = [];
    $dates = [];
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $start_date = null;

    // まず記録開始日を特定
    foreach ($data as $record) {
        $sleep_dt = new DateTime($record['sleep']);
        if ($start_date === null || $sleep_dt < $start_date) {
            $start_date = clone $sleep_dt;
        }
    }
    if (!$start_date) $start_date = clone $today;
    $start_date->setTime(0, 0, 0);

    // 記録開始日～今日までの日付配列を作成
    $period = new DatePeriod($start_date, new DateInterval('P1D'), (clone $today)->modify('+1 day'));
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
        $daily_sleep[$date->format('Y-m-d')] = 0; // 初期値0
    }

    // 睡眠記録を日付ごとに分配
    foreach ($data as $record) {
        if ($record['wake'] && $record['hours'] !== '-') {
            $sleep_dt = new DateTime($record['sleep']);
            $wake_dt = new DateTime($record['wake']);
            $sleep_date = $sleep_dt->format('Y-m-d');
            $wake_date = $wake_dt->format('Y-m-d');
            $total_hours = $record['hours'];
            if ($sleep_date === $wake_date) {
                $daily_sleep[$sleep_date] += $total_hours;
            } else {
                // 日を跨ぐ場合
                $sleep_hours = (24 - $sleep_dt->format('H')) - ($sleep_dt->format('i') / 60);
                $wake_hours = $wake_dt->format('H') + ($wake_dt->format('i') / 60);
                $daily_sleep[$sleep_date] += $sleep_hours;
                $daily_sleep[$wake_date] += $wake_hours;
                // もし2日以上またぐ場合（例：23:00～翌々日7:00）
                $interval = $sleep_dt->diff($wake_dt);
                $days_between = (int)$interval->format('%a');
                if ($days_between > 1) {
                    for ($i = 1; $i < $days_between; $i++) {
                        $mid_date = (clone $sleep_dt)->modify("+{$i} day")->format('Y-m-d');
                        $daily_sleep[$mid_date] += 24;
                    }
                }
            }
        }
    }

    // 日付でソート
    ksort($daily_sleep);

    // --- ここから修正 ---
    // 昨日までのデータだけで平均を計算
    $yesterday_str = (new DateTime('yesterday'))->format('Y-m-d');
    $filtered_sleep = array_filter(
        $daily_sleep,
        function($k) use ($yesterday_str) { return $k <= $yesterday_str; },
        ARRAY_FILTER_USE_KEY
    );

    // 各期間の平均を計算（0時間の日も含める）
    $stats = [];
    $periods = [1, 2, 3, 7, 30, 60, 90, 180, 365];
    $all_days = array_keys($filtered_sleep);
    $total_days = count($all_days);
    foreach ($periods as $days) {
        if ($total_days < $days) {
            $recent_days = $filtered_sleep;
        } else {
            $recent_days = array_slice($filtered_sleep, -$days, $days, true);
        }
        $stats[$days] = !empty($recent_days) ? round(array_sum($recent_days) / count($recent_days), 2) : 0;
    }

    return [
        'daily_sleep' => $daily_sleep,
        'averages' => $stats
    ];
}

function save_record($sleep, $wake = '') {
    try {
        $sleep = trim($sleep);
        $wake = trim($wake);
        
        // 日付の妥当性チェック
        if ($sleep === '') return false;
        
        try {
            new DateTime($sleep);
            if ($wake !== '') {
                new DateTime($wake);
            }
        } catch (Exception $e) {
            return false;
        }
        
        $dir = dirname(LOG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $line = mb_convert_encoding("{$sleep},{$wake}\n", 'SJIS', 'UTF-8');
        return file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX) !== false;
    } catch (Exception $e) {
        return false;
    }
}

function update_last_record($wake) {
    if (!file_exists(LOG_FILE)) return false;
    
    try {
        $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) return false;
        
        $lastLine = array_pop($lines);
        $fields = str_getcsv(mb_convert_encoding($lastLine, 'UTF-8', 'SJIS'));
        
        if (count($fields) >= 2 && trim($fields[1]) === '') {
            try {
                new DateTime(trim($wake));
                $fields[1] = trim($wake);
                // 3列目以降のデータは保持
                $newLine = implode(',', $fields) . "\n";
                $lines[] = $newLine;
                $content = implode("\n", $lines) . "\n";
                return file_put_contents(LOG_FILE, mb_convert_encoding($content, 'SJIS', 'UTF-8')) !== false;
            } catch (Exception $e) {
                return false;
            }
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function get_latest_status() {
    if (!file_exists(LOG_FILE)) return 'wake'; // ログがなければ「起床中」
    $lines = array_reverse(file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    foreach ($lines as $line) {
        $fields = str_getcsv(mb_convert_encoding($line, 'UTF-8', 'SJIS'));
        if (count($fields) >= 2) {
            // wakeが空なら'sleep'（就寝中）、入っていれば'wake'（起床中）
            return trim($fields[1]) === '' ? 'sleep' : 'wake';
        }
    }
    return 'wake'; // 有効な記録がなければ「起床中」
}

function get_latest_datetime() {
    if (!file_exists(LOG_FILE)) return null;
    $lines = array_reverse(file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    foreach ($lines as $line) {
        $fields = str_getcsv(mb_convert_encoding($line, 'UTF-8', 'SJIS'));
        if (count($fields) >= 2) {
            if (trim($fields[1]) !== '') {
                return new DateTime($fields[1]);
            } else if (trim($fields[0]) !== '') {
                return new DateTime($fields[0]);
            }
        }
    }
    return null;
}

function calculate_elapsed_time($action) {
    $latest_dt = get_latest_datetime();
    if ($latest_dt === null) return null;

    $now = new DateTime();
    $diff_seconds = $now->getTimestamp() - $latest_dt->getTimestamp();
    $is_negative = $diff_seconds < 0;
    $abs_seconds = abs($diff_seconds);
    $rounded_minutes = round($abs_seconds / 60 / 30) * 30; // 30分単位
    $hours = floor($rounded_minutes / 60);
    $minutes = $rounded_minutes % 60;
    $sign = $is_negative ? '-' : '';
    return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
}

$page = $_GET['page'] ?? 'record';
$error = '';
$redirectTo = '';

// APIエンドポイントの処理
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_elapsed_time') {
        $action = get_latest_status();
        $wake_stat =  $action === 'sleep' ? '就寝中' : '起床中';
        $elapsed = calculate_elapsed_time($action);
        $display = '<i class="fas fa-clock"></i> ' . $wake_stat . ' ' . $elapsed;
        echo json_encode(['display' => $display]);
        exit;
    }
    
    if ($_GET['action'] === 'get_current_datetime') {
        // 15分後の時刻をベースに計算
        $now = new DateTime();
        $now->modify('+15 minutes');
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');
        $minute = (int)$now->format('i');
        
        // 30分単位の丸め処理（15分加算済みの時刻を前提）
        $minute = $minute < 30 ? '00' : '30';
        
        $time = $hour . ':' . $minute;
        
        echo json_encode([
            'date' => $date,
            'time' => $time
        ]);
        exit;
    }

    if ($_GET['action'] === 'load_more') {
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        // sleep_log_loader.phpのload_dataと同じ形式で返す
        $records = [];
        foreach (load_data($offset) as $row) {
            $records[] = [
                'sleep' => $row['sleep'],
                'wake' => $row['wake'],
                'hours' => $row['hours'] === '-' ? null : $row['hours']
            ];
        }
        echo json_encode($records);
        exit;
    }

    if ($_GET['action'] === 'get_log_mtime') {
        $mtime = file_exists(LOG_FILE) ? filemtime(LOG_FILE) : 0;
        echo json_encode(['mtime' => $mtime]);
        exit;
    }
}

// 認証処理
$is_authenticated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'authenticate') {
        if (isset($_POST['password']) && $_POST['password'] === $PASSWORD) {
            $is_authenticated = true;
            setcookie('owner_authenticated', '1', time() + 86400 * 30, '/', '', true, true);
            $redirectTo = './'; // PRGパターン：リダイレクトしてフォーム再送信を防止
        } else {
            $error = 'パスワードが正しくありません。';
        }
    } elseif ($_POST['action'] === 'deauthenticate') {
        setcookie('owner_authenticated', '', time() - 3600, '/', '', true, true);
        $is_authenticated = false;
        $redirectTo = './'; // PRGパターン：リダイレクトしてフォーム再送信を防止
    }
} else {
    $is_authenticated = isset($_COOKIE['owner_authenticated']) && $_COOKIE['owner_authenticated'] === '1';
}

// POSTデータの処理を最初に行う
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['date'], $_POST['time'])) {
        $input_datetime = $_POST['date'] . 'T' . $_POST['time'];
        $input_dt = new DateTime($input_datetime);
        $latest_dt = get_latest_datetime();

        if ($latest_dt !== null && $input_dt <= $latest_dt) {
            $error = '記録できない時間が指定されました。最新の記録より後の時間を指定してください。';
        } else {
            if (get_latest_status() === 'sleep') {
                update_last_record($input_datetime);
            } else {
                save_record($input_datetime);
            }
            // リダイレクト先を設定
            $redirectTo = './';
        }
    } elseif (isset($_POST['filedata'])) {
        $lines = explode("\n", str_replace("\r\n", "\n", $_POST['filedata']));
        $lines = array_filter($lines, 'strlen'); // 空行を除去
        $lines = array_reverse($lines); // 表示時に反転しているので再反転
        // スペースをTに戻す
        foreach ($lines as &$l) {
            // 日付部分のスペース1箇所だけTに置換
            $l = preg_replace('/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', '$1T$2', $l);
            $l = preg_replace('/,(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', ',$1T$2', $l);
        }
        $content = implode("\n", $lines) . "\n"; // 最後に改行を追加
        file_put_contents(LOG_FILE, mb_convert_encoding($content, 'SJIS', 'UTF-8'));
        $redirectTo = './?edit_saved=1'; // PRGパターン＋保存成功フラグ
    }
}

// リダイレクト処理（出力前に実行）
if ($redirectTo) {
    error_log('Redirecting to: ' . $redirectTo);
    header('Location: ' . $redirectTo, true, 303);
    exit;
}

// 統計データの計算
$stats = calculate_stats();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>睡眠時間ログ</title>
    <link rel="icon" type="image/svg+xml" href='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🛌</text></svg>'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo get_common_css(); ?>
</head>
<body>
    <header>
        <a href="./"><h1><i class="fas fa-bed"></i> 睡眠時間ログ</h1></a>
    </header>
    
    <div class="container">
        <div id="center-toast" class="center-toast"></div>
        <nav>
            <a href="./" class="nav-link active" data-tab="record">
                <i class="fas fa-pencil-alt"></i> 入力
            </a>
            <a href="#" class="nav-link" data-tab="stats">
                <i class="fas fa-chart-bar"></i> 統計
            </a>
            <a href="#" class="nav-link" data-tab="edit">
                <i class="fas fa-edit"></i> 編集
            </a>
            <a href="#" class="nav-link" data-tab="auth">
                <i class="fas fa-user-lock"></i> 認証
            </a>
        </nav>
        
        <div id="record" class="tab-content active">
            <div class="card">
                <h2><i class="fas fa-clock"></i> 睡眠記録の入力</h2>
                
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <?php
                $action = get_latest_status();
                $now = new DateTime();
                $now->modify('+15 minutes');
                $defaultDate = $now->format('Y-m-d');
                $defaultHour = $now->format('H');
                $defaultMinute = (int)$now->format('i') < 30 ? '00' : '30';
                $defaultTime = $defaultHour . ':' . $defaultMinute;
                $label = $action === 'sleep' ? '寝た日時' : '起きた日時';
                $icon = $action === 'sleep' ? 'fa-bed' : 'fa-sun';
                $icon_kind = $action === 'sleep' ? 'sleep-icon' : 'wake-icon';
                $save_name = $action === 'sleep' ? '起床時間記録' : '就寝時間記録';
                $button_color = $action === 'sleep' ? 'wake-color' : 'sleep-color';
                $wake_stat =  $action === 'sleep' ? '就寝中' : '起床中';
                ?>
                
                <form method="post" action="./">
                    <div class="form-group">
                        <label for="date">
                            <i class="fas <?php echo $icon; ?> <?php echo $icon_kind; ?>"></i> <?php echo $label; ?>
                        </label>

                        <div class="form-row">
                            <div class="date-time-container">
                                <div class="date-input">
                                    <input type="date" id="date" name="date" class="form-control" required>
                                </div>
                                <div class="time-input">
                                    <select name="time" id="time" class="form-control" required>
                                        <!-- JSでoptionを生成 -->
                                    </select>
                                </div>
                            </div>
                            <div class="btn-container">
                                <button type="submit" class="btn <?php echo $button_color; ?> <?php echo !$is_authenticated ? 'disabled' : ''; ?>" <?php echo !$is_authenticated ? 'disabled' : ''; ?>>
                                    <i class="fas fa-save"></i> <?php echo $save_name; ?>
                                    
                                </button>
                            </div>
                        </div>
                        
                        <div id="elapsed-time" class="elapsed-time">
                            <i class="fas fa-clock" id="elapsed-time-icon" style="visibility:hidden"></i> <span id="elapsed-time-value"></span>
                        </div>
                        
                    </div>
                </form>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-history"></i> 最近の記録</h2>
                <div class="table-container">
                    <table id="log">
                        <thead>
                            <tr>
                                <th><i class="fas fa-bed sleep-icon"></i> 就寝</th>
                                <th><i class="fas fa-sun wake-icon"></i> 起床</th>
                                <th><i class="fas fa-hourglass-half hours-icon"></i> 時間</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (load_data(0) as $row): ?>
                                <tr>
                                    <td><?php 
                                        $sleep_dt = new DateTime($row['sleep']);
                                        echo htmlspecialchars($sleep_dt->format('m/d H:i'));
                                    ?></td>
                                    <td><?php 
                                        if ($row['wake'] !== '') {
                                            $wake_dt = new DateTime($row['wake']);
                                            echo htmlspecialchars($wake_dt->format('m/d H:i'));
                                        } else {
                                            echo '-';
                                        }
                                    ?></td>
                                    <td><?php 
                                        if ($row['hours'] !== '-') {
                                            echo number_format($row['hours'], 1) . ' h';
                                        } else {
                                            echo '-';
                                        }
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="stats" class="tab-content">
            <div class="card">

                <h2><i class="fas fa-chart-line"></i> 睡眠時間グラフ</h2>
                <div class="graph-container">
                    <canvas id="sleepChart"></canvas>
                </div>

                <h2 style="margin-top: 30px;"><i class="fas fa-chart-bar"></i> 睡眠時間統計</h2>
                <?php
                $daily_stats = calculate_daily_sleep_stats();
                $averages = $daily_stats['averages'] ?? [];
                ?>
                <div class="stats-list">
                    <div class="stat-item">
                        <span class="stat-label">過去1日の平均</span>
                        <span class="stat-value"><?php echo $averages[1] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去2日の平均</span>
                        <span class="stat-value"><?php echo $averages[2] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去3日の平均</span>
                        <span class="stat-value"><?php echo $averages[3] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去7日の平均</span>
                        <span class="stat-value"><?php echo $averages[7] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去30日の平均</span>
                        <span class="stat-value"><?php echo $averages[30] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去60日の平均</span>
                        <span class="stat-value"><?php echo $averages[60] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去90日の平均</span>
                        <span class="stat-value"><?php echo $averages[90] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去半年の平均</span>
                        <span class="stat-value"><?php echo $averages[180] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">過去1年の平均</span>
                        <span class="stat-value"><?php echo $averages[365] ?? 0; ?></span>
                        <span class="stat-unit">時間</span>
                    </div>
                </div>

                <h2><i class="fas fa-info-circle"></i> 記録概要</h2>
                <div class="overview-info">
                    <div class="overview-grid">
                        <div class="overview-item">
                            <div class="overview-label">記録開始日</div>
                            <div class="overview-value-div">
                                <span class="overview-value"><?php echo $stats['start_date'] ? $stats['start_date']->format('Y/m/d') : '-'; ?></span>
                            </div>
                        </div>
                        <div class="overview-item">
                            <div class="overview-label">記録期間</div>
                            <div class="overview-value-div">
                                <span class="overview-value"><?php echo $stats['days_count']; ?></span> <span class="overview-unit">日</span>
                            </div>
                        </div>
                        <div class="overview-item">
                            <div class="overview-label">総睡眠回数</div>
                            <div class="overview-value-div">
                                <span class="overview-value"><?php echo $stats['total_records']; ?></span> <span class="overview-unit">回</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div id="edit" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-edit"></i> ログを編集</h2>
                <form method="post" action="./">
                    <div class="form-group">
                    <textarea name="filedata" id="filedata" class="form-control"><?php
if (file_exists(LOG_FILE)) {
    $lines = array_reverse(file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    // Tをスペースに置換して表示
    foreach ($lines as &$l) {
        $l = preg_replace('/T/', ' ', $l);
    }
    echo htmlspecialchars(mb_convert_encoding(implode("\n", $lines) . "\n", 'UTF-8', 'SJIS'));
}
?></textarea>
                    </div>
                    <div class="btn-container">
                        <button type="submit" class="btn <?php echo !$is_authenticated ? 'disabled' : ''; ?>" <?php echo !$is_authenticated ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> 保存
                            <!--<i class="fas fa-rotate"></i> 更新-->
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="auth" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-user-lock"></i> 認証</h2>
                <?php if ($is_authenticated): ?>
                    <div class="authentication-status">
                        <p>オーナー認証を解除し、本ブラウザでの書き込みをロックします。</p>
                    </div>
                    <form method="post" action="./">
                        <input type="hidden" name="action" value="deauthenticate">
                        <div class="btn-container">
                            <button class="auth-button deauthenticate btn">
                                <i class="fas fa-lock"></i> ロック
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="authentication-status">
                        <p>オーナー認証を行い、書き込みロックを解除します。</p>
                    </div>
                    <form method="post" class="auth-form" id="authForm" action="./">
                        <input type="hidden" name="action" value="authenticate">
                        <div class="form-group">
                            <label for="password">パスワードを入力してください</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                        </div>
                        <div class="btn-container">
                            <button type="submit" class="btn">
                                <i class="fas fa-unlock"></i> アンロック
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // タブ切り替えの処理
        const tabs = ['record', 'stats', 'edit', 'auth'];
        let currentTabIndex = 0;

        function switchTab(index) {
            // インデックスの範囲チェック
            if (index < 0) index = tabs.length - 1;
            if (index >= tabs.length) index = 0;
            
            // アクティブなタブを更新
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            document.querySelector(`[data-tab="${tabs[index]}"]`).classList.add('active');
            
            // コンテンツの表示/非表示を切り替え
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabs[index]).classList.add('active');

            // 現在のタブをlocalStorageに保存
            localStorage.setItem('sleep_tracker_activeTab', tabs[index]);
            
            // 現在のインデックスを更新
            currentTabIndex = index;
        }

        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const tabId = link.getAttribute('data-tab');
                currentTabIndex = tabs.indexOf(tabId);
                switchTab(currentTabIndex);
            });
        });

        // PageUp/PageDownキーでのタブ切り替え
        document.addEventListener('keydown', (e) => {
            if (e.key === 'PageUp') {
                e.preventDefault();
                switchTab(currentTabIndex - 1);
            } else if (e.key === 'PageDown') {
                e.preventDefault();
                switchTab(currentTabIndex + 1);
            }
        });

        // フォーム送信時にタブの状態を保持（全フォーム対応）
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                const activeTab = document.querySelector('.nav-link.active').getAttribute('data-tab');
                localStorage.setItem('sleep_tracker_activeTab', activeTab);
            });
        });

        // ページ読み込み時にタブの状態を復元
        document.addEventListener('DOMContentLoaded', () => {
            const savedTab = localStorage.getItem('sleep_tracker_activeTab');
            if (savedTab && tabs.includes(savedTab)) {
                switchTab(tabs.indexOf(savedTab));
            }
        });

        // グラフの描画
        const ctx = document.getElementById('sleepChart').getContext('2d');
        // PHPで昨日を起点に過去30日分のみ抽出
        <?php
        $all_days = array_keys($daily_stats['daily_sleep'] ?? []);
        $total_days = count($all_days);
        $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
        // 昨日までのデータのみ抽出
        $filtered = array_filter(
            $daily_stats['daily_sleep'] ?? [],
            function($k) use ($yesterday) { return $k <= $yesterday; },
            ARRAY_FILTER_USE_KEY
        );
        // 末尾30件（昨日までの30日分）
        $filtered = array_slice($filtered, -30, 30, true);
        ?>
        const dailySleep = <?php echo json_encode($filtered); ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: Object.keys(dailySleep),
                datasets: [{
                    label: '睡眠時間（時間）',
                    data: Object.values(dailySleep),
                    backgroundColor: 'rgba(74, 109, 167, 0.7)',
                    borderColor: 'rgba(74, 109, 167, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        stepSize: 5,
                        max: 24,
                        title: {
                            display: true,
                            text: '睡眠時間（時間）'
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 90,
                            minRotation: 90
                        }
                    }
                }
            }
        });

        // 経過時間の更新処理
        function updateElapsedTime() {
            fetch(location.pathname + '?action=get_elapsed_time')
                .then(response => response.json())
                .then(data => {
                    const elapsedTimeElement = document.getElementById('elapsed-time-value');
                    if (elapsedTimeElement && data.display) {
                        // 状態＋時間をセットで上書き
                        elapsedTimeElement.parentElement.innerHTML = data.display;
                    }
                });
        }

        let lastUserInteraction = 0;
        const INTERACTION_TIMEOUT = 60000; // 1分間

        // フォーム要素の変更を監視
        document.getElementById('date').addEventListener('change', () => {
            lastUserInteraction = Date.now();
        });
        document.getElementById('time').addEventListener('change', () => {
            lastUserInteraction = Date.now();
        });

        // 日時入力の更新処理
        function updateDateTimeInputs() {
            // ローカルタイム基準で日付を生成
            const now = new Date(Date.now() + 15 * 60 * 1000); // 15分後
            let hour = now.getHours();
            let minute = now.getMinutes();
            // 30分単位に丸め
            minute = minute < 30 ? 0 : 30;

            // ローカルタイム基準で日付を生成
            const dateStr = now.getFullYear() + '-' +
                String(now.getMonth() + 1).padStart(2, '0') + '-' +
                String(now.getDate()).padStart(2, '0');
            const timeStr = `${hour.toString().padStart(2, '0')}:${minute === 0 ? '00' : '30'}`;

            // 値が異なる場合のみ更新
            const dateInput = document.getElementById('date');
            const timeSelect = document.getElementById('time');
            if (dateInput && dateInput.value !== dateStr) dateInput.value = dateStr;
            if (timeSelect && timeSelect.value !== timeStr) timeSelect.value = timeStr;
        }

        // もっと読み込むボタンの処理
        let offset = <?php echo LOAD_LIMIT; ?>;
        let hasMoreData = true;

        document.getElementById('loadmore')?.addEventListener('click', async () => {
            try {
                const res = await fetch(location.pathname + '?action=load_more&offset=' + offset);
                const rows = await res.json();
                
                if (rows.length === 0) {
                    hasMoreData = false;
                    document.getElementById('loadmore').classList.remove('visible');
                    return;
                }
                
                const table = document.getElementById('log');
                for (const r of rows) {
                    const tr = document.createElement('tr');
                    const td1 = document.createElement('td');
                    const td2 = document.createElement('td');
                    const td3 = document.createElement('td');
                    
                    td1.textContent = r.sleep.replace('T', ' ');
                    td2.textContent = r.wake ? r.wake.replace('T', ' ') : '-';
                    td3.textContent = r.hours !== null ? r.hours : '-';
                    
                    tr.append(td1, td2, td3);
                    table.querySelector('tbody').appendChild(tr);
                }
                
                offset += rows.length;
            } catch (error) {
                console.error('データ読み込みエラー:', error);
            }
        });

        // --- 時刻セレクトボックスの初期化 ---
        function initializeTimeSelect() {
            const timeSelect = document.getElementById('time');
            if (!timeSelect) return;
            timeSelect.innerHTML = '';
            for (let h = 0; h < 24; h++) {
                for (const m of ['00', '30']) {
                    const val = `${h.toString().padStart(2, '0')}:${m}`;
                    const opt = document.createElement('option');
                    opt.value = val;
                    opt.textContent = val;
                    timeSelect.appendChild(opt);
                }
            }
        }
        // ページ初期表示時に1回だけ呼ぶ
        initializeTimeSelect();

        // --- 統一的な毎秒更新ロジック（unifiedUpdateLoop）だけを残す ---
        let lastElapsedDisplay = '';
        let lastFormDate = '';
        let lastFormTime = '';
        let lastLogRows = '';
        let lastLogMtime = null;

        async function unifiedUpdateLoop() {
            // 1. ログファイルmtimeチェック
            let logMtimeChanged = false;
            let logRows = '';
            try {
                const res = await fetch(location.pathname + '?action=get_log_mtime');
                const data = await res.json();
                if (lastLogMtime === null) {
                    lastLogMtime = data.mtime;
                    logMtimeChanged = true;
                } else if (data.mtime !== lastLogMtime) {
                    lastLogMtime = data.mtime;
                    logMtimeChanged = true;
                }
            } catch (e) {}

            // 2. 経過時間表示の生成
            let elapsedDisplay = '';
            if (window.latestRow) {
                let latestTimeStr = window.latestRow.wake || window.latestRow.sleep;
                if (latestTimeStr) {
                    latestTimeStr = latestTimeStr.replace('T', ' ');
                    const latestDt = new Date(latestTimeStr.replace(/-/g, '/'));
                    const now = new Date();
                    let diffMs = now - latestDt;
                    let sign = '';
                    if (diffMs < 0) { sign = '-'; diffMs = -diffMs; }
                    let minutes = Math.round(diffMs / 1000 / 60 / 30) * 30;
                    let hours = Math.floor(minutes / 60);
                    minutes = minutes % 60;
                    const isSleep = !window.latestRow.wake;
                    const action = isSleep ? '就寝中' : '起床中';
                    elapsedDisplay = `${action} ${sign}${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`.replace('-00:00', '00:00');
                }
            }

            // 3. フォーム値の生成
            const now = new Date(Date.now() + 15 * 60 * 1000); // 15分後
            let hour = now.getHours();
            let minute = now.getMinutes();
            minute = minute < 30 ? '00' : '30';
            const dateStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            const timeStr = `${hour.toString().padStart(2, '0')}:${minute}`;

            // 4. ログ表示内容の生成（必要な場合のみサーバーアクセス）
            if (logMtimeChanged) {
                try {
                    const res = await fetch(location.pathname + '?action=load_more&offset=0');
                    const rows = await res.json();
                    logRows = JSON.stringify(rows);
                    // id="log"のtbodyを更新
                    const tbody = document.querySelector('#log tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        for (const r of rows) {
                            const tr = document.createElement('tr');
                            const td1 = document.createElement('td');
                            const td2 = document.createElement('td');
                            const td3 = document.createElement('td');
                            td1.textContent = r.sleep.replace(/\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2})/, '$1/$2 $3:$4');
                            if (r.wake) {
                                td2.textContent = r.wake.replace(/\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2})/, '$1/$2 $3:$4');
                            } else {
                                td2.textContent = '-';
                            }
                            td3.textContent = r.hours !== null ? (parseFloat(r.hours).toFixed(1) + ' h') : '-';
                            tr.append(td1, td2, td3);
                            tbody.appendChild(tr);
                        }
                        // 最新行をキャッシュ
                        window.latestRow = rows.length > 0 ? rows[0] : null;
                    }
                } catch (e) {}
            }

            // 5. 経過時間表示の更新
            if (elapsedDisplay && elapsedDisplay !== lastElapsedDisplay) {
                document.getElementById('elapsed-time-value').innerHTML = elapsedDisplay;
                const icon = document.getElementById('elapsed-time-icon');
                if (icon) icon.style.visibility = 'visible';
                lastElapsedDisplay = elapsedDisplay;
            }

            // 6. フォーム値の更新
            const dateInput = document.getElementById('date');
            const timeSelect = document.getElementById('time');
            if (dateInput && dateStr !== lastFormDate) {
                dateInput.value = dateStr;
                lastFormDate = dateStr;
            }
            if (timeSelect && timeStr !== lastFormTime) {
                timeSelect.value = timeStr;
                lastFormTime = timeStr;
            }
        }
        setInterval(unifiedUpdateLoop, 1000);
        // --- 統一的な毎秒更新ロジックここまで ---

        // --- 保存トースト表示 ---
        function showCenterToast(msg, duration = 1000) {
            const toast = document.getElementById('center-toast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.display = 'block';
            setTimeout(() => {
                toast.style.display = 'none';
            }, duration);
        }
        // URLにedit_savedがあれば表示（値は問わない）
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.has('edit_saved')) {
                showCenterToast('保存されました', 1000);
                // クエリを消してリロードしない（履歴汚さない）
                setTimeout(() => {
                    params.delete('edit_saved');
                    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
                    window.history.replaceState({}, '', newUrl);
                }, 1100);
            }
        });
    </script>
</body>
</html>

<?php

// 共通CSS
function get_common_css() {
    return <<<CSS
<style>
    :root {
        --primary-color: #4a6da7;
        --secondary-color: #3a517a;
        --dark-color: #2c3e50;
        --danger-color: #e74c3c;
    }
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }
    body {
        font-family: 'Helvetica Neue', Arial, sans-serif;
        line-height: 1.6;
        color: #333;
        background-color: #f9f9f9;
    }
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    header {
        background-color: var(--primary-color);
        color: white;
        padding: 0.5rem;
        text-align: center;
        box-shadow: 5px 5px 10px 0px #cccccc;
    }
    header a {
        text-decoration: none;
        color:white;
    }
    header h1 {
        font-size: 1.8rem;
    }
    nav {
        background: white;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 5px 5px 10px 0px #cccccc;
        margin: 20px 0;
        display: flex;
        justify-content: center;
    }
    nav a {
        color: var(--dark-color);
        text-decoration: none;
        padding: 10px 20px;
        margin: 0 10px;
        border-radius: 5px;
        transition: all 0.3s ease;
        font-weight: 500;
    }
    nav a:hover {
        background-color: #eef2f7;
        color: var(--primary-color);
    }
    nav a.active {
        background-color: var(--primary-color);
        color: white;
    }
    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 5px 5px 10px 0px #cccccc;
        padding: 25px;
        margin-bottom: 25px;
    }
    .card h2 {
        color: var(--primary-color);
        border-bottom: 1px solid #eee;
    }
    .form-group {
        margin: 10px 0px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: var(--dark-color);
    }
    .form-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-row .date-time-container {
        display: flex;
        gap: 10px;
        flex: 3;
        padding:10px 0px;
    }
    .form-row .date-input {
        flex: 2;
    }
    .form-row input, .form-row select {
        width: 100%;
        height: 100%;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }
    .form-row .time-input {
        flex: 1;
    }
    .form-row .btn-container {
        flex: 1;
        display: flex;
        justify-content: center;
    }
    .form-control {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 16px;
        transition: border-color 0.3s;
    }
    .form-control:focus {
        border-color: var(--primary-color);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 109, 167, 0.1);
    }
    select.form-control {
        background-image: url('data:image/svg+xml;utf8,<svg fill="%23333" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>');
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 30px;
        /*padding-right: 30px;*/
        /*-webkit-appearance: none;*/
        -moz-appearance: none;
        appearance: none;
    }
    .btn {
        background-color: var(--primary-color);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 500;
        text-align: center;
        transition: background-color 0.3s;
        width: calc(600px - 30%);
    }
    .btn:hover {
        background-color: var(--secondary-color);
    }
    .alert {
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        border-left: 4px solid transparent;
    }
    .alert-danger {
        background-color: #fdf0ed;
        color: var(--danger-color);
        border-left-color: var(--danger-color);
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        background: white;
        border-radius: 5px;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0,0,0,0.05);
    }
    th, td {
        padding: 5px 10px;
        text-align: center;
        border-bottom: 1px solid #eee;
    }
    th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: var(--dark-color);
    }
    tr:hover {
        background-color: #f8f9fa;
    }
    tbody tr:last-child td {
        border-bottom: none;
    }
    textarea {
        width: 100%;
        height: 300px;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        resize: vertical;
        font-family: monospace;
        font-size: 14px;
    }
    .sleep-icon {
        color: #4a6da7;
    }
    .wake-icon {
        color: #f1c40f;
    }
    .hours-icon {
        color: #2ecc71;
    }
    .sleep-color {
        background-color: #4a6da7;
    }
    .wake-color {
        background-color: #f1c40f !important;
    }
    .load-more {
        display: block;
        width: 100%;
        padding: 12px;
        margin-top: 20px;
        background-color: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 5px;
        color: var(--dark-color);
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .load-more:hover {
        background-color: #e9ecef;
    }
    .authentication-status {
        max-width: calc(800px - 20%);
        margin: 20px auto;
        padding: 10px;
        text-align:center;
        /*font-size:1.2rem;*/
        color: var(--primary-color);
    }
    @media (max-width: 790px) {
        nav {
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            padding: 5px 10px;
            margin: 10px auto;
        }
        nav a {
            margin: 0;
            padding: 8px 10px;
            font-size: 0.9rem;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .form-row {
            flex-direction: column;
            /*gap: 10px;*/
        }
        .form-row .date-time-container {
            width: 100%;
        }
        .form-row .date-input {
            flex: 2;
        }
        .form-row .time-input {
            flex: 1;
        }
        .form-row .btn-container {
            width: 100%;
        }
        .container {
            padding: 0px 5px;
        }
        .card {
            margin: 10px auto;
            padding: 10px;
        }
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    .stat-label {
        color: var(--dark-color);
        font-size: 0.9rem;
    }
    .stat-unit {
        color: #6c757d;
        font-size: 0.9rem;
    }
    .graph-container {
        width: 100%;
        height: 400px;
        margin: 20px 0;
    }
    .btn-container {
        display: flex;
        justify-content: center;
        margin: 10px 0;
    }
    .load-more {
        display: none;
        margin: 20px auto;
    }
    .load-more.visible {
        display: block;
    }
    .stats-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
        margin: 20px 0;
    }
    .stat-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: white;
        padding: 0px 15px;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .stat-label {
        color: var(--dark-color);
        font-size: 1rem;
    }
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    .stat-unit {
        color: #6c757d;
        font-size: 0.9rem;
    }
    .overview-info {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        padding: 0px 15px;
        margin: 20px 0;
    }
    .overview-info h3 {
        color: var(--primary-color);
        font-size: 1.2rem;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    .overview-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }
    .overview-item {
        text-align: center;
        align-items: center;
    }
    .overview-label {
        color: var(--dark-color);
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    .overview-value {
        font-size: 1.5rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    #filedata {
        padding: 10px;
    }
    .stats-list {
        max-width: 600px;
        margin: 20px auto;
    }
    @media (max-width: 768px) {
        .container {
            padding: 0px 5px;
        }
        .card {
            margin: 10px auto;
            padding: 10px;
        }
        .btn-container {
            margin: 0px;
        }
        .overview-grid {
            grid-template-columns: 1fr;
        }
        .overview-item {
            display: flex;
            justify-content: space-between;
        }
        .overview-value-div {
            display: flex;
            align-items: center;
            text-align: center;
            gap: 0.25rem;
        }
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    .nav-link {
        cursor: pointer;
    }
    .deauthenticate {
        background-color: var(--danger-color);
    }
    .deauthenticate:hover {
        background-color: #c0392b;
    }
    .auth-form {
        margin-top: 10px;
        text-align: center;
        margin-left: auto;
        margin-right: auto;
    }
    .auth-form.active {
        display: block;
    }
    .auth-form .form-group {
        margin-bottom: 20px;
    }
    .auth-form label {
        display: block;
        margin-bottom: 10px;
        font-size: 1.1rem;
        color: var(--dark-color);
    }
    .auth-form input[type="password"] {
        width: 200px;
        margin: 0 auto;
    }
    .auth-form .btn-container {
        margin-top: 20px;
    }
    .disabled {
        opacity: 0.5;
        cursor: not-allowed;
        background-color: #2154A6 !important;
        pointer-events: none;
    }
    .elapsed-time {
        text-align: center;
        margin-top: 10px;
        font-size: 1.2rem;
        color: var(--primary-color);
    }
    .elapsed-time i {
        margin-right: 5px;
    }
    @media (max-width: 700px) {
        .container, .card {
            max-width: 98vw;
            padding: 10px;
        }
        .btn {
            max-width: 100vw;
        }
    }

    .center-toast {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(51,51,51,0.7);
        color: #fff;
        padding: 24px 40px;
        border-radius: 10px;
        font-size: 1.3rem;
        z-index: 9999;
        box-shadow: 0 4px 16px rgba(0,0,0,0.18);
        opacity: 0.95;
        display: none;
        pointer-events: none;
        text-align: center;
    }

    /* パスワード初期設定画面専用 */
    .setup-btn {
        width: 100%;
        margin-top: 10px;
    }
    .setup-input {
        width: 100%;
    }
    .setup_message {
        color:#444444;
        margin-bottom: 10px;
    }

    .addtion {
        color: #888899;
        margin-left: 1em;
        text-indent: -1em;
        margin-bottom: 20px;
    }

</style>
CSS;
}
