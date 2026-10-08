/* Techill API client, shared by index.html and dashboard.html.
   Talks to api.php. When api.php is not reachable (a static preview with no PHP),
   it switches to a demo backend that lives in this browser only. */
window.TechillAPI = (() => {
  let csrf = "", demo = false;

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
  const KEY = "techill_demo_db", SKEY = "techill_demo_uid";
  let mem = {};
  const ls = {
    get(k) { try { const v = localStorage.getItem(k); return v == null ? mem[k] ?? null : JSON.parse(v); } catch (e) { return mem[k] ?? null; } },
    set(k, v) { mem[k] = v; try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    del(k) { delete mem[k]; try { localStorage.removeItem(k); } catch (e) {} }
  };
  const now = () => Math.floor(Date.now() / 1000);
  const wait = ms => new Promise(r => setTimeout(r, ms));
  function seed() {
    const t = now(), H = 3600;
    return {
      seq: 20,
      users: [
        {id: 1, name: "Techill ডেভেলপার", email: "admin@techill.top", phone: null, pass: "admin1234", role: "admin"},
        {id: 2, name: "রাকিব হাসান", email: "demo@techill.top", phone: "01711000000", pass: "demo1234", role: "customer"}
      ],
      orders: [{
        id: 3, code: "TC-8F2K1Q", user_id: 2, stack: "WooCommerce", pack: "বিজনেস", template: "ফ্যাশন হাউস · W1",
        lines: [{label: "বিজনেস প্যাকেজ", amt: 11999}, {label: "লোগো ডিজাইন", amt: 1500}], total: 13499, hours: 48, products: 40,
        pay_method: "bKash", pay_sender: "01711000000", pay_trx: "9JK7XQ2LMP", pay_status: "verified",
        info: {admin_name: "রাকিব হাসান", whatsapp: "01711000000", phone: "01711000000", fb_page: "https://facebook.com/rakibfashion", address: "মিরপুর ১০, ঢাকা", business_intro: "ছেলেদের পাঞ্জাবি আর শার্ট বিক্রি করি। ঢাকার ভেতরে ডেলিভারি ৬০ টাকা, বাইরে ১২০।", additional_info: ""},
        stage: 2, progress: 45, site_url: null, deadline: t + 30 * H, created: t - 18 * H
      }],
      events: [
        {id: 4, order_id: 3, stage: 0, progress: 5, note: "অর্ডার ও তথ্য জমা হয়েছে।", created: t - 18 * H},
        {id: 5, order_id: 3, stage: 1, progress: 15, note: "পেমেন্ট যাচাই সম্পন্ন।", created: t - 16 * H},
        {id: 6, order_id: 3, stage: 2, progress: 45, note: "থিম সেটআপ আর ব্র্যান্ডিং শেষ। এখন প্রোডাক্ট আপলোড চলছে।", created: t - 3 * H}
      ],
      messages: [
        {id: 7, order_id: 3, user_id: null, from_admin: true, body: "আসসালামু আলাইকুম! অর্ডার TC-8F2K1Q পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।", file_id: null, seen: true, created: t - 18 * H},
        {id: 8, order_id: 3, user_id: 2, from_admin: false, body: "ব্যানারে ঈদ অফারের লেখা দিতে চাই, ছবি পাঠাবো?", file_id: null, seen: true, created: t - 5 * H},
        {id: 9, order_id: 3, user_id: 1, from_admin: true, body: "অবশ্যই, ফাইল ট্যাব থেকে বা এখানে 📎 দিয়ে পাঠিয়ে দিন।", file_id: null, seen: false, created: t - 4 * H}
      ],
      files: [{id: 10, order_id: 3, user_id: 2, kind: "csv", name: "products.csv", size: 4210, data: null, created: t - 18 * H}]
    };
  }
  const load = () => ls.get(KEY) || (ls.set(KEY, seed()), ls.get(KEY));
  const save = d => ls.set(KEY, d);
  const nextId = d => ++d.seq;
  const meUser = d => { const id = ls.get(SKEY); const u = d.users.find(x => x.id === id); return u ? {id: u.id, name: u.name, email: u.email, phone: u.phone, role: u.role} : null; };
  const bad = msg => { throw new Error(msg); };
  const needUser = d => meUser(d) || bad("আগে লগইন করুন।");
  const isAdmin = u => u && u.role === "admin";
  const orderFor = (d, u, id) => { const o = d.orders.find(x => x.id === +id); if (!o || (!isAdmin(u) && o.user_id !== u.id)) bad("অর্ডার পাওয়া যায়নি।"); return o; };
  const unreadFor = (d, u, o) => d.messages.filter(m => m.order_id === o.id && !m.seen && m.from_admin === !isAdmin(u)).length;
  function orderOut(d, u, o) {
    const r = {...o}; delete r.user_id; r.unread = unreadFor(d, u, o);
    if (isAdmin(u)) { const c = d.users.find(x => x.id === o.user_id); r.customer = {name: c.name, email: c.email, phone: c.phone}; }
    return r;
  }
  const msgOut = (d, m) => {
    const f = m.file_id ? d.files.find(x => x.id === m.file_id) : null, by = d.users.find(x => x.id === m.user_id);
    return {id: m.id, from_admin: m.from_admin, system: m.user_id == null, body: m.body, by: by ? by.name : "Techill", file: f ? {id: f.id, name: f.name, size: f.size} : null, created: m.created};
  };
  const markSeen = (d, u, oid) => d.messages.forEach(m => { if (m.order_id === oid && m.from_admin === !isAdmin(u)) m.seen = true; });
  const phoneOK = v => /^(?:\+?88)?01[3-9]\d{8}$/.test(String(v).replace(/[\s-]/g, "").replace(/[০-৯]/g, c => "০১২৩৪৫৬৭৮৯".indexOf(c)));
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
    if (ex) { if (ex.pass !== pass) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, পাসওয়ার্ড মেলেনি।"); return ex.id; }
    if (!name) bad("নাম দিন।");
    const id = nextId(d); d.users.push({id, name, email, phone: phone || null, pass, role: "customer"}); return id;
  }

  const mock = {
    async me() { const d = load(); return {user: meUser(d)}; },
    async register({name, email, phone, password}) {
      const d = load(); email = String(email).trim().toLowerCase();
      if (d.users.some(x => x.email === email)) bad("এই ইমেইলে আগেই অ্যাকাউন্ট আছে, লগইন করুন।");
      const id = createOrLogin(d, String(name).trim(), email, phone, password); save(d); ls.set(SKEY, id); return {user: meUser(d)};
    },
    async login({email, password}) {
      const d = load(); const u = d.users.find(x => x.email === String(email).trim().toLowerCase());
      if (!u || u.pass !== password) bad("ইমেইল বা পাসওয়ার্ড ভুল।");
      ls.set(SKEY, u.id); return {user: meUser(d)};
    },
    async logout() { ls.del(SKEY); return {}; },
    async order_create(fd) {
      const d = load(); let u = meUser(d);
      const g = k => String(fd.get(k) ?? "").trim();
      if (!u) { const id = createOrLogin(d, g("admin_name"), g("email").toLowerCase(), g("phone"), fd.get("password") || ""); ls.set(SKEY, id); u = meUser(d); }
      const lines = JSON.parse(g("lines") || "[]"), total = +g("total");
      if (lines.reduce((s, l) => s + l.amt, 0) !== total) bad("মোট টাকার হিসাব মেলেনি।");
      const info = {}; for (const k of ["admin_name", "whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info"]) info[k] = g(k);
      const oid = nextId(d), t = now(), code = "TC-" + Math.random().toString(36).slice(2, 8).toUpperCase();
      d.orders.push({id: oid, code, user_id: u.id, stack: g("stack"), pack: g("pack"), template: g("template"), lines, total, hours: +g("hours"), products: +g("products"),
        pay_method: g("pay_method"), pay_sender: g("pay_sender"), pay_trx: g("pay_trx"), pay_status: "pending", info, stage: 0, progress: 5, site_url: null,
        deadline: t + +g("hours") * 3600, created: t});
      await addFile(d, oid, u.id, "logo", fd.get("logo"));
      await addFile(d, oid, u.id, "csv", fd.get("product_csv"));
      d.events.push({id: nextId(d), order_id: oid, stage: 0, progress: 5, note: "অর্ডার ও তথ্য জমা হয়েছে।", created: t});
      d.messages.push({id: nextId(d), order_id: oid, user_id: null, from_admin: true, body: `আসসালামু আলাইকুম! অর্ডার ${code} পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।`, file_id: null, seen: false, created: t});
      save(d); return {order: orderOut(d, u, d.orders.find(o => o.id === oid)), user: u};
    },
    async orders() {
      const d = load(), u = needUser(d);
      return {orders: d.orders.filter(o => isAdmin(u) || o.user_id === u.id).sort((a, b) => b.id - a.id).map(o => orderOut(d, u, o))};
    },
    async order({id}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, id);
      markSeen(d, u, o.id); save(d);
      return {
        order: orderOut(d, u, o),
        events: d.events.filter(e => e.order_id === o.id).sort((a, b) => b.id - a.id),
        messages: d.messages.filter(m => m.order_id === o.id).map(m => msgOut(d, m)),
        files: d.files.filter(f => f.order_id === o.id).sort((a, b) => b.id - a.id).map(f => ({id: f.id, kind: f.kind, name: f.name, size: f.size, by_admin: d.users.find(x => x.id === f.user_id)?.role === "admin", created: f.created}))
      };
    },
    async messages({id, after}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, id);
      markSeen(d, u, o.id); save(d);
      return {messages: d.messages.filter(m => m.order_id === o.id && m.id > +after).map(m => msgOut(d, m))};
    },
    async message_send({order_id, body, after}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, order_id);
      body = String(body).trim(); if (!body) bad("মেসেজ লিখুন।");
      d.messages.push({id: nextId(d), order_id: o.id, user_id: u.id, from_admin: isAdmin(u), body, file_id: null, seen: false, created: now()});
      save(d);
      if (!isAdmin(u)) setTimeout(() => {
        const d2 = load();
        d2.messages.push({id: nextId(d2), order_id: o.id, user_id: 1, from_admin: true, body: "(ডেমো) মেসেজ পেয়েছি। আসল সাইটে ডেভেলপার নিজে এখানে উত্তর দেবেন।", file_id: null, seen: false, created: now()});
        save(d2);
      }, 2500);
      return {messages: d.messages.filter(m => m.order_id === o.id && m.id > +after).map(m => msgOut(d, m))};
    },
    async file_upload({order_id, file, note}) {
      const d = load(), u = needUser(d), o = orderFor(d, u, order_id);
      if (!file || !file.name) bad("একটা ফাইল বাছাই করুন।");
      const fid = await addFile(d, o.id, u.id, "attachment", file);
      d.messages.push({id: nextId(d), order_id: o.id, user_id: u.id, from_admin: isAdmin(u), body: String(note || "").trim() || "ফাইল পাঠানো হয়েছে।", file_id: fid, seen: false, created: now()});
      save(d); return {file_id: fid};
    },
    async info_update(data) {
      const d = load(), u = needUser(d), o = orderFor(d, u, data.order_id);
      for (const k of ["whatsapp", "phone", "fb_page", "address", "business_intro", "additional_info"]) if (k in data) o.info[k] = String(data[k]).trim();
      for (const k of ["whatsapp", "phone"]) if (o.info[k] && !phoneOK(o.info[k])) bad("সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।");
      d.events.push({id: nextId(d), order_id: o.id, stage: o.stage, progress: o.progress, note: isAdmin(u) ? "ব্যবসার তথ্য আপডেট করা হয়েছে।" : "কাস্টমার ব্যবসার তথ্য আপডেট করেছেন।", created: now()});
      save(d); return {info: o.info};
    },
    async admin_update(data) {
      const d = load(), u = needUser(d); if (!isAdmin(u)) bad("শুধু ডেভেলপার এটা করতে পারবেন।");
      const o = orderFor(d, u, data.order_id), notes = [];
      const stage = Math.max(0, Math.min(4, +data.stage)), progress = Math.max(0, Math.min(100, +data.progress));
      const site = String(data.site_url || "").trim();
      if (site && !/^https?:\/\/\S+\.\S+/.test(site)) bad("সাইটের পুরো লিংক দিন (https://...)।");
      if (data.pay_status !== o.pay_status) notes.push({pending: "পেমেন্ট যাচাই বাকি।", verified: "পেমেন্ট যাচাই সম্পন্ন।", rejected: "পেমেন্ট পাওয়া যায়নি, যোগাযোগ করুন।"}[data.pay_status]);
      if (site && site !== o.site_url) notes.push("সাইটের লিংক যোগ হয়েছে: " + site);
      if (String(data.note || "").trim()) notes.push(String(data.note).trim());
      const changed = notes.length || stage !== o.stage || progress !== o.progress;
      Object.assign(o, {stage, progress, pay_status: data.pay_status, site_url: site || null});
      if (changed) d.events.push({id: nextId(d), order_id: o.id, stage, progress, note: notes.join("\n") || null, created: now()});
      save(d); return {order: orderOut(d, u, o)};
    },
    fileUrl(id) { const f = load().files.find(x => x.id === +id); return f && f.data ? f.data : null; }
  };

  /* ---------- public interface ---------- */
  async function run(action, args, httpCall) {
    if (demo) { await wait(150); return mock[action](args); }
    return httpCall();
  }
  return {
    get demo() { return demo; },
    async init() {
      try {
        const res = await fetch("api.php?action=me", {credentials: "same-origin"});
        if (!(res.headers.get("content-type") || "").includes("application/json")) throw 0;
        const data = await res.json(); csrf = data.csrf || ""; demo = false; return data.user || null;
      } catch (e) { demo = true; return (await mock.me()).user; }
    },
    login: a => run("login", a, () => post("login", a)),
    register: a => run("register", a, () => post("register", a)),
    logout: () => run("logout", {}, () => post("logout", {})),
    createOrder: fd => run("order_create", fd, () => post("order_create", fd)),
    orders: () => run("orders", {}, () => http("orders")),
    order: id => run("order", {id}, () => http("order", {params: {id}})),
    messages: (id, after) => run("messages", {id, after}, () => http("messages", {params: {id, after}})),
    send: (order_id, body, after) => run("message_send", {order_id, body, after}, () => post("message_send", {order_id, body, after})),
    upload: (order_id, file, note) => run("file_upload", {order_id, file, note}, () => post("file_upload", {order_id, file, note})),
    updateInfo: data => run("info_update", data, () => post("info_update", data)),
    adminUpdate: data => run("admin_update", data, () => post("admin_update", data)),
    fileUrl: id => demo ? mock.fileUrl(id) : "api.php?action=file&id=" + encodeURIComponent(id),
    resetDemo() { ls.del(KEY); ls.del(SKEY); }
  };
})();
