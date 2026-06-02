import { defineStore } from 'pinia';

export const useEdgeSessionStore = defineStore('edgeSession', {
  state: () => ({
    edgeId: '',
    token: '',
  }),
  getters: {
    configured: (state) => state.edgeId.trim().length > 0 && state.token.trim().length > 0,
  },
  actions: {
    setCredentials(edgeId: string, token: string) {
      this.edgeId = edgeId;
      this.token = token;
    },
    clear() {
      this.edgeId = '';
      this.token = '';
    },
  },
});
