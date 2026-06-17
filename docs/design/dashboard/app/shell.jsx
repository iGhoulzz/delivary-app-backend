// shell.jsx — Sidebar + TopBar with three layout variants (light / dark / floating).

const NAV_SECTIONS = [
  {
    label: { ar: 'العمليات', en: 'Operations' },
    items: [
      { id: 'overview', label: { ar: 'الرئيسية', en: 'Overview' }, icon: 'overview' },
      { id: 'orders', label: { ar: 'الطلبات', en: 'Orders' }, icon: 'orders', badge: 'pending' },
      { id: 'drivers', label: { ar: 'السائقون', en: 'Drivers' }, icon: 'drivers' },
    ],
  },
  {
    label: { ar: 'الدليل', en: 'Directory' },
    items: [
      { id: 'users', label: { ar: 'المستخدمون', en: 'Users' }, icon: 'users' },
      { id: 'merchants', label: { ar: 'التجار', en: 'Merchants' }, icon: 'merchants' },
    ],
  },
  {
    label: { ar: 'الإدارة', en: 'Admin' },
    items: [
      { id: 'finance', label: { ar: 'المالية', en: 'Finance' }, icon: 'finance' },
      { id: 'settlements', label: { ar: 'التسويات', en: 'Settlements' }, icon: 'settlements' },
      { id: 'staff', label: { ar: 'الطاقم', en: 'Staff' }, icon: 'staff' },
      { id: 'settings', label: { ar: 'الإعدادات', en: 'Settings' }, icon: 'settings' },
    ],
  },
];

function Brand({ collapsed, lang }) {
  return (
    <div className="flex items-center gap-2.5">
      <div className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] text-white shadow-sm"
        style={{ background: 'var(--accent)' }}>
        <Icon name="route" size={20} strokeWidth={2} />
      </div>
      {!collapsed && (
        <div className="leading-tight">
          <div className="text-[15px] font-bold tracking-tight text-slate-900">
            {lang === 'ar' ? 'توصيل' : 'Tawseel'}
          </div>
          <div className="text-[11px] text-slate-400">
            {lang === 'ar' ? 'مركز العمليات' : 'Ops Console'}
          </div>
        </div>
      )}
    </div>
  );
}

function Sidebar({ floating, collapsed, setCollapsed, page, setPage, lang, counts }) {
  const W = collapsed ? 'w-[76px]' : 'w-[248px]';
  const base = 'bg-white text-slate-700 border-slate-200/80';
  const shell = floating
    ? `app-card m-3 rounded-2xl border shadow-sm ${base}`
    : `border-e ${base}`;

  return (
    <aside className={`${W} ${shell} relative z-20 flex shrink-0 flex-col transition-[width] duration-200`}>
      <div className={`flex h-16 items-center ${collapsed ? 'justify-center' : 'justify-between'} px-4`}>
        <Brand collapsed={collapsed} lang={lang} />
      </div>

      <nav className="flex-1 overflow-y-auto px-3 pb-4">
        {NAV_SECTIONS.map((sec, si) => (
          <div key={si} className="mb-4">
            {!collapsed && (
              <div className="px-2.5 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                {tt(sec.label, lang)}
              </div>
            )}
            <ul className="flex flex-col gap-0.5">
              {sec.items.map((it) => {
                const active = page === it.id;
                const badge = it.badge ? counts[it.badge] : null;
                return (
                  <li key={it.id}>
                    <button onClick={() => setPage(it.id)} title={collapsed ? tt(it.label, lang) : undefined}
                      className={`nav-link group flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-[14px] font-medium transition
                        ${collapsed ? 'justify-center' : ''}
                        ${active ? 'text-white' : 'text-slate-600 hover:text-slate-900'}`}
                      style={active ? { background: 'var(--accent)' } : undefined}>
                      <span className={active ? 'text-white' : ''}><Icon name={it.icon} size={20} /></span>
                      {!collapsed && <span className="flex-1 text-start">{tt(it.label, lang)}</span>}
                      {!collapsed && badge ? (
                        <span className={`rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums
                          ${active ? 'bg-white/25 text-white' : 'bg-slate-200 text-slate-600'}`}>
                          {num(badge, lang)}
                        </span>
                      ) : null}
                    </button>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div className="border-t border-slate-200/80 p-3">
        <button onClick={() => setCollapsed(!collapsed)}
          className={`flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-[13px] font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-800
            ${collapsed ? 'justify-center' : ''}`}>
          <Icon name={lang === 'ar' ? 'chevronR' : 'chevronL'} size={18} />
          {!collapsed && <span>{lang === 'ar' ? 'طيّ القائمة' : 'Collapse'}</span>}
        </button>
      </div>
    </aside>
  );
}

const PAGE_TITLES = {
  overview: { ar: 'الرئيسية', en: 'Overview' },
  orders: { ar: 'الطلبات', en: 'Orders' },
  drivers: { ar: 'السائقون', en: 'Drivers' },
  users: { ar: 'المستخدمون', en: 'Users' },
  merchants: { ar: 'التجار', en: 'Merchants' },
  settlements: { ar: 'التسويات', en: 'Settlements' },
  finance: { ar: 'المالية', en: 'Finance' },
  settings: { ar: 'الإعدادات', en: 'Settings' },
  staff: { ar: 'الطاقم', en: 'Staff' },
};

function TopBar({ page, lang, setLang, onLogout }) {
  return (
    <header className="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/85 px-5 backdrop-blur">
      <div className="min-w-0 flex-1">
        <h1 className="truncate text-[17px] font-semibold tracking-tight text-slate-900">{tt(PAGE_TITLES[page], lang)}</h1>
      </div>

      <div className="ms-auto flex items-center gap-1.5">
        <button onClick={() => setLang(lang === 'ar' ? 'en' : 'ar')}
          className="flex h-9 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 text-[13px] font-semibold text-slate-600 transition hover:bg-slate-50">
          <Icon name="globe" size={17} />
          <span>{lang === 'ar' ? 'EN' : 'ع'}</span>
        </button>
        <button className="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
          <Icon name="bell" size={19} />
          <span className="absolute end-2 top-2 h-2 w-2 rounded-full ring-2 ring-white" style={{ background: 'var(--accent)' }} />
        </button>

        <div className="mx-1 h-7 w-px bg-slate-200" />

        <div className="flex items-center gap-2.5 ps-1">
          <Avatar name={{ ar: 'سارة المنصوري', en: 'Sara Al-Mansouri' }} lang={lang} size={34} tone="var(--accent)" />
          <div className="hidden leading-tight sm:block">
            <div className="flex items-center gap-1.5 text-[13.5px] font-semibold text-slate-800">
              {lang === 'ar' ? 'سارة المنصوري' : 'Sara Al-Mansouri'}
              <span className="inline-flex items-center gap-1 rounded-full px-1.5 py-[3px] text-[10.5px] font-bold text-white" style={{ background: 'var(--accent)' }}>
                <Icon name="shield" size={11} strokeWidth={2.6} />
                {lang === 'ar' ? 'مدير' : 'Admin'}
              </span>
            </div>
            <div className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'مكتب وسط المدينة' : 'City Center Office'}</div>
          </div>
          <button onClick={onLogout} title={lang === 'ar' ? 'تسجيل الخروج' : 'Log out'}
            className="ms-1 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600">
            <Icon name="logout" size={19} />
          </button>
        </div>
      </div>
    </header>
  );
}

Object.assign(window, { Sidebar, TopBar, NAV_SECTIONS, PAGE_TITLES });
