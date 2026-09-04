<?php

namespace App\Mcp\Handler;

use App\Mcp\McpHandler;
use App\Mcp\McpError;
use App\Mcp\McpException;
use App\Model\UserAiMemory;

/**
 * MCP 用户记忆操作 Handler
 *
 * 用户级长期记忆的读写删（纯全局记忆，无类型、无项目级作用域）。
 * 严格按当前登录用户 uid 隔离，游客（uid<=0 或 auth_type=guest）一律禁止。
 *
 * 工具职责：
 * - memory_add    追加一条新记忆（内容级去重，容量满自动淘汰最旧）
 * - memory_update 按 id 覆盖更新某一条内容
 * - memory_read   列出全部记忆（带 id）
 * - memory_delete 按 id 删除
 */
class MemoryHandler extends McpHandler
{
  /**
   * 获取支持的操作列表
   *
   * @return array
   */
  public function getSupportedOperations(): array
  {
    return [
      'memory_read',
      'memory_add',
      'memory_update',
      'memory_delete',
    ];
  }

  /**
   * 执行操作
   *
   * @param string $operation 操作名称
   * @param array $params 参数
   * @return mixed
   * @throws McpException
   */
  public function execute(string $operation, array $params = [])
  {
    switch ($operation) {
      case 'memory_read':
        return $this->readMemory($params);

      case 'memory_add':
        return $this->addMemory($params);

      case 'memory_update':
        return $this->updateMemory($params);

      case 'memory_delete':
        return $this->deleteMemory($params);

      default:
        McpError::throw(McpError::METHOD_NOT_FOUND, "操作不存在: {$operation}");
    }
  }

  /**
   * 记忆访问前置校验：必须为已登录非游客用户
   *
   * @return int 用户 uid
   * @throws McpException
   */
  private function requireRealUser(): int
  {
    // 游客一律禁止（auth_type=guest 或 uid<=0）
    if (($this->tokenInfo['auth_type'] ?? '') === 'guest' || $this->getUid() <= 0) {
      McpError::throw(McpError::TOKEN_OPERATION_DENIED, '游客不能使用记忆功能，请登录后使用');
    }
    return $this->getUid();
  }

  /**
   * 记忆写操作权限校验：token 必须具备 write 级能力
   *
   * login user_token / 系统管理员固定为 write|admin；guest 已被 requireRealUser 拦截；
   * ai_token 的 permission=read 为只读，禁止通过 memory_add/update/delete 持久注入记忆。
   *
   * @return void
   * @throws McpException
   */
  private function requireWriteAccess(): void
  {
    if (!$this->canWrite()) {
      McpError::throw(McpError::TOKEN_OPERATION_DENIED, 'Token 权限不足：只读 Token 不能执行记忆写入操作，请使用具备写入权限的 Token');
    }
  }

  /**
   * 读取当前用户全部记忆
   *
   * @param array $params 参数
   * @return array
   * @throws McpException
   */
  private function readMemory(array $params): array
  {
    $uid = $this->requireRealUser();

    try {
      $memories = UserAiMemory::getMemories($uid);
    } catch (\Throwable $e) {
      error_log('[MemoryHandler] memory_read failed for uid=' . $uid . ': ' . $e->getMessage());
      McpError::throw(McpError::OPERATION_FAILED, '读取记忆失败，请稍后重试');
    }

    $list = [];
    foreach ($memories as $m) {
      $updatedAt = $m['updated_at'] ?? null;
      $list[] = [
        'memory_id'  => (int) $m['id'],
        'content'    => (string) $m['content'],
        'updated_at' => ($updatedAt instanceof \DateTimeInterface)
          ? $updatedAt->format('Y-m-d H:i:s')
          : (string) $updatedAt,
      ];
    }

    return [
      'count'    => count($list),
      'memories' => $list,
      'message'  => $list === []
        ? '暂无记忆'
        : '共 ' . count($list) . ' 条记忆',
    ];
  }

  /**
   * 追加一条新记忆（内容级去重：uid+content 一致则提示已存在）
   *
   * @param array $params 参数
   * @return array
   * @throws McpException
   */
  private function addMemory(array $params): array
  {
    $uid = $this->requireRealUser();
    $this->requireWriteAccess();

    $content = trim((string) ($params['content'] ?? ''));

    if ($content === '') {
      McpError::throw(McpError::INVALID_PARAMS, 'content 不能为空');
    }

    $result = UserAiMemory::addMemory($uid, $content);

    if (!$result['ok']) {
      McpError::throw(McpError::OPERATION_FAILED, $result['message']);
    }

    return [
      'memory_id' => $result['id'],
      'action'    => $result['action'],
      'message'   => $result['message'],
    ];
  }

  /**
   * 按 id 覆盖更新某一条记忆内容
   *
   * @param array $params 参数
   * @return array
   * @throws McpException
   */
  private function updateMemory(array $params): array
  {
    $uid = $this->requireRealUser();
    $this->requireWriteAccess();

    $memoryId = (int) ($params['memory_id'] ?? 0);
    $content  = trim((string) ($params['content'] ?? ''));

    if ($memoryId <= 0) {
      McpError::throw(McpError::INVALID_PARAMS, 'memory_id 不能为空（可先 memory_read 查看）');
    }
    if ($content === '') {
      McpError::throw(McpError::INVALID_PARAMS, 'content 不能为空');
    }

    $result = UserAiMemory::updateMemory($uid, $memoryId, $content);

    if (!$result['ok']) {
      McpError::throw(McpError::OPERATION_FAILED, $result['message'] . '。可先调用 memory_read 查看已有记忆及 ID');
    }

    return [
      'memory_id' => $result['id'],
      'action'    => $result['action'],
      'message'   => $result['message'],
    ];
  }

  /**
   * 按 id 删除记忆
   *
   * @param array $params 参数
   * @return array
   * @throws McpException
   */
  private function deleteMemory(array $params): array
  {
    $uid = $this->requireRealUser();
    $this->requireWriteAccess();

    $memoryId = (int) ($params['memory_id'] ?? 0);

    if ($memoryId <= 0) {
      McpError::throw(McpError::INVALID_PARAMS, '需提供 memory_id（可先 memory_read 查看已有记忆及 ID）');
    }

    $deleted = UserAiMemory::delete($uid, $memoryId);

    return [
      'deleted' => $deleted,
      'message' => $deleted > 0 ? '已删除 1 条记忆' : '未找到匹配的记忆',
    ];
  }
}
