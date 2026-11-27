import { defineConfig } from 'vitest/config';

// Tests for the browser scripts in assets/. CI runs them through
// grmt/wp-plugin-ci's quality workflow, which adds lcov coverage for
// Codecov and SonarQube Cloud.
export default defineConfig({
  test: {
    include: ['tests/js/**/*.test.js'],
    environment: 'jsdom',
    restoreMocks: true,
    coverage: {
      include: ['assets/**/*.js'],
    },
  },
});
