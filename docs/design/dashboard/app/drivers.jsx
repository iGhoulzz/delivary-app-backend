// drivers.jsx — drivers roster (table) mirroring the Orders pipe. Admin role.

function Drivers({ lang, drivers, onOpen, openId, onAdd }) {
  const [q, setQ] = React.useState('');
  const [presence, setPresence] = React.useState('all');
  const [life, setLife] = React.useState('all');

  const officeName = (id) => {
    const o = OFFICES.find((x) => x.id === id);
    return o ? o.district : null;
  };

  const filtered = drivers.filter((d) => {
    if (presence !== 'all' && d.activity !== presence) return false;
    if (life !== 'all') {
      if (life === 'active' && d.profileStatus !== 'active') return false;
      if (life === 'suspended' && !(d.profileStatus === 'suspended' || d.accountStatus === 'suspended_unpaid_fees')) return false;
      if (life === 'pending_approval' && d.profileStatus !== 'pending_approval') return false;
      if (life === 'banned' && d.profileStatus !== 'banned') return false;
    }
    if (q.trim()) {
      const hay = [d.id, d.plate, tt(d.name, lang), tt(d.name, 'en'), d.phone].join(' ').toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    return true;
  });

  const presenceOpts = [['all', { ar: 'كل الحالات', en: 'All presence' }], ...Object.keys(PRESENCE).map((k) => [k, PRESENCE[k]])];
  const lifeChips = [
    ['all', { ar: 'الكل', en: 'All' }],
    ['active', LIFECYCLE.active],
    ['pending_approval', { ar: 'معلّقون', en: 'Pending' }],
    ['suspended', { ar: 'موقوفون', en: 'Suspended' }],
    ['banned', LIFECYCLE.banned],
  ];

  const onlineCount = drivers.filter((d) => d.activity !== 'offline').length;

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      {/* filter bar */}
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative min-w-[220px] flex-1">
          <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={18} /></div>
          <input value={q} onChange={(e) => setQ(e.target.value)}
            placeholder={lang === 'ar' ? 'ابحث بالاسم أو الهاتف أو اللوحة…' : 'Search name, phone or plate…'}
            className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[14px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
        </div>
        <Dropdown lang={lang} value={presence} setValue={setPresence} options={presenceOpts} icon="filter"
          label={lang === 'ar' ? 'الحالة' : 'Presence'} />
        <div className="hidden items-center gap-1.5 lg:flex">
          {lifeChips.map(([k, v]) => (
            <FilterChip key={k} active={life === k} onClick={() => setLife(k)}>{tt(v, lang)}</FilterChip>
          ))}
        </div>
        <div className="ms-auto flex items-center gap-3">
          <span className="hidden text-[13px] text-slate-400 tabular-nums sm:inline">
            <span className="font-semibold text-emerald-600">{num(onlineCount, lang)}</span> {lang === 'ar' ? 'متصل' : 'online'}
            <span className="mx-1.5 text-slate-300">·</span>
            {num(filtered.length, lang)} {lang === 'ar' ? 'سائق' : 'drivers'}
          </span>
          <button onClick={onAdd} className="inline-flex h-10 items-center gap-2 rounded-lg px-3.5 text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name="plus" size={17} strokeWidth={2.2} />{lang === 'ar' ? 'إضافة سائق' : 'Add driver'}
          </button>
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th>{lang === 'ar' ? 'السائق' : 'Driver'}</Th>
                <Th>{lang === 'ar' ? 'الحالة' : 'Presence'}</Th>
                <Th>{lang === 'ar' ? 'الحمولة' : 'Active load'}</Th>
                <Th className="hidden xl:table-cell">{lang === 'ar' ? 'المركبة' : 'Vehicle'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'المكتب' : 'Office'}</Th>
                <Th>{lang === 'ar' ? 'نقد محتجز' : 'COD held'}</Th>
                <Th className="hidden sm:table-cell">{lang === 'ar' ? 'التقييم' : 'Rating'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((d) => {
                const loads = driverLoads(d);
                const off = officeName(d.office);
                const dim = d.profileStatus === 'banned' || d.profileStatus === 'rejected';
                return (
                  <tr key={d.id} onClick={() => onOpen(d.id)}
                    className={`cursor-pointer text-[13.5px] transition hover:bg-slate-50/70 ${openId === d.id ? 'bg-[var(--accent-soft)]' : ''}`}>
                    <Td>
                      <div className="flex items-center gap-3">
                        <div className={`relative ${dim ? 'opacity-60' : ''}`}>
                          <Avatar name={d.name} lang={lang} size={38} />
                          <span className="absolute -bottom-0.5 -end-0.5 h-3 w-3 rounded-full ring-2 ring-white" style={{ background: (PRESENCE[d.activity] || PRESENCE.offline).dot }} />
                        </div>
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-slate-800">{tt(d.name, lang)}</span>
                            <LifecycleBadge lifecycle={d.profileStatus} lang={lang} />
                          </div>
                          <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr', display: 'inline-block' }}>{d.id}</div>
                        </div>
                      </div>
                    </Td>
                    <Td><PresencePill activity={d.activity} lang={lang} size="sm" /></Td>
                    <Td>
                      {loads.length ? (
                        <span className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[12.5px] font-semibold" style={{ background: tint('#7c3aed', 14), color: '#7c3aed' }}>
                          <Icon name="box" size={14} />{num(loads.length, lang)} {lang === 'ar' ? 'طلب' : loads.length === 1 ? 'order' : 'orders'}
                        </span>
                      ) : <span className="text-[12.5px] text-slate-300">{lang === 'ar' ? 'لا حمولة' : 'Idle'}</span>}
                    </Td>
                    <Td className="hidden xl:table-cell"><VehicleChip type={d.vehicle} lang={lang} plate={d.plate} /></Td>
                    <Td className="hidden lg:table-cell">
                      {off ? <span className="text-[13px] text-slate-600">{tt(off, lang)}</span> : <span className="text-[12.5px] italic text-slate-400">{lang === 'ar' ? 'غير معيّن' : 'Unassigned'}</span>}
                    </Td>
                    <Td><CodCell d={d} lang={lang} /></Td>
                    <Td className="hidden sm:table-cell"><Rating value={d.rating} lang={lang} /></Td>
                    <Td><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={18} /></span></Td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && (
          <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا يوجد سائقون مطابقون' : 'No matching drivers'}</div>
        )}
      </Card>
    </div>
  );
}

Object.assign(window, { Drivers });
