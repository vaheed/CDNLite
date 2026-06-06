const storageKey = 'cdnlite.admin.session';
let sessionToken = readStoredToken();

export function setAdminSessionToken(token: string) {
  sessionToken = token;
  if (typeof window === 'undefined') return;
  if (token) window.sessionStorage.setItem(storageKey, token);
  else window.sessionStorage.removeItem(storageKey);
}

export function getAdminSessionToken() {
  return sessionToken;
}

function readStoredToken() {
  if (typeof window === 'undefined') return '';
  return window.sessionStorage.getItem(storageKey) ?? '';
}
