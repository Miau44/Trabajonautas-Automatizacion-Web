const nodeMajorVersion = Number.parseInt(process.versions.node.split('.')[0], 10);

if (
  !Number.isInteger(nodeMajorVersion)
  || nodeMajorVersion < 18
  || typeof globalThis.fetch !== 'function'
) {
  console.error('SICOES requiere Node.js 18 o superior con fetch global.');
  process.exit(1);
}

const nodeFetch = globalThis.fetch.bind(globalThis);
const fs = require('fs');
const path = require('path');
const readline = require('readline');
const os = require('os');
const crypto = require('crypto');
const { spawnSync, spawn } = require('child_process');
const mammoth = require('mammoth');
const JSZip = require('jszip');

const BASE_DIR = __dirname;
const INPUT_BASE = path.join(BASE_DIR, 'entrada', 'words');
const OUT_DIR = path.join(BASE_DIR, 'salida');
const DATA_DIR = path.join(BASE_DIR, 'data');
const CONVOCATORIAS_DIR = path.join(OUT_DIR, 'convocatorias');
const RESULTADOS_DIR = path.join(OUT_DIR, 'resultados');
const UNIFICADOS_DIR = path.join(OUT_DIR, 'datos-unificados');
const FICHAS_FINALES_DIR = path.join(BASE_DIR, 'fichas-finales');
const PERFIL_DIR = path.join(BASE_DIR, 'perfil-sicoes');
const RUNTIME_DIR = path.join(BASE_DIR, 'runtime');
const TEMP_DIR = path.join(RUNTIME_DIR, 'temp');
const TOKEN_TIMEOUT_MS = Math.max(10000, Number(process.env.SICOES_TOKEN_TIMEOUT_MS || 60000));
const TABLE_TIMEOUT_MS = Math.max(10000, Number(process.env.SICOES_TABLE_TIMEOUT_MS || 60000));
const WORD_DOWNLOAD_TIMEOUT_MS = 60000;
const WORD_PROCESS_TIMEOUT_MS = 60000;
const MANUAL_DOWNLOAD_TIMEOUT_MS = Number(process.env.SICOES_MANUAL_DOWNLOAD_TIMEOUT_MS || 600000);
const DOWNLOAD_ATTEMPTS = Math.min(3, Math.max(1, Number(process.env.SICOES_DOWNLOAD_ATTEMPTS || 2)));
const DOWNLOAD_ATTEMPT_TIMEOUT_MS = Math.min(
  180000,
  Math.max(30000, Number(process.env.SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS || 120000))
);
const REPLAY_TIMEOUT_MS = Math.min(
  90000,
  Math.max(10000, Number(process.env.SICOES_REPLAY_TIMEOUT_MS || 45000))
);
const CDP_PORT = Number(process.env.SICOES_CDP_PORT || 9222);
const CDP_URL = process.env.SICOES_CDP_URL || `http://127.0.0.1:${CDP_PORT}`;
const SOURCE_CONSULTING = 'consulting_services';
const SOURCE_PERSONNEL = 'personnel_requirements';
let ACTIVE_SICOES_SOURCE = SOURCE_CONSULTING;

function activeTableSelector() {
  return ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? '#tablaAvanzada' : '#tablaSimple';
}

function activeIdentifierLabel() {
  return ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? 'Referencia' : 'CUCE';
}

fs.mkdirSync(TEMP_DIR, { recursive: true });

function usableEnvPath(value) {
  return value && String(value).trim() !== '' && String(value).toLowerCase() !== 'undefined';
}

if (!usableEnvPath(process.env.TEMP)) process.env.TEMP = TEMP_DIR;
if (!usableEnvPath(process.env.TMP)) process.env.TMP = TEMP_DIR;
if (!usableEnvPath(process.env.TMPDIR)) process.env.TMPDIR = TEMP_DIR;

function truthyEnv(value) {
  return ['1', 'true', 'yes', 'si', 'sí', 'on'].includes(String(value || '').trim().toLowerCase());
}

const LOG_TEXT_MAX_CHARS = 2000;
const TRACE_REQUEST_LIMIT = 100;
const TRACE_TARGET_LIMIT = 25;
const rawConsole = {
  log: console.log.bind(console),
  warn: console.warn.bind(console),
  error: console.error.bind(console),
};
const SENSITIVE_OBJECT_KEYS = new Set([
  'token',
  'tokenarchivo',
  'accesstoken',
  'refreshtoken',
  'downloadtoken',
  'auth',
  'authtoken',
  'authorization',
  'proxyauthorization',
  'apikey',
  'xapikey',
  'xgoogapikey',
  'cookie',
  'cookies',
  'setcookie',
  'session',
  'sessionid',
  'secret',
  'clientsecret',
  'key',
  'signature',
  'sig',
  'credential',
  'xamzsignature',
  'xamzcredential',
  'xamzsecuritytoken',
  'xgoogsignature',
  'xgoogcredential',
  'password',
  'postdata',
  'postdatapreview',
  'requestbody',
  'responsebody',
  'rawresponse',
  'documenttext',
  'documentcontent',
]);

function normalizedDiagnosticKey(key) {
  return String(key || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

function isSensitiveDiagnosticKey(key) {
  return SENSITIVE_OBJECT_KEYS.has(normalizedDiagnosticKey(key));
}

function truncateDiagnostic(value, maxChars = LOG_TEXT_MAX_CHARS) {
  const text = String(value ?? '');

  if (text.length <= maxChars) return text;

  return `${text.slice(0, maxChars)}...[truncado ${text.length - maxChars} caracteres]`;
}

function redactSensitiveText(value, maxChars = LOG_TEXT_MAX_CHARS) {
  let text = String(value ?? '');

  text = text
    .replace(/\b(Bearer|Basic)\s+[A-Za-z0-9+/_=.~-]+/gi, '$1 [REDACTED]')
    .replace(
      /([?&#](?:access[_-]?token|refresh[_-]?token|download[_-]?token|auth(?:[_-]?token)?|token(?:archivo)?|authorization|api[_-]?key|apikey|key|signature|sig|credential|x[_-]?(?:amz[_-]?(?:signature|credential|security[_-]?token)|goog[_-]?(?:signature|credential))|cookie|session(?:[_-]?id)?|secret|password)=)[^&#\s"'<>]*/gi,
      '$1[REDACTED]'
    )
    .replace(
      /(^|[&;\s])((?:access[_-]?token|refresh[_-]?token|download[_-]?token|auth(?:[_-]?token)?|token(?:archivo)?|authorization|api[_-]?key|apikey|key|signature|sig|credential|x[_-]?(?:amz[_-]?(?:signature|credential|security[_-]?token)|goog[_-]?(?:signature|credential))|cookie|session(?:[_-]?id)?|secret|password)=)[^&;\s]*/gim,
      '$1$2[REDACTED]'
    )
    .replace(
      /(\b(?:access[_-]?token|refresh[_-]?token|download[_-]?token|auth(?:[_-]?token)?|token(?:archivo)?|authorization|api[_-]?key|apikey|key|signature|sig|credential|x[_-]?(?:amz[_-]?(?:signature|credential|security[_-]?token)|goog[_-]?(?:signature|credential))|cookie|session(?:[_-]?id)?|secret|password|post[_-]?data)\b\s*[:=]\s*)(?!\[REDACTED\])[^,\s;}\]]+/gi,
      '$1[REDACTED]'
    )
    .replace(
      /(["']?(?:access[_-]?token|refresh[_-]?token|download[_-]?token|auth(?:[_-]?token)?|token(?:archivo)?|authorization|api[_-]?key|apikey|key|signature|sig|credential|x[_-]?(?:amz[_-]?(?:signature|credential|security[_-]?token)|goog[_-]?(?:signature|credential))|cookie|session(?:[_-]?id)?|secret|password|post[_-]?data)["']?\s*:\s*["'])[^"']*(["'])/gi,
      '$1[REDACTED]$2'
    )
    .replace(
      /(\b(?:authorization|proxy-authorization|x-api-key|x-goog-api-key|api-key|cookie|set-cookie)\b\s*:\s*)[^\r\n]+/gi,
      '$1[REDACTED]'
    )
    .replace(
      /(descargarArchivo\(\s*['"])[^'"]+(['"]\s*\))/gi,
      '$1[REDACTED]$2'
    )
    .replace(
      /(<input\b(?=[^>]*(?:name|id)=["'][^"']*(?:token|session|password|secret)[^"']*["'])[^>]*\bvalue=["'])[^"']*(["'][^>]*>)/gi,
      '$1[REDACTED]$2'
    )
    .replace(/https?:\/\/[^/\s:@]+:[^@\s/]+@/gi, 'https://[REDACTED]@')
    .replace(/\bC:\\Users\\[^\\\s]+(?:\\[^\r\n\t]*)?/gi, '[LOCAL_PATH]')
    .replace(/\bD:\\[^\r\n\t]*/gi, '[LOCAL_PATH]');

  return truncateDiagnostic(text, maxChars);
}

function redactUrl(value) {
  const raw = String(value || '');

  try {
    const parsed = new URL(raw);

    if (!['http:', 'https:'].includes(parsed.protocol)) {
      return '[UNSAFE_URL_SCHEME]';
    }

    parsed.username = '';
    parsed.password = '';
    parsed.hash = '';

    for (const key of [...parsed.searchParams.keys()]) {
      if (isSensitiveDiagnosticKey(key)) {
        parsed.searchParams.set(key, '[REDACTED]');
      }
    }

    return redactSensitiveText(parsed.toString());
  } catch (_) {
    return redactSensitiveText(raw);
  }
}

function sanitizeDiagnosticValue(value, depth = 0, seen = new WeakSet()) {
  if (typeof value === 'string') return redactSensitiveText(value);
  if (value === null || typeof value !== 'object') return value;
  if (depth >= 4) return '[TRUNCATED]';
  if (seen.has(value)) return '[CIRCULAR]';

  seen.add(value);

  if (Array.isArray(value)) {
    const safe = value.slice(0, 50)
      .map(item => sanitizeDiagnosticValue(item, depth + 1, seen));

    if (value.length > safe.length) {
      safe.push(`[TRUNCATED ${value.length - safe.length} ITEMS]`);
    }

    return safe;
  }

  const clean = {};

  for (const [key, item] of Object.entries(value).slice(0, 50)) {
    clean[key] = isSensitiveDiagnosticKey(key)
      ? '[REDACTED]'
      : sanitizeDiagnosticValue(item, depth + 1, seen);
  }

  return clean;
}

function sanitizeHeadersForDiagnostics(headers) {
  const clean = {};
  const allowed = new Set([
    'accept',
    'content-type',
    'content-length',
    'content-disposition',
    'user-agent',
  ]);

  for (const [key, value] of Object.entries(headers || {})) {
    const normalized = String(key).toLowerCase();

    if (isSensitiveDiagnosticKey(key)) {
      clean[key] = '[REDACTED]';
    } else if (allowed.has(normalized)) {
      clean[key] = redactSensitiveText(value, 512);
    }
  }

  return clean;
}

function summarizePostData(value) {
  const raw = String(value || '');
  const fields = [];

  if (raw) {
    try {
      const trimmed = raw.trim();
      const candidates = trimmed.startsWith('{')
        ? Object.keys(JSON.parse(trimmed))
        : [...new URLSearchParams(raw).keys()];

      for (const key of candidates) {
        if (/^[A-Za-z0-9_.[\]-]{1,80}$/.test(key) && !fields.includes(key)) {
          fields.push(isSensitiveDiagnosticKey(key) ? '[SENSITIVE_FIELD]' : key);
        }

        if (fields.length >= 20) break;
      }
    } catch (_) {}
  }

  return {
    present: raw.length > 0,
    length: raw.length,
    fields,
  };
}

function payloadMetadata(value) {
  const buffer = Buffer.isBuffer(value) ? value : Buffer.from(String(value ?? ''), 'utf8');

  return {
    bytes: buffer.length,
    sha256: crypto.createHash('sha256').update(buffer).digest('hex'),
  };
}

function safePathForLog(filePath) {
  const resolved = path.resolve(String(filePath || ''));
  const relative = path.relative(BASE_DIR, resolved);

  if (relative && !relative.startsWith('..') && !path.isAbsolute(relative)) {
    return relative.replace(/\\/g, '/');
  }

  return path.basename(resolved);
}

function sanitizeDownloadReportResult(result) {
  const value = result || {};

  return {
    cuce: redactSensitiveText(value.cuce || '', 100),
    ok: Boolean(value.ok),
    archivo: value.archivo === 'existente'
      ? 'existente'
      : path.basename(String(value.archivo || '')),
    metodo: redactSensitiveText(value.metodo || '', 100),
    size: Number.isFinite(Number(value.size)) ? Number(value.size) : null,
    motivo: value.motivo ? redactSensitiveText(value.motivo, 300) : null,
  };
}

function errorMessage(error) {
  return redactSensitiveText(
    error?.message || String(error || 'error desconocido')
  );
}

function safeErrorDetails(error) {
  return redactSensitiveText(
    error?.stack || error?.message || String(error || 'error desconocido'),
    4000
  );
}

function sanitizeConsoleArgument(value) {
  if (value instanceof Error) return safeErrorDetails(value);
  if (value !== null && typeof value === 'object') return sanitizeDiagnosticValue(value);

  return typeof value === 'string' ? redactSensitiveText(value) : value;
}

console.log = (...args) => rawConsole.log(...args.map(sanitizeConsoleArgument));
console.warn = (...args) => rawConsole.warn(...args.map(sanitizeConsoleArgument));
console.error = (...args) => rawConsole.error(...args.map(sanitizeConsoleArgument));

function waitEnter(message) {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
  });

  return new Promise(resolve => {
    rl.question(`\n${message}\nPresiona ENTER para continuar... `, () => {
      rl.close();
      resolve();
    });
  });
}

function ask(message) {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
  });

  return new Promise(resolve => {
    rl.question(message, answer => {
      rl.close();
      resolve(answer);
    });
  });
}

function parseArgs(args) {
  const flags = new Set();
  const options = {};
  const positionals = [];

  for (const arg of args) {
    if (!arg.startsWith('--')) {
      positionals.push(arg);
      continue;
    }

    const raw = arg.slice(2);
    const eqIndex = raw.indexOf('=');
    const key = eqIndex >= 0 ? raw.slice(0, eqIndex) : raw;
    const value = eqIndex >= 0 ? raw.slice(eqIndex + 1) : true;

    flags.add(`--${key}`);
    options[key] = value;
  }

  return { flags, options, positionals };
}

function optionValue(options, ...keys) {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(options, key)) {
      return options[key];
    }
  }

  return null;
}

function normalizarFecha(fecha) {
  return String(fecha || '').trim().replace(/[.-]/g, '/');
}

function fechaSlug(fecha) {
  const normal = normalizarFecha(fecha);
  const match = normal.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);

  if (!match) {
    return normal.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  const [, d, m, y] = match;
  return `${d.padStart(2, '0')}-${m.padStart(2, '0')}-${y}`;
}

function fechaDisplay(fecha) {
  const normal = normalizarFecha(fecha);
  const match = normal.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);

  if (!match) return normal;

  const [, d, m, y] = match;
  return `${d.padStart(2, '0')}/${m.padStart(2, '0')}/${y}`;
}

function ensureDirs(...dirs) {
  dirs.forEach(dir => fs.mkdirSync(dir, { recursive: true }));
}

function sleepSync(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function isRetryableFsError(error) {
  return ['EPERM', 'EACCES', 'EBUSY'].includes(error?.code);
}

function writeFileSyncRetry(filePath, data, encoding, attempts = 8) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      fs.writeFileSync(filePath, data, encoding);
      return;
    } catch (error) {
      lastError = error;

      if (!isRetryableFsError(error) || attempt === attempts) {
        throw error;
      }

      sleepSync(150 * attempt);
    }
  }

  throw lastError;
}

function unlinkSyncRetry(filePath, attempts = 5) {
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      fs.unlinkSync(filePath);
      return;
    } catch (error) {
      if (error.code === 'ENOENT') {
        return;
      }

      if (!isRetryableFsError(error) || attempt === attempts) {
        throw error;
      }

      sleepSync(150 * attempt);
    }
  }
}

function renameSyncRetry(fromPath, toPath, attempts = 8) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      fs.renameSync(fromPath, toPath);
      return;
    } catch (error) {
      lastError = error;

      if (!isRetryableFsError(error) || attempt === attempts) {
        throw error;
      }

      sleepSync(150 * attempt);
    }
  }

  throw lastError;
}

function writeFileSafe(filePath, data, encoding, attempts = 8) {
  ensureDirs(path.dirname(filePath));

  if (fs.existsSync(filePath)) {
    try {
      fs.chmodSync(filePath, 0o666);
    } catch (_) {}
  }

  try {
    writeFileSyncRetry(filePath, data, encoding, attempts);
    return;
  } catch (error) {
    if (!isRetryableFsError(error)) {
      throw error;
    }
  }

  try {
    unlinkSyncRetry(filePath);
  } catch (_) {}

  writeFileSyncRetry(filePath, data, encoding, attempts);
}

function writeAuxFileSafe(filePath, data, encoding) {
  try {
    writeFileSafe(filePath, data, encoding, 1);
    return true;
  } catch (error) {
    console.log(`[SICOES] No se pudo escribir archivo auxiliar ${safePathForLog(filePath)}: ${errorMessage(error)}. Se continua.`);
    return false;
  }
}

function writeFileAtomic(filePath, data, encoding) {
  ensureDirs(path.dirname(filePath));
  ensureDirs(TEMP_DIR);

  const safeBaseName = path.basename(filePath).replace(/[^a-zA-Z0-9._-]+/g, '_');
  const tmpPath = path.join(TEMP_DIR, `${safeBaseName}.${process.pid}.${Date.now()}.tmp`);

  try {
    writeFileSyncRetry(tmpPath, data, encoding);

    if (fs.existsSync(filePath)) {
      try {
        fs.chmodSync(filePath, 0o666);
      } catch (_) {}
    }

    try {
      renameSyncRetry(tmpPath, filePath);
    } catch (error) {
      if (!isRetryableFsError(error) && !['EEXIST', 'EXDEV'].includes(error.code)) {
        throw error;
      }

      try {
        unlinkSyncRetry(filePath);
      } catch (_) {}

      try {
        renameSyncRetry(tmpPath, filePath);
      } catch (fallbackError) {
        if (!isRetryableFsError(fallbackError) && !['EEXIST', 'EXDEV'].includes(fallbackError.code)) {
          throw fallbackError;
        }

        writeFileSafe(filePath, data, encoding);
      }
    }
  } finally {
    if (fs.existsSync(tmpPath)) {
      try {
        fs.unlinkSync(tmpPath);
      } catch (_) {}
    }
  }
}

function writeFileThroughNodeChild(filePath, data, encoding) {
  const payload = Buffer.isBuffer(data) ? data : Buffer.from(String(data), encoding || 'utf8');
  const script = `
    const fs = require('fs');
    const path = require('path');
    const filePath = process.argv[1];
    const chunks = [];
    process.stdin.on('data', chunk => chunks.push(chunk));
    process.stdin.on('end', () => {
      try {
        fs.mkdirSync(path.dirname(filePath), { recursive: true });
        fs.writeFileSync(filePath, Buffer.concat(chunks));
      } catch (error) {
        console.error(String(error?.name || 'Error') + ' ' + String(error?.code || ''));
        process.exit(1);
      }
    });
  `;

  const result = spawnSync(process.execPath, ['-e', script, filePath], {
    input: payload,
    windowsHide: true,
    maxBuffer: Math.max(1024 * 1024, payload.length + 1024),
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    const stderr = result.stderr ? result.stderr.toString('utf8').trim() : '';
    throw new Error(stderr || `proceso hijo finalizo con codigo ${result.status}`);
  }
}

function writeRequiredFinalFile(filePath, data, encoding) {
  try {
    writeFileSafe(filePath, data, encoding);
    return;
  } catch (directError) {
    console.log(`[SICOES] Escritura directa de JSON final fallo: ${errorMessage(directError)}. Intentando escritura por proceso hijo.`);
  }

  try {
    writeFileThroughNodeChild(filePath, data, encoding);
    return;
  } catch (childError) {
    console.log(`[SICOES] Escritura por proceso hijo fallo: ${errorMessage(childError)}. Intentando escritura atomica.`);
  }

  writeFileAtomic(filePath, data, encoding);
}

function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function phaseOk(number, message) {
  console.log(`[OK] Fase ${number}: ${redactSensitiveText(message)}`);
}

function phaseFail(number, message) {
  const formatted = `[FAIL] Fase ${number}: ${redactSensitiveText(message)}`;
  console.error(formatted);
  return new Error(formatted);
}

function emitProgress(step, message, data = {}) {
  const safeMessage = redactSensitiveText(message);
  const payload = sanitizeDiagnosticValue({ step, message: safeMessage, ...data });

  console.log(`[STEP ${step}] ${safeMessage}`);
  console.log(`[SICOES_PROGRESS] ${JSON.stringify(payload)}`);
}

function emitItem(index, total, item) {
  rawConsole.log(`[SICOES_ITEM] ${JSON.stringify({ index, total, cuce: item?.cuce || '', item })}`);
}

function withTimeout(promise, timeoutMs, label) {
  let timer = null;

  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label} excedio ${Math.round(timeoutMs / 1000)}s`)), timeoutMs);
  });

  return Promise.race([promise, timeout]).finally(() => {
    if (timer) clearTimeout(timer);
  });
}

async function withRetries(label, attempts, task) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      if (attempt > 1) {
        console.log(`[SICOES] Reintento ${attempt}/${attempts}: ${label}`);
      }

      return await task(attempt);
    } catch (error) {
      lastError = error;
      console.log(`[SICOES] ${label} fallo en intento ${attempt}: ${errorMessage(error)}`);

      if (attempt < attempts) {
        await delay(2000 * attempt);
      }
    }
  }

  throw lastError;
}

function safeFileName(name) {
  return String(name || '')
    .replace(/[<>:"/\\|?*\x00-\x1F]/g, '_')
    .replace(/\s+/g, '_')
    .slice(0, 140);
}

function normalizarTexto(texto) {
  return String(texto || '')
    .replace(/\r/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/[ \t]{2,}/g, ' ')
    .trim();
}

function decodeXmlText(value) {
  return String(value || '')
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
    .replace(/&#x([0-9a-fA-F]+);/g, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, num) => String.fromCodePoint(parseInt(num, 10)))
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');
}

function concat_unique(parts) {
  const vistos = new Set();
  const salida = [];

  for (const part of parts) {
    for (const rawLine of normalizarTexto(part).split('\n')) {
      const line = limpiarCampo(rawLine);
      if (!line) continue;
      const key = claveTexto(line);
      if (!key || vistos.has(key)) continue;
      vistos.add(key);
      salida.push(line);
    }
  }

  return salida.join('\n');
}

function extract_text_from_word_xml(xml) {
  const textParts = [];
  let current = '';
  const tokenRegex = /<\/w:(?:p|tc)>|<(?:w:t|a:t|m:t|w:instrText|w:delText)\b[^>]*>([\s\S]*?)<\/(?:w:t|a:t|m:t|w:instrText|w:delText)>/g;
  let match;

  while ((match = tokenRegex.exec(xml))) {
    const token = match[0];
    if (token.startsWith('</w:p')) {
      if (current.trim()) textParts.push(current.trim());
      current = '';
      continue;
    }

    if (token.startsWith('</w:tc')) {
      current += ' ';
      continue;
    }

    current += `${decodeXmlText(match[1])} `;
  }

  if (current.trim()) textParts.push(current.trim());
  return textParts.join('\n');
}

async function extract_paragraphs(docPath, mammothText = '') {
  if (mammothText) return mammothText;
  const result = await mammoth.extractRawText({ path: docPath });
  return result.value || '';
}

async function extract_tables(zip) {
  return extract_remaining_sections(zip, fileName => /word\/document\.xml$/i.test(fileName));
}

async function extract_table_cells(zip) {
  return extract_remaining_sections(zip, fileName => /word\/document\.xml$/i.test(fileName));
}

async function extract_remaining_sections(zip, predicate = null) {
  const xmlFiles = Object.keys(zip.files)
    .filter(fileName => /^word\/.+\.xml$/i.test(fileName))
    .filter(fileName => !/\/(?:styles|settings|fontTable|numbering|webSettings)\.xml$/i.test(fileName))
    .filter(fileName => !predicate || predicate(fileName))
    .sort((a, b) => {
      const rank = name => {
        if (/word\/document\.xml$/i.test(name)) return 0;
        if (/word\/header\d*\.xml$/i.test(name)) return 1;
        if (/word\/footer\d*\.xml$/i.test(name)) return 2;
        if (/word\/footnotes\.xml$/i.test(name)) return 3;
        if (/word\/endnotes\.xml$/i.test(name)) return 4;
        if (/word\/comments/.test(name)) return 5;
        return 9;
      };
      return rank(a) - rank(b) || a.localeCompare(b);
    });

  const sections = [];
  for (const fileName of xmlFiles) {
    const xml = await zip.files[fileName].async('string');
    const text = extract_text_from_word_xml(xml);
    if (text) sections.push(text);
  }

  return sections.join('\n');
}

async function build_full_document_text(docPath, mammothText = '') {
  const parts = [await extract_paragraphs(docPath, mammothText)];

  try {
    const buffer = fs.readFileSync(docPath);
    const zip = await JSZip.loadAsync(buffer);
    parts.push(await extract_tables(zip));
    parts.push(await extract_table_cells(zip));
    parts.push(await extract_remaining_sections(zip));
  } catch (error) {
    parts.push(mammothText);
  }

  return concat_unique(parts);
}

function detectarTipoWord(filePath) {
  const bytes = fs.readFileSync(filePath);

  if (bytes.length >= 4 && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) {
    return 'pdf';
  }

  if (bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4B) {
    return 'docx';
  }

  if (bytes.length >= 8 &&
    bytes[0] === 0xD0 &&
    bytes[1] === 0xCF &&
    bytes[2] === 0x11 &&
    bytes[3] === 0xE0 &&
    bytes[4] === 0xA1 &&
    bytes[5] === 0xB1 &&
    bytes[6] === 0x1A &&
    bytes[7] === 0xE1) {
    return 'doc-antiguo';
  }

  return 'desconocido';
}

function pareceWordPorNombre(fileName) {
  return /\.(docx?|pdf)$/i.test(String(fileName || ''));
}

function pareceWordPorBytes(filePath) {
  const bytes = fs.readFileSync(filePath);
  if (bytes.length >= 4 && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) return true;
  if (bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4B) return true;
  return bytes.length >= 8 &&
    bytes[0] === 0xD0 &&
    bytes[1] === 0xCF &&
    bytes[2] === 0x11 &&
    bytes[3] === 0xE0 &&
    bytes[4] === 0xA1 &&
    bytes[5] === 0xB1 &&
    bytes[6] === 0x1A &&
    bytes[7] === 0xE1;
}

function buscarLibreOffice() {
  const candidates = [
    'soffice',
    'libreoffice',
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
  ];

  for (const candidate of candidates) {
    const check = spawnSync(candidate, ['--version'], { encoding: 'utf8', shell: false });
    if (!check.error && check.status === 0) return candidate;
  }

  return null;
}

function buscarPdfToText() {
  const candidates = [
    process.env.SICOES_PDFTOTEXT_PATH,
    'pdftotext',
    'C:\\laragon\\bin\\git\\mingw64\\bin\\pdftotext.exe',
  ].filter(Boolean);

  for (const candidate of candidates) {
    const check = spawnSync(candidate, ['-v'], { encoding: 'utf8', shell: false });
    const versionOutput = `${check.stdout || ''}\n${check.stderr || ''}`;
    const reportedVersion = /pdftotext\s+version/i.test(versionOutput);

    // Xpdf usa 99 para mostrar la versión aunque el ejecutable sea válido.
    // Aceptamos el código no cero solamente cuando la salida identifica
    // inequívocamente a pdftotext, para no confundir otros fallos de ejecución.
    if (!check.error && (check.status === 0 || reportedVersion)) return candidate;
  }

  return null;
}

function extraerTextoPdf(filePath) {
  const executable = buscarPdfToText();
  if (!executable) {
    throw new Error('pdftotext no esta disponible; configure SICOES_PDFTOTEXT_PATH');
  }

  const result = spawnSync(executable, ['-layout', '-enc', 'UTF-8', filePath, '-'], {
    encoding: 'utf8',
    timeout: WORD_PROCESS_TIMEOUT_MS,
    maxBuffer: 64 * 1024 * 1024,
    shell: false,
  });

  if (result.error || result.status !== 0) {
    throw new Error(result.error?.message || result.stderr || 'No se pudo extraer el texto del PDF');
  }

  const texto = normalizarTexto(result.stdout || '');
  if (!texto) {
    throw new Error('El PDF no contiene texto extraible; puede requerir OCR');
  }

  return {
    texto,
    textoCompleto: texto,
    metodo: 'pdftotext',
    tipoReal: 'pdf',
    advertencia: '',
  };
}

function convertirDocAntiguoADocx(filePath) {
  const soffice = buscarLibreOffice();

  if (!soffice) {
    return {
      ok: false,
      error: 'LibreOffice no esta disponible',
      outputPath: '',
    };
  }

  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sicoes-doc-'));
  const result = spawnSync(soffice, [
    '--headless',
    '--convert-to',
    'docx',
    '--outdir',
    tmpDir,
    filePath,
  ], {
    encoding: 'utf8',
    timeout: 120000,
    shell: false,
  });

  if (result.error || result.status !== 0) {
    return {
      ok: false,
      error: result.error?.message || result.stderr || 'No se pudo convertir con LibreOffice',
      outputPath: '',
    };
  }

  const converted = fs.readdirSync(tmpDir)
    .filter(file => /\.(docx?|DOCX?)$/i.test(file))
    .map(file => path.join(tmpDir, file))[0];

  return {
    ok: Boolean(converted),
    error: converted ? '' : 'LibreOffice no genero un .docx',
    outputPath: converted || '',
  };
}

function extraerTextoWordAntiguo(filePath) {
  const buffer = fs.readFileSync(filePath);
  const chunks = [];
  let current = '';
  const allowed = /^[A-Za-z0-9ÁÉÍÓÚáéíóúÑñÜü.,;:()/%º°'"¿?¡!+\-\s]$/;

  for (let i = 0; i < buffer.length - 1; i += 2) {
    const code = buffer[i] | (buffer[i + 1] << 8);
    const ch = String.fromCharCode(code);

    if (allowed.test(ch)) {
      current += ch;
    } else {
      if (current.trim().length >= 8) chunks.push(current);
      current = '';
    }
  }

  if (current.trim().length >= 8) chunks.push(current);

  const latinText = buffer.toString('latin1');
  const latinChunks = latinText.match(/[A-Za-z0-9ÁÉÍÓÚáéíóúÑñÜü.,;:()/%º°'"¿?¡!+\-\s]{8,}/g) || [];
  const text = [...chunks, ...latinChunks].join('\n');

  return normalizarTexto(text)
    .replace(/\u000b/g, '\n')
    .replace(/\u000c/g, '\n')
    .replace(/\n{3,}/g, '\n\n');
}

async function extraerTextoWord(filePath) {
  const tipoReal = detectarTipoWord(filePath);

  if (tipoReal === 'pdf') {
    return extraerTextoPdf(filePath);
  }

  if (tipoReal === 'doc-antiguo') {
    const conversion = convertirDocAntiguoADocx(filePath);

    if (conversion.ok) {
      const result = await mammoth.extractRawText({ path: conversion.outputPath });
      const textoMammoth = normalizarTexto(result.value);
      const textoCompleto = await build_full_document_text(conversion.outputPath, result.value);
      return {
        texto: textoMammoth,
        textoCompleto: normalizarTexto(textoCompleto),
        metodo: 'libreoffice-doc-a-docx+mammoth+full_document_xml',
        tipoReal,
        advertencia: '',
      };
    }

    const texto = extraerTextoWordAntiguo(filePath);

    if (!texto) {
      throw new Error(conversion.error || 'No se pudo leer Word antiguo');
    }

    return {
      texto,
      metodo: 'extraccion_basica_doc_antiguo',
      tipoReal,
      advertencia: `${conversion.error}. Se uso extraccion basica; revisar acentos/formato.`,
    };
  }

  try {
    const result = await mammoth.extractRawText({ path: filePath });
    const textoMammoth = normalizarTexto(result.value);
    const textoCompleto = await build_full_document_text(filePath, result.value);
    return {
      texto: textoMammoth,
      textoCompleto: normalizarTexto(textoCompleto),
      metodo: 'mammoth_docx+full_document_xml',
      tipoReal,
      advertencia: '',
    };
  } catch (error) {
    throw error;
  }
}

function obtenerLineas(texto) {
  return normalizarTexto(texto)
    .split('\n')
    .map(linea => linea.trim())
    .filter(Boolean);
}

function limpiarCampo(value) {
  return repararMojibake(String(value || ''))
    .replace(/\bCONTENIDO\b/gi, ' ')
    .replace(/\bHYPERLINK\b/gi, ' ')
    .replace(/_Toc\d+/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function repararMojibake(value) {
  const text = String(value || '');
  if (!/[ÃÂ]/.test(text)) return text;

  try {
    const fixed = Buffer.from(text, 'latin1').toString('utf8');
    const before = (text.match(/[ÃÂ]/g) || []).length;
    const after = (fixed.match(/[ÃÂ]/g) || []).length;
    return after < before ? fixed : text;
  } catch (error) {
    return text;
  }
}

function claveTexto(value) {
  return limpiarCampo(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

function esFragmentoLegal(fragmento) {
  const key = claveTexto(fragmento);

  return [
    'formulario',
    'declaracion jurada',
    'modelo de contrato',
    'documentos administrativos',
    'documentacion administrativa',
    'impuestos',
    'multas',
    'garantia de seriedad',
    'garantias',
    'recurso administrativo',
    'impugnacion',
    'nb-sabs',
    'articulo',
    'resolucion',
    'adjudicacion',
    'declaratoria desierta',
    'plazo de validez',
    'deposito por concepto de garantia',
    'garantia de cumplimiento',
    'plazo minimo',
  ].some(term => key.includes(term));
}

function normalizarClaveSimple(value) {
  return claveTexto(value)
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

const TITULOS_BLOQUES_SICOES = [
  'objeto de la contratacion',
  'terminos de referencia',
  'perfil del consultor',
  'perfil requerido',
  'formacion academica',
  'formacion profesional',
  'experiencia general',
  'experiencia especifica',
  'lugar y plazo',
  'plazo',
  'duracion del servicio',
  'duracion del contrato',
  'condiciones del servicio',
  'presupuesto',
  'modalidad de contratacion y forma de pago',
  'modalidad de contratacion',
  'forma de pago',
  'forma de presentacion',
  'disposiciones de la participacion',
  'instrucciones al consultor',
  'cronograma de pagos',
  'requisitos minimos',
  'requisitos del consultor',
  'condiciones minimas',
  'documentos administrativos',
  'formulario',
  'declaracion jurada',
  'modelo de contrato',
  'contrato',
  'garantias',
  'multas',
  'impuestos',
];

function tituloBloqueCanonico(linea) {
  let key = normalizarClaveSimple(linea)
    .replace(/^\d+(?:\.\d+)*\s+/, '')
    .replace(/^seccion\s+(?:v\s*i|[ivxlcdm]+|\d+)\s+/, '');
  if (!key || key.length > 90) return null;

  const encontrado = TITULOS_BLOQUES_SICOES.find(titulo =>
    key === titulo ||
    key.startsWith(`${titulo} `) ||
    key.endsWith(` ${titulo}`) ||
    key.includes(` ${titulo} `)
  );

  if (!encontrado) return null;
  return encontrado.toUpperCase();
}

function segmentarBloquesDocumento(texto) {
  const lineas = obtenerLineas(texto).map(limpiarCampo).filter(Boolean);
  const bloques = [];
  let actual = {
    id: 'B1',
    titulo: 'DOCUMENTO',
    lineas: [],
    inicio_linea: 1,
  };

  const cerrar = () => {
    const textoBloque = limpiarCampo(actual.lineas.join('\n'));
    if (!textoBloque) return;
    bloques.push({
      id: `B${bloques.length + 1}`,
      titulo: actual.titulo,
      texto: textoBloque,
      inicio_linea: actual.inicio_linea,
      fin_linea: actual.inicio_linea + actual.lineas.length - 1,
    });
  };

  lineas.forEach((linea, index) => {
    const titulo = tituloBloqueCanonico(linea);
    if (titulo && actual.lineas.length) {
      cerrar();
      actual = {
        id: `B${bloques.length + 1}`,
        titulo,
        lineas: [linea],
        inicio_linea: index + 1,
      };
      return;
    }

    if (titulo) {
      actual.titulo = titulo;
      actual.inicio_linea = index + 1;
    }

    actual.lineas.push(linea);
  });

  cerrar();

  return bloques.length
    ? bloques
    : [{
      id: 'B1',
      titulo: 'DOCUMENTO',
      texto: limpiarCampo(texto),
      inicio_linea: 1,
      fin_linea: lineas.length,
    }];
}

function textoTerminosReferenciaPrioritario(texto) {
  const lineas = obtenerLineas(texto).map(limpiarCampo).filter(Boolean);
  if (!lineas.length) return { texto: '', usado: false };

  const indicesTdr = [];
  lineas.forEach((linea, index) => {
    const key = normalizarClaveSimple(linea);
    if (
      key === 'terminos de referencia' ||
      key.startsWith('terminos de referencia ') ||
      /^seccion\s+(?:v|5)\s+terminos de referencia\b/.test(key)
    ) {
      indicesTdr.push(index);
    }
  });

  const inicio = indicesTdr.find(index => {
    const keyLinea = normalizarClaveSimple(lineas[index]);
    const keySiguiente = normalizarClaveSimple(lineas[index + 1] || '');
    if (/terminos de referencia\s+\d+$/.test(keyLinea) && keySiguiente.startsWith('seccion vi contrato')) {
      return false;
    }

    const ventana = normalizarClaveSimple(lineas.slice(index, index + 180).join(' '));
    return ventana.includes('perfil requerido') ||
      ventana.includes('formacion profesional') ||
      ventana.includes('lugar y plazo') ||
      ventana.includes('modalidad de contratacion') ||
      ventana.includes('presupuesto');
  });

  if (inicio === undefined) return { texto: '', usado: false };

  let fin = lineas.length;
  for (let i = inicio + 1; i < lineas.length; i++) {
    const key = normalizarClaveSimple(lineas[i]);
    if (/^seccion\s+(?:vi|6|v\s*i)\s+contrato\b/.test(key)) {
      fin = i;
      break;
    }
  }

  const textoTdr = lineas.slice(inicio, fin).join('\n').trim();
  return {
    texto: textoTdr,
    usado: Boolean(textoTdr),
    inicio_linea: inicio + 1,
    fin_linea: fin,
  };
}

const PROFESION_EVIDENCIA = [
  'titulo profesional',
  'titulo en provision nacional',
  'licenciatura',
  'licenciado en',
  'licenciada en',
  'tecnico superior',
  'formacion academica',
  'formacion profesional',
  'perfil del consultor',
  'perfil requerido',
  'requisitos del consultor',
  'requisitos minimos',
  'profesional en',
  'area de formacion',
];

const PROFESION_POSITIVAS = [
  ['perfil requerido', 6],
  ['perfil del consultor', 6],
  ['formacion academica', 9],
  ['formacion profesional', 9],
  ['requisitos minimos', 6],
  ['requisitos del consultor', 6],
  ['profesional en', 5],
  ['licenciatura en', 6],
  ['titulo profesional en', 7],
  ['titulo profesional', 5],
  ['tecnico superior', 5],
  ['area de formacion', 6],
  ['requisito minimo habilitante', 6],
];

const BLOQUE_NEGATIVAS = [
  ['formulario', 8],
  ['declaracion jurada', 10],
  ['modelo de contrato', 10],
  ['contrato', 8],
  ['garantias', 8],
  ['garantia', 6],
  ['multas', 8],
  ['impuestos', 8],
  ['documentos administrativos', 9],
  ['documentacion administrativa', 9],
  ['anexo', 4],
  ['xxxxx', 10],
  ['_______', 10],
];

function contieneEvidenciaProfesion(texto) {
  const key = normalizarClaveSimple(texto);
  return PROFESION_EVIDENCIA.some(frase => key.includes(frase));
}

function puntuarBloque(bloque, positivas) {
  const texto = `${bloque.titulo || ''}\n${bloque.texto || ''}`;
  const key = normalizarClaveSimple(texto);
  const senales = [];
  const penalizaciones = [];
  let puntaje = 0;

  positivas.forEach(([frase, peso]) => {
    if (key.includes(frase)) {
      puntaje += peso;
      senales.push(frase);
    }
  });

  BLOQUE_NEGATIVAS.forEach(([frase, peso]) => {
    if (key.includes(frase)) {
      puntaje -= peso;
      penalizaciones.push(frase);
    }
  });

  if (esFragmentoLegal(texto)) {
    puntaje -= 6;
    penalizaciones.push('fragmento_legal');
  }

  return { puntaje, senales, penalizaciones };
}

function recortarEvidencia(texto, max = 900) {
  const limpio = limpiarTextoFicha(texto || '');
  if (limpio.length <= max) return limpio;
  return `${limpio.slice(0, max).trim()}...`;
}

function bloquesCandidatosProfesion(texto, limite = 8) {
  return segmentarBloquesDocumento(texto)
    .map(bloque => {
      const score = puntuarBloque(bloque, PROFESION_POSITIVAS);
      const tieneEvidencia = contieneEvidenciaProfesion(`${bloque.titulo}\n${bloque.texto}`);
      const puntaje = score.puntaje + (tieneEvidencia ? 5 : -8);

      return {
        id: bloque.id,
        titulo_bloque: bloque.titulo,
        texto: recortarEvidencia(bloque.texto),
        puntaje,
        confianza: puntaje >= 12 ? 'alta' : (puntaje >= 7 ? 'media' : 'baja'),
        evidencia_cercana: tieneEvidencia,
        senales: score.senales,
        penalizaciones: score.penalizaciones,
      };
    })
    .filter(bloque => bloque.evidencia_cercana && bloque.puntaje >= 7)
    .sort((a, b) => b.puntaje - a.puntaje)
    .slice(0, limite);
}

const SUELDO_POSITIVAS = [
  ['presupuesto fijo mensual', 9],
  ['monto mensual', 8],
  ['honorario mensual', 8],
  ['honorarios mensuales', 8],
  ['sueldo mensual', 8],
  ['salario mensual', 8],
  ['cuotas mensuales', 10],
  ['cuotas parciales mensuales', 10],
  ['pagos mensuales', 9],
  ['pago mensual', 9],
  ['salario total', 6],
  ['presupuesto total por consultor', 6],
  ['presupuesto total asignado', 5],
  ['precio referencial', 5],
  ['monto referencial', 5],
  ['monto total', 4],
  ['forma de pago', 6],
  ['cronograma de pagos', 6],
  ['producto', 4],
  ['contra entrega', 4],
  ['porcentaje de pago', 4],
];

function bloquesCandidatosSueldo(texto, limite = 8) {
  return segmentarBloquesDocumento(texto)
    .map(bloque => {
      const score = puntuarBloque(bloque, SUELDO_POSITIVAS);
      const key = normalizarClaveSimple(`${bloque.titulo}\n${bloque.texto}`);
      const tieneMonto = /bs\s*\d|\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?/.test(key);
      const tieneProducto = key.includes('producto') || key.includes('forma de pago') || key.includes('cronograma de pagos');
      const puntaje = score.puntaje + (tieneMonto ? 4 : 0) + (tieneProducto ? 2 : 0);

      return {
        id: bloque.id,
        titulo_bloque: bloque.titulo,
        texto: recortarEvidencia(bloque.texto),
        puntaje,
        confianza: puntaje >= 12 ? 'alta' : (puntaje >= 7 ? 'media' : 'baja'),
        senales: score.senales,
        penalizaciones: score.penalizaciones,
      };
    })
    .filter(bloque => bloque.puntaje >= 7)
    .sort((a, b) => b.puntaje - a.puntaje)
    .slice(0, limite);
}

function extraerCercaDe(lineas, palabrasClave, maxLineas = 4) {
  const index = lineas.findIndex(linea => {
    const l = claveTexto(linea);
    return palabrasClave.some(p => l.includes(claveTexto(p)));
  });

  if (index === -1) return '';

  const fragmento = limpiarCampo(lineas.slice(index, index + maxLineas).join('\n'));
  return esFragmentoLegal(fragmento) ? '' : fragmento;
}

function parseMontoBoliviano(valor) {
  let text = limpiarCampo(valor)
    .replace(/^bs\.?\s*/i, '')
    .replace(/\s*bs\.?$/i, '')
    .trim();
  const match = text.match(/\d{1,3}(?:\.\d{3})*,\d{1,2}|\d{1,3}(?:\.\d{3})+\.\d{2}|\d{1,3}(?:\.\d{3})+|\d+/);
  if (!match) return 0;

  text = match[0];
  if (/^\d{1,3}(?:\.\d{3})+\.\d{2}$/.test(text)) {
    text = text.replace(/\.(\d{2})$/, ',$1');
  }

  const normalizado = text.includes(',')
    ? text.replace(/\./g, '').replace(',', '.')
    : text.replace(/\./g, '');
  const number = Number(normalizado);
  return Number.isFinite(number) ? number : 0;
}

function formatearBs(numero) {
  const n = Number(numero || 0);
  if (!n) return 'Bs. 0,00';
  const [entero, decimal] = n.toFixed(2).split('.');
  return `Bs. ${Number(entero).toLocaleString('de-DE')},${decimal}`;
}

function normalizarNumeroSalida(numero) {
  const n = Number(numero || 0);
  if (!n) return 0;
  return Number.isInteger(n) ? n : Number(n.toFixed(2));
}

function encontrarMontos(fragmento) {
  const text = limpiarCampo(fragmento);
  const patterns = [
    /bs\.?\s*[\d.]+(?:,\d{1,2})?/gi,
    /\b\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?\b/g,
    /\b\d{1,3}(?:\.\d{3})+\.\d{2}\b/g,
  ];
  const out = [];

  for (const pattern of patterns) {
    for (const match of text.matchAll(pattern)) {
      const sueldoNumero = parseMontoBoliviano(match[0]);
      if (!sueldoNumero) continue;
      const montoTexto = limpiarCampo(match[0])
        .replace(/^bs\.?\s*/i, 'Bs. ');
      out.push({
        sueldo_numero: sueldoNumero,
        sueldo_texto: /^bs/i.test(match[0]) ? montoTexto : formatearBs(sueldoNumero),
        raw: limpiarCampo(match[0]),
        index: match.index || 0,
      });
    }
  }

  const unique = new Map();
  for (const item of out) {
    const key = `${item.sueldo_numero}-${item.sueldo_texto}`;
    if (!unique.has(key)) unique.set(key, item);
  }

  return [...unique.values()];
}

function clasificarMonto(fragmento) {
  const key = claveTexto(fragmento);
  if (
    key.includes('presupuesto total por consultor') ||
    key.includes('presupuesto total asignado') ||
    key.includes('monto total') ||
    key.includes('monto del contrato')
  ) return 'presupuesto_total';
  if (
    key.includes('presupuesto fijo mensual') ||
    key.includes('monto mensual') ||
    key.includes('honorario mensual') ||
    key.includes('honorarios mensuales') ||
    key.includes('sueldo mensual')
  ) return 'honorario_mensual';
  if (key.includes('honorarios')) return 'honorario_mensual';
  if (key.includes('referencial con impuestos') || key.includes('precio referencial') || key.includes('monto referencial')) return 'precio_referencial';
  return 'monto_detectado';
}

function clasificarMontoEnFragmento(fragmento, monto) {
  const raw = String(monto?.raw || '');
  const index = Number(monto?.index || 0);
  const desde = Math.max(0, index - 90);
  const hasta = Math.min(String(fragmento || '').length, index + raw.length + 70);
  const before = claveTexto(String(fragmento || '').slice(Math.max(0, index - 70), index));
  const after = claveTexto(String(fragmento || '').slice(index + raw.length, Math.min(String(fragmento || '').length, index + raw.length + 35)));
  const local = claveTexto(String(fragmento || '').slice(desde, hasta));
  const totalCerca = before.includes('presupuesto total por consultor') ||
    before.includes('presupuesto total asignado') ||
    before.includes('precio referencial') ||
    before.includes('monto referencial') ||
    /\btotal\b/.test(before);
  const mensualCerca = before.includes('presupuesto fijo mensual') ||
    before.includes('monto mensual') ||
    before.includes('honorario mensual') ||
    before.includes('honorarios mensuales') ||
    before.includes('sueldo mensual') ||
    /\bmensual\b/.test(before) ||
    /\bmensual\b/.test(after);

  if (totalCerca) {
    return before.includes('precio referencial') || before.includes('monto referencial')
      ? 'precio_referencial'
      : 'presupuesto_total';
  }

  if (mensualCerca) {
    return 'honorario_mensual';
  }

  if (
    local.includes('presupuesto total por consultor') ||
    local.includes('presupuesto total asignado') ||
    local.includes('monto total') ||
    local.includes('precio referencial') ||
    local.includes('monto referencial')
  ) {
    return local.includes('precio referencial') || local.includes('monto referencial')
      ? 'precio_referencial'
      : 'presupuesto_total';
  }

  const global = clasificarMonto(fragmento);
  if (global === 'honorario_mensual' && !mensualCerca) {
    const fragmentKey = claveTexto(fragmento);
    if (fragmentKey.includes('precio referencial') || fragmentKey.includes('monto referencial')) return 'precio_referencial';
    if (fragmentKey.includes('presupuesto total') || fragmentKey.includes('monto total') || /\btotal\b/.test(local)) return 'presupuesto_total';
    return 'monto_detectado';
  }

  return global;
}

function esLineaMonto(value) {
  return /^\s*(?:bs\.?\s*)?\d{1,3}(?:\.\d{3})*,\d{1,2}\s*$/i.test(limpiarCampo(value));
}

function esLineaItem(value) {
  return /^\d{1,3}$/.test(limpiarCampo(value));
}

function esFragmentoDuracion(value) {
  const key = claveTexto(value);
  return /\bmes(?:es)?\b|\bdia(?:s)?\b|\bdias\b|\bcalendario\b/.test(key);
}

function limpiarDuracionPartes(partes) {
  return limpiarCampo(partes.join(' '))
    .replace(/\s+,/g, ',')
    .replace(/\s+/g, ' ');
}

function esEncabezadoTablaSueldo(value) {
  const key = claveTexto(value);
  return [
    'item',
    'cargo',
    'descripcion',
    'duracion',
    'salario mensual',
    'salario total',
    'presupuesto fijo mensual',
    'presupuesto total por consultor',
    'precio referencial',
  ].includes(key);
}

function esCorteTablaSueldo(value) {
  const key = claveTexto(value);
  return key === 'total' ||
    key.startsWith('total ') ||
    key.includes('la contratacion se formalizara') ||
    key.includes('plazo para la ejecucion') ||
    key.includes('garantia de cumplimiento') ||
    key.includes('organismos financiadores') ||
    key.includes('informacion del documento base') ||
    key.includes('cronograma de plazos') ||
    key.startsWith('seccion ') ||
    key.startsWith('parte ');
}

function claveFamiliaCargo(value) {
  const key = claveTexto(value)
    .replace(/\b(c|p)\s*s\b/g, ' ')
    .replace(/\b(hospital|centro|puesto|establecimiento|unidad|consultorio|movil|municipio|municipal)\b.*$/g, ' ')
    .replace(/\b(soracachi|cachi|huayna|pasto|grande|tholapalca|sepulturas)\b.*$/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  if (key.includes('medico general')) return 'medico general';
  if (key.includes('licenciada') && key.includes('enfermeria')) return 'licenciada enfermeria';
  if (key.includes('licenciado') && key.includes('enfermeria')) return 'licenciado enfermeria';
  if (key.includes('auxiliar') && key.includes('enfermeria')) return 'auxiliar enfermeria';
  if (key.includes('odontologo')) return 'odontologo';
  if (key.includes('bioquimico')) return 'bioquimico';
  if (key.includes('portero') || key.includes('sereno')) return 'portero sereno';

  return key.split(/\s+/).slice(0, 5).join(' ');
}

function esLineaCargoTablaSueldo(value) {
  const limpio = limpiarCampo(value);
  const key = claveTexto(limpio);
  if (!limpio || limpio.length < 4) return false;
  if (esLineaItem(limpio) || esLineaMonto(limpio) || esFragmentoDuracion(limpio)) return false;
  if (esEncabezadoTablaSueldo(limpio) || esCorteTablaSueldo(limpio)) return false;
  if (/^(x|#|nro|no)$/i.test(limpio)) return false;
  if (key.includes('metodo de seleccion') || key.includes('forma de adjudicacion')) return false;
  return /[a-zA-ZÁÉÍÓÚÑáéíóúñ]/.test(limpio);
}

function extraerItemsTablaSalarioMensual(textoWord) {
  const lineas = obtenerLineas(textoWord).map(limpiarCampo).filter(Boolean);
  const resultado = {
    forma_adjudicacion: detectarFormaAdjudicacion(textoWord) || 'POR ITEM',
    total_items: 0,
    sueldo_tipo: 'no_detectado',
    items_detectados: [],
    presupuesto_total_asignado: null,
    presupuesto_total_asignado_texto: null,
  };

  const headerIndex = lineas.findIndex((linea, index) => {
    const ventana = claveTexto(lineas.slice(index, index + 16).join(' '));
    return ventana.includes('item') &&
      ventana.includes('cargo') &&
      ventana.includes('salario mensual') &&
      ventana.includes('salario total');
  });

  if (headerIndex === -1) return resultado;

  let startIndex = headerIndex + 1;
  for (let j = headerIndex; j < Math.min(lineas.length, headerIndex + 18); j += 1) {
    if (claveTexto(lineas[j]).includes('salario total')) {
      startIndex = j + 1;
      break;
    }
  }

  const items = [];
  let pendientes = [];
  let ultimoSueldo = null;
  let ultimaDuracion = null;
  let numeroAuto = 1;

  const agregarPendientes = (sueldoMensual, presupuestoTotal, duracion) => {
    const sueldo = normalizarNumeroSalida(sueldoMensual);
    if (!montoValidoSueldo(sueldo) || !pendientes.length) {
      pendientes = [];
      return;
    }

    for (const pendiente of pendientes) {
      items.push({
        item: numeroAuto,
        descripcion: limpiarCampo(pendiente.descripcion),
        sueldo_mensual: sueldo,
        sueldo_texto: formatearBs(sueldo),
        duracion: limpiarCampo(pendiente.duracion || duracion || ultimaDuracion || '') || null,
        presupuesto_total: presupuestoTotal ? normalizarNumeroSalida(presupuestoTotal) : null,
        presupuesto_total_texto: presupuestoTotal ? formatearBs(presupuestoTotal) : null,
      });
      numeroAuto += 1;
    }

    ultimoSueldo = {
      sueldo_mensual: sueldo,
      presupuesto_total: presupuestoTotal ? normalizarNumeroSalida(presupuestoTotal) : null,
      duracion: limpiarCampo(duracion || ultimaDuracion || '') || null,
    };
    pendientes = [];
  };

  const heredarUltimoSueldo = () => {
    if (!ultimoSueldo || !pendientes.length) return;
    agregarPendientes(
      ultimoSueldo.sueldo_mensual,
      ultimoSueldo.presupuesto_total,
      ultimoSueldo.duracion || ultimaDuracion
    );
  };

  for (let i = startIndex; i < Math.min(lineas.length, startIndex + 180); i += 1) {
    const linea = lineas[i];
    const key = claveTexto(linea);

    if (esCorteTablaSueldo(linea)) break;
    if (esEncabezadoTablaSueldo(linea) || key === 'x' || esLineaItem(linea)) continue;

    if (esFragmentoDuracion(linea) && !esLineaMonto(linea)) {
      ultimaDuracion = linea;
      pendientes = pendientes.map(item => ({ ...item, duracion: item.duracion || linea }));
      continue;
    }

    if (esLineaMonto(linea)) {
      const sueldoMensual = parseMontoBoliviano(linea);
      let presupuestoTotal = null;

      if (i + 1 < lineas.length && esLineaMonto(lineas[i + 1])) {
        presupuestoTotal = parseMontoBoliviano(lineas[i + 1]);
        i += 1;
      }

      agregarPendientes(sueldoMensual, presupuestoTotal, ultimaDuracion);
      continue;
    }

    if (!esLineaCargoTablaSueldo(linea)) continue;

    const familiaNueva = claveFamiliaCargo(linea);
    const familiaPendiente = pendientes.length ? claveFamiliaCargo(pendientes[pendientes.length - 1].descripcion) : '';

    if (pendientes.length && ultimoSueldo && familiaNueva && familiaPendiente && familiaNueva !== familiaPendiente) {
      heredarUltimoSueldo();
    }

    pendientes.push({ descripcion: linea, duracion: ultimaDuracion });
  }

  heredarUltimoSueldo();

  const unicos = new Map();
  for (const item of items) {
    const key = `${claveTexto(item.descripcion)}-${item.sueldo_mensual}`;
    if (!unicos.has(key)) unicos.set(key, item);
  }

  resultado.items_detectados = [...unicos.values()];
  resultado.total_items = resultado.items_detectados.length;
  resultado.sueldo_tipo = resultado.total_items ? 'por_item' : 'no_detectado';

  return resultado;
}

function esLineaMontoProducto(value) {
  return /^\s*(?:bs\.?\s*)?\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?\s*$/i.test(limpiarCampo(value)) ||
    /^\s*(?:bs\.?\s*)?\d{4,}(?:,\d{1,2})?\s*$/i.test(limpiarCampo(value));
}

function esLineaPorcentaje(value) {
  return /^\s*\d{1,3}(?:[,.]\d{1,2})?\s*%\s*$/.test(limpiarCampo(value));
}

function parsePorcentaje(value) {
  const match = limpiarCampo(value).match(/\d{1,3}(?:[,.]\d{1,2})?/);
  if (!match) return null;
  const numero = Number(match[0].replace(',', '.'));
  return Number.isFinite(numero) ? normalizarNumeroSalida(numero) : null;
}

function esLineaPlazoPago(value) {
  const key = claveTexto(value);
  return /\bdia(?:s)?\b|\bdias\b|\bmes(?:es)?\b|\bcalendario\b/.test(key);
}

function esEncabezadoCronogramaPago(value) {
  const key = claveTexto(value);
  return key === 'n' ||
    key === 'nro' ||
    key === 'no' ||
    key === 'producto' ||
    key === 'productos' ||
    key === 'pagos' ||
    key === '%' ||
    key.includes('plazo de presentacion');
}

function limpiarProductoPago(partes) {
  return limpiarCampo(partes
    .filter(parte => parte && !esEncabezadoCronogramaPago(parte))
    .filter(parte => !esLineaItem(parte))
    .join(' '));
}

function extraerMontoContextualProducto(textoWord) {
  const lineas = obtenerLineas(textoWord);
  const candidatos = [];

  for (let i = 0; i < lineas.length; i++) {
    const fragmento = limpiarCampo(lineas.slice(Math.max(0, i - 2), Math.min(lineas.length, i + 4)).join(' '));
    const key = claveTexto(fragmento);
    const esContextoTotal = key.includes('precio referencial') ||
      key.includes('presupuesto referencial') ||
      key.includes('monto total') ||
      key.includes('total para la ejecucion') ||
      key.includes('monto del contrato');

    if (!esContextoTotal || esFragmentoLegal(fragmento)) continue;

    for (const monto of encontrarMontos(fragmento)) {
      if (monto.sueldo_numero >= 1000) candidatos.push(monto.sueldo_numero);
    }
  }

  if (!candidatos.length) return null;
  return normalizarNumeroSalida(Math.max(...candidatos));
}

function extraerFinanciamientoProducto(textoWord) {
  const lineas = obtenerLineas(textoWord).map(limpiarCampo).filter(Boolean);
  const cronograma = [];
  const fragmentos = [];
  let total = null;

  const headerIndex = lineas.findIndex((linea, index) => {
    const keyLinea = claveTexto(linea);
    const ventana = claveTexto(lineas.slice(index, index + 20).join(' '));
    return (keyLinea.includes('forma de pago') || keyLinea.includes('monto y forma de pago')) &&
      ventana.includes('producto') &&
      ventana.includes('pagos') &&
      ventana.includes('plazo');
  });

  if (headerIndex !== -1) {
    let partesProducto = [];
    let startIndex = headerIndex + 1;
    for (let j = headerIndex + 1; j < Math.min(lineas.length, headerIndex + 40); j++) {
      if (claveTexto(lineas[j]).includes('plazo de presentacion')) {
        startIndex = j + 1;
        break;
      }
    }
    const limite = Math.min(lineas.length, headerIndex + 220);

    for (let i = startIndex; i < limite; i++) {
      const linea = lineas[i];
      const key = claveTexto(linea);

      if (
        cronograma.length &&
        (
          key.includes('retencion por cumplimiento') ||
          key === 'multas' ||
          key === 'parte iii' ||
          key.startsWith('anexo ')
        )
      ) {
        break;
      }

      if (esEncabezadoCronogramaPago(linea)) continue;

      if (esLineaMontoProducto(linea) && esLineaPorcentaje(lineas[i + 1] || '') && esLineaPlazoPago(lineas[i + 2] || '')) {
        const producto = limpiarProductoPago(partesProducto);
        const monto = parseMontoBoliviano(linea);
        const porcentaje = parsePorcentaje(lineas[i + 1]);
        const plazo = limpiarCampo(lineas[i + 2]);
        const esTotal = !producto || claveTexto(producto).includes('total') || porcentaje >= 99.9;

        if (esTotal) {
          total = normalizarNumeroSalida(monto);
        } else {
          cronograma.push({
            producto,
            monto: normalizarNumeroSalida(monto),
            monto_texto: formatearBs(monto),
            porcentaje,
            plazo,
          });
          fragmentos.push(`${producto} ${formatearBs(monto)} ${lineas[i + 1]} ${plazo}`);
        }

        partesProducto = [];
        i += 2;
        continue;
      }

      partesProducto.push(linea);
    }
  }

  if (total === null) {
    const sumaCronograma = cronograma.reduce((acc, item) => acc + Number(item.monto || 0), 0);
    total = sumaCronograma ? normalizarNumeroSalida(sumaCronograma) : extraerMontoContextualProducto(textoWord);
  }

  return {
    sueldo: null,
    sueldo_texto: null,
    sueldo_tipo: 'por_producto_total',
    sueldos_detectados: [],
    tipo_financiamiento: 'por_producto',
    cronograma_pagos: cronograma,
    forma_adjudicacion: null,
    total_items: 0,
    items_detectados: [],
    presupuesto_total_asignado: total,
    presupuesto_total_asignado_texto: total ? formatearBs(total) : null,
    precio_referencial: total || 0,
    precio_referencial_texto: total ? formatearBs(total) : 'Bs. 0,00',
    fragmentos_sueldo: [],
    fragmentos_cronograma_pagos: fragmentos.slice(0, 20),
    fragmentos_descartados: [],
  };
}

function detectarFormaAdjudicacion(textoWord) {
  const texto = claveTexto(textoWord);
  if (/forma\s+de\s+adjudicacion.{0,80}por\s+item/.test(texto) || /\bpor\s+item\b/.test(texto)) {
    return 'POR ÍTEM';
  }
  return null;
}

function extraerItemsSueldosPorItem(textoWord) {
  const lineas = obtenerLineas(textoWord).map(limpiarCampo).filter(Boolean);
  const resultado = {
    forma_adjudicacion: detectarFormaAdjudicacion(textoWord),
    total_items: 0,
    sueldo_tipo: 'no_detectado',
    items_detectados: [],
    presupuesto_total_asignado: null,
    presupuesto_total_asignado_texto: null,
  };

  const headerIndex = lineas.findIndex((linea, index) => {
    const ventana = claveTexto(lineas.slice(index, index + 12).join(' '));
    return ventana.includes('item') &&
      ventana.includes('descripcion') &&
      ventana.includes('presupuesto fijo mensual') &&
      ventana.includes('presupuesto total por consultor');
  });

  if (headerIndex === -1) return extraerItemsTablaSalarioMensual(textoWord);

  resultado.forma_adjudicacion = resultado.forma_adjudicacion || 'POR ÍTEM';
  resultado.sueldo_tipo = 'por_item';

  let i = headerIndex + 1;
  while (i < lineas.length && !esLineaItem(lineas[i])) i += 1;

  while (i < lineas.length) {
    const linea = lineas[i];
    const key = claveTexto(linea);

    if (key.includes('presupuesto total asignado')) {
      const montoLinea = lineas.slice(i, i + 4).find(esLineaMonto);
      const monto = parseMontoBoliviano(montoLinea || linea);
      if (monto) {
        resultado.presupuesto_total_asignado = normalizarNumeroSalida(monto);
        resultado.presupuesto_total_asignado_texto = formatearBs(monto);
      }
      break;
    }

    if (!esLineaItem(linea)) {
      i += 1;
      continue;
    }

    const numeroItem = parseInt(linea, 10);
    const descripcionPartes = [];
    i += 1;

    while (i < lineas.length && !esLineaMonto(lineas[i])) {
      const posibleCorte = claveTexto(lineas[i]);
      if (posibleCorte.includes('presupuesto total asignado') || esLineaItem(lineas[i])) break;
      descripcionPartes.push(lineas[i]);
      i += 1;
    }

    if (!descripcionPartes.length || i >= lineas.length || !esLineaMonto(lineas[i])) continue;

    const sueldoMensual = parseMontoBoliviano(lineas[i]);
    i += 1;

    const duracionPartes = [];
    while (i < lineas.length && !esLineaMonto(lineas[i])) {
      const posibleCorte = claveTexto(lineas[i]);
      if (posibleCorte.includes('presupuesto total asignado') || esLineaItem(lineas[i])) break;
      if (esFragmentoDuracion(lineas[i])) duracionPartes.push(lineas[i]);
      i += 1;
    }

    const presupuestoTotal = i < lineas.length && esLineaMonto(lineas[i])
      ? parseMontoBoliviano(lineas[i])
      : 0;
    if (presupuestoTotal) i += 1;

    resultado.items_detectados.push({
      item: numeroItem,
      descripcion: limpiarCampo(descripcionPartes.join(' ')),
      sueldo_mensual: normalizarNumeroSalida(sueldoMensual),
      sueldo_texto: formatearBs(sueldoMensual),
      duracion: limpiarDuracionPartes(duracionPartes) || null,
      presupuesto_total: presupuestoTotal ? normalizarNumeroSalida(presupuestoTotal) : null,
      presupuesto_total_texto: presupuestoTotal ? formatearBs(presupuestoTotal) : null,
    });
  }

  const unicos = new Map();
  for (const item of resultado.items_detectados) {
    if (!unicos.has(item.item)) unicos.set(item.item, item);
  }
  resultado.items_detectados = [...unicos.values()].sort((a, b) => a.item - b.item);
  resultado.total_items = resultado.items_detectados.length;

  if (!resultado.total_items) {
    const tablaSalario = extraerItemsTablaSalarioMensual(textoWord);
    if (tablaSalario.items_detectados.length) return tablaSalario;
    resultado.sueldo_tipo = 'no_detectado';
  }
  return resultado;
}

function extraerSueldosNumericos(textoWord) {
  const itemsPorItem = extraerItemsSueldosPorItem(textoWord);
  if (itemsPorItem.items_detectados.length) {
    const principal = itemsPorItem.items_detectados[0];
    return {
      sueldo: principal.sueldo_mensual,
      sueldo_texto: principal.sueldo_texto,
      sueldo_tipo: itemsPorItem.sueldo_tipo,
      sueldos_detectados: itemsPorItem.items_detectados.map(item => ({
        item: item.item,
        descripcion: item.descripcion,
        sueldo_numero: item.sueldo_mensual,
        sueldo_texto: item.sueldo_texto,
      })),
      forma_adjudicacion: itemsPorItem.forma_adjudicacion,
      total_items: itemsPorItem.total_items,
      items_detectados: itemsPorItem.items_detectados,
      presupuesto_total_asignado: itemsPorItem.presupuesto_total_asignado,
      presupuesto_total_asignado_texto: itemsPorItem.presupuesto_total_asignado_texto,
      precio_referencial: 0,
      precio_referencial_texto: 'Bs. 0,00',
      fragmentos_sueldo: itemsPorItem.items_detectados.slice(0, 10).map(item =>
        `Item ${item.item}: ${item.descripcion} ${item.sueldo_texto} ${item.presupuesto_total_texto || ''}`
      ),
      fragmentos_descartados: [],
    };
  }

  const lineas = obtenerLineas(textoWord);
  const detectados = [];
  const descartados = [];

  for (let i = 0; i < lineas.length; i++) {
    const fragmento = limpiarCampo(lineas.slice(Math.max(0, i - 2), Math.min(lineas.length, i + 4)).join(' '));
    const key = claveTexto(fragmento);
    const hasMoney = /bs\.?\s*\d|\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?/.test(fragmento.toLowerCase());
    const hasContext = key.includes('referencial con impuestos') ||
      key.includes('precio referencial') ||
      key.includes('monto mensual') ||
      key.includes('honorarios');

    if (!hasMoney && !hasContext) continue;

    const montos = encontrarMontos(fragmento);
    if (!montos.length) continue;

    if (esFragmentoLegal(fragmento) && !key.includes('monto mensual') && !key.includes('honorarios')) {
      descartados.push(fragmento);
      continue;
    }

    for (const monto of montos) {
      const item = {
        sueldo_numero: monto.sueldo_numero,
        sueldo_texto: monto.sueldo_texto,
        sueldo_tipo: clasificarMontoEnFragmento(fragmento, monto),
        fragmento,
      };

      if (!detectados.some(d => d.sueldo_numero === item.sueldo_numero && d.sueldo_tipo === item.sueldo_tipo)) {
        detectados.push(item);
      }
    }
  }

  const honorarios = detectados.filter(item => item.sueldo_tipo === 'honorario_mensual');
  const precios = detectados.filter(item => item.sueldo_tipo === 'precio_referencial');
  const principal = honorarios[0] || precios[0] || detectados[0] || {
    sueldo_numero: 0,
    sueldo_texto: 'Bs. 0,00',
    sueldo_tipo: 'no_identificado',
  };

  return {
    sueldo: normalizarNumeroSalida(principal.sueldo_numero),
    sueldo_texto: principal.sueldo_texto,
    sueldo_tipo: principal.sueldo_tipo,
    sueldos_detectados: detectados.map(item => ({
      sueldo_numero: normalizarNumeroSalida(item.sueldo_numero),
      sueldo_texto: item.sueldo_texto,
      sueldo_tipo: item.sueldo_tipo,
    })),
    forma_adjudicacion: detectarFormaAdjudicacion(textoWord),
    total_items: 0,
    items_detectados: [],
    presupuesto_total_asignado: null,
    presupuesto_total_asignado_texto: null,
    precio_referencial: normalizarNumeroSalida((precios[0] || {}).sueldo_numero || 0),
    precio_referencial_texto: (precios[0] || {}).sueldo_texto || 'Bs. 0,00',
    fragmentos_sueldo: detectados.map(item => item.fragmento).slice(0, 10),
    fragmentos_descartados: descartados.slice(0, 20),
  };
}

function montoValidoSueldo(value) {
  const numero = Number(value || 0);
  return Number.isFinite(numero) && numero >= 1000;
}

function montosDistintos(values) {
  return [...new Set((Array.isArray(values) ? values : [])
    .map(value => Number(value || 0))
    .filter(montoValidoSueldo)
    .map(value => normalizarNumeroSalida(value)))];
}

function extraerMontosMensualesDirectos(texto) {
  const limpio = limpiarCampo(texto);
  const patterns = [
    /(?:presupuesto\s+fijo\s+mensual|monto\s+mensual|honorarios?\s+mensuales?|sueldo\s+mensual|salario\s+mensual|pagos?\s+mensuales?|pago\s+mensual|cuotas?\s+(?:parciales\s+)?mensuales?)\D{0,180}(?:bs\.?\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d{4,}(?:,\d{1,2})?)/gi,
    /(?:cuotas?\s+mensuales?\s+(?:ser[aá]n|son|seran|sera|ser[aá]|de|por)\s+de?)\D{0,80}(?:bs\.?\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d{4,}(?:,\d{1,2})?)/gi,
    /(?:^|[\s.;])mensual\s*[:\-]\s*(?:bs\.?\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d{4,}(?:,\d{1,2})?)/gi,
  ];
  const montos = [];

  patterns.forEach(pattern => {
    for (const match of limpio.matchAll(pattern)) {
      const monto = parseMontoBoliviano(match[1]);
      if (montoValidoSueldo(monto)) montos.push(monto);
    }
  });

  return montosDistintos(montos);
}

function extraerPresupuestoTotalContrato(texto) {
  const limpio = limpiarCampo(texto);
  const patterns = [
    /(?:presupuesto\s+total(?:\s+de\s+la\s+consultor[ií]a)?|precio\s+total\s+convenido|monto\s+total\s+del\s+contrato|presupuesto\s+de\s+la\s+consultor[ií]a)\D{0,120}(?:bs\.?\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d{4,}(?:,\d{1,2})?)/gi,
  ];

  for (const pattern of patterns) {
    for (const match of limpio.matchAll(pattern)) {
      const monto = parseMontoBoliviano(match[1]);
      if (!montoValidoSueldo(monto)) continue;
      const inicio = Math.max(0, (match.index || 0) - 80);
      const fin = Math.min(limpio.length, (match.index || 0) + match[0].length + 120);
      return {
        monto: normalizarNumeroSalida(monto),
        texto: formatearBs(monto),
        fragmento: recortarEvidencia(limpio.slice(inicio, fin), 420),
      };
    }
  }

  return null;
}

function extraerPlazoMesesContrato(texto) {
  const limpio = limpiarCampo(texto);
  const patterns = [
    /(?:duraci[oó]n|plazo|tiempo\s+de\s+duraci[oó]n|tiempo\s+de\s+duraci[oó]n\s+del\s+contrato)\D{0,160}(?:\((\d{1,3})\)|(\d{1,3}))\s+mes(?:es)?\b/gi,
    /(?:\((\d{1,3})\)|(\d{1,3}))\s+mes(?:es)?\b/gi,
  ];

  for (const pattern of patterns) {
    for (const match of limpio.matchAll(pattern)) {
      const meses = Number(match[1] || match[2] || 0);
      if (Number.isFinite(meses) && meses > 0 && meses <= 120) {
        const inicio = Math.max(0, (match.index || 0) - 80);
        const fin = Math.min(limpio.length, (match.index || 0) + match[0].length + 120);
        return {
          meses,
          texto: `${meses} meses`,
          fragmento: recortarEvidencia(limpio.slice(inicio, fin), 420),
        };
      }
    }
  }

  return null;
}

function extraerPrimeraCuotaMensual(texto) {
  const lineas = obtenerLineas(texto);
  const linea = lineas.find(item => {
    const key = claveTexto(item);
    return key.includes('primera cuota') && (key.includes('dias trabajados') || key.includes('fecha de firma'));
  });

  return linea ? recortarEvidencia(linea, 320) : '';
}

function contextoMensualContrato(texto, montoMensual) {
  const presupuesto = extraerPresupuestoTotalContrato(texto);
  const plazo = extraerPlazoMesesContrato(texto);
  const primeraCuota = extraerPrimeraCuotaMensual(texto);
  const detalle = [];
  let validacion = null;

  if (presupuesto && plazo) {
    detalle.push(`La consultoria tiene un presupuesto total de ${presupuesto.texto} por un plazo de ${plazo.meses} meses.`);
  } else if (presupuesto) {
    detalle.push(`La consultoria tiene un presupuesto total de ${presupuesto.texto}.`);
  } else if (plazo) {
    detalle.push(`La consultoria tiene un plazo de ${plazo.meses} meses.`);
  }

  detalle.push(`El pago sera mediante cuotas parciales mensuales de ${formatearBs(montoMensual)} cada una.`);

  if (primeraCuota) {
    detalle.push('La primera cuota sera calculada segun los dias trabajados en el mes de inicio del contrato.');
  }

  if (presupuesto && plazo) {
    const calculado = normalizarNumeroSalida(Number(montoMensual) * Number(plazo.meses));
    const diferencia = Math.abs(calculado - Number(presupuesto.monto));
    validacion = {
      cuota_mensual: normalizarNumeroSalida(montoMensual),
      meses: plazo.meses,
      presupuesto_total: presupuesto.monto,
      total_calculado: calculado,
      coincide: diferencia <= 1,
      confianza: diferencia <= 1 ? 'alta' : 'media',
    };
  }

  return {
    detalle: detalle.join(' '),
    presupuesto,
    plazo,
    primera_cuota: primeraCuota,
    validacion,
  };
}

function detalleItemsSueldos(items) {
  return (Array.isArray(items) ? items : [])
    .filter(item => montoValidoSueldo(item?.sueldo_mensual ?? item?.sueldo_numero))
    .map(item => {
      const perfil = limpiarTextoFicha(item.descripcion || item.perfil || `Item ${item.item || ''}`).trim();
      const sueldo = normalizarBsTexto(item.sueldo_texto) || formatearBs(item.sueldo_mensual ?? item.sueldo_numero);
      return limpiarCampo(`${perfil || `Item ${item.item || ''}`}: ${sueldo}`);
    })
    .filter(Boolean);
}

function detalleCronogramaProducto(cronograma, montoTotal) {
  const lineas = [];
  if (montoTotal) lineas.push(`Monto total: ${formatearBs(montoTotal)}`);

  (Array.isArray(cronograma) ? cronograma : []).forEach(pago => {
    const producto = limpiarTextoFicha(pago.producto || 'Producto');
    const porcentaje = pago.porcentaje ? `${pago.porcentaje}%` : '';
    const monto = pago.monto ? formatearBs(pago.monto) : '';
    lineas.push(limpiarCampo(`${producto}: ${porcentaje} ${monto}`));
  });

  return lineas.filter(Boolean);
}

function construirEvidenciaSueldo(textoWord, sueldoInfo) {
  const candidatos = bloquesCandidatosSueldo(textoWord);
  const principal = candidatos[0] || null;
  const fragmento = (sueldoInfo.fragmentos_sueldo || sueldoInfo.fragmentos_cronograma_pagos || [])[0] || '';

  return {
    evidencia_sueldo: principal ? {
      bloque: principal.titulo_bloque,
      bloque_id: principal.id,
      texto: principal.texto,
      puntaje: principal.puntaje,
    } : (fragmento ? {
      bloque: 'Fragmento detectado',
      texto: recortarEvidencia(fragmento),
    } : null),
    bloques_candidatos_sueldo: candidatos,
  };
}

function aplicarReglasDurasSueldo(sueldoInfo, tipoConvocatoria, textoWord) {
  const info = { ...(sueldoInfo || {}) };
  const evidencia = construirEvidenciaSueldo(textoWord, info);
  const items = Array.isArray(info.items_detectados) ? info.items_detectados : [];
  const sueldosDetectados = Array.isArray(info.sueldos_detectados) ? info.sueldos_detectados : [];
  const montosItems = montosDistintos(items.map(item => item.sueldo_mensual ?? item.sueldo_numero));
  const montosMensualesDirectos = extraerMontosMensualesDirectos(textoWord);
  const montosMensualesDetectados = montosDistintos(sueldosDetectados
    .filter(item => item.sueldo_tipo === 'honorario_mensual')
    .map(item => item.sueldo_numero));
  const montosMensuales = montosMensualesDirectos.length ? montosMensualesDirectos : montosMensualesDetectados;
  const montosGenerales = montosDistintos(sueldosDetectados.map(item => item.sueldo_numero));
  const montoTotalProducto = normalizarNumeroSalida(info.presupuesto_total_asignado || info.precio_referencial || 0);

  if (tipoConvocatoria === 'individual_producto' || info.tipo_financiamiento === 'por_producto' || info.sueldo_tipo === 'por_producto_total') {
    const detalle = detalleCronogramaProducto(info.cronograma_pagos, montoTotalProducto);
    return {
      ...info,
      sueldo: 1,
      sueldo_texto: 'Bs. 1',
      sueldo_tipo: 'por_producto',
      sueldo_tipo_detalle: 'por_producto',
      detalle_sueldos: [
        'Pago por producto.',
        ...detalle,
        'Nota: esta convocatoria no tiene sueldo mensual. El campo sueldo se registro como 1.',
      ].join('\n'),
      ...evidencia,
    };
  }

  if (items.length > 1 || montosItems.length > 1) {
    const detalle = detalleItemsSueldos(items);
    return {
      ...info,
      sueldo: 1,
      sueldo_texto: 'Bs. 1',
      sueldo_tipo: 'multiple_items',
      sueldo_tipo_detalle: 'multiple_items',
      detalle_sueldos: [
        'Sueldos por item:',
        ...(detalle.length ? detalle.map(linea => `- ${linea}`) : ['- Ver detalle en el documento adjunto.']),
        'Nota: esta convocatoria contiene varios perfiles/items. El campo sueldo se registro como 1.',
      ].join('\n'),
      ...evidencia,
    };
  }

  if (items.length === 1 && montoValidoSueldo(items[0].sueldo_mensual)) {
    const monto = normalizarNumeroSalida(items[0].sueldo_mensual);
    const contexto = contextoMensualContrato(textoWord, monto);
    return {
      ...info,
      sueldo: monto,
      sueldo_texto: formatearBs(monto),
      sueldo_tipo: 'mensual_unico',
      sueldo_tipo_detalle: 'mensual_unico',
      sueldos_detectados: [{ sueldo_numero: monto, sueldo_texto: formatearBs(monto), sueldo_tipo: 'honorario_mensual' }],
      items_detectados: [{ sueldo_mensual: monto, sueldo_texto: formatearBs(monto) }],
      detalle_sueldos: contexto.detalle || `Sueldo mensual detectado: ${formatearBs(monto)}`,
      presupuesto_total_asignado: contexto.presupuesto?.monto || info.presupuesto_total_asignado || null,
      presupuesto_total_asignado_texto: contexto.presupuesto?.texto || info.presupuesto_total_asignado_texto || null,
      validacion_matematica_sueldo: contexto.validacion,
      ...evidencia,
    };
  }

  if (montosMensuales.length === 1) {
    const monto = montosMensuales[0];
    const contexto = contextoMensualContrato(textoWord, monto);
    return {
      ...info,
      sueldo: monto,
      sueldo_texto: formatearBs(monto),
      sueldo_tipo: 'mensual_unico',
      sueldo_tipo_detalle: 'mensual_unico',
      sueldos_detectados: [{ sueldo_numero: monto, sueldo_texto: formatearBs(monto), sueldo_tipo: 'honorario_mensual' }],
      items_detectados: [{ sueldo_mensual: monto, sueldo_texto: formatearBs(monto) }],
      detalle_sueldos: contexto.detalle || `Sueldo mensual detectado: ${formatearBs(monto)}`,
      presupuesto_total_asignado: contexto.presupuesto?.monto || info.presupuesto_total_asignado || null,
      presupuesto_total_asignado_texto: contexto.presupuesto?.texto || info.presupuesto_total_asignado_texto || null,
      validacion_matematica_sueldo: contexto.validacion,
      ...evidencia,
    };
  }

  if (montosMensuales.length > 1) {
    const detalle = montosMensuales.map(formatearBs);
    return {
      ...info,
      sueldo: 1,
      sueldo_texto: 'Bs. 1',
      sueldo_tipo: 'multiple_items',
      sueldo_tipo_detalle: 'multiple_items',
      sueldos_detectados: montosMensuales.map(monto => ({
        sueldo_numero: monto,
        sueldo_texto: formatearBs(monto),
        sueldo_tipo: 'honorario_mensual',
      })),
      items_detectados: montosMensuales.map(monto => ({
        sueldo_mensual: monto,
        sueldo_texto: formatearBs(monto),
      })),
      detalle_sueldos: [
        'Se detectaron varios montos mensuales posibles:',
        ...[...new Set(detalle)].map(linea => `- ${linea}`),
        'Nota: el campo sueldo se registro como 1 para revision.',
      ].join('\n'),
      ...evidencia,
    };
  }

  if (info.sueldo_tipo === 'precio_referencial' || info.sueldo_tipo === 'presupuesto_total' || (!montosMensuales.length && montosGenerales.length)) {
    const monto = normalizarNumeroSalida(info.precio_referencial || info.presupuesto_total_asignado || montosGenerales[0] || 0);
    return {
      ...info,
      sueldo: 1,
      sueldo_texto: 'Bs. 1',
      sueldo_tipo: 'presupuesto_total',
      sueldo_tipo_detalle: 'presupuesto_total',
      sueldos_detectados: [],
      items_detectados: [],
      detalle_sueldos: [
        monto ? `Solo se detecto presupuesto/precio referencial: ${formatearBs(monto)}.` : 'Solo se detecto presupuesto/precio referencial.',
        'No se identifico sueldo mensual con certeza. El campo sueldo se registro como 1.',
      ].join('\n'),
      ...evidencia,
    };
  }

  return {
    ...info,
    sueldo: 1,
    sueldo_texto: 'Bs. 1',
    sueldo_tipo: 'no_identificado',
    sueldo_tipo_detalle: 'no_identificado',
    sueldos_detectados: [],
    items_detectados: [],
    detalle_sueldos: 'No se identifico sueldo mensual con certeza. El campo sueldo se registro como 1.',
    ...evidencia,
  };
}

function extraerSueldos(lineas) {
  const sueldoInfo = extraerSueldosNumericos(lineas.join('\n'));
  if (sueldoInfo.items_detectados?.length) {
    return sueldoInfo.items_detectados.map(item =>
      `Item ${item.item}: ${item.descripcion} - ${item.sueldo_texto}`
    );
  }
  return sueldoInfo.sueldos_detectados.map(item => item.sueldo_texto);
}

function extraerAreaOProfesion(objetoContratacion, textoWord = '') {
  let objeto = limpiarCampo(objetoContratacion);
  const key = claveTexto(objeto);

  if (key.startsWith('gestion social')) return 'Gestion Social / Firma consultora';
  if (key.startsWith('supervision') || key.startsWith('supervisiones')) return 'Supervision tecnica / Firma consultora';

  objeto = objeto
    .replace(/^consultoria\s+individual\s+de\s+linea\s*:?\s*/i, '')
    .replace(/^consultor\s+en\s+linea\s*/i, '')
    .replace(/\s+equipo\s+prp\s+.*$/i, '')
    .replace(/\s+para\s+la\s+adm\s+.*$/i, '')
    .trim();

  if (claveTexto(objeto).includes('medico de emergencias')) return 'Medico de Emergencias e Internacion';
  return formatearCargo(objeto) || 'No identificado';
}

function formatearCargo(value) {
  const small = new Set(['de', 'del', 'en', 'e', 'y', 'la', 'el', 'los', 'las', 'para']);

  return limpiarCampo(value)
    .split(/\s+/)
    .map((word, index) => {
      const lower = word.toLowerCase();
      if (index > 0 && small.has(lower)) return lower;
      return lower.charAt(0).toUpperCase() + lower.slice(1);
    })
    .join(' ');
}

function detectarPersonalClave(textoWord) {
  const texto = limpiarCampo(textoWord);
  const cargos = [
    { canonico: 'Gerente de Proyecto', variantes: ['Gerente de Proyecto'] },
    { canonico: 'Especialista en Saneamiento Básico', variantes: ['Especialista en Saneamiento Basico', 'Especialista en Saneamiento Básico'] },
    { canonico: 'Especialista en Desarrollo Social y Comunitario', variantes: ['Especialista en Desarrollo Social y Comunitario'] },
    { canonico: 'Especialista Geotécnico', variantes: ['Especialista Geotecnico', 'Especialista Geotécnico'] },
    { canonico: 'Especialista Estructural', variantes: ['Especialista Estructural'] },
    { canonico: 'Especialista Topógrafo', variantes: ['Especialista Topografo', 'Especialista Topógrafo'] },
    { canonico: 'Especialista Económico', variantes: ['Especialista Economico', 'Especialista Económico'] },
    { canonico: 'Especialista Ambiental', variantes: ['Especialista Ambiental'] },
  ];

  return cargos
    .filter(cargo => cargo.variantes.some(variante => claveTexto(texto).includes(claveTexto(variante))))
    .map(cargo => cargo.canonico);
}

function detectarSenales(texto, frases) {
  const key = claveTexto(texto);
  return frases.filter(frase => key.includes(claveTexto(frase)));
}

function clasificarTipoConvocatoria(convocatoria, textoWord = '') {
  if (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL || convocatoria?.source_type === SOURCE_PERSONNEL) {
    return {
      incluir: true,
      tipo: 'requerimiento_personal',
      motivo: 'La publicación pertenece al apartado oficial Requerimientos de Personal.',
      senales_individuales_detectadas: ['requerimiento de personal'],
      senales_empresa_detectadas: [],
      personal_clave_detectado: [],
    };
  }

  const objeto = limpiarCampo(convocatoria?.objetoContratacion || '');
  const texto = limpiarCampo(`${objeto}\n${textoWord}`);
  const objetoKey = claveTexto(objeto);
  const textoKey = claveTexto(texto);
  const textoWordKey = claveTexto(textoWord);
  const personalClaveDetectado = detectarPersonalClave(textoWord);

  const frasesIndividualLinea = [
    'consultoria individual de linea',
    'consultoría individual de línea',
    'consultor en linea',
    'consultor en línea',
    'consultor individual de linea',
    'consultor individual de línea',
  ];

  const frasesIndividualProducto = [
    'consultoria individual por producto',
    'consultoría individual por producto',
    'consultor individual por producto',
    'forma de pago y cronograma',
    'pagos porcentuales',
    'pagos por producto',
    'previa presentacion del producto',
    'previa presentación del producto',
    'cronograma de pagos',
    'entregables',
  ];

  const frasesIndividualGenerica = [
    'consultoria individual',
    'consultoría individual',
    'consultor individual',
    'seleccion de consultor individual',
    'selección de consultor individual',
    'contratacion de consultor individual',
    'contratación de consultor individual',
    'contratacion de un consultor individual',
    'contratación de un consultor individual',
  ];

  // Señales fuertes: casi siempre son procesos para firma/empresa, no para persona natural.
  const frasesEmpresaFuertes = [
    'firmas consultoras elegibles',
    'empresas consultoras elegibles',
    'sociedad accidental',
    'asociacion accidental',
    'asociación accidental',
    'experiencia general de la empresa',
    'experiencia especifica de la empresa',
    'experiencia específica de la empresa',
    'consultora y/o constructora',
    'la empresa consultora debera contar',
    'la empresa consultora deberá contar',
    'la empresa consultora y/o constructora debera contar',
  ];

  // Señales débiles: pueden aparecer como texto plantilla incluso en convocatorias individuales.
  // No deben excluir por sí solas.
  const frasesEmpresaDebiles = [
    'empresa consultora',
    'firma consultora',
    'los consultores interesados deberan proporcionar informacion',
    'los consultores interesados deberán proporcionar información',
    'personal clave',
  ];

  const senalesLinea = detectarSenales(texto, frasesIndividualLinea);
  const senalesProducto = detectarSenales(texto, frasesIndividualProducto);
  const senalesGenerica = detectarSenales(texto, frasesIndividualGenerica);
  const senalesIndividuales = [...new Set([...senalesLinea, ...senalesProducto, ...senalesGenerica])];

  const senalesEmpresaFuertes = detectarSenales(texto, frasesEmpresaFuertes);
  const senalesEmpresaDebiles = detectarSenales(texto, frasesEmpresaDebiles);
  const senalesEmpresa = [...new Set([...senalesEmpresaFuertes, ...senalesEmpresaDebiles])];

  const tienePersonalClaveMultiple = textoWordKey.includes('personal clave') && personalClaveDetectado.length >= 3;

  const objetoProducto = detectarSenales(objeto, [
    'consultoria individual por producto',
    'consultoría individual por producto',
    'consultor individual por producto',
  ]).length > 0;

  const senalesPagoProducto = detectarSenales(texto, [
    'forma de pago y cronograma',
    'pagos porcentuales',
    'pagos por producto',
    'previa presentacion del producto',
    'previa presentación del producto',
    'cronograma de pagos',
  ]);

  const objetoIndividualExplicito = detectarSenales(objeto, frasesIndividualGenerica).length > 0 ||
    /\bconsultor(?:a)?\s+individual\b/i.test(objetoKey);

  const textoIndividualExplicito = senalesGenerica.length > 0 || senalesLinea.length > 0 || objetoProducto;

  const tieneHonorarioMensual = /\b(honorario(?:s)?|monto|pago)\s+mensual\b/.test(textoKey) ||
    textoKey.includes('presupuesto fijo mensual') ||
    textoKey.includes('sueldo mensual');

  const tieneFormularioParticipacion = textoKey.includes('formulario de participacion') ||
    textoKey.includes('formulario de participación');

  const pideCedulaPersona = /fotocopia\s+de\s+c\.?\s*i\.?\b/.test(textoKey) ||
    textoKey.includes('fotocopia de ci') ||
    textoKey.includes('cedula de identidad') ||
    textoKey.includes('cédula de identidad');

  // Títulos como los de ENDE: "Profesional Contable", "Ingeniero Civil Líneas",
  // "Profesional en Adquisiciones". Si además pide CI/Formulario u honorario mensual,
  // se trata como consultor individual aunque el objeto no repita la frase "consultor individual".
  const objetoPareceCargoIndividual = /^(contratacion\s+de\s+(un\s+)?|contratación\s+de\s+(un\s+)?)?(profesional|ingeniero|ingeniera|arquitecto|arquitecta|abogado|abogada|licenciado|licenciada|tecnico|técnico|especialista|contador|contadora|auditor|auditora|economista|medico|médico)\b/.test(objetoKey);

  const evidenciaIndividualOperativa = (tieneFormularioParticipacion && pideCedulaPersona) || tieneHonorarioMensual;
  const pareceIndividualPorCargo = objetoPareceCargoIndividual && evidenciaIndividualOperativa;

  const objetoEmpresaExplicito = detectarSenales(objeto, [
    'empresa consultora',
    'firma consultora',
    'firmas consultoras',
    'empresas consultoras',
    'sociedad accidental',
    'asociacion accidental',
    'asociación accidental',
    'sbcc',
    'sbcc-cf',
  ]).length > 0;

  // Primero: exclusiones fuertes por objeto, salvo que el propio objeto diga consultor individual.
  if (!objetoIndividualExplicito && (
    objetoKey.startsWith('gestion social') ||
    objetoKey.startsWith('supervision') ||
    objetoKey.startsWith('supervisiones') ||
    objetoKey.includes('sbcc') ||
    objetoKey.includes('sbcc-cf') ||
    objetoEmpresaExplicito
  )) {
    return {
      incluir: false,
      tipo: objetoKey.startsWith('gestion social') ? 'gestion_social_empresa' : 'supervision_empresa',
      motivo: 'Objeto corresponde a gestion social/supervision/SBCC o menciona firma/empresa consultora de forma explicita.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  // Segundo: señales individuales fuertes ganan sobre frases plantilla débiles de empresa.
  if (objetoProducto || (senalesProducto.length && senalesPagoProducto.length)) {
    return {
      incluir: true,
      tipo: 'individual_producto',
      motivo: 'Objeto/documento contiene Consultoria Individual por Producto o cronograma de pagos por producto.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  if (senalesLinea.length || (pareceIndividualPorCargo && tieneHonorarioMensual)) {
    return {
      incluir: true,
      tipo: 'individual_linea',
      motivo: senalesLinea.length
        ? 'Objeto/documento contiene Consultoria Individual de Linea.'
        : 'Objeto parece cargo individual y el documento contiene honorario/monto mensual.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  if (objetoIndividualExplicito || textoIndividualExplicito || pareceIndividualPorCargo) {
    return {
      incluir: true,
      tipo: 'individual_generica',
      motivo: objetoIndividualExplicito
        ? 'El objeto indica Consultor Individual.'
        : 'El documento contiene señales suficientes de postulación individual.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  // Tercero: recién aquí se excluye por empresa/firma, cuando no hubo evidencia individual suficiente.
  if (tienePersonalClaveMultiple || senalesEmpresaFuertes.length) {
    return {
      incluir: false,
      tipo: senalesEmpresa.some(s => claveTexto(s).includes('firma')) ? 'firma_consultora' : 'empresa_consultora',
      motivo: tienePersonalClaveMultiple
        ? 'Documento menciona personal clave y multiples cargos tecnicos.'
        : 'Documento contiene señales fuertes de empresa/firma consultora.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  if (objetoKey.includes('consultoria por producto') || objetoKey.includes('consultoría por producto')) {
    return {
      incluir: false,
      tipo: 'empresa_consultora',
      motivo: 'Objeto dice consultoria por producto sin señal explicita de consultor individual.',
      senales_individuales_detectadas: senalesIndividuales,
      senales_empresa_detectadas: senalesEmpresa,
      personal_clave_detectado: personalClaveDetectado,
    };
  }

  return {
    incluir: false,
    tipo: 'desconocido',
    motivo: 'No se detecto una señal explicita/suficiente de convocatoria para persona individual.',
    senales_individuales_detectadas: senalesIndividuales,
    senales_empresa_detectadas: senalesEmpresa,
    personal_clave_detectado: personalClaveDetectado,
  };
}

function extraerAreaProfesiones(convocatoria, lineas) {
  return extraerAreaOProfesion(convocatoria?.objetoContratacion || '', lineas.join('\n'));
}

function fragmentoPorTitulo(textoWord, titulos, maxLineas = 8) {
  const lineas = obtenerLineas(textoWord);
  const index = lineas.findIndex(linea => {
    const key = normalizarClaveSimple(linea);
    return titulos.some(titulo => key === titulo || key.startsWith(`${titulo} `));
  });

  if (index === -1) return '';

  return limpiarCampo(lineas.slice(index, Math.min(lineas.length, index + maxLineas)).join(' '));
}

function extraerLugarTrabajoMejorado(textoWord) {
  const lineas = obtenerLineas(textoWord);
  const lugarPlazo = fragmentoPorTitulo(textoWord, ['lugar y plazo'], 5);

  if (lugarPlazo && !esFragmentoLegal(lugarPlazo)) {
    const ciudad = lugarPlazo.match(/ciudad\s+de\s+([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑáéíóúñ\s]{2,40}?)(?:\s+y\s+necesariamente|\s+\.|,|;|\s+de\s+la\s+misma|\s+el\s+consultor|$)/i);
    if (ciudad) {
      return { valor: limpiarCampo(ciudad[1]), fragmentos: [lugarPlazo] };
    }
  }

  const index = lineas.findIndex(linea => claveTexto(linea).includes('lugar de trabajo'));
  const fragmento = index === -1 ? '' : limpiarCampo(lineas.slice(index, index + 5).join(' '));

  if (/oficina de la abc regional chuquisaca/i.test(fragmento)) {
    return { valor: 'Oficina de la ABC Regional Chuquisaca', fragmentos: [fragmento] };
  }
  if (/abc regional chuquisaca|regional chuquisaca|gerencia regional chuquisaca/i.test(fragmento)) {
    return { valor: 'ABC Regional Chuquisaca', fragmentos: [fragmento] };
  }

  return { valor: fragmento && !esFragmentoLegal(fragmento) ? fragmento.slice(0, 240) : '', fragmentos: fragmento ? [fragmento] : [] };
}

function extraerUbicacionLimpia(textoWord, objetoContratacion, lugarDeTrabajo) {
  const texto = `${objetoContratacion}\n${textoWord}`;
  const key = claveTexto(texto);
  const lugares = [];

  if (claveTexto(lugarDeTrabajo).includes('abc regional chuquisaca')) lugares.push('ABC Regional Chuquisaca');

  for (const place of ['Uspha Uspha', 'Tupiza', 'Potosi', 'Potosí', 'Sijllawiri', 'Chuquisaca', 'Cotoca', 'Vinto', 'Cochabamba', 'Sucre']) {
    if (key.includes(claveTexto(place)) && !lugares.some(x => claveTexto(x) === claveTexto(place))) {
      lugares.push(place);
    }
  }

  if (lugares.length) return { valor: lugares.slice(0, 5).join(', '), fragmentos: [limpiarCampo(lugarDeTrabajo || objetoContratacion)] };

  const regional = obtenerLineas(textoWord).find(linea => /regional\s+chuquisaca|gerencia\s+regional\s+chuquisaca|oficina\s+de\s+la\s+abc/i.test(limpiarCampo(linea)));
  if (regional) return { valor: 'ABC Regional Chuquisaca', fragmentos: [limpiarCampo(regional)] };

  return { valor: 'No identificado', fragmentos: [] };
}

function extraerDuracionMejorada(textoWord) {
  const lineas = obtenerLineas(textoWord);
  const fragmentos = [];
  const fragmentosPrioritarios = [
    fragmentoPorTitulo(textoWord, ['lugar y plazo'], 8),
    fragmentoPorTitulo(textoWord, ['plazo', 'duracion del servicio', 'duracion del contrato', 'tiempo de duracion del contrato'], 6),
  ].filter(Boolean);

  for (const fragmento of fragmentosPrioritarios) {
    if (esFragmentoLegal(fragmento)) continue;
    const plazoMeses = extraerPlazoMesesContrato(fragmento);
    if (plazoMeses) return { valor: plazoMeses.texto, fragmentos: [fragmento] };

    const dias = fragmento.match(/(\d{1,4})\s+d[ií]as?\s+calendario/i);
    if (dias) return { valor: `${Number(dias[1])} dias calendario`, fragmentos: [fragmento] };
  }

  for (let i = 0; i < lineas.length; i++) {
    const fragmento = limpiarCampo(lineas.slice(i, i + 3).join(' '));
    const key = claveTexto(fragmento);
    const hit = /(duracion|plazo de prestacion|plazo del contrato|plazo de ejecucion|dias calendario|meses)/.test(key);
    if (!hit || esFragmentoLegal(fragmento)) continue;
    if (/duracion en horas academicas|institucion, empresa o lugar de trabajo/i.test(fragmento)) continue;
    fragmentos.push(fragmento);
  }

  const mejor = fragmentos.find(f => /dias calendario|meses/i.test(f)) || fragmentos[0] || '';
  const plazoMeses = mejor ? extraerPlazoMesesContrato(mejor) : null;
  if (plazoMeses) return { valor: plazoMeses.texto, fragmentos: fragmentos.slice(0, 8) };

  const dias = mejor.match(/(\d{1,4})\s+d[ií]as?\s+calendario/i);
  if (dias) return { valor: `${Number(dias[1])} dias calendario`, fragmentos: fragmentos.slice(0, 8) };

  return { valor: mejor ? mejor.slice(0, 240) : '', fragmentos: fragmentos.slice(0, 8) };
}

function extraerModalidadPostulacionMejorada(textoWord) {
  const lineas = obtenerLineas(textoWord);
  const fragmentos = [];
  const especificos = [];

  for (let i = 0; i < lineas.length; i++) {
    const linea = limpiarCampo(lineas[i]);
    const fragmento = limpiarCampo(lineas.slice(i, i + 3).join(' '));
    const keyLinea = claveTexto(linea);
    const key = claveTexto(fragmento);
    const tieneCorreoLinea = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i.test(linea);
    const presentacionEspecifica = keyLinea.includes('formulario de participacion podra ser presentado') ||
      keyLinea.includes('hoja de vida podra ser presentada') ||
      keyLinea.includes('hoja de vida podra presentarse') ||
      (keyLinea.includes('podra ser presentada') && (keyLinea.includes('medio fisico') || keyLinea.includes('medio electronico'))) ||
      (keyLinea.includes('por medio fisico') && keyLinea.includes('por medio electronico')) ||
      (tieneCorreoLinea && (keyLinea.includes('presentada') || keyLinea.includes('presentarse') || keyLinea.includes('propuesta') || keyLinea.includes('hoja de vida')));

    if (presentacionEspecifica && !/garantia|descalificacion|adjudicacion|impugnacion/i.test(fragmento)) {
      especificos.push(linea);
      continue;
    }

    const hit = key.includes('presentacion de propuestas') ||
      key.includes('forma de presentacion') ||
      key.includes('modalidad de presentacion') ||
      key.includes('lugar de presentacion') ||
      key.includes('www.sicoes.gob.bo') ||
      key.includes('rupe');

    if (!hit || esFragmentoLegal(fragmento)) continue;
    if (/garantia|descalificacion|adjudicacion|impugnacion/i.test(fragmento)) continue;
    fragmentos.push(fragmento);
  }

  if (especificos.length) {
    const puntuar = value => {
      const key = claveTexto(value);
      let score = 0;
      if (key.includes('formulario de participacion')) score += 12;
      if (key.includes('hoja de vida')) score += 10;
      if (key.includes('medio fisico')) score += 8;
      if (key.includes('medio electronico')) score += 8;
      if (/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i.test(value)) score += 6;
      if (key.includes('presupuesto total')) score -= 10;
      if (key.includes('garantia')) score -= 10;
      return score;
    };
    especificos.sort((a, b) => puntuar(b) - puntuar(a));

    return {
      valor: recortarEvidencia(especificos[0], 520),
      fragmentos: especificos.slice(0, 8),
    };
  }

  const digital = fragmentos.find(f => /rupe|www\.sicoes\.gob\.bo|electronica/i.test(f));
  return {
    valor: digital ? digital.slice(0, 240) : 'De manera digital a traves del RUPE en www.sicoes.gob.bo',
    fragmentos: fragmentos.slice(0, 8),
  };
}

function extraerDatosClave(convocatoria, textoWord, debug = null, tipoConvocatoria = '', textoCompletoWord = '') {
  const textoBase = textoCompletoWord || textoWord;
  const tdr = textoTerminosReferenciaPrioritario(textoBase);
  const textoPrioritario = tdr.usado ? tdr.texto : textoBase;
  const textoSueldo = textoPrioritario;
  const sueldoExtraido = tipoConvocatoria === 'individual_producto'
    ? extraerFinanciamientoProducto(textoSueldo)
    : extraerSueldosNumericos(textoSueldo);
  const sueldoInfo = aplicarReglasDurasSueldo(sueldoExtraido, tipoConvocatoria, textoSueldo);
  const textoProfesiones = textoPrioritario;
  const candidatosProfesion = bloquesCandidatosProfesion(textoProfesiones);
  const lugar = extraerLugarTrabajoMejorado(textoPrioritario);
  const ubicacion = extraerUbicacionLimpia(textoBase, convocatoria?.objetoContratacion || '', lugar.valor);
  const duracion = extraerDuracionMejorada(textoPrioritario);
  const modalidad = extraerModalidadPostulacionMejorada(textoBase);

  if (debug) {
    debug.terminos_referencia_prioritarios = tdr.usado ? {
      inicio_linea: tdr.inicio_linea,
      fin_linea: tdr.fin_linea,
      texto: recortarEvidencia(tdr.texto, 1200),
    } : null;
    debug.fragmentos_sueldo = sueldoInfo.fragmentos_sueldo;
    debug.fragmentos_cronograma_pagos = sueldoInfo.fragmentos_cronograma_pagos || [];
    debug.bloques_candidatos_profesion = candidatosProfesion;
    debug.bloques_candidatos_sueldo = sueldoInfo.bloques_candidatos_sueldo || [];
    debug.evidencia_sueldo = sueldoInfo.evidencia_sueldo || null;
    debug.fragmentos_ubicacion = [...lugar.fragmentos, ...ubicacion.fragmentos].filter(Boolean);
    debug.fragmentos_duracion = duracion.fragmentos;
    debug.fragmentos_descartados_legales = sueldoInfo.fragmentos_descartados;
  }

  return {
    areaProfesiones: extraerAreaOProfesion(convocatoria?.objetoContratacion || '', textoProfesiones),
    bloquesCandidatosProfesion: candidatosProfesion,
    ubicacion: ubicacion.valor,
    sueldo: sueldoInfo.sueldo,
    sueldoTexto: sueldoInfo.sueldo_texto,
    sueldoTipo: sueldoInfo.sueldo_tipo,
    sueldoTipoDetalle: sueldoInfo.sueldo_tipo_detalle,
    detalleSueldos: sueldoInfo.detalle_sueldos,
    evidenciaSueldo: sueldoInfo.evidencia_sueldo,
    bloquesCandidatosSueldo: sueldoInfo.bloques_candidatos_sueldo || [],
    sueldosDetectados: sueldoInfo.sueldos_detectados,
    tipoFinanciamiento: sueldoInfo.tipo_financiamiento || '',
    cronogramaPagos: sueldoInfo.cronograma_pagos || [],
    formaAdjudicacion: sueldoInfo.forma_adjudicacion,
    totalItems: sueldoInfo.total_items,
    itemsDetectados: sueldoInfo.items_detectados,
    presupuestoTotalAsignado: sueldoInfo.presupuesto_total_asignado,
    presupuestoTotalAsignadoTexto: sueldoInfo.presupuesto_total_asignado_texto,
    precioReferencial: sueldoInfo.precio_referencial,
    precioReferencialTexto: sueldoInfo.precio_referencial_texto,
    validacionMatematicaSueldo: sueldoInfo.validacion_matematica_sueldo,
    lugarTrabajo: lugar.valor,
    duracionContrato: duracion.valor,
    modalidadPostulacion: modalidad.valor,
  };
}

function normalizarFuente(fuente) {
  const value = String(fuente || '').trim();

  if (!value) return 'https://www.sicoes.gob.bo';
  if (/^https?:\/\//i.test(value)) return value;
  if (value.startsWith('/')) return `https://www.sicoes.gob.bo${value}`;

  return value;
}

function crearRegistroFinal({ numero, estado, convocatoria, docx, textoWord = '', textoCompletoWord = '', textoPath = '', descripcionPath = '', error = '', debug = null, clasificacion = null }) {
  const datos = extraerDatosClave(convocatoria, textoWord, debug, clasificacion?.tipo || '', textoCompletoWord);

  return {
    incluido: clasificacion ? clasificacion.incluir : true,
    tipo_convocatoria_detectado: clasificacion?.tipo || '',
    motivo_clasificacion: clasificacion?.motivo || '',
    fecha_publicacion: repararMojibake(convocatoria?.fechaPublicacion || ''),
    objeto_contratacion: limpiarCampo(convocatoria?.objetoContratacion || ''),
    entidad: limpiarCampo(convocatoria?.entidad || ''),
    area_o_profesiones_que_buscan: datos.areaProfesiones || '',
    ubicacion: datos.ubicacion || '',
    fecha_expiracion: repararMojibake(convocatoria?.fechaPresentacion || ''),
    sueldo: datos.sueldo === null ? null : (datos.sueldo || 0),
    sueldo_texto: datos.sueldoTexto === null ? null : (datos.sueldoTexto || 'Bs. 0,00'),
    sueldo_tipo: datos.sueldoTipo || 'no_identificado',
    sueldo_tipo_detalle: datos.sueldoTipoDetalle || datos.sueldoTipo || 'no_identificado',
    detalle_sueldos: datos.detalleSueldos || '',
    evidencia_sueldo: datos.evidenciaSueldo || null,
    bloques_candidatos_sueldo: datos.bloquesCandidatosSueldo || [],
    sueldos_detectados: datos.sueldosDetectados || [],
    tipo_financiamiento: datos.tipoFinanciamiento || '',
    cronograma_pagos: datos.cronogramaPagos || [],
    forma_adjudicacion: datos.formaAdjudicacion || '',
    total_items: datos.totalItems || 0,
    items_detectados: datos.itemsDetectados || [],
    presupuesto_total_asignado: datos.presupuestoTotalAsignado || null,
    presupuesto_total_asignado_texto: datos.presupuestoTotalAsignadoTexto || null,
    validacion_matematica_sueldo: datos.validacionMatematicaSueldo || null,
    precio_referencial: datos.precioReferencial || 0,
    precio_referencial_texto: datos.precioReferencialTexto || 'Bs. 0,00',
    archivo: docx?.name || '',
    lugar_de_trabajo: datos.lugarTrabajo || '',
    duracion_del_contrato: datos.duracionContrato || '',
    modalidad_de_postulacion: datos.modalidadPostulacion || '',
    cuce: convocatoria?.cuce || '',
    fuente: normalizarFuente(convocatoria?.ficha),
    bloques_candidatos_profesion: datos.bloquesCandidatosProfesion || [],
    texto_completo_word: textoCompletoWord || textoWord || '',
  };
}

function primerValor(...values) {
  return values.find(value => value !== undefined && value !== null && value !== '');
}

function limpiarListaItemsFicha(items) {
  return (Array.isArray(items) ? items : [])
    .filter(item => item && (item.descripcion || item.sueldo_mensual || item.sueldo_numero))
    .filter(item => !item.sueldo_tipo || ['honorario_mensual', 'mensual_unico'].includes(item.sueldo_tipo))
    .filter(item => montoValidoSueldo(item.sueldo_mensual ?? item.sueldo_numero))
    .map(item => ({
      item: item.item ?? null,
      descripcion: limpiarTextoFicha(item.descripcion || ''),
      sueldo_mensual: primerValor(item.sueldo_mensual, item.sueldo_numero, null),
      sueldo_texto: normalizarBsTexto(item.sueldo_texto),
      duracion: valorCortoFicha(item.duracion),
    }))
    .map(quitarCamposVacios);
}

function modalidadNormalizada(raw) {
  const tipo = raw?.tipo_convocatoria_detectado || '';
  if (tipo === 'individual_producto') return 'Por producto';
  if (tipo === 'individual_item') return 'Por item';
  if (tipo === 'individual_linea') return 'Linea';
  return tipo || null;
}

function limpiarTextoFicha(value) {
  return repararMojibake(String(value || ''))
    .replace(/[×•●▪▫]/g, ' ')
    .replace(/\bHYPERLINK\b/gi, ' ')
    .replace(/\bCONTENIDO\b/gi, ' ')
    .replace(/_Toc\d+/gi, ' ')
    .replace(/\r?\n+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function limpiarTextoMultilineaFicha(value) {
  return repararMojibake(String(value || ''))
    .split(/\r?\n+/)
    .map(linea => limpiarTextoFicha(linea))
    .filter(Boolean)
    .join('\n');
}

function normalizarBsTexto(value) {
  const limpio = limpiarTextoFicha(value);
  if (!limpio) return null;
  return limpio
    .replace(/^bs[,.\s]*/i, 'Bs. ')
    .replace(/\s+/g, ' ')
    .trim();
}

function quitarCamposVacios(obj) {
  return Object.fromEntries(Object.entries(obj).filter(([, value]) => {
    if (value === undefined || value === null || value === '') return false;
    if (Array.isArray(value) && !value.length) return false;
    return true;
  }));
}

function esRuidoFicha(value) {
  const key = claveTexto(value);
  if (!key) return true;
  if (key.length > 220) return true;
  return [
    'no identificado',
    'institucion, empresa o lugar de trabajo objeto del trabajo cargo ocupado',
    'descripcion presupuesto fijo mensual por consultor',
    'la propuesta debera tener una validez',
    'preparacion de propuestas',
    'formulario de presentacion de propuesta',
    'acto de apertura',
    'impulsa tu futuro profesional',
    'verificada por el equipo',
    'descarga todos los detalles',
    'fuente:',
    'www.sicoes.gob.bo',
    'documento base de contratacion',
    'terminos de referencia',
  ].some(pattern => key.includes(pattern));
}

function valorLimpioFicha(value) {
  const limpio = limpiarTextoFicha(value || '');
  return limpio && !esRuidoFicha(limpio) ? limpio : null;
}

function valorCortoFicha(value) {
  const limpio = valorLimpioFicha(value);
  if (!limpio) return null;
  if (limpio.split(/\s+/).length > 16) return null;
  return limpio;
}

function lugarTrabajoFicha(raw) {
  let lugar = valorLimpioFicha(raw?.lugar_de_trabajo);
  if (lugar) {
    lugar = lugar
      .replace(/^lugar\s+(?:de\s+)?(?:del\s+)?trabajo[:\s-]*/i, '')
      .replace(/\s+plazo\b.*$/i, '')
      .trim();
    if (lugar.split(/\s+/).length > 18) lugar = null;
  }
  return lugar || valorLimpioFicha(raw?.ubicacion);
}

function limpiarTextoAreaDesdeObjeto(value) {
  return limpiarTextoFicha(value || '')
    // Quita codigos de programa/proceso que no son profesion: (BO-L1190), (EMH-LQ-2026), etc.
    .replace(/\([a-z]{1,6}(?:[-\s]?[a-z0-9]{1,8}){1,5}\)/gi, ' ')
    .replace(/\bbo[-\s]*l\d+\b/gi, ' ')
    .replace(/\bemh[-\s]*lq[-\s]*\d+\b/gi, ' ')
    .replace(/\bc[uú]ce\b.*$/i, ' ')
    .replace(/\bdel\s+programa\s+de\s+expansi[oó]n\s+de\s+infraestructura\s+el[eé]ctrica\b.*$/i, ' ')
    .replace(/\bprograma\s+de\s+expansi[oó]n\s+de\s+infraestructura\s+el[eé]ctrica\b.*$/i, ' ')
    .replace(/\bgesti[oó]n\s+\d{4}\b/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function esBasuraAreaProfesional(value) {
  const limpio = limpiarTextoFicha(value || '');
  const key = normalizarBusqueda(limpio);
  if (!key || key.length < 3) return true;
  if (/^\d+$/.test(key)) return true;
  if (/^[a-z]{0,6}\s*\d+[a-z0-9\s-]*$/.test(key)) return true;
  if (/^l\d+$/.test(key) || /^bo\s*l\d+$/.test(key)) return true;
  if (/\b(?:cuce|sicoes|lq|bo l|programa de expansion|infraestructura electrica)\b/.test(key)) return true;
  if (/^(del|de|la|el|los|las|para|por|producto|linea|gestion|proyecto|programa)$/.test(key)) return true;
  return false;
}

function limpiarItemAreaProfesional(value) {
  let item = limpiarTextoAreaDesdeObjeto(value)
    .replace(/^contrataci[oó]n\s+de\s+(?:uno|una|dos|tres|cuatro|cinco|\d+)?\s*/i, '')
    .replace(/^consultores?\s+individual(?:es)?\s+(?:de\s+linea|de\s+l[ií]nea|por\s+producto)?\s*/i, '')
    .replace(/^consultor(?:es)?\s+individual(?:es)?\s+(?:de\s+linea|de\s+l[ií]nea|por\s+producto)?\s*/i, '')
    .replace(/^consultor\s+t[eé]cnico\s+[ivx]+\s*/i, '')
    .replace(/^especialista\s+/i, '')
    .replace(/^de\s+(?:linea|l[ií]nea)\s+/i, '')
    .replace(/^por\s+producto\s+/i, '')
    .replace(/["“”]/g, '')
    .replace(/\s+/g, ' ')
    .trim();

  item = item.replace(/\s+(?:para|del?|de\s+la)\s+(?:la\s+)?(?:iniciativa|programa|proyecto|gerencia|unidad|direcci[oó]n)\b.*$/i, '').trim();

  if (esBasuraAreaProfesional(item)) return null;
  if (item.split(/\s+/).length > 10) return null;
  return item;
}


function areaProfesionalFicha(raw) {
  const tempFicha = {
    titulo_convocatoria: raw?.objeto_contratacion || '',
    descripcion: {},
  };

  // Desde esta versión el campo area_profesional solo muestra carreras/profesiones
  // normalizadas y registradas en el catálogo, no frases del DBC.
  const explicitos = extraerTerminosProfesionalesExplicitos(raw, tempFicha)
    .map(limpiarItemAreaProfesional)
    .filter(Boolean);

  return [...new Set(explicitos)].slice(0, 12);
}
function sueldoFicha(tipo, monto, mostrar) {
  return quitarCamposVacios({
    tipo,
    monto_total: monto ?? null,
    mostrar: mostrar ? 1 : 0,
  });
}

function pagosFicha(pagos) {
  return (Array.isArray(pagos) ? pagos : [])
    .map(pago => quitarCamposVacios({
      producto: limpiarTextoFicha(pago.producto || ''),
      monto: pago.monto ?? null,
      porcentaje: pago.porcentaje ?? null,
      plazo: valorCortoFicha(pago.plazo),
    }))
    .filter(pago => pago.producto && pago.monto);
}

const dataJsonCache = new Map();

function cargarJsonData(fileName) {
  const filePath = path.join(DATA_DIR, fileName);
  if (dataJsonCache.has(filePath)) return dataJsonCache.get(filePath);

  try {
    const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    dataJsonCache.set(filePath, data);
    return data;
  } catch (error) {
    dataJsonCache.set(filePath, {});
    return {};
  }
}

function normalizarBusqueda(value) {
  return limpiarTextoFicha(value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function contieneTerminoNormalizado(texto, termino) {
  if (!texto || !termino) return false;
  const safe = termino.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(^|\\s)${safe}(\\s|$)`).test(texto);
}


function lineasDocumentoParaBusqueda(raw, ficha) {
  return obtenerLineas([
    raw?.texto_completo_word,
    raw?.lugar_de_trabajo,
    raw?.ubicacion,
    raw?.area_o_profesiones_que_buscan,
    ficha?.descripcion?.lugar_trabajo,
    ficha?.titulo_convocatoria,
  ].filter(Boolean).join('\n'));
}

function fragmentosContextuales(lineas, criterios, antes = 1, despues = 4, limite = 30) {
  const relevantes = new Set();

  lineas.forEach((linea, index) => {
    const key = claveTexto(linea);
    const hit = criterios.some(term => key.includes(claveTexto(term)));
    if (!hit) return;

    for (let i = Math.max(0, index - antes); i <= Math.min(lineas.length - 1, index + despues); i++) {
      const limpio = limpiarCampo(lineas[i]);
      if (limpio && !esFragmentoLegal(limpio)) relevantes.add(limpio);
    }
  });

  return [...relevantes].slice(0, limite);
}

function agregarCandidatoMunicipio(candidatos, municipio, departamento) {
  const termino = normalizarBusqueda(municipio);
  if (!termino) return;
  const key = `${normalizarBusqueda(municipio)}|${normalizarBusqueda(departamento)}`;
  if (candidatos.some(item => item.key === key)) return;

  candidatos.push({
    key,
    municipio,
    departamento,
    termino,
    esNombreDepartamento: normalizarBusqueda(municipio) === normalizarBusqueda(departamento),
  });
}

function candidatosMunicipiosBolivia() {
  const catalogo = cargarJsonData('bolivia_municipios.json');
  const candidatos = [];

  Object.entries(catalogo || {}).forEach(([departamento, municipios]) => {
    (Array.isArray(municipios) ? municipios : []).forEach(municipio => {
      agregarCandidatoMunicipio(candidatos, municipio, departamento);
    });
  });

  // Fallback mínimo por si falta el archivo data/bolivia_municipios.json.
  [
    ['La Paz', 'La Paz'],
    ['El Alto', 'La Paz'],
    ['Santa Cruz de la Sierra', 'Santa Cruz'],
    ['Cochabamba', 'Cochabamba'],
    ['Sucre', 'Chuquisaca'],
    ['Oruro', 'Oruro'],
    ['Potosí', 'Potosí'],
    ['Tarija', 'Tarija'],
    ['Trinidad', 'Beni'],
    ['Cobija', 'Pando'],
  ].forEach(([municipio, departamento]) => agregarCandidatoMunicipio(candidatos, municipio, departamento));

  candidatos.sort((a, b) => {
    if (a.esNombreDepartamento !== b.esNombreDepartamento) return a.esNombreDepartamento ? 1 : -1;
    return b.termino.length - a.termino.length;
  });

  return candidatos;
}

function detectarMunicipioEnFuentes(fuentes, candidatos) {
  for (const fuenteRaw of fuentes) {
    const fuente = normalizarBusqueda(fuenteRaw);
    if (!fuente) continue;

    const encontrado = candidatos.find(candidato => contieneTerminoNormalizado(fuente, candidato.termino));
    if (encontrado) {
      return {
        municipio: encontrado.municipio,
        departamento: encontrado.departamento,
      };
    }
  }

  return null;
}

function extraerFragmentosUbicacionDocumento(raw, ficha) {
  const lineas = lineasDocumentoParaBusqueda(raw, ficha);
  const criterios = [
    'lugar de trabajo',
    'lugar del trabajo',
    'lugar de prestación',
    'lugar de prestacion',
    'lugar de ejecución',
    'lugar de ejecucion',
    'lugar del servicio',
    'ubicación',
    'ubicacion',
    'dirección',
    'direccion',
    'municipio',
    'ciudad',
    'sede',
    'oficina',
    'oficinas',
    'regional',
    'distrital',
  ];

  return fragmentosContextuales(lineas, criterios, 1, 5, 40);
}

function detectarMunicipioBolivia(raw, ficha) {
  const candidatos = candidatosMunicipiosBolivia();
  if (!candidatos.length) return null;

  const fuentesPrioritarias = [
    raw?.lugar_de_trabajo,
    raw?.ubicacion,
    ficha?.descripcion?.lugar_trabajo,
    ...extraerFragmentosUbicacionDocumento(raw, ficha),
  ].filter(Boolean);

  const directo = detectarMunicipioEnFuentes(fuentesPrioritarias, candidatos);
  if (directo) return directo;

  // Último recurso: objeto/título. Esto ayuda en obras o servicios donde la zona está en el nombre,
  // pero se deja al final para no confundir entidad o objeto con lugar real de prestación.
  return detectarMunicipioEnFuentes([
    raw?.objeto_contratacion,
    ficha?.titulo_convocatoria,
  ].filter(Boolean), candidatos);
}

function esEncabezadoFormacionProfesional(value) {
  const key = claveTexto(value);
  if (!key) return false;

  return /\bformacion\s*:?$/.test(key) ||
    key.includes('formacion academica') ||
    key.includes('formacion profesional') ||
    key.includes('formacion requerida') ||
    key.includes('formacion minima') ||
    key.includes('formacion minimo habilitante') ||
    key.includes('formacion academica requisito minimo habilitante') ||
    key.includes('requisito minimo habilitante') ||
    key.includes('requisito minimo de formacion') ||
    key.includes('profesion requerida') ||
    key.includes('profesion solicitada') ||
    key.includes('area de formacion') ||
    key.includes('perfil profesional') ||
    key.includes('titulo profesional') ||
    key.includes('titulo en provision nacional') ||
    key.includes('licenciatura en') ||
    key.includes('licenciado en') ||
    key.includes('licenciada en') ||
    key.includes('carrera de');
}

function esCorteBloqueFormacion(value) {
  const key = claveTexto(value);
  if (!key) return false;
  if (esEncabezadoFormacionProfesional(value)) return false;

  return /^(experiencia|experiencia general|experiencia especifica|experiencia específica|postgrado|post grado|cursos?|conocimientos?|habilidades?|competencias?|funciones?|actividades?|responsabilidades?|objetivo|alcance|productos?|lugar de trabajo|plazo|duracion|duración|honorarios?|monto|forma de pago|cronograma|documentos?|presentacion|presentación|condiciones adicionales|otros requisitos|requisitos generales)\b/.test(key) ||
    key === 'requisitos' ||
    key === 'condiciones minimas' ||
    key === 'condiciones mínimas';
}

function extraerFragmentosFormacionProfesional(raw, ficha) {
  const candidatos = bloquesCandidatosProfesion(raw?.texto_completo_word || raw?.texto_word || '');
  if (!candidatos.length) return [];

  const lineas = candidatos
    .flatMap(candidato => obtenerLineas(candidato.texto))
    .map(limpiarCampo)
    .filter(Boolean);
  const relevantes = new Set();

  for (let i = 0; i < lineas.length; i++) {
    if (!esEncabezadoFormacionProfesional(lineas[i])) continue;

    const bloque = [];
    for (let j = i; j < Math.min(lineas.length, i + 16); j++) {
      const linea = limpiarCampo(lineas[j]);
      if (!linea || esFragmentoLegal(linea)) continue;
      if (j > i && esCorteBloqueFormacion(linea)) break;
      bloque.push(linea);
    }

    const fragmento = limpiarCampo(bloque.join(' '));
    if (fragmento) relevantes.add(fragmento);
  }

  const criterios = [
    'licenciatura en',
    'licenciado en',
    'licenciada en',
    'titulo en provision nacional',
    'título en provisión nacional',
    'titulo profesional',
    'título profesional',
    'formacion academica',
    'formación académica',
    'formacion profesional',
    'formación profesional',
    'formacion:',
    'formación:',
    'formacion requerida',
    'formación requerida',
    'formacion minima',
    'formación mínima',
    'requisito minimo habilitante',
    'requisito mínimo habilitante',
    'grado academico',
    'grado académico',
    'profesion requerida',
    'profesión requerida',
    'profesional en',
    'perfil profesional',
    'area de formacion',
    'área de formación',
    'provision nacional',
    'provisión nacional',
    'ramas afines',
  ];

  fragmentosContextuales(lineas, criterios, 1, 7, 80)
    .forEach(fragmento => relevantes.add(fragmento));

  return [...relevantes].slice(0, 80);
}
function limpiarTerminoProfesion(value) {
  return limpiarTextoFicha(value || '')
    .replace(/^[\-–—:;,.\s]+/g, '')
    .replace(/[\-–—:;,.\s]+$/g, '')
    .replace(/^(?:formaci[oó]n\s*)[:\-]?\s*/i, '')
    .replace(/^formaci[oó]n\s+(?:acad[eé]mica|profesional|requerida|minima|m[ií]nima)(?:\s+requisito\s+m[ií]nimo\s+habilitante)?\s*[:\-]?\s*/i, '')
    .replace(/^requisito\s+m[ií]nimo\s+habilitante\s*[:\-]?\s*/i, '')
    .replace(/^nivel\s+(?:de\s+)?licenciatura\s+(?:en\s+)?/i, '')
    .replace(/^licenciatura\s+en\s+/i, '')
    .replace(/^licenciado\s+en\s+/i, '')
    .replace(/^licenciada\s+en\s+/i, '')
    .replace(/^profesional\s+en\s+/i, '')
    .replace(/^carrera\s+de\s+/i, '')
    .replace(/^t[eé]cnico\s+superior\s+en\s+/i, '')
    .replace(/^t[ií]tulo\s+(?:profesional\s+)?(?:en\s+)?/i, '')
    .replace(/\bt[ií]tulo\s+en\s+provisi[oó]n\s+nacional\b/gi, '')
    .replace(/\bt[ií]tulo\s+profesional\b/gi, '')
    .replace(/\bprovisi[oó]n\s+nacional\b/gi, '')
    .replace(/\bnivel\s+licenciatura\b/gi, '')
    .replace(/\b(?:y\/o|o)\s+ramas?\s+afines?\b/gi, '')
    .replace(/\bramas?\s+afines?\b/gi, '')
    .replace(/\bpara\s+la\s+verificaci[oó]n\b.*$/i, '')
    .replace(/\badjuntar\b.*$/i, '')
    .replace(/\bfotocopia\b.*$/i, '')
    .replace(/\bpost\s*grado\b.*$/i, '')
    .replace(/\bpostgrado\b.*$/i, '')
    .replace(/\bexperiencia\b.*$/i, '')
    .replace(/\bcondiciones\s+adicionales\b.*$/i, '')
    .replace(/\bregistro\s+profesional\b.*$/i, '')
    .replace(/\bmatr[ií]cula\s+profesional\b.*$/i, '')
    .replace(/\s+/g, ' ')
    .trim();
}
function expandirSinonimosProfesion(termino) {
  const norm = normalizarBusqueda(termino);
  const sinonimos = new Set([termino]);

  // Solo equivalencias directas de carrera/cargo. No agrega areas completas.
  const reglas = [
    { hit: 'economia', add: ['Economía', 'Economista'] },
    { hit: 'economista', add: ['Economía', 'Economista'] },
    { hit: 'administracion de empresas', add: ['Administración de Empresas'] },
    { hit: 'administrador de empresas', add: ['Administración de Empresas'] },
    { hit: 'auditoria financiera', add: ['Auditoría Financiera', 'Auditoría'] },
    { hit: 'auditoria', add: ['Auditoría Financiera', 'Auditoría'] },
    { hit: 'auditor', add: ['Auditoría Financiera', 'Auditoría'] },
    { hit: 'contaduria publica', add: ['Contaduría Pública', 'Contabilidad', 'Auditoría'] },
    { hit: 'contador publico', add: ['Contaduría Pública', 'Contabilidad', 'Auditoría'] },
    { hit: 'contable', add: ['Contaduría Pública', 'Contabilidad', 'Auditoría'] },
    { hit: 'ingenieria financiera', add: ['Ingeniería Financiera'] },
    { hit: 'ingenieria comercial', add: ['Ingeniería Comercial'] },
    { hit: 'matematicas', add: ['Matemáticas', 'Matemática'] },
    { hit: 'matematica', add: ['Matemáticas', 'Matemática'] },
    { hit: 'ingenieria civil', add: ['Ingeniería Civil'] },
    { hit: 'ingeniero civil', add: ['Ingeniería Civil'] },
    { hit: 'ingenieria electrica', add: ['Ingeniería Eléctrica'] },
    { hit: 'ingeniero electrico', add: ['Ingeniería Eléctrica'] },
    { hit: 'ingenieria electronica', add: ['Ingeniería Electrónica'] },
    { hit: 'ingeniero electronico', add: ['Ingeniería Electrónica'] },
    { hit: 'ingenieria electromecanica', add: ['Ingeniería Electromecánica'] },
    { hit: 'ingeniero electromecanico', add: ['Ingeniería Electromecánica'] },
    { hit: 'ingenieria mecanica', add: ['Ingeniería Mecánica'] },
    { hit: 'ingenieria industrial', add: ['Ingeniería Industrial'] },
    { hit: 'ingenieria de sistemas', add: ['Ingeniería de Sistemas'] },
    { hit: 'ingenieria informatica', add: ['Ingeniería Informática'] },
    { hit: 'derecho', add: ['Derecho'] },
    { hit: 'abogado', add: ['Derecho'] },
  ];

  reglas.forEach(regla => {
    if (norm.includes(regla.hit)) regla.add.forEach(item => sinonimos.add(item));
  });

  return [...sinonimos]
    .map(limpiarTerminoProfesion)
    .filter(item => item && normalizarBusqueda(item).length >= 4);
}

function textoFormacionCatalogo(raw, ficha) {
  const fragmentos = extraerFragmentosFormacionProfesional(raw, ficha)
    .map(fragmento => limpiarTextoFicha(fragmento))
    .filter(Boolean);

  return limpiarTextoFicha(fragmentos.join('\n'))
    .replace(/\bLic\s+enciatura\b/gi, 'Licenciatura')
    .replace(/\bAuditor[ií]a\s+Financiera\s+Matem[aá]ticas\b/gi, 'Auditoría Financiera, Matemáticas')
    .replace(/\s+/g, ' ')
    .trim();
}

function agregarTerminoCarrera(set, value) {
  const limpio = limpiarItemAreaProfesional(value);
  if (!limpio) return;
  if (esBasuraAreaProfesional(limpio)) return;
  set.add(limpio);
}

function contieneFraseCatalogo(textoNorm, fraseNorm) {
  if (!textoNorm || !fraseNorm) return false;
  return contieneTerminoNormalizado(textoNorm, fraseNorm);
}

function contieneAlgunaFraseCatalogo(textoNorm, variantes) {
  return (Array.isArray(variantes) ? variantes : [])
    .some(variante => contieneFraseCatalogo(textoNorm, normalizarBusqueda(variante)));
}

function extraerTerminosProfesionalesExplicitos(raw, ficha) {
  const textoFormacion = textoFormacionCatalogo(raw, ficha);
  const formacionNorm = normalizarBusqueda(textoFormacion);
  const terminos = new Set();

  const reglas = [
    {
      canonico: 'Administración de Empresas',
      variantes: ['administracion de empresas', 'administrador de empresas', 'administradora de empresas']
    },
    {
      canonico: 'Contaduría',
      variantes: ['contaduria publica', 'contador publico', 'contadora publica', 'contabilidad', 'contable']
    },
    {
      canonico: 'Auditoria',
      variantes: ['auditoria financiera', 'auditoria', 'auditor financiero', 'auditora financiera', 'auditor', 'auditora']
    },
    {
      canonico: 'Economía',
      variantes: ['economia', 'economista']
    },
    {
      canonico: 'Ingeniería Comercial',
      variantes: ['ingenieria comercial', 'ingeniero comercial', 'ingeniera comercial']
    },
    {
      canonico: 'Ingeniería Financiera',
      variantes: ['ingenieria financiera', 'ingeniero financiero', 'ingeniera financiera']
    },
    {
      canonico: 'Estadística',
      variantes: ['estadistica', 'estadistico', 'estadistica actuarial', 'ciencias actuariales']
    },
    {
      canonico: 'Matemáticas',
      variantes: ['matematicas', 'matematica', 'matematico', 'matematica actuarial', 'matematico actuarial']
    },
    {
      canonico: 'Arquitectura y Urbanismo',
      variantes: ['arquitectura', 'arquitecto', 'arquitecta', 'arquitectura y urbanismo']
    },
    {
      canonico: 'Civil y Construcciones Civiles',
      variantes: ['ingenieria civil', 'ingeniero civil', 'ingeniera civil', 'construccion civil', 'construcciones civiles', 'civil y construcciones civiles']
    },
    {
      canonico: 'Eléctrico/a',
      variantes: ['ingenieria electrica', 'ingeniero electrico', 'ingeniera electrica', 'electricidad', 'electrico', 'electrica']
    },
    {
      canonico: 'Electrónica, Electromecánica y Mecatrónica',
      variantes: ['ingenieria electronica', 'ingeniero electronico', 'ingeniera electronica', 'electronica', 'ingenieria electromecanica', 'ingeniero electromecanico', 'electromecanica', 'mecatronica', 'ingenieria mecatronica']
    },
    {
      canonico: 'Ingeniería Industrial',
      variantes: ['ingenieria industrial', 'ingeniero industrial', 'ingeniera industrial']
    },
    {
      canonico: 'Sistemas e Informática',
      variantes: ['ingenieria de sistemas', 'ingenieria informatica', 'informatica', 'sistemas informaticos', 'sistemas e informatica']
    },
    {
      canonico: 'Química',
      variantes: ['ingenieria quimica', 'ingeniero quimico', 'ingeniera quimica', 'licenciatura en quimica', 'quimica industrial', 'procesos quimicos', 'quimica']
    },
    {
      canonico: 'Derecho y Ciencias Juridicas',
      variantes: ['derecho', 'abogado', 'abogada', 'ciencias juridicas']
    },
    {
      canonico: 'Relaciones Internacionales, Ciencias Políticas y Gestión Pública',
      variantes: ['relaciones internacionales', 'ciencias politicas', 'gestion publica', 'administracion publica']
    },
    {
      canonico: 'Psicología',
      variantes: ['psicologia', 'psicologa', 'psicologo']
    },
    {
      canonico: 'Comunicación Social',
      variantes: ['comunicacion social', 'comunicador social', 'comunicadora social']
    },
    {
      canonico: 'Trabajo Social',
      variantes: ['trabajo social', 'trabajador social', 'trabajadora social']
    },
    {
      canonico: 'Antropología',
      variantes: ['antropologia', 'antropologo', 'antropologa']
    },
    {
      canonico: 'Ingeniería Agronómica',
      variantes: ['ingenieria agronomica', 'ingeniero agronomo', 'ingeniera agronoma']
    },
    {
      canonico: 'Ingeniería Ambiental',
      variantes: ['ingenieria ambiental', 'ambiental']
    },
    {
      canonico: 'Ingeniería Minera',
      variantes: ['ingenieria minera', 'mineria']
    }
  ];

  reglas.forEach(regla => {
    if (contieneAlgunaFraseCatalogo(formacionNorm, regla.variantes)) {
      agregarTerminoCarrera(terminos, regla.canonico);
    }
  });

  // Reglas de respaldo por título/objeto. Son intencionalmente pocas y específicas:
  // evitan que el objeto completo active áreas por palabras sueltas.
  return [...terminos].slice(0, 80);

  const objetoNorm = normalizarBusqueda([raw?.objeto_contratacion, ficha?.titulo_convocatoria].filter(Boolean).join(' '));

  if (contieneFraseCatalogo(objetoNorm, 'ingeniero civil')) {
    agregarTerminoCarrera(terminos, 'Civil y Construcciones Civiles');
  }

  if (
    contieneFraseCatalogo(objetoNorm, 'ingeniero control proteccion') ||
    contieneFraseCatalogo(objetoNorm, 'ingeniero control y proteccion') ||
    contieneFraseCatalogo(objetoNorm, 'control y proteccion')
  ) {
    agregarTerminoCarrera(terminos, 'Eléctrico/a');
  }

  if (contieneFraseCatalogo(objetoNorm, 'profesional contable') || contieneFraseCatalogo(objetoNorm, 'contable')) {
    agregarTerminoCarrera(terminos, 'Contaduría');
    agregarTerminoCarrera(terminos, 'Auditoria');
  }

  if (contieneFraseCatalogo(objetoNorm, 'adquisiciones')) {
    ['Administración de Empresas', 'Economía', 'Auditoria', 'Ingeniería Comercial', 'Ingeniería Financiera', 'Contaduría']
      .forEach(item => agregarTerminoCarrera(terminos, item));
  }

  if (contieneFraseCatalogo(objetoNorm, 'matematico actuarial') || contieneFraseCatalogo(objetoNorm, 'actuarial')) {
    agregarTerminoCarrera(terminos, 'Matemáticas');
    if (contieneFraseCatalogo(formacionNorm, 'estadistica') || contieneFraseCatalogo(formacionNorm, 'actuarial')) {
      agregarTerminoCarrera(terminos, 'Estadística');
    }
  }

  return [...terminos].slice(0, 80);
}

function palabrasClaveProfesiones(raw, ficha) {
  return [...new Set(extraerTerminosProfesionalesExplicitos(raw, ficha)
    .map(normalizarBusqueda)
    .filter(Boolean))];
}

function textoCompletoParaProfesiones(raw, ficha) {
  // Solo se usa texto cercano a títulos de formación académica/profesional.
  // No se usa todo el Word para evitar falsos positivos como "persona física",
  // "seguridad física", "corrupción", "sanción", etc.
  return normalizarBusqueda(textoFormacionCatalogo(raw, ficha));
}

function areaIdCatalogoParaProfesion(profesion, pivot, areas) {
  const idProfesion = Number(profesion?.id);
  const areaDesdePivot = (Array.isArray(pivot) ? pivot : [])
    .find(row => Number(row.profesion_id) === idProfesion);
  const areaId = Number(areaDesdePivot?.area_id || profesion?.area_id || 0);
  const existeArea = (Array.isArray(areas) ? areas : []).some(area => Number(area.id) === areaId);
  return existeArea ? areaId : 0;
}

function variantesEspecialesCatalogo(nombre) {
  const n = normalizarBusqueda(nombre);
  const map = {
    'administracion de empresas': ['administracion de empresas', 'administrador de empresas', 'administradora de empresas'],
    'contaduria': ['contaduria', 'contaduria publica', 'contador publico', 'contadora publica', 'contabilidad', 'contable'],
    'economia': ['economia', 'economista'],
    'auditoria': ['auditoria', 'auditoria financiera', 'auditor', 'auditora', 'auditor financiero', 'auditora financiera'],
    'ingenieria comercial': ['ingenieria comercial', 'ingeniero comercial', 'ingeniera comercial'],
    'ingenieria financiera': ['ingenieria financiera', 'ingeniero financiero', 'ingeniera financiera'],
    'estadistica': ['estadistica', 'estadistico', 'estadistica actuarial', 'ciencias actuariales'],
    'derecho y ciencias juridicas': ['derecho y ciencias juridicas', 'derecho', 'abogado', 'abogada', 'ciencias juridicas'],
    'arquitectura y urbanismo': ['arquitectura y urbanismo', 'arquitectura', 'arquitecto', 'arquitecta'],
    'electrico a': ['electrico a', 'electrico', 'electrica', 'electricidad', 'ingenieria electrica', 'ingeniero electrico', 'ingeniera electrica'],
    'electronica electromecanica y mecatronica': ['electronica electromecanica y mecatronica', 'electronica', 'ingenieria electronica', 'ingeniero electronico', 'ingeniera electronica', 'electromecanica', 'ingenieria electromecanica', 'mecatronica', 'ingenieria mecatronica'],
    'civil y construcciones civiles': ['civil y construcciones civiles', 'ingenieria civil', 'ingeniero civil', 'ingeniera civil', 'construccion civil', 'construcciones civiles'],
    'quimica': ['quimica', 'ingenieria quimica', 'ingeniero quimico', 'ingeniera quimica', 'licenciatura en quimica', 'procesos quimicos', 'quimica industrial'],
    'matematicas': ['matematicas', 'matematica', 'matematico', 'matematico actuarial', 'matematica actuarial'],
    'fisica': ['fisica', 'ingenieria fisica', 'licenciatura en fisica'],
    'biologia': ['biologia', 'biologo', 'biologa'],
    'sistemas e informatica': ['sistemas e informatica', 'ingenieria de sistemas', 'ingenieria informatica', 'informatica', 'sistemas informaticos'],
    'redes y telecomunicaciones': ['redes y telecomunicaciones', 'telecomunicaciones', 'redes'],
    'ingenieria industrial': ['ingenieria industrial', 'ingeniero industrial', 'ingeniera industrial'],
    'ingenieria ambiental': ['ingenieria ambiental', 'ambiental'],
    'ingenieria minera': ['ingenieria minera', 'mineria'],
    'relaciones internacionales ciencias politicas y gestion publica': ['relaciones internacionales', 'ciencias politicas', 'gestion publica', 'administracion publica'],
    'psicologia': ['psicologia', 'psicologa', 'psicologo'],
    'comunicacion y medios digitales': ['comunicacion y medios digitales', 'comunicacion social', 'comunicador social', 'comunicadora social'],
    'antropologia historia y filosofia': ['antropologia historia y filosofia', 'antropologia', 'antropologo', 'antropologa'],
    'trabajo social': ['trabajo social', 'trabajador social', 'trabajadora social'],
    'ingenieria agronomica': ['ingenieria agronomica', 'ingeniero agronomo', 'ingeniera agronoma'],
    'secretariado ejecutivo': ['secretariado ejecutivo', 'secretaria ejecutiva', 'secretario ejecutivo'],
    'seguridad fisica e institucional': ['seguridad fisica e institucional'],
  };

  return [...new Set([n, ...(map[n] || [])])].filter(Boolean);
}

function aliasEsUnaPalabraRiesgosa(aliasNorm) {
  return [
    'fisica',
    'quimica',
    'biologia',
    'matematicas',
    'matematica',
    'estadistica',
    'derecho',
    'arquitectura',
    'ambiental',
    'mineria',
    'electronica',
    'electrica',
    'electricidad',
    'informatica',
    'redes',
  ].includes(aliasNorm);
}

function aliasTieneContextoProfesional(textoNorm, aliasNorm) {
  if (!contieneFraseCatalogo(textoNorm, aliasNorm)) return false;

  if (!aliasEsUnaPalabraRiesgosa(aliasNorm)) return true;

  const words = textoNorm.split(' ');
  const aliasWords = aliasNorm.split(' ');
  for (let i = 0; i <= words.length - aliasWords.length; i++) {
    if (words.slice(i, i + aliasWords.length).join(' ') !== aliasNorm) continue;

    const ventana = words.slice(Math.max(0, i - 10), Math.min(words.length, i + aliasWords.length + 10)).join(' ');

    if (/\b(persona|personas|seguridad|actividad|condicion|condiciones|infraestructura|institucional|corrupcion|sancion|investigacion|banco|virtual|sitio)\b/.test(ventana)) {
      continue;
    }

    if (/\b(formacion|academica|profesional|licenciatura|licenciado|licenciada|ingenieria|ingeniero|ingeniera|titulo|carrera|tecnico|superior|provision|nacional|grado)\b/.test(ventana)) {
      return true;
    }
  }

  return false;
}

function profesionCatalogoCoincide(profesion, keywordsNorm, textoFormacionNorm) {
  const nombre = limpiarTextoFicha(profesion?.nombre || profesion?.name || '');
  const variantes = variantesEspecialesCatalogo(nombre);

  // 1) Match exacto por términos ya extraídos del bloque de formación o reglas específicas por objeto.
  if (variantes.some(variante => keywordsNorm.includes(variante))) return true;

  // 2) Match exacto en texto de formación, con protección para palabras sueltas peligrosas.
  return variantes.some(variante => aliasTieneContextoProfesional(textoFormacionNorm, variante));
}

function evidenciaContieneProfesion(keywordsNorm, textoFormacionNorm, frase) {
  const norm = normalizarBusqueda(frase);
  return keywordsNorm.includes(norm) || contieneFraseCatalogo(textoFormacionNorm, norm);
}

function nombreProfesionSegunEvidencia(nombre, keywordsNorm, textoFormacionNorm) {
  const n = normalizarBusqueda(nombre);

  if (
    n === 'relaciones internacionales ciencias politicas y gestion publica' &&
    evidenciaContieneProfesion(keywordsNorm, textoFormacionNorm, 'ciencias politicas') &&
    !evidenciaContieneProfesion(keywordsNorm, textoFormacionNorm, 'relaciones internacionales')
  ) {
    return 'Ciencias Políticas';
  }

  if (
    n === 'comunicacion y medios digitales' &&
    evidenciaContieneProfesion(keywordsNorm, textoFormacionNorm, 'comunicacion social')
  ) {
    return 'Comunicación Social';
  }

  if (
    n === 'antropologia historia y filosofia' &&
    evidenciaContieneProfesion(keywordsNorm, textoFormacionNorm, 'antropologia')
  ) {
    return 'Antropología';
  }

  return nombre;
}

function detectarProfesionesJson(raw, ficha) {
  const keywordsNorm = palabrasClaveProfesiones(raw, ficha);
  const textoFormacionNorm = textoCompletoParaProfesiones(raw, ficha);
  if (!keywordsNorm.length && !textoFormacionNorm) return [];

  const profesiones = cargarJsonData('profesiones.json');
  const areas = cargarJsonData('areas.json');
  const pivot = cargarJsonData('area_profesion.json');

  if (!Array.isArray(profesiones) || !Array.isArray(areas)) return [];

  const matches = new Map();

  profesiones.forEach(profesion => {
    const nombre = limpiarTextoFicha(profesion.nombre || profesion.name || '');
    if (!nombre) return;

    const areaId = areaIdCatalogoParaProfesion(profesion, pivot, areas);
    if (!areaId) return;

    if (!profesionCatalogoCoincide(profesion, keywordsNorm, textoFormacionNorm)) return;

    if (!matches.has(areaId)) matches.set(areaId, new Set());
    matches.get(areaId).add(nombreProfesionSegunEvidencia(nombre, keywordsNorm, textoFormacionNorm));
  });

  return [...matches.keys()]
    .sort((a, b) => Number(a) - Number(b))
    .map(areaId => {
      const area = areas.find(item => Number(item.id) === Number(areaId));
      if (!area) return null;
      return quitarCamposVacios({
        area_id: Number(areaId),
        area: limpiarTextoFicha(area.nombre || area.name || ''),
        profesiones: [...(matches.get(areaId) || new Set())].sort((a, b) => a.localeCompare(b)),
      });
    })
    .filter(item => item && item.area_id && item.area && Array.isArray(item.profesiones) && item.profesiones.length);
}

function evidenciaProfesionesJson(raw, profesionesDetectadas) {
  const candidatos = bloquesCandidatosProfesion(raw?.texto_completo_word || raw?.texto_word || '');
  const principal = candidatos[0] || null;
  const profesiones = (Array.isArray(profesionesDetectadas) ? profesionesDetectadas : [])
    .flatMap(item => Array.isArray(item.profesiones) ? item.profesiones : [])
    .map(limpiarTextoFicha)
    .filter(item => item && normalizarBusqueda(item) !== 'no especificado');
  const areas = (Array.isArray(profesionesDetectadas) ? profesionesDetectadas : [])
    .map(item => limpiarTextoFicha(item.area || ''))
    .filter(item => item && normalizarBusqueda(item) !== 'no especificado');

  if (!profesiones.length) {
    return quitarCamposVacios({
      area: 'No especificado',
      profesiones: [],
      confianza: 'baja',
      bloques_candidatos: candidatos.slice(0, 5),
    });
  }

  return quitarCamposVacios({
    area: areas.length ? [...new Set(areas)].join(', ') : 'No especificado',
    profesiones: [...new Set(profesiones)],
    confianza: principal ? principal.confianza : 'baja',
    evidencia: principal ? {
      bloque: principal.titulo_bloque,
      bloque_id: principal.id,
      texto: principal.texto,
      puntaje: principal.puntaje,
    } : null,
    bloques_candidatos: candidatos.slice(0, 5),
  });
}

function areaPrincipalDesdeContexto(raw, ficha) {
  const texto = normalizarBusqueda([
    raw?.objeto_contratacion,
    raw?.texto_completo_word,
    ficha?.titulo_convocatoria,
  ].filter(Boolean).join(' '));

  if (
    texto.includes('especialista social') ||
    texto.includes('gestion social') ||
    texto.includes('desarrollo comunitario')
  ) {
    return 'Social / Gestión Social / Desarrollo Comunitario';
  }

  return '';
}



function enrich_location_and_profession(ficha_final, raw_json = {}) {
  const ficha = {
    ...(ficha_final || {}),
    descripcion: {
      ...((ficha_final || {}).descripcion || {}),
    },
  };

  const ubicacion = detectarMunicipioBolivia(raw_json, ficha);
  if (ubicacion) {
    ficha.ubicacion_final = `${ubicacion.municipio}, ${ubicacion.departamento}`;
    ficha.ubicacion_detalle = {
      municipio: ubicacion.municipio,
      departamento: ubicacion.departamento,
    };
    ficha.descripcion.lugar_trabajo = ubicacion.municipio;
  }

  const profesionesDetectadas = detectarProfesionesJson(raw_json, ficha);
  const areaPrincipal = areaPrincipalDesdeContexto(raw_json, ficha);
  ficha.profesiones_detectadas = profesionesDetectadas;
  ficha.areaProfesiones = evidenciaProfesionesJson(raw_json, profesionesDetectadas);
  if (areaPrincipal) {
    ficha.area_principal = areaPrincipal;
    ficha.areaProfesiones = {
      ...(ficha.areaProfesiones || {}),
      area: areaPrincipal,
    };
  }
  ficha.evidencia_profesiones = ficha.areaProfesiones?.evidencia || null;

  const profesionesPlanos = profesionesDetectadas
    .flatMap(item => Array.isArray(item.profesiones) ? item.profesiones : [])
    .map(limpiarTextoFicha)
    .filter(Boolean);

  // area_profesional se mantiene como lista simple para la ficha pública,
  // pero ahora sale del mismo catálogo validado que profesiones_detectadas.
  ficha.area_profesional = [...new Set(profesionesPlanos)].slice(0, 12);

  if (!Array.isArray(ficha.profesiones_detectadas) || ficha.profesiones_detectadas.length === 0) {
    ficha.profesiones_detectadas = [{
      area_id: null,
      area: 'No especificado',
      profesiones: ['No especificado'],
    }];
  }

  if (!Array.isArray(ficha.area_profesional) || ficha.area_profesional.length === 0) {
    ficha.area_profesional = ['No especificado'];
  }

  ficha.descripcion = quitarCamposVacios(ficha.descripcion);
  return ficha;
}

function normalize_convocatoria(raw_json) {
  const raw = raw_json || {};
  const tipo = raw.tipo_convocatoria_detectado || '';
  const sueldoTipo = raw.sueldo_tipo_detalle || raw.sueldo_tipo || '';
  const lugarTrabajo = lugarTrabajoFicha(raw);
  const ficha_final = {
    titulo_convocatoria: limpiarTextoFicha(raw.objeto_contratacion || ''),
    empresa: limpiarTextoFicha(raw.entidad || ''),
    area_profesional: areaProfesionalFicha(raw),
    expiracion: limpiarTextoFicha(raw.fecha_expiracion || ''),
    cuce: limpiarTextoFicha(raw.cuce || ''),
    lugar_de_trabajo: limpiarTextoFicha(raw.lugar_de_trabajo || ''),
    duracion_del_contrato: limpiarTextoFicha(raw.duracion_del_contrato || ''),
    modalidad_de_postulacion: limpiarTextoFicha(raw.modalidad_de_postulacion || ''),
    sueldo: sueldoFicha('item', null, false),
    descripcion: {
      lugar_trabajo: lugarTrabajo,
      duracion: valorCortoFicha(raw.duracion_del_contrato),
      modalidad: modalidadNormalizada(raw),
      modalidad_de_postulacion: limpiarTextoFicha(raw.modalidad_de_postulacion || ''),
      detalle_sueldos: limpiarTextoMultilineaFicha(raw.detalle_sueldos || ''),
    },
    detalle_sueldos: limpiarTextoMultilineaFicha(raw.detalle_sueldos || ''),
    evidencia_sueldo: raw.evidencia_sueldo || null,
    validacion_matematica_sueldo: raw.validacion_matematica_sueldo || null,
    bloques_candidatos_sueldo: raw.bloques_candidatos_sueldo || [],
    bloques_candidatos_profesion: raw.bloques_candidatos_profesion || [],
  };

  if (tipo === 'individual_producto') {
    const montoTotal = primerValor(raw.presupuesto_total_asignado, raw.precio_referencial, null);
    ficha_final.sueldo = sueldoFicha('por_producto', montoTotal, true);
    const pagos = pagosFicha(raw.cronograma_pagos);
    if (pagos.length) ficha_final.pagos = pagos;
    return quitarCamposVacios({
      ...ficha_final,
      descripcion: quitarCamposVacios(ficha_final.descripcion),
    });
  }

  if (tipo === 'individual_linea') {
    ficha_final.sueldo = sueldoFicha(sueldoTipo || 'linea', raw.sueldo ?? null, Boolean(raw.sueldo));
    const items = limpiarListaItemsFicha(raw.items_detectados?.length ? raw.items_detectados : raw.sueldos_detectados);
    if (items.length) ficha_final.items = items;
    return quitarCamposVacios({
      ...ficha_final,
      descripcion: quitarCamposVacios(ficha_final.descripcion),
    });
  }

  if (tipo === 'individual_item') {
    ficha_final.sueldo = sueldoFicha(sueldoTipo || 'item', raw.sueldo ?? null, Boolean(raw.sueldo));
    const items = limpiarListaItemsFicha(raw.items_detectados?.length ? raw.items_detectados : raw.sueldos_detectados);
    if (items.length) ficha_final.items = items;
    return quitarCamposVacios({
      ...ficha_final,
      descripcion: quitarCamposVacios(ficha_final.descripcion),
    });
  }

  ficha_final.sueldo = sueldoFicha(sueldoTipo || tipo || 'item', raw.sueldo ?? null, Boolean(raw.sueldo));
  return quitarCamposVacios({
    ...ficha_final,
    descripcion: quitarCamposVacios(ficha_final.descripcion),
  });
}

async function generarFichasFinales(unificado) {
  const resultados = Array.isArray(unificado?.resultados) ? unificado.resultados : [];
  const fichasFinales = [];

  for (const raw of resultados) {
    fichasFinales.push(enrich_location_and_profession(normalize_convocatoria(raw), raw));
  }

  return {
    fecha: unificado?.fecha || '',
    total_fichas: resultados.length,
    fichas_finales: fichasFinales,
  };
}

function tieneValorFicha(value) {
  if (Array.isArray(value)) return value.some(tieneValorFicha);
  if (value && typeof value === 'object') return Object.values(value).some(tieneValorFicha);
  return String(value || '').trim() !== '';
}

function fichaFinalValida(ficha) {
  return Boolean(
    ficha &&
    tieneValorFicha(ficha.cuce) &&
    tieneValorFicha(ficha.titulo_convocatoria) &&
    tieneValorFicha(ficha.empresa)
  );
}

function validarFichasFinales(fichasFinales, contexto = 'SICOES', phaseNumber = 7, allowEmpty = false) {
  const items = Array.isArray(fichasFinales?.fichas_finales) ? fichasFinales.fichas_finales : [];

  if (!items.length) {
    if (allowEmpty) return;

    throw phaseFail(phaseNumber, `${contexto}: JSON final vacio. No hay fichas_finales importables.`);
  }

  const invalidas = items
    .map((ficha, index) => ({ ficha, index }))
    .filter(({ ficha }) => !fichaFinalValida(ficha));

  if (invalidas.length) {
    const detalle = invalidas
      .slice(0, 5)
      .map(({ ficha, index }) => `#${index + 1} CUCE=${ficha?.cuce || 'sin_cuce'}`)
      .join(', ');

    throw phaseFail(phaseNumber, `${contexto}: JSON final contiene fichas incompletas: ${detalle}`);
  }
}

function construirDescripcion(convocatoria, textoWord) {
  const lineas = obtenerLineas(textoWord);

  const lugar = extraerCercaDe(lineas, [
    'lugar de trabajo',
    'lugar de prestacion',
    'lugar de prestacion del servicio',
    'ubicacion',
    'direccion',
    'municipio',
  ]);

  const duracion = extraerCercaDe(lineas, [
    'duracion',
    'plazo del contrato',
    'plazo de prestacion',
    'vigencia',
    'dias calendario',
    'meses',
  ]);

  const modalidad = extraerCercaDe(lineas, [
    'presentacion de propuestas',
    'forma de presentacion',
    'modalidad de presentacion',
    'rupe',
    'lugar de presentacion',
  ]);

  const sueldos = extraerSueldos(lineas);

  return `
LUGAR DEL TRABAJO:
${lugar || 'No identificado automaticamente. Revisar documento adjunto.'}

DURACION DEL CONTRATO:
${duracion || 'No identificado automaticamente. Revisar documento adjunto.'}

MODALIDAD DE POSTULACION:
${modalidad || 'No identificado automaticamente. Revisar documento adjunto.'}
De manera digital a traves del RUPE en www.sicoes.gob.bo

CUCE:
${convocatoria?.cuce || 'No identificado'}

DETALLE DE SUELDOS:
${sueldos.length ? sueldos.join('\n') : '0'}

Impulsa tu futuro profesional!

Esta convocatoria fue verificada por el equipo de TRABAJONAUTAS.COM y representa una excelente oportunidad de crecimiento para ti. No la dejes pasar!

Fuente: www.sicoes.gob.bo

Descarga todos los detalles en el/los archivo(s) adjunto(s):
`.trim();
}

function listarDocx(inputDir) {
  if (!fs.existsSync(inputDir)) return [];

  return fs.readdirSync(inputDir)
    .filter(file => !file.startsWith('~$'))
    .map(file => {
      const filePath = path.join(inputDir, file);
      const stat = fs.statSync(filePath);
      if (!stat.isFile()) return null;

      const ext = path.extname(file).toLowerCase();
      const esWordPorNombre = ['.doc', '.docx', '.pdf'].includes(ext);
      let esWordPorBytes = false;

      try {
        esWordPorBytes = pareceWordPorBytes(filePath);
      } catch (error) {
        esWordPorBytes = false;
      }

      if (!esWordPorNombre && !esWordPorBytes) return null;

      return {
        name: file,
        path: filePath,
        order: parseInt((file.match(/^(\d+)/) || [])[1], 10),
      };
    })
    .filter(Boolean)
    .sort((a, b) => {
      const ao = Number.isNaN(a.order) ? Number.MAX_SAFE_INTEGER : a.order;
      const bo = Number.isNaN(b.order) ? Number.MAX_SAFE_INTEGER : b.order;
      if (ao !== bo) return ao - bo;
      return a.name.localeCompare(b.name, 'es');
    });
}

function compactarCuce(value) {
  return String(value || '').replace(/\D/g, '');
}

function asignarWords(convocatorias, docxFiles) {
  const usados = new Set();
  const asignaciones = convocatorias.map((convocatoria, index) => ({
    index,
    convocatoria,
    docx: null,
    metodo: 'sin_archivo',
  }));

  for (const asignacion of asignaciones) {
    const cuce = compactarCuce(asignacion.convocatoria.cuce);
    if (!cuce) continue;

    const doc = docxFiles.find(file =>
      !usados.has(file.path) && compactarCuce(file.name).includes(cuce)
    );

    if (doc) {
      asignacion.docx = doc;
      asignacion.metodo = 'cuce_en_nombre';
      usados.add(doc.path);
    }
  }

  for (const file of docxFiles) {
    if (usados.has(file.path) || Number.isNaN(file.order)) continue;
    const asignacion = asignaciones[file.order - 1];

    if (asignacion && !asignacion.docx) {
      asignacion.docx = file;
      asignacion.metodo = 'numero_en_nombre';
      usados.add(file.path);
    }
  }

  const restantesDocs = docxFiles.filter(file => !usados.has(file.path));
  const restantesConv = asignaciones.filter(item => !item.docx);

  if (restantesDocs.length === restantesConv.length) {
    restantesConv.forEach((asignacion, i) => {
      asignacion.docx = restantesDocs[i];
      asignacion.metodo = 'orden_alfabetico';
      usados.add(restantesDocs[i].path);
    });
  }

  return asignaciones;
}

function escribirOrdenConvocatorias(convocatorias, ordenPath) {
  const lines = [
    'Orden de convocatorias para nombrar los Word manuales',
    '',
    'Recomendado: renombra los archivos como 01.docx, 02.docx, etc. segun este orden.',
    'Tambien sirve si el nombre del archivo contiene el CUCE.',
    '',
  ];

  convocatorias.forEach((c, index) => {
    lines.push(`${String(index + 1).padStart(2, '0')}. CUCE: ${c.cuce || ''}`);
    lines.push(`    Entidad: ${c.entidad || ''}`);
    lines.push(`    Objeto: ${c.objetoContratacion || ''}`);
    lines.push('');
  });

  try {
    writeFileSafe(ordenPath, lines.join('\n'), 'utf8', 1);
  } catch (error) {
    console.log(`[SICOES] No se pudo escribir guia de orden ${safePathForLog(ordenPath)}: ${errorMessage(error)}. Se continua el procesamiento.`);
  }
}

async function obtenerCantidadPaginas(page) {
  return await page.evaluate(tableSelector => {
    const links = Array.from(document.querySelectorAll(`${tableSelector}_paginate a`));
    const numeros = links
      .map(a => parseInt((a.innerText || '').trim(), 10))
      .filter(n => !Number.isNaN(n));

    return numeros.length ? Math.max(...numeros) : 1;
  }, activeTableSelector());
}

async function irAPagina(page, pagina) {
  const tableSelector = activeTableSelector();
  const primerCuceAntes = await page.$eval(`${tableSelector} tbody tr td`, element => element.innerText || '').catch(() => '');

  await page.evaluate(({ p, personnel }) => {
    if (personnel && typeof window.buscarReqPersonal === 'function') {
      window.buscarReqPersonal('Avanzada', 'tablaAvanzada', 'formAvanzada', String(p));
    } else if (typeof window.busquedadraw === 'function') {
      window.busquedadraw(String(p));
    }
  }, { p: pagina, personnel: ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL });

  await page.waitForFunction(
    ({ oldCuce, tableSelector }) => {
      const nuevo = document.querySelector(`${tableSelector} tbody tr td`)?.innerText || '';
      return nuevo && nuevo !== oldCuce;
    },
    { timeout: 15000 },
    { oldCuce: primerCuceAntes, tableSelector }
  ).catch(async () => {
    await delay(2500);
  });
}

async function extraerConvocatoriasDePagina(page) {
  const tableSelector = activeTableSelector();
  const personnel = ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL;

  return await page.$$eval(`${tableSelector} tbody tr`, (rows, personnelMode) => {
    return rows.map((row, index) => {
      const cells = Array.from(row.querySelectorAll('td'));
      const text = i => (cells[i]?.innerText || '')
        .replace(/Â(?=[ºª°])/g, '')
        .trim()
        .replace(/\s+/g, ' ');

      const filesCell = personnelMode ? 6 : 9;
      const archivos = Array.from(cells[filesCell]?.querySelectorAll('a') || []).map(a => {
        const onclick = a.getAttribute('onclick') || '';
        const match = onclick.match(/descargarArchivo\(['"]([^'"]+)['"]\)/);

        return {
          nombre: (a.innerText || '').trim(),
          token: match ? match[1] : null,
          onclick,
        };
      });

      if (personnelMode) {
        const reference = text(2);

        return {
          numero: index + 1,
          cuce: reference,
          referencia: reference,
          entidad: text(0),
          tipoContratacion: 'Requerimiento de personal',
          modalidad: 'Requerimiento de personal',
          objetoContratacion: text(1),
          fechaPublicacion: text(4),
          fechaPresentacion: text(5),
          estado: text(3),
          archivos,
          ficha: 'https://www.sicoes.gob.bo/portal/contrataciones/otrasPublicaciones/requerimientoPersonal.php',
          source_type: 'personnel_requirements',
        };
      }

      const fichaOnclick = cells[11]?.querySelector('a')?.getAttribute('onclick') || '';
      const fichaMatch = fichaOnclick.match(/irFicha\('([^']+)'\)/);

      return {
        numero: index + 1,
        cuce: text(0),
        entidad: text(1),
        tipoContratacion: text(2),
        modalidad: text(3),
        objetoContratacion: text(4),
        subasta: text(5),
        fechaPublicacion: text(6),
        fechaPresentacion: text(7),
        estado: text(8),
        archivos,
        ficha: fichaMatch ? `https://www.sicoes.gob.bo${fichaMatch[1]}` : null,
      };
    });
  }, personnel);
}

function personnelSearchRange(fecha) {
  const [day, month, year] = String(fecha).split('/').map(Number);
  const formatNearbyDate = offset => {
    const value = new Date(Date.UTC(year, month - 1, day + offset));
    return `${String(value.getUTCDate()).padStart(2, '0')}/${String(value.getUTCMonth() + 1).padStart(2, '0')}/${value.getUTCFullYear()}`;
  };

  // El portal aplica limites exclusivos cuando ambas fechas son iguales.
  return { from: formatNearbyDate(-1), to: formatNearbyDate(1) };
}

async function personnelSearchIsReady(page, fecha, reference = '') {
  if (ACTIVE_SICOES_SOURCE !== SOURCE_PERSONNEL || !fecha) return true;

  const expectedRange = personnelSearchRange(fecha);
  return await page.evaluate(({ range, referenceValue }) => {
    const from = document.querySelector('#formAvanzada input[name="publicacionDesde"]')?.value || '';
    const to = document.querySelector('#formAvanzada input[name="publicacionHasta"]')?.value || '';
    const rows = Array.from(document.querySelectorAll('#tablaAvanzada tbody tr'));
    const referenceFound = !referenceValue
      || rows.some(row => (row.innerText || '').includes(referenceValue));

    return from === range.from && to === range.to && rows.length > 0 && referenceFound;
  }, { range: expectedRange, referenceValue: String(reference || '').trim() }).catch(() => false);
}

async function gotoSicoesPersonnel(page, fecha) {
  await page.goto('https://www.sicoes.gob.bo/portal/index.php', {
    waitUntil: 'domcontentloaded',
    timeout: TOKEN_TIMEOUT_MS,
  });

  const token = await page.$eval('#token', input => input.value || '').catch(() => '');
  if (!token) throw phaseFail(1, 'no se encontro token de navegacion SICOES');

  const target = `https://www.sicoes.gob.bo/portal/contrataciones/otrasPublicaciones/requerimientoPersonal.php?token=${encodeURIComponent(token)}`;
  await page.goto(target, { waitUntil: 'domcontentloaded', timeout: TABLE_TIMEOUT_MS });
  const personnelDateSelector = '#formAvanzada input[name="publicacionDesde"]';
  // Playwright espera elementos visibles por defecto, pero el portal conserva
  // este input oculto hasta que se abre la pestana avanzada. Para preparar la
  // busqueda basta con que el control ya este adjunto al DOM.
  const personnelDateLocator = typeof page.locator === 'function'
    ? page.locator(personnelDateSelector)
    : null;
  if (typeof personnelDateLocator?.waitFor === 'function') {
    await personnelDateLocator.waitFor({ state: 'attached', timeout: TABLE_TIMEOUT_MS });
  } else {
    await page.waitForSelector(personnelDateSelector, { timeout: TABLE_TIMEOUT_MS });
  }

  // Ampliamos un día a cada lado y luego filtramos localmente la fecha exacta.
  const searchRange = personnelSearchRange(fecha);
  const filteredResponse = page.waitForResponse(response => {
    if (!/\/portal\/contrataciones\/operacion\.php/i.test(response.url())) return false;
    const postData = response.request().postData() || '';
    return postData.includes('requerimientoPersonal') && postData.includes('publicacionDesde=');
  }, { timeout: TABLE_TIMEOUT_MS });

  await page.evaluate(range => {
    document.querySelector('#formAvanzada input[name="publicacionDesde"]').value = range.from;
    document.querySelector('#formAvanzada input[name="publicacionHasta"]').value = range.to;
    document.querySelectorAll('#formAvanzada input[name="r1"]')
      .forEach(input => { input.checked = input.value === ''; });
    document.querySelector('a[href="#f-avanzada"]')?.click();

    if (typeof window.buscarReqPersonal === 'function') {
      window.buscarReqPersonal('Avanzada', 'tablaAvanzada', 'formAvanzada', 1);
    }
  }, searchRange);

  await filteredResponse;
  await delay(500);

  phaseOk(3, 'tabla avanzada de requerimientos de personal detectada');
}

async function extraerConvocatorias(fecha, convocatoriasPath, options = {}) {
  const { interactive = false } = options;
  const assisted = Boolean(options.assistedDownload);
  const browserSession = assisted
    ? await abrirBrowserRealCdp()
    : await abrirBrowserSicoes({ headless: !interactive });
  const { browser, page } = browserSession;

  console.log(`\nNavegando a SICOES en navegador ${assisted ? 'real/CDP asistido' : (interactive ? 'visible asistido' : 'headless')}...`);

  try {
    if (assisted) {
      await asegurarPaginaDescargaSicoes(page, { interactive: true, fecha });
    } else if (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL) {
      await gotoSicoesPersonnel(page, fecha);
    } else {
      await gotoSicoes(page);
    }

    if (interactive && !assisted) {
      await waitEnter(
        'SICOES abrio en navegador visible. Aplica los filtros que necesites y cuando la tabla muestre las convocatorias del dia, presiona ENTER aqui.'
      );
    } else {
      console.log('Modo automatico: leyendo la tabla de convocatorias sin pausa manual.');
    }

    await withTimeout(
      page.waitForSelector(`${activeTableSelector()} tbody tr`, { timeout: TABLE_TIMEOUT_MS }),
      TABLE_TIMEOUT_MS,
      `Fase 3 wait selector ${activeTableSelector()}`
    );

    const totalPaginas = await obtenerCantidadPaginas(page);
    const convocatorias = [];
    console.log(`\nPaginas detectadas: ${totalPaginas}`);
    console.log(`Fecha buscada: ${fecha}`);

    for (let pagina = 1; pagina <= totalPaginas; pagina++) {
      if (pagina > 1) {
        await irAPagina(page, pagina);
      }

      const paginaConvocatorias = await extraerConvocatoriasDePagina(page);
      const convocatoriasFecha = paginaConvocatorias.filter(c =>
        fechaDisplay(String(c.fechaPublicacion || '').split(/\s+/)[0]) === fecha
      );

      console.log(`Pagina ${pagina}: ${convocatoriasFecha.length} convocatorias para ${fecha}`);

      convocatorias.push(...convocatoriasFecha.map(c => ({
        ...c,
        pagina,
      })));
    }

    writeFileSafe(convocatoriasPath, JSON.stringify(convocatorias, null, 2), 'utf8');
    console.log(`\nConvocatorias guardadas en: ${safePathForLog(convocatoriasPath)}`);
    if (!convocatorias.length) {
      phaseOk(4, `filas detectadas: 0 para la fecha ${fecha}`);
      emitProgress(2, `sin convocatorias para ${fecha}`, { total: 0, no_results: true });
      return [];
    }
    phaseOk(4, `filas detectadas: ${convocatorias.length}`);
    emitProgress(2, `tabla encontrada: ${convocatorias.length} filas`, { total: convocatorias.length });

    return convocatorias;
  } finally {
    if (browserSession.close) {
      await browserSession.close().catch(() => {});
    } else {
      await browser.close().catch(() => {});
    }
  }
}

async function cargarPuppeteer() {
  // Intentar usar puppeteer-extra con plugin stealth (mejor contra Cloudflare)
  try {
    const puppeteerExtra = require('puppeteer-extra');
    const StealthPlugin = require('puppeteer-extra-plugin-stealth');
    puppeteerExtra.use(StealthPlugin());
    console.log('[BROWSER] Usando puppeteer-extra con stealth plugin');
    return puppeteerExtra;
  } catch (_) {
    // Fallback a puppeteer normal
    console.log('[BROWSER] puppeteer-extra no disponible, usando puppeteer standard');
    return require('puppeteer');
  }
}

async function abrirBrowserSicoes({ downloadDir = null, headless = true } = {}) {
  const puppeteer = await cargarPuppeteer();
  const executablePath = resolverBrowserExecutable();
  let userDataDir = '';

  try {
    if (headless) {
      userDataDir = fs.mkdtempSync(path.join(TEMP_DIR, 'puppeteer-profile-'));
    } else {
      userDataDir = PERFIL_DIR;
      ensureDirs(userDataDir);
    }
  } catch (error) {
    throw phaseFail(1, `no se pudo crear perfil temporal Chromium en ${TEMP_DIR}: ${errorMessage(error)}`);
  }

  const browser = await puppeteer.launch({
    headless: headless ? 'new' : false,
    ...(executablePath ? { executablePath } : {}),
    userDataDir,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--no-first-run',
      '--no-default-browser-check',
      // Reducir huella de automatización
      '--disable-blink-features=AutomationControlled',
      '--disable-infobars',
      '--window-size=1366,900',
    ],
  });

  const page = await browser.newPage();
  page.setDefaultTimeout(30000);
  page.setDefaultNavigationTimeout(60000);
  await page.setViewport({ width: 1366, height: 900 });

  // User-agent de Chrome real en Windows (más común, menos sospechoso que Linux)
  await page.setUserAgent(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
  );

  // Ocultar señales de automatización
  await page.evaluateOnNewDocument(() => {
    // Eliminar navigator.webdriver
    Object.defineProperty(navigator, 'webdriver', { get: () => false });
    // Simular plugins reales
    Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    // Simular idiomas
    Object.defineProperty(navigator, 'languages', { get: () => ['es-BO', 'es', 'en-US'] });
    // Chrome runtime simulado
    window.chrome = { runtime: {} };
  });

  // Habilitar intercepción de respuestas para capturar descargas via red
  await page.setRequestInterception(false);

  if (downloadDir) {
    ensureDirs(downloadDir);
    const client = await page.target().createCDPSession();
    await client.send('Page.setDownloadBehavior', {
      behavior: 'allow',
      downloadPath: downloadDir,
    });
    // Guardar referencia al cliente CDP en la page para usarlo después
    page._cdpClient = client;
    page._downloadDir = downloadDir;
  }

  return { browser, page };
}

function browserCandidates() {
  const envPath = process.env.SICOES_BROWSER_PATH;
  const candidates = [];
  if (envPath) candidates.push(envPath);

  if (process.platform === 'win32') {
    const local = process.env.LOCALAPPDATA || '';
    const programFiles = process.env.PROGRAMFILES || 'C:\\Program Files';
    const programFilesX86 = process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)';

    candidates.push(
      path.join(local, 'Programs', 'Opera GX', 'opera.exe'),
      path.join(programFiles, 'Opera GX', 'opera.exe'),
      path.join(programFilesX86, 'Opera GX', 'opera.exe'),
      path.join(programFiles, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(programFilesX86, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(programFiles, 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
      path.join(programFilesX86, 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
      path.join(programFiles, 'BraveSoftware', 'Brave-Browser', 'Application', 'brave.exe'),
      path.join(programFilesX86, 'BraveSoftware', 'Brave-Browser', 'Application', 'brave.exe')
    );
  } else if (process.platform === 'darwin') {
    candidates.push(
      '/Applications/Opera GX.app/Contents/MacOS/Opera',
      '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
      '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser'
    );
  } else {
    candidates.push('opera', 'opera-gx', 'google-chrome', 'google-chrome-stable', 'microsoft-edge', 'brave-browser', 'chromium', 'chromium-browser');
  }

  return candidates.filter(Boolean);
}

function resolverBrowserExecutable() {
  for (const candidate of browserCandidates()) {
    if (path.isAbsolute(candidate)) {
      if (fs.existsSync(candidate)) return candidate;
      continue;
    }

    return candidate;
  }

  return '';
}

async function cdpDisponible() {
  try {
    const response = await nodeFetch(`${CDP_URL}/json/version`);
    return response.ok;
  } catch (_) {
    return false;
  }
}

async function esperarCdpDisponible(timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    if (await cdpDisponible()) return true;
    await delay(1000);
  }

  return false;
}

async function iniciarNavegadorRealCdp(urlInicial = 'about:blank') {
  if (await cdpDisponible()) {
    console.log(`[REAL_BROWSER] CDP ya disponible en ${CDP_URL}`);
    return;
  }

  const executable = resolverBrowserExecutable();
  if (!executable) {
    throw phaseFail(5, 'No se encontro navegador Chromium real para CDP. Define SICOES_BROWSER_PATH con la ruta de Opera/Chrome/Edge.');
  }

  const profileDir = path.join(BASE_DIR, 'perfil-sicoes-real');
  ensureDirs(profileDir);

  const args = [
    `--remote-debugging-port=${CDP_PORT}`,
    `--user-data-dir=${profileDir}`,
    '--no-first-run',
    '--no-default-browser-check',
    urlInicial,
  ];

  console.log(`[REAL_BROWSER] Abriendo navegador real: ${executable}`);
  console.log(`[REAL_BROWSER] CDP: ${CDP_URL}`);
  spawn(executable, args, {
    detached: true,
    stdio: 'ignore',
    windowsHide: false,
  }).unref();

  if (!await esperarCdpDisponible(45000)) {
    throw phaseFail(5, `No se pudo conectar al navegador real por CDP en ${CDP_URL}. Cierra instancias previas o abre tu navegador con --remote-debugging-port=${CDP_PORT}.`);
  }
}

async function abrirBrowserRealCdp({ downloadDir = null } = {}) {
  await iniciarNavegadorRealCdp('https://www.sicoes.gob.bo/portal/index.php');

  let chromium;
  try {
    chromium = require('playwright').chromium;
  } catch (error) {
    throw phaseFail(5, `Playwright no esta instalado para conectar al navegador real: ${errorMessage(error)}. Ejecuta npm install en ${BASE_DIR}.`);
  }

  const browser = await chromium.connectOverCDP(CDP_URL);
  let context = browser.contexts()[0];
  if (!context) {
    context = await browser.newContext({ acceptDownloads: true });
  }

  context.setDefaultTimeout?.(30000);
  context.setDefaultNavigationTimeout?.(60000);

  let page = context.pages().find(item => /sicoes\.gob\.bo/i.test(item.url())) || context.pages()[0];
  if (!page) {
    page = await context.newPage();
  }

  page.setDefaultTimeout?.(30000);
  page.setDefaultNavigationTimeout?.(60000);
  await page.bringToFront().catch(() => {});

  if (downloadDir) {
    ensureDirs(downloadDir);
    page._downloadDir = downloadDir;
  }

  return {
    browser,
    context,
    page,
    close: async () => {
      await browser.close().catch(() => {});
    },
  };
}

async function guardarDiagnosticoPagina(page, label) {
  const dir = path.join(OUT_DIR, 'diagnosticos');
  try {
    ensureDirs(dir);
  } catch (error) {
    console.log(`[SICOES] No se pudo crear carpeta de diagnosticos: ${errorMessage(error)}`);
    return;
  }

  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const base = `${stamp}-${safeFileName(label)}`;
  const diagnosticPath = path.join(dir, `${base}.json`);
  const diagnostic = {
    capturedAt: new Date().toISOString(),
    label: redactSensitiveText(label, 200),
    url: redactUrl(page.url()),
    title: redactSensitiveText(await page.title().catch(() => ''), 300),
  };

  try {
    writeFileSafe(diagnosticPath, JSON.stringify(diagnostic, null, 2), 'utf8');
  } catch (error) {
    console.log(`[SICOES] No se pudo guardar diagnostico: ${errorMessage(error)}`);
    return;
  }

  console.log(`[SICOES] Diagnostico de pagina: ${safePathForLog(diagnosticPath)}`);
}

async function gotoSicoes(page) {
  try {
    await withRetries('abrir pagina SICOES', 2, async () => {
      try {
        await page.goto('https://www.sicoes.gob.bo/portal/index.php', {
          waitUntil: 'domcontentloaded',
          timeout: TOKEN_TIMEOUT_MS,
        });
      } catch (error) {
        const tokenVisible = await page.$('#token').catch(() => null);
        if (!tokenVisible) {
          throw error;
        }

        console.log('[SICOES] La navegacion inicial excedio el tiempo, pero el token ya esta disponible; continuando.');
      }

      const token = await page.$eval('#token', input => input.value || '').catch(() => '');
      if (!token) {
        throw phaseFail(1, 'no se encontro token de navegacion SICOES en el portal');
      }
      phaseOk(1, 'token obtenido');
      emitProgress(1, 'token OK');

      const target = `https://www.sicoes.gob.bo/portal/contrataciones/busqueda/convocatorias.php?tipo=convNacional&tipoContratacion=C&token=${encodeURIComponent(token)}`;
      try {
        try {
          await page.goto(target, {
            waitUntil: 'domcontentloaded',
            timeout: TABLE_TIMEOUT_MS,
          });
        } catch (error) {
          const tableVisible = await page.$('#tablaSimple').catch(() => null);
          if (!tableVisible) {
            throw error;
          }

          console.log('[SICOES] La navegacion de convocatorias excedio el tiempo, pero la tabla ya esta disponible; continuando.');
        }

        phaseOk(2, `URL convocatorias cargada: ${redactUrl(page.url())}`);
      } catch (error) {
        throw phaseFail(2, `no se pudo cargar URL convocatorias: ${errorMessage(error)}`);
      }

      try {
        await withTimeout(esperarTablaConvocatorias(page), TABLE_TIMEOUT_MS, 'Fase 3 tabla convocatorias');
        phaseOk(3, 'tabla #tablaSimple detectada');
      } catch (error) {
        throw phaseFail(3, `tabla #tablaSimple no detectada: ${errorMessage(error)}`);
      }
    });
  } catch (error) {
    console.log(`[SICOES] URL actual: ${redactUrl(page.url())}`);
    console.log(`[SICOES] Titulo actual: ${await page.title().catch(() => '')}`);
    await guardarDiagnosticoPagina(page, 'carga-tabla-sicoes');
    if (errorMessage(error).startsWith('[FAIL] Fase')) {
      throw error;
    }

    throw phaseFail(3, `no se pudo cargar la tabla de convocatorias SICOES en headless: ${errorMessage(error)}`);
  }
}

function extensionWordDesdeNombre(fileName, fallback = '.docx') {
  const match = String(fileName || '').match(/\.(docx?|DOCX?)$/);
  return match ? match[0].toLowerCase() : fallback;
}

function extensionArchivoDesdeNombre(fileName, fallback = '.bin') {
  const match = String(fileName || '').match(/\.(docx?|pdf)$/i);
  return match ? match[0].toLowerCase() : fallback;
}

function extensionArchivoDesdeBytes(filePath) {
  const bytes = fs.readFileSync(filePath);
  if (bytes.length >= 4 && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) return '.pdf';
  if (bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4B) return '.docx';
  if (bytes.length >= 8 &&
    bytes[0] === 0xD0 &&
    bytes[1] === 0xCF &&
    bytes[2] === 0x11 &&
    bytes[3] === 0xE0) return '.doc';
  return '';
}

function extensionArchivoDesdeBytes_buffer(buffer) {
  if (!buffer || buffer.length < 4) return '';
  if (buffer.length >= 4 && buffer[0] === 0x25 && buffer[1] === 0x50 && buffer[2] === 0x44 && buffer[3] === 0x46) return '.pdf';
  if (buffer.length >= 4 && buffer[0] === 0x50 && buffer[1] === 0x4B) return '.docx';
  if (buffer.length >= 8 &&
    buffer[0] === 0xD0 &&
    buffer[1] === 0xCF &&
    buffer[2] === 0x11 &&
    buffer[3] === 0xE0) return '.doc';
  return '';
}

function nombreDescargaWord(convocatoria, archivo, index, archivoIndex, suggestedName = '') {
  const cuce = safeFileName(convocatoria?.cuce || `sin-cuce-${index + 1}`);
  const etiqueta = safeFileName(archivo?.nombre || 'documento').slice(0, 40) || 'documento';
  const ext = extensionArchivoDesdeNombre(suggestedName, '.docx');
  return `${String(index + 1).padStart(2, '0')}_${cuce}_${String(archivoIndex + 1).padStart(2, '0')}_${etiqueta}${ext}`;
}

function documentoDescargadoExistente(inputDir, convocatoria, archivo, index, archivoIndex) {
  const expectedBase = nombreDescargaWord(convocatoria, archivo, index, archivoIndex)
    .replace(/\.(docx?|pdf)$/i, '');

  return listarDocx(inputDir).find(file =>
    path.basename(file.name, path.extname(file.name)) === expectedBase
  ) || null;
}

async function asegurarPaginaDescargaSicoes(page, options = {}) {
  const { interactive = true } = options;
  const urlActual = page.url();
  const expectedPage = ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL
    ? /requerimientoPersonal\.php/i
    : /convocatorias\.php/i;
  if (!/sicoes\.gob\.bo/i.test(urlActual) || !expectedPage.test(urlActual) || !await tablaConvocatoriasDisponible(page)) {
    if (interactive) {
      try {
        if (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL) {
          await gotoSicoesPersonnel(page, options.fecha);
        } else {
          await gotoSicoes(page);
        }
      } catch (error) {
        console.log(`[REAL_BROWSER] La navegacion automatica no dejo lista la tabla: ${errorMessage(error)}`);
        if (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL) {
          throw phaseFail(3, `no se pudo cargar automaticamente la tabla de Requerimientos de Personal: ${errorMessage(error)}`);
        }
        console.log('[REAL_BROWSER] Continua manualmente en el navegador real hasta la tabla de convocatorias.');
        await page.goto('https://www.sicoes.gob.bo/portal/index.php', {
          waitUntil: 'domcontentloaded',
          timeout: TOKEN_TIMEOUT_MS,
        }).catch(() => {});
      }
    } else {
      if (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL) {
        await gotoSicoesPersonnel(page, options.fecha);
      } else {
        await gotoSicoes(page);
      }
    }
  }

  if (interactive && !await tablaConvocatoriasDisponible(page)) {
    const section = ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? 'Requerimientos de Personal' : 'Servicios por Consultorias';
    const message = `SICOES esta abierto en navegador real con CDP.\nSi aparece Cloudflare/captcha, resuelvelo en esa ventana.\nAsegurate de ver la tabla de convocatorias (${section}).\nCuando la tabla este lista, presiona ENTER aqui.`;

    if (process.stdin.isTTY) {
      await waitEnter(message);
    } else {
      console.log(`[REAL_BROWSER] ${message.replace(/\n/g, ' ')}`);
      console.log('[REAL_BROWSER] Laravel no tiene terminal interactiva; esperando tabla automaticamente.');
      const deadline = Date.now() + MANUAL_DOWNLOAD_TIMEOUT_MS;
      let lastHeartbeat = 0;

      while (!await tablaConvocatoriasDisponible(page)) {
        if (Date.now() > deadline) {
          throw phaseFail(3, 'tabla #tablaSimple no detectada tras esperar validacion manual en navegador real');
        }

        if (Date.now() - lastHeartbeat > 30000) {
          const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
          console.log(`[REAL_BROWSER_WAITING] esperando tabla SICOES en navegador real, quedan ${remaining}s`);
          lastHeartbeat = Date.now();
        }

        await delay(1000);
      }
    }
  } else if (!interactive) {
    console.log('Modo automatico: usando la tabla de SICOES para descargar documentos.');
  } else {
    console.log('[SICOES] Tabla filtrada lista automaticamente; iniciando descargas.');
  }
}

async function esperarTablaConvocatorias(page) {
  await page.waitForSelector(`${activeTableSelector()} tbody tr`, { timeout: TABLE_TIMEOUT_MS });
}

async function tablaConvocatoriasDisponible(page) {
  return Boolean(await page.$(`${activeTableSelector()} tbody tr`).catch(() => null));
}

async function irAPaginaSiHaceFalta(page, pagina) {
  if (!pagina || Number.isNaN(Number(pagina))) return;
  await esperarTablaConvocatorias(page);
  const tableSelector = activeTableSelector();
  const paginaActual = await page.evaluate(table => {
    const current = document.querySelector(`${table}_paginate a.paginate_active, ${table}_paginate .current`);
    const n = parseInt((current?.textContent || '').trim(), 10);
    return Number.isNaN(n) ? null : n;
  }, tableSelector).catch(() => null);

  // La búsqueda inicial siempre abre en la primera página. Algunos DataTables
  // del portal no marcan visualmente el paginador activo y devolvían null,
  // provocando una espera innecesaria de hasta 17 segundos por cada fila.
  if (paginaActual === null && Number(pagina) === 1) return;

  if (paginaActual !== Number(pagina)) {
    await irAPagina(page, Number(pagina));
    await esperarTablaConvocatorias(page);
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PARCHE SICOES - Descarga via intercepción de request real con CDP
// Reemplaza las funciones: localizarLinkArchivoEnTabla, descargarViaFetchConCookies,
//                          descargarViaClickReal, descargarArchivoDesdeFila
//
// CÓMO APLICAR:
//   1. Abre sicoes.js
//   2. Busca la función: async function localizarLinkArchivoEnTabla(
//   3. Borra desde esa línea hasta el final de descargarArchivoDesdeFila() inclusive
//   4. Pega el contenido de este archivo en ese lugar
// ═══════════════════════════════════════════════════════════════════════════════

const SICOES_BASE = 'https://www.sicoes.gob.bo';
const SICOES_ALLOWED_HOSTS = new Set(['www.sicoes.gob.bo']);
const SICOES_REPLAY_METHODS = new Set(['GET', 'POST']);
const SICOES_MAX_REPLAY_BODY_BYTES = 1024 * 1024;
const SICOES_MAX_REPLAY_RESPONSE_BYTES = 100 * 1024 * 1024;
const SICOES_MAX_BROWSER_RESPONSE_BYTES = 25 * 1024 * 1024;
const SICOES_MAX_DIAGNOSTIC_RESPONSE_BYTES = 1024 * 1024;
const SICOES_REPLAY_TIMEOUT_MS = REPLAY_TIMEOUT_MS;
const SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS = DOWNLOAD_ATTEMPT_TIMEOUT_MS;

function safeSicoesRequestUrl(value) {
  try {
    const url = new URL(String(value || ''));

    if (
      url.protocol !== 'https:'
      || !SICOES_ALLOWED_HOSTS.has(url.hostname.toLowerCase())
      || (url.port && url.port !== '443')
      || url.username
      || url.password
    ) {
      return null;
    }

    return url;
  } catch (_) {
    return null;
  }
}

function capturedHeaderValue(headers, name, maxChars = 500) {
  const pair = Object.entries(headers || {})
    .find(([key]) => key.toLowerCase() === name.toLowerCase());
  const value = String(pair?.[1] || '').trim();

  if (!value || value.length > maxChars || /[\r\n\0]/.test(value)) {
    return '';
  }

  return value;
}

async function readResponseBufferLimited(response, maxBytes, abortController) {
  const contentLength = Number(response.headers.get('content-length') || 0);

  if (Number.isFinite(contentLength) && contentLength > maxBytes) {
    abortController.abort();
    throw new Error(`respuesta_http_supera_limite_${maxBytes}`);
  }

  if (!response.body) {
    return Buffer.alloc(0);
  }

  const reader = response.body.getReader();
  const chunks = [];
  let totalBytes = 0;

  try {
    while (true) {
      const { done, value } = await reader.read();

      if (done) break;

      const chunk = Buffer.from(value);
      totalBytes += chunk.length;

      if (totalBytes > maxBytes) {
        abortController.abort();
        throw new Error(`respuesta_http_supera_limite_${maxBytes}`);
      }

      chunks.push(chunk);
    }
  } finally {
    reader.releaseLock();
  }

  return Buffer.concat(chunks, totalBytes);
}

// ─── Localizar link de descarga en la tabla ────────────────────────────────────
// Retorna { token, nombre, onclick } o null.
// Sin código muerto: un solo return, sin lógica después de él.
async function localizarLinkArchivoEnTabla(page, convocatoria, archivo) {
  await esperarTablaConvocatorias(page);
  const cuce  = String(convocatoria?.cuce  || '').trim();
  const nombre = String(archivo?.nombre   || '').trim();
  const token  = String(archivo?.token    || '').trim();

  return await page.evaluate(({ cuceValue, tokenValue, nombreValue }) => {
    const tokenFromOnclick = onclick => {
      const m = String(onclick || '').match(/descargarArchivo\('([^']+)'\)/);
      return m ? m[1] : '';
    };

    const rows = Array.from(document.querySelectorAll('#tablaSimple tbody tr, #tablaAvanzada tbody tr'));
    const rowNode = rows.find(row =>
      !cuceValue || (row.innerText || '').includes(cuceValue)
    );
    if (!rowNode) return null;

    const links = Array.from(rowNode.querySelectorAll('a[onclick*="descargarArchivo"]'))
      .map(a => ({
        nombre : (a.textContent || '').trim(),
        token  : tokenFromOnclick(a.getAttribute('onclick') || ''),
        onclick: a.getAttribute('onclick') || '',
      }))
      .filter(l => l.token);

    if (!links.length) return null;

    return (
      links.find(l => tokenValue && l.token === tokenValue) ||
      links.find(l => tokenValue && l.onclick.includes(tokenValue)) ||
      links.find(l => /Documento\s+Base\s+de\s+Contrataci[oó]n/i.test(l.nombre)) ||
      links.find(l => nombreValue && l.nombre.toLowerCase().includes(nombreValue.toLowerCase())) ||
      links[0]
    );
  }, { cuceValue: cuce, tokenValue: token, nombreValue: nombre });
}

// ─── Interceptar la request real que genera window.descargarArchivo() ───────────
// Usamos CDP Network para capturar la petición exacta (URL, method, headers, body)
// que el JS del portal envía al servidor. Luego la reproducimos con fetch nativo
// para obtener el binario sin depender del sistema de descargas del browser.
async function descargarViaInterceptCDP(page, token, inputDir, convocatoria, archivo, index, archivoIndex) {
  const cuce = convocatoria?.cuce || 'sin_cuce';

  // Obtener (o crear) el cliente CDP
  const client = page._cdpClient || await page.target().createCDPSession();
  if (!page._cdpClient) page._cdpClient = client;

  // Habilitar domain de red CDP
  await client.send('Network.enable');

  console.log(`  [CDP] Habilitando intercepcion; token presente longitud=${String(token || '').length}`);

  const requestTrace = [];
  const targetTrace = [];
  let requestTraceTotal = 0;
  let targetTraceTotal = 0;
  const browser = typeof page.browser === 'function' ? page.browser() : null;
  const tracePath = path.join(inputDir, `_cdp-requests-${safeFileName(cuce)}.json`);

  const saveTrace = status => {
    const payload = {
      status,
      cuce,
      tokenPresent: Boolean(token),
      tokenLength: String(token || '').length,
      capturedAt: new Date().toISOString(),
      currentUrl: redactUrl(page.url()),
      requestCount: requestTraceTotal,
      targetCount: targetTraceTotal,
      requests: requestTrace.slice(-TRACE_REQUEST_LIMIT),
      targets: targetTrace.slice(-TRACE_TARGET_LIMIT),
    };

    try {
      writeFileSafe(tracePath, JSON.stringify(payload, null, 2), 'utf8');
      console.log(`  [CDP_TRACE] Guardado: ${safePathForLog(tracePath)} requests=${requestTraceTotal} targets=${targetTraceTotal}`);
      const recentRequests = requestTrace.slice(-20);
      recentRequests.forEach((req, idx) => {
        console.log(`  [CDP_TRACE] #${requestTraceTotal - recentRequests.length + idx + 1} ${req.method} ${req.resourceType || '-'} ${req.url}`);
      });
    } catch (error) {
      console.log(`  [CDP_TRACE] No se pudo guardar diagnostico: ${errorMessage(error)}`);
    }
  };

  const targetHandler = target => {
    const entry = {
      type: typeof target.type === 'function' ? target.type() : '',
      url: typeof target.url === 'function' ? redactUrl(target.url()) : '',
      createdAt: new Date().toISOString(),
    };
    targetTraceTotal += 1;
    targetTrace.push(entry);
    if (targetTrace.length > TRACE_TARGET_LIMIT) targetTrace.shift();
    console.log(`  [CDP_TRACE] Target nuevo: ${entry.type || '-'} ${entry.url || '(sin url)'}`);
  };

  if (browser && typeof browser.on === 'function') {
    browser.on('targetcreated', targetHandler);
  }

  // Durante 15s registramos TODO lo que sale despues de llamar descargarArchivo().
  // Si alguna request parece descarga, la guardamos como candidata para reproducirla.
  let cancelRequestCapture = () => {};
  const requestCapture = new Promise((resolve, reject) => {
    let timeout;
    let matchedRequest = null;
    let settled = false;

    const cleanup = () => {
      clearTimeout(timeout);
      client.off('Network.requestWillBeSent', handler);
      if (browser && typeof browser.off === 'function') {
        browser.off('targetcreated', targetHandler);
      }
    };

    const finish = () => {
      if (settled) return;
      settled = true;
      cleanup();
      saveTrace(matchedRequest ? 'matched' : 'timeout_sin_match');
      if (matchedRequest) {
        resolve(matchedRequest);
        return;
      }
      reject(new Error('timeout_captura_request_cdp'));
    };

    cancelRequestCapture = () => {
      if (settled) return;
      settled = true;
      cleanup();
      saveTrace('cancelled');
      resolve(null);
    };

    const handler = (params) => {
      const url = params.request?.url || '';
      const body = params.request?.postData || '';
      const method = String(params.request?.method || '').toUpperCase();
      const resourceType = params.type || '';
      const safeUrl = safeSicoesRequestUrl(url);
      const searchableUrl = safeUrl
        ? `${safeUrl.pathname}${safeUrl.search}`.toLowerCase()
        : '';
      const includesExpectedToken = Boolean(
        token && safeUrl && (body.includes(token) || safeUrl.search.includes(token))
      );

      const entry = {
        ts: new Date().toISOString(),
        method,
        url: redactUrl(url),
        resourceType,
        documentURL: redactUrl(params.documentURL || ''),
        postData: summarizePostData(body),
        initiator: {
          type: params.initiator?.type || '',
          url: redactUrl(params.initiator?.url || ''),
          lineNumber: params.initiator?.lineNumber ?? null,
          columnNumber: params.initiator?.columnNumber ?? null,
          functionName: redactSensitiveText(params.initiator?.stack?.callFrames?.[0]?.functionName || '', 200),
        },
        headers: sanitizeHeadersForDiagnostics(params.request?.headers || {}),
      };

      requestTraceTotal += 1;
      requestTrace.push(entry);
      if (requestTrace.length > TRACE_REQUEST_LIMIT) requestTrace.shift();
      console.log(`  [CDP_TRACE] ${method || '-'} ${resourceType || '-'} ${entry.url}`);

      const esDescarga = Boolean(
        safeUrl
        && SICOES_REPLAY_METHODS.has(method)
        && (
          searchableUrl.includes('descargar')
          || searchableUrl.includes('archivo')
          || includesExpectedToken
        )
      );

      if (esDescarga && !matchedRequest) {
        matchedRequest = {
          url,
          method,
          headers : params.request.headers,
          postData: params.request.postData || '',
        };
        console.log(`  [CDP] Request candidata capturada: ${method} ${redactUrl(url)}`);
      }
    };

    client.on('Network.requestWillBeSent', handler);
    timeout = setTimeout(finish, 15000);
  });

  // Disparar la función JS del portal — esto enviará la request real
  try {
    const triggered = await page.evaluate(tok => {
      if (typeof window.descargarArchivo === 'function') {
        window.descargarArchivo(tok);
        return true;
      } else {
        // Fallback: simular el submit del formulario si existe
        const form = document.getElementById('formDescargaArchivoPortal') ||
                     document.querySelector('form[action*="descargar"]');
        if (form) {
          const inp = form.querySelector('input[name="tokenarchivo"]') ||
                      form.querySelector('input[name="token"]');
          if (inp) inp.value = tok;
          form.submit();
          return true;
        } else {
          return false;
        }
      }
    }, token);

    if (!triggered) {
      cancelRequestCapture();
      console.log('  [CDP] No se encontro funcion ni formulario de descarga.');
      return null;
    }
  } catch (evalErr) {
    console.log(`  [CDP] Error al disparar descarga: ${errorMessage(evalErr)}`);
    console.log('  [CDP] Se esperara la captura por si la navegacion destruyo el contexto.');
  }

  // Esperar la captura de la request
  let reqInfo;
  try {
    reqInfo = await requestCapture;
  } catch (timeoutErr) {
    console.log(`  [CDP] ${errorMessage(timeoutErr)}`);
    return null;
  }

  const requestUrl = safeSicoesRequestUrl(reqInfo.url);
  const requestMethod = String(reqInfo.method || '').toUpperCase();
  const requestBody = String(reqInfo.postData || '');

  if (!requestUrl || !SICOES_REPLAY_METHODS.has(requestMethod)) {
    console.log('  [CDP] Request candidata rechazada por politica de destino.');
    return null;
  }

  if (Buffer.byteLength(requestBody, 'utf8') > SICOES_MAX_REPLAY_BODY_BYTES) {
    console.log('  [CDP] Request candidata rechazada por tamano de body.');
    return null;
  }

  // Obtener solo cookies aplicables al endpoint SICOES permitido, incluyendo su Path.
  const cookies = await page.cookies(requestUrl.href);
  const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
  const requestContentType = capturedHeaderValue(reqInfo.headers, 'content-type');
  const requestedWith = capturedHeaderValue(reqInfo.headers, 'x-requested-with', 100);

  // Construir headers para la request reproducida en Node.js
  const headers = {
    'Cookie'        : cookieHeader,
    'Referer'       : `${SICOES_BASE}/portal/contrataciones/busqueda/convocatorias.php`,
    'Origin'        : SICOES_BASE,
    'User-Agent'    : await page.evaluate(() => navigator.userAgent),
    'Accept'        : 'application/octet-stream, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document, */*',
    'Accept-Language': 'es-BO,es;q=0.9',
  };

  if (requestContentType) headers['Content-Type'] = requestContentType;
  if (requestedWith.toLowerCase() === 'xmlhttprequest') {
    headers['X-Requested-With'] = 'XMLHttpRequest';
  }

  console.log(`  [CDP] Reproduciendo ${requestMethod} ${redactUrl(requestUrl.href)}`);
  console.log(`  [CDP] Body: ${JSON.stringify(summarizePostData(requestBody))}`);

  const abortController = new AbortController();
  const abortTimeout = setTimeout(
    () => abortController.abort(new Error('timeout_fetch_sicoes')),
    SICOES_REPLAY_TIMEOUT_MS
  );
  let response;
  let responseBuffer = Buffer.alloc(0);
  let contentType = '';
  let contentDisp = '';

  try {
    response = await nodeFetch(requestUrl.href, {
      method : requestMethod,
      headers,
      body   : requestMethod !== 'GET' ? requestBody : undefined,
      redirect: 'manual',
      signal: abortController.signal,
    });
  } catch (fetchErr) {
    clearTimeout(abortTimeout);
    console.log(`  [CDP] Error en fetch reproducido: ${errorMessage(fetchErr)}`);
    return null;
  }

  if (response.status >= 300 && response.status < 400) {
    abortController.abort();
    clearTimeout(abortTimeout);
    console.log(`  [CDP] Redireccion HTTP bloqueada (status=${response.status}).`);
    return null;
  }

  contentType = response.headers.get('content-type') || '';
  contentDisp = response.headers.get('content-disposition') || '';
  const xDebug       = response.headers.get('x-debug')             || '';
  console.log(`  [CDP] Respuesta: ${response.status} content-type=${redactSensitiveText(contentType, 200)} content-disposition=${redactSensitiveText(contentDisp, 300)}`);
  if (xDebug) console.log('  [CDP] La respuesta incluyo cabecera X-Debug (valor omitido).');

  const esArchivoBinario = (
    contentType.includes('octet-stream')    ||
    contentType.includes('msword')          ||
    contentType.includes('wordprocessingml') ||
    contentType.includes('pdf')             ||
    contentDisp.includes('attachment')      ||
    contentDisp.includes('filename')
  );

  try {
    responseBuffer = await readResponseBufferLimited(
      response,
      response.ok && esArchivoBinario
        ? SICOES_MAX_REPLAY_RESPONSE_BYTES
        : SICOES_MAX_DIAGNOSTIC_RESPONSE_BYTES,
      abortController
    );
  } catch (readErr) {
    console.log(`  [CDP] Error al leer respuesta: ${errorMessage(readErr)}`);
    return null;
  } finally {
    clearTimeout(abortTimeout);
  }

  if (!response.ok || !esArchivoBinario) {
    // Guardar solo metadatos; el cuerpo puede contener tokens, HTML de sesion o documentos.
    try {
      const debugPath = path.join(inputDir, `_http-debug-${safeFileName(cuce)}.json`);
      const metadata = {
        capturedAt: new Date().toISOString(),
        status: response.status,
        url: redactUrl(requestUrl.href),
        contentType: redactSensitiveText(contentType, 200),
        contentDisposition: redactSensitiveText(contentDisp, 300),
        ...payloadMetadata(responseBuffer),
      };
      writeFileSafe(debugPath, JSON.stringify(metadata, null, 2), 'utf8');
      console.log(`  [CDP] Metadatos de respuesta no binaria: ${safePathForLog(debugPath)}`);
    } catch (_) {}
    return null;
  }

  // Tenemos un archivo binario — guardarlo
  const buffer = responseBuffer;
  if (buffer.length < 1000) {
    console.log(`  [CDP] Archivo muy pequeño (${buffer.length} bytes), ignorando`);
    return null;
  }

  const ext          = extensionArchivoDesdeBytes_buffer(buffer) || '.docx';
  const cdFilename   = (contentDisp.match(/filename[^;=\n]*=(['"]?)([^'";\n]*)['"]?/i) || [])[2] || '';
  const nombreArch   = cdFilename || `archivo_${safeFileName(cuce)}${ext}`;
  const destino      = path.join(inputDir, nombreDescargaWord(convocatoria, archivo, index, archivoIndex, nombreArch));

  writeFileSafe(destino, buffer);
  console.log(`  [CDP_OK] Guardado: ${safePathForLog(destino)} (${buffer.length} bytes)`);
  return { ok: true, path: destino, archivo: archivo?.nombre || '', metodo: 'cdp_intercept', size: buffer.length };
}

// ─── Descarga via fetch interno del browser (fallback si CDP falla) ────────────
// Ejecuta fetch desde dentro del contexto del navegador con credenciales include,
// probando el endpoint real deducido del JS del portal.
async function descargarViaFetchBrowser(page, token, inputDir, convocatoria, archivo, index, archivoIndex) {
  const cuce = convocatoria?.cuce || 'sin_cuce';
  console.log(`  [FETCH] Intentando fetch interno del browser para CUCE ${cuce}`);

  const resultado = await withTimeout(
    page.evaluate(async (
      tok,
      maxRequestBytes,
      maxResponseBytes,
      requestTimeoutMs
    ) => {
    const BASE = 'https://www.sicoes.gob.bo';

    // Intentar obtener la URL real del form si existe
    const form = document.getElementById('formDescargaArchivoPortal') ||
                 document.querySelector('form[action*="descargar"]');
    const formAction = form?.action || `${BASE}/portal/contrataciones/busqueda/descargarArchivo.php`;
    let safeFormAction;

    try {
      const parsed = new URL(formAction, window.location.href);

      if (
        parsed.protocol !== 'https:'
        || parsed.hostname.toLowerCase() !== 'www.sicoes.gob.bo'
        || (parsed.port && parsed.port !== '443')
        || parsed.username
        || parsed.password
      ) {
        return { ok: false, motivo: 'destino_descarga_no_permitido' };
      }

      safeFormAction = parsed.href;
    } catch (_) {
      return { ok: false, motivo: 'destino_descarga_invalido' };
    }

    // Recopilar campos ocultos del formulario
    const formFields = {};
    if (form) {
      for (const inp of form.querySelectorAll('input')) {
        if (inp.name) formFields[inp.name] = inp.value || '';
      }
    }

    const body = new URLSearchParams({ ...formFields, tokenarchivo: tok }).toString();

    if (new TextEncoder().encode(body).byteLength > maxRequestBytes) {
      return { ok: false, motivo: 'cuerpo_descarga_supera_limite' };
    }

    const abortController = new AbortController();
    const abortTimeout = setTimeout(() => abortController.abort(), requestTimeoutMs);

    try {
      const resp = await fetch(safeFormAction, {
        method     : 'POST',
        headers    : {
          'Content-Type'    : 'application/x-www-form-urlencoded',
          'Accept'          : 'application/octet-stream, application/msword, */*',
          'Accept-Language' : 'es-BO,es;q=0.9',
          'Referer'         : window.location.href,
          'Origin'          : BASE,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        redirect   : 'error',
        signal     : abortController.signal,
        body,
      });

      const ct = resp.headers.get('content-type') || '';
      const cd = resp.headers.get('content-disposition') || '';
      const esArchivo = resp.ok && (
        ct.includes('octet-stream') || ct.includes('msword') ||
        ct.includes('wordprocessingml') || ct.includes('pdf') ||
        cd.includes('attachment') || cd.includes('filename')
      );

      if (!esArchivo) {
        await resp.body?.cancel().catch(() => {});
        return { ok: false, motivo: 'respuesta_no_binaria', status: resp.status, ct };
      }

      const declaredSize = Number(resp.headers.get('content-length') || 0);
      if (Number.isFinite(declaredSize) && declaredSize > maxResponseBytes) {
        await resp.body?.cancel().catch(() => {});
        return { ok: false, motivo: 'archivo_supera_limite', size: declaredSize };
      }

      const reader = resp.body?.getReader();
      if (!reader) return { ok: false, motivo: 'respuesta_sin_stream' };

      const chunks = [];
      let totalBytes = 0;

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        totalBytes += value.byteLength;
        if (totalBytes > maxResponseBytes) {
          await reader.cancel().catch(() => {});
          return { ok: false, motivo: 'archivo_supera_limite', size: totalBytes };
        }
        chunks.push(value);
      }

      if (totalBytes < 1000) return { ok: false, motivo: 'archivo_muy_pequeno', size: totalBytes };

      // Convertir a base64 para retornar a Node.js
      const uint8 = new Uint8Array(totalBytes);
      let offset = 0;
      for (const chunk of chunks) {
        uint8.set(chunk, offset);
        offset += chunk.byteLength;
      }
      let binary   = '';
      for (let i = 0; i < uint8.length; i++) binary += String.fromCharCode(uint8[i]);
      const base64 = btoa(binary);
      const filename = (cd.match(/filename[^;=\n]*=(['"]?)([^'";\n]*)['"]?/i) || [])[2] || '';
      return { ok: true, base64, filename, endpoint: safeFormAction, ct, size: totalBytes };
    } catch (e) {
      return { ok: false, motivo: String(e?.name || 'error_fetch_browser') };
    } finally {
      clearTimeout(abortTimeout);
    }
    }, token, SICOES_MAX_REPLAY_BODY_BYTES, SICOES_MAX_BROWSER_RESPONSE_BYTES, SICOES_REPLAY_TIMEOUT_MS),
    SICOES_REPLAY_TIMEOUT_MS + 10000,
    `fetch browser CUCE ${cuce}`
  );

  if (!resultado.ok) {
    console.log(`  [FETCH] Fallo: ${resultado.motivo || '?'} status=${resultado.status || 'N/A'} bytes=${resultado.size || resultado.responseLength || 0}`);
    return null;
  }

  const buffer = Buffer.from(resultado.base64, 'base64');
  const ext    = extensionArchivoDesdeBytes_buffer(buffer) || '.docx';
  const nombre = resultado.filename || `archivo_${safeFileName(cuce)}${ext}`;
  const destino = path.join(inputDir, nombreDescargaWord(convocatoria, archivo, index, archivoIndex, nombre));
  writeFileSafe(destino, buffer);
  console.log(`  [FETCH_OK] Guardado: ${safePathForLog(destino)} (${buffer.length} bytes)`);
  return { ok: true, path: destino, archivo: archivo?.nombre || '', metodo: 'fetch_browser', size: buffer.length };
}

// ─── Función principal de descarga ────────────────────────────────────────────
// Flujo: localizar token → CDP intercept → fetch browser → timeout
function carpetasDescargaManual(inputDir) {
  const dirs = [inputDir];
  const envDir = process.env.SICOES_MANUAL_DOWNLOAD_DIR;
  if (envDir) dirs.push(path.resolve(envDir));

  const downloadsDir = path.join(os.homedir(), 'Downloads');
  if (fs.existsSync(downloadsDir)) dirs.push(downloadsDir);

  return Array.from(new Set(dirs.map(dir => path.resolve(dir)))).filter(dir => {
    try {
      ensureDirs(dir);
      return fs.existsSync(dir) && fs.statSync(dir).isDirectory();
    } catch (_) {
      return false;
    }
  });
}

function archivoTemporalDescarga(fileName) {
  return /\.(crdownload|tmp|part|download)$/i.test(fileName || '');
}

function snapshotArchivos(dirs) {
  const snapshot = new Set();

  for (const dir of dirs) {
    if (!fs.existsSync(dir)) continue;

    for (const file of fs.readdirSync(dir)) {
      snapshot.add(path.resolve(dir, file));
    }
  }

  return snapshot;
}

function esWordDescargado(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  if (['.doc', '.docx'].includes(ext)) return true;

  try {
    return ['.doc', '.docx'].includes(extensionArchivoDesdeBytes(filePath));
  } catch (_) {
    return false;
  }
}

async function esperarArchivoWordNuevo(dirs, snapshot, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastHeartbeat = 0;

  while (Date.now() < deadline) {
    if (Date.now() - lastHeartbeat > 30000) {
      const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      console.log(`[MANUAL_WAITING] esperando Word manual, quedan ${remaining}s`);
      lastHeartbeat = Date.now();
    }

    for (const dir of dirs) {
      if (!fs.existsSync(dir)) continue;

      for (const file of fs.readdirSync(dir)) {
        if (archivoTemporalDescarga(file)) continue;

        const filePath = path.resolve(dir, file);
        if (snapshot.has(filePath)) continue;

        let stat1;
        try {
          stat1 = fs.statSync(filePath);
          if (!stat1.isFile() || stat1.size < 1000) continue;
        } catch (_) {
          continue;
        }

        await delay(800);

        try {
          const stat2 = fs.statSync(filePath);
          if (!stat2.isFile() || stat2.size !== stat1.size || stat2.size < 1000) continue;
        } catch (_) {
          continue;
        }

        if (esWordDescargado(filePath)) {
          return filePath;
        }
      }
    }

    await delay(1000);
  }

  return null;
}

function guardarWordManual(filePath, inputDir, convocatoria, archivo, index, archivoIndex) {
  const suggestedName = path.basename(filePath);
  const ext = extensionArchivoDesdeBytes(filePath) || extensionArchivoDesdeNombre(suggestedName, '.docx');

  if (!['.doc', '.docx'].includes(ext)) {
    return { ok: false, motivo: 'archivo_manual_no_es_word', archivo: archivo?.nombre || '', path: filePath };
  }

  const destino = path.join(
    inputDir,
    nombreDescargaWord(convocatoria, archivo, index, archivoIndex, suggestedName).replace(/\.(docx?|pdf)$/i, ext)
  );

  if (path.resolve(filePath) !== path.resolve(destino)) {
    writeFileSafe(destino, fs.readFileSync(filePath));
  }

  return {
    ok: true,
    path: destino,
    archivo: archivo?.nombre || '',
    metodo: 'manual_asistido',
    sourcePath: filePath,
  };
}

async function resaltarDescargaManual(page, convocatoria, target) {
  return await page.evaluate(({ cuceValue, tokenValue }) => {
    document.querySelectorAll('[data-sicoes-manual-download="1"]').forEach(node => {
      node.removeAttribute('data-sicoes-manual-download');
      node.style.outline = '';
      node.style.background = '';
    });

    document.querySelectorAll('[data-sicoes-manual-row="1"]').forEach(node => {
      node.removeAttribute('data-sicoes-manual-row');
      node.style.outline = '';
      node.style.background = '';
    });

    const rows = Array.from(document.querySelectorAll('#tablaSimple tbody tr, #tablaAvanzada tbody tr'));
    const row = rows.find(item => (item.innerText || '').includes(cuceValue));
    if (!row) return false;

    const links = Array.from(row.querySelectorAll('a[onclick*="descargarArchivo"]'));
    const link = links.find(item => (item.getAttribute('onclick') || '').includes(tokenValue)) || links[0];
    if (!link) return false;

    row.setAttribute('data-sicoes-manual-row', '1');
    row.style.outline = '4px solid #f59e0b';
    row.style.background = '#fff7ed';
    link.setAttribute('data-sicoes-manual-download', '1');
    link.style.outline = '4px solid #16a34a';
    link.style.background = '#dcfce7';
    link.scrollIntoView({ block: 'center', inline: 'center' });

    return true;
  }, {
    cuceValue: String(convocatoria?.cuce || ''),
    tokenValue: String(target?.token || ''),
  }).catch(() => false);
}

async function descargarViaManualAsistida(page, target, inputDir, convocatoria, archivo, index, archivoIndex, timeoutMs = MANUAL_DOWNLOAD_TIMEOUT_MS) {
  const cuce = convocatoria?.cuce || 'sin_cuce';
  const watchDirs = carpetasDescargaManual(inputDir);
  const snapshot = snapshotArchivos(watchDirs);
  const marcado = await resaltarDescargaManual(page, convocatoria, target);

  console.log(`[MANUAL_WAIT] CUCE ${cuce} esperando descarga manual hasta ${Math.round(timeoutMs / 1000)}s`);
  console.log(`[MANUAL_ACTION] En el navegador visible resuelve Cloudflare/Turnstile si aparece y haz click en el enlace resaltado: ${target?.nombre || archivo?.nombre || 'Documento Base de Contratacion'}`);
  for (const dir of watchDirs) {
    console.log(`[MANUAL_DIR] Vigilando: ${safePathForLog(dir)}`);
  }

  if (marcado) {
    try {
      await page.click('a[data-sicoes-manual-download="1"]', { delay: 80 });
      console.log('[MANUAL_CLICK] Click inicial enviado. Si Cloudflare aparece, resuelvelo y vuelve a hacer click si hace falta.');
    } catch (error) {
      console.log(`[MANUAL_CLICK] No se pudo enviar click inicial: ${errorMessage(error)}`);
    }
  }

  const filePath = await esperarArchivoWordNuevo(watchDirs, snapshot, timeoutMs);
  if (!filePath) {
    console.log(`[MANUAL_FAIL] CUCE ${cuce} motivo: timeout_descarga_manual`);
    return { ok: false, motivo: 'timeout_descarga_manual', archivo: archivo?.nombre || target?.nombre || '' };
  }

  const resultado = guardarWordManual(filePath, inputDir, convocatoria, archivo, index, archivoIndex);
  if (resultado.ok) {
    console.log(`[MANUAL_OK] Guardado: ${safePathForLog(resultado.path)} origen omitido`);
  } else {
    console.log(`[MANUAL_FAIL] CUCE ${cuce} motivo: ${resultado.motivo}`);
  }

  return resultado;
}

function esRespuestaArchivoSicoes(response) {
  try {
    const headers = response.headers();
    const contentType = String(headers['content-type'] || '').toLowerCase();
    const contentDisposition = String(headers['content-disposition'] || '').toLowerCase();
    const url = response.url();
    const status = response.status();

    return status >= 200 && status < 400 && (
      contentType.includes('octet-stream') ||
      contentType.includes('msword') ||
      contentType.includes('wordprocessingml') ||
      contentType.includes('pdf') ||
      contentDisposition.includes('attachment') ||
      contentDisposition.includes('filename') ||
      /descargar|archivo|documento/i.test(url)
    );
  } catch (_) {
    return false;
  }
}

async function marcarLinkDescargaPlaywright(page, convocatoria, archivo) {
  await esperarTablaConvocatorias(page);
  const cuce = String(convocatoria?.cuce || '').trim();
  const nombre = String(archivo?.nombre || '').trim();
  const token = String(archivo?.token || '').trim();

  return await page.evaluate(({ cuceValue, tokenValue, nombreValue }) => {
    const tokenFromOnclick = onclick => {
      const match = String(onclick || '').match(/descargarArchivo\('([^']+)'\)/);
      return match ? match[1] : '';
    };

    document.querySelectorAll('a[data-sicoes-playwright-download="1"]').forEach(link => {
      link.removeAttribute('data-sicoes-playwright-download');
      link.style.outline = '';
      link.style.background = '';
    });

    const rows = Array.from(document.querySelectorAll('#tablaSimple tbody tr, #tablaAvanzada tbody tr'));
    const row = rows.find(item => !cuceValue || (item.innerText || '').includes(cuceValue));
    if (!row) return null;

    const links = Array.from(row.querySelectorAll('a[onclick*="descargarArchivo"]'))
      .map(link => ({
        element: link,
        nombre: (link.textContent || '').trim(),
        token: tokenFromOnclick(link.getAttribute('onclick') || ''),
        onclick: link.getAttribute('onclick') || '',
      }))
      .filter(item => item.token);

    if (!links.length) return null;

    const selected =
      links.find(item => tokenValue && item.token === tokenValue) ||
      links.find(item => tokenValue && item.onclick.includes(tokenValue)) ||
      links.find(item => /Documento\s+Base\s+de\s+Contrataci[oó]n/i.test(item.nombre || '')) ||
      links.find(item => nombreValue && (item.nombre || '').toLowerCase().includes(nombreValue.toLowerCase())) ||
      links[0];

    selected.element.setAttribute('data-sicoes-playwright-download', '1');
    selected.element.style.outline = '4px solid #16a34a';
    selected.element.style.background = '#dcfce7';
    selected.element.scrollIntoView({ block: 'center', inline: 'center' });

    return {
      nombre: selected.nombre,
      token: selected.token,
      onclick: selected.onclick,
    };
  }, { cuceValue: cuce, tokenValue: token, nombreValue: nombre });
}

async function guardarPlaywrightDownload(download, inputDir, convocatoria, archivo, index, archivoIndex) {
  const suggestedName = download.suggestedFilename();
  const identifierLabel = activeIdentifierLabel();
  console.log(`[DOWNLOAD_EVENT] ${identifierLabel} ${convocatoria?.cuce || 'sin referencia'} evento=playwright_download`);
  const tempPath = await download.path();
  console.log(`[DOWNLOAD_TEMP] ${identifierLabel} ${convocatoria?.cuce || 'sin referencia'} creado=${tempPath && fs.existsSync(tempPath) ? 1 : 0} archivo=${safePathForLog(tempPath)}`);
  const ext = tempPath && fs.existsSync(tempPath)
    ? (extensionArchivoDesdeBytes(tempPath) || extensionArchivoDesdeNombre(suggestedName, '.docx'))
    : extensionArchivoDesdeNombre(suggestedName, '.docx');
  const destino = path.join(
    inputDir,
    nombreDescargaWord(convocatoria, archivo, index, archivoIndex, suggestedName).replace(/\.(docx?|pdf)$/i, ext)
  );

  await download.saveAs(destino);
  console.log(`[DOWNLOAD_FILE_SAVED] ${identifierLabel} ${convocatoria?.cuce || 'sin referencia'} archivo=${safePathForLog(destino)}`);

  const realExt = extensionArchivoDesdeBytes(destino);
  if (!['.doc', '.docx', '.pdf'].includes(realExt)) {
    return { ok: false, motivo: 'download_tipo_no_soportado', archivo: archivo?.nombre || '', path: destino };
  }

  return { ok: true, path: destino, archivo: archivo?.nombre || '', metodo: 'playwright_download', suggestedName };
}

async function guardarPlaywrightResponse(response, inputDir, convocatoria, archivo, index, archivoIndex) {
  const headers = response.headers();
  const contentDisposition = headers['content-disposition'] || '';
  const cdFilename = (contentDisposition.match(/filename[^;=\n]*=(['"]?)([^'";\n]*)['"]?/i) || [])[2] || '';
  const buffer = await response.body();
  const ext = extensionArchivoDesdeBytes_buffer(buffer) || extensionArchivoDesdeNombre(cdFilename, '.docx');

  if (!['.doc', '.docx', '.pdf'].includes(ext) || buffer.length < 1000) {
    const debugPath = path.join(inputDir, `_playwright-response-debug-${safeFileName(convocatoria?.cuce || 'sin_cuce')}.json`);
    writeFileSafe(debugPath, JSON.stringify({
      capturedAt: new Date().toISOString(),
      status: response.status(),
      url: redactUrl(response.url()),
      headers: sanitizeHeadersForDiagnostics(headers),
      ...payloadMetadata(buffer),
    }, null, 2), 'utf8');
    return { ok: false, motivo: 'response_no_es_word', archivo: archivo?.nombre || '', path: debugPath };
  }

  const nombre = cdFilename || `archivo_${safeFileName(convocatoria?.cuce || 'sin_cuce')}${ext}`;
  const destino = path.join(inputDir, nombreDescargaWord(convocatoria, archivo, index, archivoIndex, nombre));
  writeFileSafe(destino, buffer);

  return { ok: true, path: destino, archivo: archivo?.nombre || '', metodo: 'playwright_response', size: buffer.length };
}

async function esperarPopupArchivo(popup, inputDir, convocatoria, archivo, index, archivoIndex) {
  await popup.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});

  const downloadPromise = popup.waitForEvent('download', { timeout: WORD_DOWNLOAD_TIMEOUT_MS })
    .then(download => ({ tipo: 'download', download }));
  const responsePromise = popup.waitForResponse(esRespuestaArchivoSicoes, { timeout: WORD_DOWNLOAD_TIMEOUT_MS })
    .then(response => ({ tipo: 'response', response }));

  const result = await Promise.any([downloadPromise, responsePromise]);
  if (result.tipo === 'download') {
    return await guardarPlaywrightDownload(result.download, inputDir, convocatoria, archivo, index, archivoIndex);
  }

  return await guardarPlaywrightResponse(result.response, inputDir, convocatoria, archivo, index, archivoIndex);
}

async function descargarArchivoDesdeFilaPlaywright(page, convocatoria, archivo, index, archivoIndex, inputDir) {
  const cuce = convocatoria?.cuce || 'sin_cuce';
  const identifierLabel = activeIdentifierLabel();
  const target = await marcarLinkDescargaPlaywright(page, convocatoria, archivo);

  if (!target?.token) {
    console.log(`[PW_DOWNLOAD_FAIL] ${identifierLabel} ${cuce} motivo: token_no_encontrado_en_fila`);
    return { ok: false, motivo: 'token_no_encontrado_en_fila', archivo: archivo?.nombre || '' };
  }

  console.log(`[PW_DOWNLOAD_START] ${identifierLabel} ${cuce}`);
  console.log(`[PW_DOWNLOAD_TOKEN] presente longitud=${String(target.token).length}`);
  console.log(`[PW_DOWNLOAD_NOMBRE] ${target.nombre || archivo?.nombre || '?'}`);

  const link = await page.$('a[data-sicoes-playwright-download="1"]');
  if (!link) {
    console.log(`[PW_DOWNLOAD_FAIL] ${identifierLabel} ${cuce} motivo: link_no_encontrado`);
    return { ok: false, motivo: 'link_no_encontrado', archivo: target.nombre || archivo?.nombre || '' };
  }

  let resolvePortalDialog;
  const portalDialogPromise = new Promise(resolve => {
    resolvePortalDialog = resolve;
  });
  const dialogHandler = async dialog => {
    const message = String(dialog.message?.() || '');
    const captchaRejected = /captcha|verificar/i.test(message);
    await dialog.dismiss().catch(() => {});
    resolvePortalDialog({
      tipo: 'portal_dialog',
      motivo: captchaRejected ? 'captcha_no_verificado' : 'mensaje_portal',
      mensaje: message,
    });
  };
  page.on('dialog', dialogHandler);

  const downloadPromise = page.waitForEvent('download', { timeout: WORD_DOWNLOAD_TIMEOUT_MS })
    .then(download => ({ tipo: 'download', download }));
  const responsePromise = page.waitForResponse(esRespuestaArchivoSicoes, { timeout: WORD_DOWNLOAD_TIMEOUT_MS })
    .then(response => ({ tipo: 'response', response }));
  const popupPromise = page.waitForEvent('popup', { timeout: 15000 })
    .then(popup => ({ tipo: 'popup', popup }));

  let result;
  try {
    await link.click({ timeout: 15000, force: true });
    result = await Promise.any([downloadPromise, responsePromise, popupPromise, portalDialogPromise]);
  } catch (error) {
    console.log(`[PW_DOWNLOAD_FAIL] ${identifierLabel} ${cuce} motivo: sin_download_response_popup`);
    return { ok: false, motivo: 'sin_download_response_popup', archivo: target.nombre || archivo?.nombre || '' };
  } finally {
    page.off('dialog', dialogHandler);
  }

  try {
    if (result.tipo === 'portal_dialog') {
      console.log(`[PW_DOWNLOAD_FAIL] ${identifierLabel} ${cuce} motivo: ${result.motivo}`);
      return {
        ok: false,
        motivo: result.motivo,
        archivo: target.nombre || archivo?.nombre || '',
      };
    }

    if (result.tipo === 'download') {
      const saved = await guardarPlaywrightDownload(result.download, inputDir, convocatoria, archivo, index, archivoIndex);
      if (saved.ok) console.log(`[PW_DOWNLOAD_OK] ${safePathForLog(saved.path)}`);
      return saved;
    }

    if (result.tipo === 'response') {
      const saved = await guardarPlaywrightResponse(result.response, inputDir, convocatoria, archivo, index, archivoIndex);
      if (saved.ok) console.log(`[PW_RESPONSE_OK] ${safePathForLog(saved.path)}`);
      return saved;
    }

    if (result.tipo === 'popup') {
      const saved = await esperarPopupArchivo(result.popup, inputDir, convocatoria, archivo, index, archivoIndex);
      if (saved.ok) console.log(`[PW_POPUP_OK] ${safePathForLog(saved.path)}`);
      return saved;
    }
  } catch (error) {
    console.log(`[PW_DOWNLOAD_FAIL] ${identifierLabel} ${cuce} motivo: ${errorMessage(error)}`);
    return { ok: false, motivo: errorMessage(error), archivo: target.nombre || archivo?.nombre || '' };
  }

  return { ok: false, motivo: 'evento_desconocido', archivo: target.nombre || archivo?.nombre || '' };
}

async function descargarArchivoDesdeFila(page, convocatoria, archivo, index, archivoIndex, inputDir, options = {}) {
  const cuce = convocatoria?.cuce || 'sin_cuce';
  const startedAt = Date.now();
  const strategies = [];

  // 1. Localizar el token en la tabla
  const target = await localizarLinkArchivoEnTabla(page, convocatoria, archivo);
  if (!target?.token) {
    console.log(`[DOWNLOAD_FAIL] CUCE ${cuce} motivo: token_no_encontrado_en_fila`);
    return { ok: false, motivo: 'token_no_encontrado_en_fila', archivo: archivo?.nombre || '' };
  }

  console.log(`[DOWNLOAD_START] CUCE ${cuce}`);
  console.log(`[DOWNLOAD_TOKEN] presente longitud=${String(target.token).length}`);
  console.log(`[DOWNLOAD_NOMBRE] ${target.nombre || archivo?.nombre || '?'}`);

  // 2. Estrategia principal: interceptar la request real via CDP
  const cdpStartedAt = Date.now();
  console.log(`[DOWNLOAD_STRATEGY_START] CUCE ${cuce} estrategia=cdp_intercept`);
  try {
    const cdpResult = await descargarViaInterceptCDP(
      page, target.token, inputDir, convocatoria, archivo, index, archivoIndex
    );
    if (cdpResult?.ok) {
      console.log(`[DOWNLOAD_OK] Método CDP intercept: ${safePathForLog(cdpResult.path)}`);
      console.log(`[DOWNLOAD_STRATEGY_END] CUCE ${cuce} estrategia=cdp_intercept ok=1 elapsed_ms=${Date.now() - cdpStartedAt}`);
      return { ...cdpResult, elapsed_ms: Date.now() - startedAt, estrategias: ['cdp_intercept'] };
    }
    strategies.push({ estrategia: 'cdp_intercept', ok: false, motivo: cdpResult?.motivo || 'sin_archivo' });
    console.log(`[DOWNLOAD_STRATEGY_END] CUCE ${cuce} estrategia=cdp_intercept ok=0 elapsed_ms=${Date.now() - cdpStartedAt} motivo=${cdpResult?.motivo || 'sin_archivo'}`);
  } catch (e) {
    strategies.push({ estrategia: 'cdp_intercept', ok: false, motivo: errorMessage(e) });
    console.log(`  [CDP] Error inesperado: ${errorMessage(e)}`);
  }

  // 3. Fallback: fetch desde dentro del browser con credentials include
  const fetchStartedAt = Date.now();
  console.log(`[DOWNLOAD_FALLBACK] CUCE ${cuce} estrategia=fetch_browser motivo=cdp_sin_archivo`);
  try {
    const fetchResult = await descargarViaFetchBrowser(
      page, target.token, inputDir, convocatoria, archivo, index, archivoIndex
    );
    if (fetchResult?.ok) {
      console.log(`[DOWNLOAD_OK] Método fetch browser: ${safePathForLog(fetchResult.path)}`);
      console.log(`[DOWNLOAD_STRATEGY_END] CUCE ${cuce} estrategia=fetch_browser ok=1 elapsed_ms=${Date.now() - fetchStartedAt}`);
      return { ...fetchResult, elapsed_ms: Date.now() - startedAt, estrategias: [...strategies, { estrategia: 'fetch_browser', ok: true }] };
    }
    strategies.push({ estrategia: 'fetch_browser', ok: false, motivo: fetchResult?.motivo || 'sin_archivo' });
    console.log(`[DOWNLOAD_STRATEGY_END] CUCE ${cuce} estrategia=fetch_browser ok=0 elapsed_ms=${Date.now() - fetchStartedAt} motivo=${fetchResult?.motivo || 'sin_archivo'}`);
  } catch (e) {
    strategies.push({ estrategia: 'fetch_browser', ok: false, motivo: errorMessage(e) });
    console.log(`  [FETCH] Error inesperado: ${errorMessage(e)}`);
  }

  // 4. Modo asistido: humano resuelve Cloudflare y el scraper espera el Word.
  if (options.assistedDownload) {
    const manualStartedAt = Date.now();
    console.log(`[DOWNLOAD_FALLBACK] CUCE ${cuce} estrategia=manual_asistida motivo=fallback_automatico_agotado`);
    try {
      const manualResult = await descargarViaManualAsistida(
        page,
        target,
        inputDir,
        convocatoria,
        archivo,
        index,
        archivoIndex,
        options.manualDownloadTimeoutMs || MANUAL_DOWNLOAD_TIMEOUT_MS
      );

      if (manualResult?.ok) {
        console.log(`[DOWNLOAD_STRATEGY_END] CUCE ${cuce} estrategia=manual_asistida ok=1 elapsed_ms=${Date.now() - manualStartedAt}`);
        console.log(`[DOWNLOAD_OK] Metodo manual asistido: ${safePathForLog(manualResult.path)}`);
        return { ...manualResult, elapsed_ms: Date.now() - startedAt, estrategias: [...strategies, { estrategia: 'manual_asistida', ok: true }] };
      }
      strategies.push({ estrategia: 'manual_asistida', ok: false, motivo: manualResult?.motivo || 'sin_archivo' });
    } catch (e) {
      strategies.push({ estrategia: 'manual_asistida', ok: false, motivo: errorMessage(e) });
      console.log(`  [MANUAL] Error inesperado: ${errorMessage(e)}`);
    }
  }

  // 5. Sin resultado
  console.log(`[DOWNLOAD_FAIL] CUCE ${cuce} motivo=todos_los_metodos_fallaron elapsed_ms=${Date.now() - startedAt}`);
  return {
    ok: false,
    motivo: 'todos_los_metodos_fallaron',
    archivo: target.nombre || archivo?.nombre || '',
    elapsed_ms: Date.now() - startedAt,
    estrategias: strategies,
  };
}


async function descargarWordsConvocatorias(fecha, slug, convocatorias, inputDir, options = {}) {
  ensureDirs(inputDir);

  const assistedDownload = Boolean(options.assistedDownload);
  // Las descargas protegidas por Turnstile deben ejecutarse en el perfil real
  // persistente. Una instancia Puppeteer nueva es reconocida como automatizada
  // y el portal responde "Error al verificar el captcha".
  const usePlaywrightAssisted = assistedDownload;
  const manualDownloadTimeoutMs = options.manualDownloadTimeoutMs || MANUAL_DOWNLOAD_TIMEOUT_MS;
  const browserSession = usePlaywrightAssisted
    ? await abrirBrowserRealCdp({ downloadDir: inputDir })
    : await abrirBrowserSicoes({ downloadDir: inputDir, headless: !assistedDownload });
  const { browser, page } = browserSession;
  const resultados = [];

  try {
    console.log(`\nNavegador ${assistedDownload ? 'visible asistido' : 'headless'} listo. Navegando a SICOES...`);
    if (assistedDownload) {
      console.log('[MANUAL_MODE] Descarga asistida activada. Si Cloudflare aparece, resuelvelo en el navegador visible.');
      console.log(`[MANUAL_MODE] Tiempo maximo por documento: ${Math.round(manualDownloadTimeoutMs / 1000)}s`);
    }
    await asegurarPaginaDescargaSicoes(page, { ...options, interactive: assistedDownload });
    await esperarTablaConvocatorias(page);
    // Delay inicial para que la pagina termine de estabilizarse y no se kakache
    await delay(2000);
    const totalArchivosObjetivo = convocatorias.reduce((total, convocatoria) => {
      const archivos = Array.isArray(convocatoria.archivos) ? convocatoria.archivos : [];
      const dbc = archivos.filter(archivo =>
        claveTexto(archivo.nombre || '').includes('documento base de contratacion')
      );

      return total + (dbc.length ? dbc.length : archivos.length);
    }, 0);

    if (!totalArchivosObjetivo) {
      throw phaseFail(5, 'descarga de documentos iniciada: 0 archivos disponibles en la tabla');
    }

    phaseOk(5, `descarga de documentos iniciada: ${totalArchivosObjetivo} archivos`);

    for (let i = 0; i < convocatorias.length; i++) {
      if (page.isClosed()) {
        throw new Error('La pagina headless de SICOES se cerro inesperadamente.');
      }

      const convocatoria = convocatorias[i];
      const archivos = Array.isArray(convocatoria.archivos) ? convocatoria.archivos : [];
      if (!archivos.length) continue;

      const identifierLabel = ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? 'Referencia' : 'CUCE';
      emitProgress(3, `procesando fila ${i + 1}/${convocatorias.length} ${identifierLabel}: ${convocatoria.cuce || 'sin referencia'}`, {
        index: i + 1,
        total: convocatorias.length,
        cuce: convocatoria.cuce || '',
      });
      console.log(`\n${String(i + 1).padStart(2, '0')}/${convocatorias.length} ${convocatoria.cuce || ''}`);

      if (!await tablaConvocatoriasDisponible(page)) {
        await asegurarPaginaDescargaSicoes(page, { ...options, interactive: false });
      }

      await irAPaginaSiHaceFalta(page, convocatoria.pagina);

      const archivosObjetivo = archivos.filter(archivo =>
        claveTexto(archivo.nombre || '').includes('documento base de contratacion')
      );
      const archivosADescargar = archivosObjetivo.length ? archivosObjetivo : archivos;

      for (let j = 0; j < archivosADescargar.length; j++) {
        const archivo = archivosADescargar[j];
        const existingDocument = documentoDescargadoExistente(
          inputDir,
          convocatoria,
          archivo,
          i,
          j
        );
        if (existingDocument) {
          console.log(`  Documento existente para esta ${identifierLabel.toLowerCase()}: ${archivo.nombre || `archivo ${j + 1}`}. Se omite descarga.`);
          emitProgress(4, 'Documento descargado correctamente', {
            index: i + 1,
            total: convocatorias.length,
            cuce: convocatoria.cuce || '',
            archivo: archivo.nombre || '',
            cached: true,
          });
          resultados.push({
            cuce: convocatoria.cuce || '',
            ok: true,
            archivo: archivo.nombre || 'existente',
            path: existingDocument.path,
            metodo: 'cache_documento',
          });
          continue;
        }

        console.log(`  Descargando: ${archivo.nombre || `archivo ${j + 1}`}`);

        const MAX_REINTENTOS = DOWNLOAD_ATTEMPTS;
        let resultado = null;
        let previousFailure = null;

        for (let intento = 1; intento <= MAX_REINTENTOS; intento++) {
          const attemptStartedAt = Date.now();
          console.log(`[DOWNLOAD_ATTEMPT_START] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} intento=${intento}/${MAX_REINTENTOS}`);
          try {
            const personnelReady = await personnelSearchIsReady(
              page,
              options.fecha,
              convocatoria.cuce
            );
            if (!personnelReady) {
              console.log(`[DOWNLOAD_FILTER] Restaurando el rango de publicación para ${options.fecha}.`);
              await gotoSicoesPersonnel(page, options.fecha);
              await irAPaginaSiHaceFalta(page, convocatoria.pagina);
            } else if (!await tablaConvocatoriasDisponible(page)) {
              await asegurarPaginaDescargaSicoes(page, { ...options, interactive: false });
              await irAPaginaSiHaceFalta(page, convocatoria.pagina);
            }

            if (usePlaywrightAssisted) {
              resultado = await withTimeout(
                descargarArchivoDesdeFilaPlaywright(page, convocatoria, archivo, i, j, inputDir),
                manualDownloadTimeoutMs + WORD_DOWNLOAD_TIMEOUT_MS,
                `descarga de documento ${identifierLabel} ${convocatoria.cuce || 'sin referencia'}`
              );
            } else {
              resultado = await withTimeout(
                descargarArchivoDesdeFila(page, convocatoria, archivo, i, j, inputDir, {
                  assistedDownload,
                  manualDownloadTimeoutMs,
                }),
                assistedDownload
                  ? manualDownloadTimeoutMs + SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS
                  : SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS,
                `descarga de documento ${identifierLabel} ${convocatoria.cuce || 'sin referencia'}`
              );
            }
            console.log(`[DOWNLOAD_ATTEMPT_END] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} intento=${intento} ok=${resultado.ok ? 1 : 0} elapsed_ms=${Date.now() - attemptStartedAt} motivo=${resultado.motivo || 'ok'}`);
            if (resultado.ok) break;
            if (['token_no_encontrado_en_fila', 'download_tipo_no_soportado'].includes(resultado.motivo)) {
              console.log(`[DOWNLOAD_RETRY_STOP] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} motivo_no_reintentable=${resultado.motivo}`);
              break;
            }
            if (previousFailure === resultado.motivo) {
              console.log(`[DOWNLOAD_RETRY_STOP] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} motivo_repetido=${resultado.motivo}`);
              break;
            }
            previousFailure = resultado.motivo;
            // Si fallo pero no por error de código, reintentar con pausas y asi le damos otra oportunidad jajaja
            if (intento < MAX_REINTENTOS) {
              console.log(`  Reintentando (${intento}/${MAX_REINTENTOS - 1})...`);
              await delay(2000 * intento);
            }
          } catch (error) {
            resultado = {
              ok: false,
              archivo: archivo.nombre || '',
              motivo: errorMessage(error),
              elapsed_ms: Date.now() - attemptStartedAt,
            };
            console.log(`[DOWNLOAD_ATTEMPT_END] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} intento=${intento} ok=0 elapsed_ms=${resultado.elapsed_ms} motivo=${resultado.motivo}`);
            if (previousFailure === resultado.motivo) {
              console.log(`[DOWNLOAD_RETRY_STOP] ${identifierLabel} ${convocatoria.cuce || 'sin referencia'} motivo_repetido=${resultado.motivo}`);
              break;
            }
            previousFailure = resultado.motivo;
            if (intento < MAX_REINTENTOS) {
              console.log(`  Error en intento ${intento}: ${errorMessage(error)}. Reintentando...`);
              await delay(2000 * intento);
            }
          }
        }

        resultados.push({ cuce: convocatoria.cuce || '', ...resultado });
        if (resultado.ok) {
          emitProgress(4, 'Documento descargado correctamente', {
            index: i + 1,
            total: convocatorias.length,
            cuce: convocatoria.cuce || '',
            archivo: resultado.archivo || archivo.nombre || '',
          });
        }
        console.log(resultado.ok ? `  OK: ${safePathForLog(resultado.path)}` : `  Omitido tras ${MAX_REINTENTOS} intentos: ${resultado.motivo}`);

        if (!page.isClosed()) {
          await delay(1200);
        }
      }
    }
  } finally {
    if (browserSession.close) {
      await browserSession.close().catch(() => {});
    } else {
      await browser.close().catch(() => {});
    }
  }

  const reportePath = path.join(inputDir, `_descargas-${slug}.json`);
  writeFileSafe(reportePath, JSON.stringify({
    fecha,
    total: resultados.length,
    descargados: resultados.filter(r => r.ok).length,
    resultados: resultados.map(sanitizeDownloadReportResult),
  }, null, 2), 'utf8');

  const okDownloads = resultados.filter(r => r.ok).length;
  if (!okDownloads) {
    throw phaseFail(5, 'descarga Word completada con 0 archivos validos');
  }

  console.log(`\nReporte de descargas: ${safePathForLog(reportePath)}`);
  return resultados;
}

async function procesarWords(fecha, slug, convocatorias, inputDir, options = {}) {
  const { interactive = true } = options;
  const resultsDir = path.join(RESULTADOS_DIR, slug);
  const textosDir = path.join(resultsDir, 'textos-extraidos');
  const descripcionesDir = path.join(resultsDir, 'descripciones');
  ensureDirs(resultsDir, textosDir, descripcionesDir, UNIFICADOS_DIR, FICHAS_FINALES_DIR);

  const ordenPath = path.join(CONVOCATORIAS_DIR, `${slug}-orden.txt`);
  escribirOrdenConvocatorias(convocatorias, ordenPath);

  console.log('\nCarpeta donde debes agregar los Word:');
  console.log(inputDir);
  console.log('\nGuia de orden guardada en:');
  console.log(ordenPath);
  console.log('\nSi puedes, nombra los Word como 01.docx, 02.docx, 03.docx...');
  console.log('Tambien sirve si el nombre contiene el CUCE.');

  let docxFiles = listarDocx(inputDir);

  if (!docxFiles.length) {
    if (!interactive) {
      throw phaseFail(6, `No se encontraron archivos Word para procesar en: ${inputDir}`);
    }

    await waitEnter('Copia tus archivos .docx a esa carpeta');
    docxFiles = listarDocx(inputDir);
  }

  console.log(`\nConvocatorias: ${convocatorias.length}`);
  console.log(`Word encontrados: ${docxFiles.length}`);

  const asignaciones = asignarWords(convocatorias, docxFiles);
  const resultados = [];
  const excluidas = [];
  const debugExtraccion = [];
  let totalProcesados = 0;

  for (const asignacion of asignaciones) {
    const numero = asignacion.index + 1;
    const convocatoria = asignacion.convocatoria;
    const clasificacionSinWord = clasificarTipoConvocatoria(convocatoria, '');

    if (!asignacion.docx) {
      debugExtraccion.push({
        archivo: '',
        cuce: convocatoria?.cuce || '',
        tipo_real_word: 'sin_archivo',
        metodo_extraccion: 'sin_archivo',
        fragmentos_sueldo: [],
        fragmentos_cronograma_pagos: [],
        fragmentos_ubicacion: [],
        fragmentos_duracion: [],
        fragmentos_descartados_legales: [],
        incluido: clasificacionSinWord.incluir,
        tipo_convocatoria_detectado: clasificacionSinWord.tipo,
        motivo_clasificacion: clasificacionSinWord.motivo,
        senales_individuales_detectadas: clasificacionSinWord.senales_individuales_detectadas,
        senales_empresa_detectadas: clasificacionSinWord.senales_empresa_detectadas,
        personal_clave_detectado: clasificacionSinWord.personal_clave_detectado,
      });
      const registroSinWord = crearRegistroFinal({
        numero,
        estado: 'sin_word',
        convocatoria,
        docx: null,
        clasificacion: clasificacionSinWord,
      });

      excluidas.push({
        ...registroSinWord,
        incluido: false,
        motivo_clasificacion: `${clasificacionSinWord.motivo} Sin archivo Word asociado para validar/publicar.`,
        personal_clave_detectado: clasificacionSinWord.personal_clave_detectado,
      });
      continue;
    }

    console.log(`\nProcesando ${numero}/${convocatorias.length}: ${asignacion.docx.name}`);

    const baseName = safeFileName(`${String(numero).padStart(2, '0')}_${convocatoria.cuce || 'sin-cuce'}`);
    const textoPath = path.join(textosDir, `${baseName}.txt`);
    const descripcionPath = path.join(descripcionesDir, `${baseName}.descripcion.txt`);

    try {
      const extraccion = await withTimeout(
        extraerTextoWord(asignacion.docx.path),
        WORD_PROCESS_TIMEOUT_MS,
        `procesamiento Word CUCE ${convocatoria.cuce || 'sin_cuce'}`
      );
      const textoWord = extraccion.texto;
      const textoCompletoWord = extraccion.textoCompleto || textoWord;
      const descripcion = construirDescripcion(convocatoria, textoWord);
      const debugRecord = {
        archivo: asignacion.docx.name,
        cuce: convocatoria?.cuce || '',
        tipo_real_word: extraccion.tipoReal,
        metodo_extraccion: extraccion.metodo,
        fragmentos_sueldo: [],
        fragmentos_cronograma_pagos: [],
        fragmentos_ubicacion: [],
        fragmentos_duracion: [],
        fragmentos_descartados_legales: [],
      };
      const clasificacion = clasificarTipoConvocatoria(convocatoria, textoWord);
      debugRecord.incluido = clasificacion.incluir;
      debugRecord.tipo_convocatoria_detectado = clasificacion.tipo;
      debugRecord.motivo_clasificacion = clasificacion.motivo;
      debugRecord.senales_individuales_detectadas = clasificacion.senales_individuales_detectadas;
      debugRecord.senales_empresa_detectadas = clasificacion.senales_empresa_detectadas;
      debugRecord.personal_clave_detectado = clasificacion.personal_clave_detectado;

      writeAuxFileSafe(textoPath, textoCompletoWord, 'utf8');
      writeAuxFileSafe(descripcionPath, descripcion, 'utf8');

      const registro = crearRegistroFinal({
        numero,
        estado: 'procesado',
        convocatoria,
        textoWord,
        textoCompletoWord,
        docx: asignacion.docx,
        textoPath,
        descripcionPath,
        debug: debugRecord,
        clasificacion,
      });

      if (clasificacion.incluir) {
        resultados.push(registro);
        const fichaIndividual = await generarFichasFinales({
          fecha,
          total_convocatorias: convocatorias.length,
          total_words_encontrados: docxFiles.length,
          total_procesados: totalProcesados + 1,
          total_incluidas: 1,
          total_excluidas: 0,
          resultados: [registro],
        });

        if (fichaIndividual.fichas_finales.length) {
          emitItem(numero, convocatorias.length, fichaIndividual.fichas_finales[0]);
        }
      } else {
        excluidas.push({
          ...registro,
          personal_clave_detectado: clasificacion.personal_clave_detectado,
        });
      }
      debugExtraccion.push(debugRecord);
      totalProcesados += 1;
      emitProgress(5, 'Word procesado OK', {
        index: numero,
        total: convocatorias.length,
        cuce: convocatoria.cuce || '',
        incluido: clasificacion.incluir,
      });

      console.log(`Tipo detectado: ${clasificacion.tipo}`);
      console.log(`Incluido: ${clasificacion.incluir ? 'SI' : 'NO'}`);
      console.log(`Motivo: ${clasificacion.motivo}`);
      console.log(`Tipo real: ${extraccion.tipoReal}`);
      console.log(`Metodo extraccion: ${extraccion.metodo}`);
      console.log(`Profesion detectada: ${registro.area_o_profesiones_que_buscan || 'No identificado'}`);
      console.log(`Ubicacion detectada: ${registro.ubicacion || 'No identificado'}`);
      console.log(`Sueldo detectado: ${registro.sueldo_texto === null ? 'No aplica' : (registro.sueldo_texto || 'Bs. 0,00')}`);
      console.log(`Precio referencial detectado: ${registro.precio_referencial_texto || 'Bs. 0,00'}`);

      if (extraccion.advertencia) {
        console.log(extraccion.advertencia);
      }

      if (!clasificacion.incluir) {
        console.log('Archivo excluido del resultado final.');
      }

      const detalleExtraccionPath = path.join(resultsDir, `${baseName}.meta.json`);
      writeAuxFileSafe(detalleExtraccionPath, JSON.stringify({
        numero,
        archivo: asignacion.docx.name,
        metodoAsignacion: asignacion.metodo,
        metodoExtraccion: extraccion.metodo,
        advertencia: extraccion.advertencia,
        longitudTextoMammoth: textoWord.length,
        longitudTextoCompleto: textoCompletoWord.length,
      }, null, 2), 'utf8');
    } catch (error) {
      const safeError = errorMessage(error);
      console.log(`No se pudo leer ${asignacion.docx.name}: ${safeError}`);
      emitProgress(5, `Word procesado con error: ${safeError}`, {
        index: numero,
        total: convocatorias.length,
        cuce: convocatoria.cuce || '',
        ok: false,
      });
      debugExtraccion.push({
        archivo: asignacion.docx.name,
        cuce: convocatoria?.cuce || '',
        tipo_real_word: detectarTipoWord(asignacion.docx.path),
        metodo_extraccion: 'error',
        error: safeError,
        fragmentos_sueldo: [],
        fragmentos_cronograma_pagos: [],
        fragmentos_ubicacion: [],
        fragmentos_duracion: [],
        fragmentos_descartados_legales: [],
        incluido: clasificacionSinWord.incluir,
        tipo_convocatoria_detectado: clasificacionSinWord.tipo,
        motivo_clasificacion: clasificacionSinWord.motivo,
        senales_individuales_detectadas: clasificacionSinWord.senales_individuales_detectadas,
        senales_empresa_detectadas: clasificacionSinWord.senales_empresa_detectadas,
        personal_clave_detectado: clasificacionSinWord.personal_clave_detectado,
      });

      const registroError = crearRegistroFinal({
        numero,
        estado: 'error_word',
        convocatoria,
        docx: asignacion.docx,
        error: safeError,
        clasificacion: clasificacionSinWord,
      });

      if (clasificacionSinWord.incluir) {
        resultados.push(registroError);
      } else {
        excluidas.push({
          ...registroError,
          personal_clave_detectado: clasificacionSinWord.personal_clave_detectado,
        });
      }
    }
  }

  const unificado = {
    fecha,
    total_convocatorias: convocatorias.length,
    total_words_encontrados: docxFiles.length,
    total_procesados: totalProcesados,
    total_incluidas: resultados.length,
    total_excluidas: excluidas.length,
    resultados,
  };
  phaseOk(6, `procesamiento Word completado: ${totalProcesados}/${convocatorias.length} procesados, ${resultados.length} incluidos`);

  const unificadoPath = path.join(UNIFICADOS_DIR, `${slug}.json`);
  const resumenTxtPath = path.join(UNIFICADOS_DIR, `${slug}.txt`);
  const csvPath = path.join(UNIFICADOS_DIR, `${slug}.csv`);
  const debugPath = path.join(UNIFICADOS_DIR, `debug-extraccion-${slug}.json`);
  const excluidasPath = path.join(UNIFICADOS_DIR, `excluidas-${slug}.json`);
  const legacyFichasFinalesPath = path.join(UNIFICADOS_DIR, `fichas-finales-${slug}.json`);
  const fichaFinalVisiblePath = path.join(FICHAS_FINALES_DIR, `${slug}.json`);
  const fichasFinales = await generarFichasFinales(unificado);
  const todosDescartadosCorrectamente = convocatorias.length > 0 &&
    resultados.length === 0 &&
    totalProcesados === convocatorias.length &&
    excluidas.length === convocatorias.length;

  validarFichasFinales(fichasFinales, `SICOES ${fecha}`, 7, todosDescartadosCorrectamente);

  writeRequiredFinalFile(fichaFinalVisiblePath, JSON.stringify(fichasFinales, null, 2), 'utf8');
  if (fs.existsSync(legacyFichasFinalesPath)) {
    try {
      fs.unlinkSync(legacyFichasFinalesPath);
    } catch (_) {}
  }

  if (todosDescartadosCorrectamente) {
    console.log(`[SICOES_EMPTY] ${JSON.stringify({
      date: fecha,
      total: convocatorias.length,
      excluded: excluidas.length,
      reason: 'all_documents_discarded',
    })}`);
  }

  phaseOk(7, `JSON generado con ${fichasFinales.fichas_finales.length} fichas en ${fichaFinalVisiblePath}`);

  writeAuxFileSafe(unificadoPath, JSON.stringify(unificado, null, 2), 'utf8');
  writeAuxFileSafe(excluidasPath, JSON.stringify({
    fecha,
    total_excluidas: excluidas.length,
    excluidas,
  }, null, 2), 'utf8');
  writeAuxFileSafe(debugPath, JSON.stringify(debugExtraccion, null, 2), 'utf8');
  writeAuxFileSafe(
    resumenTxtPath,
    resultados.map((r, index) => [
      `#${index + 1}`,
      `Incluido: ${r.incluido ? 'SI' : 'NO'}`,
      `Tipo convocatoria: ${r.tipo_convocatoria_detectado}`,
      `Motivo clasificacion: ${r.motivo_clasificacion}`,
      `Fecha publicacion: ${r.fecha_publicacion}`,
      `Objeto contratacion: ${r.objeto_contratacion}`,
      `Entidad: ${r.entidad}`,
      `Area/profesiones: ${r.area_o_profesiones_que_buscan}`,
      `Ubicacion: ${r.ubicacion}`,
      `Fecha expiracion: ${r.fecha_expiracion}`,
      `Sueldo: ${r.sueldo}`,
      `Sueldo texto: ${r.sueldo_texto}`,
      `Sueldo tipo: ${r.sueldo_tipo}`,
      `Tipo financiamiento: ${r.tipo_financiamiento || ''}`,
      `Cronograma pagos: ${JSON.stringify(r.cronograma_pagos || [])}`,
      `Forma adjudicacion: ${r.forma_adjudicacion || ''}`,
      `Total items: ${r.total_items || 0}`,
      `Presupuesto total asignado: ${r.presupuesto_total_asignado || ''}`,
      `Presupuesto total asignado texto: ${r.presupuesto_total_asignado_texto || ''}`,
      `Precio referencial: ${r.precio_referencial}`,
      `Precio referencial texto: ${r.precio_referencial_texto}`,
      `Archivo: ${r.archivo || 'SIN WORD'}`,
      `Lugar de trabajo: ${r.lugar_de_trabajo}`,
      `Duracion del contrato: ${r.duracion_del_contrato}`,
      `Modalidad de postulacion: ${r.modalidad_de_postulacion}`,
      `CUCE: ${r.cuce}`,
      `Fuente: ${r.fuente}`,
      r.error ? `Error: ${r.error}` : '',
      '',
      '============================================================',
      '',
    ].filter(Boolean).join('\n')).join('\n'),
    'utf8'
  );

  const csvRows = [
    [
      'fecha_publicacion',
      'incluido',
      'tipo_convocatoria_detectado',
      'motivo_clasificacion',
      'objeto_contratacion',
      'entidad',
      'area_o_profesiones_que_buscan',
      'ubicacion',
      'fecha_expiracion',
      'sueldo',
      'sueldo_texto',
      'sueldo_tipo',
      'sueldos_detectados',
      'tipo_financiamiento',
      'cronograma_pagos',
      'forma_adjudicacion',
      'total_items',
      'items_detectados',
      'presupuesto_total_asignado',
      'presupuesto_total_asignado_texto',
      'precio_referencial',
      'precio_referencial_texto',
      'archivo',
      'lugar_de_trabajo',
      'duracion_del_contrato',
      'modalidad_de_postulacion',
      'cuce',
      'fuente',
    ],
    ...resultados.map(r => [
      r.fecha_publicacion,
      r.incluido,
      r.tipo_convocatoria_detectado,
      r.motivo_clasificacion,
      r.objeto_contratacion,
      r.entidad,
      r.area_o_profesiones_que_buscan,
      r.ubicacion,
      r.fecha_expiracion,
      r.sueldo,
      r.sueldo_texto,
      r.sueldo_tipo,
      JSON.stringify(r.sueldos_detectados || []),
      r.tipo_financiamiento,
      JSON.stringify(r.cronograma_pagos || []),
      r.forma_adjudicacion,
      r.total_items,
      JSON.stringify(r.items_detectados || []),
      r.presupuesto_total_asignado,
      r.presupuesto_total_asignado_texto,
      r.precio_referencial,
      r.precio_referencial_texto,
      r.archivo,
      r.lugar_de_trabajo,
      r.duracion_del_contrato,
      r.modalidad_de_postulacion,
      r.cuce,
      r.fuente,
    ]),
  ];

  writeAuxFileSafe(csvPath, csvRows.map(row =>
    row.map(value => `"${String(value).replace(/"/g, '""')}"`).join(',')
  ).join('\n'), 'utf8');

  return {
    unificadoPath,
    fichaFinalVisiblePath,
    resumenTxtPath,
    csvPath,
    debugPath,
    excluidasPath,
    unificado,
    fichasFinales,
    noResults: todosDescartadosCorrectamente,
  };
}

async function ejecutarPipelineFull(fecha, slug, inputDir, convocatoriasPath, options = {}) {
  const assistedDownload = Boolean(options.assistedDownload);
  console.log(`\nModo FULL ${assistedDownload ? 'asistido' : 'automatico'}: extraccion -> descarga Word -> procesamiento -> ficha final.`);

  // La lectura de la tabla no requiere captcha. Mantenerla headless evita que
  // una sesión CDP inestable bloquee el lote antes de conocer los resultados.
  const convocatorias = await extraerConvocatorias(fecha, convocatoriasPath, {
    interactive: false,
    assistedDownload: false,
  });

  if (!convocatorias.length) {
    console.log(`[SICOES_EMPTY] ${JSON.stringify({ date: fecha, total: 0 })}`);

    return { empty: true };
  }

  const descargas = await descargarWordsConvocatorias(fecha, slug, convocatorias, inputDir, {
    interactive: false,
    assistedDownload,
    manualDownloadTimeoutMs: options.manualDownloadTimeoutMs || MANUAL_DOWNLOAD_TIMEOUT_MS,
    fecha,
  });
  const descargados = descargas.filter(resultado => resultado.ok).length;
  const descargasFallidas = descargas.filter(resultado => !resultado.ok).length;
  const wordFiles = listarDocx(inputDir);

  if (!descargados && !wordFiles.length) {
    throw phaseFail(5, `No se descargaron documentos para ${fecha}. No se pueden procesar fichas finales.`);
  }

  const resultado = await procesarWords(fecha, slug, convocatorias, inputDir, { interactive: false });

  if (!fs.existsSync(resultado.fichaFinalVisiblePath)) {
    throw phaseFail(7, `No se genero el JSON final esperado: ${resultado.fichaFinalVisiblePath}`);
  }

  validarFichasFinales(resultado.fichasFinales, `SICOES ${fecha}`, 7, resultado.noResults);

  if (descargasFallidas > 0) {
    console.log(`[SICOES_PARTIAL] ${JSON.stringify({
      date: fecha,
      downloaded: descargados,
      failed_downloads: descargasFallidas,
    })}`);
  }

  return resultado;
}

async function main() {
  const { flags, options, positionals } = parseArgs(process.argv.slice(2));
  const mode = String(optionValue(options, 'mode', 'modo') || '').toLowerCase();
  const fechaOption = optionValue(options, 'fecha', 'date');
  const fechaArg = fechaOption || positionals[0] || await ask('Que fecha quieres procesar? Usa dd/mm/aaaa: ');
  const fecha = fechaDisplay(fechaArg);
  const sourceOption = String(optionValue(options, 'source', 'fuente') || SOURCE_CONSULTING).toLowerCase();
  ACTIVE_SICOES_SOURCE = sourceOption === SOURCE_PERSONNEL ? SOURCE_PERSONNEL : SOURCE_CONSULTING;
  const slug = fechaSlug(fecha) + (ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? '-personal' : '');
  const inputDir = path.join(INPUT_BASE, slug);
  const convocatoriasPath = path.join(CONVOCATORIAS_DIR, `${slug}.json`);

  ensureDirs(INPUT_BASE, inputDir, OUT_DIR, CONVOCATORIAS_DIR, RESULTADOS_DIR, UNIFICADOS_DIR, FICHAS_FINALES_DIR);

  console.log('\n====================================================');
  console.log('SICOES - FLUJO UNIFICADO');
  console.log('====================================================');
  console.log(`Fecha: ${fecha}`);
  console.log(`Seccion: ${ACTIVE_SICOES_SOURCE === SOURCE_PERSONNEL ? 'Requerimientos de personal' : 'Servicios de consultoria'}`);
  console.log(`Carpeta Word: ${safePathForLog(inputDir)}`);

  let convocatorias = [];
  const fullAutomatico = mode === 'full' || flags.has('--full');
  const soloProcesar = flags.has('--procesar') || mode === 'procesar' || mode === 'process';
  const soloWord = flags.has('--solo-word') || soloProcesar;
  const forzarExtraccion = flags.has('--extraer');
  const soloPreparar = flags.has('--preparar');
  const soloDescargarWords = flags.has('--descargar-words') || mode === 'descargar-words' || mode === 'download-words';
  const descargaAsistida = ['full-assisted', 'full-asistido', 'assisted', 'asistido'].includes(mode) ||
    flags.has('--asistido') ||
    flags.has('--assisted') ||
    truthyEnv(process.env.SICOES_ASSISTED_DOWNLOAD);
  const fullAsistido = ['full-assisted', 'full-asistido', 'assisted', 'asistido'].includes(mode) ||
    (fullAutomatico && descargaAsistida);
  const fullPipeline = fullAutomatico || fullAsistido;
  const modoBatch = fullPipeline || soloProcesar || soloDescargarWords || Boolean(fechaOption);
  const usarCache = flags.has('--usar-cache') || soloWord || soloPreparar || soloDescargarWords;

  if (fullPipeline) {
    const resultado = await ejecutarPipelineFull(fecha, slug, inputDir, convocatoriasPath, {
      assistedDownload: fullAsistido,
      manualDownloadTimeoutMs: MANUAL_DOWNLOAD_TIMEOUT_MS,
    });

    console.log('\n====================================================');
    console.log('LISTO');
    console.log('====================================================');
    if (resultado.empty) {
      console.log(`Sin convocatorias publicadas en SICOES para ${fecha}.`);
      return;
    }
    console.log(`Ficha final limpia: ${safePathForLog(resultado.fichaFinalVisiblePath)}`);
    console.log(`Datos unificados JSON: ${safePathForLog(resultado.unificadoPath)}`);
    console.log(`Resumen TXT: ${safePathForLog(resultado.resumenTxtPath)}`);
    console.log(`Resumen CSV: ${safePathForLog(resultado.csvPath)}`);
    console.log(`Debug extraccion: ${safePathForLog(resultado.debugPath)}`);
    console.log(`Excluidas JSON: ${safePathForLog(resultado.excluidasPath)}`);
    console.log(`Procesados: ${resultado.unificado.total_procesados}/${resultado.unificado.total_convocatorias}`);
    console.log(`Incluidas: ${resultado.unificado.total_incluidas}`);
    console.log(`Excluidas: ${resultado.unificado.total_excluidas}`);
    return;
  }

  if (!usarCache || forzarExtraccion || !fs.existsSync(convocatoriasPath)) {
    convocatorias = await extraerConvocatorias(fecha, convocatoriasPath, { interactive: !modoBatch, assistedDownload: descargaAsistida });
  } else if (fs.existsSync(convocatoriasPath)) {
    convocatorias = JSON.parse(fs.readFileSync(convocatoriasPath, 'utf8'));
    console.log(`\nUsando convocatorias existentes: ${safePathForLog(convocatoriasPath)}`);
  } else {
    console.log('\nNo existe el JSON de convocatorias para esta fecha.');
    console.log('Ejecuta sin --solo-word, --preparar o --usar-cache para extraerlas primero.');
    return;
  }

  if (!convocatorias.length) {
    if (modoBatch) {
      throw new Error(`No hay convocatorias para ${fecha}. No se genera JSON final.`);
    }

    console.log('\nNo hay convocatorias para esa fecha. No hay Word para procesar.');
    return;
  }

  if (soloDescargarWords) {
    await descargarWordsConvocatorias(fecha, slug, convocatorias, inputDir, {
      interactive: !modoBatch,
      assistedDownload: descargaAsistida,
      manualDownloadTimeoutMs: MANUAL_DOWNLOAD_TIMEOUT_MS,
      fecha,
    });
    return;
  }

  if (soloPreparar) {
    const ordenPath = path.join(CONVOCATORIAS_DIR, `${slug}-orden.txt`);
    escribirOrdenConvocatorias(convocatorias, ordenPath);

    console.log('\nPreparacion lista.');
    console.log(`Copia tus Word en: ${safePathForLog(inputDir)}`);
    console.log(`Guia de orden: ${safePathForLog(ordenPath)}`);
    return;
  }

  const resultado = await procesarWords(fecha, slug, convocatorias, inputDir, { interactive: !modoBatch });

  console.log('\n====================================================');
  console.log('LISTO');
  console.log('====================================================');
  console.log(`Ficha final limpia: ${safePathForLog(resultado.fichaFinalVisiblePath)}`);
  console.log(`Datos unificados JSON: ${safePathForLog(resultado.unificadoPath)}`);
  console.log(`Resumen TXT: ${safePathForLog(resultado.resumenTxtPath)}`);
  console.log(`Resumen CSV: ${safePathForLog(resultado.csvPath)}`);
  console.log(`Debug extraccion: ${safePathForLog(resultado.debugPath)}`);
  console.log(`Excluidas JSON: ${safePathForLog(resultado.excluidasPath)}`);
  console.log(`Procesados: ${resultado.unificado.total_procesados}/${resultado.unificado.total_convocatorias}`);
  console.log(`Incluidas: ${resultado.unificado.total_incluidas}`);
  console.log(`Excluidas: ${resultado.unificado.total_excluidas}`);
}

main().catch(error => {
  console.error(safeErrorDetails(error));
  process.exitCode = 1;
});
