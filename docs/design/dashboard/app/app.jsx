// app.jsx — wires the shell, pages, drawer, tweaks and bilingual/RTL state.

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "theme": "Light",
  "skin": "Playful",
  "shell": "Standard",
  "accent": "#2563eb",
  "density": "Comfortable",
  "lang": "ar"
}/*EDITMODE-END*/;

function nowHM() {
  const d = new Date();
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const lang = t.lang === 'en' ? 'en' : 'ar';
  const floating = (t.shell || 'Standard') === 'Floating';
  const dark = (t.theme || 'Light') === 'Dark';
  const playful = (t.skin || 'Playful') === 'Playful';

  const [page, setPage] = React.useState('overview');
  const [openId, setOpenId] = React.useState(null);
  const [collapsed, setCollapsed] = React.useState(false);
  const [orders, setOrders] = React.useState(ORDERS);
  const [drivers, setDrivers] = React.useState(DRIVER_RECORDS);
  const [users, setUsers] = React.useState(USERS);
  const [merchants, setMerchants] = React.useState(MERCHANT_RECORDS);
  const [openMerchant, setOpenMerchant] = React.useState(null);
  const [merchantFormState, setMerchantFormState] = React.useState(null); // null | 'new' | <merchantId>
  const [earnings, setEarnings] = React.useState(SELLER_EARNINGS);
  const [settlements, setSettlements] = React.useState(SETTLEMENTS);
  const [payouts, setPayouts] = React.useState(PAYOUTS);
  const [settleScope, setSettleScope] = React.useState('all');
  const [staffMode, setStaffMode] = React.useState('admin');
  const [settleDriverId, setSettleDriverId] = React.useState(null);
  const [financeRange, setFinanceRange] = React.useState('30d');
  const [settings, setSettings] = React.useState(PLATFORM_SETTINGS);
  const [savedSettings, setSavedSettings] = React.useState(PLATFORM_SETTINGS);
  const [staffList, setStaffList] = React.useState(STAFF_RECORDS);
  const [openStaff, setOpenStaff] = React.useState(null);
  const [addStaff, setAddStaff] = React.useState(false);
  const [tempPw, setTempPw] = React.useState(null);
  const STAFF = { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' };
  const [openDriver, setOpenDriver] = React.useState(null);
  const [openUser, setOpenUser] = React.useState(null);
  const [addDriver, setAddDriver] = React.useState(false);
  const [presetUser, setPresetUser] = React.useState(null);
  const [toast, setToast] = React.useState(null);
  const [selectedOffice, setSelectedOffice] = React.useState(null);
  const toastTimer = React.useRef(null);

  React.useEffect(() => { document.documentElement.style.setProperty('--accent', t.accent); }, [t.accent]);
  React.useEffect(() => {
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = lang;
  }, [lang]);
  React.useEffect(() => {
    document.body.setAttribute('data-density', (t.density || 'Comfortable').toLowerCase());
  }, [t.density]);
  React.useEffect(() => {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-skin', playful ? 'playful' : 'clean');
  }, [dark, playful]);

  function showToast(msg, tone) {
    setToast({ msg, tone });
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(null), 2600);
  }

  function onAction(kind, id, driver) {
    setOrders((prev) => prev.map((o) => {
      if (o.id !== id) return o;
      const at = nowHM();
      const me = { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' };
      if (kind === 'assign') {
        return { ...o, status: 'assigned', driver, timeline: [...o.timeline, { s: 'assigned', at, by: me }] };
      }
      if (kind === 'fail') {
        return { ...o, status: 'failed', timeline: [...o.timeline, { s: 'failed', at, by: o.driver || me, note: { ar: 'أُغلق يدويًا', en: 'Closed manually' } }] };
      }
      if (kind === 'cancel') {
        return { ...o, status: 'cancelled', timeline: [...o.timeline, { s: 'cancelled', at, by: me }] };
      }
      return o;
    }));
    const msgs = {
      assign: { ar: 'تم إسناد السائق', en: 'Driver assigned' },
      fail: { ar: 'تم تعليم الطلب كفاشل', en: 'Order marked as failed' },
      cancel: { ar: 'تم إلغاء الطلب', en: 'Order cancelled' },
    };
    const tones = { assign: 'green', fail: 'amber', cancel: 'red' };
    showToast(msgs[kind], tones[kind]);
  }

  function onDriverAction(kind, id, payload) {
    const d = drivers.find((x) => x.id === id);
    if (!d) return;
    const T = {
      call:       { ar: 'جارٍ الاتصال بالسائق…', en: 'Calling driver…', tone: 'slate' },
      message:    { ar: 'فُتحت محادثة مع السائق', en: 'Opened chat with driver', tone: 'slate' },
      approve:    { ar: 'تمت الموافقة على السائق', en: 'Driver approved', tone: 'green' },
      reject:     { ar: 'تم رفض الطلب', en: 'Application rejected', tone: 'red' },
      suspend:    { ar: 'تم إيقاف السائق', en: 'Driver suspended', tone: 'amber' },
      reactivate: { ar: 'تمت إعادة التفعيل', en: 'Driver reactivated', tone: 'green' },
      ban:        { ar: 'تم حظر السائق', en: 'Driver banned', tone: 'red' },
      reinstate:  { ar: 'تم رفع الحظر', en: 'Ban lifted', tone: 'green' },
      offline:    { ar: 'تم فصل السائق', en: 'Driver forced offline', tone: 'slate' },
      adjust:     { ar: 'فُتح التعديل اليدوي', en: 'Manual adjustment opened', tone: 'slate' },
      settle:     { ar: 'تمت تسوية النقد', en: 'Cash settled', tone: 'green' },
      payout:     { ar: 'تم صرف الأرباح', en: 'Earnings paid out', tone: 'green' },
      strike_add: { ar: 'سُجّلت المخالفة', en: 'Strike issued', tone: 'red' },
      strike_void:{ ar: 'أُلغيت المخالفة', en: 'Strike voided', tone: 'slate' },
      doc_verify: { ar: 'تم توثيق المستند', en: 'Document verified', tone: 'green' },
      assign_order:{ ar: 'تم إسناد الطلب للسائق', en: 'Order assigned to driver', tone: 'green' },
    };

    // order assignment also mutates the orders pipe
    if (kind === 'assign_order') {
      setOrders((prev) => prev.map((o) => o.id === payload
        ? { ...o, status: 'assigned', driver: d.name, timeline: [...o.timeline, { s: 'assigned', at: nowHM(), by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } }] }
        : o));
    }

    setDrivers((prev) => prev.map((x) => {
      if (x.id !== id) return x;
      const n = { ...x };
      switch (kind) {
        case 'approve': n.profileStatus = 'active'; n.accountStatus = 'active'; break;
        case 'reject': n.profileStatus = 'rejected'; n.activity = 'offline'; break;
        case 'suspend': n.profileStatus = 'suspended'; n.activity = 'offline'; break;
        case 'reactivate': n.profileStatus = 'active'; if (n.accountStatus === 'suspended_unpaid_fees' || n.accountStatus === 'suspended') n.accountStatus = 'active'; break;
        case 'ban': n.profileStatus = 'banned'; n.accountStatus = 'banned'; n.activity = 'offline'; break;
        case 'reinstate': n.profileStatus = 'active'; n.accountStatus = 'active'; break;
        case 'offline': n.activity = 'offline'; break;
        case 'assign_order': if (n.activity !== 'on_order') n.activity = 'on_order'; break;
        case 'settle': {
          const amt = n.account.cash;
          n.account = { ...n.account, cash: 0, lifeCash: n.account.lifeCash + amt };
          n.ledger = [{ bucket: 'cash_to_deposit', amount: -amt, reason: 'settlement', after: 0, ago: 'now', by: 'admin' }, ...n.ledger];
          break;
        }
        case 'payout': {
          const amt = n.account.earnings;
          n.account = { ...n.account, earnings: 0, lifeEarnings: n.account.lifeEarnings + 0 };
          n.ledger = [{ bucket: 'earnings_balance', amount: -amt, reason: 'payout', after: 0, ago: 'now', by: 'admin' }, ...n.ledger];
          break;
        }
        case 'doc_verify': n.docs = { ...n.docs, [payload]: { ...(n.docs[payload] || {}), v: true } }; break;
        case 'strike_void': n.strikes = n.strikes.map((s, i) => i === payload ? { ...s, voided: true } : s); break;
        case 'strike_add': {
          const fee = +payload.fee || 0;
          n.strikes = [{ reason: payload.reason, order: null, fee, by: 'admin', voided: false, daysAgo: 0 }, ...n.strikes];
          if (fee > 0) {
            n.account = { ...n.account, debt: n.account.debt + fee };
            n.ledger = [{ bucket: 'debt_balance', amount: fee, reason: 'strike_fee', after: n.account.debt, ago: 'now', by: 'admin' }, ...n.ledger];
          }
          break;
        }
        default: break;
      }
      return n;
    }));

    const m = T[kind];
    if (m) showToast({ ar: m.ar, en: m.en }, m.tone);
  }

  function onCreateDriver(f) {
    const id = 'DRV-' + Math.random().toString(36).slice(2, 6).toUpperCase();
    // identity + linked user: either an existing user, or a freshly-created one
    let userId, name, phone, email, accountStatus;
    if (f.mode === 'existing') {
      const u = users.find((x) => x.id === f.userId);
      userId = u.id; name = u.name; phone = u.phone; email = u.email; accountStatus = u.accountStatus;
      setUsers((prev) => prev.map((x) => x.id === u.id ? { ...x, driverId: id, roles: ['customer', 'driver'], moderation: [{ action: 'promote', scope: 'driver', reason: { ar: 'ترقية إلى سائق', en: 'Promoted to driver' }, by: 'admin', ago: { ar: 'الآن', en: 'now' } }, ...x.moderation] } : x));
    } else {
      userId = 'USR-' + Math.random().toString(36).slice(2, 6).toUpperCase();
      name = { ar: `${f.first} ${f.last}`, en: `${f.first} ${f.last}` };
      phone = f.phone; email = f.email; accountStatus = 'pending_verification';
      setUsers((prev) => [{ id: userId, name, phone, email, accountStatus, joined: '2026-06', driverId: id, orders: 0,
        roles: ['customer', 'driver'], phoneVerified: false, emailVerified: false, locale: 'ar',
        notif: { push: true, sms: false, email: !!email }, moderation: [] }, ...prev]);
    }
    const rec = {
      id, userId, name, phone, email,
      activity: 'offline', profileStatus: 'pending_approval', accountStatus,
      vehicle: f.vehicle, plate: f.plate, vehicleColor: { ar: f.color, en: f.color }, vehicleModel: f.model, office: f.office,
      regions: [], rating: 5.0, lifetimeDeliveries: 0, deliveriesToday: 0, joined: '2026-06', lastActive: { ar: '—', en: '—' },
      account: { cash: 0, earnings: 0, debt: 0, ceiling: 100, lifeEarnings: 0, lifeCash: 0, lifeFees: 0 },
      docs: Object.keys(DOC_TYPES).reduce((acc, k) => { acc[k] = { v: false }; return acc; }, {}),
      strikes: [], ledger: [],
    };
    setDrivers((prev) => [rec, ...prev]);
    setAddDriver(false);
    showToast(f.mode === 'existing'
      ? { ar: 'تم ربط ملف سائق بالمستخدم — بانتظار الموافقة', en: 'Driver profile linked to user — pending approval' }
      : { ar: 'أُضيف السائق — بانتظار الموافقة', en: 'Driver added — pending approval' }, 'green');
  }

  function onUserAction(kind, id) {
    const T = {
      suspend:    { ar: 'تم إيقاف الحساب', en: 'Account suspended', tone: 'amber', reason: { ar: 'إجراء إداري', en: 'Manual (admin)' } },
      reactivate: { ar: 'تمت إعادة التفعيل', en: 'Account reactivated', tone: 'green', reason: { ar: 'مراجعة إدارية', en: 'Admin review' } },
      ban:        { ar: 'تم حظر الحساب', en: 'Account banned', tone: 'red', reason: { ar: 'إجراء إداري', en: 'Manual (admin)' } },
      reinstate:  { ar: 'تم رفع الحظر', en: 'Ban lifted', tone: 'green', reason: { ar: 'مراجعة إدارية', en: 'Admin review' } },
    };
    const statusMap = { suspend: 'suspended', reactivate: 'active', ban: 'banned', reinstate: 'active' };
    const m = T[kind];
    setUsers((prev) => prev.map((x) => {
      if (x.id !== id) return x;
      const entry = { action: kind === 'reactivate' && x.accountStatus === 'pending_verification' ? 'verify' : kind, scope: 'account', reason: m.reason, by: 'admin', ago: { ar: 'الآن', en: 'now' } };
      return { ...x, accountStatus: statusMap[kind], moderation: [entry, ...x.moderation] };
    }));
    // keep a linked driver's account flag in sync (profile status stays admin-controlled separately)
    setDrivers((prev) => prev.map((d) => {
      const u = users.find((x) => x.id === id);
      return u && d.id === u.driverId ? { ...d, accountStatus: statusMap[kind] } : d;
    }));
    if (m) showToast({ ar: m.ar, en: m.en }, m.tone);
  }

  function onMerchantAction(kind, id) {
    const T = {
      suspend:    { ar: 'تم إيقاف التاجر', en: 'Merchant suspended', tone: 'amber', reason: { ar: 'إجراء إداري', en: 'Manual (admin)' } },
      reactivate: { ar: 'تمت إعادة تفعيل التاجر', en: 'Merchant reactivated', tone: 'green', reason: { ar: 'مراجعة إدارية', en: 'Admin review' } },
      ban:        { ar: 'تم حظر التاجر نهائيًا — أُزيل الدور', en: 'Merchant banned — role removed', tone: 'red', reason: { ar: 'حظر نهائي', en: 'Terminal ban' } },
    };
    const statusMap = { suspend: 'suspended', reactivate: 'active', ban: 'banned' };
    const m = T[kind];
    const mer = merchants.find((x) => x.id === id);
    setMerchants((prev) => prev.map((x) => x.id === id ? { ...x, status: statusMap[kind] } : x));
    // ban is terminal: strip the 'merchant' role from the owner. log to owner moderation.
    setUsers((prev) => prev.map((u) => {
      if (!mer || u.id !== mer.ownerUserId) return u;
      const entry = { action: kind, scope: 'merchant', reason: m.reason, by: 'admin', ago: { ar: 'الآن', en: 'now' } };
      const roles = kind === 'ban' ? u.roles.filter((r) => r !== 'merchant') : (u.roles.includes('merchant') ? u.roles : [...u.roles, 'merchant']);
      return { ...u, roles, moderation: [entry, ...u.moderation] };
    }));
    if (m) showToast({ ar: m.ar, en: m.en }, m.tone);
  }

  function onMerchantSubmit(p) {
    if (p.editingId) {
      setMerchants((prev) => prev.map((x) => x.id === p.editingId
        ? { ...x, business: p.business, businessPhone: p.businessPhone, commissionOverride: p.commissionOverride, driverFeeCutOverride: p.driverFeeCutOverride, pickup: p.pickup, pickupDist: p.pickupDist, notes: p.notes }
        : x));
      setMerchantFormState(null);
      showToast({ ar: 'تم حفظ تغييرات التاجر', en: 'Merchant changes saved' }, 'green');
      return;
    }
    const id = 'MER-' + Math.random().toString(36).slice(2, 6).toUpperCase();
    const rec = { id, business: p.business, businessPhone: p.businessPhone, ownerUserId: p.ownerUserId,
      status: 'active', commissionOverride: p.commissionOverride, driverFeeCutOverride: p.driverFeeCutOverride,
      pickup: p.pickup, pickupDist: p.pickupDist, notes: p.notes, created: '2026-06' };
    setMerchants((prev) => [rec, ...prev]);
    setUsers((prev) => prev.map((u) => u.id === p.ownerUserId
      ? { ...u, merchantId: id, roles: u.roles.includes('merchant') ? u.roles : [...u.roles, 'merchant'],
          moderation: [{ action: 'onboard', scope: 'merchant', reason: { ar: 'تسجيل تاجر بدعوة', en: 'Invite onboarding' }, by: 'admin', ago: { ar: 'الآن', en: 'now' } }, ...u.moderation] }
      : u));
    setMerchantFormState(null);
    showToast({ ar: 'أُنشئ التاجر — نشط مباشرةً', en: 'Merchant created — active immediately' }, 'green');
  }

  // ── Settlements ──
  function nowStamp() { return '2026-06-16 ' + nowHM(); }
  function linkedPendingFor(d) {
    return earnings.filter((e) => {
      if (e.status !== 'pending_settlement') return false;
      const o = ORDERS.find((x) => x.id === e.orderId);
      return o && o.driver && tt(o.driver, 'en') === tt(d.name, 'en');
    });
  }
  function onSettle(driverId, info) {
    const d = drivers.find((x) => x.id === driverId);
    if (!d) return null;
    const before = { cash: d.account.cash, earnings: d.account.earnings, debt: d.account.debt };
    const linked = linkedPendingFor(d);
    const linkedIds = linked.map((e) => e.id);
    const linkedTotal = linked.reduce((sum, e) => sum + e.amount, 0);
    const id = 'STL-' + Math.floor(3100 + Math.random() * 800);
    const rec = { id, driverId, office: d.office, staff: STAFF, processedAt: nowStamp(),
      before, net: info.net, direction: info.direction,
      cashReceived: info.cashReceived,
      cashReceivedFromDriver: info.direction === 'driver_to_office' ? info.cashReceived : 0,
      cashPaidToDriver: info.direction === 'office_to_driver' ? Math.abs(info.net) : 0,
      shortage: info.shortage,
      status: 'processed', linkedEarnings: linkedIds, linkedEarningsTotal: linkedTotal };
    // atomic: clear buckets; shortage becomes the new debt
    setDrivers((prev) => prev.map((x) => x.id === driverId ? { ...x, account: { ...x.account, cash: 0, earnings: 0, debt: info.shortage || 0 } } : x));
    // linked seller earnings: pending_settlement → pending_clearance
    if (linkedIds.length) setEarnings((prev) => prev.map((e) => linkedIds.includes(e.id) ? { ...e, status: 'pending_clearance', settlementId: id, clearAt: '2026-06-18 ' + nowHM() } : e));
    setSettlements((prev) => [rec, ...prev]);
    showToast({ ar: 'تمّت تسوية السائق', en: 'Driver settlement processed' }, 'green');
    return rec;
  }
  function onPayout(sellerId, earningIds, total) {
    const id = 'PYT-' + Math.floor(4100 + Math.random() * 800);
    const office = settleScope === 'all' ? 'of-01' : settleScope;
    const rec = { id, sellerUserId: sellerId, office, staff: STAFF, paidAt: nowStamp(), method: 'cash_at_office', earnings: earningIds, total };
    setEarnings((prev) => prev.map((e) => earningIds.includes(e.id) ? { ...e, status: 'paid_out', payoutId: id } : e));
    setPayouts((prev) => [rec, ...prev]);
    showToast({ ar: 'تمّ صرف أرباح البائع نقدًا', en: 'Seller earnings paid out in cash' }, 'green');
    return rec;
  }
  function onReverse(settlementId) {
    const stl = settlements.find((s) => s.id === settlementId);
    if (!stl) return;
    const correctingId = 'STL-' + Math.floor(3900 + Math.random() * 90);
    const correcting = { id: correctingId, driverId: stl.driverId, office: stl.office, staff: STAFF, processedAt: nowStamp(),
      status: 'correcting', reversalOf: stl.id, net: -stl.net, direction: stl.direction === 'driver_to_office' ? 'office_to_driver' : 'driver_to_office', restored: stl.before, linkedEarnings: [] };
    // restore driver buckets to the pre-settlement snapshot
    setDrivers((prev) => prev.map((x) => x.id === stl.driverId && stl.before ? { ...x, account: { ...x.account, cash: stl.before.cash, earnings: stl.before.earnings, debt: stl.before.debt } } : x));
    // linked earnings back to pending_settlement
    const linkedIds = stl.linkedEarnings || [];
    if (linkedIds.length) setEarnings((prev) => prev.map((e) => linkedIds.includes(e.id) ? { ...e, status: 'pending_settlement', settlementId: undefined, clearAt: undefined } : e));
    setSettlements((prev) => [correcting, ...prev.map((s) => s.id === settlementId ? { ...s, status: 'cancelled', correctingId } : s)]);
    showToast({ ar: 'تمّ عكس التسوية واستعادة الأرصدة', en: 'Settlement reversed — buckets restored' }, 'amber');
  }

  // ── Staff ──
  function genTempPw() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let p = '';
    for (let i = 0; i < 10; i++) p += chars[Math.floor(Math.random() * chars.length)];
    return p.slice(0, 5) + '-' + p.slice(5);
  }
  function onStaffAction(kind, id) {
    const s = staffList.find((x) => x.id === id);
    if (!s) return;
    const guarded = s.id === CURRENT_STAFF_ID || isLastActiveAdmin(s, staffList);
    if ((kind === 'suspend' || kind === 'deactivate') && guarded) {
      showToast({ ar: 'إجراء محمي — لا يمكن تنفيذه', en: 'Protected account — action blocked' }, 'red');
      return;
    }
    if (kind === 'reset_pw' && s.id === CURRENT_STAFF_ID) {
      showToast({ ar: 'لا يمكنك إعادة تعيين كلمة مرور حسابك من هنا', en: 'You cannot reset your own password here' }, 'red');
      return;
    }
    if (kind === 'reset_pw') {
      const pw = genTempPw();
      setStaffList((prev) => prev.map((x) => x.id === id ? { ...x, mustChangePassword: true, updatedAt: NOW_DATE } : x));
      setTempPw({ name: staffName(s, lang), password: pw, reset: true });
      return;
    }
    const T = {
      suspend:    { st: 'suspended', msg: { ar: 'تم إيقاف الموظف', en: 'Staff suspended' }, tone: 'amber' },
      reinstate:  { st: 'active', msg: { ar: 'تمت إعادة التفعيل', en: 'Staff reinstated' }, tone: 'green' },
      deactivate: { st: 'suspended', msg: { ar: 'تم تعطيل الحساب وإزالة المهام', en: 'Account deactivated, assignments removed' }, tone: 'slate' },
    };
    const t = T[kind];
    setStaffList((prev) => prev.map((x) => {
      if (x.id !== id) return x;
      const n = { ...x, updatedAt: NOW_DATE };
      if (kind === 'deactivate') { n.deactivated = true; n.accountStatus = 'suspended'; n.assignments = x.assignments.map((a) => a.removedAt ? a : { ...a, removedAt: NOW_DATE }); }
      else if (kind === 'reinstate') { n.deactivated = false; n.accountStatus = 'active'; }
      else { n.accountStatus = t.st; }
      return n;
    }));
    showToast(t.msg, t.tone);
  }
  function onAddAssignment(id, officeId) {
    setStaffList((prev) => prev.map((x) => x.id === id
      ? { ...x, updatedAt: NOW_DATE, assignments: [...x.assignments, { id: 'OA-' + Math.floor(300 + Math.random() * 600), office: officeId, is_manager: false, assignedAt: NOW_DATE, removedAt: null }] }
      : x));
    showToast({ ar: 'تمت إضافة المكتب', en: 'Office assigned' }, 'green');
  }
  function onRemoveAssignment(id, assignmentId) {
    const s = staffList.find((x) => x.id === id);
    if (!s || activeAssignments(s).length <= 1) { showToast({ ar: 'لا يمكن إزالة آخر مكتب', en: 'Cannot remove the last office' }, 'red'); return; }
    setStaffList((prev) => prev.map((x) => x.id === id
      ? { ...x, updatedAt: NOW_DATE, assignments: x.assignments.map((a) => a.id === assignmentId ? { ...a, removedAt: NOW_DATE } : a) }
      : x));
    showToast({ ar: 'تمت إزالة المكتب', en: 'Office removed' }, 'slate');
  }
  function onCreateStaff(p) {
    const id = 'STF-' + Math.floor(1100 + Math.random() * 800);
    const pw = genTempPw();
    const rec = { id, firstName: { ar: p.first, en: p.first }, lastName: { ar: p.last, en: p.last }, phone: p.phone, email: p.email || null,
      role: p.role, accountStatus: 'active', mustChangePassword: true, phoneVerified: false, emailVerified: false,
      assignments: (p.assignments || []).map((a, i) => ({ id: 'OA-' + Math.floor(300 + Math.random() * 600) + i, office: a.office, is_manager: a.is_manager, assignedAt: NOW_DATE, removedAt: null })),
      createdAt: NOW_DATE, updatedAt: NOW_DATE };
    setStaffList((prev) => [rec, ...prev]);
    setAddStaff(false);
    setTempPw({ name: `${p.first} ${p.last}`, password: pw, reset: false });
  }

  const counts = { pending: orders.filter((o) => o.status === 'pending').length };
  const outerBg = floating ? 'bg-slate-100' : 'bg-slate-50';

  let content;
  if (page === 'overview') content = <Overview lang={lang} setPage={setPage} dark={dark} playful={playful} selectedOffice={selectedOffice} setSelectedOffice={setSelectedOffice} />;
  else if (page === 'orders') content = <Orders lang={lang} orders={orders} onOpen={setOpenId} openId={openId} />;
  else if (page === 'drivers') content = <Drivers lang={lang} drivers={drivers} onOpen={setOpenDriver} openId={openDriver} onAdd={() => { setPresetUser(null); setAddDriver(true); }} />;
  else if (page === 'users') content = <Users lang={lang} users={users} onOpen={setOpenUser} openId={openUser} />;
  else if (page === 'merchants') content = <Merchants lang={lang} merchants={merchants} users={users} onOpen={setOpenMerchant} openId={openMerchant} onAdd={() => setMerchantFormState('new')} />;
  else if (page === 'settlements') content = <Settlements lang={lang} drivers={drivers} users={users} earnings={earnings} settlements={settlements} payouts={payouts}
    scope={settleScope} setScope={setSettleScope} staffMode={staffMode} setStaffMode={setStaffMode}
    onSettle={setSettleDriverId} onPayout={onPayout} onReverse={onReverse} onOpenOrder={setOpenId} />;
  else if (page === 'finance') content = <Finance lang={lang} settlements={settlements} payouts={payouts} range={financeRange} setRange={setFinanceRange} onOpenOrder={setOpenId} />;
  else if (page === 'settings') content = <Settings lang={lang} settings={settings} setSettings={setSettings}
    dirty={JSON.stringify(settings) !== JSON.stringify(savedSettings)}
    onSave={() => { setSavedSettings(settings); showToast({ ar: 'حُفظت إعدادات المنصّة — تسري على التسعير الجديد', en: 'Platform settings saved — applies to new quotes' }, 'green'); }}
    onReset={() => setSettings(savedSettings)} />;
  else if (page === 'staff') content = <Staff lang={lang} staff={staffList} onOpen={setOpenStaff} openId={openStaff} onAdd={() => setAddStaff(true)} />;
  else content = <StubPage page={page} lang={lang} />;

  return (
    <div className={`app-bg flex h-screen overflow-hidden ${outerBg}`}>
      <Sidebar floating={floating} collapsed={collapsed} setCollapsed={setCollapsed}
        page={page} setPage={(p) => { setPage(p); setOpenId(null); setOpenDriver(null); setOpenUser(null); setOpenMerchant(null); setSettleDriverId(null); setOpenStaff(null); }} lang={lang} counts={counts} />
      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar page={page} lang={lang} setLang={(l) => setTweak('lang', l)}
          onLogout={() => showToast({ ar: 'تم تسجيل الخروج', en: 'Logged out' }, 'slate')} />
        <main className="flex-1 overflow-y-auto">{content}</main>
      </div>

      <DriverModal driverId={openDriver} drivers={drivers} lang={lang} onClose={() => setOpenDriver(null)} onAction={onDriverAction} onOpenOrder={setOpenId} />
      <UserModal userId={openUser} users={users} lang={lang} onClose={() => setOpenUser(null)} onAction={onUserAction} onOpenOrder={setOpenId}
        onOpenDriver={(did) => { setOpenUser(null); setOpenDriver(did); }}
        onOpenMerchant={(mid) => { setOpenUser(null); setOpenMerchant(mid); }}
        onPromote={(uid) => { const u = users.find((x) => x.id === uid); setOpenUser(null); setPresetUser(u); setAddDriver(true); }} />
      <MerchantModal merchantId={openMerchant} merchants={merchants} users={users} lang={lang} onClose={() => setOpenMerchant(null)}
        onAction={onMerchantAction} onEdit={(mid) => { setOpenMerchant(null); setMerchantFormState(mid); }}
        onOpenOrder={setOpenId} onOpenOwner={(uid) => { setOpenMerchant(null); setOpenUser(uid); }} />
      {merchantFormState && <MerchantForm lang={lang} users={users} editing={merchantFormState !== 'new' ? merchants.find((x) => x.id === merchantFormState) : null} onClose={() => setMerchantFormState(null)} onSubmit={onMerchantSubmit} />}
      <DriverSettleModal driverId={settleDriverId} drivers={drivers} lang={lang}
        office={settleDriverId ? (drivers.find((x) => x.id === settleDriverId) || {}).office : null} staff={STAFF}
        pendingLinkedList={settleDriverId ? linkedPendingFor(drivers.find((x) => x.id === settleDriverId) || { name: { en: '' } }) : []}
        onClose={() => setSettleDriverId(null)} onSettle={onSettle} />
      <StaffModal staffId={openStaff} staff={staffList} lang={lang} settlements={settlements} payouts={payouts}
        onClose={() => setOpenStaff(null)} onAction={onStaffAction} onAddAssignment={onAddAssignment} onRemoveAssignment={onRemoveAssignment} />
      {addStaff && <StaffForm lang={lang} onClose={() => setAddStaff(false)} onCreate={onCreateStaff} />}
      {tempPw && <TempPasswordModal data={tempPw} lang={lang} onClose={() => setTempPw(null)} />}
      {addDriver && <AddDriverModal lang={lang} users={users} presetUser={presetUser} onClose={() => { setAddDriver(false); setPresetUser(null); }} onCreate={onCreateDriver} />}
      <OrderDrawer orderId={openId} orders={orders} lang={lang} onClose={() => setOpenId(null)} onAction={onAction} />
      <Toast toast={toast} lang={lang} />

      <TweaksPanel>
        <TweakSection label={lang === 'ar' ? 'المظهر' : 'Appearance'} />
        <TweakRadio label={lang === 'ar' ? 'الوضع' : 'Theme'} value={t.theme}
          options={['Light', 'Dark']} onChange={(v) => setTweak('theme', v)} />
        <TweakRadio label={lang === 'ar' ? 'الطابع' : 'Style'} value={t.skin}
          options={['Playful', 'Clean']} onChange={(v) => setTweak('skin', v)} />
        <TweakRadio label={lang === 'ar' ? 'الشريط الجانبي' : 'Sidebar'} value={t.shell}
          options={['Standard', 'Floating']} onChange={(v) => setTweak('shell', v)} />
        <TweakSection label={lang === 'ar' ? 'الهوية' : 'Brand'} />
        <TweakColor label={lang === 'ar' ? 'اللون المميز' : 'Accent'} value={t.accent}
          options={['#2563eb', '#4f46e5', '#0d9488', '#7c3aed', '#ea580c']} onChange={(v) => setTweak('accent', v)} />
        <TweakSection label={lang === 'ar' ? 'العرض' : 'Display'} />
        <TweakRadio label={lang === 'ar' ? 'الكثافة' : 'Density'} value={t.density}
          options={['Comfortable', 'Dense']} onChange={(v) => setTweak('density', v)} />
        <TweakRadio label={lang === 'ar' ? 'اللغة' : 'Language'} value={t.lang === 'en' ? 'EN' : 'AR'}
          options={['AR', 'EN']} onChange={(v) => setTweak('lang', v === 'EN' ? 'en' : 'ar')} />
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
