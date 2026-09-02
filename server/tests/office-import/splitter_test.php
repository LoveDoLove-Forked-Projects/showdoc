<?php
/**
 * Splitter 拆页规则单元测试（设计文档 §3 四类边界 + 基本场景）
 *
 * 用法: docker exec showdoc-dev php /app/showdoc.cc/server/tests/office-import/splitter_test.php
 * 或:   php tests/office-import/splitter_test.php（在 server 目录下）
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Common\Helper\Splitter;

$failures = 0;
$pass = function (string $name, $cond, $got = null) use (&$failures) {
    if ($cond) {
        echo "  ✓ {$name}\n";
    } else {
        $failures++;
        $gotStr = is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : var_export($got, true);
        echo "  ✗ {$name}\n    got: {$gotStr}\n";
    }
};
$titles = fn(array $pages) => array_map(fn($p) => $p['title'] . ($p['cat'] !== '' ? '@' . $p['cat'] : ''), $pages);

echo "== 基本结构：H1 目录页 / H2 子页 / H3 并入 ==\n";
$md = <<<MD
引言内容第一段
引言内容第二段

# 第一章 安装

安装说明

## 1.1 环境要求

需要 PHP 8

### 1.1.1 细节

深层内容

## 1.2 步骤

下载解压

# 第二章 配置

配置正文
MD;
$pages = Splitter::split($md, 'heading');
$pass('页面数 = 5（概述+目录1+子页2+目录2）', count($pages) === 5, $titles($pages));
$pass('第一页是概述页（标题前内容）', $pages[0]['title'] === '概述' && str_contains($pages[0]['content'], '引言内容第一段'), $titles($pages));
$pass('H1 生成目录页', $pages[1]['title'] === '第一章 安装' && $pages[1]['is_cat'] === true && $pages[1]['cat'] === '', $titles($pages));
$pass('H2 子页归属 H1 目录', $pages[2]['title'] === '1.1 环境要求' && $pages[2]['cat'] === '第一章 安装' && $pages[2]['is_cat'] === false, $titles($pages));
$pass('H3 并入 H2 子页', str_contains($pages[2]['content'], '### 1.1.1 细节') && str_contains($pages[2]['content'], '深层内容'), $pages[2]['content']);
$pass('第二章目录页正文保留', str_contains($pages[4]['content'], '配置正文'), $pages[4]['content']);

echo "\n== 边界1：标题前内容 → 概述页 ==\n";
$pages = Splitter::split("# T1\n正文\n# T2\n正文2", 'heading');
$pass('无引言时不生成概述页', count($pages) === 2 && $pages[0]['title'] === 'T1', $titles($pages));
$pages = Splitter::split("引言\n\n# T1\n正文", 'heading');
$pass('有引言时生成概述页在最前', count($pages) === 2 && $pages[0]['title'] === '概述' && $pages[0]['cat'] === '', $titles($pages));

echo "\n== 边界2：无 H1 退化（H2 升格目录页，H3 并入） ==\n";
$md = <<<MD
开头

## 甲组

甲组正文

### 甲组子标题

H3 内容

## 乙组

乙组正文
MD;
$pages = Splitter::split($md, 'heading');
$pass('无 H1：概述 + 2 个升格目录页 = 3 页', count($pages) === 3, $titles($pages));
$pass('H2 升格为目录页', $pages[1]['title'] === '甲组' && $pages[1]['is_cat'] === true, $titles($pages));
$pass('H3 并入升格目录页正文', str_contains($pages[1]['content'], '### 甲组子标题') && str_contains($pages[1]['content'], 'H3 内容'), $pages[1]['content']);

echo "\n== 边界2b：一个标题都没有 → 单页（等同 none） ==\n";
$pages = Splitter::split("纯正文第一行\n\n纯正文第二行", 'heading');
$pass('输出单页且 title 为空', count($pages) === 1 && $pages[0]['title'] === '' && $pages[0]['cat'] === '', $titles($pages));
$pass('内容完整保留', str_contains($pages[0]['content'], '纯正文第二行'), $pages[0]['content']);

echo "\n== 边界3：空页跳过（目录页允许无正文） ==\n";
$md = <<<MD
# 目录一

## 有内容

正文

## 空子页

# 目录二
MD;
$pages = Splitter::split($md, 'heading');
$ts = $titles($pages);
$pass('空 H2 子页被跳过（目录一+有内容+目录二 = 3 页）', count($pages) === 3 && $ts === ['目录一', '有内容@目录一', '目录二'], $ts);
$pass('无正文目录页保留（纯容器）', $pages[2]['title'] === '目录二' && $pages[2]['is_cat'] === true, $ts);

# 空目录页且无子页不产生任何内容的验证
$pages = Splitter::split("# 只有标题", 'heading');
$pass('仅有 H1 仍生成目录页', count($pages) === 1 && $pages[0]['is_cat'] === true, $titles($pages));

echo "\n== 边界4：同名标题加序号（同一父级） ==\n";
$md = <<<MD
# 章

## 安装

A

## 安装

B

## 安装

C

# 章2

## 安装

D
MD;
$pages = Splitter::split($md, 'heading');
$ts = $titles($pages);
$pass(
    '同级同名：安装/安装 (2)/安装 (3)，目录页在前',
    count($pages) === 6
        && $ts[0] === '章' && $ts[1] === '安装@章' && $ts[2] === '安装 (2)@章' && $ts[3] === '安装 (3)@章'
        && $ts[4] === '章2',
    $ts
);
$pass('不同父级同名不冲突', $ts[5] === '安装@章2', $ts);

echo "\n== 同名 H1 目录页加序号 ==\n";
$pages = Splitter::split("# 部署\n\n## x\n\nA\n\n# 部署\n\n## y\n\nB", 'heading');
$ts = $titles($pages);
$pass('目录页同名：部署/部署 (2)', $ts[0] === '部署' && $ts[2] === '部署 (2)', $ts);

echo "\n== 概述页与目录页同名 ==\n";
$pages = Splitter::split("引言\n\n# 概述\n\n正文", 'heading');
$ts = $titles($pages);
$pass('根层级概述与 H1 目录去重：H1 变「概述 (2)」', $ts[0] === '概述' && $ts[1] === '概述 (2)', $ts);

echo "\n== none / sheet / slide 模式 ==\n";
$pages = Splitter::split("# a\n\nb", 'none');
$pass('none 单页保留原标题行', count($pages) === 1 && str_contains($pages[0]['content'], '# a'), $titles($pages));

$md = <<<MD
## 用户表

| a | b |
| --- | --- |
| 1 | 2 |

## 订单表

数据

## 用户表

重复表
MD;
$pages = Splitter::split($md, 'sheet');
$ts = $titles($pages);
$pass('sheet：每表一页 + 同名序号', count($pages) === 3 && $ts[0] === '用户表' && $ts[1] === '订单表' && $ts[2] === '用户表 (2)', $ts);
$pass('sheet：表格内容保留', str_contains($pages[0]['content'], '| a | b |'), $pages[0]['content']);

$pages = Splitter::split("## 封面\n\n副标题\n\n第二页内容（无标题节，并入上一节）\n\n## 总结", 'slide');
$ts = $titles($pages);
$pass('slide：无标题节内容并入上一节（anydoc 输出无幻灯片分隔标记，接受此降级）', count($pages) === 2 && $ts === ['封面', '总结'] && str_contains($pages[0]['content'], '第二页内容'), $ts);
$pass('slide：末尾无正文的节仍成页', $pages[1]['content'] === '' && $pages[1]['title'] === '总结', $ts);

$pages = Splitter::split("没有任何分节的幻灯片内容", 'slide');
$pass('slide：无 ## 节 → 单页', count($pages) === 1 && $pages[0]['title'] === '', $titles($pages));

echo "\n== 代码块中的 # 不当标题 ==\n";
$md = <<<MD
# 真标题

````
# 代码里的伪标题
````

## 真子页

正文
MD;
$pages = Splitter::split($md, 'heading');
$ts = $titles($pages);
$pass('代码块内 # 行不拆页', count($pages) === 2 && $ts === ['真标题', '真子页@真标题'], $ts);
$pass('代码块内容并入其所在页（真标题页）', str_contains($pages[0]['content'], '# 代码里的伪标题'), $pages[0]['content']);

echo "\n== 空文档 ==\n";
$pages = Splitter::split("   \n\n  ", 'heading');
$pass('空白输入返回空数组', $pages === [], $pages);

echo "\n";
if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILURES\n";
exit(1);
