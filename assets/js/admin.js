(function () {
	'use strict';

	document.querySelectorAll('.ko-rbm-copy').forEach(function (button) {
		button.addEventListener('click', function () {
			var original = button.textContent;
			navigator.clipboard.writeText(button.getAttribute('data-copy') || '').then(function () {
				button.textContent = 'Copied';
				window.setTimeout(function () { button.textContent = original; }, 1200);
			});
		});
	});

	document.querySelectorAll('.ko-rbm-delete-button').forEach(function (button) {
		button.addEventListener('click', function (event) {
			if (!window.confirm(button.getAttribute('data-confirm') || 'Remove this item?')) {
				event.preventDefault();
			}
		});
	});

	var role = document.getElementById('ko-rbm-role');
	function updateRolePanels() {
		document.querySelectorAll('[data-role-panel]').forEach(function (panel) {
			panel.hidden = !role || panel.getAttribute('data-role-panel') !== role.value;
		});
	}
	if (role) {
		role.addEventListener('change', updateRolePanels);
		updateRolePanels();
	}

	var idInput = document.getElementById('ko-rbm-id');
	var preview = document.querySelector('[data-ko-rive-id-preview]');
	if (idInput && preview) {
		idInput.addEventListener('input', function () {
			preview.textContent = idInput.value || 'yellow-navigation';
		});
	}

	var colorInput = document.getElementById('ko-rbm-color');
	var colorSwatch = document.querySelector('[data-ko-rive-color-swatch]');
	function updateColorSwatch() {
		var value = colorInput ? colorInput.value.trim() : '';
		var valid = /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(value);
		if (colorSwatch) {
			colorSwatch.style.setProperty('--ko-rbm-swatch', valid ? value : 'transparent');
		}
	}
	if (colorInput) {
		colorInput.addEventListener('input', updateColorSwatch);
		updateColorSwatch();
	}
}());
