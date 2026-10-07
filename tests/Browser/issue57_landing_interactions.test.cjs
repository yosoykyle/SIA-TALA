const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function landingWith({ invalid = false, intersectionObserver = true, motionControl = true } = {}) {
    const handlers = {};
    let focused = false;
    let observerCallback = null;
    const heroClasses = new Set();
    const toggleAttributes = new Map([['aria-pressed', 'false']]);
    const label = { textContent: 'Pause motion' };
    const invalidInput = { focus: () => { focused = true; } };
    const section = {
        querySelector: (selector) => selector === '[aria-invalid="true"]' && invalid ? invalidInput : null,
        querySelectorAll: () => [],
    };
    const toggle = {
        addEventListener: (event, listener) => { handlers.toggleClick = listener; },
        getAttribute: (name) => toggleAttributes.get(name),
        setAttribute: (name, value) => { toggleAttributes.set(name, value); },
        querySelector: () => label,
    };
    const hero = {
        classList: {
            add: (name) => heroClasses.add(name),
            toggle: (name, active) => active ? heroClasses.add(name) : heroClasses.delete(name),
        },
        querySelector: (selector) => selector === '[data-hero-motion-toggle]' && motionControl ? toggle : null,
    };
    const window = {
        location: { hash: '', search: '' },
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        addEventListener: (event, listener) => { handlers[event] = listener; },
    };

    if (intersectionObserver) {
        window.IntersectionObserver = class {
            constructor(callback) { observerCallback = callback; }
            observe() {}
        };
    }

    const document = {
        addEventListener: (event, listener) => { handlers.ready = listener; },
        querySelector: (selector) => selector === '[data-hero-motion]' ? hero : null,
        querySelectorAll: () => [],
        getElementById: (id) => id === 'application-status' ? section : null,
    };
    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    handlers.ready();

    return {
        handlers,
        focused: () => focused,
        heroHas: (name) => heroClasses.has(name),
        pressed: () => toggleAttributes.get('aria-pressed'),
        label: () => label.textContent,
        reportHeroVisibility: (isIntersecting) => observerCallback([{ isIntersecting }]),
    };
}

test('server validation focuses the invalid status field in the directly visible form', () => {
    assert.equal(landingWith({ invalid: true }).focused(), true);
    assert.equal(landingWith().focused(), false);
});

test('the hero motion control pauses and resumes the ambient background with a matching accessible state', () => {
    const landing = landingWith();
    assert.equal(landing.heroHas('is-motion-enhanced'), true);
    assert.equal(landing.heroHas('is-motion-paused'), false);

    landing.handlers.toggleClick();
    assert.equal(landing.heroHas('is-motion-paused'), true);
    assert.equal(landing.pressed(), 'true');
    assert.equal(landing.label(), 'Play motion');

    landing.handlers.toggleClick();
    assert.equal(landing.heroHas('is-motion-paused'), false);
    assert.equal(landing.pressed(), 'false');
    assert.equal(landing.label(), 'Pause motion');
});

test('the ambient background suspends while the masthead is offscreen without changing the visitor choice', () => {
    const landing = landingWith();

    landing.reportHeroVisibility(false);
    assert.equal(landing.heroHas('is-offscreen'), true);
    assert.equal(landing.pressed(), 'false');

    landing.reportHeroVisibility(true);
    assert.equal(landing.heroHas('is-offscreen'), false);
});

test('the motion control still works where offscreen observation is unavailable', () => {
    const landing = landingWith({ intersectionObserver: false });
    landing.handlers.toggleClick();
    assert.equal(landing.heroHas('is-motion-paused'), true);
});

test('ambient motion remains static when its pause control cannot initialize', () => {
    assert.equal(landingWith({ motionControl: false }).heroHas('is-motion-enhanced'), false);
});

function landingControlsWith({ formBounds = null, mapBounds = null, dark = true, scrollY = 500 } = {}) {
    const windowHandlers = new Map();
    const frames = [];
    const visibleClasses = new Set();
    const foreground = new Map();
    const form = {
        dataset: {},
        getBoundingClientRect: () => formBounds,
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
    };
    const mapFrame = { getBoundingClientRect: () => mapBounds };
    const trackingSection = { querySelector: (selector) => selector === 'form' && formBounds ? form : null, querySelectorAll: () => [] };
    const scrollButton = {
        classList: { toggle: (name, visible) => visible ? visibleClasses.add(name) : visibleClasses.delete(name) },
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
        matchMedia: () => ({ matches: dark, addEventListener: () => {} }),
        getComputedStyle: () => ({ bottom: '56px' }),
        requestAnimationFrame: (callback) => { frames.push(callback); return frames.length; },
        addEventListener: (event, listener) => {
            windowHandlers.set(event, [...(windowHandlers.get(event) ?? []), listener]);
        },
    };
    const document = {
        documentElement: { getAttribute: () => dark ? 'dark' : 'light' },
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => ({
            '.btn-scroll-top': scrollButton,
            '.navbar': navbar,
            'iframe.institution-map': mapBounds ? mapFrame : null,
        }[selector] ?? null),
        querySelectorAll: () => [],
        getElementById: (id) => id === 'application-status' ? trackingSection : null,
        elementsFromPoint: () => [target, behindNavbar],
    };
    const flushFrames = () => { while (frames.length) { frames.shift()(); } };
    const emit = (event) => {
        for (const listener of windowHandlers.get(event) ?? []) { listener(); }
        flushFrames();
    };

    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    flushFrames();

    return {
        visible: () => visibleClasses.has('visible'),
        foreground: () => foreground.get('data-navbar-foreground'),
        showMap: () => {
            behindNavbar = {
                closest: (selector) => selector.includes('iframe.institution-map') ? mapSurface : themedSurface,
            };
        },
        showTheme: () => { behindNavbar = { closest: () => themedSurface }; },
        setFormBounds: (bounds) => { formBounds = bounds; },
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

test('return control clears the tracking form and reappears after the form scrolls out of its area', () => {
    const landing = landingControlsWith({ formBounds: { left: 16, right: 334, top: 500, bottom: 900 } });
    assert.equal(landing.visible(), false);

    landing.setFormBounds({ left: 16, right: 334, top: 300, bottom: 650 });
    landing.emit('scroll');

    assert.equal(landing.visible(), true);
});

test('return control rechecks form collision on resize and hides near the top of the page', () => {
    const landing = landingControlsWith({ formBounds: { left: 16, right: 334, top: 200, bottom: 650 } });
    assert.equal(landing.visible(), true);

    landing.window.innerHeight = 700;
    landing.emit('resize');

    assert.equal(landing.visible(), false);

    landing.setFormBounds({ left: 0, right: 0, top: 0, bottom: 0 });
    landing.emit('scroll');

    assert.equal(landing.visible(), true);

    landing.window.scrollY = 100;
    landing.emit('scroll');

    assert.equal(landing.visible(), false);
});

test('return control steps aside while it would cover the embedded map controls', () => {
    const landing = landingControlsWith({ mapBounds: { left: 16, right: 396, top: 300, bottom: 760 } });
    assert.equal(landing.visible(), false);

    landing.setMapBounds({ left: 16, right: 396, top: -400, bottom: 60 });
    landing.emit('scroll');

    assert.equal(landing.visible(), true);
});

function updatesWith({ slideCount = 3, reduced = false, forced = false, hash = '' } = {}) {
    const handlers = { updates: {}, window: {} };
    let interval = null;
    const heroClasses = new Set();
    const makeClassList = (set) => ({
        add: (name) => set.add(name),
        toggle: (name, active) => (active ? set.add(name) : set.delete(name)),
        contains: (name) => set.has(name),
    });
    const slides = Array.from({ length: slideCount }, () => {
        const classes = new Set();
        const attributes = new Map();
        return { classes, attributes, inert: false, classList: makeClassList(classes), setAttribute: (name, value) => attributes.set(name, value) };
    });
    const position = { textContent: '' };
    const stackAttributes = new Map();
    const stack = { setAttribute: (name, value) => stackAttributes.set(name, value) };
    const buttons = {};
    let announcementClick;
    const announcementLink = { addEventListener: (event, listener) => { announcementClick = listener; } };
    const updatesClasses = new Set();
    const updates = {
        classList: makeClassList(updatesClasses),
        querySelectorAll: (selector) => selector === '[data-update-slide]' ? slides : [],
        querySelector: (selector) => {
            if (selector === '[data-hero-updates-stack]') { return stack; }
            if (selector === '[data-update-position]') { return position; }
            if (selector === '[data-update-previous]' || selector === '[data-update-next]') {
                buttons[selector] = buttons[selector] ?? { addEventListener: (event, listener) => { buttons[selector].click = listener; } };
                return buttons[selector];
            }
            return null;
        },
        addEventListener: (event, listener) => { handlers.updates[event] = listener; },
        contains: (element) => element === buttons['[data-update-next]'],
    };
    const hero = {
        classList: makeClassList(heroClasses),
        querySelector: () => null,
    };
    const window = {
        location: { hash, search: '' },
        matchMedia: (query) => ({ matches: query.includes('reduce') ? reduced : query.includes('forced-colors') ? forced : false, addEventListener: () => {} }),
        addEventListener: (event, listener) => { handlers.window[event] = listener; },
        setInterval: (callback) => { interval = callback; return 1; },
    };
    const document = {
        hidden: false,
        activeElement: null,
        addEventListener: (event, listener) => { handlers.ready = listener; },
        querySelector: (selector) => ({ '[data-hero-motion]': hero, '[data-hero-updates]': updates }[selector] ?? null),
        querySelectorAll: (selector) => selector === 'a[href="#notices"]' ? [announcementLink] : [],
        getElementById: () => null,
    };
    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    handlers.ready();

    return {
        active: () => slides.findIndex((slide) => slide.classes.has('is-active')),
        hiddenFlags: () => slides.map((slide) => slide.attributes.get('aria-hidden')),
        inertFlags: () => slides.map((slide) => slide.inert),
        position: () => position.textContent,
        enhanced: () => updatesClasses.has('is-enhanced'),
        reverse: () => updatesClasses.has('is-reverse'),
        live: () => stackAttributes.get('aria-live'),
        next: () => buttons['[data-update-next]'].click(),
        previous: () => buttons['[data-update-previous]'].click(),
        tick: () => interval?.(),
        hover: () => handlers.updates.pointerenter(),
        leave: () => handlers.updates.pointerleave(),
        focus: () => handlers.updates.focusin(),
        blur: (inside = false) => handlers.updates.focusout({ relatedTarget: inside ? buttons['[data-update-next]'] : null }),
        announcements: () => announcementClick(),
        pauseMotion: () => heroClasses.add('is-motion-paused'),
        hasInterval: () => interval !== null,
    };
}

test('the masthead update stack starts on Admissions and exposes only the visible card', () => {
    const updates = updatesWith();
    assert.equal(updates.enhanced(), true);
    assert.equal(updates.active(), 0);
    assert.deepEqual(updates.hiddenFlags(), ['false', 'true', 'true']);
    assert.deepEqual(updates.inertFlags(), [false, true, true]);
    assert.equal(updates.position(), '1 of 3');
});

test('manual controls slide between Admissions and announcements in both directions and wrap around', () => {
    const updates = updatesWith();
    updates.next();
    assert.equal(updates.active(), 1);
    assert.equal(updates.reverse(), false);
    updates.previous();
    updates.previous();
    assert.equal(updates.active(), 2);
    assert.equal(updates.reverse(), true);
    assert.equal(updates.position(), '3 of 3');
});

test('automatic advancing stops while a visitor hovers or after motion is paused', () => {
    const updates = updatesWith();
    assert.equal(updates.live(), 'off');
    updates.tick();
    assert.equal(updates.active(), 1);

    updates.hover();
    assert.equal(updates.live(), 'polite');
    updates.tick();
    assert.equal(updates.active(), 1);

    updates.leave();
    updates.pauseMotion();
    updates.tick();
    assert.equal(updates.active(), 1);
    assert.equal(updates.live(), 'polite');
});

test('reduced motion keeps the stack on manual paging only', () => {
    const updates = updatesWith({ reduced: true });
    updates.tick();
    assert.equal(updates.active(), 0);
    updates.next();
    assert.equal(updates.active(), 1);
});

test('forced colors disables automatic advancing while manual paging stays available', () => {
    const updates = updatesWith({ forced: true });
    assert.equal(updates.live(), 'polite');
    updates.tick();
    assert.equal(updates.active(), 0);
    updates.next();
    assert.equal(updates.active(), 1);
});

test('leaving focus does not resume updates while the pointer remains over the stack', () => {
    const updates = updatesWith();
    updates.hover();
    updates.focus();
    updates.blur();
    updates.tick();
    assert.equal(updates.active(), 0);
    updates.leave();
    updates.tick();
    assert.equal(updates.active(), 1);
});

test('leaving the pointer does not resume updates while keyboard focus remains inside', () => {
    const updates = updatesWith();
    updates.focus();
    updates.hover();
    updates.leave();
    updates.blur(true);
    updates.tick();
    assert.equal(updates.active(), 0);
    updates.blur();
    updates.tick();
    assert.equal(updates.active(), 1);
});

test('the Announcements anchor opens the first announcement card', () => {
    assert.equal(updatesWith({ hash: '#notices' }).active(), 1);
});

test('repeating an Announcements link returns to the first announcement without a hash change', () => {
    const updates = updatesWith({ hash: '#notices' });
    updates.next();
    assert.equal(updates.active(), 2);
    updates.announcements();
    assert.equal(updates.active(), 1);
});

test('a single Admissions card stays static without carousel behaviour', () => {
    const updates = updatesWith({ slideCount: 1 });
    assert.equal(updates.enhanced(), false);
    assert.equal(updates.hasInterval(), false);
});

function trackingFormWith({ referenceValue = '', emailValue = '', confirmation = false, invalid = false } = {}) {
    const handlers = { window: {} };
    const formHandlers = {};
    let focused = null;
    const makeInput = (value, name) => {
        const listeners = {};
        return {
            name,
            value,
            selectionEnd: value.length,
            addEventListener: (event, listener) => { listeners[event] = listener; },
            setSelectionRange: () => {},
            focus() { focused = name; document.activeElement = this; },
            fire: (event) => listeners[event]?.({ type: event }),
        };
    };
    const reference = makeInput(referenceValue, 'reference');
    const email = makeInput(emailValue, 'email');
    const label = { textContent: 'Email status link' };
    const buttonAttributes = new Map();
    const buttonClasses = new Set();
    const button = {
        querySelector: () => label,
        setAttribute: (name, value) => buttonAttributes.set(name, value),
        removeAttribute: (name) => buttonAttributes.delete(name),
        classList: { add: (name) => buttonClasses.add(name), remove: (name) => buttonClasses.delete(name) },
    };
    const form = {
        dataset: {},
        getBoundingClientRect: () => ({ left: 0, right: 0, top: 0, bottom: 0 }),
        querySelector: (selector) => selector === '[data-tracking-submit]' ? button : null,
        querySelectorAll: (selector) => ({ '[data-reference-input]': [reference], '[data-trim-input]': [email] }[selector] ?? []),
        addEventListener: (event, listener) => { formHandlers[event] = listener; },
    };
    const confirmationElement = { focus() { focused = 'confirmation'; document.activeElement = this; } };
    const section = {
        querySelector: (selector) => ({
            form,
            '[data-tracking-confirmation]': confirmation ? confirmationElement : null,
            '[aria-invalid="true"]': invalid ? reference : null,
        }[selector] ?? null),
        querySelectorAll: (selector) => ({ '[data-reference-input]': [reference], '[data-trim-input]': [email] }[selector] ?? []),
    };
    const window = {
        location: { hash: '', search: '' },
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        addEventListener: (event, listener) => { handlers.window[event] = listener; },
    };
    const document = {
        body: {},
        documentElement: {},
        activeElement: null,
        addEventListener: (event, listener) => { handlers.ready = listener; },
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: (id) => id === 'application-status' ? section : null,
    };
    vm.runInNewContext(fs.readFileSync('public/landing/js/main.js', 'utf8'), { window, document, URLSearchParams });
    handlers.ready();

    return {
        reference,
        email,
        submit: () => {
            let prevented = false;
            formHandlers.submit({ preventDefault: () => { prevented = true; } });
            return prevented;
        },
        pageshow: () => handlers.window.pageshow(),
        loseFocusThenLoad: () => { focused = 'body'; document.activeElement = document.body; handlers.window.load(); },
        focusEmailThenLoad: () => { email.focus(); handlers.window.load(); },
        sending: () => buttonClasses.has('is-sending'),
        ariaDisabled: () => buttonAttributes.get('aria-disabled'),
        label: () => label.textContent,
        focused: () => focused,
    };
}

test('a pasted reference is normalised to the stored format without rejecting older references', () => {
    const form = trackingFormWith({ referenceValue: ' “app–2026 abcd  efgh-jk23.” ' });
    form.reference.fire('input');
    assert.equal(form.reference.value, 'APP-2026-ABCD-EFGH-JK23.-');
    form.reference.fire('blur');
    assert.equal(form.reference.value, 'APP-2026-ABCD-EFGH-JK23');

    const typing = trackingFormWith({ referenceValue: 'app ' });
    typing.reference.fire('input');
    assert.equal(typing.reference.value, 'APP-');

    const older = trackingFormWith({ referenceValue: 'app-12345678' });
    older.reference.fire('blur');
    assert.equal(older.reference.value, 'APP-12345678');
});

test('the email is trimmed and the request is sent once with an honest sending state', () => {
    const form = trackingFormWith({ referenceValue: 'APP-2026-ABCD-EFGH-JK23', emailValue: '  applicant@example.test ' });
    assert.equal(form.submit(), false);
    assert.equal(form.email.value, 'applicant@example.test');
    assert.equal(form.sending(), true);
    assert.equal(form.ariaDisabled(), 'true');
    assert.equal(form.label(), 'Sending request…');
    assert.equal(form.submit(), true);
});

test('returning to the page restores the request button', () => {
    const form = trackingFormWith();
    form.submit();
    form.pageshow();
    assert.equal(form.sending(), false);
    assert.equal(form.ariaDisabled(), undefined);
    assert.equal(form.label(), 'Email status link');
    assert.equal(form.submit(), false);
});

test('the uniform confirmation receives focus, while a validation error keeps focus on the field', () => {
    assert.equal(trackingFormWith({ confirmation: true }).focused(), 'confirmation');
    assert.equal(trackingFormWith({ confirmation: true, invalid: true }).focused(), 'reference');
});

test('focus returns to the status result after fragment navigation resets it on load', () => {
    const form = trackingFormWith({ confirmation: true });
    form.loseFocusThenLoad();
    assert.equal(form.focused(), 'confirmation');

    const invalid = trackingFormWith({ invalid: true });
    invalid.loseFocusThenLoad();
    assert.equal(invalid.focused(), 'reference');
});

test('a delayed page load preserves focus the visitor moved to another form control', () => {
    const form = trackingFormWith({ confirmation: true });
    form.focusEmailThenLoad();
    assert.equal(form.focused(), 'email');

    const invalid = trackingFormWith({ invalid: true });
    invalid.focusEmailThenLoad();
    assert.equal(invalid.focused(), 'email');
});

function navigationWith({ hash = '' } = {}) {
    const windowHandlers = new Map();
    const navigationHandlers = new Map();
    const navbarHandlers = new Map();
    const properties = new Map();
    let navbarHeight = 80;
    let topOffset = 0;
    let programsOffset = 700;
    let shown = false;
    const register = (handlers, event, listener) => handlers.set(event, [...(handlers.get(event) ?? []), listener]);
    const emit = (handlers, event, data) => (handlers.get(event) ?? []).forEach((listener) => listener(data));
    const makeLink = (fragment) => {
        const attributes = new Map();
        return {
            hash: fragment,
            attributes,
            setAttribute: (name, value) => attributes.set(name, value),
            removeAttribute: (name) => attributes.delete(name),
            focus() { document.activeElement = this; },
            closest() { return this; },
        };
    };
    const home = makeLink('#top');
    const noticesLink = makeLink('#notices');
    const programsLink = makeLink('#programs');
    const toggle = makeLink('');
    const notices = makeLink('');
    const top = { id: 'top', getBoundingClientRect: () => ({ top: topOffset }) };
    const programs = { id: 'programs', getBoundingClientRect: () => ({ top: programsOffset }) };
    const navigation = {
        classList: { contains: () => shown },
        querySelector: () => home,
        querySelectorAll: () => [home, noticesLink, programsLink],
        addEventListener: (event, listener) => register(navigationHandlers, event, listener),
        contains: (element) => [home, noticesLink, programsLink].includes(element),
    };
    const navbar = {
        querySelector: (selector) => selector === '.navbar-toggler' ? toggle : null,
        querySelectorAll: () => [],
        getBoundingClientRect: () => ({ bottom: navbarHeight, height: navbarHeight }),
        addEventListener: (event, listener) => register(navbarHandlers, event, listener),
    };
    const document = {
        activeElement: null,
        documentElement: { style: { setProperty: (name, value) => properties.set(name, value) } },
        addEventListener: (event, listener) => { if (event === 'DOMContentLoaded') { listener(); } },
        querySelector: (selector) => selector === '.navbar' ? navbar : null,
        querySelectorAll: (selector) => selector === 'main > section[id]' ? [top, programs] : [],
        getElementById: (id) => ({ navbarNav: navigation, notices, top, programs }[id] ?? null),
    };
    const hide = () => {
        shown = false;
        navbarHeight = 80;
        emit(navigationHandlers, 'hidden.bs.collapse');
    };
    const window = {
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
        open: () => { shown = true; navbarHeight = 320; emit(navigationHandlers, 'shown.bs.collapse'); },
        followNotices: () => emit(navigationHandlers, 'click', { target: noticesLink }),
        escape: () => emit(navbarHandlers, 'keydown', { key: 'Escape', preventDefault() {} }),
        height: () => properties.get('--tala-navbar-height'),
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

test('opening and closing mobile navigation updates masthead clearance and restores toggle focus on Escape', () => {
    const navigation = navigationWith();
    assert.equal(navigation.height(), '80px');
    navigation.open();
    assert.equal(navigation.height(), '320px');
    assert.equal(navigation.focus(), navigation.home);
    navigation.escape();
    assert.equal(navigation.height(), '80px');
    assert.equal(navigation.focus(), navigation.toggle);
});

test('following an anchor closes mobile navigation and focuses the destination after collapse', () => {
    const navigation = navigationWith();
    navigation.open();
    navigation.followNotices();
    assert.equal(navigation.height(), '80px');
    assert.equal(navigation.focus(), navigation.notices);
});
