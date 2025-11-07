const defaultWsBase = (() => {
  if (typeof window === 'undefined') {
    return 'ws://localhost:8083/ws';
  }
  const { protocol, hostname } = window.location;
  const scheme = protocol === 'https:' ? 'wss' : 'ws';
  return `${scheme}://${hostname}:8083/ws`;
})();

export const appConfig = {
  wsBase: import.meta.env.VITE_WS_BASE || defaultWsBase,
};
