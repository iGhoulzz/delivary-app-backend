// settlementSeller.jsx — seller/merchant cash payout: lookup, 4-stage lifecycle,
// select available earnings, exact-total + minimum verify, payout receipt.

function SellerPayouts({ lang, users, earnings, payouts, scope, onPayout, onOpenOrder }) {
  const [q, setQ] = React.useState('');
  const [sellerId, setSellerId] = React.useState(null);
  const [checked, setChecked] = React.useState({});
  const [receipt, setReceipt] = React.useState(null);

  // sellers = users who have at least one seller earning
  const sellerIds = [...new Set(earnings.map((e) => e.sellerUserId))];
  const sellers = sellerIds.map((id) => userById(id)).filter(Boolean);
  const pool = sellers.filter((u) => {
    if (!q.trim()) return true;
    const hay = [u.id, u.phone, tt(u.name, lang), tt(u.name, 'en'), tt(sellerLabel(u.id), lang), tt(sellerLabel(u.id), 'en')].join(' ').toLowerCase();
    return hay.includes(q.trim().toLowerCase());
  });

  const seller = sellerId ? userById(sellerId) : null;
  const sellerEarn = seller ? earningsForSeller(seller.id, earnings) : [];
  const available = sellerEarn.filter((e) => e.status === 'available');
  const selectedIds = Object.keys(checked).filter((k) => checked[k]);
  const selectedTotal = available.filter((e) => selectedIds.includes(e.id)).reduce((s, e) => s + e.amount, 0);
  const belowMin = selectedTotal > 0 && selectedTotal < PAYOUT_MIN;
  const canPay = selectedIds.length > 0 && !belowMin;

  function pickSeller(id) { setSellerId(id); setChecked({}); setReceipt(null); }
  function toggle(id) { setChecked((p) => ({ ...p, [id]: !p[id] })); }
  function pay() {
    if (!canPay) return;
    const rec = onPayout(seller.id, selectedIds, selectedTotal);
    setReceipt(rec); setChecked({});
  }

  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };

  // ── receipt view ──
  if (receipt) {
    return (
      <Card className="mx-auto max-w-[560px] overflow-hidden">
        <div className="border-b border-slate-200/70 px-6 py-4">
          <div className="flex items-center justify-center"><span className="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[12.5px] font-bold" style={{ background: tint('#7c3aed', 14), color: '#7c3aed' }}><Icon name="checkCircle" size={16} />{lang === 'ar' ? 'تمّ الصرف' : 'Payout complete'}</span></div>
        </div>
        <div className="px-6 py-5">
          <div className="rounded-2xl border border-slate-200/80 bg-white/60 px-4 py-3">
            <ReceiptRow label={lang === 'ar' ? 'رقم الصرف' : 'Payout ID'}><span className="font-mono" style={{ direction: 'ltr' }}>{receipt.id}</span></ReceiptRow>
            <ReceiptRow label={lang === 'ar' ? 'البائع' : 'Seller'}>{tt(sellerLabel(receipt.sellerUserId), lang)}</ReceiptRow>
            <ReceiptRow label={lang === 'ar' ? 'الطريقة' : 'Method'}>{lang === 'ar' ? 'نقدًا في المكتب' : 'Cash at office'}</ReceiptRow>
            <ReceiptRow label={lang === 'ar' ? 'الموظف' : 'Paid by'}>{tt(receipt.staff, lang)}</ReceiptRow>
            <ReceiptRow label={lang === 'ar' ? 'الوقت' : 'Time'}><span className="font-mono text-[12px]" style={{ direction: 'ltr' }}>{receipt.paidAt}</span></ReceiptRow>
            <div className="my-2 border-t border-dashed border-slate-200" />
            {receipt.earnings.map((eid) => { const e = earnings.find((x) => x.id === eid) || SELLER_EARNINGS.find((x) => x.id === eid); return (
              <ReceiptRow key={eid} label={<span className="font-mono text-[12px]" style={{ direction: 'ltr' }}>{eid}</span>}><Money v={e ? e.amount : 0} lang={lang} /></ReceiptRow>
            ); })}
            <div className="my-2 border-t border-dashed border-slate-200" />
            <ReceiptRow label={lang === 'ar' ? 'الإجمالي المدفوع' : 'Total paid'} strong><Money v={receipt.total} lang={lang} strong /></ReceiptRow>
          </div>
          <button onClick={() => setReceipt(null)} className="mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}><Icon name="check" size={17} />{lang === 'ar' ? 'تم' : 'Done'}</button>
        </div>
      </Card>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[340px_1fr]">
      {/* lookup */}
      <Card className="overflow-hidden">
        <div className="border-b border-slate-200/70 p-3">
          <div className="relative">
            <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={17} /></div>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={lang === 'ar' ? 'بحث بالهاتف أو المعرّف…' : 'Search phone or public ID…'}
              className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[13.5px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
          </div>
        </div>
        <div className="max-h-[460px] space-y-1 overflow-y-auto p-2">
          {pool.map((u) => {
            const avail = earningsForSeller(u.id, earnings).filter((e) => e.status === 'available').reduce((s, e) => s + e.amount, 0);
            return (
              <button key={u.id} onClick={() => pickSeller(u.id)}
                className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-start transition ${sellerId === u.id ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}>
                <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg" style={{ background: tint('#0d9488', 12), color: '#0d9488' }}><Icon name="merchants" size={17} /></div>
                <div className="min-w-0 flex-1">
                  <div className="truncate text-[13px] font-semibold text-slate-800">{tt(sellerLabel(u.id), lang)}</div>
                  <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr' }}>{u.phone}</div>
                </div>
                {avail > 0 && <span className="shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-bold" style={{ background: tint('#16a34a', 14), color: '#16a34a' }}><Money v={avail} lang={lang} /></span>}
              </button>
            );
          })}
          {pool.length === 0 && <div className="py-10 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا نتائج' : 'No matches'}</div>}
        </div>
      </Card>

      {/* earnings + payout */}
      <Card className="flex flex-col overflow-hidden">
        {!seller ? (
          <div className="grid flex-1 place-items-center px-6 py-20 text-center">
            <div>
              <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-300"><Icon name="coins" size={26} /></span>
              <p className="mt-4 text-[14px] text-slate-400">{lang === 'ar' ? 'ابحث عن بائع لعرض أرباحه القابلة للصرف.' : 'Look up a seller to view payable earnings.'}</p>
            </div>
          </div>
        ) : (
          <>
            <div className="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4">
              <div className="grid h-11 w-11 place-items-center rounded-xl" style={{ background: tint('#0d9488', 12), color: '#0d9488' }}><Icon name="merchants" size={21} /></div>
              <div className="min-w-0 flex-1">
                <div className="font-bold text-slate-900">{tt(sellerLabel(seller.id), lang)}</div>
                <div className="flex items-center gap-2 text-[12px] text-slate-500"><span className="font-mono" style={{ direction: 'ltr' }}>{seller.phone}</span><span className="text-slate-300">·</span><span className="font-mono text-[11px] text-slate-400" style={{ direction: 'ltr' }}>{seller.id}</span></div>
              </div>
            </div>

            {/* lifecycle legend */}
            <div className="border-b border-slate-200/70 px-5 py-3">
              <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'مسار الأرباح' : 'Earnings lifecycle'}</div>
              <LifecyclePipeline current={null} lang={lang} />
            </div>

            {/* earnings list */}
            <div className="flex-1 space-y-1.5 overflow-y-auto px-4 py-3">
              {sellerEarn.length === 0 && <div className="py-10 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد أرباح.' : 'No earnings.'}</div>}
              {sellerEarn.map((e) => {
                const isAvail = e.status === 'available';
                const on = !!checked[e.id];
                return (
                  <div key={e.id} className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 transition ${on ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-slate-200/80 bg-white/60'}`}>
                    {isAvail ? (
                      <input type="checkbox" checked={on} onChange={() => toggle(e.id)} className="h-4 w-4 shrink-0 accent-[var(--accent)]" />
                    ) : <span className="h-4 w-4 shrink-0" />}
                    <button onClick={() => onOpenOrder && onOpenOrder(e.orderId)} className="font-mono text-[12.5px] font-semibold text-slate-800 hover:underline" style={{ direction: 'ltr' }}>{e.orderId}</button>
                    <div className="min-w-0 flex-1">
                      <div className="hidden sm:block"><LifecyclePipeline current={e.status} lang={lang} compact /></div>
                      <div className="sm:hidden"><EarningBadge status={e.status} lang={lang} sm /></div>
                      {e.status === 'pending_clearance' && e.clearAt && <div className="mt-0.5 text-[10.5px] text-amber-600" style={{ direction: 'ltr' }}>{lang === 'ar' ? 'تُتاح' : 'clears'} {e.clearAt}</div>}
                    </div>
                    <Money v={e.amount} lang={lang} strong className="text-[14px]" />
                  </div>
                );
              })}
            </div>

            {/* payout bar */}
            <div className="shrink-0 border-t border-slate-200/70 px-5 py-3.5">
              {available.length === 0 ? (
                <div className="text-center text-[12.5px] text-slate-400">{lang === 'ar' ? 'لا توجد أرباح متاحة للصرف حاليًا.' : 'No available earnings to pay out right now.'}</div>
              ) : (
                <>
                  <div className="mb-2.5 flex items-center justify-between">
                    <span className="text-[12.5px] text-slate-500">{num(selectedIds.length, lang)} {lang === 'ar' ? 'محدّد' : 'selected'} · {lang === 'ar' ? 'نقدًا في المكتب' : 'cash at office'}</span>
                    <span className="text-[15px] font-bold text-slate-900"><Money v={selectedTotal} lang={lang} strong /></span>
                  </div>
                  {belowMin && <div className="mb-2 rounded-lg border border-amber-200 px-3 py-1.5 text-[11.5px] text-amber-700" style={{ background: tint('#d97706', 8) }}>{lang === 'ar' ? `الحد الأدنى للصرف ${num(PAYOUT_MIN, lang)} د.ل.` : `Minimum payout is ${num(PAYOUT_MIN, lang)} LYD.`}</div>}
                  <button onClick={pay} disabled={!canPay}
                    className={`inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg text-[13.5px] font-semibold text-white shadow-sm transition ${canPay ? '' : 'cursor-not-allowed opacity-40'}`} style={{ background: 'var(--accent)' }}>
                    <Icon name="send" size={16} />{lang === 'ar' ? 'صرف نقدي' : 'Pay out in cash'}
                  </button>
                </>
              )}
            </div>
          </>
        )}
      </Card>
    </div>
  );
}

Object.assign(window, { SellerPayouts });
