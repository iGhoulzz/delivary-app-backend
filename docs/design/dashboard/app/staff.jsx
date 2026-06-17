// staff.jsx — staff roster. Internal accounts = users with a Spatie role
// (admin / office_staff). Managed by admins via the /admin/staff endpoints.

function StaffRoleBadge({ role, lang, sm }) {
  const meta = STAFF_ROLES[role] || STAFF_ROLES.office_staff;
  const s = SOFT[meta.tone] || SOFT.slate;
  return (
    <span className="inline-flex items-center gap-1 rounded-md font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 15), color: s.c, padding: sm ? '2px 7px' : '3px 9px', fontSize: sm ? '11px' : '11.5px' }}>
      <Icon name={role === 'admin' ? 'shield' : 'building'} size={sm ? 11 : 12} strokeWidth={2.2} />{tt(meta, lang)}
    </span>
  );
}

// Office-assignment chips (active only). Manager flagged with a dot.
function AssignmentChips({ staff, lang, max }) {
  const active = activeAssignments(staff);
  if (staff.role === 'admin') return <span className="text-[12px] italic text-slate-400">{lang === 'ar' ? 'غير مرتبط بمكتب' : 'Not office-scoped'}</span>;
  if (active.length === 0) return <span className="text-[12px] italic text-slate-400">{lang === 'ar' ? 'بلا مكتب' : 'No office'}</span>;
  const officeName = (id) => { const o = OFFICES.find((x) => x.id === id); return o ? o.district : null; };
  const shown = max ? active.slice(0, max) : active;
  return (
    <div className="flex flex-wrap items-center gap-1">
      {shown.map((a) => (
        <span key={a.id} className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11.5px] font-medium text-slate-600">
          {a.is_manager && <span title={lang === 'ar' ? 'مدير المكتب' : 'Manager'} className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--accent)' }} />}
          {officeName(a.office) ? tt(officeName(a.office), lang) : a.office}
        </span>
      ))}
      {max && active.length > max && <span className="text-[11px] text-slate-400">+{num(active.length - max, lang)}</span>}
    </div>
  );
}

function Staff({ lang, staff, onOpen, openId, onAdd }) {
  const [q, setQ] = React.useState('');
  const [role, setRole] = React.useState('all');
  const [status, setStatus] = React.useState('all');
  const [office, setOffice] = React.useState('all');

  const filtered = staff.filter((s) => {
    if (role !== 'all' && s.role !== role) return false;
    if (status !== 'all') {
      if (status === 'deactivated' && !s.deactivated) return false;
      if (status !== 'deactivated' && (s.accountStatus !== status || s.deactivated)) return false;
    }
    if (office !== 'all' && !activeAssignments(s).some((a) => a.office === office)) return false;
    if (q.trim()) {
      const hay = [s.id, s.phone, s.email || '', staffName(s, lang), staffName(s, 'en')].join(' ').toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    return true;
  });

  const roleChips = [['all', { ar: 'الكل', en: 'All' }], ['admin', STAFF_ROLES.admin], ['office_staff', STAFF_ROLES.office_staff]];
  const statusOpts = [
    ['all', { ar: 'كل الحالات', en: 'All statuses' }],
    ['active', ACCOUNT_STATUS.active], ['suspended', ACCOUNT_STATUS.suspended],
    ['suspended_unpaid_fees', ACCOUNT_STATUS.suspended_unpaid_fees], ['deactivated', { ar: 'مُعطّل', en: 'Deactivated' }],
  ];
  const officeOpts = [['all', { ar: 'كل المكاتب', en: 'All offices' }], ...OFFICES.map((o) => [o.id, o.district])];
  const adminCount = staff.filter((s) => s.role === 'admin' && s.accountStatus === 'active' && !s.deactivated).length;

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative min-w-[220px] flex-1">
          <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={18} /></div>
          <input value={q} onChange={(e) => setQ(e.target.value)}
            placeholder={lang === 'ar' ? 'ابحث بالاسم أو الهاتف أو البريد…' : 'Search name, phone or email…'}
            className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[14px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
        </div>
        <div className="hidden items-center gap-1.5 lg:flex">
          {roleChips.map(([k, v]) => <FilterChip key={k} active={role === k} onClick={() => setRole(k)}>{tt(v, lang)}</FilterChip>)}
        </div>
        <Dropdown lang={lang} value={status} setValue={setStatus} options={statusOpts} icon="filter" label={lang === 'ar' ? 'الحالة' : 'Status'} />
        <Dropdown lang={lang} value={office} setValue={setOffice} options={officeOpts} icon="building" label={lang === 'ar' ? 'المكتب' : 'Office'} />
        <div className="ms-auto flex items-center gap-3">
          <span className="hidden text-[13px] text-slate-400 tabular-nums sm:inline">
            <span className="font-semibold text-violet-600">{num(adminCount, lang)}</span> {lang === 'ar' ? 'مدير' : 'admins'}
            <span className="mx-1.5 text-slate-300">·</span>{num(filtered.length, lang)} {lang === 'ar' ? 'موظف' : 'staff'}
          </span>
          <button onClick={onAdd} className="inline-flex h-10 items-center gap-2 rounded-lg px-3.5 text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name="userPlus" size={17} strokeWidth={2.1} />{lang === 'ar' ? 'إضافة موظف' : 'Add staff'}
          </button>
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[920px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th className="min-w-[210px]">{lang === 'ar' ? 'الموظف' : 'Staff member'}</Th>
                <Th>{lang === 'ar' ? 'الدور' : 'Role'}</Th>
                <Th>{lang === 'ar' ? 'الحالة' : 'Status'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'المكاتب' : 'Offices'}</Th>
                <Th className="hidden sm:table-cell">{lang === 'ar' ? 'الهاتف' : 'Phone'}</Th>
                <Th className="hidden xl:table-cell">{lang === 'ar' ? 'أُضيف' : 'Created'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((s) => {
                const dim = s.deactivated;
                const isMe = s.id === CURRENT_STAFF_ID;
                return (
                  <tr key={s.id} onClick={() => onOpen(s.id)}
                    className={`cursor-pointer text-[13.5px] transition hover:bg-slate-50/70 ${openId === s.id ? 'bg-[var(--accent-soft)]' : ''}`}>
                    <Td className="min-w-[210px]">
                      <div className="flex items-center gap-3">
                        <div className={dim ? 'opacity-60' : ''}><Avatar name={s.firstName} lang={lang} size={38} /></div>
                        <div className="min-w-0">
                          <div className="flex items-center gap-1.5">
                            <span className="font-semibold text-slate-800" style={{ whiteSpace: 'nowrap' }}>{staffName(s, lang)}</span>
                            {isMe && <span className="rounded bg-[var(--accent-soft)] px-1.5 py-px text-[10px] font-bold" style={{ color: 'var(--accent)' }}>{lang === 'ar' ? 'أنت' : 'You'}</span>}
                            {s.mustChangePassword && <span title={lang === 'ar' ? 'يجب تغيير كلمة المرور' : 'Must change password'} className="text-amber-500"><Icon name="key" size={13} /></span>}
                          </div>
                          <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr', whiteSpace: 'nowrap' }}>{s.id}</div>
                        </div>
                      </div>
                    </Td>
                    <Td><StaffRoleBadge role={s.role} lang={lang} /></Td>
                    <Td>{s.deactivated ? <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold" style={{ background: tint('#64748b', 14), color: '#64748b' }}><Icon name="power" size={12} />{lang === 'ar' ? 'مُعطّل' : 'Deactivated'}</span> : <AccountChip status={s.accountStatus} lang={lang} />}</Td>
                    <Td className="hidden lg:table-cell"><AssignmentChips staff={s} lang={lang} max={2} /></Td>
                    <Td className="hidden sm:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr' }}>{s.phone}</span></Td>
                    <Td className="hidden xl:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr' }}>{s.createdAt}</span></Td>
                    <Td><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={18} /></span></Td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا يوجد موظفون مطابقون' : 'No matching staff'}</div>}
      </Card>
    </div>
  );
}

Object.assign(window, { Staff, StaffRoleBadge, AssignmentChips });
