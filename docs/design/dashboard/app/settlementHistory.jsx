// settlementHistory.jsx — settlement receipts + payout receipts + admin reversal
// (only while linked earnings are still pending_clearance) with correcting audit trail.

function reversible(stl, earnings) {
  if (stl.status !== 'processed') return { ok: false, reason: 'not_processed' };
  const linked = (stl.linkedEarnings || []).map((id) => earnings.find((e) => e.id === id)).filter(Boolean);
  const blocked = linked.find((e) => e.status !== 'pending_clearance');
  if (blocked) return { ok: false, reason: 'cleared' };
  return { ok: true };
}

function SettlementRow({ stl, drivers, settlements, earnings, lang, onReverse }) {
  const [open, setOpen] = React.useState(false);
  const [confirm, setConfirm] = React.useState(false);
  const d = drivers.find((x) => x.id === stl.driverId);
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  const rev = reversible(stl, earnings);
  const correcting = stl.correctingId ? settlements.find((s) => s.id === stl.correctingId) : null;

  return (
    <div className={`rounded-xl border ${stl.status === 'cancelled' ? 'border-slate-200 bg-slate-50/40' : 'border-slate-200/80 bg-white/60'}`}>
      <button onClick={() => setOpen(!open)} className="flex w-full items-center gap-3 px-3.5 py-2.5 text-start">
        <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{stl.id}</span>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="truncate text-[13px] font-semibold text-slate-700">{d ? tt(d.name, lang) : stl.driverId}</span>
            <SettlementStatusBadge status={stl.status} lang={lang} />
          </div>
          <div className="flex items-center gap-2 text-[11.5px] text-slate-400">
            <span className="inline-flex items-center gap-1"><Icon name="building" size={11} />{officeName(stl.office) ? tt(officeName(stl.office), lang) : '—'}</span>
            <span className="text-slate-300">·</span>
            <span className="font-mono" style={{ direction: 'ltr' }}>{stl.processedAt}</span>
          </div>
        </div>
        <div className="text-end">
          <Money v={Math.abs(stl.net)} lang={lang} strong className="text-[13.5px]" />
          <div className="text-[10.5px] text-slate-400">{stl.direction === 'office_to_driver' ? (lang === 'ar' ? 'للسائق' : 'to driver') : stl.direction === 'driver_to_office' ? (lang === 'ar' ? 'للمكتب' : 'to office') : '—'}</div>
        </div>
        <span className="text-slate-300"><Icon name={open ? 'chevronD' : (lang === 'ar' ? 'chevronL' : 'chevronR')} size={16} /></span>
      </button>

      {open && (
        <div className="border-t border-slate-200/70 px-3.5 py-3">
          <div className="grid grid-cols-3 gap-2 text-center">
            {['cash', 'earnings', 'debt'].map((k, i) => (
              <div key={k} className="rounded-lg bg-white/70 py-2">
                <div className="text-[10.5px] text-slate-400">{tt(BUCKETS[['cash_to_deposit', 'earnings_balance', 'debt_balance'][i]].short, lang)}</div>
                <div className="text-[13px] font-bold text-slate-800">{stl.before ? num(stl.before[k], lang) : '—'}</div>
              </div>
            ))}
          </div>
          {stl.shortage > 0 && <div className="mt-2 text-[12px] text-rose-600">{lang === 'ar' ? `عجز ${num(stl.shortage, lang)} د.ل أُضيف للدين` : `Shortage ${num(stl.shortage, lang)} LYD added to debt`}</div>}
          {(stl.linkedEarnings || []).length > 0 && (
            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11.5px] text-slate-500">
              <span>{lang === 'ar' ? 'أرباح مرتبطة:' : 'Linked earnings:'}</span>
              {stl.linkedEarnings.map((id) => { const e = earnings.find((x) => x.id === id); return (
                <span key={id} className="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 font-mono text-[10.5px] ring-1 ring-slate-200" style={{ direction: 'ltr' }}>{id} {e && <EarningBadge status={e.status} lang={lang} sm />}</span>
              ); })}
            </div>
          )}

          {/* audit trail for a reversed settlement */}
          {correcting && (
            <div className="mt-3 rounded-lg border border-violet-200 px-3 py-2.5" style={{ background: tint('#7c3aed', 7) }}>
              <div className="flex items-center gap-1.5 text-[12px] font-semibold text-violet-700"><Icon name="undo" size={14} />{lang === 'ar' ? 'سجل العكس' : 'Reversal trail'}</div>
              <div className="mt-1.5 space-y-1 text-[11.5px] text-slate-600">
                <div className="flex items-center justify-between"><span>{lang === 'ar' ? 'التسوية الأصلية' : 'Original settlement'}</span><span className="font-mono" style={{ direction: 'ltr' }}>{stl.id} · {lang === 'ar' ? 'ملغاة' : 'cancelled'}</span></div>
                <div className="flex items-center justify-between"><span>{lang === 'ar' ? 'التسوية التصحيحية' : 'Correcting settlement'}</span><span className="font-mono" style={{ direction: 'ltr' }}>{correcting.id}</span></div>
                <div className="flex items-center justify-between"><span>{lang === 'ar' ? 'الأرصدة المُعادة' : 'Buckets restored'}</span><span>{correcting.restored ? `${num(correcting.restored.cash, lang)} / ${num(correcting.restored.earnings, lang)} / ${num(correcting.restored.debt, lang)}` : '—'}</span></div>
                <div className="text-[10.5px] text-slate-400">{lang === 'ar' ? 'أُعيدت الأرباح المرتبطة إلى «بانتظار التسوية».' : 'Linked earnings returned to “pending settlement”.'}</div>
              </div>
            </div>
          )}

          {/* reversal control */}
          {stl.status === 'processed' && (
            <div className="mt-3">
              {rev.ok ? (
                confirm ? (
                  <div className="flex items-center gap-2 rounded-lg border border-rose-200 px-3 py-2" style={{ background: tint('#e11d48', 6) }}>
                    <span className="text-[12px] text-rose-700">{lang === 'ar' ? 'عكس هذه التسوية واستعادة الأرصدة؟' : 'Reverse this settlement and restore buckets?'}</span>
                    <div className="ms-auto flex gap-2">
                      <button onClick={() => setConfirm(false)} className="h-8 rounded-md px-2.5 text-[12px] font-semibold text-slate-500 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                      <button onClick={() => { onReverse(stl.id); setConfirm(false); }} className="inline-flex h-8 items-center gap-1.5 rounded-md bg-rose-600 px-3 text-[12px] font-semibold text-white hover:bg-rose-700"><Icon name="undo" size={13} />{lang === 'ar' ? 'تأكيد العكس' : 'Confirm reversal'}</button>
                    </div>
                  </div>
                ) : (
                  <button onClick={() => setConfirm(true)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 text-[12px] font-semibold text-rose-600 transition hover:bg-rose-50"><Icon name="undo" size={14} />{lang === 'ar' ? 'عكس التسوية' : 'Reverse settlement'}</button>
                )
              ) : (
                <div className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-[11.5px] font-medium text-slate-400">
                  <Icon name="ban" size={13} />{lang === 'ar' ? 'العكس محظور — الأرباح المرتبطة تجاوزت مرحلة المقاصّة' : 'Reversal blocked — linked earnings already cleared'}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function SettlementHistory({ lang, drivers, settlements, payouts, earnings, scope, onReverse, onOpenOrder }) {
  const inScope = (office) => scope === 'all' || office === scope;
  const stls = settlements.filter((s) => s.status !== 'correcting' && inScope(s.office)).sort((a, b) => (a.processedAt < b.processedAt ? 1 : -1));
  const pays = payouts.filter((p) => inScope(p.office)).sort((a, b) => (a.paidAt < b.paidAt ? 1 : -1));
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      {/* settlement receipts */}
      <Card className="overflow-hidden">
        <div className="flex items-center gap-2 border-b border-slate-200/70 px-5 py-3.5">
          <Icon name="settlements" size={17} className="text-slate-400" />
          <span className="text-[14px] font-bold text-slate-800">{lang === 'ar' ? 'تسويات السائقين' : 'Driver settlements'}</span>
          <span className="ms-auto text-[12px] text-slate-400">{num(stls.length, lang)}</span>
        </div>
        <div className="space-y-2 p-3">
          {stls.map((s) => <SettlementRow key={s.id} stl={s} drivers={drivers} settlements={settlements} earnings={earnings} lang={lang} onReverse={onReverse} />)}
          {stls.length === 0 && <div className="py-12 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا سجل في هذا النطاق.' : 'No history in this scope.'}</div>}
        </div>
      </Card>

      {/* payout receipts */}
      <Card className="overflow-hidden">
        <div className="flex items-center gap-2 border-b border-slate-200/70 px-5 py-3.5">
          <Icon name="coins" size={17} className="text-slate-400" />
          <span className="text-[14px] font-bold text-slate-800">{lang === 'ar' ? 'مدفوعات البائعين' : 'Seller payouts'}</span>
          <span className="ms-auto text-[12px] text-slate-400">{num(pays.length, lang)}</span>
        </div>
        <div className="space-y-2 p-3">
          {pays.map((p) => (
            <div key={p.id} className="rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-2.5">
              <div className="flex items-center gap-3">
                <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{p.id}</span>
                <div className="min-w-0 flex-1">
                  <div className="truncate text-[13px] font-semibold text-slate-700">{tt(sellerLabel(p.sellerUserId), lang)}</div>
                  <div className="flex items-center gap-2 text-[11.5px] text-slate-400">
                    <span className="inline-flex items-center gap-1"><Icon name="building" size={11} />{officeName(p.office) ? tt(officeName(p.office), lang) : '—'}</span>
                    <span className="text-slate-300">·</span><span className="font-mono" style={{ direction: 'ltr' }}>{p.paidAt}</span>
                  </div>
                </div>
                <div className="text-end">
                  <Money v={p.total} lang={lang} strong className="text-[13.5px]" />
                  <div className="text-[10.5px] text-slate-400">{num(p.earnings.length, lang)} {lang === 'ar' ? 'بند · نقدًا' : 'item(s) · cash'}</div>
                </div>
              </div>
            </div>
          ))}
          {pays.length === 0 && <div className="py-12 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا مدفوعات في هذا النطاق.' : 'No payouts in this scope.'}</div>}
        </div>
      </Card>
    </div>
  );
}

Object.assign(window, { SettlementHistory });
