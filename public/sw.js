"use strict";

const CACHE_NAME = "tala-pwa-shell-v1";
const OFFLINE_URL = "/offline.html";
const PRECACHE_URLS = [
    OFFLINE_URL,
    "/talalogo.jpg",
];

const isGetRequest = (request) => request.method === "GET";

const isSameOrigin = (url) => url.origin === self.location.origin;

const isStaticAsset = (url) => [
    "/talalogo.jpg",
    "/favicon.ico",
].includes(url.pathname) || url.pathname.startsWith("/build/");

const isProtectedRoute = (url) => [
    "/student",
    "/admin",
    "/livewire",
].some((prefix) => url.pathname === prefix || url.pathname.startsWith(`${prefix}/`));

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)),
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames
                .filter((cacheName) => cacheName !== CACHE_NAME)
                .map((cacheName) => caches.delete(cacheName)),
        )),
    );
    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    const { request } = event;

    if (! isGetRequest(request)) {
        return;
    }

    const url = new URL(request.url);

    if (! isSameOrigin(url)) {
        return;
    }

    if (request.mode === "navigate") {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );

        return;
    }

    if (isProtectedRoute(url)) {
        event.respondWith(fetch(request));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => cachedResponse || fetch(request)),
        );
    }
});
