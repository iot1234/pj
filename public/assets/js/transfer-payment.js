/* Separate transfer instructions from verification: neither QR nor LINE proves payment. */
(() => {
  'use strict';
  const cents = (value) => {
    if (typeof value !== 'string' || !/^\d{1,12}\.\d{2}$/.test(value)) return null;
    const n = Number(value.replace('.', ''));
    return Number.isSafeInteger(n) ? n : null;
  };
  function validInstruction(qr, billId) {
    const base = cents(qr?.bill_amount), delta = cents(qr?.adjustment_amount), amount = cents(qr?.amount);
    return Number(qr?.bill_id) === Number(billId) && qr?.amount_locked === true
      && base !== null && base > 0 && delta !== null && delta >= 1 && delta <= 99
      && amount === base + delta && typeof qr.payload === 'string'
      && qr.payload.length <= 512 && /^000201/.test(qr.payload) && /6304[0-9A-F]{4}$/.test(qr.payload);
  }
  function safeLineFallback(value) {
    if (value?.available !== true || typeof value.url !== 'string' || value.url.length > 4000
      || !/^https:\/\/line\.me\/R\/oaMessage\/%40[A-Za-z0-9._-]{1,32}\/\?[^#\s]*$/.test(value.url)
      || typeof value.message !== 'string' || value.message.length > 600) return null;
    try { if (decodeURIComponent(value.url.split('/?')[1]) !== value.message) return null; }
    catch (_) { return null; }
    return { url: value.url, message: value.message };
  }
  function recoveryStore() {
    const key = 'dormitory:uncertain-payment-uploads';
    const pending = new Set();
    let storage;
    try {
      storage = window.sessionStorage;
      const saved = JSON.parse(storage?.getItem(key) || '[]');
      if (Array.isArray(saved)) saved.filter(id => typeof id === 'string' && /^[1-9]\d{0,18}$/.test(id)).forEach(id => pending.add(id));
    } catch (_) { /* Private browsing may disallow storage; retain in-memory protection. */ }
    const persist = () => { try { storage?.setItem(key, JSON.stringify([...pending])); } catch (_) {} };
    return Object.freeze({
      has: id => pending.has(String(id)),
      add(id) { pending.add(String(id)); persist(); },
      delete(id) { pending.delete(String(id)); persist(); },
    });
  }
  window.DormTransferPayment = Object.freeze({ cents, validInstruction, safeLineFallback, recoveryStore });
})();
