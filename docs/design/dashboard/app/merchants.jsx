// merchants.jsx — merchant roster. A merchant is an existing user with a 1:1
// merchant profile. Invite-only, created directly active. Two status axes.

// Rate cell: shows override as %, or the platform default in muted tone.
function RateCell({ override, fallback, lang }) {
  if (override == null) {
    return (
      <span className="inline-flex items-center gap-1 text-[12.5px] text-slate-400">
        <span className="tabular-nums">{num(Math.round(fallback * 100), lang)}%</span>
        <span className="rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium">{lang === 'ar' ? 'افتراضي' : 'default'}</span>
      </span>
    );
  }
  return <span className="font-semibold tabular-nums text-slate-700" style={{ direction: 'ltr' }}>{num(Math.round(override * 100), lang)}%</span>;
}

function Merchants({ lang, merchants, users, onOpen, openId, onAdd }) {
  const [q, setQ] = React.useState('');
  const [status, setStatus] = React.useState('all');

  const owner = (m) => users.find((u) => u.id === m.ownerUserId);

  const filtered = merchants.filter((m) => {
    if (status !== 'all' && m.status !== status) return false;
    if (q.trim()) {
      const o = owner(m);
      const hay = [m.id, tt(m.business, lang), tt(m.business, 'en'), m.businessPhone, o ? tt(o.name, lang) : '', o ? tt(o.name, 'en') : '', o ? o.phone : ''].join(' ').toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    return true;
  });

  const statusOpts = [['all', { ar: 'كل الحالات', en: 'All statuses' }], ...Object.keys(MERCHANT_STATUS).map((k) => [k, MERCHANT_STATUS[k]])];
  const activeCount = merchants.filter((m) => m.status === 'active').length;

  return (
    <div className="mx-auto max-w-[1500px] p-5 lg:p-7">
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative min-w-[220px] flex-1">
          <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={18} /></div>
          <input value={q} onChange={(e) => setQ(e.target.value)}
            placeholder={lang === 'ar' ? 'ابحث باسم النشاط أو المالك أو الهاتف…' : 'Search business, owner or phone…'}
            className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[14px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
        </div>
        <div className="hidden items-center gap-1.5 lg:flex">
          <FilterChip active={status === 'all'} onClick={() => setStatus('all')}>{lang === 'ar' ? 'الكل' : 'All'}</FilterChip>
          {Object.keys(MERCHANT_STATUS).map((k) => (
            <FilterChip key={k} active={status === k} onClick={() => setStatus(k)}>{tt(MERCHANT_STATUS[k], lang)}</FilterChip>
          ))}
        </div>
        <Dropdown lang={lang} value={status} setValue={setStatus} options={statusOpts} icon="filter"
          label={lang === 'ar' ? 'الحالة' : 'Status'} />
        <div className="ms-auto flex items-center gap-3">
          <span className="hidden text-[13px] text-slate-400 tabular-nums sm:inline">
            <span className="font-semibold text-emerald-600">{num(activeCount, lang)}</span> {lang === 'ar' ? 'نشط' : 'active'}
            <span className="mx-1.5 text-slate-300">·</span>
            {num(filtered.length, lang)} {lang === 'ar' ? 'تاجر' : 'merchants'}
          </span>
          <button onClick={onAdd} className="inline-flex h-10 items-center gap-2 rounded-lg px-3.5 text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name="plus" size={17} strokeWidth={2.2} />{lang === 'ar' ? 'تسجيل تاجر' : 'Onboard merchant'}
          </button>
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1040px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th className="min-w-[210px]">{lang === 'ar' ? 'النشاط التجاري' : 'Business'}</Th>
                <Th>{lang === 'ar' ? 'المالك' : 'Owner'}</Th>
                <Th>{lang === 'ar' ? 'حالة التاجر' : 'Merchant'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'الحساب' : 'Account'}</Th>
                <Th className="hidden xl:table-cell">{lang === 'ar' ? 'العمولة' : 'Commission'}</Th>
                <Th className="hidden xl:table-cell">{lang === 'ar' ? 'حصة التوصيل' : 'Delivery cut'}</Th>
                <Th className="hidden 2xl:table-cell">{lang === 'ar' ? 'الاستلام' : 'Pickup'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'أُنشئ' : 'Created'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((m) => {
                const o = owner(m);
                const dim = m.status === 'banned';
                return (
                  <tr key={m.id} onClick={() => onOpen(m.id)}
                    className={`cursor-pointer text-[13.5px] transition hover:bg-slate-50/70 ${openId === m.id ? 'bg-[var(--accent-soft)]' : ''}`}>
                    <Td className="min-w-[210px]">
                      <div className="flex items-center gap-3">
                        <div className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${dim ? 'opacity-60' : ''}`} style={{ background: tint('#2563eb', 12), color: '#2563eb' }}>
                          <Icon name="merchants" size={20} />
                        </div>
                        <div className="min-w-0">
                          <div className="font-semibold text-slate-800" style={{ whiteSpace: 'nowrap' }}>{tt(m.business, lang)}</div>
                          <div className="flex items-center gap-1.5 text-[11.5px] text-slate-400">
                            <span className="font-mono" style={{ direction: 'ltr' }}>{m.id}</span>
                            <span className="text-slate-300">·</span>
                            <span className="font-mono" style={{ direction: 'ltr' }}>{m.businessPhone}</span>
                          </div>
                        </div>
                      </div>
                    </Td>
                    <Td>
                      {o ? (
                        <div className="flex items-center gap-2">
                          <Avatar name={o.name} lang={lang} size={28} />
                          <span className="text-[13px] text-slate-600" style={{ whiteSpace: 'nowrap' }}>{tt(o.name, lang)}</span>
                        </div>
                      ) : <span className="text-slate-300">—</span>}
                    </Td>
                    <Td><MerchantChip status={m.status} lang={lang} withIcon /></Td>
                    <Td className="hidden lg:table-cell">{o ? <AccountChip status={o.accountStatus} lang={lang} /> : null}</Td>
                    <Td className="hidden xl:table-cell"><RateCell override={m.commissionOverride} fallback={PLATFORM_SETTINGS.pricing.item_commission_rate} lang={lang} /></Td>
                    <Td className="hidden xl:table-cell"><RateCell override={m.driverFeeCutOverride} fallback={PLATFORM_SETTINGS.pricing.driver_fee_cut_rate} lang={lang} /></Td>
                    <Td className="hidden 2xl:table-cell"><span className="text-[12.5px] text-slate-500">{tt(m.pickupDist, lang)}</span></Td>
                    <Td className="hidden lg:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr' }}>{m.created}</span></Td>
                    <Td><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={18} /></span></Td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && (
          <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا يوجد تجّار مطابقون' : 'No matching merchants'}</div>
        )}
      </Card>
    </div>
  );
}

Object.assign(window, { Merchants, RateCell });
