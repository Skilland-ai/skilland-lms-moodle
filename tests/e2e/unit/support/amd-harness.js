/**
 * A dependency-free harness that executes the plugin's AMD modules (src/amd/src/*.js) under
 * node:test: a stub `define`, fakes of core/str, core/ajax and core/notification, and a small
 * DOM with just what the modules touch (tree edits, a flat innerHTML parser, a simple selector
 * engine, events with bubbling, select/option semantics). No jsdom: the plugin keeps no DOM
 * library among its dependencies.
 */
const fs = require('node:fs')
const path = require('node:path')

const AMD_SRC = path.join(__dirname, '..', '..', '..', '..', 'src', 'amd', 'src')

// ---------------------------------------------------------------------------
// Mini DOM
// ---------------------------------------------------------------------------

function escapeText (value) {
  return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function escapeAttr (value) {
  return escapeText(value).replace(/"/g, '&quot;')
}

function decodeEntities (value) {
  return String(value)
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, '\'')
    .replace(/&amp;/g, '&')
}

class Event {
  constructor (type, options) {
    this.type = type
    this.bubbles = !!(options && options.bubbles)
    this.defaultPrevented = false
    this.propagationStopped = false
    this.target = null
    this.currentTarget = null
  }

  preventDefault () {
    this.defaultPrevented = true
  }

  stopPropagation () {
    this.propagationStopped = true
  }
}

class Node {
  constructor (doc) {
    this.ownerDocument = doc
    this.parentNode = null
    this.childNodes = []
    this.listeners = {}
  }

  get firstChild () {
    return this.childNodes[0] || null
  }

  get nextSibling () {
    if (!this.parentNode) {
      return null
    }
    const siblings = this.parentNode.childNodes
    return siblings[siblings.indexOf(this) + 1] || null
  }

  get children () {
    return this.childNodes.filter(child => child instanceof Element)
  }

  appendChild (node) {
    return this.insertBefore(node, null)
  }

  insertBefore (node, reference) {
    if (node.parentNode) {
      node.parentNode.removeChild(node)
    }
    const index = reference ? this.childNodes.indexOf(reference) : -1
    if (reference && index < 0) {
      throw new Error('insertBefore: the reference node is not a child')
    }
    if (index < 0) {
      this.childNodes.push(node)
    } else {
      this.childNodes.splice(index, 0, node)
    }
    node.parentNode = this
    return node
  }

  removeChild (node) {
    const index = this.childNodes.indexOf(node)
    if (index < 0) {
      throw new Error('removeChild: not a child')
    }
    this.childNodes.splice(index, 1)
    node.parentNode = null
    return node
  }

  replaceChild (node, old) {
    this.insertBefore(node, old)
    this.removeChild(old)
    return old
  }

  clearChildren () {
    this.childNodes.forEach(child => {
      child.parentNode = null
    })
    this.childNodes = []
  }

  get textContent () {
    return this.childNodes.map(child => child.textContent).join('')
  }

  set textContent (value) {
    this.clearChildren()
    if (String(value) !== '') {
      this.appendChild(new Text(this.ownerDocument, String(value)))
    }
  }

  /** Every element below this node, in document order. */
  descendants () {
    const out = []
    const walk = node => {
      node.childNodes.forEach(child => {
        if (child instanceof Element) {
          out.push(child)
          walk(child)
        }
      })
    }
    walk(this)
    return out
  }

  querySelectorAll (selector) {
    const groups = parseSelectorList(selector)
    return this.descendants().filter(element => groups.some(group => matchesComplex(element, group)))
  }

  querySelector (selector) {
    return this.querySelectorAll(selector)[0] || null
  }

  addEventListener (type, listener) {
    (this.listeners[type] = this.listeners[type] || []).push(listener)
  }

  removeEventListener (type, listener) {
    this.listeners[type] = (this.listeners[type] || []).filter(fn => fn !== listener)
  }

  dispatchEvent (event) {
    event.target = event.target || this
    let node = this
    while (node) {
      event.currentTarget = node;
      (node.listeners[event.type] || []).slice().forEach(listener => listener.call(node, event))
      if (!event.bubbles || event.propagationStopped) {
        break
      }
      node = node.parentNode
    }
    return !event.defaultPrevented
  }
}

class Text extends Node {
  constructor (doc, data) {
    super(doc)
    this.data = data
  }

  get textContent () {
    return this.data
  }

  set textContent (value) {
    this.data = String(value)
  }

  get outerHTML () {
    return escapeText(this.data)
  }

  cloneNode () {
    return new Text(this.ownerDocument, this.data)
  }
}

class ClassList {
  constructor (element) {
    this.element = element
  }

  get tokens () {
    return this.element.className.split(/\s+/).filter(Boolean)
  }

  add (...names) {
    const tokens = this.tokens
    names.forEach(name => {
      if (!tokens.includes(name)) {
        tokens.push(name)
      }
    })
    this.element.className = tokens.join(' ')
  }

  remove (...names) {
    this.element.className = this.tokens.filter(token => !names.includes(token)).join(' ')
  }

  contains (name) {
    return this.tokens.includes(name)
  }

  toggle (name, force) {
    const on = force === undefined ? !this.contains(name) : !!force
    if (on) {
      this.add(name)
    } else {
      this.remove(name)
    }
    return on
  }
}

const REFLECTED = ['id', 'name', 'type', 'href', 'target', 'title', 'for']

class Element extends Node {
  constructor (doc, tagName) {
    super(doc)
    this.tagName = tagName.toUpperCase()
    this.attributes = {}
    this.style = {}
    this.dataset = {}
    this.disabled = false
    this.checked = false
    this.inputValue = ''
    this.isSelected = false
    this.noneSelected = false
    this.classList = new ClassList(this)
    this.htmlAssignments = []
  }

  get className () {
    return this.attributes.class || ''
  }

  set className (value) {
    this.attributes.class = String(value)
  }

  getAttribute (name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null
  }

  setAttribute (name, value) {
    this.attributes[name] = String(value)
    if (name === 'value' && this.tagName !== 'OPTION') {
      this.inputValue = String(value)
    }
  }

  removeAttribute (name) {
    delete this.attributes[name]
  }

  hasAttribute (name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name)
  }

  /** The select an option belongs to. */
  get ownerSelect () {
    let node = this.parentNode
    while (node && node.tagName !== 'SELECT') {
      node = node.parentNode
    }
    return node || null
  }

  get options () {
    return this.descendants().filter(element => element.tagName === 'OPTION')
  }

  get selected () {
    return this.isSelected
  }

  set selected (value) {
    this.isSelected = !!value
    const select = this.ownerSelect
    if (select && value) {
      select.noneSelected = false
      select.options.forEach(option => {
        if (option !== this) {
          option.isSelected = false
        }
      })
    }
  }

  get value () {
    if (this.tagName === 'OPTION') {
      return this.hasAttribute('value') ? this.attributes.value : this.textContent
    }
    if (this.tagName === 'SELECT') {
      const options = this.options
      const selected = options.filter(option => option.isSelected).pop()
      if (selected) {
        return selected.value
      }
      return this.noneSelected || !options.length ? '' : options[0].value
    }
    return this.inputValue
  }

  set value (value) {
    if (this.tagName === 'OPTION') {
      this.attributes.value = String(value)
      return
    }
    if (this.tagName === 'SELECT') {
      const match = this.options.find(option => option.value === String(value))
      this.options.forEach(option => {
        option.isSelected = option === match
      })
      this.noneSelected = !match
      return
    }
    this.inputValue = String(value)
  }

  get form () {
    let node = this.parentNode
    while (node && node.tagName !== 'FORM') {
      node = node.parentNode
    }
    return node || null
  }

  closest (selector) {
    const groups = parseSelectorList(selector)
    let node = this
    while (node instanceof Element) {
      if (groups.some(group => matchesComplex(node, group))) {
        return node
      }
      node = node.parentNode
    }
    return null
  }

  matches (selector) {
    return parseSelectorList(selector).some(group => matchesComplex(this, group))
  }

  click () {
    if (!this.disabled) {
      this.dispatchEvent(new Event('click', { bubbles: true }))
    }
  }

  cloneNode (deep) {
    const copy = new Element(this.ownerDocument, this.tagName)
    copy.attributes = Object.assign({}, this.attributes)
    copy.style = Object.assign({}, this.style)
    copy.dataset = Object.assign({}, this.dataset)
    copy.disabled = this.disabled
    copy.checked = this.checked
    copy.inputValue = this.inputValue
    copy.isSelected = this.isSelected
    if (deep) {
      this.childNodes.forEach(child => copy.appendChild(child.cloneNode(true)))
    }
    return copy
  }

  get innerHTML () {
    return this.childNodes.map(child => child.outerHTML).join('')
  }

  set innerHTML (html) {
    this.htmlAssignments.push(String(html))
    this.clearChildren()
    parseFragment(this, String(html))
  }

  get outerHTML () {
    const tag = this.tagName.toLowerCase()
    const attrs = Object.keys(this.attributes)
      .map(name => ` ${name}="${escapeAttr(this.attributes[name])}"`).join('')
    return `<${tag}${attrs}>${this.innerHTML}</${tag}>`
  }
}

// Reflected attributes (id, name, type, href, target, title, for) read and write the attribute map.
REFLECTED.forEach(name => {
  Object.defineProperty(Element.prototype, name === 'for' ? 'htmlFor' : name, {
    get () {
      return this.attributes[name] || ''
    },
    set (value) {
      this.attributes[name] = String(value)
    }
  })
})

/**
 * Parse the flat HTML the modules assign to innerHTML: a run of text and non-nested
 * `<tag attr="value">text</tag>` elements. Anything else throws, so a test notices.
 */
function parseFragment (parent, html) {
  const doc = parent.ownerDocument
  const pattern = /<([a-z][a-z0-9]*)((?:\s+[a-z-]+="[^"]*")*)\s*>([^<]*)<\/\1>|([^<]+)/gy
  let match
  let consumed = 0
  while (consumed < html.length && (match = pattern.exec(html)) !== null) {
    consumed = pattern.lastIndex
    if (match[4] !== undefined) {
      parent.appendChild(new Text(doc, decodeEntities(match[4])))
      continue
    }
    const element = doc.createElement(match[1])
    const attrPattern = /([a-z-]+)="([^"]*)"/g
    let attr
    while ((attr = attrPattern.exec(match[2])) !== null) {
      element.setAttribute(attr[1], decodeEntities(attr[2]))
    }
    if (match[3] !== '') {
      element.appendChild(new Text(doc, decodeEntities(match[3])))
    }
    parent.appendChild(element)
  }
  if (consumed !== html.length) {
    throw new Error(`mini-dom cannot parse innerHTML: ${html}`)
  }
}

// ---------------------------------------------------------------------------
// Selectors: comma lists of descendant chains of compounds made of tag, #id, .class and
// [attr], [attr="v"], [attr*="v"], [attr^="v"], [attr$="v"].
// ---------------------------------------------------------------------------

function parseCompound (text) {
  const parts = []
  const pattern = /([a-zA-Z][a-zA-Z0-9]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:([*^$]?=)"([^"]*)")?\]/gy
  let match
  let consumed = 0
  while (consumed < text.length && (match = pattern.exec(text)) !== null) {
    consumed = pattern.lastIndex
    if (match[1]) {
      parts.push({ kind: 'tag', value: match[1].toUpperCase() })
    } else if (match[2]) {
      parts.push({ kind: 'id', value: match[2] })
    } else if (match[3]) {
      parts.push({ kind: 'class', value: match[3] })
    } else {
      parts.push({ kind: 'attr', name: match[4], op: match[5] || null, value: match[6] })
    }
  }
  if (consumed !== text.length || !parts.length) {
    throw new Error(`mini-dom: unsupported selector "${text}"`)
  }
  return parts
}

function parseSelectorList (selector) {
  return String(selector).split(',').map(group => group.trim().split(/\s+/).map(parseCompound))
}

function matchesCompound (element, parts) {
  return parts.every(part => {
    if (part.kind === 'tag') {
      return element.tagName === part.value
    }
    if (part.kind === 'id') {
      return element.id === part.value
    }
    if (part.kind === 'class') {
      return element.classList.contains(part.value)
    }
    const actual = part.name === 'class' ? element.className : element.getAttribute(part.name)
    if (actual === null || (part.name === 'class' && !element.hasAttribute('class'))) {
      return false
    }
    switch (part.op) {
      case null: return true
      case '=': return actual === part.value
      case '*=': return actual.includes(part.value)
      case '^=': return actual.startsWith(part.value)
      case '$=': return actual.endsWith(part.value)
      default: return false
    }
  })
}

function matchesComplex (element, compounds) {
  if (!matchesCompound(element, compounds[compounds.length - 1])) {
    return false
  }
  let index = compounds.length - 2
  let node = element.parentNode
  while (index >= 0 && node instanceof Element) {
    if (matchesCompound(node, compounds[index])) {
      index--
    }
    node = node.parentNode
  }
  return index < 0
}

class Document extends Node {
  constructor () {
    super(null)
    this.ownerDocument = this
    this.readyState = 'complete'
    this.body = new Element(this, 'body')
    this.appendChild(this.body)
  }

  createElement (tagName) {
    return new Element(this, tagName)
  }

  createTextNode (data) {
    return new Text(this, String(data))
  }

  getElementById (id) {
    return this.descendants().find(element => element.id === id) || null
  }
}

/**
 * Build an element: h(doc, 'div', {id: 'x', class: 'a b', value: 'v'}, [children...]).
 * A child string becomes a text node.
 */
function h (doc, tagName, attrs, children) {
  const element = doc.createElement(tagName)
  Object.keys(attrs || {}).forEach(name => {
    const value = attrs[name]
    if (name === 'disabled' || name === 'checked' || name === 'selected') {
      element[name] = !!value
    } else {
      element.setAttribute(name, value)
    }
  })
  ;(children || []).forEach(child => {
    element.appendChild(typeof child === 'string' ? doc.createTextNode(child) : child)
  })
  return element
}

// ---------------------------------------------------------------------------
// Moodle fakes
// ---------------------------------------------------------------------------

/**
 * What core/str would return: the key (prefixed "S:" for mod_skilland strings), plus " [{$a}]"
 * when the request carries a param, filled in exactly like M.util.get_string does it:
 * String.prototype.replace with the param as the replacement, so "$&" in a param is a pattern.
 */
function fakeString (request) {
  const raw = (request.component === 'core' ? '' : 'S:') + request.key +
    (request.param !== undefined ? ' [{$a}]' : '')
  return request.param !== undefined ? raw.replace(/\{\$a\}/g, request.param) : raw
}

/** A native promise with jQuery's done()/fail(), like core/str's get_strings. */
function jqueryPromise (promise) {
  promise.done = fn => {
    promise.then(fn, () => {})
    return promise
  }
  promise.fail = fn => {
    promise.then(null, fn)
    return promise
  }
  return promise
}

function createFakes (options) {
  const calls = { strings: [], ajax: [], notifications: [], exceptions: [], confirms: [], saveCancels: [] }
  const stringsMode = options.strings || 'resolve'
  const pendingStrings = []

  const Str = {
    get_strings (requests) {
      calls.strings.push(requests)
      const promise = new Promise((resolve, reject) => {
        const settle = (ok, error) => (ok ? resolve(requests.map(fakeString)) : reject(error))
        if (stringsMode === 'resolve') {
          settle(true)
        } else if (stringsMode === 'reject') {
          settle(false, options.stringsError || new Error('string fetch failed'))
        } else {
          pendingStrings.push(settle)
        }
      })
      promise.catch(() => {})
      return jqueryPromise(promise)
    }
  }

  const Ajax = {
    call (requests) {
      return requests.map(request => {
        calls.ajax.push({ methodname: request.methodname, args: request.args })
        const handler = (options.ajax || {})[request.methodname]
        if (!handler) {
          return Promise.reject(new Error(`unmocked ${request.methodname}`))
        }
        try {
          return Promise.resolve(handler(request.args, calls.ajax.length))
        } catch (error) {
          return Promise.reject(error)
        }
      })
    }
  }

  const Notification = {
    addNotification (notification) {
      calls.notifications.push(notification)
    },
    exception (error) {
      calls.exceptions.push(error)
    },
    confirm (title, message, yesLabel, noLabel, onYes, onNo) {
      calls.confirms.push({ title, message, yesLabel, noLabel, onYes, onNo })
      return Promise.resolve(null)
    },
    saveCancelPromise (title, body, saveLabel) {
      const entry = { title, body, saveLabel }
      calls.saveCancels.push(entry)
      return options.saveCancel === 'cancel' ? Promise.reject(new Error('cancelled')) : Promise.resolve()
    }
  }

  return {
    calls,
    modules: { 'core/str': Str, 'core/ajax': Ajax, 'core/notification': Notification },
    resolveStrings () {
      pendingStrings.splice(0).forEach(settle => settle(true))
    },
    rejectStrings (error) {
      pendingStrings.splice(0).forEach(settle => settle(false, error))
    }
  }
}

/**
 * Load an AMD module from src/amd/src with the fakes, a fresh document and fake timers.
 *
 * @param {string} name Module file name without .js, like 'mod_form'
 * @param {Object} [options] strings: 'resolve' | 'reject' | 'manual'; stringsError; ajax: {methodname: fn(args)};
 *   saveCancel: 'confirm' | 'cancel'
 */
function loadModule (name, options) {
  options = options || {}
  const source = fs.readFileSync(path.join(AMD_SRC, `${name}.js`), 'utf8')
  const fakes = createFakes(options)
  const doc = new Document()
  const timers = []
  const window = {
    console,
    location: { reloads: 0, reload () { this.reloads++ } }
  }
  const M = { cfg: { sesskey: 'sesskey-1', wwwroot: 'https://moodle.test' } }
  let exported = null
  const define = (deps, factory) => {
    exported = factory.apply(null, deps.map(dep => {
      if (!fakes.modules[dep]) {
        throw new Error(`no fake for ${dep}`)
      }
      return fakes.modules[dep]
    }))
  }
  const fakeSetTimeout = fn => {
    timers.push(fn)
    return timers.length
  }
  // eslint-disable-next-line no-new-func
  const run = new Function('define', 'document', 'window', 'M', 'setTimeout', 'Event', 'require', source)
  run(define, doc, window, M, fakeSetTimeout, Event, undefined)

  /** Settle promises and run queued timers until nothing is left. */
  async function flush () {
    for (let round = 0; round < 50; round++) {
      for (let i = 0; i < 5; i++) {
        await new Promise(resolve => setImmediate(resolve))
      }
      if (!timers.length) {
        return
      }
      timers.splice(0).forEach(fn => fn())
    }
    throw new Error('flush: timers keep rescheduling')
  }

  return { module: exported, document: doc, window, calls: fakes.calls, fakes, flush, h: (...args) => h(doc, ...args) }
}

module.exports = { loadModule, fakeString, Event }
