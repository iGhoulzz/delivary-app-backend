// staffDetail.jsx — glass staff modal: Details + Activity tabs, office-assignment
// management, guarded lifecycle actions. Self & last-active-admin protected.

function InfoRow({ label, children }) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2.5">
      <span className="text-[12.5px] text-slate-500">{label}</span>
      <span className="text-[13px] font-semibold text-slate-700">{children}</span>
    </div>
  );
}

function VerifyTag({ ok, lang }) {
  return ok
    ? <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-emerald-600"><Icon name="checkCircle" size={14} />{lang === 'ar' ? 'موثّق' : 'Verified'}</span>
    : <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-amber-600"><Icon name="alert" size={13} />{lang === 'ar' ? 'غير موثّق' : 'Unverified'}</span>;
}

function ActivityItem({ e, lang }) {
  const meta = STAFF_ACTIVITY_TYPES[e.type] || STAFF_ACTIVITY_TYPES.order_status;
  const applied = e.direction === 'applied';
  const c = applied ? '#e11d48' : '#475569';
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  return (
    <div className="flex items-start gap-3 rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-2.5">
      <span className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg" style={{ background: tint(c, 12), color: c }}><Icon name={meta.icon} size={15} /></span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
          <span className="text-[12.5px] font-semibold text-slate-700">{tt(meta, lang)}</span>
          {applied
            ? <span className="rounded bg-rose-50 px-1.5 py-px text-[10px] font-semibold text-rose-500">{lang === 'ar' ? 'على الحساب' : 'on account'}</span>
            : <span className="rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-400">{lang === 'ar' ? 'بواسطته' : 'performed'}</span>}
          {e.office && <span className="inline-flex items-center gap-0.5 text-[11px] text-slate-400"><Icon name="building" size={11} />{officeName(e.office) ? tt(officeName(e.office), lang) : e.office}</span>}
        </div>
        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11.5px] text-slate-500">
          <span className="font-medium text-slate-600">{tt(e.entity, lang)}</span>
          {e.reason && <span className="text-slate-400">· {tt(e.reason, lang)}</span>}
        </div>
      </div>
      <div className="shrink-0 text-end">
        {e.amount != null && <div className="font-mono text-[12px] font-bold text-slate-700" style={{ direction: 'ltr' }}>{num(Math.round(e.amount), lang)} {lang === 'ar' ? 'د.ل' : 'LYD'}</div>}
        <div className="font-mono text-[10.5px] text-slate-400" style={{ direction: 'ltr' }}>{e.at}</div>
      </div>
    </div>
  );
}

function StaffModal({ staffId, staff, lang, settlements, payouts, onClose, onAction, onAddAssignment, onRemoveAssignment }) {
  const [tab, setTab] = React.useState('details');
  const [addingOffice, setAddingOffice] = React.useState(false);
  const s = staff.find((x) => x.id === staffId) || null;
  React.useEffect(() => { setTab('details'); setAddingOffice(false); }, [staffId]);
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);
  if (!staffId || !s) return null;

  const isMe = s.id === CURRENT_STAFF_ID;
  const lastAdmin = isLastActiveAdmin(s, staff);
  const protectedAcct = isMe || lastAdmin;
  const active = activeAssignments(s);
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  const unassignedOffices = OFFICES.filter((o) => !active.some((a) => a.office === o.id));
  const activity = staffActivity(s, settlements, payouts);
  const act = (kind) => onAction(kind, s.id);

  const guardNote = isMe ? (lang === 'ar' ? 'لا يمكنك تنفيذ هذا على حسابك.' : 'You cannot do this to your own account.')
    : lastAdmin ? (lang === 'ar' ? 'آخر مدير نشِط — محميّ.' : 'Last active admin — protected.') : null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40" style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />
      <div className="app-card relative flex max-h-[90vh] w-full max-w-[760px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        {/* header */}
        <div className="relative shrink-0 border-b border-slate-200/70 px-6 pt-6 pb-0">
          <button onClick={onClose} className="absolute end-5 top-5 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
          <div className="flex items-start gap-4">
            <div className={s.deactivated ? 'opacity-60' : ''}><Avatar name={s.firstName} lang={lang} size={56} /></div>
            <div className="min-w-0 flex-1 pe-8">
              <div className="flex flex-wrap items-center gap-2.5">
                <h2 className="text-[20px] font-bold tracking-tight text-slate-900">{staffName(s, lang)}</h2>
                <StaffRoleBadge role={s.role} lang={lang} />
                {s.deactivated
                  ? <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold" style={{ background: tint('#64748b', 14), color: '#64748b' }}><Icon name="power" size={12} />{lang === 'ar' ? 'مُعطّل' : 'Deactivated'}</span>
                  : <AccountChip status={s.accountStatus} lang={lang} />}
                {isMe && <span className="rounded bg-[var(--accent-soft)] px-2 py-px text-[11px] font-bold" style={{ color: 'var(--accent)' }}>{lang === 'ar' ? 'حسابك' : 'You'}</span>}
              </div>
              <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-slate-500">
                <span className="font-mono text-slate-400" style={{ direction: 'ltr' }}>{s.id}</span>
                <span className="inline-flex items-center gap-1.5"><Icon name="phone" size={14} /><span className="font-mono" style={{ direction: 'ltr' }}>{s.phone}</span></span>
                {s.email && <span className="inline-flex items-center gap-1.5"><Icon name="mail" size={14} /><span className="font-mono text-[11.5px]" style={{ direction: 'ltr' }}>{s.email}</span></span>}
              </div>
              {s.mustChangePassword && (
                <div className="mt-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11.5px] font-semibold" style={{ background: tint('#d97706', 12), color: '#b45309' }}>
                  <Icon name="key" size={13} />{lang === 'ar' ? 'بانتظار تغيير كلمة المرور المؤقتة' : 'Pending temp-password change'}
                </div>
              )}
            </div>
          </div>

          {/* action bar */}
          <div className="mt-4 flex flex-wrap items-center gap-2 pb-4">
            {!isMe && <MiniBtn icon="key" onClick={() => act('reset_pw')} >{lang === 'ar' ? 'إعادة تعيين كلمة المرور' : 'Reset temp password'}</MiniBtn>}
            <div className="ms-auto flex flex-wrap items-center gap-2">
              {!s.deactivated && s.accountStatus === 'active' && (
                <MiniBtn icon="pause" tone="amber" onClick={() => !protectedAcct && act('suspend')}>{lang === 'ar' ? 'إيقاف' : 'Suspend'}</MiniBtn>
              )}
              {!s.deactivated && (s.accountStatus === 'suspended' || s.accountStatus === 'suspended_unpaid_fees') && (
                <MiniBtn icon="power" tone="green" onClick={() => act('reinstate')}>{lang === 'ar' ? 'إعادة تفعيل' : 'Reinstate'}</MiniBtn>
              )}
              {s.deactivated
                ? <MiniBtn icon="power" tone="green" onClick={() => act('reinstate')}>{lang === 'ar' ? 'إعادة تفعيل' : 'Reinstate'}</MiniBtn>
                : <MiniBtn icon="ban" tone="red" onClick={() => !protectedAcct && act('deactivate')}>{lang === 'ar' ? 'تعطيل' : 'Deactivate'}</MiniBtn>}
            </div>
          </div>
          {guardNote && (
            <div className="mb-3 -mt-1 flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50/70 px-3 py-1.5 text-[11.5px] text-slate-500">
              <Icon name="shield" size={13} />{guardNote}
            </div>
          )}

          {/* tabs */}
          <div className="flex items-center gap-5">
            {[['details', lang === 'ar' ? 'التفاصيل' : 'Details', 'user'], ['activity', lang === 'ar' ? 'النشاط' : 'Activity', 'history']].map(([k, label, icon]) => (
              <button key={k} onClick={() => setTab(k)}
                className={`inline-flex items-center gap-1.5 border-b-2 px-1 pb-2.5 text-[13.5px] font-semibold transition ${tab === k ? 'text-slate-900' : 'border-transparent text-slate-400 hover:text-slate-600'}`}
                style={tab === k ? { borderColor: 'var(--accent)' } : undefined}>
                <Icon name={icon} size={15} />{label}
                {k === 'activity' && <span className={`rounded-full px-1.5 py-px text-[10.5px] font-bold tabular-nums ${tab === k ? 'text-white' : 'bg-slate-100 text-slate-400'}`} style={tab === k ? { background: 'var(--accent)' } : undefined}>{num(activity.length, lang)}</span>}
              </button>
            ))}
          </div>
        </div>

        {/* body */}
        <div className="flex-1 overflow-y-auto p-5">
          {tab === 'details' ? (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Section title={lang === 'ar' ? 'الحساب' : 'Account'} icon="user">
                <div className="space-y-1.5">
                  <InfoRow label={lang === 'ar' ? 'الدور' : 'Role'}><StaffRoleBadge role={s.role} lang={lang} sm /></InfoRow>
                  <div className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2.5"><span className="text-[12.5px] text-slate-500">{lang === 'ar' ? 'الهاتف' : 'Phone'}</span><VerifyTag ok={s.phoneVerified} lang={lang} /></div>
                  <div className="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2.5"><span className="text-[12.5px] text-slate-500">{lang === 'ar' ? 'البريد' : 'Email'}</span>{s.email ? <VerifyTag ok={s.emailVerified} lang={lang} /> : <span className="text-[12px] italic text-slate-400">{lang === 'ar' ? 'غير مُسجّل' : 'Not set'}</span>}</div>
                  <InfoRow label={lang === 'ar' ? 'أُضيف' : 'Created'}><span className="font-mono text-[12px]" style={{ direction: 'ltr' }}>{s.createdAt}</span></InfoRow>
                  <InfoRow label={lang === 'ar' ? 'آخر تحديث' : 'Updated'}><span className="font-mono text-[12px]" style={{ direction: 'ltr' }}>{s.updatedAt}</span></InfoRow>
                </div>
              </Section>

              <Section title={lang === 'ar' ? 'مهام المكاتب' : 'Office assignments'} icon="building"
                right={s.role === 'office_staff' && !s.deactivated && unassignedOffices.length > 0 ? <button onClick={() => setAddingOffice(!addingOffice)} className="inline-flex items-center gap-1 text-[11.5px] font-semibold" style={{ color: 'var(--accent)' }}><Icon name="plus" size={13} strokeWidth={2.4} />{lang === 'ar' ? 'إضافة' : 'Add'}</button> : null}>
                {s.role === 'admin' ? (
                  <div className="rounded-lg border border-slate-200/80 bg-white/60 px-3.5 py-3 text-[12.5px] text-slate-500"><Icon name="shield" size={14} className="me-1.5 inline" />{lang === 'ar' ? 'المدراء غير مرتبطين بمكتب — صلاحية عامة.' : 'Admins are not office-scoped — global authority.'}</div>
                ) : (
                  <div className="space-y-1.5">
                    {active.map((a) => (
                      <div key={a.id} className="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2.5">
                        <span className="text-slate-400"><Icon name="building" size={15} /></span>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5"><span className="text-[13px] font-semibold text-slate-700">{officeName(a.office) ? tt(officeName(a.office), lang) : a.office}</span>{a.is_manager && <span className="rounded px-1.5 py-px text-[10px] font-bold" style={{ background: tint('#2563eb', 13), color: '#2563eb' }}>{lang === 'ar' ? 'مدير' : 'Manager'}</span>}</div>
                          <div className="font-mono text-[10.5px] text-slate-400" style={{ direction: 'ltr' }}>{lang === 'ar' ? 'منذ' : 'since'} {a.assignedAt}</div>
                        </div>
                        <button onClick={() => onRemoveAssignment(s.id, a.id)} disabled={active.length <= 1}
                          title={active.length <= 1 ? (lang === 'ar' ? 'لا يمكن إزالة آخر مكتب — استخدم التعطيل' : 'Cannot remove last office — use deactivate') : ''}
                          className={`shrink-0 rounded-md px-2 py-1 text-[11.5px] font-semibold transition ${active.length <= 1 ? 'cursor-not-allowed text-slate-300' : 'text-rose-500 hover:bg-rose-50'}`}>{lang === 'ar' ? 'إزالة' : 'Remove'}</button>
                      </div>
                    ))}
                    {active.length === 0 && <div className="rounded-lg border border-dashed border-slate-200 px-3 py-3 text-center text-[12.5px] text-slate-400">{lang === 'ar' ? 'لا مكاتب نشطة.' : 'No active offices.'}</div>}
                    {active.length <= 1 && active.length > 0 && <p className="px-1 text-[11px] text-slate-400">{lang === 'ar' ? 'لا يمكن إزالة آخر مكتب — استخدم «تعطيل» لإنهاء كل المهام.' : 'Last office can\u2019t be removed — use Deactivate to clear all.'}</p>}
                    {addingOffice && (
                      <div className="rounded-lg border border-slate-200 bg-white/80 p-2">
                        <div className="mb-1.5 px-1 text-[11px] font-semibold text-slate-500">{lang === 'ar' ? 'إسناد إلى مكتب' : 'Assign to office'}</div>
                        <div className="space-y-1">
                          {unassignedOffices.map((o) => (
                            <button key={o.id} onClick={() => { onAddAssignment(s.id, o.id); setAddingOffice(false); }} className="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-start text-[12.5px] text-slate-600 transition hover:bg-slate-50">
                              <Icon name="building" size={14} className="text-slate-400" />{tt(o.district, lang)}
                            </button>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </Section>

              <Section title={lang === 'ar' ? 'الوصول والأمان' : 'Access & security'} icon="key" className="lg:col-span-2">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="flex items-center gap-2 rounded-lg border border-slate-200/80 bg-white/60 px-3.5 py-2.5 text-[12.5px]">
                    <Icon name="key" size={15} className={s.mustChangePassword ? 'text-amber-500' : 'text-emerald-500'} />
                    {s.mustChangePassword ? <span className="font-semibold text-amber-700">{lang === 'ar' ? 'كلمة مرور مؤقتة — بانتظار التغيير' : 'Temp password — pending change'}</span> : <span className="text-slate-600">{lang === 'ar' ? 'كلمة المرور مُعيّنة' : 'Password set'}</span>}
                  </div>
                  <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'إعادة التعيين تُنشئ كلمة مرور مؤقتة وتُلغي الجلسات النشطة.' : 'Reset generates a one-time temp password and revokes active sessions.'}</span>
                </div>
              </Section>
            </div>
          ) : (
            <div className="space-y-2.5">
              <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-[11.5px] text-slate-500">
                <Icon name="alert" size={14} className="mt-0.5 shrink-0 text-slate-400" />
                <span>{lang === 'ar' ? 'سجلّ مُجمّع من مصادر تدقيق متعددة (تسويات، مدفوعات، إجراءات، مخزون المكتب). لا يوجد بعد نقطة وصول موحّدة في الخادم.' : 'Aggregated from multiple audit sources (settlements, payouts, moderation, office inventory). No single backend feed endpoint yet.'}</span>
              </div>
              {activity.length ? activity.map((e, i) => <ActivityItem key={i} e={e} lang={lang} />)
                : <div className="py-12 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا نشاط مُسجّل.' : 'No recorded activity.'}</div>}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { StaffModal });
