// userDetail.jsx — glass user-account modal. Mirrors the driver modal, simpler.
// A user is everyone; a driver is a user with a profile. Account actions live
// here; driver-specific control stays in the driver modal (cross-linked).

function VerifyRow({ label, verified, lang }) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2">
      <span className="text-[12.5px] text-slate-600">{label}</span>
      {verified
        ? <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-emerald-600"><Icon name="checkCircle" size={15} />{lang === 'ar' ? 'موثّق' : 'Verified'}</span>
        : <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-amber-600"><Icon name="alert" size={14} />{lang === 'ar' ? 'غير موثّق' : 'Unverified'}</span>}
    </div>
  );
}

function UserModal({ userId, users, lang, onClose, onAction, onOpenOrder, onOpenDriver, onOpenMerchant, onPromote }) {
  const u = users.find((x) => x.id === userId) || null;
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);
  if (!userId || !u) return null;

  const orders = customerOrders(u.name).sort((a, b) => (a.created < b.created ? 1 : -1));
  const isBanned = u.accountStatus === 'banned';
  const isSuspended = u.accountStatus === 'suspended' || u.accountStatus === 'suspended_unpaid_fees';
  const isDriver = !!u.driverId;
  const act = (kind) => onAction(kind, u.id);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40"
        style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />

      <div className="app-card relative flex max-h-[90vh] w-full max-w-[760px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        {/* header */}
        <div className="relative shrink-0 border-b border-slate-200/70 px-6 pt-6 pb-5">
          <button onClick={onClose} className="absolute end-5 top-5 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
          <div className="flex items-start gap-4">
            <div className={isBanned ? 'opacity-60' : ''}><Avatar name={u.name} lang={lang} size={56} /></div>
            <div className="min-w-0 flex-1 pe-8">
              <div className="flex flex-wrap items-center gap-2.5">
                <h2 className="text-[20px] font-bold tracking-tight text-slate-900">{tt(u.name, lang)}</h2>
                <AccountChip status={u.accountStatus} lang={lang} />
              </div>
              <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-slate-500">
                <span className="font-mono text-slate-400" style={{ direction: 'ltr' }}>{u.id}</span>
                <span className="inline-flex items-center gap-1.5"><Icon name="phone" size={14} /><span className="font-mono" style={{ direction: 'ltr' }}>{u.phone}</span></span>
                {u.email && <span className="inline-flex items-center gap-1.5"><Icon name="mail" size={14} /><span className="font-mono text-[11.5px]" style={{ direction: 'ltr' }}>{u.email}</span></span>}
                <span className="inline-flex items-center gap-1.5"><Icon name="globe" size={14} />{u.locale === 'ar' ? 'العربية' : 'English'}</span>
              </div>
              <div className="mt-2"><RoleBadges roles={u.roles} lang={lang} /></div>
            </div>
          </div>

          {/* action bar */}
          <div className="mt-4 flex flex-wrap items-center gap-2">
            {isDriver
              ? <MiniBtn icon="drivers" onClick={() => onOpenDriver(u.driverId)}>{lang === 'ar' ? 'فتح ملف السائق' : 'Open driver profile'}</MiniBtn>
              : !isBanned && <MiniBtn icon="drivers" onClick={() => onPromote(u.id)}>{lang === 'ar' ? 'ترقية إلى سائق' : 'Promote to driver'}</MiniBtn>}
            {u.merchantId && onOpenMerchant && <MiniBtn icon="merchants" onClick={() => onOpenMerchant(u.merchantId)}>{lang === 'ar' ? 'فتح ملف التاجر' : 'Open merchant profile'}</MiniBtn>}

            <div className="ms-auto flex flex-wrap items-center gap-2">
              {u.accountStatus === 'active' && (
                <>
                  <MiniBtn icon="pause" tone="amber" onClick={() => act('suspend')}>{lang === 'ar' ? 'إيقاف الحساب' : 'Suspend'}</MiniBtn>
                  <MiniBtn icon="ban" tone="red" onClick={() => act('ban')}>{lang === 'ar' ? 'حظر' : 'Ban'}</MiniBtn>
                </>
              )}
              {u.accountStatus === 'pending_verification' && (
                <MiniBtn icon="checkCircle" tone="green" onClick={() => act('reactivate')}>{lang === 'ar' ? 'تفعيل الحساب' : 'Activate'}</MiniBtn>
              )}
              {isSuspended && (
                <MiniBtn icon="power" tone="green" onClick={() => act('reactivate')}>{lang === 'ar' ? 'إعادة تفعيل' : 'Reactivate'}</MiniBtn>
              )}
              {isBanned && (
                <MiniBtn icon="undo" onClick={() => act('reinstate')}>{lang === 'ar' ? 'رفع الحظر' : 'Reinstate'}</MiniBtn>
              )}
            </div>
          </div>
        </div>

        {/* body */}
        <div className="grid flex-1 grid-cols-1 gap-4 overflow-y-auto p-5 lg:grid-cols-2">

          {/* Account */}
          <Section title={lang === 'ar' ? 'الحساب' : 'Account'} icon="user">
            <div className="space-y-1.5">
              <VerifyRow label={lang === 'ar' ? 'رقم الهاتف' : 'Phone number'} verified={u.phoneVerified} lang={lang} />
              <VerifyRow label={lang === 'ar' ? 'البريد الإلكتروني' : 'Email address'} verified={u.emailVerified} lang={lang} />
            </div>
            <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-slate-500">
              <span className="inline-flex items-center gap-1.5"><Icon name="calendar" size={14} />{lang === 'ar' ? 'أُنشئ' : 'Created'} <span className="font-mono" style={{ direction: 'ltr' }}>{u.joined}</span></span>
              <span className="inline-flex items-center gap-1.5"><Icon name="orders" size={14} />{num(u.orders, lang)} {lang === 'ar' ? 'طلب' : 'orders'}</span>
            </div>
          </Section>

          {/* Notifications */}
          <Section title={lang === 'ar' ? 'الإشعارات' : 'Notifications'} icon="bell">
            <div className="flex flex-col gap-1.5">
              {[['push', { ar: 'إشعارات التطبيق', en: 'Push' }], ['sms', { ar: 'رسائل SMS', en: 'SMS' }], ['email', { ar: 'البريد الإلكتروني', en: 'Email' }]].map(([k, label]) => (
                <div key={k} className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2">
                  <span className="text-[12.5px] text-slate-600">{tt(label, lang)}</span>
                  <span className={`inline-flex h-5 w-9 items-center rounded-full px-0.5 ${u.notif[k] ? '' : 'bg-slate-200'}`} style={u.notif[k] ? { background: 'var(--accent)' } : undefined}>
                    <span className={`h-4 w-4 rounded-full bg-white shadow-sm transition ${u.notif[k] ? (lang === 'ar' ? '-translate-x-4' : 'translate-x-4') : ''}`} />
                  </span>
                </div>
              ))}
            </div>
            <p className="mt-2 text-[11px] italic text-slate-400">{lang === 'ar' ? 'تفضيلات السائق — للعرض فقط.' : 'User preferences — read-only.'}</p>
          </Section>

          {/* Customer order history */}
          <Section title={lang === 'ar' ? 'الطلبات كعميل' : 'Orders as customer'} icon="orders" className="lg:col-span-2"
            right={orders.length ? <span className="text-[11.5px] text-slate-400">{num(orders.length, lang)} {lang === 'ar' ? 'طلب' : 'total'}</span> : null}>
            {orders.length ? (
              <div className="max-h-56 space-y-1.5 overflow-y-auto pe-1">
                {orders.map((o) => {
                  const en = tt(u.name, 'en');
                  const role = tt(o.sender, 'en') === en ? (lang === 'ar' ? 'مُرسِل' : 'Sender') : (lang === 'ar' ? 'مُستلِم' : 'Recipient');
                  return (
                    <button key={o.id} onClick={() => onOpenOrder && onOpenOrder(o.id)} className="flex w-full items-center gap-3 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                      <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span>
                      <span className="rounded bg-slate-100 px-1.5 py-px text-[10.5px] font-medium text-slate-500">{role}</span>
                      <span className="min-w-0 flex-1 truncate text-[12px] text-slate-500">{tt(o.senderDist, lang)} <span className="text-slate-300">→</span> {tt(o.receiverDist, lang)}</span>
                      <StatusPill status={o.status} lang={lang} size="sm" />
                      <span className="hidden font-mono text-[11px] text-slate-400 md:inline" style={{ direction: 'ltr' }}>{o.created.slice(5, 10)}</span>
                    </button>
                  );
                })}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد طلبات لهذا المستخدم.' : 'No orders for this user.'}</div>}
          </Section>

          {/* Moderation history */}
          <Section title={lang === 'ar' ? 'سجل الإجراءات' : 'Moderation history'} icon="history" className="lg:col-span-2"
            right={<span className={`rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums ${u.moderation.length ? 'text-slate-500' : 'text-slate-400'}`} style={{ background: tint('#64748b', 12) }}>{num(u.moderation.length, lang)}</span>}>
            {u.moderation.length ? (
              <div className="space-y-1.5">
                {u.moderation.map((m, i) => {
                  const meta = MOD_ACTIONS[m.action] || MOD_ACTIONS.suspend;
                  const c = (SOFT[meta.tone] || SOFT.slate).c;
                  return (
                    <div key={i} className="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2">
                      <span style={{ color: c }}><Icon name={meta.icon} size={15} /></span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5">
                          <span className="text-[12.5px] font-semibold" style={{ color: c }}>{tt(meta, lang)}</span>
                          <span className="rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-400">{m.scope === 'account' ? (lang === 'ar' ? 'الحساب' : 'account') : (lang === 'ar' ? 'السائق' : 'driver')}</span>
                        </div>
                        <div className="text-[11.5px] text-slate-500">{tt(m.reason, lang)}</div>
                      </div>
                      <div className="shrink-0 text-end text-[11px] text-slate-400">
                        <div>{m.by === 'system' ? (lang === 'ar' ? 'تلقائي' : 'system') : (lang === 'ar' ? 'إداري' : 'admin')}</div>
                        <div>{tt(m.ago, lang)}</div>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد إجراءات على هذا الحساب.' : 'No moderation actions on this account.'}</div>}
          </Section>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { UserModal });
