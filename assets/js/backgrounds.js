(function () {
	'use strict';

	var settings = window.KORiveManager || {};
	var items = settings.items || {};
	var classMap = settings.classes || {};
	var runtimePromise = null;
	var assetPromises = {};
	var instances = [];
	var mutationObserver = null;
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var navigationBridge = null;
	var navigationHandoffKey = settings.navigationHandoffKey || 'koRiveLoaderNavigationHandoff';
	var navigationLeadMaximum = 120;
	var navigationFrameMaximumChars = 350000;
	var navigationFrameMaximumWidth = 1280;
	var navigationFrameMaximumHeight = 800;

	function warn(message, error) {
		if (window.console && typeof window.console.warn === 'function') {
			window.console.warn('KO Rive Manager: ' + message, error || '');
		}
	}

	function loadRuntime() {
		if (window.rive && typeof window.rive.Rive === 'function') {
			configureWasm();
			return Promise.resolve(window.rive);
		}
		if (runtimePromise) {
			return runtimePromise;
		}
		runtimePromise = new Promise(function (resolve, reject) {
			var existing = document.querySelector('script[data-ko-rive-runtime]');
			var script = existing || document.createElement('script');
			function loaded() {
				if (window.rive && typeof window.rive.Rive === 'function') {
					configureWasm();
					resolve(window.rive);
				} else {
					reject(new Error('The shared Rive runtime did not initialize.'));
				}
			}
			if (existing) {
				existing.addEventListener('load', loaded, { once: true });
				existing.addEventListener('error', reject, { once: true });
				return;
			}
			script.src = settings.runtimeUrl;
			script.async = true;
			script.dataset.koRiveRuntime = '1';
			script.addEventListener('load', loaded, { once: true });
			script.addEventListener('error', function () { reject(new Error('The shared Rive runtime could not be loaded.')); }, { once: true });
			document.head.appendChild(script);
		});
		return runtimePromise;
	}

	function configureWasm() {
		if (!window.KORiveRuntimeWasmConfigured && settings.wasmUrl && window.rive && window.rive.RuntimeLoader) {
			window.rive.RuntimeLoader.setWasmUrl(settings.wasmUrl);
			window.KORiveRuntimeWasmConfigured = true;
		}
	}

	function loadAsset(src) {
		if (!assetPromises[src]) {
			assetPromises[src] = window.fetch(src, { credentials: 'same-origin' }).then(function (response) {
				if (!response.ok) {
					throw new Error('Rive request returned ' + response.status + '.');
				}
				return response.arrayBuffer();
			});
		}
		return assetPromises[src];
	}

	function getFit(riveApi, value) {
		var names = { layout: 'Layout', cover: 'Cover', contain: 'Contain', fill: 'Fill', fitWidth: 'FitWidth', fitHeight: 'FitHeight', scaleDown: 'ScaleDown', none: 'None' };
		return riveApi.Fit[names[value] || 'Cover'] || riveApi.Fit.Cover;
	}

	function getAlignment(riveApi, value) {
		var names = { center: 'Center', topLeft: 'TopLeft', topCenter: 'TopCenter', topRight: 'TopRight', centerLeft: 'CenterLeft', centerRight: 'CenterRight', bottomLeft: 'BottomLeft', bottomCenter: 'BottomCenter', bottomRight: 'BottomRight' };
		return riveApi.Alignment[names[value] || 'Center'] || riveApi.Alignment.Center;
	}

	function isRenderable(element) {
		var style;
		var rect;
		if (!element || !element.isConnected) {
			return false;
		}
		style = window.getComputedStyle(element);
		if (style.display === 'none' || style.visibility === 'hidden') {
			return false;
		}
		rect = element.getBoundingClientRect();
		return rect.width > 1 && rect.height > 1;
	}

	function whenVisible(element, config, callback) {
		var intersection;
		var resize;
		var check = function () {
			if (!isRenderable(element)) {
				return;
			}
			if (intersection) { intersection.disconnect(); }
			if (resize) { resize.disconnect(); }
			callback();
		};

		if (isRenderable(element) && config.loadPolicy !== 'lazy') {
			callback();
			return;
		}
		if ('IntersectionObserver' in window) {
			intersection = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting || entry.intersectionRatio > 0) { check(); }
				});
			}, { rootMargin: config.loadPolicy === 'lazy' ? '250px 0px' : '0px' });
			intersection.observe(element);
		}
		if ('ResizeObserver' in window) {
			resize = new ResizeObserver(check);
			resize.observe(element);
		}
		window.setTimeout(check, 50);
	}

	function ensurePoster(element, config) {
		var poster;
		if (!config.posterUrl || element.querySelector(':scope > .ko-rive-poster')) {
			return;
		}
		poster = document.createElement('img');
		poster.className = 'ko-rive-poster';
		poster.src = config.posterUrl;
		poster.alt = '';
		poster.setAttribute('aria-hidden', 'true');
		element.insertBefore(poster, element.firstChild);
	}

	function prepareElement(element, config) {
		element.classList.add('ko-rive-item', 'ko-rive-' + config.role);
		if (config.fallbackColor) {
			element.style.setProperty('--ko-rive-fallback', config.fallbackColor);
		}
		if (config.role === 'hero') {
			element.style.setProperty('--ko-rive-height', config.height || '100svh');
			element.style.setProperty('--ko-rive-min-height', config.minHeight || '600px');
		}
		ensurePoster(element, config);
	}

	function makeCanvas(element) {
		var canvas = element.querySelector(':scope > .ko-rive-canvas');
		if (!canvas) {
			canvas = document.createElement('canvas');
			canvas.className = 'ko-rive-canvas';
			canvas.setAttribute('aria-hidden', 'true');
			element.appendChild(canvas);
		}
		return canvas;
	}

	function createRive(element, config, onReady, onFailure) {
		var canvas = makeCanvas(element);
		var resizeObserver = null;
		var instance = null;
		Promise.all([loadRuntime(), loadAsset(config.src)]).then(function (loaded) {
			var riveApi = loaded[0];
			var buffer = loaded[1];
			var options = {
				buffer: buffer.slice(0),
				canvas: canvas,
				autoplay: true,
				layout: new riveApi.Layout({ fit: getFit(riveApi, config.fit), alignment: getAlignment(riveApi, config.alignment) }),
				onLoad: function () {
					instance.resizeDrawingSurfaceToCanvas();
					window.requestAnimationFrame(function () {
						element.classList.add('is-ready');
						if (onReady) { onReady(instance, riveApi); }
					});
				},
				onLoadError: function (error) {
					element.classList.add('has-error');
					if (onFailure) { onFailure(error); }
				}
			};
			if (config.artboard) { options.artboard = config.artboard; }
			if (config.stateMachine) { options.stateMachines = config.stateMachine; }
			else if (config.animation) { options.animations = config.animation; }
			instance = new riveApi.Rive(options);
			element._koRiveInstance = instance;
			instances.push(instance);
			if ('ResizeObserver' in window) {
				resizeObserver = new ResizeObserver(function () {
					if (instance && instance.loaded && instance.artboard && isRenderable(element)) { instance.resizeDrawingSurfaceToCanvas(); }
				});
				resizeObserver.observe(element);
			}
			element._koRiveResizeObserver = resizeObserver;
		}).catch(function (error) {
			element.classList.add('has-error');
			warn('The animation “' + config.name + '” could not be initialized.', error);
			if (onFailure) { onFailure(error); }
		});
	}

	function initializeVisual(element, config) {
		if (reduceMotion) {
			element.classList.add('is-reduced-motion');
			return;
		}
		createRive(element, config, function () {
			element.dispatchEvent(new CustomEvent('ko-rive:ready', { bubbles: true, detail: { id: config.id, role: config.role } }));
		}, function () {
			element.dispatchEvent(new CustomEvent('ko-rive:error', { bubbles: true, detail: { id: config.id, role: config.role } }));
		});
	}

	function storageAvailable(storage) {
		try {
			storage.setItem('__ko_rive_test__', '1');
			storage.removeItem('__ko_rive_test__');
			return true;
		} catch (error) {
			return false;
		}
	}

	function createAdaptiveLoaderGate(options) {
		var now = options.now || function () { return Date.now(); };
		var setTimer = options.setTimer || function (callback, delay) { return window.setTimeout(callback, delay); };
		var clearTimer = options.clearTimer || function (timer) { window.clearTimeout(timer); };
		var startedAt = parseInt(options.startedAt, 10) || now();
		var grace = Math.max(0, parseInt(options.graceDuration, 10) || 0);
		var minimumVisible = Math.max(0, parseInt(options.minimumVisibleDuration, 10) || 0);
		var maximum = Math.max(1000, parseInt(options.maximumDuration, 10) || 5000);
		var hold = Math.max(0, parseInt(options.exitHoldDuration, 10) || 0);
		var revealed = !!options.initiallyRevealed;
		var revealedAt = revealed ? ( parseInt(options.revealedAt, 10) || now() ) : 0;
		var finished = false;
		var graceTimer = null;
		var maximumTimer = null;
		var exitTimer = null;

		function clearAllTimers() {
			if (graceTimer) { clearTimer(graceTimer); graceTimer = null; }
			if (maximumTimer) { clearTimer(maximumTimer); maximumTimer = null; }
			if (exitTimer) { clearTimer(exitTimer); exitTimer = null; }
		}

		function reveal() {
			if (finished || revealed) { return; }
			revealed = true;
			revealedAt = now();
			if (typeof options.reveal === 'function') { options.reveal(); }
		}

		function exit(force) {
			var delay;
			var remainingMaximum;
			if (finished) { return; }
			finished = true;
			if (!revealed) {
				clearAllTimers();
				if (typeof options.suppress === 'function') { options.suppress(); }
				return;
			}
			delay = force ? 0 : Math.max(0, minimumVisible - (now() - revealedAt)) + hold;
			remainingMaximum = Math.max(0, maximum - (now() - startedAt));
			delay = Math.min(delay, remainingMaximum);
			if (graceTimer) { clearTimer(graceTimer); graceTimer = null; }
			if (maximumTimer) { clearTimer(maximumTimer); maximumTimer = null; }
			exitTimer = setTimer(function () {
				exitTimer = null;
				if (typeof options.exit === 'function') { options.exit(!!force); }
			}, delay);
		}

		function ready() {
			exit(false);
		}

		function forceExit() {
			exit(true);
		}

		function cancel() {
			if (finished && !exitTimer) { return; }
			finished = true;
			clearAllTimers();
		}

		if (revealed && typeof options.reveal === 'function') {
			options.reveal();
		} else {
			graceTimer = setTimer(reveal, Math.max(0, grace - (now() - startedAt)));
		}
		maximumTimer = setTimer(forceExit, Math.max(0, maximum - (now() - startedAt)));

		return {
			ready: ready,
			forceExit: forceExit,
			cancel: cancel,
			isRevealed: function () { return revealed; },
			isFinished: function () { return finished; }
		};
	}

	function elementIsInInitialViewport(element) {
		var rect;
		var style;
		if (!element || (element.closest && element.closest('.ko-rive-loader'))) { return false; }
		rect = element.getBoundingClientRect();
		if (!rect || rect.width <= 0 || rect.height <= 0 || rect.bottom <= 0 || rect.right <= 0 || rect.top >= window.innerHeight || rect.left >= window.innerWidth) { return false; }
		style = window.getComputedStyle ? window.getComputedStyle(element) : null;
		return !style || ('none' !== style.display && 'hidden' !== style.visibility && 'collapse' !== style.visibility);
	}

	function visibleResourceIsSettled(element) {
		var tag = element && element.tagName ? element.tagName.toUpperCase() : '';
		var source;
		var entries;
		if (!element || '1' === (element.dataset && element.dataset.koRiveViewportSettled)) { return true; }
		if (element.classList && element.classList.contains('ko-rive-item')) {
			return element.classList.contains('is-ready') || element.classList.contains('has-error') || element.classList.contains('is-reduced-motion');
		}
		if ('IMG' === tag) { return !!element.complete; }
		if ('VIDEO' === tag) { return element.readyState >= 2 || !!element.error; }
		if ('IFRAME' !== tag) { return true; }
		try {
			if (element.contentDocument && 'complete' === element.contentDocument.readyState) { return true; }
		} catch (error) {}
		source = element.currentSrc || element.src || '';
		if (!source || 'about:blank' === source) { return true; }
		try {
			entries = window.performance && window.performance.getEntriesByName ? window.performance.getEntriesByName(new URL(source, window.location.href).href) : [];
			return entries.some(function (entry) { return entry.responseEnd > 0; });
		} catch (error) {
			return false;
		}
	}

	function watchInitialViewportReady(done) {
		var canceled = false;
		var listenerCleanups = [];
		var frameOne = null;
		var frameTwo = null;
		var stablePasses = 0;

		function clearListeners() {
			listenerCleanups.forEach(function (cleanup) { cleanup(); });
			listenerCleanups = [];
		}

		function cancel() {
			canceled = true;
			clearListeners();
			if (frameOne) { window.cancelAnimationFrame(frameOne); frameOne = null; }
			if (frameTwo) { window.cancelAnimationFrame(frameTwo); frameTwo = null; }
		}

		function scheduleScan() {
			if (canceled) { return; }
			if (frameOne) { window.cancelAnimationFrame(frameOne); }
			if (frameTwo) { window.cancelAnimationFrame(frameTwo); }
			frameOne = window.requestAnimationFrame(function () {
				frameOne = null;
				frameTwo = window.requestAnimationFrame(function () {
					frameTwo = null;
					scan();
				});
			});
		}

		function watch(element, events) {
			var settled = false;
			function complete() {
				if (settled || canceled) { return; }
				settled = true;
				if (element.dataset) { element.dataset.koRiveViewportSettled = '1'; }
				scheduleScan();
			}
			events.forEach(function (eventName) { element.addEventListener(eventName, complete, { once: true }); });
			listenerCleanups.push(function () { events.forEach(function (eventName) { element.removeEventListener(eventName, complete); }); });
		}

		function scan() {
			var pending = [];
			if (canceled) { return; }
			clearListeners();
			Array.prototype.slice.call(document.querySelectorAll('img,video,iframe,.ko-rive-item[data-ko-rive-id]:not(.ko-rive-loader)')).forEach(function (element) {
				if (elementIsInInitialViewport(element) && !visibleResourceIsSettled(element)) { pending.push(element); }
			});
			if (!pending.length) {
				if (stablePasses >= 1) {
					cancel();
					done();
					return;
				}
				stablePasses += 1;
				scheduleScan();
				return;
			}
			stablePasses = 0;
			pending.forEach(function (element) {
				var tag = element.tagName.toUpperCase();
				watch(element, element.classList && element.classList.contains('ko-rive-item') ? ['ko-rive:ready', 'ko-rive:error'] : ( 'VIDEO' === tag ? ['loadeddata', 'error'] : ['load', 'error'] ));
			});
		}

		scheduleScan();
		return cancel;
	}

	function normalizedInternalUrl(value) {
		var parsed;
		var path;
		try {
			parsed = new URL(value, window.location.href);
		} catch (error) {
			return '';
		}
		if (!/^https?:$/.test(parsed.protocol) || parsed.origin !== window.location.origin) {
			return '';
		}
		path = parsed.pathname.replace(/\/+$/, '') || '/';
		return parsed.origin + path;
	}

	function comparableNavigationUrl(value) {
		var parsed;
		try {
			parsed = new URL(value, window.location.href);
		} catch (error) {
			return '';
		}
		if (!/^https?:$/.test(parsed.protocol) || parsed.origin !== window.location.origin) { return ''; }
		return parsed.origin + parsed.pathname + parsed.search;
	}

	function navigationIntentForClick(event, anchor) {
		var destination;
		var current;
		var target;
		var href;
		if (!event || !anchor || event.defaultPrevented || (typeof event.button === 'number' && event.button !== 0) || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return ''; }
		if (anchor.hasAttribute && (anchor.hasAttribute('download') || anchor.hasAttribute('data-ko-rive-no-loader'))) { return ''; }
		if (anchor.getAttribute && anchor.getAttribute('aria-disabled') === 'true') { return ''; }
		target = anchor.getAttribute ? (anchor.getAttribute('target') || '').toLowerCase() : '';
		if (target && target !== '_self') { return ''; }
		href = anchor.getAttribute ? anchor.getAttribute('href') : anchor.href;
		if (!href) { return ''; }
		try {
			destination = new URL(anchor.href || href, window.location.href);
			current = new URL(window.location.href);
		} catch (error) {
			return '';
		}
		if (!/^https?:$/.test(destination.protocol) || destination.origin !== current.origin) { return ''; }
		if (destination.origin + destination.pathname + destination.search === current.origin + current.pathname + current.search) { return ''; }
		return destination.href;
	}

	function navigationLoaderFor(destination) {
		var normalized = normalizedInternalUrl(destination);
		var matches = [];
		if (!normalized || settings.automaticLoaders === false) { return null; }
		Object.keys(items).forEach(function (id) {
			var config = items[id];
			var loader = config && config.loader ? config.loader : {};
			var targets;
			var selected;
			if (!config || config.role !== 'loader' || !loader.enabled) { return; }
			targets = (loader.targetUrls || []).map(normalizedInternalUrl).filter(Boolean);
			selected = targets.indexOf(normalized) !== -1;
			if (loader.displayMode === 'everywhere' || (loader.displayMode === 'include_selected' && selected) || (loader.displayMode === 'exclude_selected' && !selected)) {
				matches.push(config);
			}
		});
		matches.sort(function (a, b) { return (parseInt(a.loader.priority, 10) || 999) - (parseInt(b.loader.priority, 10) || 999); });
		return matches.length ? matches[0] : null;
	}

	function navigationFrequencyAllows(config) {
		var loader = config && config.loader ? config.loader : {};
		var key = loader.storageKey || ('koRiveLoaderSeen-' + config.id);
		var previous;
		if (loader.respectReducedMotion && reduceMotion) { return false; }
		try {
			if (loader.frequency === 'session') { return !window.sessionStorage.getItem(key); }
			if (loader.frequency === 'daily') {
				previous = parseInt(window.localStorage.getItem(key), 10) || 0;
				return Date.now() - previous >= 86400000;
			}
		} catch (error) {}
		return true;
	}

	function createNavigationHandoff(destination, config, startedAt) {
		var loader = config.loader || {};
		var grace = Math.max(0, parseInt(loader.graceDuration, 10) || 0);
		var maximum = Math.max(1000, parseInt(loader.maximumDuration, 10) || 5000);
		return {
			version: 1,
			id: config.id,
			destination: comparableNavigationUrl(destination),
			startedAt: startedAt,
			revealAfter: Math.min(grace, navigationLeadMaximum),
			revealedAt: 0,
			expiresAt: startedAt + maximum + 10000
		};
	}

	function validNavigationFrame(value) {
		return typeof value === 'string' && value.length <= navigationFrameMaximumChars && /^data:image\/(?:webp|png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(value);
	}

	function canvasHasUsefulPaint(canvas) {
		var probe;
		var context;
		var data;
		var index;
		var alpha;
		try {
			probe = document.createElement('canvas');
			probe.width = 160;
			probe.height = 100;
			context = probe.getContext('2d', { willReadFrequently: true });
			if (!context) { return false; }
			context.drawImage(canvas, 0, 0, probe.width, probe.height);
			data = context.getImageData(0, 0, probe.width, probe.height).data;
			for (index = 0; index < data.length; index += 4) {
				alpha = data[index + 3];
				if (alpha > 24 && (data[index] < 245 || data[index + 1] < 245 || data[index + 2] < 245)) { return true; }
			}
		} catch (error) {}
		return false;
	}

	function encodeNavigationFrame(canvas) {
		var rect;
		var sourceWidth;
		var sourceHeight;
		var scale;
		var output;
		var context;
		var data;
		if (!canvas || !canvasHasUsefulPaint(canvas)) { return ''; }
		rect = canvas.getBoundingClientRect ? canvas.getBoundingClientRect() : null;
		sourceWidth = Math.max(1, Math.round((rect && rect.width) || canvas.width || window.innerWidth || 1));
		sourceHeight = Math.max(1, Math.round((rect && rect.height) || canvas.height || window.innerHeight || 1));
		scale = Math.min(1, navigationFrameMaximumWidth / sourceWidth, navigationFrameMaximumHeight / sourceHeight);
		try {
			output = document.createElement('canvas');
			output.width = Math.max(1, Math.round(sourceWidth * scale));
			output.height = Math.max(1, Math.round(sourceHeight * scale));
			context = output.getContext('2d');
			if (!context) { return ''; }
			context.drawImage(canvas, 0, 0, output.width, output.height);
			data = output.toDataURL('image/webp', 0.72);
			if (!validNavigationFrame(data)) {
				output.width = Math.max(1, Math.round(output.width * 0.65));
				output.height = Math.max(1, Math.round(output.height * 0.65));
				context = output.getContext('2d');
				context.drawImage(canvas, 0, 0, output.width, output.height);
				data = output.toDataURL('image/webp', 0.5);
			}
			return validNavigationFrame(data) ? data : '';
		} catch (error) {
			return '';
		}
	}

	function captureNavigationFrame(bridge) {
		var canvas;
		var frame;
		if (!bridge || !bridge.element || !bridge.handoff || !bridge.element.classList.contains('is-ready')) { return false; }
		canvas = bridge.element.querySelector(':scope > .ko-rive-canvas');
		frame = encodeNavigationFrame(canvas);
		if (!frame) { return false; }
		bridge.handoff.frameDataUrl = frame;
		bridge.handoff.frameCapturedAt = Date.now();
		writeNavigationHandoff(bridge.handoff);
		return true;
	}

	function waitForUsefulNavigationFrame(bridge) {
		function scan() {
			if (!bridge || navigationBridge !== bridge || !bridge.element) { return; }
			if (captureNavigationFrame(bridge)) {
				bridge.captureFrameRequest = null;
				return;
			}
			bridge.captureFrameRequest = window.requestAnimationFrame(scan);
		}
		if (!bridge || bridge.captureFrameRequest) { return; }
		bridge.captureFrameRequest = window.requestAnimationFrame(scan);
	}

	function writeNavigationHandoff(handoff) {
		var fallback;
		try {
			window.sessionStorage.setItem(navigationHandoffKey, JSON.stringify(handoff));
		} catch (error) {
			if (!handoff || !handoff.frameDataUrl) { return; }
			fallback = Object.assign({}, handoff);
			delete fallback.frameDataUrl;
			delete fallback.frameCapturedAt;
			try { window.sessionStorage.setItem(navigationHandoffKey, JSON.stringify(fallback)); } catch (storageError) {}
		}
	}

	function clearNavigationHandoff(handoff) {
		var stored;
		try {
			stored = JSON.parse(window.sessionStorage.getItem(navigationHandoffKey) || 'null');
			if (!handoff || (stored && stored.id === handoff.id && stored.startedAt === handoff.startedAt)) {
				window.sessionStorage.removeItem(navigationHandoffKey);
			}
		} catch (error) {}
	}

	function cleanupNavigationBridge(preserveHandoff) {
		var bridge = navigationBridge;
		if (!bridge) { return; }
		navigationBridge = null;
		if (bridge.revealTimer) { window.clearTimeout(bridge.revealTimer); }
		if (bridge.maximumTimer) { window.clearTimeout(bridge.maximumTimer); }
		if (bridge.captureFrameRequest) { window.cancelAnimationFrame(bridge.captureFrameRequest); }
		if (bridge.element && bridge.element._koRiveResizeObserver) { bridge.element._koRiveResizeObserver.disconnect(); }
		if (bridge.element && bridge.element._koRiveInstance && typeof bridge.element._koRiveInstance.cleanup === 'function') {
			bridge.element._koRiveInstance.cleanup();
			instances = instances.filter(function (instance) { return instance !== bridge.element._koRiveInstance; });
		}
		if (bridge.element && bridge.element.parentNode) { bridge.element.parentNode.removeChild(bridge.element); }
		document.documentElement.classList.remove('ko-rive-scroll-locked');
		document.body.classList.remove('ko-rive-scroll-locked');
		if (!preserveHandoff) { clearNavigationHandoff(bridge.handoff); }
	}

	function revealNavigationBridge(bridge) {
		var loader;
		if (!bridge || navigationBridge !== bridge || bridge.element) { return; }
		bridge.handoff.revealedAt = Date.now();
		writeNavigationHandoff(bridge.handoff);
		bridge.element = createLoaderElement(bridge.config, 'ko-rive-navigation-loader');
		if (!bridge.element) { return; }
		loader = bridge.config.loader || {};
		bridge.element.dataset.koRiveInitialized = '1';
		prepareElement(bridge.element, bridge.config);
		bridge.element.classList.add('is-active');
		if (loader.lockScroll) {
			document.documentElement.classList.add('ko-rive-scroll-locked');
			document.body.classList.add('ko-rive-scroll-locked');
		}
		createRive(bridge.element, bridge.config, function () {
			waitForUsefulNavigationFrame(bridge);
		}, function (error) {
			warn('The outgoing Page Loader animation could not start; keeping its fallback visible until navigation or the maximum wait.', error);
		});
	}

	function armNavigationBridge(destination, config) {
		var startedAt = Date.now();
		var handoff = createNavigationHandoff(destination, config, startedAt);
		var maximum = Math.max(1000, parseInt(config.loader.maximumDuration, 10) || 5000);
		var bridge;
		cleanupNavigationBridge(false);
		bridge = { config: config, handoff: handoff, element: null, revealTimer: null, maximumTimer: null, captureFrameRequest: null };
		navigationBridge = bridge;
		writeNavigationHandoff(handoff);
		bridge.revealTimer = window.setTimeout(function () { revealNavigationBridge(bridge); }, handoff.revealAfter);
		bridge.maximumTimer = window.setTimeout(function () {
			if (navigationBridge === bridge) { cleanupNavigationBridge(false); }
		}, maximum);
		return bridge;
	}

	function handleNavigationClick(event) {
		var anchor = event && event.target && event.target.closest ? event.target.closest('a[href]') : null;
		var destination = navigationIntentForClick(event, anchor);
		var config = destination ? navigationLoaderFor(destination) : null;
		var deferredMenuNavigation = !!(anchor && anchor.closest && anchor.closest('[data-canvas="Fullwidth Nav"]') && document.body.classList.contains('ko-nav-open'));
		var bridge;
		if (!config || !navigationFrequencyAllows(config)) { return; }
		bridge = armNavigationBridge(destination, config);
		Promise.resolve().then(function () {
			/* KO Menu Enhancements owns fullscreen link clicks while its exit
			 * sequence completes, then navigates with location.assign(). */
			if (event.defaultPrevented && !deferredMenuNavigation && navigationBridge === bridge) { cleanupNavigationBridge(false); }
		});
	}

	if (settings.testHooks) {
		window.KORiveManagerTestHooks = {
			createAdaptiveLoaderGate: createAdaptiveLoaderGate,
			elementIsInInitialViewport: elementIsInInitialViewport,
			visibleResourceIsSettled: visibleResourceIsSettled,
			watchInitialViewportReady: watchInitialViewportReady,
			navigationIntentForClick: navigationIntentForClick,
			navigationLoaderFor: navigationLoaderFor,
			createNavigationHandoff: createNavigationHandoff,
			validNavigationFrame: validNavigationFrame
		};
		return;
	}

	function initializeLoader(element, config) {
		var loader = config.loader || {};
		var cleaned = false;
		var riveStarted = false;
		var gate = null;
		var cancelViewportWatch = null;
		var cleanupTimer = null;
		var transitionHandler = null;
		var bootstrap = window.KORiveLoaderBootstrap && window.KORiveLoaderBootstrap.id === config.id ? window.KORiveLoaderBootstrap : null;
		var handoffFrame = null;
		var handoffRemovalTimer = null;
		var handoffPaintFrame = null;
		var bootstrapReleaseTimer = null;

		function installHandoffFrame() {
			if (!bootstrap || !validNavigationFrame(bootstrap.frameDataUrl)) { return; }
			handoffFrame = document.createElement('img');
			handoffFrame.className = 'ko-rive-handoff-frame';
			handoffFrame.src = bootstrap.frameDataUrl;
			handoffFrame.alt = '';
			handoffFrame.setAttribute('aria-hidden', 'true');
			element.insertBefore(handoffFrame, element.firstChild);
		}

		function retireHandoffFrame() {
			if (!handoffFrame || cleaned) { return; }
			window.requestAnimationFrame(function () {
				if (!handoffFrame || cleaned) { return; }
				element.classList.add('is-handoff-ready');
				handoffRemovalTimer = window.setTimeout(function () {
					if (handoffFrame && handoffFrame.parentNode) { handoffFrame.parentNode.removeChild(handoffFrame); }
					handoffFrame = null;
				}, 280);
			});
		}

		function waitForUsefulDestinationPaint() {
			var canvas;
			function scan() {
				if (!handoffFrame || cleaned) { return; }
				canvas = element.querySelector(':scope > .ko-rive-canvas');
				if (canvasHasUsefulPaint(canvas)) {
					handoffPaintFrame = null;
					retireHandoffFrame();
					return;
				}
				handoffPaintFrame = window.requestAnimationFrame(scan);
			}
			if (handoffFrame && !handoffPaintFrame) { handoffPaintFrame = window.requestAnimationFrame(scan); }
		}

		function releaseBootstrapWhenCovered() {
			var scheduled = false;
			function scheduleRelease() {
				if (scheduled || cleaned || !bootstrap || typeof bootstrap.release !== 'function') { return; }
				scheduled = true;
				if (bootstrapReleaseTimer) { window.clearTimeout(bootstrapReleaseTimer); bootstrapReleaseTimer = null; }
				window.requestAnimationFrame(function () {
					window.requestAnimationFrame(function () { bootstrap.release(config.id); });
				});
			}
			if (!bootstrap || typeof bootstrap.release !== 'function') { return; }
			if (handoffFrame && typeof handoffFrame.decode === 'function') {
				handoffFrame.decode().then(scheduleRelease, scheduleRelease);
				bootstrapReleaseTimer = window.setTimeout(scheduleRelease, 250);
				return;
			}
			scheduleRelease();
		}

		function unlockScroll() {
			document.documentElement.classList.remove('ko-rive-scroll-locked');
			document.body.classList.remove('ko-rive-scroll-locked');
		}

		function cleanup() {
			if (cleaned) { return; }
			cleaned = true;
			if (gate) { gate.cancel(); }
			if (cancelViewportWatch) { cancelViewportWatch(); }
			if (cleanupTimer) { window.clearTimeout(cleanupTimer); }
			if (handoffRemovalTimer) { window.clearTimeout(handoffRemovalTimer); }
			if (handoffPaintFrame) { window.cancelAnimationFrame(handoffPaintFrame); }
			if (bootstrapReleaseTimer) { window.clearTimeout(bootstrapReleaseTimer); }
			if (transitionHandler) { element.removeEventListener('transitionend', transitionHandler); }
			if (element._koRiveResizeObserver) { element._koRiveResizeObserver.disconnect(); }
			if (element._koRiveInstance && typeof element._koRiveInstance.cleanup === 'function') { element._koRiveInstance.cleanup(); }
			unlockScroll();
			if (bootstrap && typeof bootstrap.release === 'function') { bootstrap.release(config.id); }
			if (element.parentNode) { element.parentNode.removeChild(element); }
		}

		function startRive() {
			if (riveStarted || cleaned) { return; }
			riveStarted = true;
			createRive(element, config, waitForUsefulDestinationPaint, function (error) {
				warn('The Page Loader animation could not start; keeping the fallback visible until viewport readiness or the maximum wait.', error);
			});
		}

		function reveal() {
			if (cleaned) { return; }
			element.classList.add('is-active');
			if (loader.lockScroll) {
				document.documentElement.classList.add('ko-rive-scroll-locked');
				document.body.classList.add('ko-rive-scroll-locked');
			}
			startRive();
			releaseBootstrapWhenCovered();
		}

		function beginExit() {
			var fade;
			fade = Math.max(20, parseInt(loader.fadeDuration, 10) || 0);
			element.style.setProperty('--ko-rive-loader-fade', fade + 'ms');
			transitionHandler = function (event) {
				if (event.target === element && event.propertyName === 'opacity') { cleanup(); }
			};
			element.addEventListener('transitionend', transitionHandler);
			window.requestAnimationFrame(function () { element.classList.add('is-exiting'); });
			cleanupTimer = window.setTimeout(cleanup, fade + 150);
		}

		function shouldSkipFrequency() {
			var key = loader.storageKey || ('koRiveLoaderSeen-' + config.id);
			var now = Date.now();
			var previous;
			if (element.dataset.koRiveMarked === '1') { return false; }
			if (loader.frequency === 'session' && storageAvailable(window.sessionStorage)) {
				if (window.sessionStorage.getItem(key)) { return true; }
				window.sessionStorage.setItem(key, String(now));
			}
			if (loader.frequency === 'daily' && storageAvailable(window.localStorage)) {
				previous = parseInt(window.localStorage.getItem(key), 10) || 0;
				if (now - previous < 86400000) { return true; }
				window.localStorage.setItem(key, String(now));
			}
			return false;
		}

		if (element.dataset.koRiveSkip === '1' || shouldSkipFrequency() || (loader.respectReducedMotion && reduceMotion)) {
			cleanup();
			return;
		}
		installHandoffFrame();
		gate = createAdaptiveLoaderGate({
			startedAt: bootstrap && bootstrap.startedAt ? bootstrap.startedAt : Date.now(),
			initiallyRevealed: !!(bootstrap && typeof bootstrap.isRevealed === 'function' && bootstrap.isRevealed()),
			revealedAt: bootstrap ? bootstrap.revealedAt : 0,
			graceDuration: loader.graceDuration,
			minimumVisibleDuration: loader.minimumVisibleDuration,
			maximumDuration: loader.maximumDuration,
			exitHoldDuration: loader.exitHoldDuration,
			reveal: reveal,
			exit: beginExit,
			suppress: cleanup
		});
		cancelViewportWatch = watchInitialViewportReady(function () { gate.ready(); });
	}

	function initializeElement(element, config) {
		if (!element || !config || element.dataset.koRiveInitialized === '1') {
			return;
		}
		element.dataset.koRiveInitialized = '1';
		prepareElement(element, config);
		if (config.role === 'loader') {
			initializeLoader(element, config);
			return;
		}
		whenVisible(element, config, function () { initializeVisual(element, config); });
	}

	function createLoaderElement(config, extraClass) {
		var element;
		if (!config || document.querySelector('.ko-rive-loader')) { return null; }
		element = document.createElement('div');
		element.className = 'ko-rive-item ' + config.id + ' ko-rive-loader' + (extraClass ? ' ' + extraClass : '');
		element.setAttribute('data-ko-rive-id', config.id);
		element.setAttribute('role', 'status');
		element.setAttribute('aria-live', 'polite');
		element.setAttribute('aria-label', (config.loader && config.loader.ariaLabel) || config.name || 'Loading page');
		document.body.appendChild(element);
		return element;
	}

	function showAutomaticLoader() {
		var config = navigationLoaderFor(window.location.href);
		var element;
		if (!config || document.querySelector('.ko-rive-loader')) { return; }
		element = createLoaderElement(config, 'ko-rive-automatic-loader');
		if (!element) { return; }
		initializeElement(element, config);
	}

	function collect(root) {
		var found = [];
		var seen = [];
		function add(element, id) {
			if (!element || seen.indexOf(element) !== -1 || !items[id]) { return; }
			seen.push(element);
			found.push({ element: element, config: items[id] });
		}
		if (root.nodeType !== 1 && root !== document) { return found; }
		if (root.matches && root.matches('[data-ko-rive-id]')) { add(root, root.getAttribute('data-ko-rive-id')); }
		(root.querySelectorAll ? root.querySelectorAll('[data-ko-rive-id]') : []).forEach(function (element) { add(element, element.getAttribute('data-ko-rive-id')); });
		Object.keys(classMap).forEach(function (className) {
			var id = classMap[className];
			if (root.classList && root.classList.contains(className)) { add(root, id); }
			(root.getElementsByClassName ? Array.prototype.slice.call(root.getElementsByClassName(className)) : []).forEach(function (element) { add(element, id); });
		});
		return found;
	}

	function initialize(root) {
		collect(root || document).forEach(function (entry) { initializeElement(entry.element, entry.config); });
	}

	function start() {
		/* Server-side conditional rendering can be bypassed by full-page caches.
		 * Re-evaluate the current URL from the Library config in the browser. */
		window.addEventListener('click', handleNavigationClick, true);
		showAutomaticLoader();
		initialize(document);
		mutationObserver = new MutationObserver(function (records) {
			records.forEach(function (record) {
				record.addedNodes.forEach(function (node) { if (node.nodeType === 1) { initialize(node); } });
			});
		});
		mutationObserver.observe(document.documentElement, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}

	window.addEventListener('pagehide', function () {
		if (navigationBridge) { captureNavigationFrame(navigationBridge); }
		if (mutationObserver) { mutationObserver.disconnect(); }
		instances.forEach(function (instance) { if (instance && typeof instance.cleanup === 'function') { instance.cleanup(); } });
		instances = [];
	}, { once: true });

	window.addEventListener('pageshow', function (event) {
		if (event.persisted) { cleanupNavigationBridge(false); }
	});
}());
