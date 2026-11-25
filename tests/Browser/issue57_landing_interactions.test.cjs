const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function makeClassList(initial = []) {
    const classes = new Set(initial);

    return {
        add: (...names) => names.forEach((name) => classes.add(name)),
        remove: (...names) => names.forEach((name) => classes.delete(name)),
        toggle: (name, force) => {
            const active = force === undefined ? !classes.has(name) : Boolean(force);
            if (active) {
                classes.add(name);
            } else {
                classes.delete(name);
            }
            return active;
        },
        contains: (name) => classes.has(name),
    };
}

function makeDocumentElement({ theme = 'light', properties = new Map() } = {}) {
    const attributes = new Map([['data-bs-theme', theme]]);
    const classList = makeClassList(theme === 'dark' ? ['dark'] : []);

    return {
        dataset: {},
        classList,
        style: {
            colorScheme: theme,
            setProperty: (name, value) => properties.set(name, value),
        },
        getAttribute: (name) => attributes.get(name) ?? null,
        setAttribute: (name, value) => attributes.set(name, value),
    };
}

function landingControlsWith({ mapBounds = null, dark = true, scrollY = 500 } = {}) {
    const windowHandlers = new Map();
    const frames = [];
    const visibleClasses = makeClassList();
    const foreground = new Map();
    const mapFrame = { getBoundingClientRect: () => mapBounds };
    const scrollButton = {
        classList: visibleClasses,
        addEventListener: () => {},
        getBoundingClientRect: () => ({ left: 290, right: 334, height: 44 }),
    };
    const target = {
        getBoundingClientRect: () => ({ left: 100, top: 20, width: 60, height: 30 }),
        setAttribute: (name, value) => { foreground.set(name, value); },
    };
    const themedSurface = {
        matches: () => false,
        getAttribute: () => 'theme',
    };
    const mapSurface = {
        matches: (selector) => selector === 'iframe.institution-map',
        getAttribute: () => null,
    };
    let behindNavbar = { closest: () => themedSurface };
    const navbar = {
        contains: (element) => element === target,
        querySelector: () => null,
        querySelectorAll: () => [target],
        getBoundingClientRect: () => ({ bottom: 80 }),
        addEventListener: () => {},
    };
    const window = {
        location: { hash: '', search: '' },
        scrollY,
        innerWidth: 360,
        innerHeight: 800,
        matchMedia: (query) => ({ matches: query.includes('dark') ? dark : false, addEventListener: () => {} }),
        getComputedStyle: () => ({ bottom: '56px' }),
        requestAnimationFrame: (callback) => { frames.push(callback); return frames.length; },
        addEventListener: (event, listener) => {
            windowHandlers.set(event, [...(windowHandlers.get(event) ?? []), listener]);
        },
    };
    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement({ theme: dark ? 'dark' : 'light' }),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => ({
            '.btn-scroll-top': scrollButton,
            '.navbar': navbar,
            'iframe.institution-map': mapBounds ? mapFrame : null,
        }[selector] ?? null),
        querySelectorAll: () => [],
        elementsFromPoint: () => [target, behindNavbar],
        getElementById: () => null,
    };
    const flushFrames = () => { while (frames.length) { frames.shift()(); } };
    const emit = (event) => {
        for (const listener of windowHandlers.get(event) ?? []) { listener(); }
        flushFrames();
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    flushFrames();

    return {
        visible: () => visibleClasses.contains('visible'),
        foreground: () => foreground.get('data-navbar-foreground'),
        showMap: () => {
            behindNavbar = {
                closest: (selector) => selector.includes('iframe.institution-map') ? mapSurface : themedSurface,
            };
        },
        showTheme: () => { behindNavbar = { closest: () => themedSurface }; },
        setMapBounds: (bounds) => { mapBounds = bounds; },
        emit,
        window,
    };
}

test('navigation uses dark text over the visible map in dark mode and restores light text over the page', () => {
    const landing = landingControlsWith();
    assert.equal(landing.foreground(), 'white');

    landing.showMap();
    landing.emit('scroll');

    assert.equal(landing.foreground(), 'black');

    landing.showTheme();
    landing.emit('scroll');

    assert.equal(landing.foreground(), 'white');
});

test('navigation retains dark text on ordinary light theme surfaces', () => {
    const landing = landingControlsWith({ dark: false });

    assert.equal(landing.foreground(), 'black');
});

test('return control steps aside while it would cover the embedded map controls', () => {
    const landing = landingControlsWith({ mapBounds: { left: 16, right: 396, top: 300, bottom: 760 } });
    assert.equal(landing.visible(), false);

    landing.setMapBounds({ left: 16, right: 396, top: -400, bottom: 60 });
    landing.emit('scroll');

    assert.equal(landing.visible(), true);
});

function navigationWith({ hash = '', hasNavbarBar = true, innerWidth = 1280 } = {}) {
    const windowHandlers = new Map();
    const navigationHandlers = new Map();
    const navbarHandlers = new Map();
    const navListHandlers = new Map();
    const properties = new Map();
    let navbarHeight = 80;
    const closedBarHeight = 80;
    let topOffset = 0;
    let programsOffset = 700;
    let shown = false;
    const register = (handlers, event, listener) => handlers.set(event, [...(handlers.get(event) ?? []), listener]);
    const emit = (handlers, event, data) => (handlers.get(event) ?? []).forEach((listener) => listener(data));
    const makeLink = (fragment, left = 0, width = 80) => {
        const attributes = new Map();
        return {
            hash: fragment,
            attributes,
            getAttribute: (name) => attributes.get(name) ?? null,
            setAttribute: (name, value) => attributes.set(name, value),
            removeAttribute: (name) => attributes.delete(name),
            getBoundingClientRect: () => ({ left, width }),
            focus() { document.activeElement = this; },
            closest() { return this; },
        };
    };
    const home = makeLink('#top', 24, 60);
    const noticesLink = makeLink('#notices', 96, 110);
    const programsLink = makeLink('#programs', 218, 90);
    const toggle = makeLink('');
    const notices = makeLink('');
    const top = { id: 'top', getBoundingClientRect: () => ({ top: topOffset }) };
    const programs = { id: 'programs', getBoundingClientRect: () => ({ top: programsOffset }) };
    const navbarBar = { getBoundingClientRect: () => ({ height: closedBarHeight }) };
    const navIndicator = { style: { transform: '', width: '', opacity: '0' } };
    const navList = {
        getBoundingClientRect: () => ({ left: 20, width: 320 }),
        querySelector: (selector) => selector === '[data-nav-indicator]' ? navIndicator : null,
        addEventListener: (event, listener) => register(navListHandlers, event, listener),
    };
    const navigation = {
        classList: { contains: (name) => name === 'show' && shown },
        querySelector: (selector) => selector === '.navbar-nav' ? navList : home,
        querySelectorAll: () => [home, noticesLink, programsLink],
        addEventListener: (event, listener) => register(navigationHandlers, event, listener),
        contains: (element) => [home, noticesLink, programsLink].includes(element),
    };
    const navbar = {
        querySelector: (selector) => {
            if (selector === '.navbar-toggler') { return toggle; }
            if (selector.includes('data-navbar-bar') && hasNavbarBar) { return navbarBar; }
            return null;
        },
        querySelectorAll: () => [],
        getBoundingClientRect: () => ({ bottom: navbarHeight, height: navbarHeight }),
        addEventListener: (event, listener) => register(navbarHandlers, event, listener),
    };
    const document = {
        readyState: 'loading',
        activeElement: null,
        documentElement: makeDocumentElement({ properties }),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '.navbar' ? navbar : null,
        querySelectorAll: (selector) => selector === 'main section[id]' ? [top, programs] : [],
        getElementById: (id) => ({ navbarNav: navigation, notices, top, programs }[id] ?? null),
    };
    const hide = () => {
        shown = false;
        navbarHeight = 80;
        emit(navigationHandlers, 'hidden.bs.collapse');
    };
    const window = {
        innerWidth,
        location: { hash, search: '' },
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        addEventListener: (event, listener) => register(windowHandlers, event, listener),
        bootstrap: { Collapse: { getOrCreateInstance: () => ({ hide }) } },
    };
    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });

    return {
        current: () => [home, noticesLink, programsLink].find((link) => link.attributes.has('aria-current'))?.hash,
        goHome: () => { window.location.hash = '#top'; emit(windowHandlers, 'hashchange'); },
        scrollToPrograms: () => { topOffset = -700; programsOffset = 0; emit(windowHandlers, 'scroll'); },
        hoverPrograms: () => emit(navListHandlers, 'pointerover', { target: programsLink }),
        leaveNav: () => emit(navListHandlers, 'pointerleave'),
        open: () => { shown = true; navbarHeight = 320; emit(navigationHandlers, 'shown.bs.collapse'); },
        resize: (nextWidth) => {
            if (nextWidth !== undefined) {
                window.innerWidth = nextWidth;
            }
            emit(windowHandlers, 'resize');
        },
        followNotices: () => emit(navigationHandlers, 'click', { target: noticesLink }),
        escape: () => emit(navbarHandlers, 'keydown', { key: 'Escape', preventDefault() {} }),
        height: () => properties.get('--tala-navbar-height'),
        indicator: () => ({ ...navIndicator.style }),
        toggleLabel: () => toggle.attributes.get('aria-label'),
        focus: () => document.activeElement,
        home,
        toggle,
        notices,
    };
}

test('the nested Announcements destination has the current cue until Home or a later section is reached', () => {
    const navigation = navigationWith({ hash: '#notices' });
    assert.equal(navigation.current(), '#notices');
    navigation.goHome();
    assert.equal(navigation.current(), '#top');

    const announcements = navigationWith({ hash: '#notices' });
    announcements.scrollToPrograms();
    assert.equal(announcements.current(), '#programs');
});

test('sliding nav indicator tracks active section and hovered desktop link, and hides on mobile', () => {
    const navigation = navigationWith({ innerWidth: 1280 });
    assert.deepEqual(navigation.indicator(), { transform: 'translateX(4px)', width: '60px', opacity: '1' });

    navigation.hoverPrograms();
    assert.deepEqual(navigation.indicator(), { transform: 'translateX(198px)', width: '90px', opacity: '1' });

    navigation.leaveNav();
    assert.deepEqual(navigation.indicator(), { transform: 'translateX(4px)', width: '60px', opacity: '1' });

    navigation.scrollToPrograms();
    assert.deepEqual(navigation.indicator(), { transform: 'translateX(198px)', width: '90px', opacity: '1' });

    navigation.resize(390);
    assert.equal(navigation.indicator().opacity, '0');
});

test('opening and closing mobile navigation keeps masthead height stable and restores toggle focus on Escape', () => {
    for (const hasNavbarBar of [true, false]) {
        const navigation = navigationWith({ hasNavbarBar, innerWidth: 390 });
        assert.equal(navigation.height(), '80px');
        navigation.open();
        navigation.resize();
        assert.equal(navigation.height(), '80px');
        assert.equal(navigation.toggleLabel(), 'Close navigation menu');
        assert.equal(navigation.focus(), navigation.home);
        navigation.escape();
        assert.equal(navigation.height(), '80px');
        assert.equal(navigation.toggleLabel(), 'Open navigation menu');
        assert.equal(navigation.focus(), navigation.toggle);
    }
});

test('following an anchor closes mobile navigation and focuses the destination after collapse', () => {
    const navigation = navigationWith({ innerWidth: 390 });
    navigation.open();
    navigation.followNotices();
    assert.equal(navigation.height(), '80px');
    assert.equal(navigation.focus(), navigation.notices);
});

test('automatic OS theme sync updates data-bs-theme, .dark class, colorScheme, and navbar contrast without localStorage', () => {
    let colorSchemeListener = null;
    let headStyleAppended = false;
    let headStyleRemoved = false;
    const frames = [];
    const contrastTarget = {
        attrs: new Map(),
        getBoundingClientRect: () => ({ left: 100, top: 20, width: 60, height: 30 }),
        setAttribute: (name, value) => contrastTarget.attrs.set(name, value),
    };
    const themedSurface = { matches: () => false, getAttribute: () => 'theme' };
    const navbar = {
        contains: (element) => element === contrastTarget,
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '[data-navbar-contrast-target]' ? [contrastTarget] : [],
        getBoundingClientRect: () => ({ bottom: 80, height: 80 }),
        addEventListener: () => {},
    };
    const documentElement = makeDocumentElement({ theme: 'light' });
    const window = {
        location: { hash: '', search: '' },
        innerWidth: 1280,
        innerHeight: 800,
        matchMedia: (query) => ({
            matches: false,
            addEventListener: (event, listener) => {
                if (query.includes('dark') && event === 'change') {
                    colorSchemeListener = listener;
                }
            },
        }),
        getComputedStyle: () => ({ getPropertyValue: () => '1' }),
        requestAnimationFrame: (callback) => { frames.push(callback); return frames.length; },
        addEventListener: () => {},
    };
    const document = {
        readyState: 'loading',
        documentElement,
        head: {
            appendChild: (node) => {
                headStyleAppended = true;
                node.remove = () => { headStyleRemoved = true; };
            },
        },
        createElement: () => ({ textContent: '' }),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '.navbar' ? navbar : null,
        querySelectorAll: () => [],
        elementsFromPoint: () => [contrastTarget, { closest: () => themedSurface }],
        getElementById: () => null,
    };
    const flushFrames = () => { while (frames.length) { frames.shift()(); } };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    flushFrames();

    assert.equal(documentElement.getAttribute('data-bs-theme'), 'light');
    assert.equal(documentElement.classList.contains('dark'), false);
    assert.equal(documentElement.style.colorScheme, 'light');
    assert.equal(contrastTarget.attrs.get('data-navbar-foreground'), 'black');

    colorSchemeListener({ matches: true });
    assert.equal(headStyleAppended, true);
    assert.equal(documentElement.getAttribute('data-bs-theme'), 'dark');
    assert.equal(documentElement.classList.contains('dark'), true);
    assert.equal(documentElement.style.colorScheme, 'dark');

    flushFrames();
    assert.equal(headStyleRemoved, true);
    assert.equal(contrastTarget.attrs.get('data-navbar-foreground'), 'white');
});

test('cursor spotlight updates --mouse-x and --mouse-y percentages on hover-capable motion-enabled devices', () => {
    const cardHandlers = new Map();
    const cardProps = new Map();
    let rect = { left: 100, top: 200, width: 200, height: 100 };
    const card = {
        getBoundingClientRect: () => rect,
        style: { setProperty: (name, value) => cardProps.set(name, value) },
        addEventListener: (event, listener) => cardHandlers.set(event, listener),
    };
    const window = {
        location: { hash: '', search: '' },
        matchMedia: (query) => ({
            matches: query.includes('hover: hover') || query.includes('no-preference'),
            addEventListener: () => {},
        }),
        ResizeObserver: class {
            observe() {}
        },
        addEventListener: () => {},
    };
    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement(),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '[data-spotlight]' ? [card] : [],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });

    cardHandlers.get('pointerenter')();
    cardHandlers.get('pointermove')({ clientX: 200, clientY: 250 });
    assert.equal(cardProps.get('--mouse-x'), '50.0%');
    assert.equal(cardProps.get('--mouse-y'), '50.0%');
});

test('copy campus address works via CommandEvent and click fallback with live feedback and 2s reset', async () => {
    for (const hasCommandEvent of [true, false]) {
        const docHandlers = new Map();
        const commandCaptures = [];
        const timers = [];
        const buttonClasses = makeClassList();
        const feedback = { textContent: '' };
        const wrapper = {
            querySelector: (selector) => selector.includes('data-copy-feedback') ? feedback : null,
        };
        const button = {
            classList: buttonClasses,
            getAttribute: (name) => name === 'commandfor' ? 'campus-address' : null,
            closest: (selector) => selector === '.copy-control'
                ? wrapper
                : selector.includes('command="--copy"')
                    ? button
                    : null,
        };
        const address = { textContent: '  123 Servitech Ave, San Pedro  ' };
        let copiedText = null;
        const window = {
            location: { hash: '', search: '' },
            matchMedia: () => ({ matches: false, addEventListener: () => {} }),
            navigator: {
                clipboard: {
                    writeText: async (text) => { copiedText = text; },
                },
            },
            setTimeout: (fn) => { timers.push(fn); return timers.length; },
            clearTimeout: () => {},
            addEventListener: () => {},
        };
        if (hasCommandEvent) {
            window.CommandEvent = function CommandEvent() {};
        }
        const document = {
            readyState: 'loading',
            documentElement: makeDocumentElement(),
            addEventListener: (event, listener, options) => {
                if (event === 'DOMContentLoaded') {
                    listener();
                } else {
                    if (event === 'command') {
                        commandCaptures.push(options === true || options?.capture === true);
                    }
                    docHandlers.set(event, [...(docHandlers.get(event) ?? []), listener]);
                }
            },
            querySelector: () => null,
            querySelectorAll: () => [],
            getElementById: (id) => id === 'campus-address' ? address : null,
        };

        vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });

        if (hasCommandEvent) {
            assert.deepEqual(commandCaptures, [true]);
            docHandlers.get('command').forEach((fn) => fn({ command: '--copy', source: button, target: address }));
        } else {
            docHandlers.get('click').forEach((fn) => fn({ target: button }));
        }

        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(copiedText, '123 Servitech Ave, San Pedro');
        assert.equal(buttonClasses.contains('is-copied'), true);
        assert.equal(feedback.textContent, 'Copied');

        timers[0]();
        assert.equal(buttonClasses.contains('is-copied'), false);
        assert.equal(feedback.textContent, '');
    }
});

function revealWith({ supportsScrollTimeline = false, noPreference = true, hasObserver = true } = {}) {
    let observerCallback = null;
    const observed = [];
    const unobserved = [];
    const makeRevealElement = (top, bottom) => {
        const classList = makeClassList();
        return {
            classList,
            getBoundingClientRect: () => ({ top, bottom }),
        };
    };
    const aboveFold = makeRevealElement(120, 320);
    const belowFold = makeRevealElement(1100, 1400);
    const documentElement = makeDocumentElement();
    const window = {
        innerHeight: 800,
        location: { hash: '', search: '' },
        matchMedia: (query) => ({
            matches: query.includes('no-preference') ? noPreference : false,
            addEventListener: () => {},
        }),
        addEventListener: () => {},
    };

    if (hasObserver) {
        window.IntersectionObserver = class {
            constructor(callback) { observerCallback = callback; }
            observe(element) { observed.push(element); }
            unobserve(element) { unobserved.push(element); }
        };
    }

    const document = {
        readyState: 'loading',
        documentElement,
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '[data-reveal]' ? [aboveFold, belowFold] : [],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), {
        window,
        document,
        URLSearchParams,
        CSS: { supports: () => supportsScrollTimeline },
    });

    return {
        hasFallbackClass: () => documentElement.classList.contains('has-reveal-fallback'),
        isAboveFoldRevealed: () => aboveFold.classList.contains('is-revealed'),
        isBelowFoldRevealed: () => belowFold.classList.contains('is-revealed'),
        observed,
        unobserved,
        aboveFold,
        belowFold,
        intersectBelowFold: () => observerCallback?.(
            [{ target: belowFold, isIntersecting: true }],
            { unobserve: (element) => unobserved.push(element) }
        ),
    };
}

test('scroll reveal fallback observes offscreen elements only when scroll-driven animations are unsupported', () => {
    const landing = revealWith({ supportsScrollTimeline: false, noPreference: true, hasObserver: true });
    assert.equal(landing.hasFallbackClass(), true);
    assert.equal(landing.isAboveFoldRevealed(), true);
    assert.equal(landing.isBelowFoldRevealed(), false);
    assert.deepEqual(landing.observed, [landing.belowFold]);

    landing.intersectBelowFold();
    assert.equal(landing.isBelowFoldRevealed(), true);
    assert.deepEqual(landing.unobserved, [landing.belowFold]);
});

test('scroll reveal fallback stays inactive when scroll-timeline is supported, reduced motion is requested, or IntersectionObserver is missing', () => {
    for (const options of [
        { supportsScrollTimeline: true },
        { noPreference: false },
        { hasObserver: false },
    ]) {
        const landing = revealWith(options);
        assert.equal(landing.hasFallbackClass(), false);
        assert.deepEqual(landing.observed, []);
    }
});

function modalLinkWith({ href = 'https://tala.test/', bootstrap = true, modalPresent = true } = {}) {
    const windowHandlers = new Map();
    const modalHandlers = new Map();
    const opened = [];
    let ready;
    const modalElement = {
        addEventListener: (event, listener) => modalHandlers.set(event, listener),
    };
    const window = {
        location: new URL(href),
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener: (event, listener) => {
            windowHandlers.set(event, [...(windowHandlers.get(event) ?? []), listener]);
        },
        history: {
            replaceState: (state, title, url) => { window.location = new URL(url, window.location); },
        },
    };

    if (bootstrap) {
        window.bootstrap = {
            Modal: { getOrCreateInstance: (element) => ({ show: () => opened.push(element) }) },
        };
    }

    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement(),
        addEventListener: (event, listener) => { ready = listener; },
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: (id) => modalPresent && ['supportModal', 'privacyModal', 'accessibilityModal'].includes(id)
            ? modalElement : null,
    };
    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URL, URLSearchParams });
    ready();

    return {
        opened,
        modalElement,
        location: () => window.location,
        close: () => modalHandlers.get('hidden.bs.modal')?.(),
        follow: (hash) => {
            window.location.hash = hash;
            windowHandlers.get('hashchange').forEach((listener) => listener());
        },
    };
}

test('the Support deep link opens the existing modal on arrival and can reopen after dismissal', () => {
    const landing = modalLinkWith({ href: 'https://tala.test/?duration=4#support' });
    assert.deepEqual(landing.opened, [landing.modalElement]);

    landing.close();
    assert.equal(landing.location().hash, '');
    assert.equal(landing.location().search, '?duration=4');

    landing.follow('#support');
    assert.equal(landing.opened.length, 2);
});

test('Programs and Announcements anchors keep their navigation without opening Support', () => {
    const landing = modalLinkWith();

    landing.follow('#programs');
    landing.follow('#notices');

    assert.deepEqual(landing.opened, []);
    assert.equal(landing.location().hash, '#notices');
});

test('legacy informational modal query links preserve filters and section navigation on dismissal', () => {
    for (const modal of ['support', 'privacy', 'accessibility']) {
        const landing = modalLinkWith({ href: `https://tala.test/?duration=4&modal=${modal}#programs` });
        assert.deepEqual(landing.opened, [landing.modalElement]);

        landing.close();

        assert.equal(landing.location().search, '?duration=4');
        assert.equal(landing.location().hash, '#programs');
    }
});

test('a Support fragment degrades safely if Bootstrap or its modal element is unavailable', () => {
    assert.deepEqual(modalLinkWith({ href: 'https://tala.test/#support', bootstrap: false }).opened, []);
    assert.deepEqual(modalLinkWith({ href: 'https://tala.test/#support', modalPresent: false }).opened, []);
});

test('program detail modal syncs ?program= query parameter and restores focus on dismissal', () => {
    const modalHandlers = new Map();
    const opened = [];
    const readMoreButton = {
        focused: false,
        focus() { this.focused = true; },
    };
    const programModal = {
        dataset: { programDetailModal: '42' },
        addEventListener: (event, listener) => modalHandlers.set(event, listener),
    };
    const window = {
        location: new URL('https://tala.test/?program=42#programs'),
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener: () => {},
        history: {
            replaceState: (state, title, url) => { window.location = new URL(url, window.location); },
        },
        bootstrap: {
            Modal: { getOrCreateInstance: (element) => ({ show: () => opened.push(element) }) },
        },
    };
    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement(),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '[data-program-read-more="42"]' ? readMoreButton : null,
        querySelectorAll: (selector) => selector === '[data-program-detail-modal]' ? [programModal] : [],
        getElementById: (id) => id === 'programDetailModal-42' ? programModal : null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URL, URLSearchParams });

    assert.deepEqual(opened, [programModal]);
    modalHandlers.get('shown.bs.modal')?.();
    assert.equal(window.location.search, '?program=42');

    modalHandlers.get('hidden.bs.modal')?.();
    assert.equal(window.location.search, '');
    assert.equal(window.location.hash, '#programs');
    assert.equal(readMoreButton.focused, true);
});

function physicsWith({ finePointer = true, hoverPointer = true, noPreference = true, modalOpen = false } = {}) {
    const windowHandlers = new Map();
    const docHandlers = new Map();
    const properties = new Map();
    const frames = [];
    const scrollCalls = [];
    const scrollBehaviors = [];
    const documentElement = makeDocumentElement({ properties });
    documentElement.scrollHeight = 2400;
    let fakeTime = 1000;

    const window = {
        scrollY: 0,
        innerWidth: 1200,
        innerHeight: 800,
        location: { hash: '', search: '' },
        matchMedia: (query) => ({
            matches: query.includes('pointer: fine')
                ? finePointer
                : query.includes('hover: hover')
                    ? hoverPointer
                    : query.includes('no-preference')
                        ? noPreference
                        : false,
            addEventListener: () => {},
        }),
        scrollTo: (optionsOrX, maybeY) => {
            const y = typeof optionsOrX === 'object' && optionsOrX !== null ? optionsOrX.top : maybeY;
            const behavior = typeof optionsOrX === 'object' && optionsOrX !== null ? optionsOrX.behavior : undefined;
            window.scrollY = y;
            scrollCalls.push(y);
            scrollBehaviors.push(behavior);
        },
        requestAnimationFrame: (callback) => {
            frames.push(callback);
            return frames.length;
        },
        cancelAnimationFrame: () => {},
        addEventListener: (event, listener) => {
            windowHandlers.set(event, [...(windowHandlers.get(event) ?? []), listener]);
        },
    };
    const document = {
        readyState: 'loading',
        documentElement,
        addEventListener: (event, listener) => {
            if (event === 'DOMContentLoaded') {
                listener();
            } else {
                docHandlers.set(event, [...(docHandlers.get(event) ?? []), listener]);
            }
        },
        querySelector: (selector) => selector === '.modal.show' && modalOpen ? {} : null,
        querySelectorAll: () => [],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), {
        window,
        document,
        URLSearchParams,
        CSS: { supports: () => false },
    });

    return {
        dispatchWheel: (overrides = {}) => {
            let prevented = false;
            const event = {
                deltaX: 0,
                deltaY: 120,
                deltaMode: 0,
                ctrlKey: false,
                metaKey: false,
                target: null,
                preventDefault: () => { prevented = true; },
                ...overrides,
            };
            (windowHandlers.get('wheel') ?? []).forEach((fn) => fn(event));
            return prevented;
        },
        dispatchPointerMove: (clientX, clientY) => {
            (windowHandlers.get('pointermove') ?? []).forEach((fn) => fn({ clientX, clientY }));
        },
        dispatchAsyncScroll: () => {
            (windowHandlers.get('scroll') ?? []).forEach((fn) => fn());
        },
        flushFrames: (limit = 200, { emitAsyncScroll = false } = {}) => {
            let count = 0;
            while (frames.length && count < limit) {
                fakeTime += 16.667;
                frames.shift()(fakeTime);
                if (emitAsyncScroll) {
                    (windowHandlers.get('scroll') ?? []).forEach((fn) => fn());
                }
                count += 1;
            }
        },
        scrollCalls,
        scrollBehaviors,
        window,
        prop: (name) => properties.get(name),
    };
}

test('native compositor scroll is never hijacked by JS wheel listeners', () => {
    for (const options of [{}, { noPreference: false }, { modalOpen: true }]) {
        const active = physicsWith(options);
        assert.equal(active.dispatchWheel({ deltaY: 400 }), false);
        assert.equal(active.dispatchWheel({ deltaY: 200, ctrlKey: true }), false);
        active.flushFrames(20);
        assert.deepEqual(active.scrollCalls, []);
    }
});

test('pointer parallax lerps --px and --py and native scroll updates --scroll-progress, --hero-progress, and --bottom-blur-opacity', () => {
    const landing = physicsWith();
    assert.equal(landing.prop('--scroll-progress'), '0.0000');
    assert.equal(landing.prop('--hero-progress'), '0.0000');
    assert.equal(landing.prop('--bottom-blur-opacity'), '0.0000');

    landing.dispatchPointerMove(1200, 0);
    landing.flushFrames();
    assert.equal(landing.prop('--px'), '1.0000');
    assert.equal(landing.prop('--py'), '-1.0000');

    landing.window.scrollY = 168; // (168 - 96) / 144 -> 0.5000 opacity
    landing.dispatchAsyncScroll();
    landing.flushFrames();
    assert.equal(landing.prop('--bottom-blur-opacity'), '0.5000');
    assert.equal(landing.prop('--scroll-progress'), '0.1050');
    assert.equal(landing.prop('--hero-progress'), '0.2100');

    landing.window.scrollY = 318; // >= 240 -> 1.0000 opacity
    landing.dispatchAsyncScroll();
    landing.flushFrames();
    assert.equal(landing.prop('--bottom-blur-opacity'), '1.0000');
});

test('clicking a[href="#top"] scrolls to top 0 even when scrolled down on a sticky hero', () => {
    const docHandlers = new Map();
    const scrollCalls = [];
    const replacedUrls = [];
    const topLink = {
        closest: (selector) => selector === 'a[href="#top"]' ? topLink : null,
    };
    const window = {
        scrollY: 1200,
        innerWidth: 1200,
        innerHeight: 800,
        location: { pathname: '/', search: '', hash: '#programs' },
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        scrollTo: (options) => {
            scrollCalls.push({ top: options.top, left: options.left, behavior: options.behavior });
            window.scrollY = options.top;
        },
        history: {
            state: null,
            replaceState: (state, title, url) => replacedUrls.push(url),
        },
        addEventListener: () => {},
    };
    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement(),
        addEventListener: (event, listener) => {
            if (event === 'DOMContentLoaded') {
                listener();
            } else {
                docHandlers.set(event, [...(docHandlers.get(event) ?? []), listener]);
            }
        },
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), {
        window,
        document,
        URLSearchParams,
        CSS: { supports: () => false },
    });

    let prevented = false;
    (docHandlers.get('click') ?? []).forEach((fn) => fn({
        target: topLink,
        preventDefault: () => { prevented = true; },
    }));

    assert.equal(prevented, true);
    assert.deepEqual(scrollCalls, [{ top: 0, left: 0, behavior: 'smooth' }]);
    assert.deepEqual(replacedUrls, ['/#top']);
});

test('navbar contrast updates skip .landing-nav-track pill links over Google Maps while updating bare targets', () => {
    const frames = [];
    const bareAttrs = new Map();
    const pillAttrs = new Map();
    const bareTarget = {
        closest: () => null,
        getBoundingClientRect: () => ({ left: 40, top: 20, width: 80, height: 24 }),
        setAttribute: (name, value) => bareAttrs.set(name, value),
    };
    const pillLink = {
        closest: (selector) => selector === '.landing-nav-track' ? {} : null,
        getBoundingClientRect: () => ({ left: 200, top: 20, width: 60, height: 24 }),
        setAttribute: (name, value) => pillAttrs.set(name, value),
    };
    const mapSurface = {
        matches: (selector) => selector === 'iframe.institution-map',
        getAttribute: () => null,
    };
    const navbar = {
        contains: (el) => el === bareTarget || el === pillLink,
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '[data-navbar-contrast-target]' ? [bareTarget, pillLink] : [],
        getBoundingClientRect: () => ({ bottom: 80 }),
        addEventListener: () => {},
    };
    const window = {
        location: { hash: '', search: '' },
        innerWidth: 1280,
        innerHeight: 800,
        matchMedia: (query) => ({ matches: query.includes('dark'), addEventListener: () => {} }),
        requestAnimationFrame: (callback) => { frames.push(callback); return frames.length; },
        addEventListener: () => {},
    };
    const document = {
        readyState: 'loading',
        documentElement: makeDocumentElement({ theme: 'dark' }),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '.navbar' ? navbar : null,
        querySelectorAll: () => [],
        elementsFromPoint: () => [bareTarget, { closest: () => mapSurface }],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    while (frames.length) { frames.shift()(); }

    assert.equal(bareAttrs.get('data-navbar-foreground'), 'black');
    assert.equal(pillAttrs.has('data-navbar-foreground'), false);
});

function campusDeckWith({ cardCount = 3, reduced = false } = {}) {
    const deckHandlers = new Map();
    const buttonHandlers = new Map();
    const timers = [];
    let intervalCallback = null;
    let intervalCleared = false;
    const counter = { textContent: '' };
    const prevBtn = { addEventListener: (e, fn) => buttonHandlers.set(`prev:${e}`, fn) };
    const nextBtn = { addEventListener: (e, fn) => buttonHandlers.set(`next:${e}`, fn) };
    const cards = Array.from({ length: cardCount }, () => {
        const classList = makeClassList();
        const attrs = new Map();
        const card = {
            classList,
            attrs,
            setAttribute: (name, value) => attrs.set(name, value),
            getAttribute: (name) => attrs.get(name) ?? null,
            closest: (selector) => selector.includes('[data-deck-card]') ? card : null,
        };
        return card;
    });
    const deck = {
        contains: () => false,
        addEventListener: (e, fn) => deckHandlers.set(e, fn),
        querySelector: (selector) => ({
            '[data-deck-prev]': prevBtn,
            '[data-deck-next]': nextBtn,
            '[data-deck-counter]': counter,
        }[selector] ?? null),
        querySelectorAll: (selector) => selector === '[data-deck-card]' ? cards : [],
    };
    const window = {
        location: { hash: '', search: '' },
        matchMedia: (query) => ({
            matches: query.includes('reduce') ? reduced : false,
            addEventListener: () => {},
        }),
        setInterval: (fn) => { intervalCallback = fn; return 1; },
        clearInterval: () => { intervalCleared = true; intervalCallback = null; },
        setTimeout: (fn) => { timers.push(fn); return timers.length; },
        clearTimeout: () => {},
        addEventListener: () => {},
    };
    const document = {
        readyState: 'loading',
        hidden: false,
        documentElement: makeDocumentElement(),
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '[data-campus-deck]' ? deck : null,
        querySelectorAll: () => [],
        getElementById: () => null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });

    return {
        activeIndex: () => cards.findIndex((c) => c.classList.contains('is-active')),
        nextIndex: () => cards.findIndex((c) => c.classList.contains('is-next')),
        hiddenFlags: () => cards.map((c) => c.getAttribute('aria-hidden')),
        counter: () => counter.textContent,
        isExiting: (i) => cards[i].classList.contains('is-exiting'),
        clickNext: () => buttonHandlers.get('next:click')?.(),
        clickPrev: () => buttonHandlers.get('prev:click')?.(),
        clickFrontCard: () => deckHandlers.get('click')?.({ target: cards.find((c) => c.classList.contains('is-active')) }),
        hover: () => deckHandlers.get('pointerenter')?.(),
        leave: () => deckHandlers.get('pointerleave')?.(),
        tick: () => intervalCallback?.(),
        hasInterval: () => intervalCallback !== null,
        intervalCleared: () => intervalCleared,
        flushTimers: () => { while (timers.length) { timers.shift()(); } },
    };
}

test('stacked campus photo deck cycles slides, updates counter and aria-hidden, and pauses on hover', () => {
    const deck = campusDeckWith({ cardCount: 3 });
    assert.equal(deck.activeIndex(), 0);
    assert.equal(deck.nextIndex(), 1);
    assert.deepEqual(deck.hiddenFlags(), ['false', 'true', 'true']);
    assert.equal(deck.counter(), '1 / 3');
    assert.equal(deck.hasInterval(), true);

    deck.tick();
    assert.equal(deck.activeIndex(), 1);
    assert.equal(deck.isExiting(0), true);
    assert.equal(deck.counter(), '2 / 3');
    deck.flushTimers();
    assert.equal(deck.isExiting(0), false);

    deck.clickFrontCard();
    assert.equal(deck.activeIndex(), 2);
    assert.equal(deck.counter(), '3 / 3');

    deck.clickPrev();
    assert.equal(deck.activeIndex(), 1);

    deck.hover();
    assert.equal(deck.intervalCleared(), true);
    assert.equal(deck.hasInterval(), false);
    deck.leave();
    assert.equal(deck.hasInterval(), true);
});

test('stacked campus photo deck disables auto-cycling under prefers-reduced-motion while keeping manual controls', () => {
    const deck = campusDeckWith({ cardCount: 3, reduced: true });
    assert.equal(deck.hasInterval(), false);
    assert.equal(deck.activeIndex(), 0);

    deck.clickNext();
    assert.equal(deck.activeIndex(), 1);
    assert.equal(deck.counter(), '2 / 3');
});

test('interactive sheet handle supports RAF-coalesced 1:1 pointer drag up/down with cached bounds and click toggle between hero and sheet', () => {
    const handleHandlers = new Map();
    const handleClasses = makeClassList();
    const sheetClasses = makeClassList();
    const scrollCalls = [];
    let domRectReads = 0;
    const sheet = {
        classList: sheetClasses,
        getBoundingClientRect: () => {
            domRectReads += 1;
            return { top: 700 - window.scrollY };
        },
    };
    const handle = {
        classList: handleClasses,
        closest: (selector) => selector === '.landing-content-sheet' ? sheet : null,
        addEventListener: (event, listener) => handleHandlers.set(event, listener),
        setPointerCapture: () => {},
        releasePointerCapture: () => {},
    };
    const notices = {
        getBoundingClientRect: () => {
            domRectReads += 1;
            return { top: 760 - window.scrollY };
        },
    };
    const navbar = {
        querySelector: () => null,
        querySelectorAll: () => [],
        getBoundingClientRect: () => {
            domRectReads += 1;
            return { bottom: 76, height: 76 };
        },
        addEventListener: () => {},
    };
    const documentElement = makeDocumentElement();
    documentElement.scrollHeight = 2400;
    let now = 1000;
    const frames = new Map();
    let nextFrameId = 1;

    const window = {
        scrollY: 0,
        innerWidth: 1280,
        innerHeight: 800,
        location: { hash: '', search: '' },
        performance: { now: () => now },
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        scrollTo: (options) => {
            window.scrollY = options.top;
            scrollCalls.push({ top: options.top, behavior: options.behavior });
        },
        requestAnimationFrame: (callback) => {
            const id = nextFrameId;
            nextFrameId += 1;
            frames.set(id, callback);
            return id;
        },
        cancelAnimationFrame: (id) => {
            frames.delete(id);
        },
        addEventListener: () => {},
    };
    const flushOneFrame = () => {
        const entry = frames.entries().next();
        if (!entry.done) {
            const [id, callback] = entry.value;
            frames.delete(id);
            now += 16;
            callback(now);
        }
    };
    const flushSnap = () => {
        let guard = 0;
        while (frames.size && guard < 100) {
            const [id, callback] = frames.entries().next().value;
            frames.delete(id);
            now += 34;
            callback(now);
            guard += 1;
        }
    };
    const document = {
        readyState: 'loading',
        documentElement,
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => ({
            '.navbar': navbar,
            '[data-sheet-handle]': handle,
            '.landing-content-sheet': sheet,
        }[selector] ?? null),
        querySelectorAll: () => [],
        getElementById: (id) => id === 'notices' ? notices : null,
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });

    // 1. Multiple pointermoves coalesce into 1 RAF per frame with 1:1 tracking and zero DOM reads during drag
    handleHandlers.get('pointerdown')({ pointerId: 1, button: 0, clientY: 600 });
    assert.equal(documentElement.classList.contains('is-dragging'), true);
    assert.equal(handleClasses.contains('is-dragging'), true);
    assert.equal(sheetClasses.contains('is-dragging'), true);

    const readsAfterDown = domRectReads;
    const callsBeforeMove = scrollCalls.length;
    now += 100;
    handleHandlers.get('pointermove')({ pointerId: 1, clientY: 450 });
    now += 4;
    handleHandlers.get('pointermove')({ pointerId: 1, clientY: 280 });
    assert.equal(scrollCalls.length, callsBeforeMove); // coalesced until RAF
    assert.equal(domRectReads, readsAfterDown);

    flushOneFrame();
    assert.equal(scrollCalls.length, callsBeforeMove + 1);
    assert.equal(window.scrollY, 320); // 1:1 tracking: 0 - (280 - 600) = 320
    assert.equal(domRectReads, readsAfterDown);

    now += 200;
    handleHandlers.get('pointerup')({ pointerId: 1, clientY: 280 });
    assert.equal(documentElement.classList.contains('is-dragging'), false);
    assert.equal(handleClasses.contains('is-dragging'), false);
    flushSnap();
    assert.equal(window.scrollY, 616); // sheet top (700) - navBottom (76) - 8px clearance = 616
    assert.ok(sheet.getBoundingClientRect().top > navbar.getBoundingClientRect().bottom);

    // 2. Drag downward toward top -> returns to 0
    handleHandlers.get('pointerdown')({ pointerId: 2, button: 0, clientY: 200 });
    now += 200;
    handleHandlers.get('pointermove')({ pointerId: 2, clientY: 650 });
    flushOneFrame();
    assert.equal(window.scrollY, 166); // 616 - (650 - 200) = 166

    now += 200;
    handleHandlers.get('pointerup')({ pointerId: 2, clientY: 650 });
    flushSnap();
    assert.equal(window.scrollY, 0);
    handleHandlers.get('click')(); // suppressed after drag
    assert.equal(window.scrollY, 0);

    // 3. Click toggle opens sheet from 0, then closes back to 0
    handleHandlers.get('click')();
    flushSnap();
    assert.equal(window.scrollY, 616);
    handleHandlers.get('click')();
    flushSnap();
    assert.equal(window.scrollY, 0);
});
