// users.jsx — users roster. Everyone in the system; drivers are the subset
// with a driver profile. Mirrors the Drivers pipe, a touch simpler.

function RoleBadges({ roles, lang, size }) {
  const sm = size === 'sm';
  return (
    <span className="inline-flex items-center gap-1">
      <span className="inline-flex items-center gap-1 rounded-md font-semibold" style={{ background: tint('#64748b', 14), color: '#64748b', padding: sm ? '2px 7px' : '3px 8px', fontSize: sm ? '11px' : '11.5px' }}>
        <Icon name="user" size={sm ? 12 : 13} />{lang === 'ar' ? 'عميل' : 'Customer'}
      </span>
      {roles.includes('driver') && (
        <span className="inline-flex items-center gap-1 rounded-md font-semibold" style={{ background: tint('#2563eb', 14), color: '#2563eb', padding: sm ? '2px 7px' : '3px 8px', fontSize: sm ? '11px' : '11.5px' }}>
          <Icon name="drivers" size={sm ? 12 : 13} />{lang === 'ar' ? 'سائق' : 'Driver'}
        </span>
      )}
      {roles.includes('merchant') && (
        <span className="inline-flex items-center gap-1 rounded-md font-semibold" style={{ background: tint('#0d9488', 14), color: '#0d9488', padding: sm ? '2px 7px' : '3px 8px', fontSize: sm ? '11px' : '11.5px' }}>
          <Icon name="merchants" size={sm ? 12 : 13} />{lang === 'ar' ? 'تاجر' : 'Merchant'}
        </span>
      )}
    </span>
  );
}

function Users({ lang, users, onOpen, openId }) {
  const [q, setQ] = React.useState('');
  const [status, setStatus] = React.useState('all');
  const [role, setRole] = React.useState('all');

  const filtered = users.filter((u) => {
    if (status !== 'all' && u.accountStatus !== status) return false;
    if (role === 'drivers' && !u.driverId) return false;
    if (role === 'customers' && u.driverId) return false;
    if (q.trim()) {
      const hay = [u.id, tt(u.name, lang), tt(u.name, 'en'), u.phone, u.email].join(' ').toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    return true;
  });

  const statusOpts = [['all', { ar: 'كل الحالات', en: 'All statuses' }], ...Object.keys(ACCOUNT_STATUS).map((k) => [k, ACCOUNT_STATUS[k]])];
  const roleChips = [
    ['all', { ar: 'الكل', en: 'All' }],
    ['customers', { ar: 'عملاء', en: 'Customers' }],
    ['drivers', { ar: 'سائقون', en: 'Drivers' }],
  ];
  const driverCount = users.filter((u) => u.driverId).length;

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative min-w-[220px] flex-1">
          <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={18} /></div>
          <input value={q} onChange={(e) => setQ(e.target.value)}
            placeholder={lang === 'ar' ? 'ابحث بالاسم أو الهاتف أو البريد…' : 'Search name, phone or email…'}
            className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[14px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
        </div>
        <Dropdown lang={lang} value={status} setValue={setStatus} options={statusOpts} icon="filter"
          label={lang === 'ar' ? 'الحالة' : 'Status'} />
        <div className="hidden items-center gap-1.5 lg:flex">
          {roleChips.map(([k, v]) => (
            <FilterChip key={k} active={role === k} onClick={() => setRole(k)}>{tt(v, lang)}</FilterChip>
          ))}
        </div>
        <span className="ms-auto hidden text-[13px] text-slate-400 tabular-nums sm:inline">
          {num(filtered.length, lang)} {lang === 'ar' ? 'مستخدم' : 'users'}
          <span className="mx-1.5 text-slate-300">·</span>
          <span className="font-semibold text-slate-600">{num(driverCount, lang)}</span> {lang === 'ar' ? 'سائق' : 'drivers'}
        </span>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th className="min-w-[200px]">{lang === 'ar' ? 'المستخدم' : 'User'}</Th>
                <Th>{lang === 'ar' ? 'الحساب' : 'Account'}</Th>
                <Th>{lang === 'ar' ? 'الأدوار' : 'Roles'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'الطلبات' : 'Orders'}</Th>
                <Th className="hidden xl:table-cell">{lang === 'ar' ? 'انضمّ' : 'Joined'}</Th>
                <Th className="hidden sm:table-cell">{lang === 'ar' ? 'الهاتف' : 'Phone'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((u) => {
                const dim = u.accountStatus === 'banned';
                return (
                  <tr key={u.id} onClick={() => onOpen(u.id)}
                    className={`cursor-pointer text-[13.5px] transition hover:bg-slate-50/70 ${openId === u.id ? 'bg-[var(--accent-soft)]' : ''}`}>
                    <Td className="min-w-[200px]">
                      <div className="flex items-center gap-3">
                        <div className={dim ? 'opacity-60' : ''}><Avatar name={u.name} lang={lang} size={38} /></div>
                        <div className="min-w-0">
                          <div className="flex items-center gap-1.5">
                            <span className="font-semibold text-slate-800" style={{ whiteSpace: 'nowrap' }}>{tt(u.name, lang)}</span>
                            {!u.phoneVerified && <span title={lang === 'ar' ? 'هاتف غير موثّق' : 'Phone unverified'} className="shrink-0 text-amber-500"><Icon name="alert" size={13} /></span>}
                          </div>
                          <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr', display: 'inline-block', whiteSpace: 'nowrap' }}>{u.id}</div>
                        </div>
                      </div>
                    </Td>
                    <Td><AccountChip status={u.accountStatus} lang={lang} /></Td>
                    <Td><RoleBadges roles={u.roles} lang={lang} size="sm" /></Td>
                    <Td className="hidden lg:table-cell"><span className="font-semibold text-slate-700 tabular-nums">{num(u.orders, lang)}</span></Td>
                    <Td className="hidden xl:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr' }}>{u.joined}</span></Td>
                    <Td className="hidden sm:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr' }}>{u.phone}</span></Td>
                    <Td><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={18} /></span></Td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && (
          <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا يوجد مستخدمون مطابقون' : 'No matching users'}</div>
        )}
      </Card>
    </div>
  );
}

Object.assign(window, { Users, RoleBadges });
