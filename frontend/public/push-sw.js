/* Penangan Web Push; dimuat ke service worker Workbox lewat importScripts. */
self.addEventListener('push', (event) => {
  let d = {}
  try { d = event.data ? event.data.json() : {} } catch { d = { body: event.data && event.data.text() } }
  // Angka di ikon aplikasi (Badging API): total yang menunggu keputusan, diisi walau aplikasi tertutup.
  const setBadge = async () => {
    try {
      if (typeof d.badge === 'number' && self.navigator && 'setAppBadge' in self.navigator) {
        if (d.badge > 0) await self.navigator.setAppBadge(d.badge)
        else await self.navigator.clearAppBadge()
      }
    } catch { /* tidak didukung perangkat ini */ }
  }
  event.waitUntil(Promise.all([setBadge(),
    self.registration.showNotification(d.title || 'ORCA X Mobile', {
      body: d.body || '',
      tag: d.tag || undefined,     // tag sama = notifikasi menggantikan yang lama (tidak menumpuk)
      renotify: !!d.tag,
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: { url: d.url || '/' },
    })]),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const w of wins) {
      if (new URL(w.url).origin === self.location.origin) {
        await w.focus()
        // Aplikasi sudah terbuka: minta router pindah halaman tanpa memuat ulang.
        w.postMessage({ type: 'navigate', url })
        return
      }
    }
    await self.clients.openWindow(url)
  })())
})
