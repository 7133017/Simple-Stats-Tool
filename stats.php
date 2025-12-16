<?php
/**
 * Simple Web Stats (PV/UV Edition)
 * 修复：完整URL链接、UV统计、设置弹窗
 */

// ==================== 配置项 ====================
$DB_FILE = 'stats.db';
session_start(); 
date_default_timezone_set('Asia/Shanghai');
// ================================================

// --- 核心工具函数 ---

function getDB($dbFile) {
    try {
        $db = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        // 性能优化
        $db->exec('PRAGMA journal_mode = WAL;');
        
        // 建表
        $db->exec('CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT,
            path TEXT,
            referer TEXT,
            user_agent TEXT,
            visitor_id TEXT, -- 新增 UV 标识
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 自动升级数据库结构 (添加 visitor_id 字段)
        $cols = $db->query("PRAGMA table_info(visits)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('visitor_id', $cols)) {
            $db->exec('ALTER TABLE visits ADD COLUMN visitor_id TEXT');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_visitor ON visits(visitor_id)');
        }

        // 索引
        $db->exec('CREATE INDEX IF NOT EXISTS idx_time ON visits(timestamp)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_path ON visits(path)');
        
        $db->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)');
        
        return $db;
    } catch (Exception $e) {
        die("Database Error: " . $e->getMessage());
    }
}

function getClientIP() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            if (filter_var(trim($ip), FILTER_VALIDATE_IP)) return trim($ip);
        }
    }
    return '0.0.0.0';
}

function isBot($ua) {
    return preg_match('/(bot|crawl|spider|slurp|mediapartners|python|curl)/i', $ua);
}

// 获取当前站点根地址 (用于拼接完整URL)
function getSiteRoot() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    // 如果 stats.php 在根目录，直接用 HTTP_HOST，如果在子目录，需要自行调整或去除文件名
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = ($path == '/' || $path == '\\') ? '' : $path; 
    // 这里假设 stats.php 可能放在根目录或者某个文件夹下，我们尽量只取域名
    // 为了更通用的拼接，我们直接返回 协议+域名
    return $protocol . $_SERVER['HTTP_HOST'];
}

// --- 逻辑处理 ---

$db = getDB($DB_FILE);

// 1. 记录数据 API
if (isset($_GET['action']) && $_GET['action'] === 'record') {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: text/plain');
    
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!isBot($ua)) {
            $ip = getClientIP();
            $path = substr($_GET['path'] ?? '/', 0, 255);
            $referer = substr($_GET['referer'] ?? '', 0, 500);
            
            // 获取前端传来的 UV ID，如果没传（比如不支持JS），则由后端生成一个基于IP+日期的弱指纹
            $visitor_id = $_GET['vid'] ?? md5($ip . $ua . date('Y-m-d'));
            
            $stmt = $db->prepare('INSERT INTO visits (ip, path, referer, user_agent, visitor_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$ip, $path, $referer, $ua, $visitor_id]);
        }
    } catch (Exception $e) {}
    exit('ok');
}

// 检查初始化状态
$hasPassword = $db->query("SELECT value FROM config WHERE key = 'admin_password'")->fetchColumn();
$isInitialized = ($hasPassword !== false);

// 2. 表单处理
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 登录
    if (isset($_POST['login'])) {
        if (password_verify($_POST['password'], $hasPassword)) {
            $_SESSION['is_admin'] = true;
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        } else {
            $msg = '<div class="msg error">密码错误</div>';
        }
    }
    // 初始化/修改密码 (需权限)
    if (isset($_POST['save_pwd'])) {
        if (isset($_POST['initialize']) || isset($_SESSION['is_admin'])) {
            $pwd = trim($_POST['password']);
            if (!empty($pwd)) {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $db->prepare('REPLACE INTO config (key, value) VALUES (?, ?)')->execute(['admin_password', $hash]);
                if (isset($_POST['initialize'])) $_SESSION['is_admin'] = true;
                $msg = '<div class="msg success">密码设置成功</div>';
                if (isset($_POST['initialize'])) { header("Location: " . $_SERVER['PHP_SELF']); exit; }
            }
        }
    }
}

// 登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

// 3. 读取数据
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$stats = [];
$siteRoot = getSiteRoot();

if ($isAdmin) {
    // 基础统计 (PV & UV)
    $sqlBasic = "SELECT COUNT(*) as pv, COUNT(DISTINCT visitor_id) as uv FROM visits";
    
    $stats['total'] = $db->query($sqlBasic)->fetch();
    $stats['today'] = $db->query($sqlBasic . " WHERE timestamp >= date('now', 'localtime')")->fetch();
    $stats['yesterday'] = $db->query($sqlBasic . " WHERE timestamp >= date('now', '-1 day', 'localtime') AND timestamp < date('now', 'localtime')")->fetch();
    
    // 热门页面
    $stats['pages'] = $db->query("SELECT path, COUNT(*) as pv, COUNT(DISTINCT visitor_id) as uv FROM visits GROUP BY path ORDER BY pv DESC LIMIT 10")->fetchAll();
    
    // 来源
    $stats['referrers'] = $db->query("SELECT referer, COUNT(*) as pv FROM visits WHERE referer != '' GROUP BY referer ORDER BY pv DESC LIMIT 10")->fetchAll();
    
    // 最近访问
    $stats['recent'] = $db->query("SELECT * FROM visits ORDER BY id DESC LIMIT 20")->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>流量统计 PV/UV</title>
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --bg: #f4f7f6; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; position: relative; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 20px; }
        h3 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; color: var(--primary); }
        
        /* 统计卡片 */
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .stat-box { background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; }
        .stat-label { color: #777; font-size: 14px; margin-bottom: 5px; }
        .stat-num { font-size: 24px; font-weight: bold; color: var(--primary); }
        .stat-sub { font-size: 13px; color: #999; }
        .uv-tag { color: var(--accent); font-size: 0.8em; margin-left: 5px; }

        /* 表格与链接 */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        .url-link { color: var(--accent); text-decoration: none; display: inline-block; max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
        .url-link:hover { text-decoration: underline; }
        
        /* 弹窗与表单 */
        .auth-box { max-width: 350px; margin: 80px auto; text-align: center; }
        input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background: var(--accent); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; width: 100%; }
        
        /* 模态框 (Modal) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 400px; position: relative; }
        .close-btn { position: absolute; right: 15px; top: 10px; font-size: 24px; cursor: pointer; color: #aaa; }
        
        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-sm { padding: 5px 15px; font-size: 14px; width: auto; margin-left: 10px; }
        .msg { padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .msg.error { background: #fee; color: #c00; }
        .msg.success { background: #eef; color: #009; }
        pre { background: #333; color: #eee; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>

<div class="container">
    <?= $msg ?>

    <?php if (!$isInitialized): ?>
        <div class="card auth-box">
            <h2>⚙️ 系统初始化</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="设置管理员密码" required>
                <button type="submit" name="save_pwd" value="1">完成初始化</button>
                <input type="hidden" name="initialize" value="1">
            </form>
        </div>

    <?php elseif (!$isAdmin): ?>
        <div class="card auth-box">
            <h2>🔒 请登录</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="输入管理员密码" required>
                <button type="submit" name="login">进入面板</button>
            </form>
        </div>

    <?php else: ?>
        <div class="header-bar">
            <h1>📊 流量统计 <small style="font-size:14px; font-weight:normal; color:#888;">PV(浏览) / UV(访客)</small></h1>
            <div>
                <button class="btn-sm" style="background:#666;" onclick="toggleSettings()">设置</button>
                <a href="?logout=1"><button class="btn-sm" style="background:#e74c3c;">退出</button></a>
            </div>
        </div>

        <div class="card">
            <div class="grid-3">
                <div class="stat-box">
                    <div class="stat-label">今日数据</div>
                    <div class="stat-num"><?= $stats['today']['pv'] ?: 0 ?><span class="uv-tag">PV</span></div>
                    <div class="stat-sub"><?= $stats['today']['uv'] ?: 0 ?> UV</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">昨日数据</div>
                    <div class="stat-num"><?= $stats['yesterday']['pv'] ?: 0 ?><span class="uv-tag">PV</span></div>
                    <div class="stat-sub"><?= $stats['yesterday']['uv'] ?: 0 ?> UV</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">历史总计</div>
                    <div class="stat-num"><?= $stats['total']['pv'] ?: 0 ?><span class="uv-tag">PV</span></div>
                    <div class="stat-sub"><?= $stats['total']['uv'] ?: 0 ?> UV</div>
                </div>
            </div>
        </div>

        <div class="grid-3" style="grid-template-columns: 1fr 1fr;">
            <div class="card">
                <h3>页面排行 (Top 10)</h3>
                <table>
                    <thead><tr><th>页面路径</th><th width="60">PV</th><th width="60">UV</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['pages'] as $p): 
                        // 构造完整URL：如果是 http 开头则直接用，否则拼接当前域名
                        $fullUrl = (strpos($p['path'], 'http') === 0) ? $p['path'] : $siteRoot . $p['path'];
                    ?>
                        <tr>
                            <td>
                                <a href="<?= htmlspecialchars($fullUrl) ?>" target="_blank" class="url-link" title="<?= htmlspecialchars($fullUrl) ?>">
                                    <?= htmlspecialchars($p['path']) ?>
                                </a>
                            </td>
                            <td><?= $p['pv'] ?></td>
                            <td><?= $p['uv'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <h3>来源域名 (Top 10)</h3>
                <table>
                    <thead><tr><th>来源 URL</th><th width="60">PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['referrers'] as $r): ?>
                        <tr>
                            <td>
                                <a href="<?= htmlspecialchars($r['referer']) ?>" target="_blank" class="url-link" title="<?= htmlspecialchars($r['referer']) ?>">
                                    <?= htmlspecialchars($r['referer']) ?>
                                </a>
                            </td>
                            <td><?= $p['pv'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <h3>集成代码</h3>
            <p style="color:#666; font-size:14px;">请将以下代码放在网站底部的 <code>&lt;/body&gt;</code> 之前。它会自动生成并存储访客ID(UV)。</p>
            <pre>&lt;script&gt;
(function() {
    var vid = localStorage.getItem('stats_vid');
    if (!vid) {
        vid = Math.random().toString(36).substring(2) + Date.now().toString(36);
        localStorage.setItem('stats_vid', vid);
    }
    var img = new Image();
    var p = encodeURIComponent(window.location.pathname);
    var r = encodeURIComponent(document.referrer);
    img.src = '<?= $siteRoot . $_SERVER['SCRIPT_NAME'] ?>?action=record&path=' + p + '&referer=' + r + '&vid=' + vid;
})();
&lt;/script&gt;</pre>
        </div>

        <div id="settingsModal" class="modal">
            <div class="modal-content">
                <span class="close-btn" onclick="toggleSettings()">&times;</span>
                <h3>修改管理员密码</h3>
                <form method="POST">
                    <input type="password" name="password" placeholder="输入新密码" required>
                    <button type="submit" name="save_pwd" style="margin-top:10px;">保存修改</button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
function toggleSettings() {
    var modal = document.getElementById("settingsModal");
    modal.style.display = (modal.style.display === "block") ? "none" : "block";
}
// 点击窗口外部关闭弹窗
window.onclick = function(event) {
    var modal = document.getElementById("settingsModal");
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>
    <!-- 简洁版页脚 -->
    <div style="margin-top: 40px; padding: 20px; text-align: center; color: #999; font-size: 13px; border-top: 1px solid #eee;">
        <a href="https://github.com/7133017/Simple-Stats-Tool" target="_blank" style="color: #666; text-decoration: none;">GitHub</a>
        <span> | </span>
        <span>Simple Stats Tool</span>
    </div>
</body>
</html>