<?php

namespace App\Common\Helper;

/**
 * Office 导入图片对齐器（仅对 docx/pptx 生效）。
 *
 * 背景：anydoc v0.2.4 对 docx 的嵌入图片不输出占位符（内容直接跳过），
 * 对 pptx 输出媒体文件名裸文本。因此采用「落地重排」策略：
 *
 *  1. 用 ZipArchive 解包 OOXML，解析 document.xml（docx）/ ppt slides（pptx）
 *     中 r:embed 引用顺序，得到图片出现顺序清单；
 *  2. 把每个 media 文件落地为临时文件，经 Attachment::upload 走现有
 *     附件系统上传拿到 URL；
 *  3. 按顺序生成 Markdown 图片语法追加到文档（anydoc 输出本身不含图片，
 *     全部图片按文档内出现顺序统一补到文末；pptx 每页幻灯片的图片
 *     归属该页——见 reconcile 的 bySlide 参数）。
 *
 * 设计文档原本的「替换占位符」策略对当前 anydoc 版本无占位符可替换，
 * 实现为「全部按顺序补文末」，与设计文档「顺序对不齐的图片追加文末」的
 * 降级行为一致。
 *
 * 开源版说明：Attachment::upload 未配置 OSS 时走本地上传
 * （Public/Uploads/），uploadLocal 优先 move_uploaded_file、失败回退
 * rename——本类的图片来自 zip 解包而非真实上传，依赖该 rename 回退落地，
 * 与开源版行为兼容（主版走 OssHelper，此处行为等价）。
 */
class ImageReconciler
{
    /** zip 内条目数上限（正常 docx/pptx 的条目数为几十到几百，留足余量） */
    public const MAX_ZIP_ENTRIES = 2000;

    /** 解包读取的总字节数上限（解压后的累计大小，防 zip 炸弹；512MB） */
    public const MAX_UNCOMPRESSED_TOTAL = 512 * 1024 * 1024;

    /** 单个 media 文件解压后上限（32MB，超出当损坏跳过） */
    public const MAX_MEDIA_SIZE = 32 * 1024 * 1024;

    /** 允许上传展示的图片扩展名（浏览器可直接渲染；SVG 能携带脚本，禁止） */
    public const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];

    /** 允许识别引用的图片扩展名（含不能直接展示的矢量/打印格式，仅用于判定引用） */
    public const REF_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'emf', 'wmf', 'tiff'];

    /** 图片扩展名 => 魔数（文件头）校验规则 */
    private const MAGIC = [
        'png'  => ['89504e47', 4],
        'jpg'  => ['ffd8ff', 3],
        'jpeg' => ['ffd8ff', 3],
        'gif'  => ['47494638', 4],
        'bmp'  => ['424d', 2],
        'webp' => ['52494646', 4], // RIFF....WEBP
    ];
    /**
     * 解包并上传源文件中的嵌入图片，返回带 URL 的图片清单。
     *
     * @param string $filePath 源 docx/pptx 文件路径
     * @param string $ext 小写扩展名（docx/pptx）
     * @param int $uid 上传者
     * @param int $itemId 归属项目
     * @return array{images: array<int, array{url: string, slide: ?int}>, total: int}
     *         images 按文档内出现顺序排列（只含上传成功的）；slide 为 pptx 中该图
     *         所属幻灯片序号（从 1 开始），docx 为 null；total 为文档内图片总数
     *         （含上传失败被跳过的）。任一图片上传失败则跳过该图（不中断导入）。
     */
    public static function extractImages(string $filePath, string $ext, int $uid, int $itemId): array
    {
        $collected = self::collectImages($filePath, $ext);

        if (empty($collected)) {
            return ['images' => [], 'total' => 0];
        }

        // 复用同一个 ZipArchive 实例：多图文档（如 200 图 pptx）避免逐张重新
        // open/索引 zip（网络盘上代价显著）
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['images' => [], 'total' => count($collected)];
        }

        $result = [];
        try {
            foreach ($collected as $img) {
                $url = self::uploadMedia($zip, $img['entry'], $uid, $itemId, $img['name']);
                if ($url !== null) {
                    $result[] = ['url' => $url, 'slide' => $img['slide']];
                }
            }
        } finally {
            $zip->close();
        }

        return ['images' => $result, 'total' => count($collected)];
    }

    /**
     * 把图片清单按文档顺序追加到 Markdown 末尾。
     *
     * @param string $markdown anydoc 输出的 Markdown
     * @param array<int, array{url: string, slide: ?int}> $images 已上传图片清单
     * @param bool $bySlide true 时每个 slide 序号独立成组（slide 拆页模式用），
     *                      false 时全部追加文末
     * @return array{0: string, 1: array<int, string>} [处理后的整篇 Markdown, 每页的图片 Markdown 片段]
     *         片段按 slide 序号（1-based）索引，供 slide 拆页后逐页追加。
     */
    public static function appendImages(string $markdown, array $images, bool $bySlide): array
    {
        if (empty($images)) {
            return [$markdown, []];
        }

        $perSlide = [];
        $all = [];

        foreach ($images as $img) {
            $md = '![](' . $img['url'] . ')';
            $all[] = $md;
            if ($bySlide && $img['slide'] !== null) {
                $perSlide[(int) $img['slide']][] = $md;
            }
        }

        if ($bySlide) {
            // 整篇不再追加（拆页后按页追加）
            return [$markdown, $perSlide];
        }

        $markdown = rtrim($markdown) . "\n\n" . implode("\n\n", $all) . "\n";
        return [$markdown, $perSlide];
    }

    // ------------------------------------------------------------------
    // 内部：解包
    // ------------------------------------------------------------------

    /**
     * 收集图片引用（按文档出现顺序）。
     *
     * @return array<int, array{entry: string, name: string, slide: ?int}>
     */
    private static function collectImages(string $filePath, string $ext): array
    {
        if (!class_exists('ZipArchive')) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        try {
            // 条目数上限（防解析超长目录浪费；也防后续循环被起大）
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                LogHelper::error('ImageReconciler: too many zip entries (' . $zip->numFiles . '), skip media', 'Api');
                return [];
            }
            // 解压后总体积上限（zip 炸弹防护）：按 statIndex 的解压后大小累计
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    continue;
                }
                $total += (int) $st['size'];
                if ($total > self::MAX_UNCOMPRESSED_TOTAL) {
                    LogHelper::error('ImageReconciler: uncompressed size exceeds limit, skip media', 'Api');
                    return [];
                }
            }

            if ($ext === 'pptx') {
                return self::collectFromPptx($zip);
            }
            if ($ext === 'docx') {
                return self::collectFromDocx($zip);
            }
            return [];
        } finally {
            $zip->close();
        }
    }

    /** docx：解析 word/document.xml 的 r:embed 顺序 */
    private static function collectFromDocx(\ZipArchive $zip): array
    {
        $doc = $zip->getFromName('word/document.xml');
        if ($doc === false) {
            return [];
        }

        $rels = self::parseRels($zip->getFromName('word/_rels/document.xml.rels'));
        if (empty($rels)) {
            return [];
        }

        $images = [];
        if (preg_match_all('/r:embed="(rId\d+)"/', $doc, $m)) {
            foreach ($m[1] as $rid) {
                $target = $rels[$rid] ?? null;
                if ($target && self::isImageTarget($target)) {
                    $entry = self::normalizeZipTarget('word/_rels/document.xml.rels', $target);
                    if ($entry !== null) {
                        $images[] = [
                            'entry' => $entry,
                            'name'  => basename($entry),
                            'slide' => null,
                        ];
                    }
                }
            }
        }

        return $images;
    }

    /** pptx：按幻灯片序号解析每页 slideN.xml 的 r:embed */
    private static function collectFromPptx(\ZipArchive $zip): array
    {
        $images = [];
        $slideNums = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slideNums[(int) $m[1]] = $name;
            }
        }
        ksort($slideNums);

        foreach ($slideNums as $slideNo => $slideXml) {
            $xml = $zip->getFromName($slideXml);
            if ($xml === false) {
                continue;
            }
            $rels = self::parseRels($zip->getFromName('ppt/slides/_rels/' . basename($slideXml) . '.rels'));
            if (empty($rels)) {
                continue;
            }

            // 收集该页 r:embed（保持出现顺序）
            if (preg_match_all('/r:embed="(rId\d+)"/', $xml, $m)) {
                $seen = [];
                $relsPath = 'ppt/slides/_rels/' . basename($slideXml) . '.rels';
                foreach ($m[1] as $rid) {
                    $target = $rels[$rid] ?? null;
                    if ($target && self::isImageTarget($target)) {
                        $entry = self::normalizeZipTarget($relsPath, $target);
                        if ($entry !== null && !in_array($entry, $seen, true)) {
                            $seen[] = $entry;
                            $images[] = [
                                'entry' => $entry,
                                'name'  => basename($entry),
                                'slide' => $slideNo,
                            ];
                        }
                    }
                }
            }
        }

        return $images;
    }

    /** 解析 .rels：rId => Target */
    private static function parseRels($relsXml): array
    {
        if ($relsXml === false || $relsXml === '') {
            return [];
        }
        $map = [];
        if (preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $map[$row[1]] = $row[2];
            }
        }
        return $map;
    }

    /** 只认常见图片扩展名（不含 SVG：SVG 可携带脚本，存在 XSS 风险） */
    private static function isImageTarget(string $target): bool
    {
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        return in_array($ext, self::REF_EXTS, true);
    }

    /** Target（相对路径，可能含 ../）归一化为 zip 内条目路径。
     * $relsPath 是 .rels 文件自身的 zip 路径，Target 相对于它所在目录 */
    private static function normalizeZipTarget(string $relsPath, string $target): ?string
    {
        $target = str_replace('\\', '/', $target);
        // .rels 位于 <dir>/_rels/xxx.rels，Target 相对于 <dir>
        $baseDir = dirname(dirname($relsPath));

        if (strpos($target, '/') === 0) {
            // 绝对目标（少见）：从 zip 根开始
            return ltrim($target, '/');
        }

        $parts = explode('/', $baseDir . '/' . $target);
        $real = [];
        foreach ($parts as $p) {
            if ($p === '..') {
                array_pop($real);
            } elseif ($p !== '.' && $p !== '') {
                $real[] = $p;
            }
        }
        $path = implode('/', $real);
        return $path !== '' ? $path : null;
    }

    // ------------------------------------------------------------------
    // 内部：上传
    // ------------------------------------------------------------------

    /** 从 zip 中导出一个 media 条目并走附件系统上传，返回 URL（失败 null） */
    private static function uploadMedia(\ZipArchive $zip, string $entry, int $uid, int $itemId, string $name): ?string
    {
        $content = $zip->getFromName($entry);

        if ($content === false || $content === '') {
            return null;
        }

        // 单个 media 文件解压后大小上限
        if (strlen($content) > self::MAX_MEDIA_SIZE) {
            return null;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTS, true)) {
            // emf/wmf/tiff 等浏览器不便展示的格式，以及 svg（可携带脚本）一并跳过
            return null;
        }

        // 魔数校验：防止伪造扩展名把非图片内容（如脚本、HTML）当图片上传
        [$magicHex, $magicLen] = self::MAGIC[$ext];
        if (strlen($content) < $magicLen || substr(bin2hex($content), 0, $magicLen * 2) !== $magicHex) {
            LogHelper::error('ImageReconciler: media magic mismatch (' . $ext . '), skip: ' . $entry, 'Api');
            return null;
        }

        // 扩展名修正（jpeg 统一展示为 jpg 不影响，保留原名）。
        // 注意：tempnam() 会先创建一个无扩展名空文件，追加扩展名后那份不再被
        // 引用——先删掉原文件，避免每次导一张图就在 /tmp 残留一个空文件
        $tmpBase = tempnam(sys_get_temp_dir(), 'anydoc_img_');
        if ($tmpBase === false) {
            return null;
        }
        $tmpFile = $tmpBase . '.' . $ext;
        if (file_put_contents($tmpFile, $content) === false) {
            @unlink($tmpBase);
            @unlink($tmpFile);
            return null;
        }
        @unlink($tmpBase);

        $fakeFiles = [
            'upload' => [
                'name'     => 'office-import-' . FileHelper::getRandStr(8) . '.' . $ext,
                'type'     => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
                'tmp_name' => $tmpFile,
                'size'     => strlen($content),
            ],
        ];

        try {
            // Attachment::upload 会按配置走 OSS 或本地上传并登记 upload_file 表
            $url = \App\Model\Attachment::upload($fakeFiles, 'upload', $uid, $itemId, 0, true);
        } catch (\Throwable $e) {
            LogHelper::exception($e, 'Api');
            $url = false;
        } finally {
            @unlink($tmpFile);
        }

        return $url ?: null;
    }
}
