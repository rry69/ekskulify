const ALLOWED_TAGS = new Set(['b','i','u','strong','em','br','ul','ol','li','p','span','a'])
const DROP_TAGS = new Set(['script','style','iframe','object','embed','svg','math','form','input','textarea','select','button','link','meta','base','applet','canvas','video','audio','source','template','slot','portal'])
const SAFE_URL = /^(?:https?:\/\/|\/|#|mailto:|tel:)/i

export function sanitizeHtml(html){
  const s = String(html ?? '')
  if (!s) return ''
  if (typeof window === 'undefined' || typeof DOMParser === 'undefined') return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))
  const doc = new DOMParser().parseFromString(s, 'text/html')
  return clean(doc.body, doc).innerHTML
}

function clean(root, doc){
  const out = doc.createDocumentFragment()
  const kids = Array.from(root.childNodes)
  for (const child of kids){
    if (child.nodeType === 3){
      out.appendChild(doc.createTextNode(child.nodeValue))
    } else if (child.nodeType === 1){
      const tag = child.tagName.toLowerCase()
      if (DROP_TAGS.has(tag)) continue
      if (!ALLOWED_TAGS.has(tag)){
        out.appendChild(clean(child, doc))
        continue
      }
      const el = doc.createElement(tag)
      for (const attr of Array.from(child.attributes)){
        const name = attr.name.toLowerCase()
        if (name.startsWith('on')) continue
        if (name === 'style') continue
        if ((name === 'href' || name === 'src') && !SAFE_URL.test(attr.value)) continue
        el.setAttribute(attr.name, attr.value)
      }
      el.appendChild(clean(child, doc))
      out.appendChild(el)
    }
  }
  return out
}
