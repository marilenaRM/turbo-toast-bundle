import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        environmentOptions: {
            jsdom: {
                // requestAnimationFrame (toast fade-in) and a http origin
                // (cookie jar) are both needed by the controllers under test.
                pretendToBeVisual: true,
                url: 'http://localhost/',
            },
        },
        include: ['tests/**/*.test.js'],
    },
});
