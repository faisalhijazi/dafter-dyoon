/**
 * Copy text to the clipboard on every page, including plain-HTTP ones.
 *
 * navigator.clipboard only exists on secure origins (HTTPS or localhost), so on
 * http://accounting.test or a phone on the LAN it is undefined and copying silently
 * failed. Fall back to the classic hidden-textarea + execCommand('copy') technique.
 *
 * @returns {Promise<boolean>} whether the text was copied
 */
window.ddCopy = async function (text) {
    text = String(text ?? '');

    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            // Permission denied or document not focused: try the fallback below.
        }
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    // Keep it off-screen without display:none (hidden elements can't be selected), and avoid iOS zoom.
    area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;';
    document.body.appendChild(area);

    const selection = document.getSelection();
    const previous = selection && selection.rangeCount ? selection.getRangeAt(0) : null;

    area.focus();
    area.select();
    area.setSelectionRange(0, text.length); // iOS Safari needs an explicit range

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (e) {
        copied = false;
    }

    document.body.removeChild(area);
    if (previous && selection) {
        selection.removeAllRanges();
        selection.addRange(previous);
    }

    return copied;
};

/**
 * Copy + toast, used by the copy buttons: x-on:click="ddCopyToast(text, 'تم نسخ الرابط')".
 * A plain global (not an Alpine magic) so it works whatever order Livewire/Alpine boot in.
 */
window.ddCopyToast = async function (text, message = 'تم النسخ') {
    const copied = await window.ddCopy(text);

    window.dispatchEvent(new CustomEvent('toast', {
        detail: copied
            ? { message, type: 'success' }
            : { message: 'تعذّر النسخ تلقائياً، حدّد النص وانسخه يدوياً', type: 'error' },
    }));

    return copied;
};

/**
 * x-data="ddDismissToday('key')": a banner the user can hide until tomorrow.
 * Storage may be unavailable (private mode), so every access is guarded.
 */
window.ddDismissToday = function (key) {
    let hidden = false;
    try {
        hidden = localStorage.getItem(key) === new Date().toDateString();
    } catch (e) {
        hidden = false;
    }

    return {
        hidden,
        dismiss() {
            this.hidden = true;
            try {
                localStorage.setItem(key, new Date().toDateString());
            } catch (e) {
                // not persisted; hidden for this page view only
            }
        },
    };
};

/**
 * «قراءة من فاتورة»: x-data="ddInvoiceReader()" on the add-entry sheet. Reads the photo in the
 * browser (OCR), fills amount + date and attaches the photo to the entry.
 */
window.ddScanInvoice = async function (file, onProgress) {
    const { scanInvoice } = await import('./ocr.js'); // loaded only when used

    return scanInvoice(file, onProgress);
};

window.ddInvoiceReader = function () {
    return {
        busy: false,
        status: '',
        async read(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file || this.busy) {
                return;
            }

            this.busy = true;
            this.status = 'جاري تجهيز القارئ…';

            try {
                const result = await window.ddScanInvoice(file, (m) => {
                    if (m.status === 'recognizing text') {
                        this.status = `جاري قراءة الفاتورة… ${Math.round(m.progress * 100)}%`;
                    } else if (m.status?.includes('loading')) {
                        this.status = 'جاري تحميل محرك القراءة (مرة واحدة)…';
                    }
                });

                if (result.amount) {
                    this.$wire.set('amount', String(result.amount));
                }
                if (result.date) {
                    this.$wire.set('date', result.date);
                }
                this.$wire.upload('photo', file);

                this.status = result.amount
                    ? `✓ المبلغ ${result.amount}${result.date ? ' — التاريخ ' + result.date : ''}. راجعه قبل الحفظ.`
                    : 'لم أجد مبلغاً واضحاً، أدخله يدوياً (أُرفقت الصورة).';
            } catch (e) {
                console.error(e);
                this.status = 'تعذرت قراءة الصورة، جرّب صورة أوضح.';
            } finally {
                this.busy = false;
            }
        },
    };
};

/**
 * Voice input for «مساعد AI»: x-data="ddVoice()". Uses the browser's own speech recognition
 * (Chrome/Edge/Safari). The text lands in the message box for review before sending.
 */
window.ddVoice = function () {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    return {
        supported: Boolean(Recognition),
        listening: false,
        recognition: null,
        toggle(input) {
            if (this.listening) {
                this.recognition?.stop();
                return;
            }

            const recognition = new Recognition();
            recognition.lang = 'ar-JO'; // Levantine Arabic; also understands MSA
            recognition.interimResults = true;
            recognition.continuous = false;

            const before = input.value ? input.value.trim() + ' ' : '';
            recognition.onresult = (event) => {
                const said = Array.from(event.results).map((r) => r[0].transcript).join('');
                input.value = before + said;
                input.dispatchEvent(new Event('input')); // keeps wire:model + auto-height in sync
            };
            recognition.onerror = (event) => {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'اسمح للمتصفح باستخدام الميكروفون لتفعيل الإدخال الصوتي', type: 'error' } }));
                }
            };
            recognition.onend = () => {
                this.listening = false;
                input.focus();
            };

            this.recognition = recognition;
            this.listening = true;
            recognition.start();
        },
    };
};

/* ----------------------------------------------------------------------------------------------
 * Offline entry: entries recorded without a connection wait in this device's storage and are
 * sent to /app/offline/sync when back online. Each carries a UUID, so a retried sync is harmless.
 * ------------------------------------------------------------------------------------------- */
const OFFLINE_QUEUE = 'dd-offline-queue';
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * RFC 4122 v4 UUID. crypto.randomUUID() only exists on secure pages (HTTPS / localhost), so on
 * plain-HTTP it is built from crypto.getRandomValues(), which every browser exposes.
 */
function ddUuid() {
    if (window.crypto?.randomUUID && window.isSecureContext) {
        return window.crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (window.crypto?.getRandomValues) {
        window.crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC 4122 variant
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

window.ddOfflineQueue = {
    all() {
        let entries;
        try {
            entries = JSON.parse(localStorage.getItem(OFFLINE_QUEUE) || '[]');
        } catch (e) {
            return [];
        }

        // Repair entries queued by an older version with a non-UUID id (they were never saved on the
        // server — it rejected them — so giving them a fresh id is safe).
        let repaired = false;
        for (const entry of entries) {
            if (!UUID_RE.test(entry.uuid || '')) {
                entry.uuid = ddUuid();
                delete entry.error;
                repaired = true;
            }
        }
        if (repaired) {
            this.save(entries);
        }

        return entries;
    },
    save(entries) {
        try {
            localStorage.setItem(OFFLINE_QUEUE, JSON.stringify(entries));
            return true;
        } catch (e) {
            return false;
        }
    },
    add(entry) {
        const uuid = ddUuid();
        const entries = this.all();
        entries.push({ uuid, occurred_at: new Date().toISOString(), ...entry });
        return this.save(entries) ? uuid : null;
    },
    remove(uuid) {
        this.save(this.all().filter((e) => e.uuid !== uuid));
    },
};

/**
 * Sends queued entries; returns { saved, rejected, error }. Rejected entries stay with their message.
 * Automatic syncs skip rejected entries; a manual «مزامنة الآن» (retryRejected) sends them again.
 */
window.ddOfflineSync = async function (url, token, retryRejected = false) {
    const pending = window.ddOfflineQueue.all().filter((e) => retryRejected || !e.error);
    if (!pending.length || !navigator.onLine) {
        return { saved: 0, rejected: 0 };
    }

    const post = (csrf) => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ entries: pending.map(({ error, account_name, currency_code, ...e }) => e) }),
    });

    let response = await post(token);

    // Session token expired while offline: fetch a fresh one from the offline page and retry once.
    if (response.status === 419) {
        const page = await fetch('/app/offline', { credentials: 'same-origin' });
        const fresh = page.redirected ? null : (await page.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
        if (!fresh) {
            // No fresh token means the session itself ended.
            return { saved: 0, rejected: 0, error: 'انتهت الجلسة — سجّل الدخول ثم اضغط «مزامنة الآن». معاملاتك محفوظة على الجهاز.' };
        }
        response = await post(fresh);
    }

    if (response.status === 401 || response.redirected) {
        return { saved: 0, rejected: 0, error: 'سجّل الدخول ثم اضغط «مزامنة الآن».' };
    }
    if (!response.ok) {
        return { saved: 0, rejected: 0, error: 'تعذرت المزامنة، سنحاول لاحقاً.' };
    }

    const { results } = await response.json();
    let saved = 0;
    let rejected = 0;
    const byUuid = Object.fromEntries(results.map((r) => [r.uuid, r]));
    const remaining = window.ddOfflineQueue.all().filter((entry) => {
        const r = byUuid[entry.uuid];
        if (!r) {
            return true;
        }
        if (r.status === 'saved' || r.status === 'duplicate') {
            saved += r.status === 'saved' ? 1 : 0;
            return false;
        }
        rejected++;
        entry.error = r.message || 'رُفضت';
        return true;
    });
    window.ddOfflineQueue.save(remaining);

    return { saved, rejected };
};

/** Runs on every tenant page: register the service worker and flush the queue when online. */
window.ddOfflineBoot = function (syncUrl, token) {
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').catch(() => null);
    }

    const flush = async () => {
        const result = await window.ddOfflineSync(syncUrl, token).catch(() => null);
        if (result?.saved) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: `تمت مزامنة ${result.saved} معاملة أُدخلت بدون إنترنت`, type: 'success' } }));
        }
        if (result?.rejected) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: `${result.rejected} معاملة لم تُقبل — راجعها في صفحة الإدخال بدون إنترنت`, type: 'error' } }));
        }
    };

    window.addEventListener('online', flush);
    flush();
};

/** The offline entry page: x-data="ddOffline(config)". */
window.ddOffline = function (config) {
    return {
        online: navigator.onLine,
        accounts: config.accounts,
        currencies: config.currencies,
        search: '',
        account: null,
        amount: '',
        currencyId: config.currencies[0]?.id ?? null,
        notes: '',
        queue: window.ddOfflineQueue.all(),
        message: '',
        syncing: false,
        init() {
            window.addEventListener('online', () => { this.online = true; this.sync(); });
            window.addEventListener('offline', () => { this.online = false; });
            if (this.online && this.queue.length) {
                this.sync();
            }
        },
        get matches() {
            const q = this.search.trim();
            return (q ? this.accounts.filter((a) => a.name.includes(q) || (a.phone || '').includes(q)) : this.accounts).slice(0, 30);
        },
        currencyCode(id) {
            return this.currencies.find((c) => c.id === id)?.code ?? '';
        },
        record(type) {
            const amount = parseFloat(String(this.amount).replace(/[٠-٩]/g, (d) => d.charCodeAt(0) - 0x0660).replace(',', '.'));
            if (!this.account || !(amount > 0)) {
                this.message = !this.account ? 'اختر الشخص أولاً.' : 'أدخل مبلغاً صحيحاً.';
                return;
            }
            const uuid = window.ddOfflineQueue.add({
                account_id: this.account.id,
                account_name: this.account.name,
                type,
                amount,
                currency_id: this.currencyId,
                currency_code: this.currencyCode(this.currencyId),
                notes: this.notes.trim() || null,
            });
            if (!uuid) {
                this.message = 'تعذر الحفظ على الجهاز (المساحة ممتلئة؟).';
                return;
            }
            this.message = `✓ حُفظت على جهازك: ${type === 'debit' ? 'عليه' : 'له'} ${amount} ${this.currencyCode(this.currencyId)} — ${this.account.name}`;
            this.amount = '';
            this.notes = '';
            this.account = null;
            this.search = '';
            this.queue = window.ddOfflineQueue.all();
            if (this.online) {
                this.sync();
            }
        },
        remove(uuid) {
            window.ddOfflineQueue.remove(uuid);
            this.queue = window.ddOfflineQueue.all();
        },
        async sync(manual = false) {
            if (this.syncing) {
                return;
            }
            if (!navigator.onLine) {
                this.message = 'لا يوجد اتصال بالإنترنت حالياً، ستُرفع المعاملات تلقائياً عند عودته.';
                return;
            }
            this.syncing = true;
            try {
                const result = await window.ddOfflineSync(config.syncUrl, config.token, manual);
                this.message = result.error
                    ?? (result.saved || result.rejected
                        ? `✓ تم رفع ${result.saved} معاملة${result.rejected ? ` — ${result.rejected} لم تُقبل (السبب ظاهر بجانبها)` : ''}.`
                        : (manual ? 'لا توجد معاملات بانتظار الرفع.' : this.message));
            } catch (e) {
                this.message = 'تعذرت المزامنة، سنحاول عند عودة الاتصال.';
            } finally {
                this.queue = window.ddOfflineQueue.all();
                this.syncing = false;
            }
        },
    };
};
