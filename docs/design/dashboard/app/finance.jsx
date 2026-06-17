// finance.jsx — platform revenue visibility. COMPUTED from order snapshots +
// settlement/payout records (no dedicated revenue-ledger endpoint yet).

const RANGES = [
  ['today', { ar: 'اليوم', en: 'Today' }],
  ['7d', { ar: '٧ أيام', en: '7 days' }],
  ['30d', { ar: '٣٠ يوم', en: '30 days' }],
  ['all', { ar: 'الكل', en: 'All' }],
];

function KpiCard({ label, value, sub, tone, icon, note }) {
  const c = tone || '#0f172a';
  return (
    <div className="rounded-2xl border border-slate-200/80 bg-white/70 p-4">
      <div className="flex items-center justify-between">
        <span className="text-[12px] font-medium text-slate-400">{label}</span>
        {icon && <span style={{ color: c }}><Icon name={icon} size={17} /></span>}
      </div>
      <div className="mt-1.5 flex items-baseline gap-1.5">
        <span className="text-[26px] font-bold tabular-nums tracking-tight" style={{ color: c, direction: 'ltr' }}>{value}</span>
        <span className="text-[12px] font-semibold text-slate-400">{note}</span>
      </div>
      {sub && <div className="mt-1 text-[12px] text-slate-500">{sub}</div>}
    </div>
  );
}

// Simple horizontal bar for breakdowns.
function Bar({ label, value, max, lang, color, suffix }) {
  const pct = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;
  return (
    <div className="flex items-center gap-3">
      <span className="w-28 shrink-0 truncate text-[12.5px] text-slate-600">{label}</span>
      <span className="h-5 flex-1 overflow-hidden rounded-md bg-slate-100">
        <span className="block h-full rounded-md" style={{ width: `${pct}%`, background: color || 'var(--accent)' }} />
      </span>
      <span className="w-20 shrink-0 text-end font-mono text-[12.5px] font-semibold text-slate-700 tabular-nums" style={{ direction: 'ltr' }}>{num(Math.round(value), lang)}{suffix}</span>
    </div>
  );
}

function Finance({ lang, settlements, payouts, range, setRange, onOpenOrder }) {
  const orders = revenueOrders().filter((o) => withinRange(orderDate(o), range));
  const accrued = accruedRevenue(orders);
  const cash = cashRealized(settlements, payouts, range);
  const gap = Math.round((accrued.total - cash.total) * 100) / 100;

  // by merchant
  const byMerchant = {};
  orders.forEach((o) => { if (o.type === 'merchant') { const k = tt(o.sender, lang); byMerchant[k] = (byMerchant[k] || 0) + o.rev.platform_revenue; } });
  const merchantRows = Object.entries(byMerchant).sort((a, b) => b[1] - a[1]).slice(0, 6);
  const merchantMax = merchantRows.length ? merchantRows[0][1] : 0;

  // by office (via the order's driver → driver.office)
  const byOffice = {};
  orders.forEach((o) => {
    if (!o.driver) return;
    const drv = DRIVER_RECORDS.find((d) => tt(d.name, 'en') === tt(o.driver, 'en'));
    const off = drv ? OFFICES.find((x) => x.id === drv.office) : null;
    const k = off ? tt(off.district, lang) : (lang === 'ar' ? 'غير معيّن' : 'Unassigned');
    byOffice[k] = (byOffice[k] || 0) + o.rev.platform_revenue;
  });
  const officeRows = Object.entries(byOffice).sort((a, b) => b[1] - a[1]);
  const officeMax = officeRows.length ? officeRows[0][1] : 0;

  // daily trend
  const byDay = {};
  orders.forEach((o) => { const d = orderDate(o); byDay[d] = (byDay[d] || 0) + o.rev.platform_revenue; });
  const days = Object.keys(byDay).sort();
  const dayMax = days.length ? Math.max(...days.map((d) => byDay[d])) : 0;

  const sourceMax = Math.max(accrued.commission, accrued.feeCut, 1);

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      {/* computed banner */}
      <div className="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 px-4 py-3" style={{ background: tint('#d97706', 7) }}>
        <span className="mt-0.5 shrink-0 text-amber-600"><Icon name="alert" size={18} /></span>
        <div className="flex-1 text-[12.5px] leading-relaxed text-amber-800">
          <span className="font-bold">{lang === 'ar' ? 'محسوب — ليس سجلًّا ماليًا نهائيًا.' : 'Computed — not a final ledger.'}</span>{' '}
          {lang === 'ar'
            ? 'هذه الأرقام مُشتقّة من لقطات الطلبات وسجلات التسوية والصرف. إيراد المنصّة = العمولة + حصة المنصّة من رسوم التوصيل. تبقى تقديرية حتى بناء «تقارير المالية» و«سجلّ نقد المكتب» في الخادم.'
            : 'Derived from order snapshots and settlement/payout records. Platform revenue = commission_amount + driver_fee_cut_amount. These remain computed estimates until the Finance Reports / Office Cash Ledger endpoints are built — there is no dedicated platform-revenue ledger yet.'}
        </div>
      </div>

      {/* range selector */}
      <div className="mb-5 flex flex-wrap items-center gap-2.5">
        <span className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-slate-500"><Icon name="calendar" size={15} />{lang === 'ar' ? 'المدى' : 'Range'}</span>
        <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50/80 p-0.5">
          {RANGES.map(([k, label]) => (
            <button key={k} onClick={() => setRange(k)} className={`rounded-md px-3 py-1.5 text-[12.5px] font-semibold transition ${range === k ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{tt(label, lang)}</button>
          ))}
        </div>
        <span className="ms-auto text-[12px] text-slate-400 tabular-nums">{num(orders.length, lang)} {lang === 'ar' ? 'طلب مُدِرّ للإيراد' : 'revenue-bearing orders'}</span>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <KpiCard label={lang === 'ar' ? 'إيراد المنصّة (مُستحق)' : 'Platform revenue (accrued)'} value={num(Math.round(accrued.total), lang)} note={lang === 'ar' ? 'د.ل' : 'LYD'} tone="#0d9488" icon="finance"
          sub={lang === 'ar' ? `عمولة ${num(Math.round(accrued.commission), lang)} + توصيل ${num(Math.round(accrued.feeCut), lang)}` : `commission ${num(Math.round(accrued.commission), lang)} + delivery ${num(Math.round(accrued.feeCut), lang)}`} />
        <KpiCard label={lang === 'ar' ? 'محقّق نقدًا' : 'Cash-realized'} value={num(Math.round(cash.total), lang)} note={lang === 'ar' ? 'د.ل' : 'LYD'} tone="#2563eb" icon="coins"
          sub={lang === 'ar' ? `تسويات ${num(Math.round(cash.settlementCashNet), lang)} − صرف ${num(Math.round(cash.payouts), lang)}` : `settled ${num(Math.round(cash.settlementCashNet), lang)} − payouts ${num(Math.round(cash.payouts), lang)}`} />
        <KpiCard label={lang === 'ar' ? 'فجوة المطابقة' : 'Reconciliation gap'} value={num(Math.round(gap), lang)} note={lang === 'ar' ? 'د.ل' : 'LYD'} tone={gap > 0 ? '#d97706' : '#64748b'} icon="scale"
          sub={lang === 'ar' ? 'مستحق لم يتحوّل لنقد بعد' : 'accrued not yet realized in cash'} />
      </div>

      {/* reconciliation bridge + by source */}
      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-5">
          <div className="mb-4 flex items-center gap-2"><Icon name="scale" size={16} className="text-slate-400" /><span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'الجسر: مستحق ← نقدي' : 'Bridge — accrued → cash'}</span></div>
          <BridgeBar lang={lang} accrued={accrued.total} gap={gap} cash={cash.total} />
        </Card>

        <Card className="p-5">
          <div className="mb-4 flex items-center gap-2"><Icon name="finance" size={16} className="text-slate-400" /><span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'الإيراد حسب المصدر' : 'Revenue by source'}</span></div>
          <div className="space-y-2.5">
            <Bar label={lang === 'ar' ? 'عمولة السلعة' : 'Item commission'} value={accrued.commission} max={sourceMax} lang={lang} color="#0d9488" />
            <Bar label={lang === 'ar' ? 'حصة التوصيل' : 'Delivery cut'} value={accrued.feeCut} max={sourceMax} lang={lang} color="#2563eb" />
          </div>
          <div className="mt-3 border-t border-slate-100 pt-2.5 text-[11.5px] text-slate-400">{lang === 'ar' ? 'العمولة تُحسب على قيمة السلعة (الدفع عند الاستلام)؛ حصة التوصيل = نسبة من رسوم التوصيل.' : 'Commission is on item value (COD proxy); delivery cut is a % of the delivery fee.'}</div>
        </Card>
      </div>

      {/* daily trend */}
      <Card className="mt-4 p-5">
        <div className="mb-4 flex items-center gap-2"><Icon name="trend" size={16} className="text-slate-400" /><span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'الإيراد المستحق اليومي' : 'Daily accrued revenue'}</span></div>
        {days.length ? (
          <div className="flex items-end gap-2" style={{ height: 140 }}>
            {days.map((d) => (
              <div key={d} className="flex flex-1 flex-col items-center gap-1.5">
                <span className="font-mono text-[10.5px] font-semibold text-slate-500 tabular-nums" style={{ direction: 'ltr' }}>{num(Math.round(byDay[d]), lang)}</span>
                <div className="flex w-full items-end justify-center" style={{ height: 96 }}>
                  <div className="w-full max-w-[42px] rounded-t-md transition-all" style={{ height: `${dayMax ? Math.max(4, (byDay[d] / dayMax) * 96) : 4}px`, background: 'var(--accent)' }} />
                </div>
                <span className="font-mono text-[10px] text-slate-400" style={{ direction: 'ltr' }}>{d.slice(5)}</span>
              </div>
            ))}
          </div>
        ) : <div className="py-10 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا بيانات في هذا المدى.' : 'No data in this range.'}</div>}
      </Card>

      {/* by merchant + by office */}
      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-5">
          <div className="mb-4 flex items-center gap-2"><Icon name="merchants" size={16} className="text-slate-400" /><span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'أعلى التجار مساهمةً' : 'Top merchants'}</span></div>
          {merchantRows.length ? <div className="space-y-2.5">{merchantRows.map(([k, v]) => <Bar key={k} label={k} value={v} max={merchantMax} lang={lang} color="#0d9488" />)}</div>
            : <div className="py-8 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا بيانات.' : 'No data.'}</div>}
        </Card>
        <Card className="p-5">
          <div className="mb-4 flex items-center gap-2"><Icon name="building" size={16} className="text-slate-400" /><span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'الإيراد حسب المكتب' : 'Revenue by office'}</span></div>
          {officeRows.length ? <div className="space-y-2.5">{officeRows.map(([k, v]) => <Bar key={k} label={k} value={v} max={officeMax} lang={lang} color="#2563eb" />)}</div>
            : <div className="py-8 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا بيانات.' : 'No data.'}</div>}
        </Card>
      </div>

      {/* recent revenue orders */}
      <Card className="mt-4 overflow-hidden">
        <div className="flex items-center gap-2 border-b border-slate-200/70 px-5 py-3.5">
          <Icon name="orders" size={16} className="text-slate-400" />
          <span className="text-[13px] font-bold text-slate-800">{lang === 'ar' ? 'طلبات مُدِرّة للإيراد' : 'Revenue-bearing orders'}</span>
          <span className="ms-auto text-[12px] text-slate-400">{num(orders.length, lang)}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th>{lang === 'ar' ? 'الطلب' : 'Order'}</Th>
                <Th>{lang === 'ar' ? 'المصدر' : 'Source'}</Th>
                <Th className="hidden sm:table-cell">{lang === 'ar' ? 'قيمة السلعة' : 'Item value'}</Th>
                <Th className="hidden sm:table-cell">{lang === 'ar' ? 'العمولة' : 'Commission'}</Th>
                <Th className="hidden md:table-cell">{lang === 'ar' ? 'حصة التوصيل' : 'Delivery cut'}</Th>
                <Th>{lang === 'ar' ? 'إيراد المنصّة' : 'Platform rev.'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {orders.slice().sort((a, b) => (a.created < b.created ? 1 : -1)).slice(0, 12).map((o) => (
                <tr key={o.id} onClick={() => onOpenOrder && onOpenOrder(o.id)} className="cursor-pointer text-[13.5px] transition hover:bg-slate-50/70">
                  <Td><span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span></Td>
                  <Td><span className="truncate text-[12.5px] text-slate-600">{o.type === 'merchant' ? tt(o.sender, lang) : (lang === 'ar' ? 'طرد فردي' : 'P2P parcel')}</span></Td>
                  <Td className="hidden sm:table-cell"><Money v={o.rev.itemPrice} lang={lang} className="text-[12.5px] text-slate-500" /></Td>
                  <Td className="hidden sm:table-cell"><Money v={o.rev.commission_amount} lang={lang} className="text-[12.5px] text-teal-700" /></Td>
                  <Td className="hidden md:table-cell"><Money v={o.rev.driver_fee_cut_amount} lang={lang} className="text-[12.5px] text-blue-700" /></Td>
                  <Td><Money v={o.rev.platform_revenue} lang={lang} strong className="text-[13px]" /></Td>
                  <Td><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={16} /></span></Td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

// accrued → gap → cash, as proportional stacked bar.
function BridgeBar({ lang, accrued, gap, cash }) {
  const total = Math.max(accrued, 1);
  const cashPct = Math.max(0, Math.min(100, Math.round((cash / total) * 100)));
  const gapPct = Math.max(0, 100 - cashPct);
  return (
    <div>
      <div className="flex h-9 overflow-hidden rounded-lg">
        <div className="flex items-center justify-center text-[11.5px] font-bold text-white" style={{ width: `${cashPct}%`, background: '#2563eb', minWidth: cash > 0 ? 40 : 0 }}>{cashPct >= 12 ? num(Math.round(cash), lang) : ''}</div>
        <div className="flex items-center justify-center text-[11.5px] font-bold" style={{ width: `${gapPct}%`, background: tint('#d97706', 22), color: '#b45309', minWidth: gap > 0 ? 40 : 0 }}>{gapPct >= 12 ? num(Math.round(gap), lang) : ''}</div>
      </div>
      <div className="mt-3 space-y-1.5 text-[12px]">
        <div className="flex items-center justify-between"><span className="inline-flex items-center gap-1.5 text-slate-500"><span className="h-2.5 w-2.5 rounded-sm bg-slate-300" />{lang === 'ar' ? 'الإيراد المستحق' : 'Accrued revenue'}</span><span className="font-mono font-semibold text-slate-700" style={{ direction: 'ltr' }}>{num(Math.round(accrued), lang)}</span></div>
        <div className="flex items-center justify-between"><span className="inline-flex items-center gap-1.5 text-slate-500"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: '#2563eb' }} />{lang === 'ar' ? 'محقّق نقدًا' : 'Cash-realized'}</span><span className="font-mono font-semibold text-blue-700" style={{ direction: 'ltr' }}>{num(Math.round(cash), lang)}</span></div>
        <div className="flex items-center justify-between"><span className="inline-flex items-center gap-1.5 text-slate-500"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: tint('#d97706', 40) }} />{lang === 'ar' ? 'الفجوة (توقيت)' : 'Gap (timing)'}</span><span className="font-mono font-semibold text-amber-700" style={{ direction: 'ltr' }}>{num(Math.round(gap), lang)}</span></div>
      </div>
      <p className="mt-3 border-t border-slate-100 pt-2.5 text-[11.5px] leading-relaxed text-slate-400">{lang === 'ar' ? 'الفجوة تمثّل إيرادًا مستحقًا لم يتحوّل إلى نقد بعد — أرباح بانتظار المقاصّة، أو سائقون لم تُسوَّ حساباتهم.' : 'The gap is accrued revenue not yet converted to cash — earnings pending clearance, or drivers not yet settled.'}</p>
    </div>
  );
}

Object.assign(window, { Finance });
