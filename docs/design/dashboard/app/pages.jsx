// pages.jsx — placeholder module pages (next slices after the proven Orders pipe).

const STUB_COPY = {
  drivers: {
    icon: 'drivers',
    desc: { ar: 'قائمة السائقين بحالاتهم، المركبات، التقييم، والطلبات النشطة — ثم صفحة تفصيل لكل سائق.', en: 'Roster of drivers with status, vehicle, rating and active loads — drilling into a per-driver detail page.' },
    slot: { ar: 'جدول السائقين + خريطة المواقع الحيّة', en: 'Drivers table + live-location map' },
  },
  users: {
    icon: 'users',
    desc: { ar: 'دليل العملاء: جهات الاتصال، العناوين المحفوظة، وسجل الطلبات لكل مستخدم.', en: 'Customer directory: contacts, saved addresses and per-user order history.' },
    slot: { ar: 'جدول المستخدمين + ملف العميل', en: 'Users table + customer profile' },
  },
  merchants: {
    icon: 'merchants',
    desc: { ar: 'حسابات التجار، فروعهم، اتفاقيات الأسعار، وحجم الطلبات الأسبوعي.', en: 'Merchant accounts, their branches, pricing agreements and weekly order volume.' },
    slot: { ar: 'بطاقات التجار + لوحة الأداء', en: 'Merchant cards + performance board' },
  },
  settlements: {
    icon: 'settlements',
    desc: { ar: 'تسويات المبالغ المحصّلة عند التسليم بين السائقين والمكاتب، مع دورات الدفع.', en: 'Reconciliation of cash-on-delivery between drivers and offices, with payout cycles.' },
    slot: { ar: 'دورات التسوية + كشف الحساب', en: 'Settlement cycles + statement' },
  },
  staff: {
    icon: 'staff',
    desc: { ar: 'موظفو المكاتب، الأدوار والصلاحيات (مدير / موظف مكتب)، وتعيين المكاتب.', en: 'Office staff, roles & permissions (Admin / Office Staff), and office assignment.' },
    slot: { ar: 'جدول الطاقم + إدارة الأدوار', en: 'Staff table + role management' },
  },
};

function StubPage({ page, lang }) {
  const c = STUB_COPY[page] || STUB_COPY.staff;
  const title = tt(PAGE_TITLES[page], lang);
  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      <Card className="overflow-hidden">
        <div className="flex flex-col items-center px-6 py-12 text-center">
          <span className="grid h-16 w-16 place-items-center rounded-2xl text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name={c.icon} size={30} strokeWidth={1.6} />
          </span>
          <h2 className="mt-5 text-[22px] font-bold tracking-tight text-slate-900">{title}</h2>
          <p className="mt-2 max-w-md text-[14px] leading-relaxed text-slate-500">{tt(c.desc, lang)}</p>
          <span className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-[12px] font-semibold text-slate-500">
            <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
            {lang === 'ar' ? 'الشريحة القادمة' : 'Next build slice'}
          </span>
        </div>
        {/* striped placeholder */}
        <div className="px-6 pb-6">
          <div className="grid h-[260px] place-items-center rounded-xl border border-dashed border-slate-300"
            style={{ background: 'repeating-linear-gradient(135deg, #f8fafc, #f8fafc 11px, #f1f5f9 11px, #f1f5f9 22px)' }}>
            <span className="rounded-md bg-white/80 px-3 py-1.5 font-mono text-[12.5px] text-slate-400 ring-1 ring-slate-200">{tt(c.slot, lang)}</span>
          </div>
        </div>
      </Card>
    </div>
  );
}

Object.assign(window, { StubPage });
