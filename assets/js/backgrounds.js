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

	function initializeLoader(element, config) {
		var loader = config.loader || {};
		var finished = false;
		var cleaned = false;
		var animationComplete = false;
		var pageReady = false;
		var pageReadyScheduled = false;
		var startedAt = Date.now();
		var fallbackTimer = null;
		var safetyTimer = null;
		var exitDelayTimer = null;
		var cleanupTimer = null;
		var transitionHandler = null;
		var loadHandler = null;
		var paintFrameOne = null;
		var paintFrameTwo = null;

		function unlockScroll() {
			document.documentElement.classList.remove('ko-rive-scroll-locked');
			document.body.classList.remove('ko-rive-scroll-locked');
		}

		function cleanup() {
			if (cleaned) { return; }
			cleaned = true;
			if (fallbackTimer) { window.clearTimeout(fallbackTimer); }
			if (safetyTimer) { window.clearTimeout(safetyTimer); }
			if (exitDelayTimer) { window.clearTimeout(exitDelayTimer); }
			if (cleanupTimer) { window.clearTimeout(cleanupTimer); }
			if (loadHandler) { window.removeEventListener('load', loadHandler); }
			if (paintFrameOne) { window.cancelAnimationFrame(paintFrameOne); }
			if (paintFrameTwo) { window.cancelAnimationFrame(paintFrameTwo); }
			if (transitionHandler) { element.removeEventListener('transitionend', transitionHandler); }
			if (element._koRiveResizeObserver) { element._koRiveResizeObserver.disconnect(); }
			if (element._koRiveInstance && typeof element._koRiveInstance.cleanup === 'function') { element._koRiveInstance.cleanup(); }
			unlockScroll();
			if (element.parentNode) { element.parentNode.removeChild(element); }
		}

		function finish() {
			var minimum;
			var remaining;
			var hold;
			var fade;
			if (finished) { return; }
			finished = true;
			minimum = Math.max(0, parseInt(loader.minimumDuration, 10) || 0);
			remaining = Math.max(0, minimum - (Date.now() - startedAt));
			hold = Math.max(0, parseInt(loader.exitHoldDuration, 10) || 0);
			fade = Math.max(20, parseInt(loader.fadeDuration, 10) || 0);
			element.style.setProperty('--ko-rive-loader-fade', fade + 'ms');
			exitDelayTimer = window.setTimeout(function () {
				transitionHandler = function (event) {
					if (event.target === element && event.propertyName === 'opacity') { cleanup(); }
				};
				element.addEventListener('transitionend', transitionHandler);
				window.requestAnimationFrame(function () { element.classList.add('is-exiting'); });
				cleanupTimer = window.setTimeout(cleanup, fade + 150);
			}, remaining + hold);
		}

		function finishWhenReady() {
			if (!animationComplete || !pageReady) { return; }
			finish();
		}

		function markAnimationComplete() {
			animationComplete = true;
			finishWhenReady();
		}

		function schedulePageReady() {
			if (pageReadyScheduled || cleaned) { return; }
			pageReadyScheduled = true;
			paintFrameOne = window.requestAnimationFrame(function () {
				paintFrameOne = null;
				paintFrameTwo = window.requestAnimationFrame(function () {
					paintFrameTwo = null;
					pageReady = true;
					finishWhenReady();
				});
			});
		}

		function forceFinish() {
			animationComplete = true;
			pageReady = true;
			finish();
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
		if (loader.lockScroll) {
			document.documentElement.classList.add('ko-rive-scroll-locked');
			document.body.classList.add('ko-rive-scroll-locked');
		}
		loadHandler = schedulePageReady;
		if (document.readyState === 'complete') {
			schedulePageReady();
		} else {
			window.addEventListener('load', loadHandler, { once: true });
		}
		fallbackTimer = window.setTimeout(markAnimationComplete, Math.max(1000, parseInt(loader.maximumDuration, 10) || 5000));
		safetyTimer = window.setTimeout(forceFinish, Math.max(15000, (parseInt(loader.maximumDuration, 10) || 5000) + 10000));
		createRive(element, config, function (instance, riveApi) {
			if (loader.completionEvent && instance && typeof instance.on === 'function') {
				instance.on(riveApi.EventType.RiveEvent, function (event) {
					if (event && event.data && event.data.name === loader.completionEvent) { markAnimationComplete(); }
				});
			}
		}, markAnimationComplete);
	}

	function initializeElement(element, config) {
		if (!element || !config || element.dataset.koRiveInitialized === '1') {
			return;
		}
		element.dataset.koRiveInitialized = '1';
		prepareElement(element, config);
		if (config.role === 'loader') {
			if (window.KORiveLoaderBootstrap && typeof window.KORiveLoaderBootstrap.release === 'function') {
				window.requestAnimationFrame(function () { window.KORiveLoaderBootstrap.release(config.id); });
			}
			initializeLoader(element, config);
			return;
		}
		whenVisible(element, config, function () { initializeVisual(element, config); });
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
		if (mutationObserver) { mutationObserver.disconnect(); }
		instances.forEach(function (instance) { if (instance && typeof instance.cleanup === 'function') { instance.cleanup(); } });
		instances = [];
	}, { once: true });
}());
