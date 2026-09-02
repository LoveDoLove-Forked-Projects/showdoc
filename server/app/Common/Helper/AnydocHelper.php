<?php

namespace App\Common\Helper;

/**
 * anydoc 转换引擎封装。
 *
 * 只做一件事：安全地调用 anydoc 二进制（完整路径、超时 kill、
 * exit code 语义翻译），把 stdout 的 GFM Markdown 返回。
 * 不感知 ShowDoc 的页面/目录概念。
 *
 * 二进制部署位置见 server/bin/VERSION：
 *   - 生产镜像：/usr/local/bin/anydoc（Dockerfile COPY）
 *   - 开发容器：docker cp 到 /usr/local/bin/anydoc
 *   - 兜底：仓库内 server/bin/anydoc
 */
class AnydocHelper
{
    /** 单文件转换超时（秒），超时 kill 进程 */
    public const TIMEOUT = 20;

    /** stdout 上限（32MB）：源文件已限 50MB，正常转换输出远小于此；
     *  防异常输出（含 zip 炸弹类文档解压膨胀）撑爆内存 */
    public const MAX_OUTPUT = 32 * 1024 * 1024;

    /** exit code => 错误语义 */
    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;   // 读取/解析/转换失败
    public const EXIT_ARGS = 2;     // 参数错误
    public const EXIT_NEED_OCR = 3; // （保留）PDF 需 OCR。自编译二进制无 OCR，扫描件会以其他非零码失败

    /** @var string|null 解析出的二进制路径缓存 */
    private static ?string $binPath = null;

    /**
     * 探测 anydoc 二进制是否可用（存在且可执行）。
     * 仅用于导入时的兜底报错判断；前端不再探测，Office 导入为常驻内置功能。
     */
    public static function available(): bool
    {
        return self::resolveBin() !== null;
    }

    /**
     * 转换一个文档文件为 GFM Markdown。
     *
     * @param string $filePath 本地文件绝对路径
     * @return array{ok: bool, markdown: string, error: string}
     *   ok=false 时 error 为面向用户的中文错误信息。
     */
    public static function toMarkdown(string $filePath): array
    {
        $bin = self::resolveBin();
        if ($bin === null) {
            return ['ok' => false, 'markdown' => '', 'error' => '文档转换组件不可用（anydoc 未安装）'];
        }

        if (!is_file($filePath)) {
            return ['ok' => false, 'markdown' => '', 'error' => '文件不存在'];
        }

        // stdout/stderr 管道
        $descriptors = [
            0 => ['pipe', 'r'], // stdin（不用）
            1 => ['pipe', 'w'], // stdout -> markdown
            2 => ['pipe', 'w'], // stderr -> 错误信息
        ];

        // bypass_shell：直接 exec 二进制（路径固定 + escapeshellarg 参数，无 shell 语法）。
        // 若经 /bin/sh -c 启动，proc_terminate 只能杀到 sh，anydoc 本体会成孤儿继续跑，
        // 超时保护失效；直 exec 后 kill 目标即 anydoc 进程本身
        $cmd[0] = $bin;
        $cmd[1] = $filePath;

        $start = microtime(true);
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'markdown' => '', 'error' => '无法启动文档转换进程'];
        }

        fclose($pipes[0]);

        // 非阻塞读，循环检查超时
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $status = null;
        $timedOut = false;

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if (empty($read)) {
                break;
            }

            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 0, 200000); // 200ms
            if ($changed > 0) {
                foreach ($read as $r) {
                    $chunk = stream_get_contents($r);
                    if ($chunk === false) {
                        continue;
                    }
                    if ($r === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                    if (strlen($stdout) > self::MAX_OUTPUT) {
                        // 输出体积失控（如压缩炸弹型文档），立即放弃
                        $timedOut = true; // 复用超时的 kill/回收路径
                        @proc_terminate($proc, 9);
                        LogHelper::error('AnydocHelper output exceeds ' . self::MAX_OUTPUT . ' bytes, abort: ' . basename($filePath), 'Api');
                        break 2;
                    }
                }
            }

            // 检查进程是否已退出
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // 排干残留输出
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            // 超时 kill
            if ((microtime(true) - $start) > self::TIMEOUT) {
                $timedOut = true;
                // SIGKILL 整个进程组，防止孙进程残留
                @proc_terminate($proc, 9);
                break;
            }
        }

        // 确保进程回收（拿到最终退出码）
        $exitCode = -1;
        if (!$timedOut) {
            // 等待（此时进程应已退出，立即返回）
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
        } else {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        if ($timedOut) {
            LogHelper::error('AnydocHelper timeout after ' . self::TIMEOUT . 's: ' . basename($filePath), 'Api');
            return ['ok' => false, 'markdown' => '', 'error' => '转换超时（超过 ' . self::TIMEOUT . ' 秒）或输出异常，可尝试拆分文件后重试'];
        }

        $elapsed = round(microtime(true) - $start, 2);
        LogHelper::info("AnydocHelper convert {$filePath} exit={$exitCode} time={$elapsed}s", 'Api');

        if ($exitCode === self::EXIT_OK) {
            return ['ok' => true, 'markdown' => $stdout, 'error' => ''];
        }

        $errLine = trim($stderr);
        if ($exitCode === self::EXIT_NEED_OCR) {
            return ['ok' => false, 'markdown' => '', 'error' => '该 PDF 需要文字识别（扫描件），暂不支持，请上传文本型 PDF'];
        }

        // 扫描型 PDF 等无法解析的文件：结合 stderr 判断是否是 PDF 场景，给出更友好的提示
        if ($errLine !== '' && stripos($errLine, 'pdf') !== false) {
            return ['ok' => false, 'markdown' => '', 'error' => 'PDF 转换失败（可能为扫描件或加密 PDF），仅支持文本型 PDF'];
        }

        $detail = mb_substr($errLine, 0, 120);
        $msg = '文档转换失败';
        if ($detail !== '') {
            $msg .= '：' . $detail;
        }
        return ['ok' => false, 'markdown' => '', 'error' => $msg];
    }

    /**
     * 解析 anydoc 二进制路径。
     * 优先系统 PATH 中的 /usr/local/bin/anydoc（Docker 部署位置），
     * 其次仓库内 server/bin/anydoc，找不到返回 null。
     */
    private static function resolveBin(): ?string
    {
        if (self::$binPath !== null) {
            return self::$binPath;
        }

        $candidates = [
            '/usr/local/bin/anydoc',
            dirname(__DIR__, 3) . '/bin/anydoc', // server/bin/anydoc
        ];

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                self::$binPath = $path;
                return $path;
            }
        }

        self::$binPath = null; // 缓存未命中结果也缓存，避免每次 is_file
        return null;
    }
}
