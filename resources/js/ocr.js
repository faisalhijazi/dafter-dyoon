/**
 * Invoice reading (OCR) — runs entirely in the browser with tesseract.js served from
 * /vendor/tesseract (copied by scripts/copy-ocr-assets.mjs). The photo never leaves the device.
 *
 * Loaded on demand: app.js imports this module only when the merchant taps «قراءة من فاتورة».
 */
import { createWorker } from 'tesseract.js';

const BASE = '/vendor/tesseract';

// Words that usually sit next to the amount to pay on an invoice / receipt.
const TOTAL_WORDS = /(المجموع|الاجمالي|الإجمالي|اجمالي|إجمالي|الصافي|المطلوب|المستحق|الاجمال|total|amount|net|sum|due)/i;

let workerPromise = null;

function getWorker(onProgress) {
    workerPromise ??= createWorker(['ara', 'eng'], 1, {
        workerPath: `${BASE}/worker.min.js`,
        corePath: `${BASE}/core`,
        langPath: `${BASE}/lang`,
        logger: (m) => onProgress?.(m),
    });

    return workerPromise;
}

/** Arabic-Indic / Persian digits → Latin, Arabic decimal/thousands separators → '.' / ','. */
function normalizeDigits(text) {
    return text
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/٫/g, '.')
        .replace(/٬/g, ',');
}

/** Numbers in a line: "1,250.50" → 1250.5; ignores phone-like digit runs and years. */
function numbersIn(line) {
    const found = [];
    for (const match of line.matchAll(/\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?/g)) {
        const raw = match[0];
        const digitsOnly = raw.replace(/\D/g, '');
        if (!raw.includes('.') && !/[,\s]/.test(raw) && digitsOnly.length >= 7) {
            continue; // phone / invoice number
        }
        const value = parseFloat(raw.replace(/[,\s]/g, ''));
        if (Number.isFinite(value) && value > 0 && value < 10_000_000) {
            found.push(value);
        }
    }
    return found;
}

function findDate(text) {
    let m = text.match(/\b(20\d{2})[/.-](\d{1,2})[/.-](\d{1,2})\b/);
    if (m) {
        return iso(m[1], m[2], m[3]);
    }
    m = text.match(/\b(\d{1,2})[/.-](\d{1,2})[/.-](20\d{2}|\d{2})\b/);
    if (m) {
        const year = m[3].length === 2 ? `20${m[3]}` : m[3];
        return iso(year, m[2], m[1]); // day/month/year, as used in the region
    }
    return null;
}

function iso(y, mo, d) {
    const month = Number(mo);
    const day = Number(d);
    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return null;
    }
    const date = `${y}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return new Date(date) <= new Date(Date.now() + 86_400_000) ? date : null; // never in the future
}

export function parseInvoice(rawText) {
    const text = normalizeDigits(rawText);
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

    // 1) A number on (or right after) a "total" line; the last such line is usually the grand total.
    let amount = null;
    lines.forEach((line, i) => {
        if (TOTAL_WORDS.test(line)) {
            const candidates = numbersIn(line).concat(numbersIn(lines[i + 1] ?? ''));
            if (candidates.length) {
                amount = Math.max(...candidates);
            }
        }
    });

    // 2) Otherwise the largest plausible amount on the page, preferring ones with decimals.
    if (amount === null) {
        const all = lines.flatMap(numbersIn).filter((n) => n < 1_000_000);
        const withDecimals = all.filter((n) => !Number.isInteger(n));
        const pool = withDecimals.length ? withDecimals : all;
        amount = pool.length ? Math.max(...pool) : null;
    }

    return { amount, date: findDate(text), text };
}

export async function scanInvoice(file, onProgress) {
    const worker = await getWorker(onProgress);
    const { data } = await worker.recognize(file);

    return parseInvoice(data.text || '');
}
