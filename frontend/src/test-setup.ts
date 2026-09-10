import '@testing-library/react';

/**
 * jsdom has no scrolling, and the router restores scroll position on every
 * navigation. Left alone that prints "Not implemented: Window's scrollTo()" for
 * each one, which buries the output a failing test needs — so it is stubbed
 * rather than tolerated. Nothing asserts on scroll position; a real browser does
 * the real thing in the Playwright suite.
 */
window.scrollTo = () => undefined;
