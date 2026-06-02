import { defineStore } from 'pinia';

export const useUiStore = defineStore('ui', {
  state: () => ({
    darkMode: localStorage.getItem('cdnlite.theme') !== 'light',
    commandPaletteOpen: false,
    tableDensity: localStorage.getItem('cdnlite.tableDensity') || 'comfortable',
  }),
  actions: {
    toggleDarkMode() {
      this.darkMode = !this.darkMode;
      document.documentElement.classList.toggle('dark', this.darkMode);
      localStorage.setItem('cdnlite.theme', this.darkMode ? 'dark' : 'light');
    },
    setTableDensity(value: string) {
      this.tableDensity = value;
      localStorage.setItem('cdnlite.tableDensity', value);
    },
  },
});
