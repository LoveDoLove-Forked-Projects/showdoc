<?php
/**
 * zip/json 老导入路径回归（针对前端统一手动上传改造的后端验证）。
 * 通过真实 HTTP auto 接口导入 zip（info.json 格式），验证：
 *   1. zip 导入成功、项目创建
 *   2. 页面内容落库正确（含标题/正文）
 *   3. 重复导入同一 zip 到同一项目 → 页面按标题覆盖（不重复建页）
 *
 * 用法: ADMIN_TOKEN=xxx php tests/office-import/http_zip_test.php
 */

$ADMIN_TOKEN = getenv('ADMIN_TOKEN') ?: 'CHANGE_ME';
$BASE        = getenv('BASE_URL') ?: 'http://localhost/showdoc.cc/server/Api/Import/auto';

$failures = 0;
$pass = function (string $name, $cond, $got = null) use (&$failures) {
    if ($cond) {
        echo "  ✓ {$name}\n";
    } else {
        $failures++;
        $gotStr = is_scalar($got) ? var_export($got, true) : json_encode($got, JSON_UNESCAPED_UNICODE);
        echo "  ✗ {$name}\n    got: {$gotStr}\n";
    }
};

/** 上传一个文件走 auto 接口（multipart，与前端手动上传 FormData 一致） */
function importFile(string $url, string $token, string $path, array $extra = []): array
{
    $ch = curl_init($url);
    $cf = new CURLFile($path);
    $post = array_merge(['file' => $cf], $extra);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-API-Token: {$token}"],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['error_code' => -1, 'error_message' => "HTTP {$code}: " . substr((string) $body, 0, 200), 'data' => []];
    }
    return $json;
}

// ---- 造一个合法的 ShowDoc 导入 zip（info.json 新格式） ----
$tmpZip = tempnam(sys_get_temp_dir(), 'sdzip') . '.zip';
$info = [
    'item_name'        => 'zip回归测试项目',
    'item_description' => '由 http_zip_test 生成',
    'item_type'        => 1,
    'pages' => [
        'pages' => [
            ['page_title' => '首页说明', 'page_content' => "# 欢迎\n\n这是 zip 导入回归测试的内容。", 's_number' => 1],
            ['page_title' => 'Q&A', 'page_content' => "标题含特殊字符 & < > 的页面", 's_number' => 2],
        ],
        'catalogs' => [
            ['cat_name' => '目录A', 'pages' => [
                ['page_title' => '子页1', 'page_content' => "目录内子页内容", 's_number' => 1],
            ], 'catalogs' => []],
        ],
    ],
];
$z = new ZipArchive();
$z->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
$z->close();

echo "== zip 导入（新建项目，真实 HTTP） ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $tmpZip);
$pass('导入成功', ($r['error_code'] ?? -1) === 0, $r);
$itemId = (int) ($r['data']['item_id'] ?? 0);
$pass('返回 item_id（供前端跳转）', $itemId > 0, $r['data'] ?? null);

if ($itemId > 0) {
    echo "== 页面落库核验 ==\n";
    // 开源版差异：默认 sqlite（单表 page、库文件 Sqlite/showdoc.db.php）；
    // DB_TYPE=mysql 时与主版同口径分表
    $mysql = strtolower((string) (getenv('DB_TYPE') ?: 'sqlite')) === 'mysql';
    $pageTable = $mysql ? ('page_' . (($itemId % 100) + 1)) : 'page';
    if ($mysql) {
        $dbCheck = shell_exec('php -r '
            . escapeshellarg(
                '$p = new PDO("mysql:host=" . trim(shell_exec("grep ^DB_HOST /app/showdoc.cc/.env | cut -d= -f2"))
                    . ";port=3306;dbname=showdoc",
                    trim(shell_exec("grep ^DB_USER /app/showdoc.cc/.env | cut -d= -f2")),
                    trim(shell_exec("grep ^DB_PWD /app/showdoc.cc/.env | cut -d= -f2")));
                foreach ($p->query("select page_title from ' . $pageTable . ' where item_id=' . $itemId . '") as $r) echo $r["page_title"], "|";'
            )
        );
    } else {
        $dbCheck = shell_exec('php -r '
            . escapeshellarg(
                '$p = new PDO("sqlite:' . dirname(__DIR__, 3) . '/Sqlite/showdoc.db.php");
                foreach ($p->query("select page_title from ' . $pageTable . ' where item_id=' . $itemId . '") as $r) echo $r["page_title"], "|";'
            )
        );
    }
    $titles = array_filter(explode('|', (string) $dbCheck));
    $pass('共 3 页', count($titles) === 3, $titles);
    $pass('特殊字符标题 Q&A 存在（库内存量口径为转义后形态）', in_array('Q&amp;A', $titles, true) || in_array('Q&A', $titles, true), $titles);
    $pass('目录子页存在', in_array('子页1', $titles, true), $titles);

    echo "== 幂等：重复导入同一 zip 到同一项目 ==\n";
    $r2 = importFile($BASE, $ADMIN_TOKEN, $tmpZip, ['item_id' => $itemId]);
    $pass('重导入成功', ($r2['error_code'] ?? -1) === 0, $r2);
    if ($mysql) {
        $dbCheck2 = shell_exec('php -r '
            . escapeshellarg(
                '$p = new PDO("mysql:host=" . trim(shell_exec("grep ^DB_HOST /app/showdoc.cc/.env | cut -d= -f2"))
                    . ";port=3306;dbname=showdoc",
                    trim(shell_exec("grep ^DB_USER /app/showdoc.cc/.env | cut -d= -f2")),
                    trim(shell_exec("grep ^DB_PWD /app/showdoc.cc/.env | cut -d= -f2")));
                echo $p->query("select count(*) c from ' . $pageTable . ' where item_id=' . $itemId . '")->fetch()["c"];'
            )
        );
    } else {
        $dbCheck2 = shell_exec('php -r '
            . escapeshellarg(
                '$p = new PDO("sqlite:' . dirname(__DIR__, 3) . '/Sqlite/showdoc.db.php");
                echo $p->query("select count(*) c from ' . $pageTable . ' where item_id=' . $itemId . '")->fetch()["c"];'
            )
        );
    }
    $pass('页面数不翻倍（覆盖而非重复建页）', (int) trim((string) $dbCheck2) === 3, trim((string) $dbCheck2));
}

@unlink($tmpZip);

echo "== json 导入（runapi/openapi 分发探测） ==\n";
// json 走原有分支：非 runapi/openapi 格式应报「不支持的文件格式」或同类业务错误（证明路由到了 json 处理）
$tmpJson = tempnam(sys_get_temp_dir(), 'sdjson') . '.json';
file_put_contents($tmpJson, json_encode(['foo' => 'bar']));
$r3 = importFile($BASE, $ADMIN_TOKEN, $tmpJson);
$pass('json 走到业务校验（非 500/非静默）', ($r3['error_code'] ?? -1) !== -1, $r3);
@unlink($tmpJson);

echo "\n";
if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILURES\n";
exit(1);
