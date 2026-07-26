#!/usr/bin/env node
/**
 * SET index scraper — bypasses Imperva/Incapsula by driving a real browser.
 *
 * Strategy: navigate to a normal SET page so Chromium executes the Incapsula JS
 * challenge and receives clearance cookies, then call the JSON API *from the page
 * context* (fetch with credentials) so it inherits those cookies.
 *
 * Owns the browser lifecycle AND the poll/retry loop so the browser + cleared
 * session are reused across attempts (relaunching would re-trigger the challenge).
 *
 * Output contract: exactly one JSON object on stdout.
 *   success -> { ok: true, httpStatus, marketStatus, marketDateTime,
 *                index: { last, open, value }, computed2d, stabilized, attempts }
 *   failure -> { ok: false, error, attempts }  (also exits non-zero)
 *
 * The `computed2d` here is only used to decide stabilization; the Laravel side
 * re-derives the authoritative 2D from the raw index/value fields.
 */
import { chromium } from 'playwright';

function parseArgs(argv) {
  const args = {
    mode: 'retry', // 'poll' (open sessions) | 'retry' (close sessions)
    indexField: 'last', // 'last' | 'open'
    symbol: 'SET',
    pollInterval: 12, // seconds between attempts
    maxDuration: 90, // poll mode: total seconds budget
    maxAttempts: 5, // retry mode: attempt cap
    stableStreak: 2, // poll mode: consecutive equal reads to confirm stable
    warmupUrl: 'https://www.set.or.th/en/market/product/stock/overview',
    apiUrl: 'https://www.set.or.th/api/set/index/info/list?type=INDEX',
    navTimeout: 45000,
  };
  for (const raw of argv.slice(2)) {
    const m = raw.match(/^--([^=]+)=(.*)$/);
    if (!m) continue;
    const key = m[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase());
    const val = m[2];
    if (key in args && typeof args[key] === 'number') args[key] = Number(val);
    else args[key] = val;
  }
  return args;
}

// Mirror of the PHP TwoDCalculator, used only for stabilization comparison.
function compute2d(indexStr, valueStr) {
  const index = String(indexStr).replace(/,/g, '');
  const value = String(valueStr).replace(/,/g, '');
  const frac = index.includes('.') ? index.split('.')[1] : '';
  const digit1 = frac.length > 0 ? frac[frac.length - 1] : '0';
  const intPart = value.split('.')[0].replace(/[^0-9]/g, '');
  const digit2 = intPart.length > 0 ? intPart[intPart.length - 1] : '0';
  return `${digit1}${digit2}`;
}

const sleep = (s) => new Promise((r) => setTimeout(r, s * 1000));

async function fetchIndex(page, apiUrl, symbol) {
  const result = await page.evaluate(async (args) => {
    const r = await fetch(args.apiUrl, {
      headers: { Accept: 'application/json, text/plain, */*' },
      credentials: 'include',
    });
    let body = null;
    try {
      body = await r.json();
    } catch {
      body = null;
    }
    return { status: r.status, body };
  }, { apiUrl });

  if (result.status !== 200 || !result.body) {
    throw new Error(`API returned HTTP ${result.status}`);
  }

  const rows = result.body.indexIndustrySectors || result.body.result || [];
  const row = rows.find((x) => (x.symbol || x.querySymbol) === symbol);
  if (!row) {
    throw new Error(`Symbol ${symbol} not present in payload`);
  }
  return { status: result.status, row };
}

function snapshotFrom(row, indexField) {
  const index = row[indexField] ?? row.last;
  const value = row.value;
  return {
    index: {
      last: row.last != null ? String(row.last) : null,
      open: row.open != null ? String(row.open) : null,
      value: value != null ? String(value) : null,
    },
    marketStatus: row.marketStatus ?? null,
    marketDateTime: row.marketDateTime ?? null,
    computed2d: compute2d(index, value),
  };
}

async function run() {
  const args = parseArgs(process.argv);
  const browser = await chromium.launch({
    args: ['--no-sandbox', '--disable-blink-features=AutomationControlled'],
  });
  const context = await browser.newContext({
    userAgent:
      'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
    locale: 'en-US',
    viewport: { width: 1366, height: 768 },
  });
  const page = await context.newPage();

  let attempts = 0;
  let last = null;

  try {
    await page.goto(args.warmupUrl, { waitUntil: 'domcontentloaded', timeout: args.navTimeout });
    await sleep(6); // let the Incapsula challenge run and set clearance cookies

    if (args.mode === 'poll') {
      // Open sessions: values oscillate. Poll until N consecutive equal 2D.
      const deadline = Date.now() + args.maxDuration * 1000;
      let streak = 0;
      let prev = null;
      while (Date.now() < deadline) {
        attempts++;
        const { row } = await fetchIndex(page, args.apiUrl, args.symbol);
        const snap = snapshotFrom(row, args.indexField);
        last = snap;
        streak = prev !== null && prev === snap.computed2d ? streak + 1 : 1;
        prev = snap.computed2d;
        if (streak >= args.stableStreak) {
          emit({ ok: true, httpStatus: 200, ...snap, stabilized: true, attempts });
          return;
        }
        if (Date.now() + args.pollInterval * 1000 < deadline) await sleep(args.pollInterval);
        else break;
      }
      // Timed out without stabilizing — return the last read, flagged unstable.
      if (last) {
        emit({ ok: true, httpStatus: 200, ...last, stabilized: false, attempts });
        return;
      }
      throw new Error('Poll produced no readings');
    }

    // Close sessions: retry until a reading succeeds (latency tolerance).
    let lastError = null;
    while (attempts < args.maxAttempts) {
      attempts++;
      try {
        const { row } = await fetchIndex(page, args.apiUrl, args.symbol);
        const snap = snapshotFrom(row, args.indexField);
        emit({ ok: true, httpStatus: 200, ...snap, stabilized: true, attempts });
        return;
      } catch (e) {
        lastError = e;
        if (attempts < args.maxAttempts) await sleep(args.pollInterval);
      }
    }
    throw lastError ?? new Error('Retry exhausted with no reading');
  } finally {
    await browser.close();
  }
}

function emit(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

run().catch((e) => {
  emit({ ok: false, error: e.message, attempts: 0 });
  process.exit(1);
});
