<?php
/**
 * Office 导入 HTTP 接口验收测试
 *
 * 前置条件：
 *   - php-dev 容器内 /usr/local/bin/anydoc 可执行（docker cp 一次性步骤）
 *   - 生成测试文件：php tests/office-import/make_fixtures.php && 逐个 docker cp 进容器
 *   - 两个有效登录 token：一个管理员（ADMIN_TOKEN），一个普通用户（USER_TOKEN）
 *     （写入环境变量或修改下方默认值；token 须存在于 user_token 表）
 *
 * 用法（容器内）:
 *   php tests/office-import/http_test.php
 *
 * 或宿主机（假设容器名 showdoc-dev）:
 *   docker exec showdoc-dev php /app/showdoc.cc/server/tests/office-import/http_test.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$ADMIN_TOKEN = getenv('ADMIN_TOKEN') ?: 'CHANGE_ME';
$USER_TOKEN  = getenv('USER_TOKEN')  ?: 'CHANGE_ME';
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

/** 上传一个文件走 auto 接口 */
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

$fixture = static fn(string $f) => (getenv('FX_DIR') ?: __DIR__ . '/fixtures') . '/' . $f;

echo "== docx heading 导入（新建项目） ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('probe.docx'), ['split' => 'heading']);
$pass('导入成功', ($r['error_code'] ?? -1) === 0, $r);
$pass('按标题拆出 5 页', ($r['data']['page_count'] ?? 0) === 5, $r['data'] ?? null);
$docxItem = (int) ($r['data']['item_id'] ?? 0);
$pass('新项目已创建', $docxItem > 0, $docxItem);

echo "\n== 同名覆盖（幂等重导入） ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('probe.docx'), ['item_id' => $docxItem]);
$pass('重导入全部成功（幂等）', ($r['error_code'] ?? -1) === 0 && ($r['data']['page_count'] ?? 0) === 5, $r);

echo "\n== split 不匹配降级 ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('probe.docx'), ['split' => 'sheet']);
$pass('docx+sheet 降级为 none 并提示', ($r['error_code'] ?? -1) === 0
    && ($r['data']['split'] ?? '') === 'none'
    && !empty($r['data']['notices']), $r['data'] ?? null);

echo "\n== pptx slide / xlsx sheet / pdf ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('probe.pptx'), ['split' => 'slide']);
$pass('pptx 按幻灯片拆页', ($r['error_code'] ?? -1) === 0 && ($r['data']['page_count'] ?? 0) === 2, $r);
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('probe.xlsx'), ['split' => 'sheet']);
$pass('xlsx 按工作表拆页', ($r['error_code'] ?? -1) === 0 && ($r['data']['page_count'] ?? 0) === 2, $r);
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('text.pdf'));
$pass('文本型 PDF 成功', ($r['error_code'] ?? -1) === 0, $r);

echo "\n== 错误路径 ==\n";
$r = importFile($BASE, $ADMIN_TOKEN, $fixture('fake.pdf'));
$pass('扫描/损坏 PDF 明确报错', ($r['error_code'] ?? 0) === 10101 && str_contains($r['error_message'] ?? '', 'PDF'), $r);
$tooBig = tempnam(sys_get_temp_dir(), 'big') . '.docx';
$fp = fopen($tooBig, 'wb');
fseek($fp, 51 * 1024 * 1024 - 1);
fwrite($fp, 'x');
fclose($fp);
$r = importFile($BASE, $ADMIN_TOKEN, $tooBig);
@unlink($tooBig);
$pass('超 50MB 拒绝', ($r['error_code'] ?? 0) !== 0, $r);
$ch = curl_init($BASE);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file' => new CURLFile($fixture('probe.docx'))], CURLOPT_RETURNTRANSFER => true]);
$anon = json_decode(curl_exec($ch), true);
curl_close($ch);
$pass('匿名访问被拒', (($anon['error_code'] ?? 0) === 10102), $anon);

echo "\n== 权限 ==\n";
if ($USER_TOKEN !== 'CHANGE_ME' && $docxItem > 0) {
    $r = importFile($BASE, $USER_TOKEN, $fixture('probe.docx'), ['item_id' => $docxItem]);
    $pass('普通用户导入他人项目被拒', ($r['error_code'] ?? 0) === 10302, $r);
    $r = importFile($BASE, $USER_TOKEN, $fixture('probe.xlsx'));
    $pass('普通用户新建项目导入成功', ($r['error_code'] ?? -1) === 0, $r);
} else {
    echo "  （跳过：未设置 USER_TOKEN）\n";
}

echo "\n";
if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILURES\n";
exit(1);
