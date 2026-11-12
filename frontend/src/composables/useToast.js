import { reactive } from 'vue'

export const toast = reactive({ msg: '', kind: 'info', _t: null })

export function showToast(msg, kind = 'info', ms = 3200) {
  clearTimeout(toast._t)
  toast.msg = msg
  toast.kind = kind
  toast._t = setTimeout(() => (toast.msg = ''), ms)
}
