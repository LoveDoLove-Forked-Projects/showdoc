<?php
/**
 * 回归测试：MarkdownSanitizer 净化 → Page::updateByTitle 落库 → 读出不含 <script
 *
 * 回归覆盖：
 *  - 批2审查：sanitizer 曾把 <script> 转义为 &lt;script&gt;，被 updateByTitle 的
 *    htmlspecialchars_decode 解回 < 落库，净化被存储层反转；
 *  - 最终安全复审：双重/多重实体编码（&amp;lt;script&amp;gt;）在单轮 decode 的净化器下
 *    只还原一层，落库 decode 后恶意 HTML 逐跳复活（用例 4）。
 *
 * 本测试需要数据库（走真实 Page::updateByTitle），在 showdoc-dev 容器内运行：
 *   docker exec showdoc-dev php /app/showdoc.cc/server/tests/office-import/sanitize_persist_test.php
 *
 * 前置：默认走开源版 sqlite（Sqlite/showdoc.db.php）；.env 配置 DB_TYPE=mysql 时走 MySQL。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Common\Helper\MarkdownSanitizer;
use App\Model\Page;

// ---- 启动数据库连接（与正式入口同口径）----
// 先加载项目 .env（容器 CLI 不会自动加载）
$envFile = dirname(__DIR__, 2) . '/../.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) as $k => $v) {
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = $v;
        }
    }
}
$env = static fn(string $k, $d) => $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k) ?: $d;
$dbType = strtolower($env('DB_TYPE', 'sqlite'));
$capsule = new \Illuminate\Database\Capsule\Manager();
if ($dbType === 'mysql') {
    $capsule->addConnection([
        'driver'    => 'mysql',
        'host'      => $env('DB_HOST', '127.0.0.1'),
        'port'      => $env('DB_PORT', '3306'),
        'database'  => $env('DB_NAME', 'showdoc'),
        'username'  => $env('DB_USER', 'root'),
        'password'  => $env('DB_PWD', ''),
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
} else {
    // 开源版默认 sqlite（Sqlite/showdoc.db.php），直接连真实库测试。
    // 注意必须与 Database.php 同口径带 PDO::ATTR_STRINGIFY_FETCHES（数字字段返回
    // 字符串），否则本测试的取回内容与真实运行环境行为不一致
    $dbPath = $env('DB_NAME', dirname(__DIR__, 3) . '/Sqlite/showdoc.db.php');
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => $dbPath,
        'prefix'   => '',
        'options'  => [
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        ],
    ]);
}
$capsule->setAsGlobal();
$capsule->bootEloquent();

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

// ---- 建测试项目 ----
$now = time();
$itemId = \Illuminate\Database\Capsule\Manager::table('item')->insertGetId([
    'item_name'        => 'UT_sanitize_persist_' . substr(uniqid(), -6),
    'item_domain'      => '',
    'item_type'        => 1,
    'item_description' => '',
    'password'         => md5(uniqid()),
    'uid'              => 1,
    'username'         => 'unit-test',
    'addtime'          => $now,
]);
if (!$itemId) {
    die("无法创建测试项目\n");
}
echo "测试项目 item_id={$itemId}\n";

$readRaw = static function (int $itemId, string $title) use ($capsule): ?string {
    $table = Page::tableForItem($itemId);
    $row = $capsule::table($table)
        ->where('item_id', $itemId)
        ->where('is_del', 0)
        ->where('page_title', htmlspecialchars(htmlspecialchars_decode($title)))
        ->first();
    if (!$row) {
        return null;
    }
    return \App\Common\Helper\ContentCodec::decompress($row->page_content) ?: (string) $row->page_content;
};

$cleanup = static function () use ($itemId, $capsule) {
    $table = Page::tableForItem($itemId);
    $capsule::table($table)->where('item_id', $itemId)->delete();
    $capsule::table('item')->where('item_id', $itemId)->delete();
};

try {
    // ==== 用例 1：净化 → updateByTitle → 读出不含 <script ====
    echo "\n== 用例 1：净化输出经 updateByTitle 落库后不得复活 <script ==\n";
    $payload = "# 标题\n\n<script>alert(1)</script>\n\n正文 <img src=x onerror=alert(2)>\n\n[链接](javascript:alert(3))\n";
    $sanitized = MarkdownSanitizer::sanitize($payload);
    $title = 'XSS 回归 ' . substr(uniqid(), -6);

    $pageId = Page::updateByTitle($itemId, $title, $sanitized, '', 99, 1, 'unit-test');
    $pass('页面写入成功', $pageId > 0, $pageId);
    $stored = $readRaw($itemId, $title);
    $pass('页面可读回', $stored !== null, $title);

    // 核心断言：html_entity_decode 模拟所有下游消费者可能做的实体解码
    $decoded = html_entity_decode((string) $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pass('落库内容不含 <script', strpos((string) $stored, '<script') === false, $stored);
    $pass('落库内容实体解码后仍不含 <script（updateByTitle 的 decode 不再复活载荷）', strpos($decoded, '<script') === false, $decoded);
    $pass('落库内容不含 <img 裸标签', strpos($decoded, '<img') === false, $decoded);
    $pass('javascript: 链接被中和', strpos($decoded, '](javascript:') === false, $decoded);

    // ==== 用例 2：未闭合 script ====
    echo "\n== 用例 2：未闭合 <script ====\n";
    $payload = "<script>alert(1)\n未闭合";
    $sanitized = MarkdownSanitizer::sanitize($payload);
    $title2 = 'XSS 未闭合 ' . substr(uniqid(), -6);
    Page::updateByTitle($itemId, $title2, $sanitized, '', 99, 1, 'unit-test');
    $stored2 = $readRaw($itemId, $title2);
    $decoded2 = html_entity_decode((string) $stored2, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pass('未闭合 script 落库后不复活', strpos($decoded2, '<script') === false, $decoded2);

    // ==== 用例 4：双重/三重实体编码（最终安全复审发现的绕过链）====
    // 漏洞机理：sanitizer 只 decode 一轮 → &amp;lt;script&amp;gt; 只还原到 &lt;script&gt;
    // → updateByTitle 落库时 htmlspecialchars_decode 再解一层 → <script> 复活入库。
    // 修复后：sanitize 入口循环解码至不动点，任何层数编码在净化前已完全展开。
    echo "\n== 用例 4：双重实体编码载荷（循环解码回归） ==\n";
    $doublePayloads = [
        '双重 script'      => "# 标题\n\n&amp;lt;script&amp;gt;alert('x1')&amp;lt;/script&amp;gt;\n\n正文",
        '双重 onerror img'  => "正文 &amp;lt;img src=x onerror=alert('x2')&amp;gt; 尾部",
        '双重 javascript:'  => "[点我](javascript&amp;#58;alert('x3'))",
        '三重 script'       => "&amp;amp;lt;script&amp;amp;gt;alert('x4')&amp;amp;lt;/script&amp;amp;gt;",
        '三重 onerror'      => "&amp;amp;lt;img src=x onerror=alert('x5')&amp;amp;gt;",
        '三重 javascript:'  => "[链接](&amp;#106;avascript:alert('x6'))",
        '五层深编码'        => "&amp;amp;amp;amp;lt;script&amp;amp;amp;amp;gt;alert('x7')&amp;amp;amp;amp;lt;/script&amp;amp;amp;amp;gt;",
    ];
    foreach ($doublePayloads as $name => $payload) {
        $sanitized = MarkdownSanitizer::sanitize($payload);
        $titleN = '实体回归 ' . $name . ' ' . substr(uniqid(), -6);
        $pidN = Page::updateByTitle($itemId, $titleN, $sanitized, '', 99, 1, 'unit-test');
        $storedN = (string) $readRaw($itemId, $titleN);
        // 模拟下游任意单次解码（含 updateByTitle 落库 decode + 前端渲染 decode）
        $decodedN = html_entity_decode($storedN, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pass("[{$name}] 写入成功", $pidN > 0, $pidN);
        $pass("[{$name}] 净化输出不含可执行标签", strpos($sanitized, '<script') === false && strpos($sanitized, '<img') === false, $sanitized);
        $pass("[{$name}] 落库后（含一次 decode）不复活 <script", strpos($decodedN, '<script') === false, $decodedN);
        $pass("[{$name}] 落库后（含一次 decode）不复活 <img onerror", strpos($decodedN, '<img') === false, $decodedN);
        $pass("[{$name}] javascript: 伪协议不复活", stripos($decodedN, '](javascript:') === false && stripos($decodedN, 'avascript:') === false, $decodedN);
    }

    // ==== 用例 4b：循环解码上限之外的极端层数（6/21/30 层）====
    // 修复初版曾存在边界漏洞：5 轮解码上限 + 有限递归深度耗尽后，残余实体层
    // 在落库 decode 后复活（实测第 21 层可绕过）。现兜底为「剥标签 + 删除残余
    // 实体序列」，任何层数都必须安全。
    echo "\n== 用例 4b：超上限层数实体编码（兑底路径回归） ==\n";
    foreach ([6, 21, 30] as $layers) {
        $payload = '<script>alert(99)</script>';
        for ($i = 0; $i < $layers; $i++) {
            $payload = htmlspecialchars($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $sanitized = MarkdownSanitizer::sanitize($payload);
        $titleB = '实体' . $layers . '层 ' . substr(uniqid(), -6);
        Page::updateByTitle($itemId, $titleB, $sanitized, '', 99, 1, 'unit-test');
        $storedB = (string) $readRaw($itemId, $titleB);
        $decodedB = html_entity_decode($storedB, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pass("[{$layers}层] 落库后（含一次 decode）不复活 <script", strpos($decodedB, '<script') === false, $decodedB);
    }

    // ==== 用例 3：正常内容不被破坏 ====
    echo "\n== 用例 3：正常 Markdown 不被破坏 ==\n";
    $payload = "# 标题\n\n正常 **加粗** 与 [链接](https://example.com/a?b=1&c=2)\n\n```\n<code>代码块内的标签保留</code>\n```\n";
    $sanitized = MarkdownSanitizer::sanitize($payload);
    $title3 = '正常内容 ' . substr(uniqid(), -6);
    Page::updateByTitle($itemId, $title3, $sanitized, '', 99, 1, 'unit-test');
    $stored3 = (string) $readRaw($itemId, $title3);
    // 主版 page_content 压缩存储（decompress 后即净化原文）；开源版
    // updateByTitle 对内容整体 htmlspecialchars 存储，读出为转义态——
    // 与下游消费者同口径先做一次 decode 再断言（安全断言不受影响：上面的
    // XSS 用例已验证 decode 后不复活载荷）
    $decoded3 = html_entity_decode($stored3, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pass('代码块内容保留', strpos($decoded3, '<code>代码块内的标签保留</code>') !== false, $stored3);
    $pass('https 链接保留', strpos($decoded3, 'https://example.com/a?b=1&c=2') !== false, $stored3);

    echo "\n";
    if ($failures === 0) {
        echo "ALL PASS\n";
        exit(0);
    }
    echo "{$failures} FAILURES\n";
    exit(1);
} finally {
    $cleanup();
}
