<?php

namespace App\Common\Helper;

/**
 * Office 导入拆页器（纯函数式）。
 *
 * 输入 Markdown 文本 + split 模式，输出页面数组（标题、内容、归属目录）。
 *
 * 模式：
 *  - none   整个文档单页
 *  - heading 按 Markdown 标题层级拆页（仅 doc/docx/pdf 有意义）
 *  - sheet  按工作表拆页（anydoc 对 xlsx 输出以 "## 表名" 分节）
 *  - slide  按幻灯片拆页（anydoc 对 pptx 输出以 "## " 分节）
 *
 * heading 规则（设计文档 §3）：
 *  - H1 → 目录页（挂在该层级）；H2 → 子页；H3 及以下并入当前子页/目录页正文
 *  - 第一个标题前的内容 → 「概述」页
 *  - 无 H1 时 H2 升格为目录页，H3 并入；一个标题都没有 → 单页（等同 none）
 *  - 空页跳过（目录页允许无正文）
 *  - 同一父级下重复标题加序号
 */
class Splitter
{
    /** 概述页标题 */
    public const OVERVIEW_TITLE = '概述';

    /**
     * 拆分入口。
     *
     * @param string $markdown GFM Markdown
     * @param string $mode none|heading|sheet|slide
     * @return array<int, array{title: string, content: string, cat: string, is_cat: bool}>
     *   title  页面标题
     *   content 页面内容（Markdown，不含本页标题行）
     *   cat    归属目录名（'' = 根目录；对应 H1 标题文本）
     *   is_cat 是否是目录页（heading 模式下 H1 生成的页；sheet/slide 恒为 false）
     */
    public static function split(string $markdown, string $mode): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return [];
        }

        switch ($mode) {
            case 'heading':
                return self::splitByHeading($markdown);
            case 'sheet':
                return self::splitByH2Section($markdown);
            case 'slide':
                return self::splitByH2Section($markdown);
            case 'none':
            default:
                return [[
                    'title'   => '',
                    'content' => $markdown,
                    'cat'     => '',
                    'is_cat'  => false,
                ]];
        }
    }

    // ------------------------------------------------------------------
    // heading 模式
    // ------------------------------------------------------------------

    private static function splitByHeading(string $markdown): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $markdown);

        // 扫描标题层级，决定拆分层级（无 H1 时退化按 H2）
        $hasH1 = false;
        foreach ($lines as $line) {
            if (self::headingLevel($line) === 1) {
                $hasH1 = true;
                break;
            }
        }
        // top = 目录页层级；sub = 子页层级（无 H1 退化时无子页层级，H3+ 全部并入）
        $topLevel = $hasH1 ? 1 : 2;
        $subLevel = $hasH1 ? 2 : 0;

        // 是否存在任何标题
        $anyHeading = false;
        foreach ($lines as $line) {
            if (self::headingLevel($line) > 0) {
                $anyHeading = true;
                break;
            }
        }
        if (!$anyHeading) {
            // 一个标题都没有 → 单页
            return [[
                'title'   => '',
                'content' => $markdown,
                'cat'     => '',
                'is_cat'  => false,
            ]];
        }

        $pages = [];          // 按文档顺序生成的页（title/cat/is_cat/lines）
        $overview = ['lines' => []];

        // 按文档顺序直接入列：目录页先入列，子页随后，正文行归入最后一个页容器
        $inCodeBlock = false;

        foreach ($lines as $line) {
            // 代码块状态切换（``` 或 ~~~）
            if (preg_match('/^(```|~~~)/', trim($line))) {
                $inCodeBlock = !$inCodeBlock;
            }
            if (!$inCodeBlock) {
                $level = self::headingLevel($line);
                $text = self::headingText($line);

                if ($level > 0 && $level <= $topLevel) {
                    // 新 H1（或退化的 H2-as-H1）→ 目录页
                    $pages[] = ['title' => $text, 'cat' => '', 'is_cat' => true, 'lines' => []];
                    continue;
                }
                if ($level > 0 && $level === $subLevel) {
                    // 新 H2 子页，归属最近的目录页
                    $catTitle = '';
                    for ($i = count($pages) - 1; $i >= 0; $i--) {
                        if ($pages[$i]['is_cat']) {
                            $catTitle = $pages[$i]['title'];
                            break;
                        }
                    }
                    $pages[] = ['title' => $text, 'cat' => $catTitle, 'is_cat' => false, 'lines' => []];
                    continue;
                }
            }

            // 正文 / 代码块 / H3+ 标题行：归入最后一个页容器，否则入概述
            if (!empty($pages)) {
                $pages[count($pages) - 1]['lines'][] = $line;
            } else {
                $overview['lines'][] = $line;
            }
        }

        // 空页跳过 + 同名序号（目录页与子页分属不同命名空间：子页按其父目录去重，
        // 目录页与概述页共用根层级命名空间）
        $usedNamesByCat = [];
        $result = [];   // 先初始化：所有页都被空页跳过时也要返回数组而非 null

        // 概述页（首个标题前的内容）先注册根命名空间，参与同名去重
        $overviewContent = trim(implode("\n", $overview['lines']));
        if ($overviewContent !== '') {
            $usedNamesByCat[''] = [mb_strtolower(self::OVERVIEW_TITLE) => 1];
            $result[] = [
                'title'   => self::OVERVIEW_TITLE,
                'content' => $overviewContent,
                'cat'     => '',
                'is_cat'  => false,
            ];
        }

        foreach ($pages as $page) {
            $content = trim(implode("\n", $page['lines']));
            // 空页跳过：非目录页且内容为空
            if (!$page['is_cat'] && $content === '') {
                continue;
            }

            $catKey = $page['is_cat'] ? '' : $page['cat'];
            if (!isset($usedNamesByCat[$catKey])) {
                $usedNamesByCat[$catKey] = [];
            }
            $title = self::dedupeTitle($page['title'], $usedNamesByCat[$catKey]);

            $result[] = [
                'title'   => $title,
                'content' => $content,
                'cat'     => $catKey,
                'is_cat'  => $page['is_cat'],
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // sheet / slide 模式
    // ------------------------------------------------------------------

    /**
     * anydoc 对 xlsx/pptx 的输出统一是若干 "## 标题" 分节。
     * 每节一页；无节标题（输出中没有 ## 行）时整文档单页。
     */
    private static function splitByH2Section(string $markdown): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $markdown);
        $sections = [];
        $current = ['title' => null, 'lines' => []];
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^```/', trim($line))) {
                $inCodeBlock = !$inCodeBlock;
            }
            $level = $inCodeBlock ? 0 : self::headingLevel($line);
            if ($level === 2) {
                if ($current['title'] !== null || trim(implode("\n", $current['lines'])) !== '') {
                    $sections[] = $current;
                }
                $current = ['title' => self::headingText($line), 'lines' => []];
            } else {
                $current['lines'][] = $line;
            }
        }
        if ($current['title'] !== null || trim(implode("\n", $current['lines'])) !== '') {
            $sections[] = $current;
        }

        // 没有任何 ## 节标题 → 单页
        if (empty($sections) || ($sections[0]['title'] === null && count($sections) === 1)) {
            return [[
                'title'   => '',
                'content' => $markdown,
                'cat'     => '',
                'is_cat'  => false,
            ]];
        }

        $result = [];
        $used = [];
        $index = 0;
        foreach ($sections as $sec) {
            $index++;
            $content = trim(implode("\n", $sec['lines']));
            $title = $sec['title'];
            if ($title === null || trim($title) === '') {
                if ($content === '') {
                    continue; // 无标题且无内容 → 跳过
                }
                $title = '第 ' . $index . ' 节';
            }
            // 有节标题的空节保留成页（如末尾无正文的幻灯片/空工作表）
            $title = self::dedupeTitle($title, $used);
            $result[] = [
                'title'   => $title,
                'content' => $content,
                'cat'     => '',
                'is_cat'  => false,
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // 工具方法
    // ------------------------------------------------------------------

    /**
     * 行的标题层级，非标题返回 0。
     * 匹配 ATX 标题（# ~ ######），且 # 后必须有空白或行尾。
     */
    private static function headingLevel(string $line): int
    {
        $trimmed = ltrim($line);
        if (preg_match('/^(#{1,6})(\s+|$)/', $trimmed, $m)) {
            return strlen($m[1]);
        }
        // setext 标题（=== / ---）不参与拆页，按正文处理
        return 0;
    }

    /** 取标题行文本（去掉 # 前缀） */
    private static function headingText(string $line): string
    {
        $trimmed = ltrim($line);
        if (preg_match('/^#{1,6}\s*(.*?)\s*#*\s*$/', $trimmed, $m)) {
            return trim($m[1]);
        }
        return trim($trimmed);
    }

    /**
     * 同一父级下重复标题追加序号：安装 / 安装 (2) / 安装 (3)...
     *
     * @param string $title 原标题
     * @param array<string, int> $used 已用标题集合（引用传递，会被更新）
     */
    private static function dedupeTitle(string $title, array &$used): string
    {
        $title = trim($title);
        if ($title === '') {
            $title = '未命名';
        }
        $base = $title;
        // 剥掉我们之前追加的 " (n)" 再计数，保证幂等
        if (preg_match('/^(.*) \((\d+)\)$/u', $title, $m)) {
            $base = $m[1];
        }
        $key = mb_strtolower($base);
        if (!isset($used[$key])) {
            $used[$key] = 1;
            return $base;
        }
        $used[$key]++;
        return $base . ' (' . $used[$key] . ')';
    }
}
