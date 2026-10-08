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
    if (!data.ok) { const err = new Error(data.error || "কিছু একটা সমস্যা হয়েছে।"); err.status = res.status; throw err; }
    return data;
  }
  const toForm = obj => { if (obj instanceof FormData) return obj; const f = new FormData(); for (const [k, v] of Object.entries(obj)) if (v != null) f.append(k, v); return f; };
  const post = (action, obj) => http(action, {method: "POST", form: toForm(obj)});

  /* ---------- demo backend (browser only) ---------- */
  const KEY = "techill_demo_db_v3", SKEY = "techill_demo_uid", CKEY = "techill_demo_catalog", PKEY = "techill_demo_payments", MKEY = "techill_demo_marketing";
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
      {id: 4, name: "রাকিব হাসান", email: "demo@techill.top", phone: "01711000000", pass: "demo1234", role: "customer", active: true, created: t - 26 * D}
    ];
    const people = [["সুমাইয়া আক্তার", "01811222333"], ["মেহেদী হাসান", "01911444555"], ["ফারহানা ইসলাম", "01611777888"], ["আরিফ রহমান", "01511999000"], ["জান্নাতুল ফেরদৌস", "01712121212"], ["শাকিল খান", "01813131313"]];
    people.forEach(([name, phone], i) => users.push({id: 5 + i, name, email: `customer${i + 1}@example.com`, phone, pass: "x", role: "customer", active: true, created: t - (40 - i * 5) * D}));
    // [user, stack, pack, template, extras, pay, stage, progress, dev, createdAgo(h), hours, site, cancelled]
    const specs = [
      [4, "WooCommerce", "বিজনেস", "ফ্যাশন হাউস · W1", [["লোগো ডিজাইন", 1500]], "verified", 2, 45, 2, 18, 48, null, false],
      [5, "WooCommerce", "স্টার্টার", "বিউটি কর্নার · W3", [], "verified", 4, 100, 2, 24 * 9, 24, "https://glowbysumaiya.com", false],
      [6, "Laravel", "গ্রোথ", "টেক বাজার · L2", [["কুরিয়ার API × ১", 2000]], "verified", 3, 85, 3, 40, 48, null, false],
      [7, "WooCommerce", "প্রো", "হোম ডেকর · W5", [["ফেসবুক Pixel", 1000]], "verified", 2, 35, 3, 80, 72, null, false],
      [8, "WooCommerce", "বিজনেস", "ফ্রেশ বাজার · W4", [], "pending", 0, 5, null, 3, 48, null, false],
      [9, "Laravel", "লাইট", "মেগা স্টোর · L1", [["বিকাশ/নগদ মার্চেন্ট API", 3500]], "verified", 2, 60, 2, 24 * 4, 72, null, false],
      [10, "WooCommerce", "স্টার্টার", "গ্যাজেট জোন · W2", [], "rejected", 0, 5, null, 24 * 6, 24, null, true],
      [5, "Laravel", "আল্টিমেট", "গ্লো বিউটি · L5", [["বেসিক SEO সেটআপ", 2000]], "verified", 4, 100, 3, 24 * 20, 72, "https://glowbeauty.com.bd", false],
      [6, "WooCommerce", "বিজনেস", "ফ্যাশন হাউস · W1", [], "pending", 1, 15, 2, 7, 48, null, false]
    ];
    const prices = {"স্টার্টার": 6999, "বিজনেস": 11999, "প্রো": 17999, "লাইট": 12999, "গ্রোথ": 19999, "আল্টিমেট": 29999};
    let seq = 100;
    const orders = [], events = [], messages = [], files = [];
    specs.forEach(([uid, stack, pack, tpl, extras, pay, stage, progress, dev, ago, hours, site, cancelled], i) => {
      const id = 20 + i, created = t - ago * H, u = users.find(x => x.id === uid);
      const lines = [{label: `${pack} প্যাকেজ`, amt: prices[pack]}, ...extras.map(([label, amt]) => ({label, amt}))];
      const code = "TC-" + ["8F2K1Q", "3MZ7PA", "K9Q2XD", "7HB4RT", "Q2W8EN", "5TY6LM", "V1C3ZS", "J8D5GF", "R4N9UB"][i];
      orders.push({id, code, user_id: uid, stack, pack, template: tpl, lines, total: lines.reduce((s, l) => s + l.amt, 0), hours, products: 40,
        pay_method: ["bKash", "Nagad", "Rocket"][i % 3], pay_sender: u.phone, pay_trx: "9JK7XQ" + (2000 + i), pay_status: pay,
        info: {admin_name: u.name, whatsapp: u.phone, phone: u.phone, fb_page: "", address: "ঢাকা", business_intro: "অনলাইনে পণ্য বিক্রি করি।", additional_info: ""},
        stage, progress, site_url: site, developer_id: dev, cancelled, deadline: created + hours * H, created});
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
    return {seq, users, orders, events, messages, files, analytics};
  }
  const load = () => ls.get(KEY) || (ls.set(KEY, seed()), ls.get(KEY));
  const save = d => ls.set(KEY, d);
  const nextId = d => ++d.seq;
  const pubUser = u => u ? {id: u.id, name: u.name, email: u.email, phone: u.phone, role: u.role, avatar: u.avatar || null} : null;
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
  function createOrLogin(d, name, email, phone, pass) {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad("সঠিক ইমেইল দিন।");
    if (String(pass).length < 8) bad("পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।");
    const ex = d.users.find(x => x.email === email);
    if (ex) { if (ex.pass !== pass) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, পাসওয়ার্ড মেলেনি।", 409); return ex.id; }
    if (!name) bad("নাম দিন।");
    const id = nextId(d); d.users.push({id, name, email, phone: phone || null, pass, role: "customer", active: true, created: now()}); return id;
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

  const mock = {
    async me() { const d = load(); return {user: meUser(d)}; },
    async register({name, email, phone, password}) {
      const d = load(); email = String(email).trim().toLowerCase();
      if (d.users.some(x => x.email === email)) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, লগইন করুন।", 409);
      const id = createOrLogin(d, String(name).trim(), email, phone, password); save(d); ls.set(SKEY, id); return {user: meUser(d)};
    },
    async login({email, password}) {
      const d = load(); const u = d.users.find(x => x.email === String(email).trim().toLowerCase() && x.active);
      if (!u || u.pass !== password) bad("ইমেইল বা পাসওয়ার্ড ভুল।", 401);
      ls.set(SKEY, u.id); return {user: meUser(d)};
    },
    async logout() { ls.del(SKEY); return {}; },
    async catalog() { const m = demoMarketing(); return {catalog: await demoCatalog(), payments: publicPay(), pixel: m.pixel_enabled && m.pixel_id ? {id: m.pixel_id} : null}; },
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
      if (g("total") && +g("total") !== c.total) bad("দাম আপডেট হয়েছে, পেজ রিফ্রেশ করে আবার দেখে নিন।", 422);
      const online = g("pay_method") === "PayStation", pay = publicPay();
      if (online && !pay.online) bad("অনলাইন পেমেন্ট এখন বন্ধ আছে, পেজ রিফ্রেশ করে অন্য মাধ্যম বাছুন।", 409);
      if (!online && !pay.manual) bad("ম্যানুয়াল পেমেন্ট এখন বন্ধ আছে, অনলাইনে পেমেন্ট করুন।", 409);
      if (!u) { const id = createOrLogin(d, g("admin_name"), g("email").toLowerCase(), g("phone"), fd.get("password") || ""); ls.set(SKEY, id); u = meUser(d); }
      const info = {}; for (const k of ["admin_name", "whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info"]) info[k] = g(k);
      const oid = nextId(d), t = now(), code = "TC-" + Math.random().toString(36).slice(2, 8).toUpperCase(), T = S.templates[tpl];
      d.orders.push({id: oid, code, user_id: u.id, stack: S.name, pack: S.packs[pack].name, template: `${T.name} · ${T.code}`, lines: c.lines, total: c.total, hours: c.hours, products: c.products,
        pay_method: g("pay_method"), pay_sender: g("pay_sender"), pay_trx: g("pay_trx"), pay_status: "pending", info, stage: 0, progress: 5, site_url: null,
        developer_id: null, cancelled: false, deadline: t + c.hours * 3600, created: t});
      await addFile(d, oid, u.id, "logo", fd.get("logo"));
      await addFile(d, oid, u.id, "csv", fd.get("product_csv"));
      d.events.push({id: nextId(d), order_id: oid, stage: 0, progress: 5, note: "অর্ডার ও তথ্য জমা হয়েছে।", by: u.id, created: t});
      d.messages.push({id: nextId(d), order_id: oid, user_id: null, from_admin: true, body: `আসসালামু আলাইকুম! অর্ডার ${code} পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।`, file_id: null, seen: false, created: t});
      const created = d.orders.find(o => o.id === oid);
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
      save(d);
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
      save(d); return {file_id: fid};
    },
    async info_update(data) {
      const d = load(), u = needUser(d), o = orderFor(d, u, data.order_id);
      for (const k of ["whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info"]) if (k in data) o.info[k] = String(data[k]).trim();
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
      if (pay !== o.pay_status) notes.push({pending: "পেমেন্ট যাচাই বাকি।", verified: "পেমেন্ট যাচাই সম্পন্ন।", rejected: "পেমেন্ট পাওয়া যায়নি, যোগাযোগ করুন।"}[pay]);
      if (site && site !== o.site_url) notes.push("সাইটের লিংক যোগ হয়েছে: " + site);
      if (String(data.note || "").trim()) notes.push(String(data.note).trim());
      const changed = notes.length || stage !== o.stage || progress !== o.progress;
      Object.assign(o, {stage, progress, pay_status: pay, site_url: site || null});
      if (changed) addEvent(d, o, notes.join("\n") || null, u.id);
      save(d); return {order: orderOut(d, u, o)};
    },
    async assign({order_id, developer_id}) {
      const d = load(), u = needAdmin(d), o = orderFor(d, u, order_id);
      const dev = +developer_id ? d.users.find(x => x.id === +developer_id && isStaff(x) && x.active) : null;
      if (+developer_id && !dev) bad("এই ডেভেলপার পাওয়া যায়নি।");
      if ((o.developer_id || 0) !== (dev ? dev.id : 0)) { o.developer_id = dev ? dev.id : null; addEvent(d, o, dev ? `ডেভেলপার ${dev.name} এই প্রোজেক্টে কাজ করছেন।` : "ডেভেলপার পরিবর্তন হচ্ছে।", u.id); }
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
      const addons = {}; d.orders.filter(o => !o.cancelled && inRange(o)).forEach(o => o.lines.slice(1).filter(l => !l.adj).forEach(l => { const k = l.label.replace(/ × .*$/, ""); addons[k] = (addons[k] || 0) + 1; }));
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
        team: teamRows(d), counts: counts(d, await demoCatalog()), activity
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
      const rows = [["code", "created", "customer", "email", "phone", "platform", "package", "template", "total", "pay_method", "pay_sender", "trx", "pay_status", "stage", "progress", "developer", "deadline", "cancelled"],
        ...d.orders.slice().sort((a, b) => b.id - a.id).map(o => { const c = d.users.find(x => x.id === o.user_id), dv = d.users.find(x => x.id === o.developer_id);
          return [o.code, f(o.created), c.name, c.email, c.phone, o.stack, o.pack, o.template, o.total, o.pay_method, o.pay_sender, o.pay_trx, o.pay_status, STAGES[o.stage], o.progress, dv?.name, f(o.deadline), o.cancelled ? "yes" : "no"]; })];
      return URL.createObjectURL(new Blob(["﻿" + rows.map(r => r.map(q).join(",")).join("\r\n")], {type: "text/csv;charset=utf-8"}));
    },
    fileUrl(id) { const f = load().files.find(x => x.id === +id); return f && f.data ? f.data : null; }
  };

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
      const u = new URLSearchParams(location.search).get("utm_source") || "";
      let tz = ""; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ""; } catch (e) {}
      this.track("view", {r: document.referrer, u, tz});
      setInterval(() => { if (!document.hidden) this.track("ping"); }, 30000);
    },
    resetDemo() { ls.del(KEY); ls.del(SKEY); ls.del(CKEY); ls.del(PKEY); ls.del(MKEY); }
  };
})();
