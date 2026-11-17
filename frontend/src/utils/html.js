// Teks dari web lama sering berisi HTML sederhana (<p>, <br>, <b>...). Hanya tag aman yang dipertahankan, atribut dibuang, selebihnya jadi teks.
const OK = new Set(['P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'UL', 'OL', 'LI', 'DIV', 'SPAN', 'SMALL'])

export function clean(html) {
  if (html == null || html === '') return ''
  const doc = new DOMParser().parseFromString(String(html), 'text/html')
  const walk = (node) => {
    for (const child of [...node.childNodes]) {
      if (child.nodeType === 3) continue
      if (child.nodeType !== 1) { child.remove(); continue }
      if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED'].includes(child.tagName)) { child.remove(); continue }
      walk(child)
      if (!OK.has(child.tagName)) { child.replaceWith(...child.childNodes); continue }
      for (const a of [...child.attributes]) child.removeAttribute(a.name)
    }
  }
  walk(doc.body)
  return doc.body.innerHTML
}
