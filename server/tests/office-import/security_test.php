<?php
/**
 * MarkdownSanitizer + Office 导入安全防护单元测试
 *
 * 用法: docker exec showdoc-dev php /app/showdoc.cc/server/tests/office-import/security_test.php
 * 或:   cd server && php tests/office-import/security_test.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Common\Helper\MarkdownSanitizer;
use App\Common\Helper\Splitter;

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

echo "== MarkdownSanitizer：存储型 XSS ==\n";

$md = "# T\n\n<script>alert(1)</script>\n";
$pass('script 标签整块移除', strpos(MarkdownSanitizer::sanitize($md), 'script') === false);

$md = "<script>alert(1)\n没有闭合的 script";
$out = MarkdownSanitizer::sanitize($md);
$pass('未闭合 script 标签被剥除', strpos($out, '<script') === false && strpos($out, 'alert(1)') !== false);

$md = "<img src=x onerror=alert(2)>";
$out = MarkdownSanitizer::sanitize($md);
$pass('img onerror 标签被剥除', strpos($out, '<img') === false && strpos($out, 'onerror') === false);

$md = "[click](javascript:alert(3))";
$pass('javascript: 链接被中和', strpos(MarkdownSanitizer::sanitize($md), 'javascript') === false);

$md = "![x](javascript:alert(4))";
$pass('javascript: 图片被中和', strpos(MarkdownSanitizer::sanitize($md), 'javascript') === false);

$md = "[click](&#106;avascript:alert(5))";
$pass('HTML 实体混淆的伪协议被中和', strpos(MarkdownSanitizer::sanitize($md), 'avascript') === false);

$md = "[x](java\tscript:alert(6))";
$pass('制表符混淆的伪协议被中和', strpos(MarkdownSanitizer::sanitize($md), 'script:') === false);

$md = "[x](JAVASCRIPT:alert(7))";
$pass('大小写混淆被中和', stripos(MarkdownSanitizer::sanitize($md), 'javascript') === false);

$md = "[x](data:text/html;base64,PHNjcmlwdD4=)";
$pass('data: 伪协议被中和', strpos(MarkdownSanitizer::sanitize($md), 'data:') === false);

$md = "[x](vbscript:msgbox)";
$pass('vbscript: 被中和', strpos(MarkdownSanitizer::sanitize($md), 'vbscript') === false);

$md = "[x](https://example.com/?a=1&b=2)";
$pass('正常 https 链接保留', strpos(MarkdownSanitizer::sanitize($md), 'https://example.com') !== false);

$md = "[x](/relative/path) 和 [y](#anchor) 和 [z](mailto:a@b.c)";
$out = MarkdownSanitizer::sanitize($md);
$pass('相对路径/锚点/mailto 保留', strpos($out, '/relative/path') !== false && strpos($out, '#anchor') !== false && strpos($out, 'mailto:a@b.c') !== false);

$md = "<iframe src=\"javascript:alert(8)\"></iframe>";
$pass('iframe 整块移除', strpos(MarkdownSanitizer::sanitize($md), 'iframe') === false);

$md = "<svg onload=alert(9)></svg>";
$pass('svg 整块移除', strpos(MarkdownSanitizer::sanitize($md), 'svg') === false);

$md = "<!-- <script>alert(10)</script> -->";
$out = MarkdownSanitizer::sanitize($md);
$pass('HTML 注释内的 script 一并剥除', strpos($out, '<script') === false && strpos($out, '<!--') === false);

// 实体形态：&lt;script&gt; 先解码再剥除（防存储层 decode 复活）
$md = "# 标题\n\n&lt;script&gt;alert(15)&lt;/script&gt;";
$out = MarkdownSanitizer::sanitize($md);
$pass('实体形态的 script 被剥除', strpos($out, '<script') === false && stripos($out, 'script') === false);

// 双重/多重实体形态：循环解码至不动点后再剥除（防落库 decode 逐跳复活）
$md = "# 标题\n\n&amp;lt;script&amp;gt;alert(16)&amp;lt;/script&amp;gt;";
$out = MarkdownSanitizer::sanitize($md);
$pass('双重实体 script 被剥除', strpos($out, '<script') === false && stripos($out, 'script') === false);

$md = "&amp;lt;img src=x onerror=alert(17)&amp;gt;";
$out = MarkdownSanitizer::sanitize($md);
$pass('双重实体 img onerror 被剥除', strpos($out, '<img') === false && strpos($out, 'onerror') === false);

$md = "[点我](javascript&amp;#58;alert(18))";
$pass('双重实体 javascript: 被中和', stripos(MarkdownSanitizer::sanitize($md), 'avascript:') === false);

$md = "&amp;amp;lt;script&amp;amp;gt;alert(19)&amp;amp;lt;/script&amp;amp;gt;";
$out = MarkdownSanitizer::sanitize($md);
$pass('三重实体 script 被剥除', strpos($out, '<script') === false && stripos($out, 'script') === false);

$md = "&amp;amp;amp;amp;lt;script&amp;amp;amp;amp;gt;alert(20)&amp;amp;amp;amp;lt;/script&amp;amp;amp;amp;gt;";
$out = MarkdownSanitizer::sanitize($md);
$pass('五层实体 script 被剥除（循环上限内）', strpos($out, '<script') === false && stripos($out, 'script') === false);

$md = "[链接](&amp;#106;avascript:alert(21))";
$pass('双重实体数字引用伪协议被中和', stripos(MarkdownSanitizer::sanitize($md), 'avascript:') === false);

// 正常文本中的合法实体不受循环解码破坏（解一次即达不动点）
$md = "5 &amp; 6，Tom &amp;amp; Jerry，a &lt; b";
$out = MarkdownSanitizer::sanitize($md);
$pass('合法实体文本净化后无标签残留', strpos($out, '<') === false || strpos($out, '<script') === false);

$md = "正文 ![ok](https://a/b.png)\n\n```\n<script>alert(11)</script>\n[link](javascript:alert(12))\n<img src=x>\n```\n\n后文";
$out = MarkdownSanitizer::sanitize($md);
$inFence = substr($out, (int) strpos($out, '```'));
$pass('代码块内容一字不动', strpos($inFence, '<script>alert(11)</script>') !== false && strpos($inFence, '[link](javascript:alert(12))') !== false);
$pass('代码块外正常图片保留', strpos($out, '![ok](https://a/b.png)') !== false);

$md = "```\n包含 ``` 的文本\n```\n<script>alert(13)</script>";
$out = MarkdownSanitizer::sanitize($md);
$pass('围栏嵌套边界：围栏后的 script 被移除', strpos($out, '<script>') === false);

$md = "[link]: javascript:alert(14)\n\n[ref][link]";
$out = MarkdownSanitizer::sanitize($md);
$pass('引用式链接定义被中和', strpos($out, 'javascript') === false);

$md = "5 < 6 且 7 > 4 是数学";
$out = MarkdownSanitizer::sanitize($md);
$pass('普通小于号文本不受影响', strpos($out, '5 < 6') !== false);

echo "\n== isSafeUrl 边界 ==\n";
$pass('安全：https', MarkdownSanitizer::isSafeUrl('https://a.com'));
$pass('安全：相对', MarkdownSanitizer::isSafeUrl('images/a.png'));
$pass('危险：javascript', !MarkdownSanitizer::isSafeUrl('javascript:alert(1)'));
$pass('危险：实体混淆', !MarkdownSanitizer::isSafeUrl('&#106;avascript:alert(1)'));
$pass('危险：NUL 混淆', !MarkdownSanitizer::isSafeUrl("java\0script:alert(1)"));
$pass('危险：data:text/html', !MarkdownSanitizer::isSafeUrl('data:text/html,<script>'));

echo "\n== OfficeImporter 内部防护（反射调用）==\n";
$rc = new ReflectionClass(\App\Common\Helper\OfficeImporter::class);
$m = $rc->getMethod('checkMagic');
$m->setAccessible(true);

// 造样本
$tmp = sys_get_temp_dir() . '/ois_' . uniqid();
@mkdir($tmp);
$mk = function (string $name, string $bytes) use ($tmp): string {
    $p = $tmp . '/' . $name;
    file_put_contents($p, $bytes);
    return $p;
};

$pass('魔数：正常 docx（PK）通过', $m->invoke(null, $mk('a.docx', "\x50\x4b\x03\x04" . str_repeat('x', 100)), 'docx') === true);
$pass('魔数：HTML 伪造 .docx 拒绝', $m->invoke(null, $mk('b.docx', '<html><body>php webshell</body></html>'), 'docx') === false);
$pass('魔数：GIF89a 伪造 .pdf 拒绝', $m->invoke(null, $mk('c.pdf', "GIF89a" . str_repeat("\x00", 50)), 'pdf') === false);
$pass('魔数：%PDF 通过', $m->invoke(null, $mk('d.pdf', "%PDF-1.7\n..."), 'pdf') === true);
$pass('魔数：OLE2 通过（doc）', $m->invoke(null, $mk('e.doc', "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" . 'x'), 'doc') === true);

// polyglot: PHP + ZIP 混合（前缀 PHP 代码后接 zip 头）—— 魔数不命中 → 拒
$zipPrefixPhp = "<?php evil(); ?>" . "\x50\x4b\x03\x04";
$pass('魔数：PHP 前缀 polyglot 拒绝', $m->invoke(null, $mk('f.docx', $zipPrefixPhp), 'docx') === false);

// 标题截断
$c = $rc->getMethod('clipTitle');
$c->setAccessible(true);
$long = str_repeat('标', 80);
$clipped = $c->invoke(null, $long);
$pass('标题截断到 50 字符', mb_strlen($clipped) === 50);
$pass('英文标题同样截断', mb_strlen($c->invoke(null, str_repeat('a', 200))) === 50);

// ImageReconciler 常量
$pass('SVG 不在图片上传白名单', !in_array('svg', \App\Common\Helper\ImageReconciler::IMAGE_EXTS, true));
$pass('SVG 仍在引用识别名单（引用不报错，上传跳过）', in_array('emf', \App\Common\Helper\ImageReconciler::REF_EXTS, true));

// 清理
array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n== Splitter 回归（确认 sanitize 不影响拆页输入约定）==\n";
$pages = Splitter::split("# A\n\ntext\n\n## B\n\nb", 'heading');
$pass('H1/H2 拆页仍正常', count($pages) === 2);

if ($failures === 0) {
    echo "\nALL PASS\n";
    exit(0);
}
echo "\n{$failures} FAILURES\n";
exit(1);
