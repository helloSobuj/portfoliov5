/* Techill API client, shared by index.html, dashboard.html and admin.html.
   Talks to api.php. When api.php is not reachable (a static preview with no PHP),
   it switches to a demo backend that lives in this browser only. */
window.TechillAPI = (() => {
  let csrf = "", demo = false, ready = null;
  const BN = s => String(s).replace(/\d/g, d => "০১২৩৪৫৬৭৮৯"[d]);
  const STAGES = ["তথ্য জমা", "পেমেন্ট যাচাই", "সেটআপ চলছে", "রিভিউ", "ডেলিভারি"];

  /* ---------- pricing, shared by the checkout and the demo backend (api.php has a PHP copy) ---------- */
  function compute(cat, sel) {
    const S = cat.stacks[sel.stack], p = S.packs[sel.pack], add = {...sel.add};
    const incl = k => (p.incl || []).includes(k);
    if (add.capi && !incl("pixel")) add.pixel = true;
    const lines = [{label: `${p.name} প্যাকেজ`, amt: p.price}];
    let gw = false;
    for (const a of cat.addons) {
      if (a.only && a.only !== sel.stack) continue;
      const v = add[a.key]; if (!v) continue;
      const price = a.key === "domain" ? S.domainPrice : a.price;
      if (a.qty) lines.push({label: `${a.name} × ${BN(v)}`, amt: price * v});
      else if (!incl(a.key)) { lines.push({label: a.name, amt: price}); if (a.gw) gw = true; }
    }
    return {lines, total: lines.reduce((s, l) => s + l.amt, 0), hours: p.hours + (gw ? cat.gateway_extra_hours : 0), gw,
      products: p.products + 25 * (add.products25 || 0)};
  }

  /* ---------- domains: which extensions a package may pick (api.php has the same rule) ---------- */
  const TLDS_BASIC = ["shop", "top", "store", "online", "site", "website"], TLDS_PREMIUM = ["com"];
  function allowedTlds(cat, pack) {
    const prem = pack.premium_tlds ?? (pack.incl || []).includes("domain");
    return [...new Set([...(cat.tlds_basic || TLDS_BASIC), ...(prem ? cat.tlds_premium || TLDS_PREMIUM : [])])];
  }
  const domainLabel = v => String(v || "").toLowerCase().trim().replace(/^https?:\/\//, "").replace(/^www\./, "").split("/")[0].split(".")[0].replace(/[\s_]+/g, "-").replace(/[^a-z0-9-]/g, "").replace(/^-+|-+$/g, "");
  const validLabel = l => /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/.test(l) && l.slice(2, 4) !== "--";
  const validHost = h => h.length <= 253 && /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/.test(h);
  function needsGatewayDocs(cat, stackKey, pack, add) {
    return cat.addons.some(a => a.gw && (!a.only || a.only === stackKey) && ((pack.incl || []).includes(a.key) || add[a.key]));
  }

  /* ---------- live domain lookup from the browser ----------
     Used when there is no PHP backend (demo/preview), and to fill in names the server could not
     answer. Asks the registry's RDAP server first (404 = free, 200 = registered); if the browser
     cannot reach it, asks free DNS-over-HTTPS (Google, then Cloudflare): NXDOMAIN = most likely free. */
  const RDAP_FALLBACK = {com: "https://rdap.verisign.com/com/v1/", net: "https://rdap.verisign.com/net/v1/", shop: "https://rdap.gmoregistry.net/rdap/",
    top: "https://rdap.zdnsgtld.com/top/", store: "https://rdap.centralnic.com/store/", online: "https://rdap.centralnic.com/online/",
    site: "https://rdap.centralnic.com/site/", website: "https://rdap.centralnic.com/website/"};
  let rdapMap = null;
  async function rdapBase(tld) {
    if (!rdapMap) {
      rdapMap = (() => { try { const c = JSON.parse(localStorage.getItem("techill_rdap") || "null"); return c && Date.now() - c.at < 7 * 864e5 ? c.map : null; } catch (e) { return null; } })();
      if (!rdapMap) {
        rdapMap = {};
        try {
          const j = await (await timed("https://data.iana.org/rdap/dns.json", {}, 5000)).json();
          for (const [tlds, urls] of j.services || []) { const u = urls.find(x => x.startsWith("https://")) || urls[0]; for (const t of tlds) rdapMap[t.toLowerCase()] = u.replace(/\/?$/, "/"); }
          try { localStorage.setItem("techill_rdap", JSON.stringify({at: Date.now(), map: rdapMap})); } catch (e) {}
        } catch (e) {}
      }
    }
    return rdapMap[tld] || RDAP_FALLBACK[tld] || null;
  }
  function timed(url, opts = {}, ms = 6000) {
    const c = new AbortController(), t = setTimeout(() => c.abort(), ms);
    return fetch(url, {...opts, signal: c.signal, credentials: "omit", cache: "no-store"}).finally(() => clearTimeout(t));
  }
  async function rdapStatus(d) {
    const base = await rdapBase(d.slice(d.lastIndexOf(".") + 1)); if (!base) return null;
    try {
      const r = await timed(base + "domain/" + encodeURIComponent(d), {headers: {accept: "application/rdap+json"}});
      // A web server's HTML error page is not a registry answer, so only a non-HTML 404 means free.
      return r.status === 404 && !(await r.text()).trimStart().startsWith("<") ? "available" : r.status === 200 ? "taken" : null;
    }
    catch (e) { return null; }
  }
  async function dnsStatus(d) {
    for (const u of [`https://dns.google/resolve?name=${encodeURIComponent(d)}&type=NS`, `https://cloudflare-dns.com/dns-query?name=${encodeURIComponent(d)}&type=NS&ct=application/dns-json`]) {
      try { const j = await (await timed(u, {}, 5000)).json(); if (j.Status === 3) return "available"; if (j.Status === 0) return "taken"; } catch (e) {}
    }
    return null;
  }
  async function liveDomainStatus(domains) {
    return Object.fromEntries(await Promise.all(domains.map(async d => {
      const r = await rdapStatus(d); if (r) return [d, {status: r, src: "rdap"}];
      const n = await dnsStatus(d); return [d, n ? {status: n, src: "dns"} : {status: "unknown", src: null}];
    })));
  }

  /* ---------- visitor id ---------- */
  const vid = (() => {
    try { let v = localStorage.getItem("techill_vid"); if (!/^[a-f0-9]{32}$/.test(v || "")) { v = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, "0")).join(""); localStorage.setItem("techill_vid", v); } return v; }
    catch (e) { return [...Array(32)].map(() => "0123456789abcdef"[Math.random() * 16 | 0]).join(""); }
  })();

  /* ---------- photos: scale down in the browser before upload (the server re-checks and re-encodes) ---------- */
  async function shrink(file, max, square) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) { const e = new Error("শুধু JPG, PNG বা WebP ছবি দিন।"); throw e; }
    if (file.size > 15 * 1024 * 1024) throw new Error("ছবি অনেক বড় (১৫MB-এর বেশি)।");
    let img;
    try { img = await createImageBitmap(file); } catch (e) { return file; }
    let sx = 0, sy = 0, sw = img.width, sh = img.height;
    if (square) { sw = sh = Math.min(img.width, img.height); sx = (img.width - sw) / 2; sy = (img.height - sh) / 2; }
    const k = Math.min(1, max / Math.max(sw, sh)), c = document.createElement("canvas");
    c.width = Math.round(sw * k); c.height = Math.round(sh * k);
    c.getContext("2d").drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height);
    const out = await new Promise(r => c.toBlob(r, "image/webp", .85)) || await new Promise(r => c.toBlob(r, "image/jpeg", .85));
    return out && out.size < file.size ? out : file;
  }

  /* ---------- real backend ---------- */
  async function http(action, {method = "GET", params = {}, form = null} = {}) {
    const qs = new URLSearchParams({action, ...params}).toString();
    const res = await fetch("api.php?" + qs, {
      method, credentials: "same-origin",
      headers: method === "POST" ? {"X-CSRF-Token": csrf} : {},
      body: method === "POST" ? form : undefined
    });
    let data;
    try { data = await res.json(); } catch (e) { throw new Error("সার্ভার থেকে উত্তর পাওয়া যায়নি।"); }
    if (data.csrf) csrf = data.csrf;
    if (!data.ok) { const err = new Error(data.error || "কিছু একটা সমস্যা হয়েছে।"); err.status = res.status; err.ref_invalid = !!data.ref_invalid; err.need_otp = !!data.need_otp; err.domain_error = !!data.domain_error; throw err; }
    return data;
  }
  const toForm = obj => { if (obj instanceof FormData) return obj; const f = new FormData(); for (const [k, v] of Object.entries(obj)) if (v != null) f.append(k, v); return f; };
  const post = (action, obj) => http(action, {method: "POST", form: toForm(obj)});

  /* ---------- demo backend (browser only) ---------- */
  const KEY = "techill_demo_db_v6", SKEY = "techill_demo_uid", CKEY = "techill_demo_catalog", PKEY = "techill_demo_payments", MKEY = "techill_demo_marketing";
  const EKEY = "techill_demo_email", SUPKEY = "techill_demo_support", RKEY = "techill_demo_referral";
  const EMAIL_DEF = {enabled: true, transport: "smtp", host: "mail.techill.top", port: 465, secure: "ssl", username: "noreply@techill.top", password: "demo", from_email: "noreply@techill.top", from_name: "Techill",
    reply_to: "", admin_emails: "", otp_required: true, notify: {new_order: true, status: true, message: true, assign: true, referral: true}};
  const demoEmail = () => { const e = ls.get(EKEY) || {}; return {...EMAIL_DEF, ...e, notify: {...EMAIL_DEF.notify, ...(e.notify || {})}}; };
  const emailReady = () => { const e = demoEmail(); return !!(e.enabled && e.from_email && (e.transport === "mail" || e.host)); };
  const otpRequired = () => emailReady() && !!demoEmail().otp_required;
  const SUP_DEF = {enabled: true, messenger: "techillbd", whatsapp: "8801700000000", wa_text: "আসসালামু আলাইকুম, Techill-এর প্যাকেজ নিয়ে জানতে চাই।", tawk_property: "demo", tawk_widget: "default", on_dashboard: false, greeting: "প্যাকেজ নিয়ে প্রশ্ন? সরাসরি কথা বলুন"};
  const demoSupport = () => ({...SUP_DEF, ...(ls.get(SUPKEY) || {})});
  const REF_DEF = {enabled: true, reward: 1000, discount: 1000, min_order: 5000, trigger: "delivered", payout_min: 1000, first_order_only: true};
  const demoRef = () => ({...REF_DEF, ...(ls.get(RKEY) || {})});
  const demoMarketing = () => ls.get(MKEY) || {pixel_enabled: false, pixel_id: "", capi_token: "", test_code: "", capi_last: null};
  const marketingOut = m => ({pixel_enabled: !!m.pixel_enabled, pixel_id: m.pixel_id, capi_set: !!m.capi_token, test_code: m.test_code, capi_last: m.capi_last});
  const demoPaySettings = () => ls.get(PKEY) || {manual_enabled: true, paystation: {enabled: true, sandbox: true, merchant_id: "DEMO-MERCHANT", password: "demo", pay_with_charge: 0}};
  const publicPay = () => { const s = demoPaySettings(), p = s.paystation; return {online: !!(p.enabled && p.merchant_id && p.password), manual: !!s.manual_enabled, pay_with_charge: +p.pay_with_charge, sandbox: !!p.sandbox}; };
  const payOut = s => ({manual_enabled: !!s.manual_enabled, paystation: {enabled: !!s.paystation.enabled, sandbox: !!s.paystation.sandbox, merchant_id: s.paystation.merchant_id,
    password_set: !!s.paystation.password, pay_with_charge: +s.paystation.pay_with_charge, ready: !!(s.paystation.enabled && s.paystation.merchant_id && s.paystation.password)},
    callback_url: new URL("api.php?action=paystation_callback", location.href).href, curl: true});
  let mem = {}, fileCatalog = null;
  const ls = {
    get(k) { try { const v = localStorage.getItem(k); return v == null ? mem[k] ?? null : JSON.parse(v); } catch (e) { return mem[k] ?? null; } },
    set(k, v) { mem[k] = v; try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    del(k) { delete mem[k]; try { localStorage.removeItem(k); } catch (e) {} }
  };
  const now = () => Math.floor(Date.now() / 1000);
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const dayKey = t => new Date((t + 6 * 3600) * 1000).toISOString().slice(0, 10);   // Bangladesh date
  async function loadFileCatalog() {
    if (!fileCatalog) fileCatalog = await (await fetch("assets/catalog.json")).json();
    return fileCatalog;
  }
  const demoCatalog = async () => ls.get(CKEY) || loadFileCatalog();

  function seed() {
    const t = now(), H = 3600, D = 86400;
    const users = [
      {id: 1, name: "Techill অ্যাডমিন", email: "admin@techill.top", phone: null, pass: "admin1234", role: "admin", active: true, created: t - 120 * D},
      {id: 2, name: "তানভীর আহমেদ", email: "dev@techill.top", phone: null, pass: "dev12345", role: "developer", active: true, created: t - 100 * D},
      {id: 3, name: "নুসরাত জাহান", email: "dev2@techill.top", phone: null, pass: "dev12345", role: "developer", active: true, created: t - 60 * D},
      {id: 4, name: "রাকিব হাসান", email: "demo@techill.top", phone: "01711000000", pass: "demo1234", role: "customer", active: true, created: t - 26 * D, ref_code: "RAKIB24"}
    ];
    const people = [["সুমাইয়া আক্তার", "01811222333"], ["মেহেদী হাসান", "01911444555"], ["ফারহানা ইসলাম", "01611777888"], ["আরিফ রহমান", "01511999000"], ["জান্নাতুল ফেরদৌস", "01712121212"], ["শাকিল খান", "01813131313"]];
    people.forEach(([name, phone], i) => users.push({id: 5 + i, name, email: `customer${i + 1}@example.com`, phone, pass: "x", role: "customer", active: true, created: t - (40 - i * 5) * D, referred_by: [0, 1, 2, 5].includes(i) ? 4 : null}));
    users.forEach(u => { u.email_verified = true; u.email_notify = true; });
    // [user, stack, pack, template, extras, pay, stage, progress, dev, createdAgo(h), hours, site, cancelled]
    const specs = [
      [4, "WooCommerce", "বিজনেস", "ফ্যাশন হাউস · W1", [["লোগো ডিজাইন", 1500]], "verified", 2, 45, 2, 18, 48, null, false],
      [5, "WooCommerce", "স্টার্টার", "বিউটি কর্নার · W3", [], "verified", 4, 100, 2, 24 * 9, 24, "https://glowbysumaiya.com", false],
      [6, "Laravel", "গ্রোথ", "টেক বাজার · L2", [["কুরিয়ার API × ১", 2000]], "verified", 3, 85, 3, 40, 48, null, false],
      [7, "WooCommerce", "প্রো", "হোম ডেকর · W5", [["ফেসবুক Pixel", 1000]], "verified", 2, 35, 3, 80, 72, null, false],
      [8, "WooCommerce", "বিজনেস", "ফ্রেশ বাজার · W4", [], "pending", 0, 5, null, 3, 48, null, false],
      [9, "Laravel", "লাইট", "মেগা স্টোর · L1", [["PayStation পেমেন্ট গেটওয়ে", 3000]], "verified", 2, 60, 2, 24 * 4, 72, null, false],
      [10, "WooCommerce", "স্টার্টার", "গ্যাজেট জোন · W2", [], "rejected", 0, 5, null, 24 * 6, 24, null, true],
      [5, "Laravel", "আল্টিমেট", "গ্লো বিউটি · L5", [["বেসিক SEO সেটআপ", 2000]], "verified", 4, 100, 3, 24 * 20, 72, "https://glowbeauty.com.bd", false],
      [6, "WooCommerce", "বিজনেস", "ফ্যাশন হাউস · W1", [], "pending", 1, 15, 2, 7, 48, null, false]
    ];
    const prices = {"স্টার্টার": 6999, "বিজনেস": 11999, "প্রো": 17999, "লাইট": 12999, "গ্রোথ": 19999, "আল্টিমেট": 29999};
    let seq = 100;
    const orders = [], events = [], messages = [], files = [], referrals = [];
    const REFS = {1: "earned", 2: "pending", 3: "pending", 6: "cancelled"};   // seeded orders placed with the demo customer's code
    specs.forEach(([uid, stack, pack, tpl, extras, pay, stage, progress, dev, ago, hours, site, cancelled], i) => {
      const id = 20 + i, created = t - ago * H, u = users.find(x => x.id === uid);
      const lines = [{label: `${pack} প্যাকেজ`, amt: prices[pack]}, ...extras.map(([label, amt]) => ({label, amt}))];
      if (REFS[i]) { lines.push({label: "রেফারেল ছাড় (RAKIB24)", amt: -1000, ref: true}); referrals.push({id: 900 + i, order_id: id, referrer_id: 4, referee_id: uid, code: "RAKIB24", reward: 1000, discount: 1000, status: REFS[i], created, earned: REFS[i] === "earned" ? created + 30 * H : null}); }
      const code = "TC-" + ["8F2K1Q", "3MZ7PA", "K9Q2XD", "7HB4RT", "Q2W8EN", "5TY6LM", "V1C3ZS", "J8D5GF", "R4N9UB"][i];
      orders.push({id, code, user_id: uid, stack, pack, template: tpl, lines, total: lines.reduce((s, l) => s + l.amt, 0), hours, products: 40,
        pay_method: ["bKash", "Nagad", "Rocket"][i % 3], pay_sender: u.phone, pay_trx: "9JK7XQ" + (2000 + i), pay_status: pay,
        info: {admin_name: u.name, whatsapp: u.phone, phone: u.phone, fb_page: "", address: "ঢাকা", business_intro: "অনলাইনে পণ্য বিক্রি করি।", additional_info: ""},
        stage, progress, site_url: site, developer_id: dev, cancelled, deadline: created + hours * H, created, referrer_id: REFS[i] ? 4 : null,
        domain: ["rakibfashion.shop", "glowbysumaiya.com", "mehedigadget.store", "farhanahome.com", null, "arifmegastore.com", null, "glowbeauty.com", "mehedifashion.online"][i]});
      events.push({id: ++seq, order_id: id, stage: 0, progress: 5, note: "অর্ডার ও তথ্য জমা হয়েছে।", by: uid, created});
      if (pay === "verified") events.push({id: ++seq, order_id: id, stage: Math.max(1, Math.min(stage, 1)), progress: 15, note: "পেমেন্ট যাচাই সম্পন্ন।", by: 1, created: created + 2 * H});
      if (dev) events.push({id: ++seq, order_id: id, stage: Math.min(stage, 1), progress: 15, note: `ডেভেলপার ${users.find(x => x.id === dev).name} এই প্রোজেক্টে কাজ করছেন।`, by: 1, created: created + 2.5 * H});
      if (stage >= 2) events.push({id: ++seq, order_id: id, stage, progress, note: stage === 4 ? "ওয়েবসাইট ডেলিভারি সম্পন্ন। অ্যাডমিন লগইন হোয়াটসঅ্যাপে পাঠানো হয়েছে।" : "থিম সেটআপ আর ব্র্যান্ডিং শেষ। এখন প্রোডাক্ট আপলোড চলছে।", by: dev, created: Math.min(t - H, created + hours * H * .6)});
      if (cancelled) events.push({id: ++seq, order_id: id, stage, progress, note: "অর্ডারটি বাতিল করা হয়েছে।", by: 1, created: created + 26 * H});
      messages.push({id: ++seq, order_id: id, user_id: null, from_admin: true, body: `আসসালামু আলাইকুম! অর্ডার ${code} পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।`, file_id: null, seen: true, created});
      files.push({id: ++seq, order_id: id, user_id: uid, kind: "csv", name: "products.csv", size: 4210 + i * 300, data: null, created});
    });
    messages.push(
      {id: ++seq, order_id: 20, user_id: 4, from_admin: false, body: "ব্যানারে ঈদ অফারের লেখা দিতে চাই, ছবি পাঠাবো?", file_id: null, seen: true, created: t - 5 * H},
      {id: ++seq, order_id: 20, user_id: 2, from_admin: true, body: "অবশ্যই, ফাইল ট্যাব থেকে বা এখানে 📎 দিয়ে পাঠিয়ে দিন।", file_id: null, seen: false, created: t - 4 * H},
      {id: ++seq, order_id: 22, user_id: 6, from_admin: false, body: "রিভিউ লিংকটা দেখেছি, হেডারের রং একটু গাঢ় করা যাবে?", file_id: null, seen: false, created: t - 50 * 60},
      {id: ++seq, order_id: 28, user_id: 6, from_admin: false, body: "পেমেন্ট নগদ থেকে করেছি, যাচাই করবেন প্লিজ।", file_id: null, seen: false, created: t - 20 * 60}
    );
    // 90 days of visitor history, generated with a fixed seed so the charts look the same on every reload
    let r = 7;
    const rnd = () => (r = (r * 16807) % 2147483647) / 2147483647;
    const days = {};
    for (let i = 89; i >= 0; i--) {
      const d = dayKey(t - i * D), dow = new Date(d).getDay();
      const u = Math.round(38 + (89 - i) * .55 + (dow === 5 ? -10 : dow === 4 ? 8 : 0) + rnd() * 22);
      days[d] = {v: Math.round(u * (1.35 + rnd() * .3)), u, checkout: Math.round(u * (.07 + rnd() * .05)), wa: Math.round(u * (.03 + rnd() * .03))};
    }
    const analytics = {
      days,
      pages: {"/": 62, "/#packages": 21, "/#features": 9, "/dashboard.html": 6, "/#how": 2},
      refs: {"facebook.com": 54, "": 24, "google.com": 12, "m.facebook.com": 6, "instagram.com": 3, "youtube.com": 1},
      utm: {"fb_ads": 61, "boost_eid": 24, "whatsapp_status": 10, "google": 5},
      devices: {mobile: 81, desktop: 15, tablet: 4},
      browsers: {Facebook: 46, Chrome: 31, Samsung: 8, Safari: 7, Instagram: 4, Edge: 2, Other: 2},
      os: {Android: 79, iOS: 9, Windows: 10, macOS: 2},
      countries: {BD: 91, AE: 3, SA: 2, MY: 1.5, IT: 1, GB: 1, "": .5},
      cities: {"Dhaka": 48, "Chattogram": 14, "Gazipur": 7, "Narayanganj": 5, "Sylhet": 5, "Khulna": 4, "Rajshahi": 4, "Cumilla": 3, "Mymensingh": 3, "Rangpur": 2, "Bogura": 2, "Barishal": 2},
      regions: {"Dhaka Division": 62, "Chittagong Division": 19, "Sylhet Division": 5, "Khulna Division": 4, "Rajshahi Division": 5, "Mymensingh Division": 3, "Rangpur Division": 2, "Barisal Division": 2},
      hours: [2, 1, .6, .4, .3, .4, .8, 1.6, 2.4, 3.2, 4, 4.4, 4.6, 4.5, 4.2, 4.4, 4.8, 5.2, 5.6, 6.4, 7.4, 8.2, 7, 4.4],
      returning: .24, single: .41,
      months: {}
    };
    // earnings before the seeded orders, so the 12-month chart has a history
    for (let i = 11; i >= 1; i--) { const m = new Date(Date.UTC(new Date().getUTCFullYear(), new Date().getUTCMonth() - i, 15)).toISOString().slice(0, 7); analytics.months[m] = {earned: Math.round((38000 + (11 - i) * 9000 + rnd() * 26000) / 100) * 100, orders: 3 + Math.round((11 - i) * .6 + rnd() * 3)}; }
    const emails = [
      {id: 801, to: "admin@techill.top", subject: "নতুন অর্ডার TC-Q2W8EN · ৳১১,৯৯৯", kind: "new_order", order_id: 24, status: "sent", error: null, created: t - 3 * H},
      {id: 802, to: "customer4@example.com", subject: "অর্ডার TC-Q2W8EN পেয়েছি, ধন্যবাদ!", kind: "new_order", order_id: 24, status: "sent", error: null, created: t - 3 * H},
      {id: 803, to: "customer2@example.com", subject: "অর্ডার TC-K9Q2XD: এখন \"রিভিউ\" ধাপে", kind: "status", order_id: 22, status: "sent", error: null, created: t - 2 * H},
      {id: 804, to: "demo@techill.top", subject: "TC-8F2K1Q: তানভীর আহমেদ-এর নতুন মেসেজ", kind: "message", order_id: 20, status: "sent", error: null, created: t - 4 * H}
    ];
    return {seq, users, orders, events, messages, files, analytics, referrals, payouts: [], emails, otps: []};
  }
  const load = () => ls.get(KEY) || (ls.set(KEY, seed()), ls.get(KEY));
  const save = d => ls.set(KEY, d);
  const nextId = d => ++d.seq;
  const pubUser = u => u ? {id: u.id, name: u.name, email: u.email, phone: u.phone, role: u.role, avatar: u.avatar || null, email_verified: !!u.email_verified, email_notify: u.email_notify !== false} : null;
  const meUser = d => pubUser(d.users.find(x => x.id === ls.get(SKEY) && x.active));
  const bad = (msg, status) => { const e = new Error(msg); e.status = status; throw e; };
  const needUser = d => meUser(d) || bad("আগে লগইন করুন।", 401);
  const isAdmin = u => u && u.role === "admin";
  const isStaff = u => u && (u.role === "admin" || u.role === "developer");
  const needAdmin = d => { const u = needUser(d); if (!isAdmin(u)) bad("শুধু অ্যাডমিন এটা করতে পারবেন।", 403); return u; };
  const canSee = (u, o) => isAdmin(u) || (u.role === "developer" && o.developer_id === u.id) || (u.role === "customer" && o.user_id === u.id);
  const orderFor = (d, u, id) => { const o = d.orders.find(x => x.id === +id); if (!o || !canSee(u, o)) bad("অর্ডার পাওয়া যায়নি।", 404); return o; };
  const unreadFor = (d, u, o) => d.messages.filter(m => m.order_id === o.id && !m.seen && m.from_admin === !isStaff(u)).length;
  function orderOut(d, u, o) {
    const r = {...o}; delete r.user_id; delete r.developer_id; r.unread = unreadFor(d, u, o);
    if (isStaff(u)) {
      const c = d.users.find(x => x.id === o.user_id), dv = d.users.find(x => x.id === o.developer_id);
      r.customer = {name: c.name, email: c.email, phone: c.phone, avatar: c.avatar || null};
      r.developer = dv ? {id: dv.id, name: dv.name, avatar: dv.avatar || null} : null;
    }
    return r;
  }
  const msgOut = (d, m) => {
    const f = m.file_id ? d.files.find(x => x.id === m.file_id) : null, by = d.users.find(x => x.id === m.user_id);
    return {id: m.id, from_admin: m.from_admin, system: m.user_id == null, body: m.body, by: by ? by.name : "Techill", avatar: by?.avatar || null, file: f ? {id: f.id, name: f.name, size: f.size} : null, created: m.created};
  };
  const markSeen = (d, u, oid) => d.messages.forEach(m => { if (m.order_id === oid && m.from_admin === !isStaff(u)) m.seen = true; });
  const addEvent = (d, o, note, by) => d.events.push({id: nextId(d), order_id: o.id, stage: o.stage, progress: o.progress, note, by, created: now()});
  const phoneOK = v => /^(?:\+?88)?01[3-9]\d{8}$/.test(String(v).replace(/[\s-]/g, "").replace(/[০-৯]/g, c => "০১২৩৪৫৬৭৮৯".indexOf(c)));
  const blobToData = b => new Promise((res, rej) => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.onerror = rej; fr.readAsDataURL(b); });
  const fileToData = f => new Promise(r => { if (!f || f.size > 1.5e6) return r(null); const fr = new FileReader(); fr.onload = () => r(fr.result); fr.onerror = () => r(null); fr.readAsDataURL(f); });
  async function addFile(d, oid, uid, kind, f) {
    if (!f || !f.name) return null;
    if (f.size > 10 * 1024 * 1024) bad("ফাইল 10MB-এর বেশি বড়।");
    const id = nextId(d);
    d.files.push({id, order_id: oid, user_id: uid, kind, name: f.name, size: f.size, data: await fileToData(f), created: now()});
    return id;
  }
  function createOrLogin(d, name, email, phone, pass, verified) {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("সঠিক ইমেইল দিন।");
    if (String(pass).length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।");
    const ex = d.users.find(x => x.email === email);
    if (ex) { if (ex.pass !== pass) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, পাসওয়ার্ড মেলেনি।", 409); return ex.id; }
    if (!name) bad("নাম দিন।");
    const id = nextId(d); d.users.push({id, name, email, phone: phone || null, pass, role: "customer", active: true, created: now(), email_verified: !!verified, email_notify: true}); return id;
  }
  function teamRows(d) {
    const t = now();
    return d.users.filter(u => isStaff(u)).map(u => {
      const mine = d.orders.filter(o => o.developer_id === u.id && !o.cancelled);
      return {id: u.id, name: u.name, email: u.email, role: u.role, active: u.active, avatar: u.avatar || null, created: u.created,
        running: mine.filter(o => o.stage < 4).length, overdue: mine.filter(o => o.stage < 4 && o.deadline < t).length, done: mine.filter(o => o.stage === 4).length};
    }).sort((a, b) => b.active - a.active || a.role.localeCompare(b.role));
  }
  const bump = (d, field, k) => { const a = d.analytics; a[field][k] = (a[field][k] || 0) + 1; };

  function updatePerson(d, p, name, email, phone) {
    name = String(name || "").trim(); email = String(email || "").trim().toLowerCase();
    if (!name || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("নাম ও সঠিক ইমেইল দিন।");
    if (phone && !phoneOK(phone)) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
    if (d.users.some(x => x.email === email && x.id !== p.id)) bad("এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।", 409);
    Object.assign(p, {name, email, phone: phone || null});
  }
  function counts(d, c) {
    const r = role => d.users.filter(u => u.role === role);
    return {customers: r("customer").length, customers_active: r("customer").filter(u => u.active).length, developers: r("developer").length,
      developers_active: r("developer").filter(u => u.active).length, admins: r("admin").length,
      packages: Object.values(c.stacks).reduce((s, S) => s + S.packs.length, 0), templates: Object.values(c.stacks).reduce((s, S) => s + S.templates.length, 0),
      addons: c.addons.length, platforms: Object.keys(c.stacks).length};
  }
  function demoLive(t, n) {
    const paths = ["/", "/", "/", "/#packages", "/#packages", "/#features", "/dashboard.html"], devs = ["mobile", "mobile", "mobile", "mobile", "desktop", "tablet"];
    const br = ["Facebook", "Chrome", "Facebook", "Samsung", "Chrome", "Safari"], cities = [["Dhaka", "BD"], ["Chattogram", "BD"], ["Dhaka", "BD"], ["Gazipur", "BD"], ["Sylhet", "BD"], [null, "AE"], ["Khulna", "BD"]];
    return Array.from({length: n}, (_, i) => ({path: paths[(i * 3 + (t / 60 | 0)) % paths.length], device: devs[(i * 5) % devs.length], browser: br[i % br.length],
      city: cities[i % cities.length][0], country: cities[i % cities.length][1], since: t - (i * 97 + 40) % 900, seen: t - (i * 13) % 50}));
  }

  /* demo: emails, one-time codes, referrals */
  const taka = n => "৳" + BN(Math.round(n).toLocaleString("en-IN"));
  function demoMail(d, to, subject, kind, order_id) {
    if (!emailReady() || !to) return;
    d.emails = d.emails || [];
    d.emails.push({id: nextId(d), to, subject, kind, order_id: order_id || null, status: "sent", error: null, created: now()});
    if (d.emails.length > 200) d.emails.splice(0, d.emails.length - 200);
  }
  const notifyOn = k => emailReady() && !!demoEmail().notify[k];
  const adminMails = d => { const l = String(demoEmail().admin_emails || "").split(/[,;\s]+/).filter(x => /@/.test(x)); return l.length ? l : d.users.filter(u => u.role === "admin" && u.active).map(u => u.email); };
  const userMail = (d, id, subject, kind, oid, always) => { const u = d.users.find(x => x.id === id); if (u && u.active && (always || u.email_notify !== false)) demoMail(d, u.email, subject, kind, oid); };
  function demoNotifyUpdate(d, o, notes, stageChanged) {
    if (!notifyOn("status") || (!notes.length && !stageChanged)) return;
    userMail(d, o.user_id, o.cancelled ? `অর্ডার ${o.code} বাতিল করা হয়েছে` : o.stage === 4 ? `অর্ডার ${o.code}: ডেলিভারি সম্পন্ন` : stageChanged ? `অর্ডার ${o.code}: এখন "${STAGES[o.stage]}" ধাপে` : `অর্ডার ${o.code}-এ নতুন আপডেট`, "status", o.id);
  }
  function demoNotifyMessage(d, o, sender) {
    if (!notifyOn("message")) return;
    const subject = `${o.code}: ${sender.name}-এর নতুন মেসেজ`;
    if (isStaff(sender)) return userMail(d, o.user_id, subject, "message", o.id);
    if (o.developer_id) userMail(d, o.developer_id, subject, "message", o.id); else adminMails(d).forEach(e => demoMail(d, e, subject, "message", o.id));
  }
  function demoOtpIssue(d, email, purpose) {
    if (!emailReady()) bad("ইমেইল সার্ভিস এখন বন্ধ আছে।", 409);
    d.otps = (d.otps || []).filter(x => x.exp > now());
    const last = d.otps.filter(x => x.email === email && x.purpose === purpose).pop();
    if (last && now() - last.at < 60) bad("একটা কোড এইমাত্র পাঠানো হয়েছে, ১ মিনিট পর আবার চাইতে পারবেন।", 429);
    const code = String(Math.floor(Math.random() * 1e6)).padStart(6, "0");
    d.otps.push({email, purpose, code, at: now(), exp: now() + 600, attempts: 0, used: false});
    demoMail(d, email, "Techill যাচাই কোড", "otp");
    return code;
  }
  function demoOtpCheck(d, email, purpose, code) {
    code = String(code || "").replace(/[০-৯]/g, c => "০১২৩৪৫৬৭৮৯".indexOf(c)).replace(/\D/g, "");
    const row = (d.otps || []).filter(x => x.email === email && x.purpose === purpose && !x.used).pop();
    if (!row || row.exp < now()) bad("কোডের মেয়াদ শেষ বা কোড পাঠানো হয়নি। নতুন কোড নিন।", 422);
    if (row.attempts >= 5) bad("অনেকবার ভুল কোড দেওয়া হয়েছে। নতুন কোড নিন।", 429);
    if (row.code !== code) { row.attempts++; save(d); bad("কোড মেলেনি, আবার দেখে লিখুন।", 422); }
    row.used = true;
  }
  function refSync(d, o) {
    const r = (d.referrals || []).find(x => x.order_id === o.id); if (!r || r.status === "rejected") return;
    const s = demoRef(), paid = o.pay_status === "verified";
    const want = o.cancelled || o.pay_status === "rejected" ? "cancelled" : (s.trigger === "paid" ? paid : paid && o.stage === 4) ? "earned" : "pending";
    if (want === r.status) return;
    r.status = want;
    if (want === "earned" && !r.earned){ r.earned = now(); if (notifyOn("referral")) userMail(d, r.referrer_id, `অভিনন্দন! রেফারেলে ${taka(r.reward)} আয় করেছেন`, "referral", o.id, true); }
  }
  function refSummary(d, uid) {
    const rs = (d.referrals || []).filter(r => r.referrer_id === uid), ps = (d.payouts || []).filter(p => p.user_id === uid), sum = (a, f) => a.reduce((x, y) => x + f(y), 0);
    const o = {n: rs.length, earned_n: rs.filter(r => r.status === "earned").length, pending_n: rs.filter(r => r.status === "pending").length,
      earned: sum(rs.filter(r => r.status === "earned"), r => r.reward), pending: sum(rs.filter(r => r.status === "pending"), r => r.reward),
      paid: sum(ps.filter(p => p.status === "paid"), p => p.amount), requested: sum(ps.filter(p => p.status === "requested"), p => p.amount)};
    o.balance = o.earned - o.paid - o.requested; return o;
  }
  function refCodeFor(d, u) {
    if (u.ref_code) return u.ref_code;
    const abc = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    do { u.ref_code = Array.from({length: 7}, () => abc[Math.random() * abc.length | 0]).join(""); } while (d.users.some(x => x !== u && x.ref_code === u.ref_code));
    return u.ref_code;
  }
  const maskName = n => { const p = String(n || "").trim().split(/\s+/); return p[0] + (p[1] ? " " + p[1].slice(0, 1) + "." : ""); };
  const digits = v => { const x = String(v || "").replace(/[০-৯]/g, c => "০১২৩৪৫৬৭৮৯".indexOf(c)).replace(/\D/g, ""); return x.startsWith("880") ? x.slice(2) : x; };
  function refMeOut(d, me) {
    const s = demoRef(), code = refCodeFor(d, me), sum = refSummary(d, me.id), open = (d.payouts || []).some(p => p.user_id === me.id && p.status === "requested");
    const why = !s.enabled && !sum.n ? "রেফারেল অফার এখন বন্ধ আছে।" : open ? "আগের অনুরোধটা এখনো প্রক্রিয়াধীন।"
      : sum.balance < Math.max(1, s.payout_min) ? `কমপক্ষে ${taka(Math.max(1, s.payout_min))} জমা হলে তুলতে পারবেন।` : emailReady() && !me.email_verified ? "টাকা তুলতে আগে প্রোফাইল থেকে ইমেইল যাচাই করুন।" : "";
    return {settings: {...s}, code, link: new URL("index.html?ref=" + code, location.href).href, summary: sum, can_withdraw: !why, withdraw_note: why,
      referrals: (d.referrals || []).filter(r => r.referrer_id === me.id).slice().reverse().map(r => { const o = d.orders.find(x => x.id === r.order_id); return {name: maskName(d.users.find(x => x.id === r.referee_id)?.name), status: r.status, reward: r.reward, created: r.created, earned: r.earned, stage: o ? o.stage : 0}; }),
      payouts: (d.payouts || []).filter(p => p.user_id === me.id).slice().reverse()};
  }
  function refAdminOut(d) {
    const rs = d.referrals || [], ps = d.payouts || [], sum = (a, f) => a.reduce((x, y) => x + f(y), 0), name = id => d.users.find(x => x.id === id);
    const top = {}; rs.forEach(r => { const u = name(r.referrer_id); top[u.id] = top[u.id] || {id: u.id, name: u.name, email: u.email, code: u.ref_code, n: 0, earned: 0}; top[u.id].n++; if (r.status === "earned") top[u.id].earned += r.reward; });
    return {settings: demoRef(),
      totals: {n: rs.length, earned_n: rs.filter(r => r.status === "earned").length, pending_n: rs.filter(r => r.status === "pending").length, earned: sum(rs.filter(r => r.status === "earned"), r => r.reward),
        discounts: sum(rs.filter(r => ["pending", "earned"].includes(r.status)), r => r.discount), paid: sum(ps.filter(p => p.status === "paid"), p => p.amount), requested: sum(ps.filter(p => p.status === "requested"), p => p.amount),
        requests: ps.filter(p => p.status === "requested").length, revenue: sum(rs.filter(r => r.status === "earned"), r => d.orders.find(o => o.id === r.order_id)?.total || 0)},
      top: Object.values(top).sort((a, b) => b.earned - a.earned || b.n - a.n).slice(0, 10),
      referrals: rs.slice().reverse().map(r => { const o = d.orders.find(x => x.id === r.order_id); return {id: r.id, order_id: r.order_id, order: o?.code, total: o?.total || 0, code: r.code, status: r.status, reward: r.reward, discount: r.discount, referrer: name(r.referrer_id)?.name, referee: name(r.referee_id)?.name, created: r.created, note: r.note || null}; }),
      payouts: ps.slice().sort((a, b) => (b.status === "requested") - (a.status === "requested") || b.id - a.id).map(p => ({...p, user: name(p.user_id)?.name, email: name(p.user_id)?.email, balance: refSummary(d, p.user_id).balance + (p.status === "requested" ? p.amount : 0)}))};
  }
  function emailOut(d) {
    const e = demoEmail(), log = (d.emails || []).slice(-40).reverse(), m = (d.emails || []).filter(x => x.created > now() - 30 * 86400);
    const {password, ...rest} = e;
    return {...rest, password_set: !!password, ready: emailReady(), log, stats: {sent: m.filter(x => x.status === "sent").length, failed: m.filter(x => x.status === "failed").length, n: m.length},
      host_hint: "techill.top", admin_fallback: d.users.filter(u => u.role === "admin" && u.active).map(u => u.email), openssl: true, mail_fn: true};
  }
  async function publicSupport() {
    const s = demoSupport(); if (!s.enabled) return null;
    const wa = s.whatsapp || String((await demoCatalog()).whatsapp || "").replace(/\D/g, "");
    const o = {greeting: s.greeting, on_dashboard: !!s.on_dashboard, messenger: s.messenger ? "https://m.me/" + encodeURIComponent(s.messenger) : null, whatsapp: wa || null, wa_text: s.wa_text,
      tawk: s.tawk_property ? {property: s.tawk_property, widget: s.tawk_widget || "default"} : null};
    return o.messenger || o.whatsapp || o.tawk ? o : null;
  }
  const publicReferral = () => { const s = demoRef(); return s.enabled ? {reward: s.reward, discount: s.discount, min_order: s.min_order} : null; };
  function referrerBy(d, code) { return /^[A-Z0-9]{5,12}$/.test(code || "") ? d.users.find(u => u.ref_code === code && u.active && u.role === "customer") || null : null; }

  const mock = {
    async me() { const d = load(); return {user: meUser(d)}; },
    async register({name, email, phone, password, otp}) {
      const d = load(); email = String(email).trim().toLowerCase(); name = String(name || "").trim();
      if (d.users.some(x => x.email === email)) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, লগইন করুন।", 409);
      let verified = false;
      if (otpRequired()) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("সঠিক ইমেইল দিন।");
        if (!name) bad("নাম দিন।");
        if (phone && !phoneOK(phone)) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
        if (String(password || "").length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।");
        if (!otp) { const code = demoOtpIssue(d, email, "register"); save(d); return {need_otp: true, email, demo_code: code}; }
        demoOtpCheck(d, email, "register", otp); verified = true;
      }
      const id = createOrLogin(d, name, email, phone, password, verified); save(d); ls.set(SKEY, id); return {user: meUser(d)};
    },
    async otp_send({purpose, email}) {
      const d = load(), u = meUser(d);
      if (!["register", "verify", "reset", "change"].includes(purpose)) bad("Unknown purpose");
      if (!emailReady()) bad("ইমেইল সার্ভিস এখন বন্ধ আছে।", 409);
      if ((purpose === "verify" || purpose === "change") && !u) bad("আগে লগইন করুন।", 401);
      if (purpose === "verify"){ if (u.email_verified) bad("আপনার ইমেইল আগেই যাচাই হয়েছে।", 409); email = u.email; }
      else {
        email = String(email || "").trim().toLowerCase();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("সঠিক ইমেইল দিন।");
        const acct = d.users.find(x => x.email === email);
        if (purpose === "register" && acct) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে। সেই পাসওয়ার্ড দিন বা লগইন করুন।", 409);
        if (purpose === "change" && acct) bad("এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।", 409);
        if (purpose === "reset" && (!acct || !acct.active)) return {sent: true, email};
      }
      const code = demoOtpIssue(d, email, purpose); save(d); return {sent: true, email, demo_code: code};
    },
    async password_reset({email, otp, password}) {
      const d = load(); email = String(email || "").trim().toLowerCase();
      if (String(password || "").length < 8) bad("নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।");
      const u = d.users.find(x => x.email === email && x.active); if (!u) bad("কোড মেলেনি, আবার দেখে লিখুন।", 422);
      demoOtpCheck(d, email, "reset", otp); u.pass = password; u.email_verified = true; save(d); ls.set(SKEY, u.id); return {user: meUser(d)};
    },
    async email_verify({otp}) { const d = load(), me = needUser(d), u = d.users.find(x => x.id === me.id); demoOtpCheck(d, u.email, "verify", otp); u.email_verified = true; save(d); return {user: meUser(d)}; },
    async profile_update({name, phone, email_notify}) {
      const d = load(), me = needUser(d), u = d.users.find(x => x.id === me.id);
      name = String(name || "").trim(); if (!name) bad("নাম দিন।"); if (phone && !phoneOK(phone)) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
      Object.assign(u, {name, phone: phone || null, email_notify: !!email_notify}); save(d); return {user: meUser(d)};
    },
    async email_change({email, otp}) {
      const d = load(), me = needUser(d), u = d.users.find(x => x.id === me.id); email = String(email || "").trim().toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("সঠিক ইমেইল দিন।"); if (email === u.email) bad("এটাই আপনার বর্তমান ইমেইল।");
      if (d.users.some(x => x.email === email)) bad("এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।", 409);
      if (emailReady()) demoOtpCheck(d, email, "change", otp); else if (!isAdmin(u)) bad("ইমেইল বদলাতে যাচাই কোড লাগে, কিন্তু ইমেইল সার্ভিস এখন বন্ধ। অ্যাডমিনকে বলুন।", 409);
      u.email = email; u.email_verified = emailReady(); save(d); return {user: meUser(d)};
    },
    async password_change({current, password}) {
      const d = load(), me = needUser(d), u = d.users.find(x => x.id === me.id);
      if (u.pass !== current) bad("বর্তমান পাসওয়ার্ড মেলেনি।", 422); if (String(password || "").length < 8) bad("নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।");
      u.pass = password; save(d); return {};
    },
    async email_settings() { const d = load(); needAdmin(d); return {settings: emailOut(d)}; },
    async email_save(f) {
      const d = load(); needAdmin(d);
      const e = demoEmail(), t = k => String(f[k] ?? "").trim();
      Object.assign(e, {enabled: !!f.enabled, transport: f.transport === "mail" ? "mail" : "smtp", host: t("host").toLowerCase(), port: +f.port || 465, secure: ["ssl", "tls", "none"].includes(f.secure) ? f.secure : "ssl",
        username: t("username"), from_email: t("from_email").toLowerCase(), from_name: t("from_name") || "Techill", reply_to: t("reply_to").toLowerCase(), otp_required: !!f.otp_required});
      if (e.host && !/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/.test(e.host)) bad("SMTP হোস্ট সঠিক নয় (যেমন mail.yourdomain.com)।");
      if (e.port < 1 || e.port > 65535) bad("পোর্ট সঠিক নয় (সাধারণত 465 বা 587)।");
      if (f.password) e.password = f.password; if (f.clear_password) e.password = "";
      if (e.from_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e.from_email)) bad("প্রেরকের ইমেইল (From) সঠিক নয়।");
      const list = t("admin_emails").split(/[,;\s]+/).filter(Boolean); for (const x of list) if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x)) bad("এই ইমেইলটা সঠিক নয়: " + x);
      e.admin_emails = [...new Set(list.map(x => x.toLowerCase()))].join(", ");
      for (const k of Object.keys(EMAIL_DEF.notify)) e.notify[k] = !!f["n_" + k];
      if (e.enabled){ if (!e.from_email) bad("ইমেইল চালু করতে প্রেরকের ইমেইল (From) দিন।"); if (e.transport === "smtp" && !e.host) bad("SMTP হোস্ট দিন, অথবা \"PHP mail()\" বাছুন।"); if (e.transport === "smtp" && e.username && !e.password) bad("SMTP পাসওয়ার্ড দিন।"); }
      ls.set(EKEY, e); return {settings: emailOut(d)};
    },
    async email_test({to}) {
      const d = load(), u = needAdmin(d); to = String(to || "").trim() || u.email; const e = demoEmail();
      if (!e.from_email || (e.transport === "smtp" && !e.host)) bad("আগে SMTP তথ্য আর প্রেরকের ইমেইল দিয়ে সেভ করুন।");
      d.emails = d.emails || []; d.emails.push({id: nextId(d), to, subject: "Techill টেস্ট ইমেইল", kind: "test", order_id: null, status: "sent", error: null, created: now()}); save(d);
      return {sent: true, message: `(ডেমো) ${to}-এ টেস্ট ইমেইল পাঠানো হয়েছে। আসল সার্ভারে Inbox আর Spam দুটোই দেখুন।`, settings: emailOut(d)};
    },
    async support_settings() { const d = load(); needAdmin(d); const cat = await demoCatalog(); return {settings: {...demoSupport(), catalog_whatsapp: String(cat.whatsapp || "").replace(/\D/g, ""), clicks: {support_open: 214, support_whatsapp: 96, support_messenger: 41, support_tawk: 33}, public: await publicSupport()}}; },
    async support_save(f) {
      const d = load(); needAdmin(d); const s = demoSupport();
      let m = String(f.messenger || "").trim(); const mm = m.match(/(?:m\.me|messenger\.com\/t|facebook\.com|fb\.com)\/([A-Za-z0-9.\-_]{3,80})/i); if (mm) m = mm[1]; m = m.replace(/^[\/@ ]+|[\/@ ]+$/g, "");
      if (m && !/^[A-Za-z0-9.\-_]{3,80}$/.test(m)) bad("Messenger-এর জন্য ফেসবুক পেজের username বা লিংক দিন (যেমন techillbd বা m.me/techillbd)।");
      let wa = digits(f.whatsapp); if (/^01[3-9]\d{8}$/.test(wa)) wa = "88" + wa; if (wa && !/^\d{8,15}$/.test(wa)) bad("হোয়াটসঅ্যাপ নম্বর সঠিক নয় (যেমন 01712345678 বা 8801712345678)।");
      const t = String(f.tawk || "").trim(); let tp = "", tw = "";
      if (t === "demo") { tp = "demo"; tw = "default"; }
      else if (t) { const x = t.match(/(?:embed\.tawk\.to|tawk\.to\/chat)\/([a-f0-9]{24})(?:\/([A-Za-z0-9]{3,30}))?/i) || t.match(/^([a-f0-9]{24})(?:\/([A-Za-z0-9]{3,30}))?$/i); if (!x) bad("tawk.to-এর Direct Chat Link বা পুরো widget কোডটা পেস্ট করুন (tawk.to → Administration → Chat Widget)।"); tp = x[1].toLowerCase(); tw = x[2] || "default"; }
      Object.assign(s, {enabled: !!f.enabled, on_dashboard: !!f.on_dashboard, greeting: String(f.greeting || "").trim().slice(0, 80), messenger: m, whatsapp: wa, wa_text: String(f.wa_text || "").trim().slice(0, 300), tawk_property: tp, tawk_widget: tw});
      ls.set(SUPKEY, s); return mock.support_settings();
    },
    async domain_check({stack, pack, name}) {
      const cat = await demoCatalog(), P = cat.stacks[stack]?.packs[+pack]; if (!P) bad("প্যাকেজ পাওয়া যায়নি।");
      const label = domainLabel(name); if (!validLabel(label)) bad("ইংরেজি অক্ষর, সংখ্যা বা হাইফেন দিয়ে নাম লিখুন (যেমন myshop)।");
      let tlds = allowedTlds(cat, P); const typed = (String(name).toLowerCase().match(/\.([a-z]{2,24})\s*$/) || [])[1];
      if (typed && tlds.includes(typed)) tlds = [typed, ...tlds.filter(t => t !== typed)];
      const st = await liveDomainStatus(tlds.map(t => `${label}.${t}`)), booked = new Set(load().orders.filter(o => !o.cancelled && o.domain).map(o => o.domain));
      const prem = cat.tlds_premium || TLDS_PREMIUM;
      return {name: label, checked_at: new Date().toISOString(), tlds: allowedTlds(cat, P), premium_missing: prem.filter(t => !allowedTlds(cat, P).includes(t)),
        results: tlds.map(t => { const d = `${label}.${t}`; return {domain: d, tld: t, premium: prem.includes(t), ...(booked.has(d) ? {status: "taken", src: "order"} : st[d])}; })};
    },
    async ref_check({code}) {
      const d = load(), s = demoRef(), u = meUser(d); code = String(code || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
      if (!s.enabled) return {valid: false, message: "রেফারেল অফার এখন বন্ধ আছে।"};
      const r = referrerBy(d, code); if (!r) return {valid: false, message: "রেফারেল কোডটা সঠিক নয়।"};
      if (u && u.id === r.id) return {valid: false, message: "নিজের রেফারেল কোড নিজে ব্যবহার করা যায় না।"};
      if (u && s.first_order_only && d.orders.some(o => o.user_id === u.id && !o.cancelled)) return {valid: false, message: "রেফারেল ছাড় শুধু প্রথম অর্ডারে পাওয়া যায়।"};
      return {valid: true, code, name: r.name.split(/\s+/)[0], discount: s.discount, min_order: s.min_order};
    },
    async referral() { const d = load(), me = needUser(d); if (me.role !== "customer") bad("রেফারেল শুধু কাস্টমারদের জন্য।", 403); const u = d.users.find(x => x.id === me.id), r = refMeOut(d, u); save(d); return r; },
    async payout_request({method, account, amount}) {
      const d = load(), me = needUser(d), u = d.users.find(x => x.id === me.id);
      if (u.role !== "customer") bad("রেফারেল শুধু কাস্টমারদের জন্য।", 403);
      if (!["bKash", "Nagad", "Rocket"].includes(method)) bad("কোন মাধ্যমে টাকা নেবেন বাছুন।"); account = digits(account); if (!phoneOK(account)) bad("সঠিক ১১ ডিজিটের নম্বর দিন।");
      const cur = refMeOut(d, u); if (!cur.can_withdraw) bad(cur.withdraw_note || "এখন টাকা তোলা যাবে না।", 409);
      amount = +amount || cur.summary.balance; if (amount < Math.max(1, cur.settings.payout_min) || amount > cur.summary.balance) bad(`টাকার পরিমাণ ${taka(Math.max(1, cur.settings.payout_min))} থেকে ${taka(cur.summary.balance)}-এর মধ্যে দিন।`);
      d.payouts = d.payouts || []; d.payouts.push({id: nextId(d), user_id: u.id, amount, method, account, status: "requested", trx: null, note: null, created: now(), processed: null});
      if (notifyOn("referral")) adminMails(d).forEach(e => demoMail(d, e, "রেফারেল পেমেন্টের অনুরোধ: " + taka(amount), "payout"));
      save(d); return refMeOut(d, u);
    },
    async referral_admin() { const d = load(); needAdmin(d); return refAdminOut(d); },
    async referral_save(f) {
      const d = load(); needAdmin(d); const n = (k, max) => { const v = f[k]; if (v === "" || v == null || isNaN(+v) || +v < 0 || +v > max) bad("টাকার পরিমাণ সঠিক নয়।"); return Math.trunc(+v); };
      const s = {enabled: !!f.enabled, reward: n("reward", 1e6), discount: n("discount", 1e6), min_order: n("min_order", 1e7), trigger: f.trigger === "paid" ? "paid" : "delivered", payout_min: n("payout_min", 1e6), first_order_only: !!f.first_order_only};
      if (s.enabled && !s.reward && !s.discount) bad("আয় বা ছাড়, অন্তত একটা শূন্যের বেশি দিন।");
      ls.set(RKEY, s); return refAdminOut(d);
    },
    async referral_op({id, op, note}) {
      const d = load(); needAdmin(d); const r = (d.referrals || []).find(x => x.id === +id) || bad("রেফারেল পাওয়া যায়নি।", 404);
      if (op === "reject") { r.status = "rejected"; r.note = note || null; } else if (op === "restore") { r.status = "pending"; r.note = null; refSync(d, d.orders.find(o => o.id === r.order_id)); } else bad("Unknown op");
      save(d); return refAdminOut(d);
    },
    async payout_op({id, op, trx, note}) {
      const d = load(), a = needAdmin(d), p = (d.payouts || []).find(x => x.id === +id && x.status === "requested") || bad("এই অনুরোধটা আর অপেক্ষায় নেই।", 404);
      if (!["paid", "rejected"].includes(op)) bad("Unknown op");
      trx = String(trx || "").trim(); note = String(note || "").trim();
      if (op === "paid" && !/^[A-Za-z0-9]{6,20}$/.test(trx)) bad("যে TrxID দিয়ে টাকা পাঠিয়েছেন সেটা দিন।");
      if (op === "rejected" && !note) bad("কেন বাতিল করছেন, কাস্টমারকে জানাতে একটা কারণ লিখুন।");
      Object.assign(p, {status: op, trx: op === "paid" ? trx : null, note: note || null, processed: now()});
      if (notifyOn("referral")) userMail(d, p.user_id, op === "paid" ? `রেফারেলের ${taka(p.amount)} পাঠানো হয়েছে` : "রেফারেলের টাকা তোলার অনুরোধ বাতিল হয়েছে", "payout", null, true);
      save(d); return refAdminOut(d);
    },
    async login({email, password}) {
      const d = load(); const u = d.users.find(x => x.email === String(email).trim().toLowerCase() && x.active);
      if (!u || u.pass !== password) bad("ইমেইল বা পাসওয়ার্ড ভুল।", 401);
      ls.set(SKEY, u.id); return {user: meUser(d)};
    },
    async logout() { ls.del(SKEY); return {}; },
    async catalog() { const m = demoMarketing(); return {catalog: await demoCatalog(), payments: publicPay(), pixel: m.pixel_enabled && m.pixel_id ? {id: m.pixel_id} : null,
      support: await publicSupport(), referral: publicReferral(), auth: {email: emailReady(), otp: otpRequired()}}; },
    async avatar_upload({file, user_id}) {
      const d = load(), u = needUser(d), id = +user_id || u.id;
      if (id !== u.id && !isAdmin(u)) bad("অন্যের ছবি বদলানো যায় না।", 403);
      const t = d.users.find(x => x.id === id) || bad("অ্যাকাউন্ট পাওয়া যায়নি।", 404);
      t.avatar = await blobToData(file); save(d); return {avatar: t.avatar, user_id: id};
    },
    async avatar_remove({user_id}) {
      const d = load(), u = needUser(d), id = +user_id || u.id;
      if (id !== u.id && !isAdmin(u)) bad("অন্যের ছবি বদলানো যায় না।", 403);
      const t = d.users.find(x => x.id === id); if (t) t.avatar = null; save(d); return {avatar: null, user_id: id};
    },
    async media_upload({file}) { const d = load(); needAdmin(d); return {url: await blobToData(file)}; },
    async marketing_settings() { const d = load(); needAdmin(d); return {settings: marketingOut(demoMarketing())}; },
    async marketing_save(f) {
      const d = load(); needAdmin(d);
      const m = demoMarketing(), id = String(f.pixel_id || "").replace(/\D/g, "");
      if (f.pixel_enabled && !/^\d{10,20}$/.test(id)) bad("সঠিক Pixel ID দিন (শুধু সংখ্যা, সাধারণত ১৫-১৬ ডিজিট)।");
      Object.assign(m, {pixel_enabled: !!f.pixel_enabled, pixel_id: id, test_code: String(f.test_code || "").replace(/[^A-Za-z0-9]/g, "")});
      if (f.capi_token) m.capi_token = f.capi_token; if (f.capi_clear) m.capi_token = "";
      ls.set(MKEY, m); return {settings: marketingOut(m)};
    },
    async capi_test() { const d = load(); needAdmin(d); const m = demoMarketing(); if (!m.pixel_id || !m.capi_token) bad("আগে Pixel ID আর Access token দিয়ে সেভ করুন।"); return {ok: true, message: "(ডেমো) Meta 1টা ইভেন্ট পেয়েছে"}; },
    // Demo stand-in for PayStation: the "payment" succeeds at once, so the preview shows the paid state.
    demoPay(d, o) {
      d.payments = d.payments || [];
      const inv = o.code + "-" + Math.random().toString(16).slice(2, 8).toUpperCase(), trx = "DEMO" + Math.random().toString(36).slice(2, 8).toUpperCase();
      d.payments.push({id: nextId(d), order_id: o.id, invoice: inv, amount: o.total, status: "success", trx_id: trx, method: "bKash", payer: "01711999888", sandbox: true, note: "ডেমো পেমেন্ট", created: now(), updated: now()});
      const restart = o.stage <= 1;
      Object.assign(o, {pay_status: "verified", pay_method: "PayStation", pay_trx: trx, pay_sender: "01711999888", stage: Math.max(1, o.stage), progress: Math.max(15, o.progress)});
      if (restart) o.deadline = now() + o.hours * 3600;
      const txt = `অনলাইন পেমেন্ট সফল: ৳${BN(o.total.toLocaleString("en-IN"))} · bKash · TrxID ${trx} (ডেমো)`;
      addEvent(d, o, txt, null);
      d.messages.push({id: nextId(d), order_id: o.id, user_id: null, from_admin: true, body: txt + "। ধন্যবাদ! আমরা কাজ শুরু করছি, ডেলিভারির কাউন্টডাউন এখন থেকে শুরু।", file_id: null, seen: false, created: now()});
      refSync(d, o); demoNotifyUpdate(d, o, ["অনলাইন পেমেন্ট সফল হয়েছে।"], true);
      return `dashboard.html#o=${o.id}&pay=success`;
    },
    async pay_start({order_id}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, order_id);
      if (o.cancelled) bad("এই অর্ডার বাতিল করা হয়েছে।", 409);
      if (o.pay_status === "verified") bad("এই অর্ডারের পেমেন্ট আগেই হয়ে গেছে।", 409);
      if (!publicPay().online) bad("অনলাইন পেমেন্ট এখন বন্ধ আছে।", 409);
      const url = mock.demoPay(d, o); save(d); return {payment_url: url};
    },
    async pay_verify({order_id}) {
      const d = load(), u = needAdmin(d), o = orderFor(d, u, order_id);
      return {results: [], payments: (d.payments || []).filter(p => p.order_id === o.id).reverse(), order: orderOut(d, u, o)};
    },
    async payment_settings() { const d = load(); needAdmin(d); return {settings: payOut(demoPaySettings())}; },
    async payment_settings_save(f) {
      const d = load(); needAdmin(d);
      const s = demoPaySettings(), p = {...s.paystation};
      p.enabled = !!f.ps_enabled; p.sandbox = !!f.ps_sandbox; p.merchant_id = String(f.ps_merchant_id || "").trim();
      if (f.ps_password) p.password = f.ps_password; if (f.ps_clear_password) p.password = "";
      p.pay_with_charge = f.ps_pay_with_charge ? 1 : 0;
      if (p.enabled && (!p.merchant_id || !p.password)) bad("গেটওয়ে চালু করতে Merchant ID আর পাসওয়ার্ড দুটোই দিন।");
      if (!f.manual_enabled && !p.enabled) bad("অন্তত একটা পেমেন্ট মাধ্যম চালু রাখতে হবে, নইলে কেউ অর্ডার করতে পারবে না।");
      const n = {manual_enabled: !!f.manual_enabled, paystation: p}; ls.set(PKEY, n); return {settings: payOut(n)};
    },
    async payment_test() { const d = load(); needAdmin(d); return {reachable: true, status_code: "2001", message: "(ডেমো) PayStation-এ সংযোগ হয়েছে, পরীক্ষার ইনভয়েস পাওয়া যায়নি, এটাই প্রত্যাশিত।", sandbox: demoPaySettings().paystation.sandbox}; },
    async payments() {
      const d = load(); needAdmin(d);
      const rows = (d.payments || []).slice().reverse().map(p => { const o = d.orders.find(x => x.id === p.order_id), c = o && d.users.find(x => x.id === o.user_id); return {...p, code: o?.code, customer: c?.name}; });
      const m = rows.filter(p => p.status === "success" && p.created > now() - 30 * 86400);
      return {payments: rows, month: {count: m.length, total: m.reduce((s, p) => s + p.amount, 0)}};
    },
    async catalog_save({catalog, reset}) {
      const d = load(); needAdmin(d);
      if (reset) { ls.del(CKEY); return {catalog: await loadFileCatalog()}; }
      const c = JSON.parse(catalog); ls.set(CKEY, c); return {catalog: c};
    },
    async order_create(fd) {
      const d = load(); let u = meUser(d);
      const g = k => String(fd.get(k) ?? "").trim();
      const cat = await demoCatalog(), sk = g("stack_key"), S = cat.stacks[sk];
      const pack = +g("pack"), tpl = +g("tpl");
      if (!S || !S.packs[pack] || !S.templates[tpl]) bad("প্যাকেজ বা টেমপ্লেট পাওয়া যায়নি।");
      const c = compute(cat, {stack: sk, pack, add: JSON.parse(g("addons") || "{}")});
      const online = g("pay_method") === "PayStation", pay = publicPay();
      if (online && !pay.online) bad("অনলাইন পেমেন্ট এখন বন্ধ আছে, পেজ রিফ্রেশ করে অন্য মাধ্যম বাছুন।", 409);
      if (!online && !pay.manual) bad("ম্যানুয়াল পেমেন্ট এখন বন্ধ আছে, অনলাইনে পেমেন্ট করুন।", 409);
      const info = {}; for (const k of ["admin_name", "whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info"]) info[k] = g(k);
      const email = u ? u.email : g("email").toLowerCase(), existing = u ? u.id : (d.users.find(x => x.email === email)?.id || 0);
      const refCode = g("ref_code").toUpperCase().replace(/[^A-Z0-9]/g, ""); let referrer = null, discount = 0;
      if (refCode) {
        const s = demoRef(), rb = m => { const e = new Error(m); e.status = 422; e.ref_invalid = true; throw e; };
        if (!s.enabled) rb("রেফারেল অফার এখন বন্ধ আছে। কোড সরিয়ে আবার জমা দিন।");
        referrer = referrerBy(d, refCode) || rb("রেফারেল কোডটা সঠিক নয়।");
        if ((existing && referrer.id === existing) || referrer.email === email || [info.phone, info.whatsapp, g("phone")].some(p => p && digits(p) === digits(referrer.phone))) rb("নিজের রেফারেল কোড নিজে ব্যবহার করা যায় না।");
        if (s.first_order_only && existing && d.orders.some(o => o.user_id === existing && !o.cancelled)) rb("রেফারেল ছাড় শুধু প্রথম অর্ডারে পাওয়া যায়।");
        if (c.total < s.min_order) rb(`রেফারেল ছাড় পেতে অর্ডার কমপক্ষে ${taka(s.min_order)} হতে হবে।`);
        discount = Math.min(s.discount, c.total); c.lines = [...c.lines, {label: `রেফারেল ছাড় (${refCode})`, amt: -discount, ref: true}]; c.total -= discount;
      }
      if (g("total") && +g("total") !== c.total) bad("দাম আপডেট হয়েছে, পেজ রিফ্রেশ করে আবার দেখে নিন।", 422);
      const PK = S.packs[pack], addSel = JSON.parse(g("addons") || "{}"), gwDocs = needsGatewayDocs(cat, sk, PK, addSel);
      if (gwDocs) {
        info.trade_license = g("trade_license"); info.nid_number = digits(g("nid_number"));
        if (info.trade_license.length < 3) bad("ট্রেড লাইসেন্স নম্বর দিন।");
        if (!/^(\d{10}|\d{13}|\d{17})$/.test(info.nid_number)) bad("সঠিক NID নম্বর দিন (১০, ১৩ বা ১৭ ডিজিট)।");
        for (const [k, l] of [["trade_license_file", "ট্রেড লাইসেন্স"], ["nid_file", "NID কার্ড"]]) { const f = fd.get(k); if (!f || !f.name) bad(`${l}-এর ছবি বা PDF দিন।`); if (!/\.(jpe?g|png|webp|pdf)$/i.test(f.name)) bad(`${l}-এর জন্য শুধু JPG, PNG, WebP বা PDF দিন।`); }
      }
      const dm = ["new", "own"].includes(g("domain_mode")) ? g("domain_mode") : "later"; let domain = null;
      const db = m => { const e = new Error(m); e.status = 422; e.domain_error = true; throw e; };
      if (dm === "new") {
        if (!((PK.incl || []).includes("domain") || addSel.domain)) db("এই অর্ডারে ডোমেইন নেই। অ্যাড-অন থেকে \"ডোমেইন ও হোস্টিং\" যোগ করুন, বা নিজের ডোমেইন দিন।");
        const d = g("domain").toLowerCase(), t = d.slice(d.lastIndexOf(".") + 1);
        if (!validLabel(d.slice(0, d.lastIndexOf(".")))) db("ডোমেইনের নাম সঠিক নয়।");
        if (!allowedTlds(cat, PK).includes(t)) db(`.${t} এই প্যাকেজে নেই।`);
        if (load().orders.some(o => !o.cancelled && o.domain === d)) { const e = new Error(`${d} আরেকটা অর্ডারে বুক করা আছে, আরেকটা বাছুন।`); e.status = 409; e.domain_error = true; throw e; }
        if ((await liveDomainStatus([d]))[d].status === "taken") { const e = new Error(`${d} এর মধ্যে অন্য কেউ নিয়ে নিয়েছে, আরেকটা বাছুন।`); e.status = 409; e.domain_error = true; throw e; }
        domain = d;
      } else if (dm === "own") { const d = g("domain").toLowerCase().replace(/^(https?:\/\/)?(www\.)?/, "").split(/[/?]/)[0]; if (!validHost(d)) db("আপনার ডোমেইনটা ঠিকভাবে লিখুন, যেমন myshop.com"); domain = d; }
      info.domain_mode = dm;
      if (!u) {
        let verified = false;
        if (!existing && otpRequired()) { if (!g("otp")) { const e = new Error("ইমেইলে পাঠানো ৬ ডিজিটের কোড দিন।"); e.status = 422; e.need_otp = true; throw e; } demoOtpCheck(d, email, "register", g("otp")); verified = true; }
        const id = createOrLogin(d, g("admin_name"), email, g("phone"), fd.get("password") || "", verified); ls.set(SKEY, id); u = meUser(d);
      }
      const oid = nextId(d), t = now(), code = "TC-" + Math.random().toString(36).slice(2, 8).toUpperCase(), T = S.templates[tpl];
      d.orders.push({id: oid, code, user_id: u.id, stack: S.name, pack: S.packs[pack].name, template: `${T.name} · ${T.code}`, lines: c.lines, total: c.total, hours: c.hours, products: c.products,
        pay_method: g("pay_method"), pay_sender: g("pay_sender"), pay_trx: g("pay_trx"), pay_status: "pending", info, stage: 0, progress: 5, site_url: null,
        developer_id: null, cancelled: false, deadline: t + c.hours * 3600, created: t, referrer_id: referrer ? referrer.id : null, domain});
      if (referrer) { d.referrals = d.referrals || []; d.referrals.push({id: nextId(d), order_id: oid, referrer_id: referrer.id, referee_id: u.id, code: refCode, reward: demoRef().reward, discount, status: "pending", created: t, earned: null}); const me2 = d.users.find(x => x.id === u.id); if (!me2.referred_by) me2.referred_by = referrer.id; }
      await addFile(d, oid, u.id, "logo", fd.get("logo"));
      await addFile(d, oid, u.id, "csv", fd.get("product_csv"));
      if (gwDocs) { await addFile(d, oid, u.id, "license", fd.get("trade_license_file")); await addFile(d, oid, u.id, "nid", fd.get("nid_file")); }
      d.events.push({id: nextId(d), order_id: oid, stage: 0, progress: 5, note: "অর্ডার ও তথ্য জমা হয়েছে।", by: u.id, created: t});
      d.messages.push({id: nextId(d), order_id: oid, user_id: null, from_admin: true, body: `আসসালামু আলাইকুম! অর্ডার ${code} পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।`, file_id: null, seen: false, created: t});
      const created = d.orders.find(o => o.id === oid);
      if (notifyOn("new_order")) { userMail(d, u.id, `অর্ডার ${code} পেয়েছি, ধন্যবাদ!`, "new_order", oid, true); adminMails(d).forEach(e => demoMail(d, e, `নতুন অর্ডার ${code} · ${taka(c.total)}`, "new_order", oid)); }
      const payment_url = online ? mock.demoPay(d, created) : undefined;
      save(d); return {order: orderOut(d, u, created), user: u, payment_url};
    },
    async orders() {
      const d = load(), u = needUser(d);
      return {orders: d.orders.filter(o => canSee(u, o)).sort((a, b) => b.id - a.id).map(o => orderOut(d, u, o))};
    },
    async order({id}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, id);
      markSeen(d, u, o.id); save(d);
      return {
        order: orderOut(d, u, o),
        events: d.events.filter(e => e.order_id === o.id).sort((a, b) => b.id - a.id).map(({by, ...e}) => e),
        messages: d.messages.filter(m => m.order_id === o.id).sort((a, b) => a.id - b.id).map(m => msgOut(d, m)),
        payments: isAdmin(u) ? (d.payments || []).filter(p => p.order_id === o.id).slice().reverse() : undefined,
        referral: isAdmin(u) ? (() => { const r = (d.referrals || []).find(x => x.order_id === o.id), ru = r && d.users.find(x => x.id === r.referrer_id); return r ? {id: r.id, code: r.code, status: r.status, reward: r.reward, discount: r.discount, referrer: {name: ru?.name, email: ru?.email}} : null; })() : undefined,
        files: d.files.filter(f => f.order_id === o.id).sort((a, b) => b.id - a.id).map(f => ({id: f.id, kind: f.kind, name: f.name, size: f.size, by_admin: isStaff(d.users.find(x => x.id === f.user_id)), created: f.created}))
      };
    },
    async messages({id, after}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, id);
      markSeen(d, u, o.id); save(d);
      return {messages: d.messages.filter(m => m.order_id === o.id && m.id > +after).sort((a, b) => a.id - b.id).map(m => msgOut(d, m))};
    },
    async message_send({order_id, body, after}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, order_id);
      body = String(body).trim(); if (!body) bad("মেসেজ লিখুন।");
      d.messages.push({id: nextId(d), order_id: o.id, user_id: u.id, from_admin: isStaff(u), body, file_id: null, seen: false, created: now()});
      demoNotifyMessage(d, o, u); save(d);
      if (!isStaff(u)) setTimeout(() => {
        const d2 = load();
        d2.messages.push({id: nextId(d2), order_id: o.id, user_id: o.developer_id || 1, from_admin: true, body: "(ডেমো) মেসেজ পেয়েছি। আসল সাইটে ডেভেলপার নিজে এখানে উত্তর দেবেন।", file_id: null, seen: false, created: now()});
        save(d2);
      }, 2500);
      return {messages: d.messages.filter(m => m.order_id === o.id && m.id > +after).map(m => msgOut(d, m))};
    },
    async file_upload({order_id, file, note}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, order_id);
      if (!file || !file.name) bad("একটা ফাইল বাছাই করুন।");
      const fid = await addFile(d, o.id, u.id, "attachment", file);
      d.messages.push({id: nextId(d), order_id: o.id, user_id: u.id, from_admin: isStaff(u), body: String(note || "").trim() || "ফাইল পাঠানো হয়েছে।", file_id: fid, seen: false, created: now()});
      demoNotifyMessage(d, o, u); save(d); return {file_id: fid};
    },
    async info_update(data) {
      const d = load(), u = needUser(d), o = orderFor(d, u, data.order_id);
      for (const k of ["whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info", "trade_license", "nid_number"]) if (k in data) o.info[k] = String(data[k]).trim();
      if (o.info.nid_number) { o.info.nid_number = digits(o.info.nid_number); if (!/^(\d{10}|\d{13}|\d{17})$/.test(o.info.nid_number)) bad("সঠিক NID নম্বর দিন (১০, ১৩ বা ১৭ ডিজিট)।"); }
      for (const k of ["whatsapp", "phone"]) if (o.info[k] && !phoneOK(o.info[k])) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
      addEvent(d, o, isStaff(u) ? "ব্যবসার তথ্য আপডেট করা হয়েছে।" : "কাস্টমার ব্যবসার তথ্য আপডেট করেছেন।", u.id);
      save(d); return {info: o.info};
    },
    async admin_update(data) {
      const d = load(), u = needUser(d); if (!isStaff(u)) bad("শুধু ডেভেলপার এটা করতে পারবেন।", 403);
      const o = orderFor(d, u, data.order_id), notes = [];
      const stage = Math.max(0, Math.min(4, +data.stage)), progress = Math.max(0, Math.min(100, +data.progress));
      const pay = isAdmin(u) && data.pay_status ? data.pay_status : o.pay_status;
      const site = String(data.site_url || "").trim();
      if (site && !/^https?:\/\/\S+\.\S+/.test(site)) bad("সাইটের পুরো লিংক দিন (https://...)।");
      let dom = o.domain || null;
      if ("domain" in data){ dom = String(data.domain || "").trim().toLowerCase().replace(/^(https?:\/\/)?(www\.)?/, "").split(/[/?]/)[0] || null; if (dom && !validHost(dom)) bad("ডোমেইন সঠিক নয়, যেমন myshop.com"); }
      if (dom !== (o.domain || null) && dom) notes.push("প্রোজেক্টের ডোমেইন: " + dom);
      if (pay !== o.pay_status) notes.push({pending: "পেমেন্ট যাচাই বাকি।", verified: "পেমেন্ট যাচাই সম্পন্ন।", rejected: "পেমেন্ট পাওয়া যায়নি, যোগাযোগ করুন।"}[pay]);
      if (site && site !== o.site_url) notes.push("সাইটের লিংক যোগ হয়েছে: " + site);
      if (String(data.note || "").trim()) notes.push(String(data.note).trim());
      const changed = notes.length || stage !== o.stage || progress !== o.progress, stageChanged = stage !== o.stage;
      Object.assign(o, {stage, progress, pay_status: pay, site_url: site || null, domain: dom});
      if (changed) addEvent(d, o, notes.join("\n") || null, u.id);
      refSync(d, o); demoNotifyUpdate(d, o, notes, stageChanged);
      save(d); return {order: orderOut(d, u, o)};
    },
    async assign({order_id, developer_id}) {
      const d = load(), u = needAdmin(d), o = orderFor(d, u, order_id);
      const dev = +developer_id ? d.users.find(x => x.id === +developer_id && isStaff(x) && x.active) : null;
      if (+developer_id && !dev) bad("এই ডেভেলপার পাওয়া যায়নি।");
      if ((o.developer_id || 0) !== (dev ? dev.id : 0)) { o.developer_id = dev ? dev.id : null; addEvent(d, o, dev ? `ডেভেলপার ${dev.name} এই প্রোজেক্টে কাজ করছেন।` : "ডেভেলপার পরিবর্তন হচ্ছে।", u.id); if (dev && dev.id !== u.id && notifyOn("assign")) userMail(d, dev.id, `নতুন প্রোজেক্ট: ${o.code}`, "assign", o.id); }
      save(d); return {order: orderOut(d, u, o)};
    },
    async order_adjust(data) {
      const d = load(), u = needAdmin(d), o = orderFor(d, u, data.order_id), notes = [];
      const fmt = n => "৳" + BN(Math.abs(n).toLocaleString("en-IN"));
      if (String(data.line_label || "").trim()) {
        const amt = Math.trunc(+data.line_amt);
        if (!amt || Math.abs(amt) > 1e6) bad("সঠিক পরিমাণ দিন (ছাড় হলে মাইনাস দিয়ে লিখুন)।");
        const lines = [...o.lines, {label: String(data.line_label).trim().slice(0, 120), amt, adj: true}], total = lines.reduce((s, l) => s + l.amt, 0);
        if (total < 0) bad("মোট টাকা শূন্যের কম হতে পারে না।");
        o.lines = lines; o.total = total;
        notes.push(`${amt < 0 ? "ছাড়" : "অতিরিক্ত চার্জ"}: ${data.line_label} (${fmt(amt)}), নতুন মোট ${fmt(total)}`);
      }
      const ext = Math.trunc(+data.extend_hours || 0);
      if (ext) { if (ext < 1 || ext > 720) bad("১ থেকে ৭২০ ঘণ্টা পর্যন্ত বাড়ানো যায়।"); o.deadline += ext * 3600; o.hours += ext; notes.push(`ডেলিভারির সময় ${BN(ext)} ঘণ্টা বাড়ানো হয়েছে।`); }
      if (data.cancelled != null && !!+data.cancelled !== !!o.cancelled) { o.cancelled = !!+data.cancelled; notes.push(o.cancelled ? "অর্ডারটি বাতিল করা হয়েছে।" : "অর্ডারটি আবার চালু করা হয়েছে।"); }
      if (!notes.length) bad("কিছু বদলানো হয়নি।");
      addEvent(d, o, notes.join("\n"), u.id);
      refSync(d, o); demoNotifyUpdate(d, o, notes, false);
      save(d); return {order: orderOut(d, u, o)};
    },
    async team() { const d = load(); needAdmin(d); return {team: teamRows(d)}; },
    async team_save(data) {
      const d = load(), u = needAdmin(d);
      if (data.op === "create") {
        const email = String(data.email || "").trim().toLowerCase();
        if (!String(data.name || "").trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("নাম ও সঠিক ইমেইল দিন।");
        if (String(data.password || "").length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।");
        if (d.users.some(x => x.email === email)) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে।", 409);
        d.users.push({id: nextId(d), name: String(data.name).trim(), email, phone: null, pass: data.password, role: data.role === "admin" ? "admin" : "developer", active: true, created: now()});
      } else {
        const t = d.users.find(x => x.id === +data.id && isStaff(x)); if (!t) bad("টিম মেম্বার পাওয়া যায়নি।", 404);
        if (data.op === "toggle") { if (t.id === u.id) bad("নিজের অ্যাকাউন্ট বন্ধ করা যায় না।"); t.active = !t.active; }
        else if (data.op === "role") { if (t.id === u.id) bad("নিজের রোল বদলানো যায় না।"); t.role = data.role === "admin" ? "admin" : "developer"; }
        else if (data.op === "password") { if (String(data.password || "").length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।"); t.pass = data.password; }
        else if (data.op === "update") updatePerson(d, t, data.name, data.email, t.phone);
        else if (data.op === "delete") {
          if (t.id === u.id) bad("নিজের অ্যাকাউন্ট মোছা যায় না।");
          if (t.role === "admin" && d.users.filter(x => x.role === "admin" && x.active).length <= 1) bad("অন্তত একজন অ্যাডমিন রাখতে হবে।");
          const mine = d.orders.filter(o => o.developer_id === t.id), n = mine.filter(o => !o.cancelled && o.stage < 4).length;
          mine.forEach(o => o.developer_id = null);
          d.users = d.users.filter(x => x.id !== t.id);
          save(d); return {team: teamRows(d), unassigned: n};
        }
      }
      save(d); return {team: teamRows(d)};
    },
    async customers() {
      const d = load(); needAdmin(d);
      return {customers: d.users.filter(u => u.role === "customer").map(u => {
        const os = d.orders.filter(o => o.user_id === u.id);
        return {id: u.id, name: u.name, email: u.email, phone: u.phone, active: u.active, avatar: u.avatar || null, orders: os.length, created: u.created,
          spent: os.filter(o => !o.cancelled && o.pay_status === "verified").reduce((s, o) => s + o.total, 0),
          running: os.filter(o => !o.cancelled && o.stage < 4).length, last_order: os.length ? Math.max(...os.map(o => o.created)) : null};
      }).sort((a, b) => (b.last_order || 0) - (a.last_order || 0))};
    },
    async customer_save(data) {
      const d = load(); needAdmin(d);
      if (data.op === "create") {
        const email = String(data.email || "").trim().toLowerCase();
        if (!String(data.name || "").trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("নাম ও সঠিক ইমেইল দিন।");
        if (data.phone && !phoneOK(data.phone)) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
        if (String(data.password || "").length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।");
        if (d.users.some(x => x.email === email)) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে।", 409);
        d.users.push({id: nextId(d), name: String(data.name).trim(), email, phone: data.phone || null, pass: data.password, role: "customer", active: true, created: now()});
      } else {
        const c = d.users.find(x => x.id === +data.id && x.role === "customer"); if (!c) bad("কাস্টমার পাওয়া যায়নি।", 404);
        if (data.op === "update") updatePerson(d, c, data.name, data.email, data.phone);
        else if (data.op === "toggle") c.active = !c.active;
        else if (data.op === "password") { if (String(data.password || "").length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।"); c.pass = data.password; }
        else if (data.op === "delete") {
          if (d.orders.some(o => o.user_id === c.id)) bad("এই কাস্টমারের অর্ডার আছে, তাই মোছা যাবে না। অ্যাকাউন্ট বন্ধ করে দিন।", 409);
          d.users = d.users.filter(x => x.id !== c.id);
        }
      }
      save(d); return mock.customers();
    },
    async analytics({days}) {
      const d = load(); needAdmin(d);
      days = [7, 30, 90, 365].includes(+days) ? +days : 30;
      const t = now(), a = d.analytics, D = 86400, sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
      const keys = []; for (let i = days - 1; i >= 0; i--) keys.push(dayKey(t - i * D));
      const prevKeys = []; for (let i = 2 * days - 1; i >= days; i--) prevKeys.push(dayKey(t - i * D));
      const day = k => a.days[k] || {v: 0, u: 0, checkout: 0, wa: 0};
      const uniq = sum(keys, k => day(k).u), views = sum(keys, k => day(k).v);
      const share = (obj, total) => { const s = sum(Object.values(obj), x => x); return Object.entries(obj).map(([k, n]) => ({k, n: Math.round(n / s * total)})).sort((x, y) => y.n - x.n); };
      const hs = sum(a.hours, x => x), hours = a.hours.map(h => Math.round(h / hs * uniq));
      const weekdays = [0, 0, 0, 0, 0, 0, 0]; keys.forEach(k => weekdays[new Date(k + "T00:00:00Z").getUTCDay()] += day(k).u);
      const inR = (o, from, to) => o.created >= t - from * D && o.created < t - to * D;
      const ords = d.orders.filter(o => inR(o, days, 0)), act = ords.filter(o => !o.cancelled), prev = d.orders.filter(o => inR(o, 2 * days, days));
      const ver = os => sum(os.filter(o => !o.cancelled && o.pay_status === "verified"), o => o.total);
      const done = act.filter(o => o.stage === 4).map(o => ({o, at: (d.events.find(e => e.order_id === o.id && e.stage === 4) || {}).created || o.deadline}));
      const group = (os, key) => { const m = {}; os.forEach(o => { const k = key(o); m[k] = m[k] || {k, n: 0, t: 0}; m[k].n++; m[k].t += o.total; }); return Object.values(m).sort((x, y) => y.n - x.n); };
      const stages = [0, 0, 0, 0, 0]; act.forEach(o => stages[o.stage]++);
      const month = off => { const x = new Date(); return new Date(Date.UTC(x.getUTCFullYear(), x.getUTCMonth() - off, 15)).toISOString().slice(0, 7); };
      const months = []; for (let i = 11; i >= 0; i--) { const m = month(i), os = d.orders.filter(o => !o.cancelled && dayKey(o.created).slice(0, 7) === m), h = a.months[m] || {earned: 0, orders: 0};
        months.push({m, earned: h.earned + ver(os), orders: h.orders + os.length}); }
      const cust = {}; d.orders.filter(o => !o.cancelled && o.pay_status === "verified").forEach(o => { const c = d.users.find(x => x.id === o.user_id); cust[c.id] = cust[c.id] || {name: c.name, email: c.email, orders: 0, total: 0}; cust[c.id].orders++; cust[c.id].total += o.total; });
      const live = Math.max(1, Math.round(4 + 3 * Math.sin(t / 300) + Math.cos(t / 47)));
      return {
        days,
        visitors: {uniques: uniq, views, returning: Math.round(uniq * a.returning), new: uniq - Math.round(uniq * a.returning), single_page: Math.round(uniq * a.single),
          prev_uniques: sum(prevKeys, k => day(k).u), today: day(dayKey(t)).u, total_uniques: sum(Object.values(a.days), x => x.u) + 12930, total_views: sum(Object.values(a.days), x => x.v) + 18240},
        live: demoLive(t, live),
        series: keys.map(k => { const os = d.orders.filter(o => !o.cancelled && dayKey(o.created) === k); return {d: k, uniques: day(k).u, visits: day(k).v, orders: os.length, earned: ver(os)}; }),
        hours, weekdays,
        devices: share(a.devices, uniq), browsers: share(a.browsers, uniq), os: share(a.os, uniq), countries: share(a.countries, uniq),
        cities: share(a.cities, Math.round(uniq * .92)), regions: share(a.regions, Math.round(uniq * .92)),
        referrers: share(a.refs, uniq), utm: share(a.utm, Math.round(uniq * .62)), pages: share(a.pages, views),
        funnel: {visitors: uniq, checkout: sum(keys, k => day(k).checkout), whatsapp: sum(keys, k => day(k).wa), orders: act.length},
        orders: {count: ords.length, prev_count: prev.length, cancelled: ords.length - act.length, done: done.length, running: act.filter(o => o.stage < 4).length, value: sum(act, o => o.total),
          on_time: done.filter(x => x.at <= x.o.deadline).length, delivered: done.length,
          avg_hours: done.length ? Math.round(sum(done, x => (x.at - x.o.created) / 3600) / done.length * 10) / 10 : 0, stages,
          by_stack: group(act, o => o.stack), by_pack: group(act, o => o.pack + " · " + o.stack), by_method: group(act, o => o.pay_method), by_pay: group(act, o => o.pay_status),
          sources: share({"fb_ads": 5, "facebook.com": 2, "": 1, "google.com": 1}, act.length)},
        earnings: {range: ver(ords), prev: ver(prev), all_time: ver(d.orders) + sum(Object.values(a.months), m => m.earned), pending: sum(d.orders.filter(o => !o.cancelled && o.pay_status === "pending"), o => o.total),
          this_month: months[11].earned, last_month: months[10].earned, months, top_customers: Object.values(cust).sort((x, y) => y.total - x.total).slice(0, 8)}
      };
    },
    async admin_stats({days}) {
      const d = load(); needAdmin(d);
      days = [7, 30, 90].includes(+days) ? +days : 30;
      const t = now(), a = d.analytics, live = Math.max(1, Math.round(4 + 3 * Math.sin(t / 300) + Math.cos(t / 47)));
      const keys = []; for (let i = days - 1; i >= 0; i--) keys.push(dayKey(t - i * 86400));
      const inRange = o => o.created >= t - days * 86400;
      const series = keys.map(k => {
        const v = a.days[k] || {v: 0, u: 0};
        const os = d.orders.filter(o => !o.cancelled && dayKey(o.created) === k);
        return {d: k, visits: v.v, uniques: v.u, orders: os.length, revenue: os.reduce((s, o) => s + o.total, 0)};
      });
      const sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
      const all = Object.values(a.days), rng = keys.map(k => a.days[k] || {v: 0, u: 0, checkout: 0, wa: 0});
      const share = (obj, total) => { const s = sum(Object.values(obj), x => x); return Object.entries(obj).map(([k, n]) => ({k, n: Math.round(n / s * total)})).sort((x, y) => y.n - x.n); };
      const ru = sum(rng, x => x.u), rv = sum(rng, x => x.v);
      const act = d.orders.filter(o => !o.cancelled), run = act.filter(o => o.stage < 4);
      const month = dayKey(t).slice(0, 7), stages = [0, 0, 0, 0, 0]; act.forEach(o => stages[o.stage]++);
      const addons = {}; d.orders.filter(o => !o.cancelled && inRange(o)).forEach(o => o.lines.slice(1).filter(l => !l.adj && !l.ref).forEach(l => { const k = l.label.replace(/ × .*$/, ""); addons[k] = (addons[k] || 0) + 1; }));
      const packs = {}; d.orders.filter(o => !o.cancelled && inRange(o)).forEach(o => { const k = o.stack + "|" + o.pack; packs[k] = packs[k] || {stack: o.stack, pack: o.pack, n: 0, total: 0}; packs[k].n++; packs[k].total += o.total; });
      const code = id => d.orders.find(o => o.id === id)?.code, who = id => d.users.find(x => x.id === id)?.name || null;
      const activity = [
        ...d.events.slice(-15).map(e => ({type: "event", order_id: e.order_id, code: code(e.order_id), who: who(e.by), text: e.note || `অগ্রগতি ${e.progress}%`, stage: e.stage, created: e.created})),
        ...d.messages.filter(m => !m.from_admin).slice(-15).map(m => ({type: "message", order_id: m.order_id, code: code(m.order_id), who: who(m.user_id), text: m.body, unread: !m.seen, created: m.created}))
      ].sort((x, y) => y.created - x.created).slice(0, 20);
      const paths = ["/", "/", "/", "/#packages", "/#packages", "/#features", "/dashboard.html"], devs = ["mobile", "mobile", "mobile", "mobile", "desktop", "tablet"];
      const liveList = demoLive(t, live);
      return {
        days,
        live: {count: live, list: liveList},
        visits: {today: (a.days[dayKey(t)] || {}).v || 0, today_uniques: (a.days[dayKey(t)] || {}).u || 0, total: sum(all, x => x.v) + 18240, total_uniques: sum(all, x => x.u) + 12930, range: rv, range_uniques: ru},
        series,
        pages: share(a.pages, rv), referrers: share(a.refs, ru), utm: share(a.utm, Math.round(ru * .62)), devices: share(a.devices, ru),
        funnel: {visitors: ru, checkout: sum(rng, x => x.checkout), whatsapp: sum(rng, x => x.wa), orders: d.orders.filter(o => !o.cancelled && inRange(o)).length},
        orders: {
          total: d.orders.length, running: run.length, done: act.filter(o => o.stage === 4).length, cancelled: d.orders.length - act.length,
          overdue: run.filter(o => o.deadline < t).length, due_soon: run.filter(o => o.deadline >= t && o.deadline < t + 86400).length,
          pending_pay: act.filter(o => o.pay_status === "pending").length, unassigned: run.filter(o => !o.developer_id).length,
          revenue: sum(act.filter(o => o.pay_status === "verified"), o => o.total), pending_amount: sum(act.filter(o => o.pay_status === "pending"), o => o.total),
          revenue_month: sum(act.filter(o => o.pay_status === "verified" && dayKey(o.created).slice(0, 7) === month), o => o.total),
          avg_order: act.length ? Math.round(sum(act, o => o.total) / act.length) : 0,
          unread: d.messages.filter(m => !m.from_admin && !m.seen).length, stages
        },
        packs: Object.values(packs).sort((x, y) => y.n - x.n),
        addons: Object.entries(addons).map(([k, n]) => ({k, n})).sort((x, y) => y.n - x.n).slice(0, 10),
        team: teamRows(d), counts: counts(d, await demoCatalog()), activity,
        referral: {requests: (d.payouts || []).filter(p => p.status === "requested").length, amount: (d.payouts || []).filter(p => p.status === "requested").reduce((x, p) => x + p.amount, 0)},
        email: {ready: emailReady(), failed: 0}
      };
    },
    track({t, p, r, n}) {
      const d = load(), k = dayKey(now()), day = d.analytics.days[k] = d.analytics.days[k] || {v: 0, u: 0, checkout: 0, wa: 0};
      if (t === "view") {
        day.v++; bump(d, "pages", p);
        const seenKey = "techill_demo_seen_" + k;
        if (!ls.get(seenKey)) { ls.set(seenKey, 1); day.u++; }
      }
      if (t === "event" && n === "checkout_open") day.checkout++;
      if (t === "event" && n === "whatsapp_click") day.wa++;
      save(d);
    },
    exportCSV() {
      const d = load(); needAdmin(d);
      const q = v => `"${String(v ?? "").replace(/"/g, '""')}"`, f = ts => new Date((ts + 6 * 3600) * 1000).toISOString().slice(0, 16).replace("T", " ");
      const rows = [["code", "domain", "created", "customer", "email", "phone", "platform", "package", "template", "total", "pay_method", "pay_sender", "trx", "pay_status", "stage", "progress", "developer", "deadline", "cancelled"],
        ...d.orders.slice().sort((a, b) => b.id - a.id).map(o => { const c = d.users.find(x => x.id === o.user_id), dv = d.users.find(x => x.id === o.developer_id);
          return [o.code, o.domain || "", f(o.created), c.name, c.email, c.phone, o.stack, o.pack, o.template, o.total, o.pay_method, o.pay_sender, o.pay_trx, o.pay_status, STAGES[o.stage], o.progress, dv?.name, f(o.deadline), o.cancelled ? "yes" : "no"]; })];
      return URL.createObjectURL(new Blob(["﻿" + rows.map(r => r.map(q).join(",")).join("\r\n")], {type: "text/csv;charset=utf-8"}));
    },
    fileUrl(id) { const f = load().files.find(x => x.id === +id); return f && f.data ? f.data : null; }
  };


  /* ---------- floating sales-support button: Messenger, WhatsApp, tawk.to live chat ---------- */
  const SW_CSS = `.tsw{position:fixed;right:max(16px,env(safe-area-inset-right,0px));bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:45;display:flex;flex-direction:column;align-items:flex-end;gap:12px;font-family:inherit;line-height:1.3}
.tsw.gone{display:none}
.tsw.open::before{content:"";position:fixed;inset:0;background:rgb(10 14 22 / .28);z-index:-1;animation:tswf .2s}
@keyframes tswf{from{opacity:0}}
.tsw-fab{width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:var(--blue,#0a84f0);color:#fff;display:grid;place-items:center;box-shadow:0 12px 28px -10px rgb(10 132 240 / .75),0 2px 6px rgb(0 0 0 / .14);transition:transform .2s;position:relative;padding:0}
.tsw-fab:hover{transform:scale(1.06)}
.tsw-fab:focus-visible{outline:3px solid var(--blue-ink,#0067c7);outline-offset:3px}
.tsw-fab svg{width:28px;height:28px;position:absolute;transition:transform .25s,opacity .2s}
.tsw-fab .x{opacity:0;transform:rotate(-90deg) scale(.6)}
.tsw.open .tsw-fab .c{opacity:0;transform:rotate(90deg) scale(.6)}
.tsw.open .tsw-fab .x{opacity:1;transform:none}
.tsw-fab::after{content:"";position:absolute;inset:0;border-radius:50%;animation:tswp 2.6s ease-out 1s 3}
@keyframes tswp{0%{box-shadow:0 0 0 0 rgb(10 132 240 / .55)}100%{box-shadow:0 0 0 18px rgb(10 132 240 / 0)}}
.tsw-menu{display:flex;flex-direction:column;align-items:flex-end;gap:10px;pointer-events:none}
.tsw.open .tsw-menu{pointer-events:auto}
.tsw-greet,.tsw-item{opacity:0;transform:translateY(14px) scale(.92);transition:opacity .18s,transform .28s cubic-bezier(.2,.9,.3,1.25)}
.tsw.open .tsw-greet,.tsw.open .tsw-item{opacity:1;transform:none}
.tsw.open .tsw-item:nth-last-child(1){transition-delay:.02s}.tsw.open .tsw-item:nth-last-child(2){transition-delay:.06s}.tsw.open .tsw-item:nth-last-child(3){transition-delay:.1s}.tsw.open .tsw-greet{transition-delay:.14s}
.tsw-greet{background:var(--ink,#121822);color:var(--bg,#fff);border-radius:14px 14px 4px 14px;padding:8px 14px;font-size:.88rem;max-width:240px;text-align:right;box-shadow:0 10px 24px -12px rgb(0 0 0 / .45)}
.tsw-item{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink,#121822);background:none;border:0;padding:0;cursor:pointer;font:inherit;text-align:right}
.tsw-item:focus-visible{outline:none}.tsw-item:focus-visible .tsw-ic{outline:3px solid var(--blue,#0a84f0);outline-offset:3px}
.tsw-lb{background:var(--surface,#fff);border:1px solid var(--line,#e1e7ef);border-radius:12px;padding:7px 13px;box-shadow:0 8px 22px -12px rgb(16 24 40 / .35)}
.tsw-lb b{display:block;font-size:.95rem}.tsw-lb small{display:block;font-size:.76rem;color:var(--muted,#5a6578);margin-top:1px}
.tsw-ic{width:50px;height:50px;border-radius:50%;display:grid;place-items:center;flex:none;box-shadow:0 10px 22px -10px rgb(0 0 0 / .45);transition:transform .15s;color:#fff}
.tsw-item:hover .tsw-ic{transform:scale(1.08)}
.tsw-ic svg{width:26px;height:26px}
.tsw-demo{position:absolute;right:0;bottom:76px;width:min(320px,calc(100vw - 32px));background:var(--surface,#fff);color:var(--ink,#121822);border:1px solid var(--line,#e1e7ef);border-radius:18px;box-shadow:0 24px 50px -20px rgb(16 24 40 / .45);overflow:hidden}
.tsw-demo header{background:#0a84f0;color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;font-weight:700}
.tsw-demo header button{all:unset;cursor:pointer;font-size:1.3rem;line-height:1;padding:0 4px}
.tsw-demo p{margin:0;padding:14px 16px;font-size:.9rem;color:var(--muted,#5a6578)}
.tsw-demo .bub{margin:14px 16px 0;background:var(--sunk,#eef3f9);color:var(--ink,#121822);border-radius:14px 14px 14px 4px;padding:10px 14px;font-size:.92rem}
@media (max-width:480px){.tsw-lb small{display:none}.tsw-fab{width:56px;height:56px}}
@media (prefers-reduced-motion:reduce){.tsw *,.tsw *::after{transition:none!important;animation:none!important}}`;
  const SW_IC = {
    chat: '<svg class="c" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 8.9 8.9 0 0 1-3.8-.8L3 20.5l1.6-4.7A8.1 8.1 0 0 1 4 11.5a8.4 8.4 0 0 1 8.5-8.3 8.4 8.4 0 0 1 8.5 8.3z"/><path d="M8.5 11.5h.01M12.5 11.5h.01M16.5 11.5h.01" stroke-width="2.8"/></svg>',
    x: '<svg class="x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    messenger: '<svg viewBox="0 0 24 24" fill="#fff"><path d="M12 2C6.4 2 2 6.1 2 11.7c0 2.9 1.2 5.4 3.1 7.2.2.1.3.3.3.6l.1 1.8c0 .6.6.9 1.1.7l2-.9c.2-.1.4-.1.5 0 .9.3 1.9.4 2.9.4 5.6 0 10-4.1 10-9.7S17.6 2 12 2zm6 7.5l-2.9 4.6c-.5.8-1.5.9-2.2.4l-2.3-1.7a.6.6 0 0 0-.7 0l-3.2 2.4c-.4.3-1-.2-.7-.6l2.9-4.7c.5-.7 1.5-.9 2.2-.4l2.3 1.8c.2.2.5.2.7 0l3.2-2.4c.4-.3 1 .2.7.6z"/></svg>',
    whatsapp: '<svg viewBox="0 0 24 24"><path d="M20.4 11.7A8.4 8.4 0 0 1 8 19.1l-4.5 1.4 1.5-4.3a8.4 8.4 0 1 1 15.4-4.5z" fill="none" stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/><path fill="#fff" d="M9.3 7.9c.2-.3.4-.4.7-.4h.5c.2 0 .4.1.5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.2.3-.1.5.3.6.8 1.2 1.3 1.6.6.5 1.2.9 1.8 1.1.2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.4.2.4.4 0 .3 0 1-.4 1.4-.4.5-1.2.8-1.9.8-.9 0-2.2-.4-3.7-1.7-1.6-1.4-2.4-2.9-2.6-3.7-.3-1.1.1-2.3.5-2.8z"/></svg>',
    tawk: '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13v-1a8 8 0 0 1 16 0v1"/><rect x="2.5" y="12.5" width="4" height="6" rx="1.6"/><rect x="17.5" y="12.5" width="4" height="6" rx="1.6"/><path d="M19.5 18.5c0 1.6-2.2 2.5-5.5 2.5h-1.5"/></svg>'
  };
  function supportWidget(cfg, user) {
    if (!cfg || document.getElementById("tsw")) return;
    const A = () => window.TechillAPI, ua = navigator.userAgent;
    const mobile = /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || (navigator.maxTouchPoints > 1 && /Macintosh/.test(ua));
    const tw = cfg.tawk, demoTawk = tw && tw.property === "demo";
    const okTawk = tw && (demoTawk || (/^[a-f0-9]{24}$/.test(tw.property) && /^[A-Za-z0-9]{3,30}$/.test(tw.widget || "")));
    const wa = String(cfg.whatsapp || "").replace(/\D/g, ""), txt = encodeURIComponent(cfg.wa_text || "");
    // Phones open the WhatsApp app (wa.me); computers go straight to WhatsApp Web.
    const waUrl = wa ? (mobile ? `https://wa.me/${wa}${txt ? "?text=" + txt : ""}` : `https://web.whatsapp.com/send?phone=${wa}${txt ? "&text=" + txt : ""}`) : "";
    const msUrl = /^https:\/\/m\.me\/[A-Za-z0-9.\-_%]+$/.test(cfg.messenger || "") ? cfg.messenger : "";
    const items = [];
    if (msUrl) items.push({k: "messenger", t: "Messenger", s: "ফেসবুক পেজে মেসেজ দিন", href: msUrl, bg: "linear-gradient(135deg,#00b2ff,#006aff 55%,#a033ff)"});
    if (waUrl) items.push({k: "whatsapp", t: "WhatsApp", s: mobile ? "হোয়াটসঅ্যাপে কথা বলুন" : "WhatsApp Web-এ কথা বলুন", href: waUrl, bg: "#25d366"});
    if (okTawk) items.push({k: "tawk", t: "লাইভ চ্যাট", s: "এখনই আমাদের টিমের সাথে", bg: "#0a84f0"});
    if (!items.length) return;
    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[c]));
    if (!document.getElementById("tsw-css")) { const st = document.createElement("style"); st.id = "tsw-css"; st.textContent = SW_CSS; document.head.appendChild(st); }
    const root = document.createElement("div"); root.className = "tsw"; root.id = "tsw";
    root.innerHTML = `<div class="tsw-menu" id="tswMenu" role="menu" aria-label="সাপোর্ট">${cfg.greeting ? `<p class="tsw-greet">${esc(cfg.greeting)}</p>` : ""}${items.map(i =>
      `${i.href ? `<a class="tsw-item" role="menuitem" href="${esc(i.href)}" target="_blank" rel="noopener" data-ch="${i.k}">` : `<button class="tsw-item" role="menuitem" type="button" data-ch="${i.k}">`}<span class="tsw-lb"><b>${esc(i.t)}</b><small>${esc(i.s)}</small></span><span class="tsw-ic" style="background:${i.bg}">${SW_IC[i.k]}</span>${i.href ? "</a>" : "</button>"}`).join("")}</div>
      <button class="tsw-fab" type="button" aria-expanded="false" aria-controls="tswMenu" aria-label="সাপোর্টে যোগাযোগ করুন">${SW_IC.chat}${SW_IC.x}</button>`;
    document.body.appendChild(root);
    const fab = root.querySelector(".tsw-fab"), menu = root.querySelector(".tsw-menu");
    let opened = false, tawkWant = false, tawkReady = false;
    const setOpen = o => {
      root.classList.toggle("open", o); fab.setAttribute("aria-expanded", String(o)); menu.inert = !o;
      if (o && !opened){ opened = true; A().track("event", {n: "support_open"}); }
      if (o && okTawk && !demoTawk) loadTawk();
    };
    setOpen(false);
    fab.addEventListener("click", () => setOpen(!root.classList.contains("open")));
    document.addEventListener("keydown", e => { if (e.key === "Escape" && root.classList.contains("open")){ setOpen(false); fab.focus(); } });
    document.addEventListener("click", e => { if (root.classList.contains("open") && (!root.contains(e.target) || e.target === root)) setOpen(false); });
    function loadTawk(){
      if (window.Tawk_API && window.Tawk_API._techill) return;
      const T = window.Tawk_API = window.Tawk_API || {}; window.Tawk_LoadStart = new Date(); T._techill = true;
      if (user && user.name) T.visitor = {name: user.name, email: user.email || ""};
      T.onLoad = () => { tawkReady = true; if (tawkWant) openTawk(); else try { T.hideWidget(); } catch (e) {} };
      T.onChatMinimized = () => { try { T.hideWidget(); } catch (e) {} root.classList.remove("gone"); };
      T.onChatHidden = () => root.classList.remove("gone");
      const sc = document.createElement("script"); sc.async = true; sc.charset = "UTF-8"; sc.setAttribute("crossorigin", "*");
      sc.src = `https://embed.tawk.to/${tw.property}/${tw.widget}`; document.head.appendChild(sc);
    }
    function openTawk(){
      const T = window.Tawk_API;
      if (!tawkReady || !T || typeof T.maximize !== "function"){ tawkWant = true; loadTawk(); return; }
      tawkWant = false; root.classList.add("gone");
      try { T.showWidget(); T.maximize(); } catch (e) { root.classList.remove("gone"); }
    }
    function demoPanel(){
      let p = root.querySelector(".tsw-demo");
      if (p){ p.remove(); return; }
      p = document.createElement("div"); p.className = "tsw-demo"; p.setAttribute("role", "dialog"); p.setAttribute("aria-label", "লাইভ চ্যাট");
      p.innerHTML = `<header><span>লাইভ চ্যাট</span><button type="button" aria-label="বন্ধ করুন">×</button></header><div class="bub">আসসালামু আলাইকুম! কীভাবে সাহায্য করতে পারি?</div><p>ডেমো: আসল সাইটে এখানে tawk.to-এর লাইভ চ্যাট খুলবে। অ্যাডমিন প্যানেল → সাপোর্ট বাটন থেকে tawk.to-এর লিংক দিন।</p>`;
      p.querySelector("button").addEventListener("click", () => p.remove());
      root.appendChild(p);
    }
    menu.addEventListener("click", e => {
      const it = e.target.closest("[data-ch]"); if (!it) return;
      const k = it.dataset.ch;
      A().track("event", {n: "support_" + k}); if (k === "whatsapp") A().track("event", {n: "whatsapp_click"}); A().fb("Contact", {content_name: k});
      if (k === "tawk"){ e.preventDefault(); setOpen(false); demoTawk ? demoPanel() : openTawk(); }
      else setOpen(false);
    });
  }

  /* ---------- public interface ---------- */
  async function run(action, args, httpCall) {
    await ready;
    if (demo) { await wait(120); return mock[action](args); }
    return httpCall();
  }
  async function init() {
    try {
      const res = await fetch("api.php?action=me", {credentials: "same-origin"});
      if (!(res.headers.get("content-type") || "").includes("application/json")) throw 0;
      const data = await res.json(); csrf = data.csrf || ""; demo = false; return data.user || null;
    } catch (e) { demo = true; return (await mock.me()).user; }
  }
  return {
    STAGES, compute, vid,
    get demo() { return demo; },
    init() { return ready || (ready = init()); },
    login: a => run("login", a, () => post("login", a)),
    register: a => run("register", a, () => post("register", a)),
    logout: () => run("logout", {}, () => post("logout", {})),
    catalog: () => run("catalog", {}, () => http("catalog")),
    saveCatalog: catalog => run("catalog_save", {catalog: JSON.stringify(catalog)}, () => post("catalog_save", {catalog: JSON.stringify(catalog)})),
    resetCatalog: () => run("catalog_save", {reset: 1}, () => post("catalog_save", {reset: 1})),
    createOrder: fd => { fd.append("vid", vid); return run("order_create", fd, () => post("order_create", fd)); },
    orders: () => run("orders", {}, () => http("orders")),
    order: id => run("order", {id}, () => http("order", {params: {id}})),
    messages: (id, after) => run("messages", {id, after}, () => http("messages", {params: {id, after}})),
    send: (order_id, body, after) => run("message_send", {order_id, body, after}, () => post("message_send", {order_id, body, after})),
    upload: (order_id, file, note) => run("file_upload", {order_id, file, note}, () => post("file_upload", {order_id, file, note})),
    updateInfo: data => run("info_update", data, () => post("info_update", data)),
    adminUpdate: data => run("admin_update", data, () => post("admin_update", data)),
    assign: (order_id, developer_id) => run("assign", {order_id, developer_id}, () => post("assign", {order_id, developer_id})),
    adjust: data => run("order_adjust", data, () => post("order_adjust", data)),
    stats: days => run("admin_stats", {days}, () => http("admin_stats", {params: {days}})),
    team: () => run("team", {}, () => http("team")),
    teamSave: data => run("team_save", data, () => post("team_save", data)),
    customers: () => run("customers", {}, () => http("customers")),
    customerSave: data => run("customer_save", data, () => post("customer_save", data)),
    analytics: days => run("analytics", {days}, () => http("analytics", {params: {days}})),
    payStart: order_id => run("pay_start", {order_id}, () => post("pay_start", {order_id})),
    payVerify: order_id => run("pay_verify", {order_id}, () => post("pay_verify", {order_id})),
    paymentSettings: () => run("payment_settings", {}, () => http("payment_settings")),
    savePaymentSettings: data => run("payment_settings_save", data, () => post("payment_settings_save", data)),
    paymentTest: () => run("payment_test", {}, () => post("payment_test", {})),
    payments: () => run("payments", {}, () => http("payments")),
    otpSend: (purpose, email) => run("otp_send", {purpose, email}, () => post("otp_send", {purpose, email: email || ""})),
    passwordReset: data => run("password_reset", data, () => post("password_reset", data)),
    emailVerify: otp => run("email_verify", {otp}, () => post("email_verify", {otp})),
    profileUpdate: data => run("profile_update", data, () => post("profile_update", data)),
    emailChange: data => run("email_change", data, () => post("email_change", data)),
    passwordChange: data => run("password_change", data, () => post("password_change", data)),
    emailSettings: () => run("email_settings", {}, () => http("email_settings")),
    saveEmail: data => run("email_save", data, () => post("email_save", data)),
    emailTest: to => run("email_test", {to}, () => post("email_test", {to: to || ""})),
    supportSettings: () => run("support_settings", {}, () => http("support_settings")),
    saveSupport: data => run("support_save", data, () => post("support_save", data)),
    // Names the server could not answer for (registry down, host blocks outbound calls) are retried from the browser.
    domainCheck: async (stack, pack, name) => {
      const r = await run("domain_check", {stack, pack, name}, () => http("domain_check", {params: {stack, pack, name}}));
      const miss = r.results.filter(x => x.status === "unknown").map(x => x.domain);
      if (miss.length && !demo) { const st = await liveDomainStatus(miss); r.results.forEach(x => { if (st[x.domain]) Object.assign(x, st[x.domain]); }); }
      return r;
    },
    allowedTlds, domainLabel, validLabel, validHost, needsGatewayDocs,
    refCheck: code => run("ref_check", {code}, () => http("ref_check", {params: {code}})),
    referral: () => run("referral", {}, () => http("referral")),
    payoutRequest: data => run("payout_request", data, () => post("payout_request", data)),
    referralAdmin: () => run("referral_admin", {}, () => http("referral_admin")),
    saveReferral: data => run("referral_save", data, () => post("referral_save", data)),
    referralOp: data => run("referral_op", data, () => post("referral_op", data)),
    payoutOp: data => run("payout_op", data, () => post("payout_op", data)),
    /* referral links: index.html?ref=CODE is remembered in this browser for 30 days */
    refCapture() {
      try { const c = (new URLSearchParams(location.search).get("ref") || "").toUpperCase().replace(/[^A-Z0-9]/g, ""); if (/^[A-Z0-9]{5,12}$/.test(c)) localStorage.setItem("techill_ref", JSON.stringify({code: c, at: Date.now()})); } catch (e) {}
      return this.refStored();
    },
    refStored() { try { const r = JSON.parse(localStorage.getItem("techill_ref")); if (r && /^[A-Z0-9]{5,12}$/.test(r.code) && Date.now() - r.at < 30 * 864e5) return r.code; } catch (e) {} return ""; },
    refForget() { try { localStorage.removeItem("techill_ref"); } catch (e) {} },
    async avatarUpload(file, user_id) { const photo = await shrink(file, 320, true); return run("avatar_upload", {file: photo, user_id}, () => post("avatar_upload", {photo: new File([photo], "photo.jpg", {type: photo.type}), user_id})); },
    avatarRemove: user_id => run("avatar_remove", {user_id}, () => post("avatar_remove", {user_id: user_id || ""})),
    async mediaUpload(file) { const photo = await shrink(file, 1200, false); return run("media_upload", {file: photo}, () => post("media_upload", {photo: new File([photo], "photo.jpg", {type: photo.type})})); },
    marketingSettings: () => run("marketing_settings", {}, () => http("marketing_settings")),
    saveMarketing: data => run("marketing_save", data, () => post("marketing_save", data)),
    capiTest: () => run("capi_test", {}, () => post("capi_test", {})),
    /* Facebook Pixel: load once with the admin's Pixel ID, then fb("Purchase", {...}, eventId) */
    initPixel(id) {
      if (!id || window.fbq) return;
      const n = window.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!window._fbq) window._fbq = n;
      n.push = n; n.loaded = true; n.version = "2.0"; n.queue = [];
      const sc = document.createElement("script"); sc.async = true; sc.src = "https://connect.facebook.net/en_US/fbevents.js"; document.head.appendChild(sc);
      window.fbq("init", String(id)); window.fbq("track", "PageView");
    },
    fb(event, data = {}, eventID) { try { if (window.fbq) window.fbq("track", event, {currency: "BDT", ...data}, eventID ? {eventID} : undefined); } catch (e) {} },
    async exportUrl() { await ready; return demo ? mock.exportCSV() : "api.php?action=export_orders"; },
    fileUrl: id => demo ? mock.fileUrl(id) : "api.php?action=file&id=" + encodeURIComponent(id),
    /* analytics beacon: page view now, a heartbeat every 30s while the tab is visible */
    async track(t, extra = {}) {
      await ready;
      const data = {vid, t, p: location.pathname.replace(/\/index\.html$/, "/") + (extra.hash ? location.hash : ""), ...extra};
      if (demo) { try { mock.track(data); } catch (e) {} return; }
      const f = new FormData(); for (const [k, v] of Object.entries(data)) if (v != null) f.append(k, v);
      try { navigator.sendBeacon ? navigator.sendBeacon("api.php?action=track", f) : fetch("api.php?action=track", {method: "POST", body: f, keepalive: true}); } catch (e) {}
    },
    startTracking() {
      const qs = new URLSearchParams(location.search), u = qs.get("utm_source") || (qs.get("ref") ? "referral" : "");
      let tz = ""; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ""; } catch (e) {}
      this.track("view", {r: document.referrer, u, tz});
      setInterval(() => { if (!document.hidden) this.track("ping"); }, 30000);
    },
    resetDemo() { [KEY, SKEY, CKEY, PKEY, MKEY, EKEY, SUPKEY, RKEY].forEach(k => ls.del(k)); },
    supportWidget
  };
})();
