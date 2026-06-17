// data.jsx — fictional bilingual ops data for the Tripoli delivery dashboard.
// All user-facing strings are {ar, en}. Use tt(value, lang) to resolve.

function tt(v, lang) {
  if (v == null) return '';
  if (typeof v === 'object' && ('ar' in v || 'en' in v)) return v[lang] || v.en || v.ar;
  return v;
}

// Latin (Western) digits -> Eastern Arabic digits for RTL number display.
const _arDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
function num(n, lang) {
  const s = String(n);
  if (lang !== 'ar') return s;
  return s.replace(/[0-9]/g, (d) => _arDigits[+d]);
}

// ---- Offices (map markers) ----
const OFFICES = [
  { id: 'of-01', name: { ar: 'المكتب الرئيسي — وسط المدينة', en: 'HQ — City Center' }, district: { ar: 'وسط المدينة', en: 'City Center' }, x: 516, y: 196, hq: true, staff: 14 },
  { id: 'of-02', name: { ar: 'فرع قرقارش', en: 'Gergaresh Branch' }, district: { ar: 'قرقارش', en: 'Gergaresh' }, x: 236, y: 168, staff: 6 },
  { id: 'of-03', name: { ar: 'فرع سوق الجمعة', en: 'Souq al-Juma Branch' }, district: { ar: 'سوق الجمعة', en: 'Souq al-Juma' }, x: 772, y: 214, staff: 7 },
  { id: 'of-04', name: { ar: 'فرع عين زارة', en: 'Ain Zara Branch' }, district: { ar: 'عين زارة', en: 'Ain Zara' }, x: 612, y: 470, staff: 5 },
];

// ---- District zones (subtle map blobs + labels) ----
const DISTRICTS = [
  { id: 'd1', name: { ar: 'قرقارش', en: 'Gergaresh' }, x: 214, y: 226, r: 96 },
  { id: 'd2', name: { ar: 'حي الأندلس', en: 'Hay Alandalus' }, x: 360, y: 286, r: 104 },
  { id: 'd3', name: { ar: 'الظهرة', en: 'Al-Dahra' }, x: 470, y: 232, r: 80 },
  { id: 'd4', name: { ar: 'بن عاشور', en: 'Ben Ashour' }, x: 558, y: 312, r: 96 },
  { id: 'd5', name: { ar: 'سوق الجمعة', en: 'Souq al-Juma' }, x: 786, y: 286, r: 110 },
  { id: 'd6', name: { ar: 'أبو سليم', en: 'Abu Salim' }, x: 432, y: 410, r: 104 },
  { id: 'd7', name: { ar: 'عين زارة', en: 'Ain Zara' }, x: 636, y: 472, r: 116 },
  { id: 'd8', name: { ar: 'حي دمشق', en: 'Hay Damascus' }, x: 678, y: 360, r: 84 },
];

// ---- Live drivers (animated dots; pathId points at a road path in the map) ----
const DRIVERS = [
  { id: 'dr-01', name: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, status: 'moving', path: 'road-a', dur: 26, phone: '091 442 1180', orders: 2, vehicle: { ar: 'دراجة نارية', en: 'Motorbike' } },
  { id: 'dr-02', name: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, status: 'moving', path: 'road-b', dur: 32, phone: '092 301 7744', orders: 1, vehicle: { ar: 'دراجة نارية', en: 'Motorbike' } },
  { id: 'dr-03', name: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, status: 'moving', path: 'road-c', dur: 29, phone: '094 778 2210', orders: 3, vehicle: { ar: 'سيارة', en: 'Car' } },
  { id: 'dr-04', name: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, status: 'moving', path: 'road-d', dur: 35, phone: '091 660 9012', orders: 1, vehicle: { ar: 'دراجة نارية', en: 'Motorbike' } },
  { id: 'dr-05', name: { ar: 'عبدالله الترهوني', en: 'Abdullah Al-Tarhouni' }, status: 'idle', x: 516, y: 196, phone: '092 118 5567', orders: 0, vehicle: { ar: 'سيارة', en: 'Car' } },
  { id: 'dr-06', name: { ar: 'منذر القماطي', en: 'Munther Al-Gammati' }, status: 'idle', x: 772, y: 214, phone: '094 220 3389', orders: 0, vehicle: { ar: 'دراجة نارية', en: 'Motorbike' } },
];

// ---- Status metadata ----
const STATUS = {
  pending:    { ar: 'قيد الانتظار', en: 'Pending',    tone: 'amber' },
  assigned:   { ar: 'مُسند',        en: 'Assigned',   tone: 'blue' },
  in_transit: { ar: 'في الطريق',    en: 'In transit', tone: 'violet' },
  delivered:  { ar: 'تم التسليم',   en: 'Delivered',  tone: 'green' },
  failed:     { ar: 'فشل التسليم',  en: 'Failed',     tone: 'red' },
  cancelled:  { ar: 'ملغى',         en: 'Cancelled',  tone: 'slate' },
};

const TYPES = {
  standard: { ar: 'عادي',      en: 'Standard' },
  p2p:      { ar: 'طرف لطرف',  en: 'P2P' },
  merchant: { ar: 'تاجر',      en: 'Merchant' },
};

// ---- Orders ----
function mkTimeline(steps) { return steps; }

const ORDERS = [
  {
    id: 'TRP-24890', type: 'merchant', status: 'delivered',
    sender: { ar: 'متجر إلكترونيات طرابلس', en: 'Tripoli Electronics' }, senderDist: { ar: 'وسط المدينة', en: 'City Center' },
    receiver: { ar: 'عمر الدرسي', en: 'Omar Al-Darsi' }, receiverDist: { ar: 'قرقارش', en: 'Gergaresh' },
    driver: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, created: '2026-06-15 11:40', cod: 320,
    price: { base: 12, distance: 8, cod: 0, surge: 0, total: 20 },
    timeline: [
      { s: 'pending', at: '11:40', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '11:46', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '12:02', by: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' } },
      { s: 'delivered', at: '12:31', by: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' } },
    ],
  },
  {
    id: 'TRP-24888', type: 'merchant', status: 'in_transit',
    sender: { ar: 'مطعم الواحة', en: 'Al-Waha Restaurant' }, senderDist: { ar: 'قرقارش', en: 'Gergaresh' },
    receiver: { ar: 'مريم الشريف', en: 'Mariam Al-Sharif' }, receiverDist: { ar: 'عين زارة', en: 'Ain Zara' },
    driver: { ar: 'منذر القماطي', en: 'Munther Al-Gammati' }, created: '2026-06-15 12:55', cod: 64,
    price: { base: 12, distance: 5, cod: 0, surge: 2, total: 19 },
    timeline: [
      { s: 'pending', at: '12:55', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '13:01', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '13:14', by: { ar: 'منذر القماطي', en: 'Munther Al-Gammati' } },
    ],
  },
  {
    id: 'TRP-24885', type: 'merchant', status: 'delivered',
    sender: { ar: 'مطعم الواحة', en: 'Al-Waha Restaurant' }, senderDist: { ar: 'قرقارش', en: 'Gergaresh' },
    receiver: { ar: 'نورا المقري', en: 'Noura Al-Maghri' }, receiverDist: { ar: 'بن عاشور', en: 'Ben Ashour' },
    driver: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, created: '2026-06-14 19:20', cod: 0,
    price: { base: 12, distance: 7, cod: 0, surge: 0, total: 19 },
    timeline: [
      { s: 'pending', at: '19:20', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '19:25', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '19:38', by: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' } },
      { s: 'delivered', at: '20:02', by: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' } },
    ],
  },
  {
    id: 'TRP-24882', type: 'merchant', status: 'delivered',
    sender: { ar: 'مخبز الرشيد', en: 'Al-Rashid Bakery' }, senderDist: { ar: 'الظهرة', en: 'Al-Dahra' },
    receiver: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, receiverDist: { ar: 'أبو سليم', en: 'Abu Salim' },
    driver: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, created: '2026-06-13 08:10', cod: 28,
    price: { base: 12, distance: 4, cod: 0, surge: 0, total: 16 },
    timeline: [
      { s: 'pending', at: '08:10', by: { ar: 'النظام', en: 'System' } },
      { s: 'delivered', at: '08:52', by: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' } },
    ],
  },
  {
    id: 'TRP-24881', type: 'merchant', status: 'in_transit',
    sender: { ar: 'صيدلية النهضة', en: 'Al-Nahda Pharmacy' }, senderDist: { ar: 'بن عاشور', en: 'Ben Ashour' },
    receiver: { ar: 'فاطمة الزوي', en: 'Fatima Al-Zwai' }, receiverDist: { ar: 'حي الأندلس', en: 'Hay Alandalus' },
    driver: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, created: '2026-06-15 09:12', cod: 85,
    price: { base: 12, distance: 6, cod: 0, surge: 0, total: 18 },
    timeline: [
      { s: 'pending', at: '09:12', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '09:18', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '09:34', by: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' } },
    ],
  },
  {
    id: 'TRP-24879', type: 'standard', status: 'pending',
    sender: { ar: 'أحمد المبروك', en: 'Ahmed Al-Mabrouk' }, senderDist: { ar: 'سوق الجمعة', en: 'Souq al-Juma' },
    receiver: { ar: 'مريم الشريف', en: 'Mariam Al-Sharif' }, receiverDist: { ar: 'عين زارة', en: 'Ain Zara' },
    driver: null, created: '2026-06-15 09:05', cod: 0,
    price: { base: 12, distance: 9, cod: 0, surge: 3, total: 24 },
    timeline: [ { s: 'pending', at: '09:05', by: { ar: 'النظام', en: 'System' } } ],
  },
  {
    id: 'TRP-24876', type: 'p2p', status: 'assigned',
    sender: { ar: 'خديجة بن نصر', en: 'Khadija Ben Nasr' }, senderDist: { ar: 'قرقارش', en: 'Gergaresh' },
    receiver: { ar: 'عبدالسلام الفقيه', en: 'Abdulsalam Al-Faqih' }, receiverDist: { ar: 'حي دمشق', en: 'Hay Damascus' },
    driver: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, created: '2026-06-15 08:51', cod: 40,
    price: { base: 12, distance: 7, cod: 0, surge: 0, total: 19 },
    timeline: [
      { s: 'pending', at: '08:51', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '08:58', by: { ar: 'محمد (مكتب قرقارش)', en: 'Mohamed (Gergaresh)' } },
    ],
  },
  {
    id: 'TRP-24870', type: 'standard', status: 'delivered',
    sender: { ar: 'مخبز الرشيد', en: 'Al-Rashid Bakery' }, senderDist: { ar: 'الظهرة', en: 'Al-Dahra' },
    receiver: { ar: 'نورا المقري', en: 'Noura Al-Maghri' }, receiverDist: { ar: 'بن عاشور', en: 'Ben Ashour' },
    driver: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, created: '2026-06-15 08:20', cod: 30,
    price: { base: 12, distance: 4, cod: 0, surge: 0, total: 16 },
    timeline: [
      { s: 'pending', at: '08:20', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '08:24', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '08:31', by: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' } },
      { s: 'delivered', at: '08:49', by: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' } },
    ],
  },
  {
    id: 'TRP-24865', type: 'merchant', status: 'delivered',
    sender: { ar: 'متجر إلكترونيات طرابلس', en: 'Tripoli Electronics' }, senderDist: { ar: 'وسط المدينة', en: 'City Center' },
    receiver: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, receiverDist: { ar: 'أبو سليم', en: 'Abu Salim' },
    driver: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, created: '2026-06-15 07:58', cod: 220,
    price: { base: 12, distance: 8, cod: 0, surge: 0, total: 20 },
    timeline: [
      { s: 'pending', at: '07:58', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '08:02', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
      { s: 'in_transit', at: '08:10', by: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' } },
      { s: 'delivered', at: '08:41', by: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' } },
    ],
  },
  {
    id: 'TRP-24858', type: 'standard', status: 'failed',
    sender: { ar: 'سوبر ماركت المدينة', en: 'Al-Madina Supermarket' }, senderDist: { ar: 'حي الأندلس', en: 'Hay Alandalus' },
    receiver: { ar: 'زينب العماري', en: 'Zeinab Al-Ammari' }, receiverDist: { ar: 'تاجوراء', en: 'Tajura' },
    driver: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, created: '2026-06-15 07:40', cod: 65,
    price: { base: 12, distance: 11, cod: 0, surge: 0, total: 23 },
    timeline: [
      { s: 'pending', at: '07:40', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '07:46', by: { ar: 'محمد (مكتب قرقارش)', en: 'Mohamed (Gergaresh)' } },
      { s: 'in_transit', at: '07:55', by: { ar: 'سالم بالخير', en: 'Salem Belkhair' } },
      { s: 'failed', at: '08:30', by: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, note: { ar: 'المستلم لم يرد', en: 'Recipient unreachable' } },
    ],
  },
  {
    id: 'TRP-24851', type: 'p2p', status: 'cancelled',
    sender: { ar: 'محمد العريبي', en: 'Mohamed Al-Areibi' }, senderDist: { ar: 'عين زارة', en: 'Ain Zara' },
    receiver: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, receiverDist: { ar: 'سوق الجمعة', en: 'Souq al-Juma' },
    driver: null, created: '2026-06-15 07:22', cod: 0,
    price: { base: 12, distance: 10, cod: 0, surge: 0, total: 22 },
    timeline: [
      { s: 'pending', at: '07:22', by: { ar: 'النظام', en: 'System' } },
      { s: 'cancelled', at: '07:29', by: { ar: 'المرسل', en: 'Sender' }, note: { ar: 'تغيّرت الخطة', en: 'Changed plans' } },
    ],
  },
  {
    id: 'TRP-24847', type: 'standard', status: 'in_transit',
    sender: { ar: 'مطعم الواحة', en: 'Al-Waha Restaurant' }, senderDist: { ar: 'قرقارش', en: 'Gergaresh' },
    receiver: { ar: 'سعاد المنتصر', en: 'Souad Al-Muntasir' }, receiverDist: { ar: 'حي الأندلس', en: 'Hay Alandalus' },
    driver: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, created: '2026-06-15 07:05', cod: 48,
    price: { base: 12, distance: 5, cod: 0, surge: 0, total: 17 },
    timeline: [
      { s: 'pending', at: '07:05', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '07:11', by: { ar: 'محمد (مكتب قرقارش)', en: 'Mohamed (Gergaresh)' } },
      { s: 'in_transit', at: '07:20', by: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' } },
    ],
  },
  {
    id: 'TRP-24840', type: 'merchant', status: 'delivered',
    sender: { ar: 'صيدلية النهضة', en: 'Al-Nahda Pharmacy' }, senderDist: { ar: 'بن عاشور', en: 'Ben Ashour' },
    receiver: { ar: 'عمر الدرسي', en: 'Omar Al-Darsi' }, receiverDist: { ar: 'الظهرة', en: 'Al-Dahra' },
    driver: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, created: '2026-06-14 19:48', cod: 0,
    price: { base: 12, distance: 3, cod: 0, surge: 0, total: 15 },
    timeline: [
      { s: 'pending', at: '19:48', by: { ar: 'النظام', en: 'System' } },
      { s: 'delivered', at: '20:22', by: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' } },
    ],
  },
  {
    id: 'TRP-24833', type: 'standard', status: 'assigned',
    sender: { ar: 'هالة بن عمران', en: 'Hala Ben Omran' }, senderDist: { ar: 'حي دمشق', en: 'Hay Damascus' },
    receiver: { ar: 'طارق الصيد', en: 'Tarek Al-Sayed' }, receiverDist: { ar: 'أبو سليم', en: 'Abu Salim' },
    driver: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, created: '2026-06-14 18:30', cod: 55,
    price: { base: 12, distance: 6, cod: 0, surge: 0, total: 18 },
    timeline: [
      { s: 'pending', at: '18:30', by: { ar: 'النظام', en: 'System' } },
      { s: 'assigned', at: '18:37', by: { ar: 'سارة (مكتب وسط المدينة)', en: 'Sara (City Center)' } },
    ],
  },
];

// ---- Stat cards (Overview) ----
const STATS = [
  { id: 'active', label: { ar: 'الطلبات النشطة', en: 'Active Orders' }, value: 38, delta: 12, dir: 'up' },
  { id: 'drivers', label: { ar: 'السائقون المتصلون', en: 'Online Drivers' }, value: 21, delta: 3, dir: 'up' },
  { id: 'today', label: { ar: 'تسليمات اليوم', en: 'Deliveries Today' }, value: 164, delta: 8, dir: 'up' },
  { id: 'settle', label: { ar: 'تسويات معلّقة', en: 'Pending Settlements' }, value: 7, delta: 2, dir: 'down', money: true },
];

// ---- Recent activity ----
const ACTIVITY = [
  { id: 'a1', kind: 'delivered', order: 'TRP-24870', text: { ar: 'تم تسليم الطلب', en: 'Order delivered' }, who: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, ago: { ar: 'قبل ٤ د', en: '4m ago' } },
  { id: 'a2', kind: 'assigned', order: 'TRP-24876', text: { ar: 'أُسند الطلب لسائق', en: 'Order assigned' }, who: { ar: 'محمد — مكتب قرقارش', en: 'Mohamed — Gergaresh' }, ago: { ar: 'قبل ٧ د', en: '7m ago' } },
  { id: 'a3', kind: 'failed', order: 'TRP-24858', text: { ar: 'فشل التسليم', en: 'Delivery failed' }, who: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, ago: { ar: 'قبل ١٢ د', en: '12m ago' } },
  { id: 'a4', kind: 'pending', order: 'TRP-24879', text: { ar: 'طلب جديد بانتظار الإسناد', en: 'New order awaiting assignment' }, who: { ar: 'النظام', en: 'System' }, ago: { ar: 'قبل ١٤ د', en: '14m ago' } },
  { id: 'a5', kind: 'driver', order: null, text: { ar: 'سائق اتصل بالخدمة', en: 'Driver came online' }, who: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, ago: { ar: 'قبل ٢١ د', en: '21m ago' } },
];

// ============================================================================
//  DRIVERS — full records, mirroring the real backend (driver_profiles + users
//  + driver_accounts + driver_documents + driver_strikes).
//  Modeling note from backend: driver_id everywhere = users.id. Here each record
//  flattens the 1:1 user↔profile↔account into one object for the admin screens.
// ============================================================================

// driver_profiles.status (the driver-side lifecycle the admin acts on)
const LIFECYCLE = {
  active:           { ar: 'نشط',                 en: 'Active',            tone: 'green'  },
  approved:         { ar: 'معتمد',               en: 'Approved',          tone: 'green'  },
  pre_registered:   { ar: 'تسجيل أولي',          en: 'Pre-registered',    tone: 'slate'  },
  pending_approval: { ar: 'بانتظار الموافقة',    en: 'Pending approval',  tone: 'amber'  },
  suspended:        { ar: 'موقوف',               en: 'Suspended',         tone: 'red'    },
  suspended_unpaid: { ar: 'موقوف — رسوم متأخرة', en: 'Suspended · unpaid',tone: 'red'    },
  banned:           { ar: 'محظور',               en: 'Banned',            tone: 'red'    },
  rejected:         { ar: 'مرفوض',               en: 'Rejected',          tone: 'slate'  },
};

// users.account_status (the user-side account state — distinct from the driver profile)
const ACCOUNT_STATUS = {
  active:                { ar: 'فعّال',                en: 'Active',            tone: 'green' },
  pending_verification:  { ar: 'بانتظار التحقق',       en: 'Pending verification', tone: 'amber' },
  suspended:             { ar: 'موقوف',                en: 'Suspended',         tone: 'red'   },
  suspended_unpaid_fees: { ar: 'موقوف — رسوم متأخرة',  en: 'Suspended · unpaid fees', tone: 'red' },
  banned:                { ar: 'محظور',                en: 'Banned',            tone: 'red'   },
};

// merchant_profiles.status — independent from the owner's account status.
// Invite-only, created directly active. Ban is terminal (removes merchant role).
const MERCHANT_STATUS = {
  active:    { ar: 'نشط',    en: 'Active',    tone: 'green' },
  suspended: { ar: 'موقوف',  en: 'Suspended', tone: 'amber' },
  banned:    { ar: 'محظور',  en: 'Banned',    tone: 'red'   },
};

// platform_settings — backend-controlled config (source of truth for pricing,
// payouts, settlement, risk). The UI reads these for NEW quotes; historical
// orders keep their own snapshot. Rates are fractions (0.15 = 15%).
const PLATFORM_SETTINGS = {
  pricing: {
    item_commission_rate: 0.15,   // commission on item_price (sale orders)
    driver_fee_cut_rate:  0.02,   // platform's cut taken from the delivery fee
    delivery_fee_base:    12,     // base delivery fee (LYD)
    free_km:              3,      // km included before per-km charge
    per_km_rate:          1,      // charge per extra km (LYD)
  },
  payouts: {
    clearance_hours: 48,          // seller/merchant earning clearance delay
    min_amount:      20,          // minimum seller/merchant payout (LYD)
    allow_partial:   true,        // partial payout of selected earnings
  },
  settlement: {
    reverse_window_hours: 24,     // optional reversal time cap
  },
  risk: {
    new_driver_max_liability: 100, // max cash a new driver may hold (LYD)
  },
};
// Back-compat shims for existing call sites.
const PLATFORM_RATES = { commission: PLATFORM_SETTINGS.pricing.item_commission_rate, driverFeeCut: PLATFORM_SETTINGS.pricing.driver_fee_cut_rate };
const CLEARANCE_HOURS = PLATFORM_SETTINGS.payouts.clearance_hours;
const PAYOUT_MIN = PLATFORM_SETTINGS.payouts.min_amount;

// driver_profiles.activity_status — the live presence state
const PRESENCE = { // distinct from the page's recent-activity feed (ACTIVITY array)
  online:   { ar: 'متصل',     en: 'Online',    tone: 'green',  dot: '#16a34a' },
  on_order: { ar: 'في مهمة',  en: 'On order',  tone: 'violet', dot: '#7c3aed' },
  offline:  { ar: 'غير متصل', en: 'Offline',   tone: 'slate',  dot: '#94a3b8' },
};

const VEHICLES = {
  motorcycle: { ar: 'دراجة نارية', en: 'Motorcycle', icon: 'bike' },
  car:        { ar: 'سيارة',       en: 'Car',        icon: 'car'  },
};

// driver_documents.document_type
const DOC_TYPES = {
  national_id_front:    { ar: 'الهوية (أمامي)',       en: 'National ID — front' },
  national_id_back:     { ar: 'الهوية (خلفي)',        en: 'National ID — back'  },
  drivers_license:      { ar: 'رخصة القيادة',         en: "Driver's licence", expires: true },
  vehicle_registration: { ar: 'استمارة المركبة',      en: 'Vehicle registration' },
  selfie:               { ar: 'صورة شخصية',           en: 'Selfie' },
  vehicle_photo_front:  { ar: 'صورة المركبة (أمام)',  en: 'Vehicle photo — front' },
  vehicle_photo_back:   { ar: 'صورة المركبة (خلف)',   en: 'Vehicle photo — back'  },
  insurance:            { ar: 'التأمين',              en: 'Insurance', expires: true },
};

// driver_strikes.reason
const STRIKE_REASONS = {
  accept_then_cancel:  { ar: 'قبول ثم إلغاء',        en: 'Accepted then cancelled' },
  no_show_at_pickup:   { ar: 'تخلّف عن الاستلام',     en: 'No-show at pickup' },
  no_show_at_delivery: { ar: 'تخلّف عن التسليم',      en: 'No-show at delivery' },
  abandoned_order:     { ar: 'ترك الطلب',             en: 'Abandoned order' },
  repeated_lateness:   { ar: 'تأخّر متكرر',           en: 'Repeated lateness' },
  customer_complaint:  { ar: 'شكوى عميل',             en: 'Customer complaint' },
  manual_admin:        { ar: 'إجراء إداري',           en: 'Manual (admin)' },
};

// driver_account_transactions.reason
const LEDGER_REASONS = {
  order_completed:     { ar: 'إتمام طلب',         en: 'Order completed' },
  settlement:          { ar: 'تسوية نقدية',        en: 'Cash settlement' },
  payout:              { ar: 'صرف أرباح',          en: 'Earnings payout' },
  cancellation_fee:    { ar: 'رسوم إلغاء',         en: 'Cancellation fee' },
  settlement_shortage: { ar: 'عجز في التسوية',     en: 'Settlement shortage' },
  settlement_excess:   { ar: 'فائض في التسوية',    en: 'Settlement excess' },
  debt_offset:         { ar: 'مقاصّة دين',         en: 'Debt offset' },
  debt_payment:        { ar: 'سداد دين',           en: 'Debt payment' },
  strike_fee:          { ar: 'رسوم مخالفة',        en: 'Strike fee' },
  manual_adjustment:   { ar: 'تعديل يدوي',         en: 'Manual adjustment' },
};

const BUCKETS = {
  cash_to_deposit:  { ar: 'نقد بحوزة السائق', en: 'Cash held by driver', short: { ar: 'نقد بحوزة السائق', en: 'Cash held' } },
  earnings_balance: { ar: 'حصة السائق من التوصيل', en: 'Driver delivery share', short: { ar: 'حصة السائق', en: 'Driver share' } },
  debt_balance:     { ar: 'دين السائق',     en: 'Driver debt',           short: { ar: 'دين السائق', en: 'Driver debt' } },
};

// Mask a phone for display (Rule 12): keep leading 3 + trailing 2 digits.
function maskPhone(p) {
  const digits = String(p).replace(/\D/g, '');
  if (digits.length < 6) return p;
  const head = digits.slice(0, 3), tail = digits.slice(-2);
  return `${head} ••• ••${tail}`;
}

// Live active loads for a driver = orders assigned to them still in flight.
function driverLoads(driver) {
  const en = tt(driver.name, 'en');
  return ORDERS.filter((o) => o.driver && tt(o.driver, 'en') === en && (o.status === 'assigned' || o.status === 'in_transit'));
}

// Active strikes = not voided AND within rolling 30 days (backend rule).
function activeStrikes(driver) {
  return (driver.strikes || []).filter((s) => !s.voided && s.daysAgo <= 30);
}

// Finance helpers derived from driver_accounts (computed, not columns).
function acct(d) {
  const a = d.account;
  const remaining = Math.max(0, a.ceiling - a.cash);
  const pct = a.ceiling ? Math.min(100, Math.round((a.cash / a.ceiling) * 100)) : 0;
  return {
    ...a,
    net: a.earnings - a.debt,                       // net_position
    settlementNet: (a.cash + a.debt) - a.earnings,  // settlement_net
    remaining, pct,
    atCeiling: a.cash >= a.ceiling,
  };
}

// helper to keep records terse
function L(bucket, amount, reason, after, ago, by) { return { bucket, amount, reason, after, ago, by: by || 'system' }; }

const DRIVER_RECORDS = [
  {
    id: 'DRV-7H2K', name: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, phone: '091 442 1180',
    email: 'y.fituri@drivers.tawseel.ly', userId: 'USR-1001', activity: 'on_order', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'motorcycle', plate: 'TR 4821', vehicleColor: { ar: 'أحمر', en: 'Red' }, vehicleModel: 'Honda CG 125',
    office: 'of-01', regions: [{ ar: 'بن عاشور', en: 'Ben Ashour' }, { ar: 'الظهرة', en: 'Al-Dahra' }],
    rating: 4.8, lifetimeDeliveries: 1342, deliveriesToday: 11, joined: '2024-03', lastActive: { ar: 'الآن', en: 'now' },
    account: { cash: 240, earnings: 86, debt: 0, ceiling: 100, lifeEarnings: 9180, lifeCash: 41200, lifeFees: 2140 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2027-05' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: true, exp: '2026-11' } },
    strikes: [],
    ledger: [
      L('cash_to_deposit', 85, 'order_completed', 240, '12m', 'system'),
      L('earnings_balance', 18, 'order_completed', 86, '12m', 'system'),
      L('cash_to_deposit', 65, 'order_completed', 155, '54m', 'system'),
      L('earnings_balance', 16, 'order_completed', 68, '54m', 'system'),
      L('cash_to_deposit', -180, 'settlement', 90, '4h', 'admin'),
    ],
  },
  {
    id: 'DRV-3M9P', name: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, phone: '092 301 7744',
    email: 's.belkhair@drivers.tawseel.ly', userId: 'USR-1002', activity: 'on_order', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'motorcycle', plate: 'TR 1190', vehicleColor: { ar: 'أزرق', en: 'Blue' }, vehicleModel: 'Yamaha YBR',
    office: 'of-02', regions: [{ ar: 'قرقارش', en: 'Gergaresh' }, { ar: 'حي الأندلس', en: 'Hay Alandalus' }],
    rating: 4.5, lifetimeDeliveries: 876, deliveriesToday: 8, joined: '2024-07', lastActive: { ar: 'قبل ٣ د', en: '3m ago' },
    account: { cash: 92, earnings: 54, debt: 0, ceiling: 100, lifeEarnings: 6020, lifeCash: 28800, lifeFees: 1480 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2026-09' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: false }, insurance: { v: true, exp: '2026-07' } },
    strikes: [
      { reason: 'no_show_at_delivery', order: 'TRP-24858', fee: 10, by: 'system', voided: false, daysAgo: 5 },
    ],
    ledger: [
      L('cash_to_deposit', 65, 'order_completed', 92, '22m', 'system'),
      L('earnings_balance', 14, 'order_completed', 54, '22m', 'system'),
      L('cash_to_deposit', -10, 'strike_fee', 27, '5d', 'system'),
      L('debt_balance', 10, 'strike_fee', 10, '5d', 'system'),
    ],
  },
  {
    id: 'DRV-K48Q', name: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, phone: '094 778 2210',
    email: 'i.warfalli@drivers.tawseel.ly', userId: 'USR-1003', activity: 'on_order', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'car', plate: 'TR 7702', vehicleColor: { ar: 'أبيض', en: 'White' }, vehicleModel: 'Toyota Corolla',
    office: 'of-03', regions: [{ ar: 'سوق الجمعة', en: 'Souq al-Juma' }, { ar: 'الظهرة', en: 'Al-Dahra' }],
    rating: 4.9, lifetimeDeliveries: 2105, deliveriesToday: 14, joined: '2023-11', lastActive: { ar: 'الآن', en: 'now' },
    account: { cash: 60, earnings: 132, debt: 0, ceiling: 150, lifeEarnings: 14700, lifeCash: 63400, lifeFees: 3380 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2028-01' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: true, exp: '2027-02' } },
    strikes: [],
    ledger: [
      L('earnings_balance', 22, 'order_completed', 132, '8m', 'system'),
      L('cash_to_deposit', 60, 'order_completed', 60, '8m', 'system'),
      L('earnings_balance', -300, 'payout', 110, '2d', 'admin'),
    ],
  },
  {
    id: 'DRV-2X6R', name: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, phone: '091 660 9012',
    email: 'k.zarrouk@drivers.tawseel.ly', userId: 'USR-1004', activity: 'on_order', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'motorcycle', plate: 'TR 3345', vehicleColor: { ar: 'أسود', en: 'Black' }, vehicleModel: 'Honda CG 125',
    office: 'of-01', regions: [{ ar: 'أبو سليم', en: 'Abu Salim' }, { ar: 'حي الأندلس', en: 'Hay Alandalus' }],
    rating: 4.6, lifetimeDeliveries: 1018, deliveriesToday: 9, joined: '2024-05', lastActive: { ar: 'قبل د', en: '1m ago' },
    account: { cash: 130, earnings: 40, debt: 25, ceiling: 100, lifeEarnings: 7240, lifeCash: 33100, lifeFees: 1690 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2026-12' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: false, exp: '2026-06' } },
    strikes: [
      { reason: 'repeated_lateness', order: null, fee: 5, by: 'admin', voided: false, daysAgo: 12 },
      { reason: 'customer_complaint', order: 'TRP-24847', fee: 0, by: 'admin', voided: true, daysAgo: 40 },
    ],
    ledger: [
      L('cash_to_deposit', 48, 'order_completed', 130, '30m', 'system'),
      L('debt_balance', 25, 'cancellation_fee', 25, '1d', 'system'),
      L('cash_to_deposit', -5, 'strike_fee', 82, '12d', 'admin'),
    ],
  },
  {
    id: 'DRV-9D1T', name: { ar: 'عبدالله الترهوني', en: 'Abdullah Al-Tarhouni' }, phone: '092 118 5567',
    email: 'a.tarhouni@drivers.tawseel.ly', userId: 'USR-1005', activity: 'online', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'car', plate: 'TR 5567', vehicleColor: { ar: 'فضي', en: 'Silver' }, vehicleModel: 'Hyundai Accent',
    office: 'of-01', regions: [{ ar: 'وسط المدينة', en: 'City Center' }],
    rating: 4.7, lifetimeDeliveries: 1530, deliveriesToday: 6, joined: '2024-01', lastActive: { ar: 'قبل ٢ د', en: '2m ago' },
    account: { cash: 0, earnings: 95, debt: 0, ceiling: 100, lifeEarnings: 10400, lifeCash: 45900, lifeFees: 2310 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2027-08' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: true, exp: '2026-10' } },
    strikes: [],
    ledger: [
      L('earnings_balance', 17, 'order_completed', 95, '40m', 'system'),
      L('cash_to_deposit', -120, 'settlement', 0, '3h', 'admin'),
    ],
  },
  {
    id: 'DRV-6B8L', name: { ar: 'منذر القماطي', en: 'Munther Al-Gammati' }, phone: '094 220 3389',
    email: 'm.gammati@drivers.tawseel.ly', userId: 'USR-1006', activity: 'online', profileStatus: 'active', accountStatus: 'active',
    vehicle: 'motorcycle', plate: 'TR 2203', vehicleColor: { ar: 'أخضر', en: 'Green' }, vehicleModel: 'Yamaha YBR',
    office: 'of-03', regions: [{ ar: 'سوق الجمعة', en: 'Souq al-Juma' }],
    rating: 4.2, lifetimeDeliveries: 412, deliveriesToday: 4, joined: '2025-02', lastActive: { ar: 'قبل ٦ د', en: '6m ago' },
    account: { cash: 35, earnings: 28, debt: 40, ceiling: 80, lifeEarnings: 2880, lifeCash: 12600, lifeFees: 760 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2026-07' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: false }, vehicle_photo_back: { v: false }, insurance: { v: false } },
    strikes: [
      { reason: 'accept_then_cancel', order: 'TRP-24851', fee: 8, by: 'system', voided: false, daysAgo: 2 },
      { reason: 'abandoned_order', order: null, fee: 15, by: 'system', voided: false, daysAgo: 18 },
    ],
    ledger: [
      L('cash_to_deposit', 22, 'order_completed', 35, '1h', 'system'),
      L('debt_balance', 15, 'strike_fee', 40, '18d', 'system'),
      L('debt_balance', 8, 'strike_fee', 25, '2d', 'system'),
    ],
  },
  {
    id: 'DRV-1F5W', name: { ar: 'طارق المسماري', en: 'Tarek Al-Mismari' }, phone: '091 905 6612',
    email: 't.mismari@drivers.tawseel.ly', userId: 'USR-1007', activity: 'offline', profileStatus: 'suspended', accountStatus: 'suspended_unpaid_fees',
    vehicle: 'motorcycle', plate: 'TR 9066', vehicleColor: { ar: 'أحمر', en: 'Red' }, vehicleModel: 'Honda CG 125',
    office: 'of-04', regions: [{ ar: 'عين زارة', en: 'Ain Zara' }],
    rating: 3.9, lifetimeDeliveries: 640, deliveriesToday: 0, joined: '2024-09', lastActive: { ar: 'قبل ٣ س', en: '3h ago' },
    account: { cash: 100, earnings: 12, debt: 60, ceiling: 100, lifeEarnings: 4100, lifeCash: 19800, lifeFees: 980 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2026-06' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: true, exp: '2026-08' } },
    strikes: [
      { reason: 'no_show_at_pickup', order: null, fee: 10, by: 'system', voided: false, daysAgo: 8 },
    ],
    ledger: [
      L('debt_balance', 60, 'settlement_shortage', 60, '1d', 'admin'),
      L('cash_to_deposit', 0, 'manual_adjustment', 100, '1d', 'admin'),
    ],
  },
  {
    id: 'DRV-4A0N', name: { ar: 'حمزة العبيدي', en: 'Hamza Al-Obeidi' }, phone: '092 744 8821',
    email: 'h.obeidi@drivers.tawseel.ly', userId: 'USR-1008', activity: 'offline', profileStatus: 'pending_approval', accountStatus: 'pending_verification',
    vehicle: 'car', plate: 'TR 0088', vehicleColor: { ar: 'رمادي', en: 'Grey' }, vehicleModel: 'Kia Rio',
    office: null, regions: [{ ar: 'وسط المدينة', en: 'City Center' }],
    rating: 5.0, lifetimeDeliveries: 0, deliveriesToday: 0, joined: '2026-06', lastActive: { ar: '—', en: '—' },
    account: { cash: 0, earnings: 0, debt: 0, ceiling: 100, lifeEarnings: 0, lifeCash: 0, lifeFees: 0 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: false, exp: '2027-03' }, vehicle_registration: { v: false }, selfie: { v: true }, vehicle_photo_front: { v: false }, vehicle_photo_back: { v: false }, insurance: { v: false } },
    strikes: [],
    ledger: [],
  },
  {
    id: 'DRV-8C3V', name: { ar: 'وليد الشريف', en: 'Walid Al-Sharif' }, phone: '094 511 2030',
    email: 'w.sharif@drivers.tawseel.ly', userId: 'USR-1009', activity: 'offline', profileStatus: 'banned', accountStatus: 'banned',
    vehicle: 'motorcycle', plate: 'TR 5120', vehicleColor: { ar: 'أسود', en: 'Black' }, vehicleModel: 'Yamaha YBR',
    office: 'of-02', regions: [{ ar: 'قرقارش', en: 'Gergaresh' }],
    rating: 2.8, lifetimeDeliveries: 210, deliveriesToday: 0, joined: '2025-01', lastActive: { ar: 'قبل ٩ ي', en: '9d ago' },
    account: { cash: 0, earnings: 0, debt: 140, ceiling: 100, lifeEarnings: 1500, lifeCash: 7200, lifeFees: 360 },
    docs: { national_id_front: { v: true }, national_id_back: { v: true }, drivers_license: { v: true, exp: '2026-04' }, vehicle_registration: { v: true }, selfie: { v: true }, vehicle_photo_front: { v: true }, vehicle_photo_back: { v: true }, insurance: { v: false } },
    strikes: [
      { reason: 'customer_complaint', order: null, fee: 0, by: 'admin', voided: false, daysAgo: 9 },
      { reason: 'manual_admin', order: null, fee: 0, by: 'admin', voided: false, daysAgo: 9 },
    ],
    ledger: [
      L('debt_balance', 140, 'settlement_shortage', 140, '9d', 'admin'),
    ],
  },
];

// ============================================================================
//  USERS — every person in the system. A driver is a USER who also has a
//  driver profile (driverId set). Users without driverId are customers only,
//  and are the pool the "add driver from existing user" flow starts from.
//  A driver keeps using the app as a normal user too (toggles role in-app).
// ============================================================================
const USERS = [
  // — people who are ALSO drivers (driverId links to DRIVER_RECORDS) —
  { id: 'USR-1001', name: { ar: 'يوسف الفيتوري', en: 'Youssef Al-Fituri' }, phone: '091 442 1180', email: 'y.fituri@drivers.tawseel.ly', accountStatus: 'active', joined: '2024-03', driverId: 'DRV-7H2K', orders: 3 },
  { id: 'USR-1002', name: { ar: 'سالم بالخير', en: 'Salem Belkhair' }, phone: '092 301 7744', email: 's.belkhair@drivers.tawseel.ly', accountStatus: 'active', joined: '2024-07', driverId: 'DRV-3M9P', orders: 1 },
  { id: 'USR-1003', name: { ar: 'إبراهيم الورفلي', en: 'Ibrahim Al-Warfalli' }, phone: '094 778 2210', email: 'i.warfalli@drivers.tawseel.ly', accountStatus: 'active', joined: '2023-11', driverId: 'DRV-K48Q', orders: 0 },
  { id: 'USR-1004', name: { ar: 'خالد الزروق', en: 'Khaled Al-Zarrouk' }, phone: '091 660 9012', email: 'k.zarrouk@drivers.tawseel.ly', accountStatus: 'active', joined: '2024-05', driverId: 'DRV-2X6R', orders: 1 },
  { id: 'USR-1005', name: { ar: 'عبدالله الترهوني', en: 'Abdullah Al-Tarhouni' }, phone: '092 118 5567', email: 'a.tarhouni@drivers.tawseel.ly', accountStatus: 'active', joined: '2024-01', driverId: 'DRV-9D1T', orders: 0 },
  { id: 'USR-1006', name: { ar: 'منذر القماطي', en: 'Munther Al-Gammati' }, phone: '094 220 3389', email: 'm.gammati@drivers.tawseel.ly', accountStatus: 'active', joined: '2025-02', driverId: 'DRV-6B8L', orders: 0 },
  { id: 'USR-1007', name: { ar: 'طارق المسماري', en: 'Tarek Al-Mismari' }, phone: '091 905 6612', email: 't.mismari@drivers.tawseel.ly', accountStatus: 'suspended_unpaid_fees', joined: '2024-09', driverId: 'DRV-1F5W', orders: 0 },
  { id: 'USR-1008', name: { ar: 'حمزة العبيدي', en: 'Hamza Al-Obeidi' }, phone: '092 744 8821', email: 'h.obeidi@drivers.tawseel.ly', accountStatus: 'pending_verification', joined: '2026-06', driverId: 'DRV-4A0N', orders: 0 },
  { id: 'USR-1009', name: { ar: 'وليد الشريف', en: 'Walid Al-Sharif' }, phone: '094 511 2030', email: 'w.sharif@drivers.tawseel.ly', accountStatus: 'banned', joined: '2025-01', driverId: 'DRV-8C3V', orders: 0 },
  // — customers only (no driver profile yet) — the promotable pool —
  { id: 'USR-2001', name: { ar: 'فاطمة الزوي', en: 'Fatima Al-Zwai' }, phone: '091 220 4471', email: 'f.zwai@mail.ly', accountStatus: 'active', joined: '2024-02', driverId: null, orders: 18 },
  { id: 'USR-2002', name: { ar: 'عمر الدرسي', en: 'Omar Al-Darsi' }, phone: '092 887 1290', email: 'o.darsi@mail.ly', accountStatus: 'active', joined: '2023-12', driverId: null, orders: 41 },
  { id: 'USR-2003', name: { ar: 'مريم الشريف', en: 'Mariam Al-Sharif' }, phone: '094 503 6628', email: 'm.sharif@mail.ly', accountStatus: 'active', joined: '2024-08', driverId: null, orders: 9 },
  { id: 'USR-2004', name: { ar: 'خديجة بن نصر', en: 'Khadija Ben Nasr' }, phone: '091 776 5512', email: 'k.bennasr@mail.ly', accountStatus: 'active', joined: '2025-03', driverId: null, orders: 6 },
  { id: 'USR-2005', name: { ar: 'طارق الصيد', en: 'Tarek Al-Sayed' }, phone: '092 119 7843', email: 't.sayed@mail.ly', accountStatus: 'active', joined: '2024-11', driverId: null, orders: 4 },
  { id: 'USR-2006', name: { ar: 'نورا المقري', en: 'Noura Al-Maghri' }, phone: '094 662 0091', email: 'n.maghri@mail.ly', accountStatus: 'active', joined: '2025-01', driverId: null, orders: 12 },
  { id: 'USR-2007', name: { ar: 'بشير العماري', en: 'Bashir Al-Ammari' }, phone: '091 304 8810', email: 'b.ammari@mail.ly', accountStatus: 'pending_verification', joined: '2026-05', driverId: null, orders: 0 },
  { id: 'USR-2008', name: { ar: 'سعاد المنتصر', en: 'Souad Al-Muntasir' }, phone: '092 551 2237', email: 's.muntasir@mail.ly', accountStatus: 'suspended', joined: '2024-06', driverId: null, orders: 2 },
  // — merchant owners (each has a 1:1 merchant_profile via merchantId) —
  { id: 'USR-3001', name: { ar: 'سمير النهضي', en: 'Samir Al-Nahdi' }, phone: '091 555 7820', email: 's.nahdi@nahda-pharma.ly', accountStatus: 'active', joined: '2024-04', driverId: null, merchantId: 'MER-7K2A', orders: 7 },
  { id: 'USR-3002', name: { ar: 'ليلى القاضي', en: 'Layla Al-Qadi' }, phone: '092 414 6601', email: 'l.qadi@tripolielec.ly', accountStatus: 'active', joined: '2023-09', driverId: null, merchantId: 'MER-3P8B', orders: 3 },
  { id: 'USR-3003', name: { ar: 'كريم الواحي', en: 'Karim Al-Wahi' }, phone: '094 332 9015', email: 'k.wahi@alwaha.ly', accountStatus: 'suspended', joined: '2024-10', driverId: null, merchantId: 'MER-9D4C', orders: 12 },
  { id: 'USR-3004', name: { ar: 'حسين الرشيد', en: 'Hussein Al-Rashid' }, phone: '091 870 2244', email: 'h.rashid@rashidbakery.ly', accountStatus: 'active', joined: '2025-01', driverId: null, merchantId: 'MER-2F6D', orders: 5 },
  { id: 'USR-3005', name: { ar: 'عادل المديني', en: 'Adel Al-Madini' }, phone: '092 109 5533', email: 'a.madini@madina-mkt.ly', accountStatus: 'active', joined: '2024-03', driverId: null, merchantId: 'MER-5H1E', orders: 9 },
];

// Moderation/audit log (admin_actions) — keyed by user id; only users with history.
const MODERATION = {
  'USR-1007': [
    { action: 'suspend', scope: 'account', reason: { ar: 'رسوم تسوية متأخرة', en: 'Overdue settlement fees' }, by: 'admin', ago: { ar: 'قبل يوم', en: '1d ago' } },
    { action: 'strike', scope: 'driver', reason: { ar: 'تخلّف عن الاستلام', en: 'No-show at pickup' }, by: 'system', ago: { ar: 'قبل ٨ أيام', en: '8d ago' } },
  ],
  'USR-1009': [
    { action: 'ban', scope: 'account', reason: { ar: 'شكاوى عملاء متكررة', en: 'Repeated customer complaints' }, by: 'admin', ago: { ar: 'قبل ٩ أيام', en: '9d ago' } },
    { action: 'suspend', scope: 'account', reason: { ar: 'تحقيق', en: 'Under investigation' }, by: 'admin', ago: { ar: 'قبل ١١ يوم', en: '11d ago' } },
  ],
  'USR-2008': [
    { action: 'suspend', scope: 'account', reason: { ar: 'نزاع دفع', en: 'Payment dispute' }, by: 'admin', ago: { ar: 'قبل ٣ أيام', en: '3d ago' } },
  ],
  'USR-3004': [
    { action: 'suspend', scope: 'merchant', reason: { ar: 'نزاع تسعير قيد المراجعة', en: 'Pricing dispute under review' }, by: 'admin', ago: { ar: 'قبل يومين', en: '2d ago' } },
  ],
  'USR-3005': [
    { action: 'ban', scope: 'merchant', reason: { ar: 'مخالفات متكررة لشروط الاستخدام', en: 'Repeated terms-of-service violations' }, by: 'admin', ago: { ar: 'قبل ٥ أيام', en: '5d ago' } },
    { action: 'suspend', scope: 'merchant', reason: { ar: 'بلاغات جودة', en: 'Quality complaints' }, by: 'admin', ago: { ar: 'قبل ٨ أيام', en: '8d ago' } },
  ],
};

const MOD_ACTIONS = {
  suspend:    { ar: 'إيقاف', en: 'Suspended', tone: 'amber', icon: 'pause' },
  reactivate: { ar: 'إعادة تفعيل', en: 'Reactivated', tone: 'green', icon: 'power' },
  ban:        { ar: 'حظر', en: 'Banned', tone: 'red', icon: 'ban' },
  reinstate:  { ar: 'رفع الحظر', en: 'Reinstated', tone: 'green', icon: 'undo' },
  verify:     { ar: 'تحقق', en: 'Verified', tone: 'green', icon: 'checkCircle' },
  strike:     { ar: 'مخالفة', en: 'Strike', tone: 'red', icon: 'alert' },
  promote:    { ar: 'ترقية إلى سائق', en: 'Promoted to driver', tone: 'slate', icon: 'drivers' },
  onboard:    { ar: 'إنشاء ملف تاجر', en: 'Merchant created', tone: 'green', icon: 'merchants' },
};

// ============================================================================
//  MERCHANTS — a merchant is an existing USER with a 1:1 merchant_profile.
//  Invite-only, created directly ACTIVE (no application / no pending / no
//  approve flow). merchant_profiles.status is INDEPENDENT of the owner's
//  users.account_status. Ban is terminal on the merchant axis and strips the
//  'merchant' role from the user. Rate overrides are nullable → platform default.
// ============================================================================
const MERCHANT_RECORDS = [
  {
    id: 'MER-7K2A', business: { ar: 'صيدلية النهضة', en: 'Al-Nahda Pharmacy' }, businessPhone: '021 444 1180',
    ownerUserId: 'USR-3001', status: 'active', commissionOverride: 0.12, driverFeeCutOverride: null,
    pickup: { ar: 'شارع بن عاشور، مقابل المصرف التجاري', en: 'Ben Ashour St, opposite Commercial Bank' }, pickupDist: { ar: 'بن عاشور', en: 'Ben Ashour' },
    notes: { ar: 'الاستلام من الباب الخلفي بعد السادسة مساءً. التبريد مطلوب لبعض الطلبات.', en: 'Rear-door pickup after 6 PM. Some orders need cold-chain handling.' }, created: '2024-04',
  },
  {
    id: 'MER-3P8B', business: { ar: 'متجر إلكترونيات طرابلس', en: 'Tripoli Electronics' }, businessPhone: '021 333 5567',
    ownerUserId: 'USR-3002', status: 'active', commissionOverride: null, driverFeeCutOverride: null,
    pickup: { ar: 'شارع عمر المختار، وسط المدينة', en: 'Omar Al-Mukhtar St, City Center' }, pickupDist: { ar: 'وسط المدينة', en: 'City Center' },
    notes: { ar: 'طلبات عالية القيمة — يلزم تأكيد الهوية عند التسليم.', en: 'High-value orders — ID confirmation required on delivery.' }, created: '2023-10',
  },
  {
    id: 'MER-9D4C', business: { ar: 'مطعم الواحة', en: 'Al-Waha Restaurant' }, businessPhone: '021 555 9015',
    ownerUserId: 'USR-3003', status: 'active', commissionOverride: 0.10, driverFeeCutOverride: 0.03,
    pickup: { ar: 'شارع قرقارش الرئيسي', en: 'Gergaresh Main Rd' }, pickupDist: { ar: 'قرقارش', en: 'Gergaresh' },
    notes: { ar: 'ذروة الطلبات ١٢–٢ ظهرًا و ٨–١٠ مساءً.', en: 'Order peaks 12–2 PM and 8–10 PM.' }, created: '2024-11',
  },
  {
    id: 'MER-2F6D', business: { ar: 'مخبز الرشيد', en: 'Al-Rashid Bakery' }, businessPhone: '021 870 2244',
    ownerUserId: 'USR-3004', status: 'suspended', commissionOverride: null, driverFeeCutOverride: null,
    pickup: { ar: 'شارع الظهرة', en: 'Al-Dahra St' }, pickupDist: { ar: 'الظهرة', en: 'Al-Dahra' },
    notes: { ar: 'موقوف مؤقتًا بسبب نزاع تسعير قيد المراجعة.', en: 'Temporarily suspended over a pricing dispute under review.' }, created: '2025-01',
  },
  {
    id: 'MER-5H1E', business: { ar: 'سوبر ماركت المدينة', en: 'Al-Madina Supermarket' }, businessPhone: '021 109 5533',
    ownerUserId: 'USR-3005', status: 'banned', commissionOverride: null, driverFeeCutOverride: null,
    pickup: { ar: 'حي الأندلس، بالقرب من الدوار', en: 'Hay Alandalus, near the roundabout' }, pickupDist: { ar: 'حي الأندلس', en: 'Hay Alandalus' },
    notes: { ar: 'محظور نهائيًا — مخالفات متكررة لشروط الاستخدام.', en: 'Permanently banned — repeated terms-of-service violations.' }, created: '2024-03',
  },
];

function merchantById(id) { return MERCHANT_RECORDS.find((m) => m.id === id) || null; }

// merchant_delivery orders for a merchant (matched by business name on the sender).
function merchantOrders(m) {
  const en = tt(m.business, 'en');
  return ORDERS.filter((o) => o.type === 'merchant' && tt(o.sender, 'en') === en);
}

// ============================================================================
//  SETTLEMENTS — cash-only operational reconciliation (today). Two halves:
//   1) Driver settlement: clears the 3 driver buckets atomically against office cash.
//   2) Seller payout: pays sellers/merchants their cleared seller_earnings in cash.
//  Plus admin reversal (only while linked earnings are still pending_clearance).
//  NOTE: cash-only is the CURRENT implementation, not a permanent assumption —
//  online payments / wallets / non-cash payouts may be added later.
// ============================================================================
// seller_earnings.status lifecycle
const EARNING_STATUS = {
  pending_settlement: { ar: 'بانتظار التسوية',  en: 'Pending settlement', tone: 'slate',  step: 0 },
  pending_clearance:  { ar: 'بانتظار المقاصّة',  en: 'Pending clearance',  tone: 'amber',  step: 1 },
  available:          { ar: 'متاح للصرف',        en: 'Available',          tone: 'green',  step: 2 },
  paid_out:           { ar: 'مدفوع',             en: 'Paid out',           tone: 'violet', step: 3 },
};
const EARNING_FLOW = ['pending_settlement', 'pending_clearance', 'available', 'paid_out'];

const SETTLEMENT_STATUS = {
  processed:  { ar: 'مُنفّذة',   en: 'Processed',  tone: 'green'  },
  cancelled:  { ar: 'ملغاة',     en: 'Cancelled',  tone: 'slate'  },
  correcting: { ar: 'تصحيحية',   en: 'Correcting', tone: 'violet' },
};

// Net = (cash_to_deposit + debt_balance) − earnings_balance.
function settleNet(b) { return (b.cash + b.debt) - b.earnings; }
function settleDirection(net) { return net > 0 ? 'driver_to_office' : net < 0 ? 'office_to_driver' : 'zero'; }
// Net label (avoids “platform owes driver” framing).
const DIRECTION_LABEL = {
  driver_to_office: { ar: 'صافٍ مستحق للمكتب', en: 'Net due to office' },
  office_to_driver: { ar: 'صافٍ مستحق للسائق', en: 'Net due to driver' },
  zero:             { ar: 'لا صافي — تتصفّى الأرصدة', en: 'No net — balances clear' },
};
// Verbose action description (the physical cash movement).
const DIRECTION_ACTION = {
  driver_to_office: { ar: 'السائق يسلّم النقد للمكتب', en: 'Driver remits cash to office' },
  office_to_driver: { ar: 'المكتب يدفع النقد للسائق', en: 'Office pays cash to driver' },
  zero:             { ar: 'لا تبادل نقدي', en: 'No cash changes hands' },
};

// Business label helper for a seller user (their merchant business, else their name).
function sellerLabel(userId) {
  const u = userById(userId);
  if (!u) return { ar: '—', en: '—' };
  if (u.merchantId) { const m = merchantById(u.merchantId); if (m) return m.business; }
  return u.name;
}

const SELLER_EARNINGS = [
  { id: 'ERN-5012', sellerUserId: 'USR-3002', orderId: 'TRP-24865', amount: 520, status: 'available',          deliveredAt: '2026-06-12', availableAt: '2026-06-14' },
  { id: 'ERN-5031', sellerUserId: 'USR-3002', orderId: 'TRP-24905', amount: 75,  status: 'available',          deliveredAt: '2026-06-13', availableAt: '2026-06-15' },
  { id: 'ERN-5018', sellerUserId: 'USR-3001', orderId: 'TRP-24840', amount: 140, status: 'available',          deliveredAt: '2026-06-13', availableAt: '2026-06-15', settlementId: 'STL-3002' },
  { id: 'ERN-5021', sellerUserId: 'USR-3002', orderId: 'TRP-24890', amount: 300, status: 'pending_clearance',  deliveredAt: '2026-06-15', clearAt: '2026-06-17 12:31', settlementId: 'STL-3007' },
  { id: 'ERN-5024', sellerUserId: 'USR-3003', orderId: 'TRP-24885', amount: 95,  status: 'pending_clearance',  deliveredAt: '2026-06-14', clearAt: '2026-06-16 20:02', settlementId: 'STL-3007' },
  { id: 'ERN-5030', sellerUserId: 'USR-3004', orderId: 'TRP-24882', amount: 48,  status: 'pending_settlement', deliveredAt: '2026-06-13' },
  { id: 'ERN-5009', sellerUserId: 'USR-3001', orderId: 'TRP-24812', amount: 210, status: 'paid_out',           deliveredAt: '2026-06-08', payoutId: 'PYT-4002' },
  { id: 'ERN-4998', sellerUserId: 'USR-3003', orderId: 'TRP-24790', amount: 64,  status: 'paid_out',           deliveredAt: '2026-06-05', payoutId: 'PYT-4001' },
];

const SETTLEMENTS = [
  { id: 'STL-3007', driverId: 'DRV-K48Q', office: 'of-03', staff: { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }, processedAt: '2026-06-15 13:10',
    before: { cash: 480, earnings: 132, debt: 0 }, net: 348, direction: 'driver_to_office', cashReceived: 348, shortage: 0,
    status: 'processed', linkedEarnings: ['ERN-5021', 'ERN-5024'] },
  { id: 'STL-3002', driverId: 'DRV-9D1T', office: 'of-01', staff: { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }, processedAt: '2026-06-14 18:40',
    before: { cash: 120, earnings: 95, debt: 0 }, net: 25, direction: 'driver_to_office', cashReceived: 25, shortage: 0,
    status: 'processed', linkedEarnings: ['ERN-5018'] },
  { id: 'STL-2995', driverId: 'DRV-2X6R', office: 'of-01', staff: { ar: 'عمر القاضي', en: 'Omar Al-Qadi' }, processedAt: '2026-06-13 17:05',
    before: { cash: 60, earnings: 130, debt: 0 }, net: -70, direction: 'office_to_driver', cashReceived: 0, shortage: 0,
    status: 'processed', linkedEarnings: [] },
  { id: 'STL-2980', driverId: 'DRV-6B8L', office: 'of-03', staff: { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }, processedAt: '2026-06-12 11:00',
    before: { cash: 90, earnings: 28, debt: 0 }, net: 62, direction: 'driver_to_office', cashReceived: 55, shortage: 7,
    status: 'cancelled', linkedEarnings: [], correctingId: 'STL-2981' },
  { id: 'STL-2981', driverId: 'DRV-6B8L', office: 'of-03', staff: { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }, processedAt: '2026-06-12 11:20',
    status: 'correcting', reversalOf: 'STL-2980', net: -62, direction: 'office_to_driver', restored: { cash: 90, earnings: 28, debt: 0 }, linkedEarnings: [] },
];

const PAYOUTS = [
  { id: 'PYT-4002', sellerUserId: 'USR-3001', office: 'of-01', staff: { ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }, paidAt: '2026-06-10 14:20', method: 'cash_at_office', earnings: ['ERN-5009'], total: 210 },
  { id: 'PYT-4001', sellerUserId: 'USR-3003', office: 'of-03', staff: { ar: 'عمر القاضي', en: 'Omar Al-Qadi' }, paidAt: '2026-06-06 16:00', method: 'cash_at_office', earnings: ['ERN-4998'], total: 64 },
];

// Drivers with anything to settle (any non-zero bucket).
function settlementQueue() {
  return DRIVER_RECORDS.filter((d) => { const a = d.account; return a.cash > 0 || a.earnings > 0 || a.debt > 0; });
}
function earningsForSeller(userId, earnings) { return (earnings || SELLER_EARNINGS).filter((e) => e.sellerUserId === userId); }
function settlementById(id, list) { return (list || SETTLEMENTS).find((s) => s.id === id) || null; }

// ── Revenue snapshotting ───────────────────────────────────────────────────
// Each order carries a frozen snapshot of the rates/amounts at quote time, so
// Finance reports historical revenue from the snapshot — NOT live settings.
// commission_amount = commission_rate × item_price (item_price ≈ COD for sale
// orders); driver_fee_cut_amount = driver_fee_cut_rate × delivery_fee.
// Platform revenue (accrued) per order = commission_amount + driver_fee_cut_amount.
function orderMerchant(o) {
  if (o.type !== 'merchant') return null;
  const en = tt(o.sender, 'en');
  const u = USERS.find((x) => x.merchantId && tt(merchantById(x.merchantId).business, 'en') === en);
  return u ? merchantById(u.merchantId) : MERCHANT_RECORDS.find((m) => tt(m.business, 'en') === en) || null;
}
ORDERS.forEach((o) => {
  const mer = orderMerchant(o);
  const commissionRate = o.type === 'merchant' ? (mer && mer.commissionOverride != null ? mer.commissionOverride : PLATFORM_SETTINGS.pricing.item_commission_rate) : 0;
  const feeCutRate = mer && mer.driverFeeCutOverride != null ? mer.driverFeeCutOverride : PLATFORM_SETTINGS.pricing.driver_fee_cut_rate;
  const itemPrice = o.type === 'merchant' ? (o.cod || 0) : 0;   // commission base = item value (COD proxy)
  const deliveryFee = o.price ? o.price.total : 0;
  const commissionAmount = Math.round(commissionRate * itemPrice * 100) / 100;
  const driverFeeCutAmount = Math.round(feeCutRate * deliveryFee * 100) / 100;
  o.rev = {
    itemPrice,
    delivery_fee_base: o.price ? o.price.base : 0,
    delivery_fee: deliveryFee,
    commission_rate: commissionRate,
    commission_amount: commissionAmount,
    driver_fee_cut_rate: feeCutRate,
    driver_fee_cut_amount: driverFeeCutAmount,
    platform_revenue: Math.round((commissionAmount + driverFeeCutAmount) * 100) / 100,
  };
});

// Orders that actually accrue revenue (delivered or in-flight, not cancelled).
function revenueOrders() { return ORDERS.filter((o) => o.status !== 'cancelled' && o.status !== 'failed' && o.rev && o.rev.platform_revenue > 0); }
function orderDate(o) { return (o.created || '').slice(0, 10); }
// Range filter: 'today' | '7d' | '30d' | 'all' relative to NOW_DATE.
const NOW_DATE = '2026-06-16';
function withinRange(dateStr, range) {
  if (range === 'all' || !dateStr) return true;
  const d = new Date(dateStr + 'T00:00:00'), now = new Date(NOW_DATE + 'T00:00:00');
  const days = Math.round((now - d) / 86400000);
  if (range === 'today') return days === 0;
  if (range === '7d') return days >= 0 && days < 7;
  if (range === '30d') return days >= 0 && days < 30;
  return true;
}
// ============================================================================
//  STAFF — internal accounts are normal users with a Spatie role (admin or
//  office_staff). Flat authority: only admins manage staff. Managed via the
//  /admin/staff endpoints (separate from the customer Users roster).
// ============================================================================
const STAFF_ROLES = {
  admin:        { ar: 'مدير', en: 'Admin', tone: 'violet' },
  office_staff: { ar: 'موظف مكتب', en: 'Office staff', tone: 'slate' },
};

const CURRENT_STAFF_ID = 'STF-1001'; // the logged-in actor (Sara) — admin

// office_assignments: { id, office, is_manager, assignedAt, removedAt }
const STAFF_RECORDS = [
  { id: 'STF-1001', firstName: { ar: 'سارة', en: 'Sara' }, lastName: { ar: 'المنصوري', en: 'Al-Mansouri' }, phone: '091 200 1001', email: 's.mansouri@tawseel.ly',
    role: 'admin', accountStatus: 'active', mustChangePassword: false, phoneVerified: true, emailVerified: true,
    assignments: [], createdAt: '2023-08-01', updatedAt: '2026-06-15' },
  { id: 'STF-1007', firstName: { ar: 'ناصر', en: 'Nasser' }, lastName: { ar: 'القذافي', en: 'Al-Gathafi' }, phone: '091 200 1007', email: 'n.gathafi@tawseel.ly',
    role: 'admin', accountStatus: 'active', mustChangePassword: false, phoneVerified: true, emailVerified: true,
    assignments: [], createdAt: '2023-09-10', updatedAt: '2026-06-10' },
  { id: 'STF-1002', firstName: { ar: 'عمر', en: 'Omar' }, lastName: { ar: 'القاضي', en: 'Al-Qadi' }, phone: '092 200 1002', email: 'o.qadi@tawseel.ly',
    role: 'office_staff', accountStatus: 'active', mustChangePassword: false, phoneVerified: true, emailVerified: true,
    assignments: [
      { id: 'OA-201', office: 'of-01', is_manager: true,  assignedAt: '2024-02-01', removedAt: null },
      { id: 'OA-202', office: 'of-03', is_manager: false, assignedAt: '2025-05-12', removedAt: null },
    ], createdAt: '2024-02-01', updatedAt: '2026-06-14' },
  { id: 'STF-1003', firstName: { ar: 'هدى', en: 'Huda' }, lastName: { ar: 'بن صالح', en: 'Bin Saleh' }, phone: '092 200 1003', email: 'h.binsaleh@tawseel.ly',
    role: 'office_staff', accountStatus: 'active', mustChangePassword: false, phoneVerified: true, emailVerified: false,
    assignments: [{ id: 'OA-203', office: 'of-02', is_manager: true, assignedAt: '2024-06-20', removedAt: null }], createdAt: '2024-06-20', updatedAt: '2026-06-09' },
  { id: 'STF-1004', firstName: { ar: 'يوسف', en: 'Youssef' }, lastName: { ar: 'العماري', en: 'Al-Amari' }, phone: '094 200 1004', email: null,
    role: 'office_staff', accountStatus: 'active', mustChangePassword: true, phoneVerified: true, emailVerified: false,
    assignments: [{ id: 'OA-204', office: 'of-04', is_manager: false, assignedAt: '2026-06-15', removedAt: null }], createdAt: '2026-06-15', updatedAt: '2026-06-15' },
  { id: 'STF-1005', firstName: { ar: 'أمينة', en: 'Amina' }, lastName: { ar: 'الشلوي', en: 'Al-Shalwi' }, phone: '092 200 1005', email: 'a.shalwi@tawseel.ly',
    role: 'office_staff', accountStatus: 'suspended', mustChangePassword: false, phoneVerified: true, emailVerified: true,
    assignments: [{ id: 'OA-205', office: 'of-01', is_manager: false, assignedAt: '2024-11-03', removedAt: null }], createdAt: '2024-11-03', updatedAt: '2026-06-08' },
  { id: 'STF-1006', firstName: { ar: 'طارق', en: 'Tarek' }, lastName: { ar: 'الفقيه', en: 'Al-Faqih' }, phone: '094 200 1006', email: 't.faqih@tawseel.ly',
    role: 'office_staff', accountStatus: 'suspended', mustChangePassword: false, phoneVerified: true, emailVerified: true, deactivated: true,
    assignments: [{ id: 'OA-206', office: 'of-02', is_manager: false, assignedAt: '2024-03-15', removedAt: '2026-05-30' }], createdAt: '2024-03-15', updatedAt: '2026-05-30' },
  { id: 'STF-1008', firstName: { ar: 'بشير', en: 'Bashir' }, lastName: { ar: 'الورفلي', en: 'Al-Warfalli' }, phone: '092 200 1008', email: 'b.warfalli@tawseel.ly',
    role: 'office_staff', accountStatus: 'suspended_unpaid_fees', mustChangePassword: false, phoneVerified: true, emailVerified: true,
    assignments: [{ id: 'OA-208', office: 'of-03', is_manager: false, assignedAt: '2025-01-20', removedAt: null }], createdAt: '2025-01-20', updatedAt: '2026-06-11' },
];

const STAFF_ACTIVITY_TYPES = {
  settlement_processed: { ar: 'تسوية سائق', en: 'Driver settlement', icon: 'settlements', financial: true },
  payout_paid:          { ar: 'صرف بائع', en: 'Seller payout', icon: 'coins', financial: true },
  order_received:       { ar: 'استلام مرتجع', en: 'Returned order received', icon: 'box' },
  order_released:       { ar: 'تسليم من المخزن', en: 'Released from office', icon: 'box' },
  order_status:         { ar: 'تحديث حالة طلب', en: 'Order status update', icon: 'orders' },
  merchant_onboard:     { ar: 'تسجيل تاجر', en: 'Merchant onboarded', icon: 'merchants' },
  driver_approve:       { ar: 'اعتماد سائق', en: 'Driver approved', icon: 'drivers' },
  doc_verify:           { ar: 'توثيق مستند', en: 'Document verified', icon: 'checkCircle' },
  settings_change:      { ar: 'تعديل إعدادات', en: 'Settings changed', icon: 'settings' },
  moderation_performed: { ar: 'إجراء إداري', en: 'Moderation action', icon: 'shield' },
  moderation_applied:   { ar: 'إجراء على الحساب', en: 'Action on this account', icon: 'alert' },
};

// Seeded activity for the non-settlement/payout sources (settlements & payouts
// are derived live from their own records). Marked: full feed is an aggregation
// across audit sources, not one backend endpoint yet.
const STAFF_ACTIVITY_SEED = {
  'STF-1001': [
    { at: '2026-06-15 16:20', type: 'settings_change', entity: { ar: 'عمولة السلعة 15%→15%', en: 'Item commission 15%' }, status: 'ok', direction: 'performed' },
    { at: '2026-06-14 10:05', type: 'merchant_onboard', entity: { ar: 'صيدلية النهضة', en: 'Al-Nahda Pharmacy' }, office: 'of-01', status: 'active', direction: 'performed' },
    { at: '2026-06-13 09:40', type: 'driver_approve', entity: { ar: 'حمزة العبيدي', en: 'Hamza Al-Obeidi' }, status: 'approved', direction: 'performed' },
    { at: '2026-06-12 14:15', type: 'moderation_performed', entity: { ar: 'سعاد المنتصر', en: 'Souad Al-Muntasir' }, status: 'suspended', reason: { ar: 'نزاع دفع', en: 'Payment dispute' }, direction: 'performed' },
  ],
  'STF-1002': [
    { at: '2026-06-15 12:30', type: 'order_received', entity: { ar: 'TRP-24858', en: 'TRP-24858' }, office: 'of-01', status: 'in_office', direction: 'performed' },
    { at: '2026-06-15 11:10', type: 'order_released', entity: { ar: 'TRP-24851', en: 'TRP-24851' }, office: 'of-01', status: 'released', direction: 'performed' },
    { at: '2026-06-14 17:00', type: 'doc_verify', entity: { ar: 'رخصة — خالد الزروق', en: "Licence — Khaled Al-Zarrouk" }, status: 'verified', direction: 'performed' },
  ],
  'STF-1003': [
    { at: '2026-06-14 15:25', type: 'order_status', entity: { ar: 'TRP-24862', en: 'TRP-24862' }, office: 'of-02', status: 'in_transit', direction: 'performed' },
    { at: '2026-06-13 13:00', type: 'order_received', entity: { ar: 'TRP-24840', en: 'TRP-24840' }, office: 'of-02', status: 'in_office', direction: 'performed' },
  ],
  'STF-1005': [
    { at: '2026-06-08 09:30', type: 'moderation_applied', entity: { ar: 'الحساب', en: 'Account' }, status: 'suspended', reason: { ar: 'مخالفة إجراءات الصندوق', en: 'Cash-handling violation' }, direction: 'applied' },
  ],
  'STF-1006': [
    { at: '2026-05-30 11:00', type: 'moderation_applied', entity: { ar: 'الحساب', en: 'Account' }, status: 'deactivated', reason: { ar: 'إنهاء الخدمة', en: 'Offboarded' }, direction: 'applied' },
  ],
  'STF-1008': [
    { at: '2026-06-11 10:00', type: 'moderation_applied', entity: { ar: 'الحساب', en: 'Account' }, status: 'suspended_unpaid_fees', reason: { ar: 'دين سائق غير مسدّد', en: 'Outstanding driver debt' }, direction: 'applied' },
  ],
};

function staffById(id) { return STAFF_RECORDS.find((s) => s.id === id) || null; }
function staffName(s, lang) { return `${tt(s.firstName, lang)} ${tt(s.lastName, lang)}`; }
function activeAssignments(s) { return (s.assignments || []).filter((a) => !a.removedAt); }
function activeAdmins(list) { return (list || STAFF_RECORDS).filter((s) => s.role === 'admin' && s.accountStatus === 'active' && !s.deactivated); }
function isLastActiveAdmin(s, list) { return s.role === 'admin' && activeAdmins(list).length <= 1 && s.accountStatus === 'active' && !s.deactivated; }

// Aggregated activity feed for a staff member, merged from live financial
// records + seeded audit entries. Returns most-recent-first.
function staffActivity(s, settlements, payouts) {
  const out = [];
  const nm = staffName(s, 'en');
  (settlements || SETTLEMENTS).forEach((x) => {
    if (x.status === 'processed' && x.staff && tt(x.staff, 'en') === nm) {
      out.push({ at: x.processedAt, type: 'settlement_processed', entity: { ar: x.id, en: x.id }, office: x.office, amount: Math.abs(x.net), status: x.direction, direction: 'performed' });
    }
  });
  (payouts || PAYOUTS).forEach((x) => {
    if (x.staff && tt(x.staff, 'en') === nm) {
      out.push({ at: x.paidAt, type: 'payout_paid', entity: sellerLabel(x.sellerUserId), office: x.office, amount: x.total, status: 'paid', direction: 'performed' });
    }
  });
  (STAFF_ACTIVITY_SEED[s.id] || []).forEach((e) => out.push(e));
  return out.sort((a, b) => (a.at < b.at ? 1 : -1));
}
function accruedRevenue(list) {
  return list.reduce((acc, o) => {
    acc.commission += o.rev.commission_amount;
    acc.feeCut += o.rev.driver_fee_cut_amount;
    acc.total += o.rev.platform_revenue;
    return acc;
  }, { commission: 0, feeCut: 0, total: 0 });
}
// Cash-realized revenue = settlement cash net (in − out) − seller payouts, within range.
function cashRealized(settlements, payouts, range) {
  const settledIn = settlements.filter((s) => s.status === 'processed' && withinRange((s.processedAt || '').slice(0, 10), range))
    .reduce((sum, s) => sum + (s.direction === 'driver_to_office' ? (s.cashReceived || 0) : s.direction === 'office_to_driver' ? -Math.abs(s.net) : 0), 0);
  const paidOut = payouts.filter((p) => withinRange((p.paidAt || '').slice(0, 10), range)).reduce((sum, p) => sum + p.total, 0);
  return { settlementCashNet: settledIn, payouts: paidOut, total: settledIn - paidOut };
}


// Per-user overrides for verification / locale; everything else gets sane defaults.
const USER_META = {
  'USR-1008': { phoneVerified: true, emailVerified: false },
  'USR-2007': { phoneVerified: false, emailVerified: false },
  'USR-2003': { locale: 'en' },
  'USR-2006': { locale: 'en' },
};

// Normalize: inject roles, verification, locale, notif prefs, moderation onto each user.
USERS.forEach((u) => {
  const m = USER_META[u.id] || {};
  const roles = ['customer'];
  if (u.driverId) roles.push('driver');
  // merchant role present only while the merchant profile is not banned (ban is terminal).
  if (u.merchantId) { const mer = merchantById(u.merchantId); if (mer && mer.status !== 'banned') roles.push('merchant'); }
  u.roles = roles;
  u.phoneVerified = m.phoneVerified !== undefined ? m.phoneVerified : true;
  u.emailVerified = m.emailVerified !== undefined ? m.emailVerified : (u.accountStatus === 'active');
  u.locale = m.locale || 'ar';
  u.notif = m.notif || { push: true, sms: u.accountStatus === 'active', email: !!u.email };
  u.moderation = MODERATION[u.id] || [];
});

function userById(id) { return USERS.find((u) => u.id === id) || null; }

// Orders this person placed as a customer (sender or receiver) — NOT deliveries.
function customerOrders(name) {
  const en = tt(name, 'en');
  return ORDERS.filter((o) => tt(o.sender, 'en') === en || tt(o.receiver, 'en') === en);
}

Object.assign(window, {
  tt, num, OFFICES, DISTRICTS, DRIVERS, STATUS, TYPES, ORDERS, STATS, ACTIVITY,
  LIFECYCLE, ACCOUNT_STATUS, MERCHANT_STATUS, PLATFORM_RATES, PLATFORM_SETTINGS, PRESENCE, VEHICLES, DOC_TYPES, STRIKE_REASONS, LEDGER_REASONS, BUCKETS, MOD_ACTIONS,
  EARNING_STATUS, EARNING_FLOW, SETTLEMENT_STATUS, DIRECTION_LABEL, DIRECTION_ACTION, CLEARANCE_HOURS, PAYOUT_MIN,
  DRIVER_RECORDS, USERS, MERCHANT_RECORDS, MODERATION, SELLER_EARNINGS, SETTLEMENTS, PAYOUTS,
  maskPhone, driverLoads, activeStrikes, acct, userById, customerOrders, merchantById, merchantOrders,
  settleNet, settleDirection, sellerLabel, settlementQueue, earningsForSeller, settlementById,
  revenueOrders, orderDate, orderMerchant, NOW_DATE, withinRange, accruedRevenue, cashRealized,
  STAFF_ROLES, STAFF_RECORDS, STAFF_ACTIVITY_TYPES, CURRENT_STAFF_ID,
  staffById, staffName, activeAssignments, activeAdmins, isLastActiveAdmin, staffActivity,
});
