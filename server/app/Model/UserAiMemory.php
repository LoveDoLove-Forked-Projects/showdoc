<?php

namespace App\Model;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * AI Agent 用户长期记忆 Model
 *
 * 用户级纯全局记忆（无类型、无项目维度），每条为纯文本。
 * 写入 = 新增（内容级去重：uid + content 完全一致则跳过）；
 * 每用户最多 MAX_COUNT 条，超出时淘汰最旧（updated_at 最早）一条再插入。
 */
class UserAiMemory
{
  const TABLE = 'user_ai_memory';

  /** 单用户最大记忆条数 */
  const MAX_COUNT = 50;

  /** 单条记忆最大字数 */
  const MAX_CONTENT_LENGTH = 1000;

  /**
   * 获取用户全部记忆（按 updated_at 倒序）
   *
   * @param int $uid   用户ID
   * @param int $limit 最大条数
   * @return array
   */
  public static function getMemories(int $uid, int $limit = 50): array
  {
    if ($uid <= 0) {
      return [];
    }

    $query = DB::table(self::TABLE)
      ->where('uid', $uid)
      ->orderBy('updated_at', 'desc')
      ->limit($limit)
      ->get();

    return array_map(function ($row) {
      return (array) $row;
    }, $query->all());
  }

  /**
   * 获取用户全部记忆（getMemories 的别名）
   *
   * @param int $uid   用户ID
   * @param int $limit 最大条数
   * @return array
   */
  public static function getAllForUser(int $uid, int $limit = 50): array
  {
    return self::getMemories($uid, $limit);
  }

  /**
   * 获取用于 system prompt 注入的记忆（带条数/字符上限）
   *
   * @param int $uid      用户ID
   * @param int $maxCount 最大条数
   * @param int $maxChars 总字符上限
   * @return array 每项 ['id' => int, 'content' => string]
   */
  public static function getMemoriesForPrompt(int $uid, int $maxCount = 20, int $maxChars = 4000): array
  {
    if ($uid <= 0) {
      return [];
    }

    $rows = self::getMemories($uid, self::MAX_COUNT);
    if (empty($rows)) {
      return [];
    }

    $result = [];
    $chars  = 0;
    foreach ($rows as $r) {
      if (count($result) >= $maxCount) {
        break;
      }
      $content = self::sanitizeContent((string) $r['content']);
      if ($content === '') {
        continue;
      }
      if ($result !== [] && $chars + mb_strlen($content) > $maxChars) {
        break;
      }
      $chars += mb_strlen($content);
      $result[] = [
        'id'      => (int) $r['id'],
        'content' => $content,
      ];
    }

    return $result;
  }

  /**
   * 新增一条记忆（append，内容级去重 + 容量淘汰）
   *
   * - 去重：uid + content 完全一致 → 跳过，不重复存储
   * - 容量：已有条数 >= MAX_COUNT 时，先淘汰最旧（updated_at 最早）一条再插入
   *
   * @param int    $uid     用户ID
   * @param string $content 记忆内容
   * @return array ['ok' => bool, 'action' => 'created'|'duplicate'|'failed', 'id' => int, 'message' => string]
   */
  public static function addMemory(int $uid, string $content): array
  {
    if ($uid <= 0) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => 'uid 无效'];
    }

    $content = self::sanitizeContent($content);

    if ($content === '') {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '内容不能为空'];
    }
    if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '单条记忆最多 ' . self::MAX_CONTENT_LENGTH . ' 字'];
    }

    // 内容级去重：uid + content 完全一致则跳过
    $existing = DB::table(self::TABLE)
      ->where('uid', $uid)
      ->where('content', $content)
      ->first();
    if ($existing) {
      return ['ok' => true, 'action' => 'duplicate', 'id' => (int) $existing->id, 'message' => '相同内容的记忆已存在'];
    }

    // 容量控制：满 50 条时淘汰最旧一条再插入
    if (self::countForUser($uid) >= self::MAX_COUNT) {
      $oldest = DB::table(self::TABLE)
        ->where('uid', $uid)
        ->orderBy('updated_at', 'asc')
        ->orderBy('id', 'asc')
        ->first();
      if ($oldest) {
        DB::table(self::TABLE)
          ->where('id', (int) $oldest->id)
          ->where('uid', $uid)
          ->delete();
      }
    }

    // 并发兜底：唯一键 (uid, content) 冲突时视为已存在
    try {
      $id = DB::table(self::TABLE)->insertGetId([
        'uid'     => $uid,
        'content' => $content,
      ]);
      return ['ok' => true, 'action' => 'created', 'id' => (int) $id, 'message' => '记忆已保存'];
    } catch (\Throwable $e) {
      $existing = DB::table(self::TABLE)
        ->where('uid', $uid)
        ->where('content', $content)
        ->first();
      if ($existing) {
        return ['ok' => true, 'action' => 'duplicate', 'id' => (int) $existing->id, 'message' => '相同内容的记忆已存在'];
      }
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '保存失败，请稍后重试'];
    }
  }

  /**
   * 按 ID 更新指定记忆内容（只改这一条，不动其他条目）
   *
   * @param int    $uid      用户ID
   * @param int    $memoryId 记忆ID
   * @param string $content  新内容
   * @return array ['ok' => bool, 'action' => 'updated'|'failed', 'id' => int, 'message' => string]
   */
  public static function updateMemory(int $uid, int $memoryId, string $content): array
  {
    if ($uid <= 0) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => 'uid 无效'];
    }

    $content = self::sanitizeContent($content);

    if ($memoryId <= 0) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => 'memory_id 无效'];
    }
    if ($content === '') {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '内容不能为空'];
    }
    if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '单条记忆最多 ' . self::MAX_CONTENT_LENGTH . ' 字'];
    }

    $existing = DB::table(self::TABLE)
      ->where('uid', $uid)
      ->where('id', $memoryId)
      ->first();
    if (!$existing) {
      return ['ok' => false, 'action' => 'failed', 'id' => 0, 'message' => '记忆不存在或已删除'];
    }

    DB::table(self::TABLE)
      ->where('id', $memoryId)
      ->where('uid', $uid)
      ->update(['content' => $content]);

    return ['ok' => true, 'action' => 'updated', 'id' => $memoryId, 'message' => '记忆已更新'];
  }

  /**
   * 删除记忆（按 id 删，严格限定当前用户）
   *
   * @param int $uid      用户ID
   * @param int $memoryId 记忆ID
   * @return int 删除条数
   */
  public static function delete(int $uid, int $memoryId): int
  {
    if ($uid <= 0 || $memoryId <= 0) {
      return 0;
    }

    return DB::table(self::TABLE)
      ->where('uid', $uid)
      ->where('id', $memoryId)
      ->delete();
  }

  /**
   * 清空用户全部记忆
   *
   * @param int $uid 用户ID
   * @return int 删除条数
   */
  public static function deleteAllForUser(int $uid): int
  {
    if ($uid <= 0) {
      return 0;
    }
    return DB::table(self::TABLE)->where('uid', $uid)->delete();
  }

  /**
   * 统计用户记忆条数
   *
   * @param int $uid 用户ID
   * @return int
   */
  public static function countForUser(int $uid): int
  {
    if ($uid <= 0) {
      return 0;
    }
    return (int) DB::table(self::TABLE)->where('uid', $uid)->count();
  }

  /**
   * 消毒记忆内容（去除空字节、控制字符，修剪空白）
   *
   * @param string $content
   * @return string
   */
  public static function sanitizeContent(string $content): string
  {
    // 去除空字节与控制字符（保留换行 \n）
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
    return trim((string) $content);
  }
}
