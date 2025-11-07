import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    include: ['tests/unit/**/*.spec.js'],
    environment: 'jsdom',
    globals: true,
    setupFiles: './vitest.setup.js',
    exclude: ['tests/e2e/**'],
  },
});
