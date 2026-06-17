// icons.jsx — single stroke-icon component (Lucide-style geometry, original paths).
function Icon({ name, className, size, strokeWidth }) {
  const s = size || 20;
  const sw = strokeWidth || 1.7;
  const P = ICON_PATHS[name] || ICON_PATHS.dot;
  return (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none"
      stroke="currentColor" strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round"
      className={className} aria-hidden="true">
      {P}
    </svg>
  );
}

const ICON_PATHS = {
  overview: (<><rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" /></>),
  orders: (<><path d="M3 8.5 12 4l9 4.5M3 8.5v7L12 20m-9-11.5L12 13m9-4.5v7L12 20m9-11.5L12 13m0 7V13" /></>),
  users: (<><circle cx="9" cy="8" r="3.2" /><path d="M3.5 19a5.5 5.5 0 0 1 11 0" /><path d="M16 5.2a3 3 0 0 1 0 5.6" /><path d="M17.5 14.2A5.2 5.2 0 0 1 20.5 19" /></>),
  drivers: (<><path d="M3 7h10v8H3z" /><path d="M13 10h4l3 3v2h-7z" /><circle cx="7" cy="17.5" r="1.8" /><circle cx="16.5" cy="17.5" r="1.8" /></>),
  merchants: (<><path d="M4 9.5 5 4h14l1 5.5" /><path d="M4 9.5a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0" /><path d="M5 11.5V20h14v-8.5" /><path d="M10 20v-4.5h4V20" /></>),
  settlements: (<><rect x="3" y="6" width="18" height="13" rx="2" /><path d="M3 10h18" /><circle cx="16.5" cy="14.5" r="1.6" /></>),
  staff: (<><circle cx="12" cy="8" r="3.2" /><path d="M6 19a6 6 0 0 1 12 0" /><circle cx="18.5" cy="6.5" r="2.2" /></>),
  logout: (<><path d="M14 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-2" /><path d="M10 12h10m0 0-3-3m3 3-3 3" /></>),
  chevronL: (<path d="M15 6l-6 6 6 6" />),
  chevronR: (<path d="M9 6l6 6-6 6" />),
  chevronD: (<path d="M6 9l6 6 6-6" />),
  search: (<><circle cx="11" cy="11" r="7" /><path d="m20 20-3.2-3.2" /></>),
  bell: (<><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6" /><path d="M10 19a2 2 0 0 0 4 0" /></>),
  building: (<><rect x="5" y="3" width="14" height="18" rx="1.5" /><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2" /></>),
  filter: (<path d="M3 5h18l-7 8v5l-4 2v-7z" />),
  close: (<path d="M6 6l12 12M18 6 6 18" />),
  check: (<path d="M5 12.5 10 17 19 7" />),
  clock: (<><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></>),
  phone: (<path d="M6.5 4h3l1.5 4-2 1.5a11 11 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4.5 6a2 2 0 0 1 2-2Z" />),
  pin: (<><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z" /><circle cx="12" cy="10" r="2.6" /></>),
  plus: (<path d="M12 5v14M5 12h14" />),
  arrowUp: (<path d="M12 19V5m0 0-6 6m6-6 6 6" />),
  arrowDn: (<path d="M12 5v14m0 0 6-6m-6 6-6-6" />),
  menu: (<path d="M4 7h16M4 12h16M4 17h16" />),
  globe: (<><circle cx="12" cy="12" r="8.5" /><path d="M3.5 12h17M12 3.5c2.5 2.6 2.5 14.4 0 17M12 3.5c-2.5 2.6-2.5 14.4 0 17" /></>),
  truck: (<><path d="M3 7h10v8H3z" /><path d="M13 10h4l3 3v2h-7z" /><circle cx="7" cy="17.5" r="1.8" /><circle cx="16.5" cy="17.5" r="1.8" /></>),
  box: (<><path d="M3 8.5 12 4l9 4.5v7L12 20l-9-4.5z" /><path d="M3 8.5 12 13l9-4.5M12 13v7" /></>),
  user: (<><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" /></>),
  xCircle: (<><circle cx="12" cy="12" r="8.5" /><path d="M9 9l6 6M15 9l-6 6" /></>),
  flag: (<><path d="M5 21V4M5 5h11l-2 3 2 3H5" /></>),
  shield: (<path d="M12 3l7 3v5c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6l7-3Z" />),
  route: (<><circle cx="6" cy="18" r="2.2" /><circle cx="18" cy="6" r="2.2" /><path d="M8 16.5 16 7.5" strokeDasharray="2 2.4" /></>),
  more: (<><circle cx="5" cy="12" r="1.3" /><circle cx="12" cy="12" r="1.3" /><circle cx="19" cy="12" r="1.3" /></>),
  download: (<><path d="M12 4v10m0 0 4-4m-4 4-4-4" /><path d="M5 19h14" /></>),
  dot: (<circle cx="12" cy="12" r="3" />),
  sun: (<><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4" /></>),
  wallet: (<><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H17a2 2 0 0 1 2 2v.5" /><path d="M3 7.5V18a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3.5M3 7.5h16a2 2 0 0 1 2 2v1.5h-5a2 2 0 0 0 0 4h5" /></>),
  coins: (<><ellipse cx="9" cy="7" rx="5.5" ry="2.6" /><path d="M3.5 7v5c0 1.4 2.5 2.6 5.5 2.6s5.5-1.2 5.5-2.6V7" /><path d="M9 14.5v2.5c0 1.4 2.5 2.6 5.5 2.6s5.5-1.2 5.5-2.6v-5" /><ellipse cx="14.5" cy="9.5" rx="5.5" ry="2.6" /></>),
  doc: (<><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5M9 13h6M9 16.5h4" /></>),
  alert: (<><path d="M10.3 4.3 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z" /><path d="M12 9.5v4M12 17h.01" /></>),
  ban: (<><circle cx="12" cy="12" r="8.5" /><path d="M6 6l12 12" /></>),
  power: (<><path d="M12 4v8" /><path d="M7.5 6.6a7 7 0 1 0 9 0" /></>),
  pause: (<><rect x="7" y="5" width="3.4" height="14" rx="1" /><rect x="13.6" y="5" width="3.4" height="14" rx="1" /></>),
  edit: (<><path d="M5 19h3l9.5-9.5a2 2 0 0 0-3-3L5 16z" /><path d="M14 6.5 17.5 10" /></>),
  star: (<path d="M12 4l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8z" />),
  calendar: (<><rect x="4" y="5" width="16" height="16" rx="2" /><path d="M4 9.5h16M8 3v4M16 3v4" /></>),
  mail: (<><rect x="3" y="5.5" width="18" height="13" rx="2" /><path d="m4 7 8 5.5L20 7" /></>),
  history: (<><path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8" /><path d="M3.5 4v4h4M12 7.5V12l3 2" /></>),
  car: (<><path d="M4 13l1.6-4.5A2 2 0 0 1 7.5 7h9a2 2 0 0 1 1.9 1.5L20 13" /><path d="M3 13h18v4a1 1 0 0 1-1 1h-1.5a1 1 0 0 1-1-1v-1H6.5v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" /><path d="M6.5 15.5h.01M17.5 15.5h.01" /></>),
  bike: (<><circle cx="6" cy="17" r="3" /><circle cx="18" cy="17" r="3" /><path d="M6 17l3.5-7h4l2.5 5M9 10h5M14 7h2.5l1.5 3" /></>),
  checkCircle: (<><circle cx="12" cy="12" r="8.5" /><path d="m8.5 12 2.5 2.5 4.5-5" /></>),
  message: (<><path d="M4 5.5h16a1 1 0 0 1 1 1V15a1 1 0 0 1-1 1H9l-4 3.5V16H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Z" /></>),
  undo: (<><path d="M9 7 4.5 11.5 9 16" /><path d="M4.5 11.5H14a5 5 0 0 1 0 10h-2" /></>),
  send: (<><path d="M21 4 3 11l6 2.5L12 20l3-7z" /><path d="m9 13.5 3-2.5" /></>),
  percent: (<><circle cx="7.5" cy="7.5" r="2.2" /><circle cx="16.5" cy="16.5" r="2.2" /><path d="M18 6 6 18" /></>),
  pin: (<><path d="M12 21s-6.5-5.6-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21Z" /><circle cx="12" cy="10.5" r="2.4" /></>),
  finance: (<><path d="M4 19V5M4 19h16" /><path d="M8 16l3.5-4 3 2.5L20 8" /><path d="M20 8h-3M20 8v3" /></>),
  settings: (<><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1" /></>),
  trend: (<><path d="M3 17l5-5 4 3 6-7" /><path d="M18 8h3v3" /></>),
  scale: (<><path d="M12 3v18M7 7h10" /><path d="M7 7l-3 6a3 3 0 0 0 6 0z" /><path d="M17 7l-3 6a3 3 0 0 0 6 0z" /></>),
  key: (<><circle cx="8" cy="14" r="3.5" /><path d="M10.5 11.5 20 2M17 5l2.5 2.5M14.5 7.5 17 10" /></>),
  userPlus: (<><circle cx="9" cy="8" r="3.4" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0" /><path d="M18 8v6M15 11h6" /></>),
  copy: (<><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></>),
};

Object.assign(window, { Icon });
