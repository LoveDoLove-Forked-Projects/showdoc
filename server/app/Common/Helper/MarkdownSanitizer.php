<?php

namespace App\Common\Helper;

/**
 * Office 导入 Markdown 净化器。
 *
 * 背景：anydoc 转换出的 Markdown 会原样保留文档正文中的 HTML 片段
 * （实测 Word 正文中输入 <img src=x onerror=...> 会一字不差地出现在输出里），
 * 若不处理直接落库，就是存储型 XSS（编辑器 htmlDecode 配置允许 style/script/iframe）。
 *
 * 策略（对代码块以外的文本）：
 *  1. 整块移除危险标签对（<script>...</script>、<iframe>、<svg> 等）及其内容；
 *  2. 其余 HTML 标签一律「剥除」而非转义：开/闭标签、注释、声明、PI 直接删除。
 *     ⚠️ 不能用 &lt; 转义：Page::updateByTitle 落库前会 htmlspecialchars_decode，
 *     实体会被解回 < 导致净化被存储层反转（回归见 sanitize_persist_test.php）；
 *  3. Markdown 链接/图片的 URL 做协议白名单（http/https/mailto/tel/ftp 与
 *     相对路径），javascript:/vbscript:/data: 等伪协议替换为 #。
 *     判定前先做 HTML 实体解码与控制字符剔除，防 &#106;avascript、
 *     jav&#x09;ascript 之类混淆。
 *
 * 净化前先对非代码段做 HTML 实体解码至不动点（循环解码，上限 5 轮防 DoS）：
 * 文档正文不但会有 &lt;script&gt; 形态，还会有双重/多重实体形态
 * （&amp;lt;script&amp;gt; 等）——单轮解码不足以还原成真实标签，
 * 落库后 Page::updateByTitle 的 htmlspecialchars_decode 会再解一层，
 * 多重编码的恶意 HTML 将逐跳复活。循环解码保证无论编码多少层，
 * 进入剥除流程的都是最终明文（回归见 sanitize_persist_test.php）。
 *
 * 代码块（``` / ~~~ 围栏内）内容不做任何处理：渲染器对代码块本身就按纯文本
 * 展示，且改写代码样本内容会破坏用户数据。
 */
class MarkdownSanitizer
{
    /** 整块移除（含内容）的标签对 */
    private const STRIP_TAGS = 'script|iframe|object|embed|style|noscript|template|frameset|svg|math|form|textarea|title|xmp|noembed|plaintext|base|link|meta';

    /** 链接允许的协议（小写，含 : 结尾比较） */
    private const URL_ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp'];

    /** 明确封杀的伪协议前缀（兜底，正常走白名单逻辑） */
    private const URL_BLOCKED_SCHEMES = 'javascript|vbscript|data|file|blob|livescript|mocha|jscript|about|chrome|res|view-source';

    /**
     * 净化 Markdown 文本。
     */
    public static function sanitize(string $markdown): string
    {
        $markdown = self::normalizeNulls($markdown);
        if ($markdown === '') {
            return '';
        }

        $out = [];
        foreach (self::splitCodeFences($markdown) as $seg) {
            $out[] = $seg['code'] ? $seg['text'] : self::sanitizeSegment($seg['text']);
        }
        return implode('', $out);
    }

    /**
     * 判断一个 URL（来自 Markdown 链接/图片）是否安全。
     * 安全 = 相对路径 / #锚点，或白名单协议。
     */
    public static function isSafeUrl(string $url): bool
    {
        // HTML 实体解码（浏览器会解 href 中的实体，判定必须与浏览器同口径）
        $u = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 去掉所有控制字符与空白（java\x00script / jav\tascript 混淆）
        $u = preg_replace('/[\x00-\x20\x7f]+/u', '', $u);
        if ($u === '' || $u === '#' || $u[0] === '/' || $u[0] === '#' || $u[0] === '.') {
            return true;
        }
        // 协议白名单：scheme 必须出现在第一个 ':' 且仅含 [a-z0-9+-.
        if (preg_match('~^([a-z0-9+.\-]+)://~i', $u, $m) || preg_match('~^([a-z0-9+.\-]+):~i', $u, $m)) {
            $scheme = strtolower($m[1]);
            return in_array($scheme, self::URL_ALLOWED_SCHEMES, true);
        }
        // 无协议的裸相对路径（含 query）放行
        return true;
    }

    // ------------------------------------------------------------------

    /** 去除 NUL 字节（防截断/绕过），保留换行 */
    private static function normalizeNulls(string $s): string
    {
        return str_replace("\0", '', $s);
    }

    /**
     * 按代码围栏把 Markdown 切成 [code=>bool, text=>string] 段。
     * 围栏判定与 Splitter 同口径：行首 ``` 或 ~~~（含缩进）切换状态。
     */
    private static function splitCodeFences(string $markdown): array
    {
        $segs = [];
        $current = '';
        $inCode = false;
        foreach (preg_split("/(\r\n|\r|\n)/", $markdown) as $i => $line) {
            $eol = $i === 0 ? '' : "\n";
            if (preg_match('/^(\s*)(```|~~~)/', $line)) {
                $segs[] = ['code' => $inCode, 'text' => $current];
                $inCode = !$inCode;
                $current = $line . "\n";
                continue;
            }
            $current .= $eol . $line;
        }
        $segs[] = ['code' => $inCode, 'text' => $current];
        return $segs;
    }

    /** 对非代码段做净化 */
    private static function sanitizeSegment(string $text): string
    {
        // 0. HTML 实体循环解码至不动点：&lt;script&gt;、&amp;lt;script&amp;gt;、
        //    &amp;amp;lt;... 等多重实体形态必须完全还原成真实标签再净化，否则
        //    单轮解码后剩余的实体层会被存储层的 decode 逐跳复活（存储型 XSS）。
        //    上限 5 轮防超长输入下的 DoS；若 5 轮解码后仍在变化（≥6 层实体的
        //    极端输入），剩余实体层在落库 decode 后仍可能复活，绝不放行——
        //    兜底对解码结果递归再走一遍完整净化（每层消耗 5 轮解码，任意层数
        //    实体最终都会被完全展开后剥除），深度上限防御异常构造的自指输入。
        $overflow = false;
        for ($i = 0; $i < 5; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
            if ($i === 4) {
                $overflow = true; // 第 5 轮后仍未收敛
            }
        }
        if ($overflow) {
            static $depth = 0;
            if ($depth < 3) {
                $depth++;
                try {
                    return self::sanitizeSegment($text);
                } finally {
                    $depth--;
                }
            }
            // 递归深度超限：剥除一切标签并删除残余实体序列（宁可破坏内容也不
            // 放行可执行载荷；实体是删除而非解码，后续任何 decode 都无从复活）
            $text = (string) preg_replace('~<[^>]*>~s', '', $text);
            return (string) preg_replace('~&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);~', '', $text);
        }

        // 1. 整块移除危险标签对（含内容）
        $text = (string) preg_replace(
            '~<\s*(' . self::STRIP_TAGS . ')\b[^>]*>.*?<\s*/\s*\1\s*>~is',
            '',
            $text
        );

        // 2. 链接/图片 URL 协议净化（在剥标签之前做，此时 url 语法还是原样）
        $text = self::neutralizeUrls($text);

        // 3. 剥除其余一切 HTML 标签形态（含未闭合标签、注释、<!DOCTYPE、</x>、处理指令）。
        //    注意：必须删除而不是转义为 &lt; —— Page::updateByTitle 写库前会
        //    htmlspecialchars_decode，转义出的实体会被解回 < 导致净化被反转
        //    （存储型 XSS，见 sanitize_persist_test.php 回归测试）
        $text = (string) preg_replace('~<!--.*?-->|<\?.*?\?>|<![A-Za-z][^>]*>~s', '', $text); // 注释/PI/声明
        $text = (string) preg_replace('~</?[a-zA-Z][^>]*>~s', '', $text);                       // 开/闭标签

        return $text;
    }

    /** 把段内 Markdown 链接/图片的危险 URL 替换为 #。
     *  目标允许含空白（jav\tascript 混淆：浏览器会把 URL 内控制字符剔除，
     *  lenient 渲染器也可能接受带空白的 destination，判定时同样剔除后校验） */
    private static function neutralizeUrls(string $text): string
    {
        // 行内链接/图片：[text](dest)，dest 可为 <...> 包裹或含引号 title
        $text = (string) preg_replace_callback(
            '/(!?)\[((?:[^\[\]]|\[[^\]]*\])*)\]\(([^)]*)\)/',
            function (array $m) {
                $url = self::splitLinkDest($m[3]);
                if ($url !== null && !self::isSafeUrl($url)) {
                    return $m[1] . '[' . $m[2] . '](#)';
                }
                return $m[0];
            },
            $text
        );

        // 引用式链接定义：[label]: url
        $text = (string) preg_replace_callback(
            '/^(\s{0,3}\[[^\]^]+\]:\s*)(\S.*|<[^>]*>)(\s*)$/m',
            function (array $m) {
                $url = self::splitLinkDest($m[2]);
                if ($url !== null && !self::isSafeUrl($url)) {
                    return $m[1] . '#' . $m[3];
                }
                return $m[0];
            },
            $text
        );

        return $text;
    }

    /** 从链接目标串中取出 URL（剥离 <> 包裹与尾部 "title"/'title'/(title)） */
    private static function splitLinkDest(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '<' && substr($raw, -1) === '>') {
            return substr($raw, 1, -1);
        }
        // 尾部引号包裹的 title
        if (preg_match('/^(.*\S)\s+("[^"]*"|\'[^\']*\'|\([^()]*\))\s*$/u', $raw, $m)) {
            $raw = $m[1];
        }
        return $raw;
    }
}
