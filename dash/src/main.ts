import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { createApp } from 'vue';
import App from './App.vue';
import { runtimeConfig } from './lib/config/env';
import router from './router';
import './styles.css';

document.documentElement.classList.toggle('dark', localStorage.getItem('cdnlite.theme') !== 'light');

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: runtimeConfig.dashboardRefreshSeconds * 1000,
    },
  },
});

createApp(App)
  .use(createPinia())
  .use(router)
  .use(VueQueryPlugin, { queryClient })
  .mount('#app');
