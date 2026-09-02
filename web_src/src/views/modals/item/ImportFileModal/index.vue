<template>
  <div class="import-file-modal">
    <CommonModal
      :class="{ show }"
      :title="$t('item.import_file')"
      :icon="['fa', 'fa-upload']"
      @close="handleClose"
    >
      <div class="modal-content">
        <p class="tips">
          <span class="tips-text" v-html="tipsText"></span>
        </p>
        <div class="upload-area">
          <a-upload
            class="upload-demo"
            :before-upload="beforeUpload"
            :show-upload-list="false"
            :disabled="loading"
            drag
          >
            <div class="upload-content" :class="{ 'is-loading': loading }">
              <i class="fas fa-cloud-upload-alt"></i>
              <div class="upload-text">
                <span v-html="$t('item.import_file_tips2')"></span>
              </div>
              <!-- Loading覆盖层 -->
              <div v-if="loading" class="loading-overlay">
                <i class="fas fa-spinner fa-spin"></i>
                <span>{{ $t('common.uploading') || '文件上传和导入中，请稍候...' }}</span>
              </div>
            </div>
          </a-upload>
        </div>

        <!-- 已选文件确认面板（zip/Office 均先暂存，确认后手动上传） -->
        <div v-if="pendingFile" class="office-panel">
          <div class="office-file-row">
            <i class="fas fa-file-alt"></i>
            <span class="office-file-name" :title="pendingFile.name">{{ pendingFile.name }}</span>
            <span class="office-file-size">({{ formatSize(pendingFile.size) }})</span>
            <i class="fas fa-times office-file-remove" @click="cancelPendingFile"></i>
          </div>
          <template v-if="splitOptions.length > 0">
            <div class="office-split-row">{{ $t('item.import_split_mode') }}</div>
            <a-radio-group v-model:value="splitMode" class="office-split-group">
              <a-radio v-for="opt in splitOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </a-radio>
            </a-radio-group>
            <div v-if="props.itemId && props.itemId > 0" class="office-overwrite-tips">
              {{ $t('item.import_into_cur_item_tips') }}
            </div>
          </template>
          <div class="office-submit-row">
            <div class="primary-button office-submit-btn" @click="confirmUpload">
              {{ $t('item.import_begin') }}
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <div class="secondary-button" @click="() => handleClose(false)">{{ $t('common.cancel') }}</div>
      </div>
    </CommonModal>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import CommonModal from '@/components/CommonModal.vue'
import Message from '@/components/Message'
import AlertModal from '@/components/AlertModal'
import request from '@/utils/request'

const { t } = useI18n()
const router = useRouter()

const props = defineProps<{
  onClose: (result: boolean) => void
  itemId?: number  // 项目ID，用于导入到指定项目
}>()

const show = ref(false)
const loading = ref(false)

// Office 导入为常驻内置功能（anydoc 随镜像分发），无需能力探测。
// 单文件上限与后端 OfficeImporter::MAX_SIZE（50MB）保持一致。
const OFFICE_MAX_SIZE = 50 * 1024 * 1024

// Office 扩展名 => 拆页方式池
const OFFICE_EXTS: Record<string, string[]> = {
  doc: ['heading', 'none'],
  docx: ['heading', 'none'],
  pdf: ['heading', 'none'],
  xls: ['sheet', 'none'],
  xlsx: ['sheet', 'none'],
  ppt: ['slide', 'none'],
  pptx: ['slide', 'none'],
}

const SPLIT_LABELS: Record<string, string> = {
  heading: 'item.import_split_heading',
  sheet: 'item.import_split_sheet',
  slide: 'item.import_split_slide',
  none: 'item.import_split_none',
}

// 选中的待上传文件（zip/json 立即上传；Office 等待选拆页方式后手动上传）
const pendingFile = ref<File | null>(null)
const splitMode = ref('heading')

// 提示文案：常驻补充 Office 支持的格式说明
const tipsText = computed(() => {
  return `${t('item.import_file_tips1')}${t('item.import_file_tips1_office')}`
})

// 当前文件可选的拆页方式
const splitOptions = computed(() => {
  if (!pendingFile.value) return []
  const ext = getExt(pendingFile.value.name)
  const modes = OFFICE_EXTS[ext] || []
  return modes.map((m) => ({ value: m, label: t(SPLIT_LABELS[m]) }))
})

const getExt = (name: string) => name.slice(name.lastIndexOf('.') + 1).toLowerCase()

// 文件大小格式化
const formatSize = (size: number) => {
  if (size >= 1024 * 1024) return (size / 1024 / 1024).toFixed(1) + ' MB'
  return Math.max(1, Math.round(size / 1024)) + ' KB'
}

// 上传前校验：一律拦截自动上传，暂存待确认（zip/json 无拆页选项直接上传）
const beforeUpload = (file: File) => {
  const ext = getExt(file.name)

  if (ext === 'zip' || ext === 'json') {
    // zip/json：无拆页选项，暂存后直接上传（自动弹出确认面板）
    pendingFile.value = file
    confirmUpload()
    return false
  }

  if (OFFICE_EXTS[ext]) {
    // Office 文件：前端拦截，待用户选择拆页方式后手动上传
    if (file.size > OFFICE_MAX_SIZE) {
      AlertModal(t('item.import_file_too_large', { size: formatSize(OFFICE_MAX_SIZE) }))
      return false
    }
    pendingFile.value = file
    // 按扩展名联动默认拆页方式（heading 为文档类默认；sheet/slide 为其类型默认）
    splitMode.value = OFFICE_EXTS[ext][0]
    return false // 阻止 a-upload 自动上传
  }

  AlertModal(t('item.import_file_unsupported'))
  return false
}

// 取消待上传的文件
const cancelPendingFile = () => {
  pendingFile.value = null
}

// 开始上传（zip/json/Office 统一走这里，经项目 request 封装携带 token；
// FormData 分支自动 5 分钟超时，与后端 Office 转换耗时匹配）
const startUpload = async () => {
  if (!pendingFile.value || loading.value) return
  const file = pendingFile.value
  loading.value = true

  const formData = new FormData()
  formData.append('file', file)
  const ext = getExt(file.name)
  if (OFFICE_EXTS[ext]) {
    formData.append('split', splitMode.value)
  }
  if (props.itemId && props.itemId > 0) {
    formData.append('item_id', String(props.itemId))
  }

  try {
    // msgAlert=false：错误弹窗由 handleImportResponse 统一展示（含 notices）
    const response = await request('/api/import/auto', formData, 'post', false)
    handleImportResponse(response)
  } catch (err: any) {
    loading.value = false
    AlertModal(err?.message || t('common.op_failed'))
  }
}

// 上传开始（模态框确认按钮）
const confirmUpload = () => {
  startUpload()
}

// 统一处理导入接口响应
const handleImportResponse = (response: any) => {
  if (response && response.error_code === 0) {
    const data = response.data || {}
    const noticeText = (data.notices || []).join('\n')
    Message.success(
      noticeText ? `${t('common.op_success')}\n${noticeText}` : t('common.op_success')
    )
    loading.value = false
    pendingFile.value = null
    handleClose(true, data)
  } else {
    loading.value = false
    const data = response?.data
    const notices = data && Array.isArray(data.notices) ? data.notices.join('\n') : ''
    AlertModal(
      (response?.error_message || t('common.op_failed')) + (notices ? `\n${notices}` : '')
    )
  }
}

const handleClose = (result: boolean = false, data?: any) => {
  show.value = false
  // 清理残留的待上传文件（组件若被复用，避免重开时残留上次面板）
  pendingFile.value = null
  setTimeout(() => {
    props.onClose(result)

    // 导入成功后跳转
    if (result) {
      if (props.itemId && props.itemId > 0) {
        // 导入到已有项目：刷新页面即可看到新页面
        window.location.reload()
      } else {
        // 新建项目导入：后端返回 item_id 时直达项目页，否则回项目列表
        const newItemId = data?.item_id || data?.new_item_id
        if (newItemId) {
          const target = router.resolve({ name: 'ItemShow', params: { item_id: String(newItemId) } })
          if (target.fullPath !== router.currentRoute.value?.fullPath) {
            window.location.href = target.fullPath
          } else {
            window.location.reload()
          }
        } else {
          // 兼容 zip/json 旧返回：跳转项目列表页
          router.push({ path: '/item/index' })
        }
      }
    }
  }, 300)
}

onMounted(() => {
  setTimeout(() => {
    show.value = true
  })
})
</script>

<style scoped lang="scss">
.modal-content {
  width: 450px;
  padding: 30px 40px;
  border-bottom: 1px solid var(--color-interval);
}

.tips {
  color: var(--color-text-primary);
  margin-bottom: 20px;
  line-height: 1.6;
}

.tips-text :deep(b) {
  font-weight: 600;
  color: var(--color-primary);
}

.tips-text :deep(em) {
  font-style: italic;
}

.upload-area {
  margin: 20px 0;
  display: flex;
  justify-content: center;
}

.upload-demo {
  width: 100%;
  max-width: 100%;

  :deep(.ant-upload) {
    width: 100%;
    display: block;
  }

  :deep(.ant-upload-drag) {
    width: 100%;
    display: block;
  }
}

.upload-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  border: 2px dashed var(--color-border);
  border-radius: 8px;
  background-color: var(--color-bg-secondary);
  transition: all 0.15s ease;
  position: relative;
  min-height: 180px;

  &:hover {
    border-color: var(--color-primary);
    background-color: var(--color-bg-primary);
  }

  &.is-loading {
    pointer-events: none;
    opacity: 0.7;

    .fas,
    .upload-text {
      opacity: 0.3;
    }
  }

  .fas {
    font-size: 48px;
    color: var(--color-text-secondary);
    margin-bottom: 12px;
  }

  .upload-text {
    color: var(--color-text-primary);
    text-align: center;
    line-height: 1.6;

    :deep(em) {
      color: var(--color-primary);
      font-style: italic;
    }
  }

  .loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: rgba(255, 255, 255, 0.9);
    border-radius: 8px;
    z-index: 10;
    gap: 12px;

    i {
      font-size: 32px;
      color: var(--color-primary);
      animation: spin 1s linear infinite;
    }

    span {
      font-size: 14px;
      color: var(--color-text-primary);
      font-weight: 500;
    }
  }
}

// Office 拆页选项面板
.office-panel {
  margin-top: 16px;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background-color: var(--color-bg-secondary);
}

.office-file-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  color: var(--color-text-primary);
  font-size: 14px;

  > .fas:first-child {
    color: var(--color-primary);
  }
}

.office-file-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
}

.office-file-size {
  color: var(--color-text-secondary);
  flex-shrink: 0;
}

.office-file-remove {
  cursor: pointer;
  color: var(--color-text-secondary);
  flex-shrink: 0;
  padding: 2px 4px;

  &:hover {
    color: var(--color-danger, #e54545);
  }
}

.office-split-row {
  color: var(--color-text-primary);
  font-size: 14px;
  margin-bottom: 8px;
}

.office-split-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.office-overwrite-tips {
  margin-top: 10px;
  font-size: 12px;
  color: var(--color-text-secondary);
  line-height: 1.5;
}

.office-submit-row {
  display: flex;
  justify-content: center;
  margin-top: 16px;
}

.office-submit-btn {
  width: 200px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 90px;

  .secondary-button {
    width: 160px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: var(--font-size-m);
    font-weight: bold;
    cursor: pointer;
    background-color: var(--color-obvious);
    color: var(--color-primary);
    white-space: nowrap;
    margin: 0 7.5px;

    &:hover {
      background-color: var(--color-secondary);
    }
  }
}

// 暗黑主题适配
[data-theme='dark'] .tips {
  color: var(--color-text-primary);
}

[data-theme='dark'] .upload-content {
  background-color: var(--color-bg-secondary);

  &:hover {
    background-color: var(--color-bg-primary);
  }
}

[data-theme='dark'] .office-panel {
  background-color: var(--color-bg-primary);
}

// 旋转动画
@keyframes spin {
  from {
    transform: rotate(0deg);
  }

  to {
    transform: rotate(360deg);
  }
}
</style>
