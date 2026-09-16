import {translate} from './helpers.js';

const MODELS_ACTION = 'craft-image-creator/model-catalog/models';

function settingsInput(scope, name, tagName) {
	return scope.querySelector(`${tagName}[name="settings[${name}]"]`);
}

export function replaceModelOptions(select, modelIds) {
	const selectedValue = select.value;
	const existingLabels = new Map(
		[...select.options].map((option) => [option.value, option.textContent || option.value])
	);
	const ids = [...new Set(modelIds.filter(
		(value) => typeof value === 'string' && value.trim() !== ''
	))];

	if (!ids.length) {
		throw new Error(translate('The provider returned no image models.'));
	}

	if (selectedValue && !ids.includes(selectedValue)) {
		ids.push(selectedValue);
	}

	const options = ids.map((id) => {
		const label = existingLabels.get(id)
			|| (id === selectedValue ? `${translate('Current/custom')} — ${id}` : id);
		return new Option(label, id, false, id === selectedValue);
	});
	if (!selectedValue) {
		options.unshift(new Option('', '', true, true));
	}

	select.replaceChildren(...options);
}

function installModelControl(container) {
	const form = container.closest('form') || document;
	const provider = container.dataset.modelProvider;
	const modelField = container.dataset.modelField;
	const apiKeyField = container.dataset.apiKeyField;
	const select = settingsInput(form, modelField, 'select');
	const keyInput = settingsInput(form, apiKeyField, 'input');
	const button = container.querySelector('.craft-image-creator-model-refresh__button');
	const status = container.querySelector('.craft-image-creator-model-refresh__status');
	if (!select || !keyInput || !button || !status || !provider) {
		return;
	}

	const savedKeySetting = keyInput.value;
	let loading = false;
	const keyChanged = () => keyInput.value !== savedKeySetting;
	const setStatus = (message, error = false) => {
		status.textContent = message;
		status.classList.toggle('error', error);
	};
	const updateButton = () => {
		button.disabled = loading || select.disabled || keyChanged() || !savedKeySetting.trim();
		button.classList.toggle('loading', loading);
	};
	const updateKeyStatus = () => {
		if (keyChanged()) {
			setStatus(translate('Save API key changes before refreshing models.'));
		} else if (!savedKeySetting.trim()) {
			setStatus(translate('Save an API key before refreshing models.'));
		} else if (!loading) {
			setStatus('');
		}
		updateButton();
	};
	keyInput.addEventListener('input', updateKeyStatus);
	keyInput.addEventListener('change', updateKeyStatus);

	const loadModels = async (refresh) => {
		if (loading || keyChanged() || !savedKeySetting.trim()) {
			updateKeyStatus();
			return;
		}

		loading = true;
		updateButton();
		setStatus(translate(refresh ? 'Refreshing models…' : 'Loading models…'));
		try {
			const response = await Craft.sendActionRequest('POST', MODELS_ACTION, {
				data: {provider, refresh},
				headers: {Accept: 'application/json'},
			});
			if (!Array.isArray(response?.data?.models)) {
				throw new Error(translate('The provider returned an invalid model list.'));
			}
			if (keyChanged()) {
				setStatus(translate('Save API key changes before refreshing models.'));
				return;
			}
			replaceModelOptions(select, response.data.models);
			setStatus(translate(
				refresh ? 'Models refreshed. Choose a model and save settings.' : 'Model list loaded.'
			));
		} catch (error) {
			const serverMessage = error?.response?.data?.message;
			setStatus(
				typeof serverMessage === 'string' && serverMessage
					? serverMessage
					: translate('Could not load image models. Try refreshing again.'),
				true
			);
		} finally {
			loading = false;
			updateButton();
		}
	};

	button.addEventListener('click', () => loadModels(true));
	updateKeyStatus();
	if (savedKeySetting.trim() && !select.disabled) {
		loadModels(false);
	}
}

export function installModelSettings() {
	const start = () => {
		if (!window.Craft?.sendActionRequest) {
			return;
		}
		document.querySelectorAll('.craft-image-creator-model-refresh[data-model-provider]')
			.forEach(installModelControl);
	};

	// Craft mounts autosuggest inputs in its jQuery-ready scripts. The window load
	// event runs after those scripts, so the named API-key inputs exist here.
	if (document.readyState !== 'complete') {
		window.addEventListener('load', start, {once: true});
	} else {
		start();
	}
}
