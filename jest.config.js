const path = require( 'path' );

module.exports = {
  testEnvironment: 'jsdom',
  testMatch: [ '**/tests/js/**/*.test.js' ],
  rootDir: '.',
  coverageDirectory: '.phpunit.cache',
  coverageReporters: [ 'clover' ],
  collectCoverageFrom: [ 'src/js/**/*.js' ],
  moduleNameMapper: {
    '^@ids/(.*)$': path.resolve( __dirname, '../indivisible-shared/src/js/$1' ),
  },
};
