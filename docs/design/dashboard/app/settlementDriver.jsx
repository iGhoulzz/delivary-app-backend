// settlementDriver.jsx — driver settlement: bucket preview, net/direction,
// excess-cash guard, shortage→debt, staff/driver agreement, atomic process, receipt.

function ReceiptRow({ label, children, strong }) {
  return (
    <div className="flex items-center justify-between py-1.5 text-[13px]">
      <span className="text-slate-500">{label}</span>
      <span className={strong ? 'font-bold text-slate-900' : 'font-semibold text-slate-700'}>{children}</span>
    </div>
  );
}

// Bucket clearing row: shows balance moving from → to (e.g. 240 → 0).
function ClearRow({ label, from, to, lang, tone }) {
  return (
    <div className="flex items-center justify-between py-1.5 text-[13px]">
      <span className="text-slate-500">{label}</span>
      <span className="flex items-center gap-1.5 font-mono tabular-nums" style={{ direction: 'ltr' }}>
        <span className="text-slate-400">{num(from, lang)}</span>
        <span className="text-slate-300">→</span>
        <span className="font-bold" style={{ color: tone || (to === 0 ? '#0d9488' : '#0f172a') }}>{num(to, lang)}</span>
      </span>
    </div>
  );
}

function DriverSettleModal({ driverId, drivers, lang, office, staff, pendingLinkedList, onClose, onSettle }) {
  const d = drivers.find((x) => x.id === driverId) || null;
  const a = d ? d.account : null;
  const net = a ? settleNet(a) : 0;
  const dir = settleDirection(net);
  const linkedCount = (pendingLinkedList || []).length;
  const linkedTotal = (pendingLinkedList || []).reduce((s, e) => s + e.amount, 0);
  const [received, setReceived] = React.useState(net > 0 ? net : 0);
  const [agree, setAgree] = React.useState(false);
  const [receipt, setReceipt] = React.useState(null);

  React.useEffect(() => { if (a) { setReceived(net > 0 ? net : 0); setAgree(false); setReceipt(null); } }, [driverId]);
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);
  if (!driverId || !d) return null;

  const officeName = (() => { const o = OFFICES.find((x) => x.id === office); return o ? o.district : null; })();
  const excess = dir === 'driver_to_office' && received > net;        // must hand back, block submit
  const shortage = dir === 'driver_to_office' && received < net ? net - received : 0;
  const canSubmit = agree && !excess && received >= 0;

  function submit() {
    if (!canSubmit) return;
    const rec = onSettle(d.id, { net, direction: dir, cashReceived: dir === 'driver_to_office' ? received : 0, shortage });
    setReceipt(rec);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40" style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />
      <div className="app-card relative flex max-h-[90vh] w-full max-w-[560px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        {/* header */}
        <div className="relative shrink-0 border-b border-slate-200/70 px-6 pt-5 pb-4">
          <button onClick={onClose} className="absolute end-5 top-5 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
          <div className="flex items-center gap-3">
            <Avatar name={d.name} lang={lang} size={44} />
            <div>
              <h2 className="text-[18px] font-bold tracking-tight text-slate-900">{receipt ? (lang === 'ar' ? 'إيصال التسوية' : 'Settlement receipt') : (lang === 'ar' ? 'تسوية السائق' : 'Driver settlement')}</h2>
              <div className="flex items-center gap-2 text-[12px] text-slate-500"><span>{tt(d.name, lang)}</span><span className="text-slate-300">·</span><span className="inline-flex items-center gap-1"><Icon name="building" size={12} />{officeName ? tt(officeName, lang) : '—'}</span></div>
            </div>
          </div>
        </div>

        {!receipt ? (
          <>
            <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
              {/* buckets */}
              <div>
                <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'الأرصدة الحالية' : 'Current buckets'}</div>
                <BucketTriad b={a} lang={lang} />
              </div>

              {/* net + direction */}
              <NetDisplay net={net} lang={lang} size="lg" />

              {/* cash handling */}
              {dir === 'driver_to_office' && (
                <div>
                  <label className="mb-1 block text-[12px] font-semibold text-slate-500">{lang === 'ar' ? 'النقد المستلَم من السائق' : 'Cash counted from driver'}</label>
                  <div className="flex items-center gap-2">
                    <div className="relative flex-1">
                      <input type="number" value={received} onChange={(e) => setReceived(Math.max(0, +e.target.value))}
                        className="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-[15px] font-semibold text-slate-800 outline-none transition focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" style={{ direction: 'ltr' }} />
                      <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[12px] font-medium text-slate-400">{lang === 'ar' ? 'د.ل' : 'LYD'}</span>
                    </div>
                    <button onClick={() => setReceived(net)} className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-[12px] font-semibold text-slate-500 transition hover:bg-slate-50">{lang === 'ar' ? 'المبلغ المستحق' : 'Amount due'}</button>
                  </div>
                  {excess && (
                    <div className="mt-2 flex items-start gap-2 rounded-lg border border-rose-200 px-3 py-2 text-[12px] text-rose-600" style={{ background: tint('#e11d48', 7) }}>
                      <span className="mt-0.5 shrink-0"><Icon name="alert" size={15} /></span>
                      <span>{lang === 'ar' ? `المبلغ يتجاوز المستحق. أعِد ${num(received - net, lang)} د.ل للسائق قبل التأكيد — لا يُقبل فائض.` : `Exceeds amount due. Hand back ${num(received - net, lang)} LYD before submitting — excess is rejected.`}</span>
                    </div>
                  )}
                  {shortage > 0 && (
                    <div className="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 px-3 py-2 text-[12px] text-amber-700" style={{ background: tint('#d97706', 8) }}>
                      <span className="mt-0.5 shrink-0"><Icon name="alert" size={15} /></span>
                      <span>{lang === 'ar' ? `عجز قدره ${num(shortage, lang)} د.ل سيُضاف إلى دين السائق.` : `Shortage of ${num(shortage, lang)} LYD will be recorded to the driver's debt.`}</span>
                    </div>
                  )}
                </div>
              )}
              {dir === 'office_to_driver' && (
                <div className="rounded-lg border border-amber-200 px-3.5 py-3 text-[13px] text-amber-700" style={{ background: tint('#d97706', 8) }}>
                  <div className="font-semibold">{lang === 'ar' ? 'دفع نقدي للسائق' : 'Cash paid to driver'}</div>
                  <div className="mt-0.5 text-[12px] text-amber-700/90">{lang === 'ar' ? `يدفع المكتب ${num(Math.abs(net), lang)} د.ل نقدًا للسائق لتصفية حصته.` : `Office pays the driver ${num(Math.abs(net), lang)} LYD in cash to clear their share.`}</div>
                </div>
              )}
              {dir === 'zero' && (
                <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-3 text-[13px] text-slate-600">{lang === 'ar' ? 'لا تبادل نقدي — التسوية تصفّي الأرصدة فقط.' : 'No cash changes hands — the settlement just clears the balances.'}</div>
              )}

              {/* linked seller/merchant earnings preview */}
              {linkedCount > 0 && (
                <div className="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white/60 px-3.5 py-2.5 text-[12.5px] text-slate-600">
                  <span className="text-teal-600"><Icon name="coins" size={16} /></span>
                  <span className="flex-1">{lang === 'ar' ? `تنتقل ${num(linkedCount, lang)} من أرباح البائعين إلى «بانتظار المقاصّة».` : `${num(linkedCount, lang)} seller/merchant earning(s) move to “pending clearance”.`}</span>
                  <Money v={linkedTotal} lang={lang} strong className="text-[13px] text-teal-700" />
                </div>
              )}

              {/* agreement */}
              <label className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 bg-white/70 px-3.5 py-3">
                <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--accent)]" />
                <span className="text-[12.5px] leading-relaxed text-slate-600">{lang === 'ar' ? 'أقرّ بأن الموظف والسائق متفقان على المبلغ المعدود. عند الخلاف لا تُرسَل التسوية.' : 'Staff and driver agree on the counted amount. If you disagree, do not submit — no record is created.'}</span>
              </label>
            </div>

            <div className="flex shrink-0 items-center justify-end gap-2.5 border-t border-slate-200/70 px-6 py-4">
              <button onClick={onClose} className="h-10 rounded-lg px-4 text-[13.5px] font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
              <button onClick={submit} disabled={!canSubmit}
                className={`inline-flex h-10 items-center gap-2 rounded-lg px-4 text-[13.5px] font-semibold text-white shadow-sm transition ${canSubmit ? '' : 'cursor-not-allowed opacity-40'}`} style={{ background: 'var(--accent)' }}>
                <Icon name="checkCircle" size={17} />{lang === 'ar' ? 'تنفيذ التسوية' : 'Process settlement'}
              </button>
            </div>
          </>
        ) : (
          /* ── receipt ── */
          <>
            <div className="flex-1 overflow-y-auto px-6 py-5">
              <div className="mb-3 flex items-center justify-center">
                <span className="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[12.5px] font-bold" style={{ background: tint('#0d9488', 14), color: '#0d9488' }}><Icon name="checkCircle" size={16} />{lang === 'ar' ? 'تمّت التسوية' : 'Settlement processed'}</span>
              </div>
              <div className="rounded-2xl border border-slate-200/80 bg-white/60 px-4 py-3">
                <ReceiptRow label={lang === 'ar' ? 'رقم التسوية' : 'Settlement ID'}><span className="font-mono" style={{ direction: 'ltr' }}>{receipt.id}</span></ReceiptRow>
                <ReceiptRow label={lang === 'ar' ? 'السائق' : 'Driver'}>{tt(d.name, lang)}</ReceiptRow>
                <ReceiptRow label={lang === 'ar' ? 'المكتب' : 'Office'}>{officeName ? tt(officeName, lang) : '—'}</ReceiptRow>
                <ReceiptRow label={lang === 'ar' ? 'الموظف' : 'Staff actor'}>{tt(staff, lang)}</ReceiptRow>
                <ReceiptRow label={lang === 'ar' ? 'الوقت' : 'Timestamp'}><span className="font-mono text-[12px]" style={{ direction: 'ltr' }}>{receipt.processedAt}</span></ReceiptRow>

                <div className="my-2.5 border-t border-dashed border-slate-200" />
                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'تصفية الأرصدة' : 'Buckets cleared'}</div>
                <ClearRow label={tt(BUCKETS.cash_to_deposit, lang)} from={receipt.before.cash} to={0} lang={lang} />
                <ClearRow label={tt(BUCKETS.earnings_balance, lang)} from={receipt.before.earnings} to={0} lang={lang} />
                <ClearRow label={tt(BUCKETS.debt_balance, lang)} from={receipt.before.debt} to={receipt.shortage || 0} lang={lang} tone={receipt.shortage > 0 ? '#e11d48' : undefined} />

                <div className="my-2.5 border-t border-dashed border-slate-200" />
                <ReceiptRow label={lang === 'ar' ? 'الاتجاه' : 'Direction'}>{tt(DIRECTION_LABEL[receipt.direction], lang)}</ReceiptRow>
                {receipt.direction === 'driver_to_office' && <ReceiptRow label={lang === 'ar' ? 'نقد مستلَم من السائق' : 'Cash received from driver'}><Money v={receipt.cashReceivedFromDriver} lang={lang} /></ReceiptRow>}
                {receipt.direction === 'office_to_driver' && <ReceiptRow label={lang === 'ar' ? 'نقد مدفوع للسائق' : 'Cash paid to driver'}><Money v={receipt.cashPaidToDriver} lang={lang} /></ReceiptRow>}
                {receipt.shortage > 0 && <ReceiptRow label={lang === 'ar' ? 'عجز أُضيف إلى الدين' : 'Shortage created → debt'}><span className="text-rose-600"><Money v={receipt.shortage} lang={lang} /></span></ReceiptRow>}
                <ReceiptRow label={lang === 'ar' ? 'الحركة النقدية النهائية' : 'Final cash movement'} strong><Money v={Math.abs(receipt.net)} lang={lang} strong /></ReceiptRow>

                <div className="my-2.5 border-t border-dashed border-slate-200" />
                {receipt.linkedEarnings && receipt.linkedEarnings.length > 0 ? (
                  <div className="flex items-center justify-between gap-2 rounded-lg px-2.5 py-2" style={{ background: tint('#0d9488', 8) }}>
                    <span className="inline-flex items-center gap-1.5 text-[12px] font-medium text-teal-800"><Icon name="coins" size={14} />{lang === 'ar' ? `${num(receipt.linkedEarnings.length, lang)} ربح بائع/تاجر → بانتظار المقاصّة` : `${num(receipt.linkedEarnings.length, lang)} seller/merchant earning(s) → pending clearance`}</span>
                    <Money v={receipt.linkedEarningsTotal || 0} lang={lang} strong className="text-[12.5px] text-teal-700" />
                  </div>
                ) : (
                  <ReceiptRow label={lang === 'ar' ? 'أرباح بائعين مرتبطة' : 'Linked seller/merchant earnings'}><span className="text-slate-400">{lang === 'ar' ? 'لا يوجد' : 'None'}</span></ReceiptRow>
                )}
                <div className="mt-2.5 rounded-lg bg-slate-50/80 px-3 py-2 text-[11px] text-slate-400">{lang === 'ar' ? 'البنود الثلاثة صُفّيت معًا في إجراء واحد. يمكن عكس التسوية ما دامت الأرباح المرتبطة بانتظار المقاصّة.' : 'All three buckets were cleared together in one atomic action. Reversible while linked earnings remain in pending clearance.'}</div>
              </div>
            </div>
            <div className="flex shrink-0 items-center justify-end border-t border-slate-200/70 px-6 py-4">
              <button onClick={onClose} className="inline-flex h-10 items-center gap-2 rounded-lg px-4 text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}><Icon name="check" size={17} />{lang === 'ar' ? 'تم' : 'Done'}</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

Object.assign(window, { DriverSettleModal });
