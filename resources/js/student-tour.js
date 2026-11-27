import { driver } from "driver.js";

const TOUR_VERSION = "1";

let activeDriver = null;
let lastReturnFocusEl = null;
let dialogKeydownHandler = null;
let srLiveRegion = null;

function getInviteElement() {
    return document.getElementById("tala-student-tour-invite");
}

function getStorageKey(invite) {
    const userId = invite?.dataset.tourUserId;
    const role = invite?.dataset.tourRole || "student";
    const version = invite?.dataset.tourVersion || TOUR_VERSION;

    if (!userId) {
        return null;
    }

    return `tala.quick_tour.v${version}.${userId}.${role}`;
}

function readPreference(key) {
    if (!key) {
        return null;
    }

    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writePreference(key, value) {
    if (!key) {
        return;
    }

    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Storage unavailable; keep ordinary workspace usable without throwing.
    }
}

function hideInvite(invite) {
    if (invite) {
        invite.hidden = true;
    }
}

function syncInviteVisibility() {
    const invite = getInviteElement();
    if (!invite) {
        return;
    }

    const key = getStorageKey(invite);
    const saved = readPreference(key);
    invite.hidden = Boolean(saved);
}

function isTargetVisible(selector) {
    const el = document.querySelector(selector);
    if (!(el instanceof Element)) {
        return null;
    }

    const sidebar = el.closest("#fi-main-sidebar");
    if (sidebar && window.innerWidth < 1024 && !sidebar.classList.contains("fi-sidebar-open")) {
        return null;
    }

    const style = window.getComputedStyle(el);
    if (style.display === "none" || style.visibility === "hidden") {
        return null;
    }

    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
        return null;
    }

    return el;
}

function buildSteps() {
    const isDesktop = window.innerWidth >= 1024;
    const candidates = [
        {
            selector: '[data-tala-workspace="student"] [data-tala-brand-person]',
            allowCenteredFallback: true,
            popover: {
                title: "Student Hub workspace",
                description:
                    "Your signed-in name and Student Number stay visible in the header across every Student Hub page.",
                side: "bottom",
                align: "start",
            },
        },
        {
            selector: '.fi-sidebar-nav a[href$="/student"]',
            desktopOnly: true,
            popover: {
                title: "Overview",
                description:
                    "Start on Overview for priority notices, unpaid term fees, enrollment status, and academic progress.",
                side: "right",
                align: "start",
            },
        },
        {
            selector: '.fi-sidebar-nav a[href*="/student/schedule"]',
            desktopOnly: true,
            popover: {
                title: "Class schedule",
                description:
                    "Open your weekly class timetable, room assignments, and printable schedule.",
                side: "right",
                align: "start",
            },
        },
        {
            selector: '.fi-sidebar-nav a[href*="/student/enrollment"]',
            desktopOnly: true,
            popover: {
                title: "Enrollment",
                description:
                    "Review proposed subjects, confirm registration, and view your Certificate of Registration (COR).",
                side: "right",
                align: "start",
            },
        },
        {
            selector: '.fi-sidebar-nav a[href*="/student/academics"]',
            desktopOnly: true,
            popover: {
                title: "Grades",
                description:
                    "Check your released grades, Term GPA, Academic Year GWA, Cumulative GWA, and your study plan.",
                side: "right",
                align: "start",
            },
        },
        {
            selector: '.fi-sidebar-nav a[href*="/student/finance"]',
            desktopOnly: true,
            popover: {
                title: "Fees and payments",
                description:
                    "View upcoming due dates, Campus Cashier hours, manual online payment steps, and your Statement of Account.",
                side: "right",
                align: "start",
            },
        },
        {
            selector: ".fi-layout-sidebar-toggle-btn, .fi-topbar-open-sidebar-btn",
            mobileOnly: true,
            allowCenteredFallback: true,
            popover: {
                title: "Student Hub navigation",
                description:
                    "Use the menu button to open Overview, Class schedule, Enrollment, Grades, and Fees and payments.",
                side: "bottom",
                align: "start",
            },
        },
        {
            selector: "#fi-main-content",
            popover: {
                title: "Workspace & primary actions",
                description:
                    "Your current status, responsible office, and next available actions appear directly in the main workspace.",
                side: "top",
                align: "center",
            },
        },
        {
            selector: ".fi-user-menu-trigger",
            allowCenteredFallback: true,
            popover: {
                title: "Profile & Quick tour",
                description:
                    "Open your account menu anytime for Profile settings, appearance options, or to replay this Quick tour.",
                side: "top",
                align: "start",
            },
        },
    ];

    const resolved = [];

    for (const candidate of candidates) {
        if (candidate.desktopOnly && !isDesktop) {
            continue;
        }
        if (candidate.mobileOnly && isDesktop) {
            continue;
        }

        const targetEl = candidate.selector ? isTargetVisible(candidate.selector) : null;
        if (targetEl) {
            resolved.push({
                element: targetEl,
                popover: candidate.popover,
            });
        } else if (candidate.allowCenteredFallback) {
            resolved.push({
                popover: candidate.popover,
            });
        }
    }

    return resolved;
}

function ensureLiveRegion() {
    if (srLiveRegion && document.body.contains(srLiveRegion)) {
        return srLiveRegion;
    }

    srLiveRegion = document.createElement("div");
    srLiveRegion.id = "tala-student-tour-sr-status";
    srLiveRegion.className = "fi-sr-only";
    srLiveRegion.setAttribute("role", "status");
    srLiveRegion.setAttribute("aria-live", "polite");
    srLiveRegion.setAttribute("aria-atomic", "true");
    document.body.appendChild(srLiveRegion);

    return srLiveRegion;
}

function detachDialogTrap() {
    if (dialogKeydownHandler) {
        document.removeEventListener("keydown", dialogKeydownHandler, true);
        dialogKeydownHandler = null;
    }
}

function enhancePopoverAccessibility(popover) {
    const wrapper = popover?.wrapper;
    if (!wrapper) {
        return;
    }

    const titleId = "tala-driver-popover-title";
    const descId = "tala-driver-popover-desc";

    if (popover.title) {
        popover.title.id = titleId;
    }
    if (popover.description) {
        popover.description.id = descId;
    }

    wrapper.setAttribute("role", "dialog");
    wrapper.setAttribute("aria-modal", "true");
    wrapper.setAttribute("aria-labelledby", titleId);
    wrapper.setAttribute("aria-describedby", descId);

    if (popover.closeButton) {
        popover.closeButton.textContent = "Close";
        popover.closeButton.setAttribute("aria-label", "Close");
        popover.closeButton.setAttribute("title", "Close");
    }

    detachDialogTrap();
    dialogKeydownHandler = (event) => {
        if (event.key !== "Tab") {
            return;
        }

        const focusables = Array.from(
            wrapper.querySelectorAll('button:not([disabled]):not(.driver-popover-btn-disabled), [href], [tabindex]:not([tabindex="-1"])'),
        ).filter((el) => el instanceof HTMLElement && el.offsetParent !== null);

        if (focusables.length === 0) {
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };
    document.addEventListener("keydown", dialogKeydownHandler, true);

    const live = ensureLiveRegion();
    const titleText = popover.title?.textContent?.trim() || "";
    const progressText = popover.progress?.textContent?.trim() || "";
    const descText = popover.description?.textContent?.trim() || "";
    live.textContent = [titleText, progressText, descText].filter(Boolean).join(". ");
}

function startTour(returnFocusEl = null) {
    if (activeDriver) {
        try {
            activeDriver.destroy();
        } catch {
            // Ignore cleanup errors.
        }
        activeDriver = null;
    }

    const steps = buildSteps();
    if (steps.length === 0) {
        return;
    }

    const invite = getInviteElement();
    const storageKey = getStorageKey(invite);
    lastReturnFocusEl = returnFocusEl instanceof HTMLElement ? returnFocusEl : document.activeElement;

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let shouldPersistPreference = false;

    activeDriver = driver({
        steps,
        animate: !reducedMotion,
        smoothScroll: !reducedMotion,
        allowClose: true,
        allowKeyboardControl: true,
        showProgress: true,
        progressText: "Step {{current}} of {{total}}",
        nextBtnText: "Next",
        prevBtnText: "Previous",
        doneBtnText: "Finish",
        popoverClass: "tala-driver-popover",
        stagePadding: 6,
        stageRadius: 10,
        onPopoverRender: (popover) => {
            enhancePopoverAccessibility(popover);
        },
        onNextClick: (_element, _step, { driver: instance }) => {
            if (!instance.hasNextStep()) {
                shouldPersistPreference = true;
                instance.destroy();
                return;
            }
            instance.moveNext();
        },
        onCloseClick: (_element, _step, { driver: instance }) => {
            shouldPersistPreference = true;
            instance.destroy();
        },
        onDestroyStarted: (_element, _step, { driver: instance }) => {
            shouldPersistPreference = true;
            if (instance.isActive()) {
                instance.destroy();
            }
        },
        onDestroyed: () => {
            detachDialogTrap();
            if (shouldPersistPreference && storageKey) {
                writePreference(storageKey, "completed");
                hideInvite(invite);
            }
            activeDriver = null;
            if (lastReturnFocusEl instanceof HTMLElement && document.contains(lastReturnFocusEl)) {
                lastReturnFocusEl.focus({ preventScroll: true });
            } else {
                const fallback = document.querySelector("[data-tour-start], .fi-user-menu-trigger");
                if (fallback instanceof HTMLElement) {
                    fallback.focus({ preventScroll: true });
                }
            }
        },
    });

    activeDriver.drive();
}

function bindEvents() {
    if (document.documentElement.dataset.talaStudentTourBound === "true") {
        syncInviteVisibility();
        return;
    }
    document.documentElement.dataset.talaStudentTourBound = "true";

    document.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const startBtn = target.closest("[data-tour-start]");
        if (startBtn) {
            event.preventDefault();
            startTour(startBtn);
            return;
        }

        const dismissBtn = target.closest("[data-tour-dismiss]");
        if (dismissBtn) {
            event.preventDefault();
            const invite = getInviteElement();
            writePreference(getStorageKey(invite), "dismissed");
            hideInvite(invite);
            const focusTarget = document.querySelector(".fi-user-menu-trigger, #fi-main-content");
            if (focusTarget instanceof HTMLElement) {
                focusTarget.focus({ preventScroll: true });
            }
        }
    });

    window.addEventListener("tala:start-student-tour", (event) => {
        const customEvent = event instanceof CustomEvent ? event : null;
        startTour(customEvent?.detail?.returnFocusEl ?? null);
    });

    document.addEventListener("livewire:navigated", () => {
        if (activeDriver) {
            try {
                activeDriver.destroy();
            } catch {
                // Ignore cleanup errors during SPA navigation.
            }
            activeDriver = null;
        }
        syncInviteVisibility();
    });

    syncInviteVisibility();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindEvents, { once: true });
} else {
    bindEvents();
}
