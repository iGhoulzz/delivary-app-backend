// settlements.jsx — Settlements shell: 3 tabs + office scope. Driver queue here;
// Seller payouts and History & audit are separate components.

function ScopeBar({ lang, scope, setScope, staffMode, setStaffMode }) {
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  const opts = [['all', { ar: 'كل المكاتب (مشرف)', en: 'All offices (admin)' }], ...OFFICES.map((o) => [o.id, o.district])];
  return (
    <div className="mb-4 flex flex-wrap items-center gap-2.5 rounded-xl border border-slate-200/80 bg-white/70 px-3.5 py-2.5">
      <span className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-slate-500"><Icon name="building" size={15} />{lang === 'ar' ? 'النطاق' : 'Scope'}</span>
      <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50/80 p-0.5">
        {[['admin', lang === 'ar' ? 'مشرف (عام)' : 'Admin (global)'], ['staff', lang === 'ar' ? 'موظف مكتب' : 'Office staff']].map(([k, label]) => (
          <button key={k} onClick={() => { setStaffMode(k); if (k === 'staff' && scope === 'all') setScope('of-01'); if (k === 'admin') setScope('all'); }}
            className={`rounded-md px-3 py-1 text-[12px] font-semibold transition ${staffMode === k ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{label}</button>
        ))}
      </div>
      {staffMode === 'admin' ? (
        <Dropdown lang={lang} value={scope} setValue={setScope} options={opts} icon="filter" label={lang === 'ar' ? 'المكتب' : 'Office'} />
      ) : (
        <span className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[12.5px] text-slate-600">
          <span className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--accent)' }} />
          {lang === 'ar' ? 'مكتب' : 'Office'}: {officeName(scope) ? tt(officeName(scope), lang) : '—'}
          <span className="text-[11px] text-slate-400">· {lang === 'ar' ? 'مقيّد' : 'locked'}</span>
        </span>
      )}
      <span className="ms-auto text-[11.5px] text-slate-400">{staffMode === 'staff' ? (lang === 'ar' ? 'المعالجة من مكتبك المعيّن فقط' : 'Process from your assigned office only') : (lang === 'ar' ? 'عرض شامل لكل المكاتب' : 'Global view across offices')}</span>
    </div>
  );
}

function TabBtn({ active, onClick, icon, children, count }) {
  return (
    <button onClick={onClick}
      className={`inline-flex items-center gap-2 border-b-2 px-1 pb-2.5 text-[14px] font-semibold transition ${active ? 'text-slate-900' : 'border-transparent text-slate-400 hover:text-slate-600'}`}
      style={active ? { borderColor: 'var(--accent)' } : undefined}>
      <Icon name={icon} size={17} />{children}
      {count != null && <span className={`rounded-full px-1.5 py-px text-[11px] font-bold tabular-nums ${active ? 'text-white' : 'bg-slate-100 text-slate-400'}`} style={active ? { background: 'var(--accent)' } : undefined}>{count}</span>}
    </button>
  );
}

function Settlements({ lang, drivers, users, earnings, settlements, payouts, scope, setScope, staffMode, setStaffMode, onSettle, onPayout, onReverse, onOpenOrder }) {
  const [tab, setTab] = React.useState('drivers');

  const inScope = (office) => scope === 'all' || office === scope;
  const queue = drivers.filter((d) => { const a = d.account; return (a.cash > 0 || a.earnings > 0 || a.debt > 0) && inScope(d.office); });
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  const historyCount = settlements.filter((s) => inScope(s.office) && s.status !== 'correcting').length + payouts.filter((p) => inScope(p.office)).length;

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      <ScopeBar lang={lang} scope={scope} setScope={setScope} staffMode={staffMode} setStaffMode={setStaffMode} />

      <div className="mb-5 flex items-center gap-6 border-b border-slate-200">
        <TabBtn active={tab === 'drivers'} onClick={() => setTab('drivers')} icon="settlements" count={num(queue.length, lang)}>{lang === 'ar' ? 'تسويات السائقين' : 'Driver settlements'}</TabBtn>
        <TabBtn active={tab === 'sellers'} onClick={() => setTab('sellers')} icon="coins">{lang === 'ar' ? 'مدفوعات البائعين' : 'Seller payouts'}</TabBtn>
        <TabBtn active={tab === 'history'} onClick={() => setTab('history')} icon="history" count={num(historyCount, lang)}>{lang === 'ar' ? 'السجل والمراجعة' : 'History & audit'}</TabBtn>
      </div>

      {tab === 'drivers' && (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] border-collapse text-start">
              <thead>
                <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                  <Th className="min-w-[200px]">{lang === 'ar' ? 'السائق' : 'Driver'}</Th>
                  <Th className="hidden lg:table-cell">{lang === 'ar' ? 'المكتب' : 'Office'}</Th>
                  <Th>{lang === 'ar' ? 'نقد بحوزته' : 'Cash held'}</Th>
                  <Th>{lang === 'ar' ? 'حصة السائق' : 'Driver share'}</Th>
                  <Th className="hidden sm:table-cell">{lang === 'ar' ? 'دين السائق' : 'Driver debt'}</Th>
                  <Th>{lang === 'ar' ? 'الصافي' : 'Net'}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {queue.map((d) => {
                  const a = d.account; const net = settleNet(a); const dir = settleDirection(net);
                  const tone = dir === 'driver_to_office' ? '#0d9488' : dir === 'office_to_driver' ? '#d97706' : '#64748b';
                  return (
                    <tr key={d.id} className="text-[13.5px]">
                      <Td className="min-w-[200px]">
                        <div className="flex items-center gap-3">
                          <Avatar name={d.name} lang={lang} size={36} />
                          <div className="min-w-0">
                            <div className="font-semibold text-slate-800" style={{ whiteSpace: 'nowrap' }}>{tt(d.name, lang)}</div>
                            <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr', whiteSpace: 'nowrap' }}>{d.id}</div>
                          </div>
                        </div>
                      </Td>
                      <Td className="hidden lg:table-cell"><span className="text-[13px] text-slate-600">{officeName(d.office) ? tt(officeName(d.office), lang) : '—'}</span></Td>
                      <Td><Money v={a.cash} lang={lang} className="text-[13px] text-slate-700" /></Td>
                      <Td><Money v={a.earnings} lang={lang} className="text-[13px] text-teal-700" /></Td>
                      <Td className="hidden sm:table-cell"><Money v={a.debt} lang={lang} className={`text-[13px] ${a.debt > 0 ? 'text-rose-600' : 'text-slate-400'}`} /></Td>
                      <Td>
                        <div className="flex items-center gap-1.5">
                          <Money v={Math.abs(net)} lang={lang} strong className="text-[13.5px]" />
                          <span title={tt(DIRECTION_LABEL[dir], lang)} style={{ color: tone }}><Icon name={dir === 'office_to_driver' ? 'chevronD' : dir === 'driver_to_office' ? 'route' : 'dot'} size={14} /></span>
                        </div>
                      </Td>
                      <Td>
                        <button onClick={() => onSettle(d.id)} className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[12.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
                          <Icon name="settlements" size={14} />{lang === 'ar' ? 'تسوية' : 'Settle'}
                        </button>
                      </Td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {queue.length === 0 && <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا توجد تسويات معلّقة في هذا النطاق.' : 'No pending settlements in this scope.'}</div>}
        </Card>
      )}

      {tab === 'sellers' && <SellerPayouts lang={lang} users={users} earnings={earnings} payouts={payouts} scope={scope} onPayout={onPayout} onOpenOrder={onOpenOrder} />}
      {tab === 'history' && <SettlementHistory lang={lang} drivers={drivers} settlements={settlements} payouts={payouts} earnings={earnings} scope={scope} onReverse={onReverse} onOpenOrder={onOpenOrder} />}
    </div>
  );
}

Object.assign(window, { Settlements });
