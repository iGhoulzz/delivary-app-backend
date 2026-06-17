// overview.jsx — landing page: stat cards, Tripoli map hero, live drivers, activity.

function Sparkline({ up, color }) {
  const pts = up
    ? '0,18 12,16 24,17 36,12 48,13 60,8 72,9 84,4'
    : '0,6 12,7 24,5 36,9 48,8 60,12 72,11 84,16';
  return (
    <svg width="86" height="22" viewBox="0 0 86 22" fill="none" className="overflow-visible">
      <polyline points={pts} stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

const STAT_ICON = { active: 'box', drivers: 'truck', today: 'check', settle: 'settlements' };

function StatCard({ stat, lang }) {
  const up = stat.dir === 'up';
  const good = stat.id === 'settle' ? !up : up; // fewer settlements pending = good
  const color = good ? '#059669' : '#e11d48';
  return (
    <Card className="p-5">
      <div className="flex items-center gap-2.5">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
          style={{ background: 'color-mix(in srgb, var(--accent) 14%, transparent)', color: 'var(--accent)' }}>
          <Icon name={STAT_ICON[stat.id]} size={19} />
        </span>
        <div className="flex-1 text-[13px] font-medium text-slate-500">{tt(stat.label, lang)}</div>
        <span className={`inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-[12px] font-semibold ${good ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
          <Icon name={up ? 'arrowUp' : 'arrowDn'} size={13} strokeWidth={2.4} />
          {num(stat.delta, lang)}%
        </span>
      </div>
      <div className="mt-3 flex items-end justify-between">
        <div className="text-[30px] font-bold tracking-tight text-slate-900 tabular-nums">
          {num(stat.value, lang)}
          {stat.money && <span className="ms-1 text-[15px] font-semibold text-slate-400">{lang === 'ar' ? 'د.ل' : 'LYD'}</span>}
        </div>
        <Sparkline up={up} color={color} />
      </div>
    </Card>
  );
}

function LegendDot({ color, ring, label }) {
  return (
    <div className="flex items-center gap-2 text-[13px] text-slate-600">
      <span className="relative grid h-3.5 w-3.5 place-items-center">
        {ring && <span className="absolute h-3.5 w-3.5 rounded-full opacity-30" style={{ background: color }} />}
        <span className="h-2.5 w-2.5 rounded-full ring-2 ring-white" style={{ background: color }} />
      </span>
      {label}
    </div>
  );
}

function Overview({ lang, setPage, dark, playful, selectedOffice, setSelectedOffice }) {
  const moving = DRIVERS.filter((d) => d.status === 'moving');
  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      {/* stat cards */}
      <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
        {STATS.map((s) => <StatCard key={s.id} stat={s} lang={lang} />)}
      </div>

      {/* map + live drivers */}
      <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Card className="overflow-hidden lg:col-span-2">
          <div className="flex items-center justify-between gap-3 border-b border-slate-200/80 px-5 py-3.5">
            <div className="min-w-0 flex-1">
              <h2 className="truncate text-[15px] font-semibold text-slate-900">{lang === 'ar' ? 'خريطة العمليات — طرابلس' : 'Operations Map — Tripoli'}</h2>
              <p className="truncate text-[12.5px] text-slate-400">{lang === 'ar' ? 'المكاتب والسائقون النشطون لحظيًا' : 'Offices and active drivers, live'}</p>
            </div>
            <div className="hidden shrink-0 items-center gap-4 sm:flex">
              <LegendDot color="var(--accent)" label={lang === 'ar' ? 'مكتب' : 'Office'} />
              <LegendDot color="#16a34a" ring label={lang === 'ar' ? 'سائق متصل' : 'Online driver'} />
              <LegendDot color="#94a3b8" label={lang === 'ar' ? 'متوقف' : 'Idle'} />
            </div>
          </div>
          <div className="relative h-[300px] sm:h-[400px] lg:h-[460px]" style={{ background: dark ? '#0e1526' : '#eef1ee' }}>
            <TripoliMap lang={lang} dark={dark} playful={playful} selectedOffice={selectedOffice} onSelectOffice={setSelectedOffice} />
          </div>
        </Card>

        <Card className="flex flex-col">
          <div className="flex items-center justify-between border-b border-slate-200/80 px-5 py-3.5">
            <h2 className="text-[15px] font-semibold text-slate-900">{lang === 'ar' ? 'السائقون النشطون' : 'Active Drivers'}</h2>
            <button onClick={() => setPage('drivers')} className="text-[13px] font-semibold" style={{ color: 'var(--accent)' }}>
              {lang === 'ar' ? 'الكل' : 'View all'}
            </button>
          </div>
          <div className="flex-1 divide-y divide-slate-100">
            {moving.map((d) => (
              <div key={d.id} className="flex items-center gap-3 px-5 py-3">
                <Avatar name={d.name} lang={lang} size={36} />
                <div className="min-w-0 flex-1">
                  <div className="truncate text-[13.5px] font-semibold text-slate-800">{tt(d.name, lang)}</div>
                  <div className="flex items-center gap-1.5 text-[12px] text-slate-400">
                    <Icon name="truck" size={13} />{tt(d.vehicle, lang)}
                    <span className="text-slate-300">·</span>
                    {num(d.orders, lang)} {lang === 'ar' ? 'طلب' : 'orders'}
                  </div>
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-[11.5px] font-semibold text-emerald-700">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  {lang === 'ar' ? 'في الطريق' : 'En route'}
                </span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      {/* recent activity */}
      <Card className="mt-5">
        <div className="flex items-center justify-between border-b border-slate-200/80 px-5 py-3.5">
          <h2 className="text-[15px] font-semibold text-slate-900">{lang === 'ar' ? 'النشاط الأخير' : 'Recent activity'}</h2>
          <button onClick={() => setPage('orders')} className="text-[13px] font-semibold" style={{ color: 'var(--accent)' }}>
            {lang === 'ar' ? 'كل الطلبات' : 'All orders'}
          </button>
        </div>
        <ul className="divide-y divide-slate-100">
          {ACTIVITY.map((a) => {
            const meta = ACT_META[a.kind];
            return (
              <li key={a.id} className="flex items-center gap-3.5 px-5 py-3">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full" style={{ background: meta.bg, color: meta.fg }}>
                  <Icon name={meta.icon} size={17} />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="text-[13.5px] text-slate-700">
                    <span className="font-semibold text-slate-900">{tt(a.text, lang)}</span>
                    {a.order && <span className="ms-1.5 font-mono text-[12.5px] text-slate-400">{a.order}</span>}
                  </div>
                  <div className="text-[12px] text-slate-400">{tt(a.who, lang)}</div>
                </div>
                <div className="shrink-0 text-[12px] text-slate-400">{tt(a.ago, lang)}</div>
              </li>
            );
          })}
        </ul>
      </Card>
    </div>
  );
}

const ACT_META = {
  delivered: { icon: 'check', bg: '#ecfdf5', fg: '#059669' },
  assigned:  { icon: 'route', bg: 'color-mix(in srgb, var(--accent) 12%, transparent)', fg: 'var(--accent)' },
  failed:    { icon: 'xCircle', bg: '#fff1f2', fg: '#e11d48' },
  pending:   { icon: 'clock', bg: '#fffbeb', fg: '#d97706' },
  driver:    { icon: 'truck', bg: '#f1f5f9', fg: '#475569' },
};

Object.assign(window, { Overview });
