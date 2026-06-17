// merchantDetail.jsx — glass merchant detail modal. Two independent status
// axes (owner account vs merchant profile). Ban is terminal + role-stripping.

function AxisCard({ label, children }) {
  return (
    <div className="flex items-center justify-between rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-2.5">
      <span className="text-[11.5px] font-medium text-slate-400">{label}</span>
      {children}
    </div>
  );
}

function RateRow({ label, override, fallback, lang }) {
  const usingDefault = override == null;
  return (
    <div className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2.5">
      <span className="inline-flex items-center gap-1.5 text-[12.5px] text-slate-600"><span className="text-slate-400"><Icon name="percent" size={14} /></span>{label}</span>
      <span className="flex items-center gap-2">
        <span className="font-mono text-[14px] font-bold tabular-nums text-slate-800" style={{ direction: 'ltr' }}>{num(Math.round((usingDefault ? fallback : override) * 100), lang)}%</span>
        <span className={`rounded px-1.5 py-px text-[10px] font-semibold ${usingDefault ? 'bg-slate-100 text-slate-400' : ''}`} style={usingDefault ? undefined : { background: tint('#2563eb', 14), color: '#2563eb' }}>
          {usingDefault ? (lang === 'ar' ? 'افتراضي المنصّة' : 'platform default') : (lang === 'ar' ? 'مخصّص' : 'override')}
        </span>
      </span>
    </div>
  );
}

function MerchantModal({ merchantId, merchants, users, lang, onClose, onAction, onEdit, onOpenOrder, onOpenOwner }) {
  const [confirmBan, setConfirmBan] = React.useState(false);
  const m = merchants.find((x) => x.id === merchantId) || null;
  React.useEffect(() => { setConfirmBan(false); }, [merchantId]);
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);
  if (!merchantId || !m) return null;

  const owner = users.find((u) => u.id === m.ownerUserId);
  const orders = merchantOrders(m).sort((a, b) => (a.created < b.created ? 1 : -1));
  const delivered = orders.filter((o) => o.status === 'delivered').length;
  const mod = owner ? owner.moderation.filter((x) => x.scope === 'merchant') : [];
  const isBanned = m.status === 'banned';
  const isSuspended = m.status === 'suspended';
  const act = (kind) => onAction(kind, m.id);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40"
        style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />

      <div className="app-card relative flex max-h-[90vh] w-full max-w-[820px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        {/* header */}
        <div className="relative shrink-0 border-b border-slate-200/70 px-6 pt-6 pb-5">
          <button onClick={onClose} className="absolute end-5 top-5 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
          <div className="flex items-start gap-4">
            <div className={`grid h-14 w-14 shrink-0 place-items-center rounded-2xl ${isBanned ? 'opacity-60' : ''}`} style={{ background: tint('#2563eb', 12), color: '#2563eb' }}>
              <Icon name="merchants" size={28} />
            </div>
            <div className="min-w-0 flex-1 pe-8">
              <div className="flex flex-wrap items-center gap-2.5">
                <h2 className="text-[20px] font-bold tracking-tight text-slate-900">{tt(m.business, lang)}</h2>
                <MerchantChip status={m.status} lang={lang} withIcon />
              </div>
              <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-slate-500">
                <span className="font-mono text-slate-400" style={{ direction: 'ltr' }}>{m.id}</span>
                <span className="inline-flex items-center gap-1.5"><Icon name="phone" size={14} /><span className="font-mono" style={{ direction: 'ltr' }}>{m.businessPhone}</span></span>
                <span className="inline-flex items-center gap-1.5"><Icon name="calendar" size={14} />{lang === 'ar' ? 'أُنشئ' : 'Created'} <span className="font-mono" style={{ direction: 'ltr' }}>{m.created}</span></span>
              </div>
            </div>
          </div>

          {/* action bar */}
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <MiniBtn icon="edit" onClick={() => onEdit(m.id)}>{lang === 'ar' ? 'تعديل' : 'Edit'}</MiniBtn>
            {owner && <MiniBtn icon="user" onClick={() => onOpenOwner(owner.id)}>{lang === 'ar' ? 'فتح حساب المالك' : 'Open owner'}</MiniBtn>}
            <div className="ms-auto flex flex-wrap items-center gap-2">
              {m.status === 'active' && <MiniBtn icon="pause" tone="amber" onClick={() => act('suspend')}>{lang === 'ar' ? 'إيقاف' : 'Suspend'}</MiniBtn>}
              {isSuspended && <MiniBtn icon="power" tone="green" onClick={() => act('reactivate')}>{lang === 'ar' ? 'إعادة تفعيل' : 'Reactivate'}</MiniBtn>}
              {!isBanned && <MiniBtn icon="ban" tone="red" onClick={() => setConfirmBan(true)}>{lang === 'ar' ? 'حظر' : 'Ban'}</MiniBtn>}
            </div>
          </div>

          {/* terminal ban confirm */}
          {confirmBan && (
            <div className="mt-3 rounded-xl border border-rose-200 p-3.5" style={{ background: tint('#e11d48', 7) }}>
              <div className="flex items-start gap-2.5">
                <span className="mt-0.5 shrink-0 text-rose-500"><Icon name="alert" size={18} /></span>
                <div className="flex-1">
                  <div className="text-[13px] font-bold text-rose-700">{lang === 'ar' ? 'حظر نهائي للتاجر' : 'Terminal merchant ban'}</div>
                  <p className="mt-0.5 text-[12px] leading-relaxed text-rose-600/90">{lang === 'ar' ? 'سيُزال دور «تاجر» من المستخدم ويفقد الوصول إلى طلبات التجار. هذا الإجراء لا يمكن التراجع عنه. يبقى حساب المستخدم كما هو.' : "The 'merchant' role is removed and merchant order access is revoked. This cannot be undone. The owner's user account is unaffected."}</p>
                  <div className="mt-2.5 flex items-center gap-2">
                    <button onClick={() => { act('ban'); setConfirmBan(false); }} className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-rose-600 px-3.5 text-[12.5px] font-semibold text-white shadow-sm transition hover:bg-rose-700"><Icon name="ban" size={15} />{lang === 'ar' ? 'تأكيد الحظر النهائي' : 'Confirm terminal ban'}</button>
                    <button onClick={() => setConfirmBan(false)} className="h-9 rounded-lg px-3 text-[12.5px] font-semibold text-slate-500 transition hover:bg-white hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* body */}
        <div className="grid flex-1 grid-cols-1 gap-4 overflow-y-auto p-5 lg:grid-cols-2">

          {/* Status axes */}
          <Section title={lang === 'ar' ? 'الحالة على محورين' : 'Status — two axes'} icon="overview" className="lg:col-span-2">
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              <AxisCard label={lang === 'ar' ? 'ملف التاجر — يتحكّم في الوصول للطلبات' : 'Merchant profile — controls order access'}><MerchantChip status={m.status} lang={lang} withIcon /></AxisCard>
              <AxisCard label={lang === 'ar' ? 'حساب المستخدم — يتحكّم في الدخول' : 'User account — controls login'}>{owner ? <AccountChip status={owner.accountStatus} lang={lang} /> : <span className="text-slate-300">—</span>}</AxisCard>
            </div>
            <p className="mt-2 flex items-start gap-1.5 text-[11.5px] leading-relaxed text-slate-400">
              <span className="mt-px shrink-0"><Icon name="overview" size={13} /></span>
              <span>{lang === 'ar' ? 'المحوران مستقلان: قد يكون التاجر نشطًا بينما الحساب موقوف، أو العكس.' : 'The two axes are independent: a merchant can be active while the account is suspended, or vice-versa.'}</span>
            </p>
          </Section>

          {/* Owner account */}
          <Section title={lang === 'ar' ? 'المالك' : 'Owner'} icon="user"
            right={owner ? <button onClick={() => onOpenOwner(owner.id)} className="text-[11.5px] font-semibold" style={{ color: 'var(--accent)' }}>{lang === 'ar' ? 'فتح الحساب' : 'Open account'}</button> : null}>
            {owner ? (
              <div className="flex items-center gap-3">
                <Avatar name={owner.name} lang={lang} size={42} />
                <div className="min-w-0 flex-1">
                  <div className="font-semibold text-slate-800">{tt(owner.name, lang)}</div>
                  <div className="flex items-center gap-2 text-[12px] text-slate-500"><span className="font-mono" style={{ direction: 'ltr' }}>{owner.phone}</span><span className="text-slate-300">·</span><span className="font-mono text-[11px] text-slate-400" style={{ direction: 'ltr' }}>{owner.id}</span></div>
                  <div className="mt-1.5"><RoleBadges roles={owner.roles} lang={lang} size="sm" /></div>
                </div>
              </div>
            ) : <div className="text-[13px] text-slate-400">—</div>}
          </Section>

          {/* Rate overrides */}
          <Section title={lang === 'ar' ? 'الأسعار والعمولات' : 'Rates & overrides'} icon="coins">
            <div className="space-y-2">
              <RateRow label={lang === 'ar' ? 'عمولة المنصّة (على السلعة)' : 'Item commission'} override={m.commissionOverride} fallback={PLATFORM_SETTINGS.pricing.item_commission_rate} lang={lang} />
              <RateRow label={lang === 'ar' ? 'حصة المنصّة من رسوم التوصيل' : 'Platform delivery cut'} override={m.driverFeeCutOverride} fallback={PLATFORM_SETTINGS.pricing.driver_fee_cut_rate} lang={lang} />
            </div>
          </Section>

          {/* Pickup */}
          <Section title={lang === 'ar' ? 'الاستلام الافتراضي' : 'Default pickup'} icon="pin" className="lg:col-span-2">
            <div className="flex items-stretch gap-3">
              <div className="grid w-28 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200/80" style={{ background: 'repeating-linear-gradient(135deg,#f1f5f9,#f1f5f9 8px,#e2e8f0 8px,#e2e8f0 16px)' }}>
                <span style={{ color: 'var(--accent)' }}><Icon name="pin" size={26} /></span>
              </div>
              <div className="flex-1 rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-3">
                <div className="text-[13.5px] font-semibold text-slate-800">{tt(m.pickup, lang)}</div>
                <div className="mt-0.5 inline-flex items-center gap-1.5 text-[12px] text-slate-500"><Icon name="building" size={13} />{tt(m.pickupDist, lang)}</div>
              </div>
            </div>
          </Section>

          {/* Notes */}
          <Section title={lang === 'ar' ? 'ملاحظات' : 'Notes'} icon="doc" className="lg:col-span-2">
            {m.notes && tt(m.notes, lang) ? (
              <p className="rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-3 text-[13px] leading-relaxed text-slate-600">{tt(m.notes, lang)}</p>
            ) : <div className="py-1 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد ملاحظات.' : 'No notes.'}</div>}
          </Section>

          {/* Merchant orders */}
          <Section title={lang === 'ar' ? 'طلبات التاجر' : 'Merchant orders'} icon="orders" className="lg:col-span-2"
            right={orders.length ? <span className="text-[11.5px] text-slate-400">{num(delivered, lang)} {lang === 'ar' ? 'مُسلّم من' : 'delivered of'} {num(orders.length, lang)}</span> : null}>
            {orders.length ? (
              <div className="max-h-56 space-y-1.5 overflow-y-auto pe-1">
                {orders.map((o) => (
                  <button key={o.id} onClick={() => onOpenOrder && onOpenOrder(o.id)} className="flex w-full items-center gap-3 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                    <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span>
                    <span className="min-w-0 flex-1 truncate text-[12px] text-slate-500">{lang === 'ar' ? 'إلى' : 'to'} {tt(o.receiver, lang)} · {tt(o.receiverDist, lang)}</span>
                    {o.cod > 0 && <span className="hidden sm:inline"><Money v={o.cod} lang={lang} className="text-[11.5px] text-amber-600" /></span>}
                    <StatusPill status={o.status} lang={lang} size="sm" />
                    <span className="hidden font-mono text-[11px] text-slate-400 md:inline" style={{ direction: 'ltr' }}>{o.created.slice(5, 10)}</span>
                  </button>
                ))}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد طلبات لهذا التاجر بعد.' : 'No orders for this merchant yet.'}</div>}
          </Section>

          {/* Merchant moderation */}
          {mod.length > 0 && (
            <Section title={lang === 'ar' ? 'سجل إجراءات التاجر' : 'Merchant moderation'} icon="history" className="lg:col-span-2">
              <div className="space-y-1.5">
                {mod.map((x, i) => {
                  const meta = MOD_ACTIONS[x.action] || MOD_ACTIONS.suspend;
                  const c = (SOFT[meta.tone] || SOFT.slate).c;
                  return (
                    <div key={i} className="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2">
                      <span style={{ color: c }}><Icon name={meta.icon} size={15} /></span>
                      <div className="min-w-0 flex-1">
                        <span className="text-[12.5px] font-semibold" style={{ color: c }}>{tt(meta, lang)}</span>
                        <div className="text-[11.5px] text-slate-500">{tt(x.reason, lang)}</div>
                      </div>
                      <div className="shrink-0 text-end text-[11px] text-slate-400">
                        <div>{x.by === 'system' ? (lang === 'ar' ? 'تلقائي' : 'system') : (lang === 'ar' ? 'إداري' : 'admin')}</div>
                        <div>{tt(x.ago, lang)}</div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </Section>
          )}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { MerchantModal });
