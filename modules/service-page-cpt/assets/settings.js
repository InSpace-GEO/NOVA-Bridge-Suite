window.addEventListener('DOMContentLoaded', function () {
	const settings = window.serviceCptSettings || {};
	const pickers = Array.from(document.querySelectorAll('.service-cpt-color-picker'));
	const inputs = Array.from(document.querySelectorAll('.service-cpt-setting'));
	const colorPresetSelect = document.getElementById('service-cpt-color-preset');
	const spacingPresetSelect = document.getElementById('service-cpt-spacing-preset');

	if (!inputs.length) {
		return;
	}

	const pickerMap = new Map();
	pickers.forEach((picker) => {
		const targetId = picker.getAttribute('data-target');
		if (targetId) {
			pickerMap.set(targetId, picker);
		}
	});

	const getInputValue = (option) => {
		const input = document.querySelector(`.service-cpt-setting[data-option="${option}"]`);
		return input ? input.value.trim() : '';
	};

	const syncPicker = (input) => {
		const picker = pickerMap.get(input.id);
		if (!picker) {
			return;
		}
		const value = input.value.trim();
		if (/^#[0-9a-fA-F]{6}$/.test(value) || /^#[0-9a-fA-F]{3}$/.test(value)) {
			picker.value = value;
		}
	};

	const detectPreset = (presetMap) => {
		if (!presetMap) {
			return '';
		}
		const entries = Object.entries(presetMap);
		for (let i = 0; i < entries.length; i += 1) {
			const [key, preset] = entries[i];
			if (!preset || !preset.values) {
				continue;
			}
			const values = preset.values;
			const match = Object.keys(values).every((option) => {
				return getInputValue(option) === String(values[option] || '');
			});
			if (match) {
				return key;
			}
		}
		return '';
	};

	const updatePresetSelections = () => {
		if (colorPresetSelect) {
			colorPresetSelect.value = detectPreset(settings.colorPresets);
		}
		if (spacingPresetSelect) {
			spacingPresetSelect.value = detectPreset(settings.spacingPresets);
		}
	};

	const applyPresetValues = (preset) => {
		if (!preset || !preset.values) {
			return;
		}
		Object.keys(preset.values).forEach((option) => {
			const input = document.querySelector(`.service-cpt-setting[data-option="${option}"]`);
			if (!input) {
				return;
			}
			input.value = preset.values[option];
			syncPicker(input);
		});
	};

	pickers.forEach((picker) => {
		const targetId = picker.getAttribute('data-target');
		if (!targetId) {
			return;
		}
		const target = document.getElementById(targetId);
		if (!target) {
			return;
		}
		picker.addEventListener('input', function () {
			target.value = picker.value;
			updatePresetSelections();
		});
		target.addEventListener('input', function () {
			syncPicker(target);
			updatePresetSelections();
		});
	});

	inputs.forEach((input) => {
		input.addEventListener('input', function () {
			updatePresetSelections();
		});
	});

	if (colorPresetSelect && settings.colorPresets) {
		colorPresetSelect.addEventListener('change', function () {
			const preset = settings.colorPresets[colorPresetSelect.value] || null;
			applyPresetValues(preset);
			updatePresetSelections();
		});
	}

	if (spacingPresetSelect && settings.spacingPresets) {
		spacingPresetSelect.addEventListener('change', function () {
			const preset = settings.spacingPresets[spacingPresetSelect.value] || null;
			applyPresetValues(preset);
			updatePresetSelections();
		});
	}

	const archiveModeSelect = document.getElementById('service-cpt-archive-service-mode');
	const archiveManualWrap = document.getElementById('service-cpt-archive-service-manual');
	const updateArchiveManual = () => {
		if (!archiveModeSelect || !archiveManualWrap) {
			return;
		}
		archiveManualWrap.style.display = archiveModeSelect.value === 'manual' ? 'block' : 'none';
	};
	if (archiveModeSelect && archiveManualWrap) {
		archiveModeSelect.addEventListener('change', updateArchiveManual);
		updateArchiveManual();
	}

	if (archiveManualWrap) {
		const selectedList = archiveManualWrap.querySelector('[data-service-cpt-selected]');
		const availableList = archiveManualWrap.querySelector('[data-service-cpt-list="archive-services"]');
		const emptyItem = archiveManualWrap.querySelector('[data-service-cpt-empty]');
		const inputName = selectedList ? selectedList.getAttribute('data-input-name') : '';

		const setEmptyState = () => {
			if (!emptyItem || !selectedList) {
				return;
			}
			const items = selectedList.querySelectorAll('.service-cpt-selected-item');
			emptyItem.style.display = items.length ? 'none' : 'flex';
		};

		const updateAvailableState = (id, isSelected) => {
			if (!availableList) {
				return;
			}
			const item = availableList.querySelector(`[data-service-id="${id}"]`);
			if (!item) {
				return;
			}
			item.classList.toggle('is-selected', isSelected);
			const button = item.querySelector('.service-cpt-add-service');
			if (button) {
				button.disabled = isSelected;
				button.textContent = isSelected ? 'Added' : 'Add';
			}
		};

		const buildSelectedItem = (id, title) => {
			if (!selectedList || !inputName) {
				return null;
			}
			const li = document.createElement('li');
			li.className = 'service-cpt-selected-item';
			li.setAttribute('data-service-id', id);
			li.setAttribute('data-service-title', title);
			li.setAttribute('data-related-item', '');
			li.setAttribute('data-related-title', title.toLowerCase());

			const titleSpan = document.createElement('span');
			titleSpan.className = 'service-cpt-item-title';
			titleSpan.textContent = title;

			const actions = document.createElement('div');
			actions.className = 'service-cpt-selected-actions';

			const up = document.createElement('button');
			up.type = 'button';
			up.className = 'button button-small service-cpt-move-up';
			up.textContent = 'Up';

			const down = document.createElement('button');
			down.type = 'button';
			down.className = 'button button-small service-cpt-move-down';
			down.textContent = 'Down';

			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button button-small service-cpt-remove-service';
			remove.textContent = 'Remove';

			actions.appendChild(up);
			actions.appendChild(down);
			actions.appendChild(remove);

			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = inputName;
			input.value = id;

			li.appendChild(titleSpan);
			li.appendChild(actions);
			li.appendChild(input);
			return li;
		};

		const moveSelectedItem = (item, direction) => {
			if (!selectedList || !item) {
				return;
			}
			const items = Array.from(selectedList.querySelectorAll('.service-cpt-selected-item'));
			const index = items.indexOf(item);
			if (index === -1) {
				return;
			}
			if (direction === 'up' && index > 0) {
				selectedList.insertBefore(item, items[index - 1]);
			}
			if (direction === 'down' && index < items.length - 1) {
				const next = items[index + 1];
				if (next && next.nextSibling) {
					selectedList.insertBefore(item, next.nextSibling);
				} else {
					selectedList.appendChild(item);
				}
			}
		};

		archiveManualWrap.addEventListener('click', (event) => {
			const addButton = event.target.closest('.service-cpt-add-service');
			if (addButton && availableList && selectedList) {
				const item = addButton.closest('[data-service-id]');
				if (!item) {
					return;
				}
				const id = item.getAttribute('data-service-id');
				const title = item.getAttribute('data-service-title') || 'Service';
				if (!id || selectedList.querySelector(`[data-service-id="${id}"]`)) {
					return;
				}
				const selectedItem = buildSelectedItem(id, title);
				if (selectedItem) {
					selectedList.appendChild(selectedItem);
					updateAvailableState(id, true);
					setEmptyState();
				}
				return;
			}

			const removeButton = event.target.closest('.service-cpt-remove-service');
			if (removeButton && selectedList) {
				const item = removeButton.closest('.service-cpt-selected-item');
				if (!item) {
					return;
				}
				const id = item.getAttribute('data-service-id');
				item.remove();
				if (id) {
					updateAvailableState(id, false);
				}
				setEmptyState();
				return;
			}

			const moveUp = event.target.closest('.service-cpt-move-up');
			if (moveUp && selectedList) {
				const item = moveUp.closest('.service-cpt-selected-item');
				moveSelectedItem(item, 'up');
				return;
			}

			const moveDown = event.target.closest('.service-cpt-move-down');
			if (moveDown && selectedList) {
				const item = moveDown.closest('.service-cpt-selected-item');
				moveSelectedItem(item, 'down');
			}
		});

		setEmptyState();
	}

	const filterInputs = Array.from(document.querySelectorAll('[data-service-cpt-filter]'));
	filterInputs.forEach((input) => {
		const target = input.getAttribute('data-service-cpt-filter');
		if (!target) {
			return;
		}
		const list = document.querySelector(`[data-service-cpt-list="${target}"]`);
		if (!list) {
			return;
		}
		const items = Array.from(list.querySelectorAll('[data-related-item]'));
		const updateFilter = () => {
			const term = input.value.trim().toLowerCase();
			items.forEach((item) => {
				const title = (item.getAttribute('data-related-title') || '').toLowerCase();
				item.style.display = !term || title.includes(term) ? 'flex' : 'none';
			});
		};
		input.addEventListener('input', updateFilter);
	});

	updatePresetSelections();
});
