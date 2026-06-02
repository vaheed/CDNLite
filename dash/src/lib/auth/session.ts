let sessionToken = '';

export function setAdminSessionToken(token: string) {
  sessionToken = token;
}

export function getAdminSessionToken() {
  return sessionToken;
}
