import { readFileSync } from 'fs'
import { parse, compileScript, compileTemplate, compileStyle } from '@vue/compiler-sfc'

const file = process.argv[2]
const src = readFileSync(file, 'utf8')

const loc = (err, tag) => {
  const l = err.loc?.start
  if (l) return `${tag} ${l.line}:${l.column} ${err.message}`
  return `${tag} ${err.message}`
}

// 1. parse (structural)
const { descriptor, errors } = parse(src, { filename: file })
if (errors.length) {
  errors.forEach(e => console.log('PARSE', loc(e, 'P')))
}

// 2. compileScript (only if script blocks exist)
if (descriptor.script || descriptor.scriptSetup) {
  try {
    compileScript(descriptor, { id: 'x' })
  } catch (e) {
    console.log('SCRIPT-ERR', loc(e, 'S'))
  }
}

// 3. compileTemplate
if (descriptor.template) {
  const tr = compileTemplate({
    source: descriptor.template.content,
    filename: file,
    id: 'x',
    compilerOptions: { bindingMetadata: undefined },
  })
  tr.errors.forEach(e => console.log('TEMPLATE', loc(e, 'T')))
}

// 4. compileStyle
for (const st of descriptor.styles) {
  const sr = compileStyle({ source: st.content, filename: file, id: 'x', scoped: st.scoped })
  sr.errors.forEach(e => console.log('STYLE', loc(e, 'C')))
}

console.log('DONE', file)