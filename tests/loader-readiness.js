'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

function assert(condition, message) {
	if (!condition) {
		throw new Error('FAIL: ' + message);
	}
}

function createScheduler() {
	let now = 1000;
	let nextId = 1;
	let timers = [];
	return {
		now: () => now,
		set(callback, delay) {
			const timer = { id: nextId++, at: now + Math.max(0, Number(delay) || 0), callback };
			timers.push(timer);
			return timer.id;
		},
		clear(id) {
			timers = timers.filter((timer) => timer.id !== id);
		},
		advance(milliseconds) {
			const target = now + milliseconds;
			let next;
			do {
				timers.sort((a, b) => a.at - b.at || a.id - b.id);
				next = timers[0];
				if (!next || next.at > target) { break; }
				timers.shift();
				now = next.at;
				next.callback();
			} while (true);
			now = target;
		},
		runAll(limit = 100) {
			let count = 0;
			while (timers.length && count < limit) {
				timers.sort((a, b) => a.at - b.at || a.id - b.id);
				this.advance(timers[0].at - now);
				count += 1;
			}
			assert(count < limit, 'The readiness test scheduler must not loop indefinitely.');
		}
	};
}

function createMedia(tagName, rect, properties = {}) {
	const listeners = {};
	const classes = new Set(properties.classes || []);
	return Object.assign({
		tagName,
		dataset: {},
		complete: false,
		readyState: 0,
		error: null,
		classList: { contains: (name) => classes.has(name), add: (name) => classes.add(name) },
		getBoundingClientRect: () => rect,
		closest: () => null,
		addEventListener(name, callback) {
			(listeners[name] ||= []).push(callback);
		},
		removeEventListener(name, callback) {
			listeners[name] = (listeners[name] || []).filter((entry) => entry !== callback);
		},
		trigger(name) {
			(listeners[name] || []).slice().forEach((callback) => callback({ type: name }));
		}
	}, properties);
}


function createStorage() {
	const values = new Map();
	return {
		getItem: (key) => values.has(key) ? values.get(key) : null,
		setItem: (key, value) => values.set(key, String(value)),
		removeItem: (key) => values.delete(key)
	};
}

function createAnchor(href, attributes = {}) {
	const values = Object.assign({ href }, attributes);
	return {
		href: new URL(href, 'https://example.test/').href,
		getAttribute: (name) => Object.prototype.hasOwnProperty.call(values, name) ? values[name] : null,
		hasAttribute: (name) => Object.prototype.hasOwnProperty.call(values, name)
	};
}

function loadHooks(media, scheduler, managerSettings = {}) {
	const window = {
		KORiveManager: Object.assign({ testHooks: true, automaticLoaders: true, items: {} }, managerSettings),
		matchMedia: () => ({ matches: false }),
		innerWidth: 1280,
		innerHeight: 800,
		location: { href: 'https://example.test/', origin: 'https://example.test' },
		sessionStorage: createStorage(),
		localStorage: createStorage(),
		performance: { getEntriesByName: () => [] },
		getComputedStyle: () => ({ display: 'block', visibility: 'visible' }),
		requestAnimationFrame: (callback) => scheduler.set(callback, 16),
		cancelAnimationFrame: (id) => scheduler.clear(id)
	};
	const context = {
		window,
		document: { querySelectorAll: () => media },
		URL,
		Date,
		console,
		setTimeout: (callback, delay) => scheduler.set(callback, delay),
		clearTimeout: (id) => scheduler.clear(id)
	};
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/backgrounds.js'), 'utf8'), context);
	return window.KORiveManagerTestHooks;
}

function gateOptions(hooks, scheduler, events) {
	return {
		startedAt: scheduler.now(),
		graceDuration: 350,
		minimumVisibleDuration: 250,
		maximumDuration: 5000,
		exitHoldDuration: 100,
		now: scheduler.now,
		setTimer: scheduler.set.bind(scheduler),
		clearTimer: scheduler.clear.bind(scheduler),
		reveal: () => events.push('reveal'),
		suppress: () => events.push('suppress'),
		exit: (forced) => events.push(forced ? 'forced-exit' : 'exit')
	};
}

{
	const scheduler = createScheduler();
	const hooks = loadHooks([], scheduler);
	const events = [];
	const gate = hooks.createAdaptiveLoaderGate(gateOptions(hooks, scheduler, events));
	scheduler.advance(100);
	gate.ready();
	scheduler.advance(6000);
	assert(events.join(',') === 'suppress', 'A fast ready viewport must suppress the loader before the grace period.');
}

{
	const scheduler = createScheduler();
	const hooks = loadHooks([], scheduler);
	const events = [];
	const gate = hooks.createAdaptiveLoaderGate(gateOptions(hooks, scheduler, events));
	scheduler.advance(350);
	assert(events.join(',') === 'reveal', 'A slow viewport must reveal the loader after the grace period.');
	scheduler.advance(550);
	gate.ready();
	scheduler.advance(99);
	assert(events.join(',') === 'reveal', 'The configured exit pause must be honored after a slow viewport becomes ready.');
	scheduler.advance(1);
	assert(events.join(',') === 'reveal,exit', 'A slow viewport must exit promptly after readiness and the short exit pause.');
}

{
	const scheduler = createScheduler();
	const hooks = loadHooks([], scheduler);
	const events = [];
	hooks.createAdaptiveLoaderGate(gateOptions(hooks, scheduler, events));
	scheduler.advance(5000);
	assert(events.join(',') === 'reveal,forced-exit', 'The maximum wait must force a bounded release when visible media never settles.');
}

{
	const scheduler = createScheduler();
	const cachedVisible = createMedia('IMG', { top: 20, bottom: 220, left: 0, right: 300, width: 300, height: 200 }, { complete: true });
	const delayedOffscreen = createMedia('IMG', { top: 1200, bottom: 1400, left: 0, right: 300, width: 300, height: 200 }, { complete: false });
	const hooks = loadHooks([cachedVisible, delayedOffscreen], scheduler);
	let ready = 0;
	hooks.watchInitialViewportReady(() => { ready += 1; });
	scheduler.runAll();
	assert(ready === 1, 'Cached visible media must become ready without waiting for incomplete offscreen media.');
}

{
	const scheduler = createScheduler();
	const slowVisible = createMedia('IMG', { top: 20, bottom: 220, left: 0, right: 300, width: 300, height: 200 }, { complete: false });
	const hooks = loadHooks([slowVisible], scheduler);
	let ready = 0;
	hooks.watchInitialViewportReady(() => { ready += 1; });
	scheduler.runAll();
	assert(ready === 0, 'An incomplete visible image must keep the viewport pending.');
	slowVisible.complete = true;
	slowVisible.trigger('load');
	scheduler.runAll();
	assert(ready === 1, 'A visible image load must release viewport readiness after paint stabilization.');
}

{
	const scheduler = createScheduler();
	const failedVisible = createMedia('IMG', { top: 20, bottom: 220, left: 0, right: 300, width: 300, height: 200 }, { complete: false });
	const hooks = loadHooks([failedVisible], scheduler);
	let ready = 0;
	hooks.watchInitialViewportReady(() => { ready += 1; });
	scheduler.runAll();
	failedVisible.complete = true;
	failedVisible.trigger('error');
	scheduler.runAll();
	assert(ready === 1, 'A failed visible resource must settle rather than trapping the loader.');
}

{
	const scheduler = createScheduler();
	const visibleRive = createMedia('DIV', { top: 20, bottom: 420, left: 0, right: 600, width: 600, height: 400 }, { classes: ['ko-rive-item'] });
	const hooks = loadHooks([visibleRive], scheduler);
	let ready = 0;
	hooks.watchInitialViewportReady(() => { ready += 1; });
	scheduler.runAll();
	assert(ready === 0, 'An unready managed Rive item in the viewport must keep the adaptive loader pending.');
	visibleRive.classList.add('is-ready');
	visibleRive.trigger('ko-rive:ready');
	scheduler.runAll();
	assert(ready === 1, 'A managed Rive ready event must settle viewport readiness.');
}

{
	const scheduler = createScheduler();
	const hooks = loadHooks([], scheduler);
	const plainClick = { button: 0, defaultPrevented: false, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false };
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('/work/')) === 'https://example.test/work/', 'A normal same-tab internal link must qualify for the navigation loader handoff.');
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('/?view=all')) === 'https://example.test/?view=all', 'A query-changing internal navigation must qualify for the handoff.');
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('/#section')) === '', 'A same-page anchor must not trigger the loader.');
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('https://outside.example/work/')) === '', 'An external link must not trigger the loader.');
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('/work/', { target: '_blank' })) === '', 'A new-tab link must not trigger the loader.');
	assert(hooks.navigationIntentForClick(plainClick, createAnchor('/file.pdf', { download: '' })) === '', 'A download link must not trigger the loader.');
	assert(hooks.navigationIntentForClick(Object.assign({}, plainClick, { metaKey: true }), createAnchor('/work/')) === '', 'A modified click must not trigger the loader.');
	assert(hooks.navigationIntentForClick(Object.assign({}, plainClick, { defaultPrevented: true }), createAnchor('/work/')) === '', 'A canceled click must not trigger the loader.');
}

{
	const scheduler = createScheduler();
	const loaderConfig = {
		id: 'rive-page-loader',
		role: 'loader',
		loader: {
			enabled: true,
			priority: 10,
			displayMode: 'include_selected',
			targetUrls: ['https://example.test/work/'],
			graceDuration: 350,
			maximumDuration: 5000
		}
	};
	const hooks = loadHooks([], scheduler, { items: { 'rive-page-loader': loaderConfig } });
	assert(hooks.navigationLoaderFor('https://example.test/work/') === loaderConfig, 'The click handoff must select the same configured loader as the destination URL.');
	assert(hooks.navigationLoaderFor('https://example.test/news/') === null, 'A selected-location loader must not lead navigation to an unmatched destination.');
	const handoff = hooks.createNavigationHandoff('https://example.test/work/', loaderConfig, 2000);
	assert(handoff.destination === 'https://example.test/work/' && handoff.startedAt === 2000, 'The handoff must bind the intended destination and original click time.');
	assert(handoff.revealAfter === 120, 'The outgoing navigation lead must be capped at 120 ms even when destination Grace is longer.');
	assert(handoff.expiresAt === 17000, 'The handoff must expire after the bounded loader window plus a short transfer allowance.');
	assert(hooks.validNavigationFrame('data:image/webp;base64,AAAA') === true, 'A small validated WebP data URL must qualify as a painted-frame handoff.');
	assert(hooks.validNavigationFrame('data:text/html;base64,AAAA') === false, 'A non-image data URL must never qualify as a loader frame.');
	assert(hooks.validNavigationFrame('data:image/webp;base64,' + 'A'.repeat(350001)) === false, 'An oversized captured frame must be rejected before session storage or destination paint.');
	loaderConfig.loader.graceDuration = 75;
	assert(hooks.createNavigationHandoff('https://example.test/work/', loaderConfig, 3000).revealAfter === 75, 'A shorter configured Grace must remain authoritative for the outgoing lead.');
}

console.log('PASS: adaptive readiness, navigation lead, bounded frame handoff, exclusions, and failure fallback');
