<?php
/**
 * 生成 Office 导入测试文件（需要 python3 + python-docx/python-pptx/openpyxl）
 *
 * 输出到 tests/office-import/fixtures/：
 *   probe.docx  标题层级 + 表格 + 列表（heading 拆页用）
 *   probe.pptx  3 页幻灯片（slide 拆页用）
 *   probe.xlsx  2 个工作表（sheet 拆页用）
 *   text.pdf    2 页文本型 PDF（手写 PDF，无依赖）
 *   fake.pdf    随机字节（模拟扫描件/损坏 PDF）
 *
 * 用法: php make_fixtures.php
 */

$dir = __DIR__ . '/fixtures';
@mkdir($dir, 0755, true);

$py = <<<PY
from docx import Document
from pptx import Presentation
from pptx.util import Inches
import openpyxl

doc = Document()
doc.add_paragraph('这是第一段引言文字')
doc.add_heading('第一章 安装', level=1)
doc.add_paragraph('安装说明内容')
doc.add_heading('1.1 环境要求', level=2)
doc.add_paragraph('需要 PHP 8')
doc.add_heading('1.2 安装步骤', level=2)
doc.add_paragraph('下载并解压')
doc.add_heading('第二章 配置', level=1)
doc.add_paragraph('配置说明')
doc.add_heading('2.1 深层标题', level=3)
doc.add_paragraph('H3 内容应并入上一级')
doc.save('{$dir}/probe.docx')

prs = Presentation()
s1 = prs.slides.add_slide(prs.slide_layouts[0])
s1.shapes.title.text = '封面标题'
s1.placeholders[1].text = '副标题内容'
s2 = prs.slides.add_slide(prs.slide_layouts[1])
tb = s2.shapes.add_textbox(Inches(1), Inches(1), Inches(4), Inches(2))
tb.text_frame.text = '第二个无标题幻灯片内容'
s3 = prs.slides.add_slide(prs.slide_layouts[5])
s3.shapes.title.text = '第三页 总结'
prs.save('{$dir}/probe.pptx')

wb = openpyxl.Workbook()
ws = wb.active
ws.title = '用户表'
ws.append(['姓名','年龄','城市'])
ws.append(['张三', 28, '北京'])
ws2 = wb.create_sheet('订单表')
ws2.append(['订单号','金额'])
ws2.append(['A001', 99.5])
wb.save('{$dir}/probe.xlsx')
print('python fixtures ok')
PY;

exec('python3 -c ' . escapeshellarg('import docx, pptx, openpyxl') . ' >/dev/null 2>&1', $o, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "缺少 python3 依赖（python-docx/python-pptx/openpyxl），Office fixture 未生成\n");
} else {
    $tmp = tempnam(sys_get_temp_dir(), 'mkfx_') . '.py';
    file_put_contents($tmp, $py);
    exec('python3 ' . escapeshellarg($tmp), $o, $rc2);
    @unlink($tmp);
    echo implode("\n", $o), "\n";
    if ($rc2 !== 0) {
        fwrite(STDERR, "python fixture 生成失败\n");
    }
}

// 手写文本型 PDF（2 页）
function pdf_stream(string $data): string
{
    return "<</Length " . strlen($data) . ">>stream\n" . $data . "\nendstream";
}
function build_pdf(array $objects): string
{
    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($out);
        $out .= "{$num} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($out);
    $max = max(array_keys($objects));
    $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
    }
    $out .= "trailer\n<</Size " . ($max + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    return $out;
}
file_put_contents($dir . '/text.pdf', build_pdf([
    1 => '<</Type/Catalog/Pages 2 0 R>>',
    2 => '<</Type/Pages/Kids[3 0 R 4 0 R]/Count 2>>',
    3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 5 0 R/Resources<</Font<</F1 6 0 R>>>>>>',
    4 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 7 0 R/Resources<</Font<</F1 6 0 R>>>>>>',
    5 => pdf_stream("BT /F1 18 Tf 72 700 Td (Hello PDF Page One) Tj ET\n"),
    7 => pdf_stream("BT /F1 18 Tf 72 700 Td (Second page content here) Tj ET\n"),
    6 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
]));

// 假 PDF（随机字节）
file_put_contents($dir . '/fake.pdf', random_bytes(100000));

echo "fixtures done: {$dir}\n";
foreach (glob($dir . '/*') as $f) {
    echo "  ", basename($f), " ", filesize($f), " bytes\n";
}
