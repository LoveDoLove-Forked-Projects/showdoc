<?php

namespace App\Common\Helper;

use App\Model\Item;
use App\Model\Page;

/**
 * Office 文档导入编排器。
 *
 * 流程（设计文档 §2）：
 *  1. 校验扩展名/魔数/大小/权限，创建临时目录
 *  2. 串行处理每个文件：AnydocHelper 转 Markdown → MarkdownSanitizer 净化
 *     → Splitter 拆页 → docx/pptx 调 ImageReconciler 补图 → 逐页写入（同名覆盖）
 *  3. 汇总成功/失败清单返回，清理临时文件
 *
 * 由 ImportController::auto() 对 Office 扩展名分发调用（officeImport()）。
 *
 * 开源版说明：Page 不分表（tableForItem 恒返回 page）、page_content 不压缩，
 * 以上差异均由 Page 层内部消化，本类与主版逻辑一致。
 */
class OfficeImporter
{
    /** 支持的扩展名 => 默认 split */
    public const SUPPORTED = [
        'doc'  => 'heading',
        'docx' => 'heading',
        'pdf'  => 'heading',
        'ppt'  => 'slide',
        'pptx' => 'slide',
        'xls'  => 'sheet',
        'xlsx' => 'sheet',
    ];

    /** 单文件大小上限 50MB */
    public const MAX_SIZE = 50 * 1024 * 1024;

    /** 页面/目录标题截断长度（page_title / cat_name 为 varchar(50)，
     *  MySQL 严格模式下超长直接报错导致写库失败） */
    public const TITLE_MAX = 50;

    /** 新建项目名截断长度（item_name 为 varchar(50)） */
    public const ITEM_NAME_MAX = 50;

    /** 扩展名 => 文件头魔数（十六进制）。上传文件必须命中真实格式，防伪造扩展名 */
    private const MAGIC = [
        'doc'  => [['d0cf11e0', 4]],                            // OLE2 复合文档（doc/xls/ppt 共用）
        'xls'  => [['d0cf11e0', 4]],
        'ppt'  => [['d0cf11e0', 4]],
        'docx' => [['504b0304', 4]],                            // ZIP（docx/xlsx/pptx 共用）
        'xlsx' => [['504b0304', 4]],
        'pptx' => [['504b0304', 4]],
        'pdf'  => [['25504446', 4]],                            // %PDF
    ];

    /** @var array<string> 处理过程中的提示（如 split 模式被降级）。静态属性：import() 为静态方法 */
    private static array $notices = [];

    /**
     * 导入入口。
     *
     * @param array $uploadedFiles Slim 上传文件数组（$_FILES 风格的 UploadedFile 对象数组，
     *                             支持 'file' 单文件；本次按单文件处理，多文件可多次调用）
     * @param int $uid 当前登录用户
     * @param string $username 用户名
     * @param int $itemId 目标项目（0 = 新建项目）
     * @param string $split 拆页模式 none|heading|sheet|slide（默认 heading）
     * @return array{error_code: int, error_message: string, data: array}
     */
    public static function import(array $uploadedFileObjects, int $uid, string $username, int $itemId, string $split = 'heading'): array
    {
        self::$notices = [];

        $file = $uploadedFileObjects['file'] ?? null;
        if (!$file) {
            return ['error_code' => 10101, 'error_message' => '请上传文件', 'data' => []];
        }

        /** @var \Slim\Psr7\UploadedFile $file */
        $filename = (string) $file->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!isset(self::SUPPORTED[$ext])) {
            return ['error_code' => 10101, 'error_message' => '不支持的 Office 文件格式（支持 doc/docx/ppt/pptx/xls/xlsx/pdf）', 'data' => []];
        }

        // 上传错误码检查（Slim UploadedFile）
        if (method_exists($file, 'getError') && $file->getError() !== UPLOAD_ERR_OK) {
            return ['error_code' => 10101, 'error_message' => '文件上传失败（code ' . $file->getError() . '）', 'data' => []];
        }

        // 大小校验（50MB，服务端强制；getSize 为 0/未知时落地后由 is_file 再兑底）
        $size = $file->getSize();
        if ($size && $size > self::MAX_SIZE) {
            return ['error_code' => 10101, 'error_message' => '单个文件不能超过 50MB', 'data' => []];
        }

        // 文件名长度限制（防超长名拖垮后续处理；含扩展名）
        if (strlen($filename) > 255) {
            return ['error_code' => 10101, 'error_message' => '文件名过长', 'data' => []];
        }

        // 兑底：二进制缺失时明确报错（anydoc 随镜像内置分发，正常部署不会走到这里）
        if (!AnydocHelper::available()) {
            return ['error_code' => 10101, 'error_message' => '文档转换组件缺失，请检查部署（anydoc 未安装）', 'data' => []];
        }

        // split 模式与格式匹配校验：不匹配时降级为 none 并提示
        $split = strtolower(trim($split));
        if (!in_array($split, ['none', 'heading', 'sheet', 'slide'], true)) {
            $split = 'heading';
        }
        if ($split !== 'none') {
            $valid = [
                'doc' => ['heading'], 'docx' => ['heading'], 'pdf' => ['heading'],
                'ppt' => ['slide'], 'pptx' => ['slide'],
                'xls' => ['sheet'], 'xlsx' => ['sheet'],
            ];
            if (!in_array($split, $valid[$ext], true)) {
                self::$notices[] = "拆页模式 {$split} 不适用于 {$ext} 文件，已按单页处理";
                $split = 'none';
            }
        }

        // 落地临时文件（保留原扩展名，anydoc 靠内容检测，扩展名仅兜底）
        $tmpDir = sys_get_temp_dir() . '/office_import_' . FileHelper::getRandStr(12);
        if (!mkdir($tmpDir, 0755, true)) {
            return ['error_code' => 10500, 'error_message' => '创建临时目录失败', 'data' => []];
        }

        $safeBase = FileHelper::sanitizeFilename(pathinfo($filename, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'import';
        }
        // 项目名长度限制（item_name varchar(50)，严格模式超长会导致创建项目失败）
        if (mb_strlen($safeBase) > self::ITEM_NAME_MAX) {
            $safeBase = mb_substr($safeBase, 0, self::ITEM_NAME_MAX);
        }
        $tmpFile = $tmpDir . '/' . FileHelper::getRandStr(8) . '.' . $ext;

        try {
            $file->moveTo($tmpFile);

            // 落地后二次校验：真实大小（不信请求头/表单）+ 魔数（防伪造扩展名/polyglot）
            clearstatcache(true, $tmpFile);
            $realSize = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;
            if ($realSize <= 0 || $realSize > self::MAX_SIZE) {
                return ['error_code' => 10101, 'error_message' => '文件为空或超过 50MB 上限', 'data' => ['notices' => self::$notices]];
            }
            if (!self::checkMagic($tmpFile, $ext)) {
                // 旧格式（OLE2）细分提示：WPS/低版本 Office 常把 doc/ppt/xls 实存为 RTF/HTML 或导出不完整，
                // 引导用户转存为新格式（anydoc 对 OOXML 的解析也受全得多）
                if (in_array($ext, ['doc', 'ppt', 'xls'], true)) {
                    $magicError = '文件内容与扩展名不符（或文件已损坏）。建议使用 Microsoft Office 或 WPS 打开该文件，另存为新格式（docx/pptx/xlsx）后再上传';
                } else {
                    $magicError = '文件内容与扩展名不符（或文件已损坏），请确认为有效的 ' . strtoupper($ext) . ' 文档';
                }
                return ['error_code' => 10101, 'error_message' => $magicError, 'data' => ['notices' => self::$notices]];
            }

            // 转换（全进程级互斥：anydoc 为同步 CPU 密集调用，防止并发导入叠满 CPU）
            $lock = @fopen(sys_get_temp_dir() . '/showdoc_anydoc_convert.lock', 'w');
            if ($lock === false) {
                return ['error_code' => 10500, 'error_message' => '服务器繁忙，请稍后重试', 'data' => ['notices' => self::$notices]];
            }
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            try {
                if (!$locked) {
                    return ['error_code' => 10101, 'error_message' => '当前有其他文档正在转换，请稍后重试', 'data' => ['notices' => self::$notices]];
                }
                $conv = AnydocHelper::toMarkdown($tmpFile);
            } finally {
                if ($locked) {
                    @flock($lock, LOCK_UN);
                }
                @fclose($lock);
            }
            if (!$conv['ok']) {
                return [
                    'error_code' => 10101,
                    'error_message' => $conv['error'],
                    'data' => ['notices' => self::$notices],
                ];
            }

            $markdown = $conv['markdown'];
            if (trim($markdown) === '') {
                return [
                    'error_code' => 10101,
                    'error_message' => '文档内容为空或无法提取文本',
                    'data' => ['notices' => self::$notices],
                ];
            }

            // XSS 净化：anydoc 会原样保留文档正文中的 HTML 片段，
            // <script>/<img onerror>/javascript: 链接必须在落库前处理（存储型 XSS 防护）
            $markdown = MarkdownSanitizer::sanitize($markdown);

            // 新建项目
            $newItem = false;
            if ($itemId <= 0) {
                $itemData = [
                    'item_name'        => $safeBase,
                    'item_domain'      => '',
                    'item_type'        => 1,
                    'item_description' => '',
                    'password'         => FileHelper::getRandStr(),
                    'uid'              => $uid,
                    'username'         => $username,
                    'addtime'          => time(),
                ];
                $itemId = Item::add($itemData);
                if ($itemId <= 0) {
                    return ['error_code' => 10500, 'error_message' => '创建项目失败', 'data' => []];
                }
                $newItem = true;
            }

            // 图片补齐（docx/pptx）；上传失败（如存储不可用）降级为无图导入
            $images = [];
            $imageTotal = 0;
            if (in_array($ext, ['docx', 'pptx'], true)) {
                try {
                    $extracted = ImageReconciler::extractImages($tmpFile, $ext, $uid, $itemId);
                    $images = $extracted['images'];
                    $imageTotal = $extracted['total'];
                    if (count($images) < $imageTotal) {
                        self::$notices[] = '有 ' . ($imageTotal - count($images)) . ' 张图片上传失败（附件存储不可用），已跳过';
                    }
                } catch (\Throwable $e) {
                    LogHelper::exception($e, 'Api');
                    self::$notices[] = '图片提取失败，已跳过文档内图片';
                }
            }

            // slide 拆页模式：图片按页归属；其余模式追加文末
            $bySlide = ($split === 'slide');
            $perSlide = [];
            if (!empty($images)) {
                [$markdown, $perSlide] = ImageReconciler::appendImages($markdown, $images, $bySlide);
            }

            // 拆页
            $pages = Splitter::split($markdown, $split);
            if (empty($pages)) {
                return ['error_code' => 10101, 'error_message' => '未解析出任何页面内容', 'data' => ['notices' => self::$notices]];
            }

            // slide 模式下把每页图片补到对应页
            if ($bySlide && !empty($perSlide)) {
                $pages = self::applySlideImages($pages, $perSlide);
            }

            // 逐页写入（同名覆盖；重复导入内容未变化时 updateByTitle 因 affected=0 返回 false，
            // 需回查确认页面已存在，视为成功（幂等））
            $sNumber = 99;
            $successCount = 0;
            $slideIndex = 0;
            foreach ($pages as $page) {
                $slideIndex++;
                $title = $page['title'];
                if ($title === '') {
                    $title = $safeBase; // none 模式 / 无标题文档：以文件名作页名
                }
                $title = self::clipTitle($title); // page_title varchar(50)，严格模式超长直接报错
                $catName = ($page['cat'] !== '') ? self::clipTitle($page['cat']) : '';
                $pageId = Page::updateByTitle(
                    $itemId,
                    $title,
                    $page['content'],
                    $catName,
                    $sNumber,
                    $uid,
                    $username
                );
                if ($pageId) {
                    $successCount++;
                } elseif (self::pageExists($itemId, $title, $catName)) {
                    // 内容未变化的重导入：页面已在，计成功
                    $successCount++;
                }
            }

            if ($successCount === 0) {
                return ['error_code' => 10101, 'error_message' => '页面写入失败', 'data' => ['notices' => self::$notices]];
            }

            Item::deleteCache($itemId);

            return [
                'error_code' => 0,
                'error_message' => '',
                'data' => [
                    'item_id'     => $itemId,
                    'new_item'    => $newItem,
                    'page_count'  => $successCount,
                    'image_count' => count($images),
                    'split'       => $split,
                    'notices'     => self::$notices,
                ],
            ];
        } finally {
            // 临时文件清理
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            if (is_dir($tmpDir)) {
                FileHelper::clearRuntime($tmpDir);
                @rmdir($tmpDir);
            }
        }
    }

    /** slide 模式：把图片片段按幻灯片序号（1-based）补到对应页 */
    private static function applySlideImages(array $pages, array $perSlide): array
    {
        foreach ($pages as $i => $page) {
            $slideNo = $i + 1;
            if (!empty($perSlide[$slideNo])) {
                $pages[$i]['content'] = rtrim($page['content']) . "\n\n" . implode("\n\n", $perSlide[$slideNo]);
            }
        }
        return $pages;
    }

    /** 校验文件头魔数（十六进制前缀匹配）。规则见 self::MAGIC */
    private static function checkMagic(string $path, string $ext): bool
    {
        $rules = self::MAGIC[$ext] ?? [];
        if (empty($rules)) {
            return true;
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, 8);
        fclose($fh);
        $hex = bin2hex($head);
        foreach ($rules as [$prefixHex, $len]) {
            if (strlen($hex) >= $len * 2 && strncmp($hex, $prefixHex, $len * 2) === 0) {
                return true;
            }
        }
        return false;
    }

    /** 标题截断：多字节安全 + 硬上限兑底（page_title/cat_name varchar(50)） */
    private static function clipTitle(string $title): string
    {
        if (mb_strlen($title) > self::TITLE_MAX) {
            $title = mb_substr($title, 0, self::TITLE_MAX);
        }
        // 兑底：极端情况（超宽字符组合等）按字节再截一次
        if (strlen($title) > self::TITLE_MAX * 4) {
            $title = mb_substr($title, 0, self::TITLE_MAX);
        }
        return $title;
    }

    /** 检查项目内某目录下是否已存在同名页面（与 updateByTitle 的查找口径一致） */
    private static function pageExists(int $itemId, string $title, string $catName): bool
    {
        $catId = 0;
        if ($catName !== '') {
            $cat = \App\Model\Catalog::saveCatPath($catName, $itemId);
            if ($cat === false) {
                return false;
            }
            $catId = (int) $cat;
        }

        $table = Page::tableForItem($itemId);
        $existing = \Illuminate\Database\Capsule\Manager::table($table)
            ->where('item_id', $itemId)
            ->where('is_del', 0)
            ->where('cat_id', $catId)
            ->where('page_title', htmlspecialchars(htmlspecialchars_decode($title)))
            ->first();

        return $existing !== null;
    }
}
