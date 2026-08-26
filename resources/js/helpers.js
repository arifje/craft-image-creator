export const CONTEXT_SELECTOR = '.craft-image-creator-context';

export function translate(message) {
	return window.Craft?.t
		? window.Craft.t('craft-image-creator', message)
		: message;
}

export function cacheBustUrl(url, timestamp = Date.now()) {
	if (!url || url.startsWith('data:') || url.startsWith('blob:')) {
		return url;
	}

	return `${url}${url.includes('?') ? '&' : '?'}imageCreator=${timestamp}`;
}

export function renderElementRequest(craftMajorVersion, input, assetId, siteId) {
	if (Number(craftMajorVersion) >= 5) {
		const viewMode = input?.settings?.viewMode || 'list';

		return {
			action: 'app/render-elements',
			data: {
				elements: [{
					type: input?.settings?.elementType || 'craft\\elements\\Asset',
					id: Number(assetId),
					siteId: Number(siteId),
					instances: [{
						context: 'field',
						ui: ['list', 'list-inline', 'thumbs', 'large'].includes(viewMode)
							? 'chip'
							: 'card',
						size: ['thumbs', 'large'].includes(viewMode) ? 'large' : 'small',
						showActionMenu: input?.settings?.showActionMenu !== false,
					}],
				}],
			},
		};
	}

	return {
		action: 'elements/get-element-html',
		data: {
			elementId: Number(assetId),
			siteId: Number(siteId),
			thumbSize: input?.settings?.viewMode || 'list',
		},
	};
}

export function extractRenderedElement(craftMajorVersion, responseData, assetId) {
	if (Number(craftMajorVersion) >= 5) {
		return responseData?.elements?.[assetId]?.[0] || '';
	}

	return responseData?.html || '';
}

export function matchesFieldName(name, handle) {
	if (!name || !handle) {
		return false;
	}

	return name === `fields[${handle}]`
		|| name.startsWith(`fields[${handle}][`)
		|| name.includes(`[fields][${handle}]`);
}

export function matchesNativeFieldName(name, handle) {
	if (!name || !handle) {
		return false;
	}

	// Top-level native fields use `title`/`slug`. Craft 5 Matrix entries use a
	// namespace such as `fields[content][entries][uid:…][title]`.
	return name === handle || name.endsWith(`[${handle}]`);
}

function visible(element) {
	return element instanceof HTMLElement
		&& element.type !== 'hidden'
		&& !element.hidden
		&& element.getAttribute('aria-hidden') !== 'true';
}

function normalizeText(value) {
	const text = String(value ?? '');
	if (!text.includes('<')) {
		return text.trim();
	}

	const holder = document.createElement('div');
	holder.innerHTML = text;
	return (holder.textContent || '').trim();
}

function controlValue(control) {
	if (control.matches('input[type="radio"]')) {
		return control.checked ? control.value : '';
	}

	if (control.matches('input[type="checkbox"]')) {
		if ((control.name || '').endsWith('[]')) {
			return control.checked ? control.value : '';
		}

		return control.checked
			? (!control.value || control.value === '1' ? 'Yes' : control.value)
			: 'No';
	}

	if (control instanceof HTMLSelectElement) {
		return [...control.selectedOptions]
			.map((option) => option.textContent?.trim() || option.value)
			.filter(Boolean)
			.join(', ');
	}

	if (control.isContentEditable) {
		return (control.innerText || control.textContent || '').trim();
	}

	if (control.matches('input[type="hidden"]')) {
		const elementSelect = control.closest('.elementselect');
		const labels = elementSelect
			? [...elementSelect.querySelectorAll(
				'.element .label, .element .title, .element-card .label, .element-card .title'
			)]
				.map((label) => label.textContent?.trim() || '')
				.filter((value, index, all) => value && all.indexOf(value) === index)
			: [];
		if (labels.length) {
			return labels.join(', ');
		}
	}

	return normalizeText(control.value ?? control.textContent ?? '');
}

function matrixScopes(context) {
	const scopes = [];
	let scope = context.closest('.matrixblock');
	while (scope) {
		scopes.push(scope);
		scope = scope.parentElement?.closest('.matrixblock') || null;
	}

	return scopes;
}

function belongsToScope(control, scope) {
	const owner = control.closest('.matrixblock');
	return scope.matches?.('.matrixblock') ? owner === scope : owner === null;
}

function contextControls(scope, definition) {
	return [...scope.querySelectorAll(
		'input[name], textarea[name], select[name], [contenteditable="true"]'
	)].filter((control) => {
		if (!belongsToScope(control, scope)) {
			return false;
		}

		const name = control.getAttribute('name') || '';
		return definition.native
			? matchesNativeFieldName(name, definition.handle)
			: matchesFieldName(name, definition.handle);
	});
}

function bestControls(context, definition) {
	const form = context.closest('form') || document;
	const scopes = [...matrixScopes(context), form];
	for (const scope of scopes) {
		const candidates = contextControls(scope, definition);
		if (!candidates.length) {
			continue;
		}
		const visibleControls = candidates.filter(visible);
		return visibleControls.length ? visibleControls : candidates;
	}

	return [];
}

export function readContextFields(context, definitions = []) {
	return definitions.map((definition) => {
		const controls = bestControls(context, definition);
		const values = controls
			.map(controlValue)
			.filter((value, index, all) => value !== '' && all.indexOf(value) === index);

		return {
			key: String(definition.key || definition.handle || ''),
			label: String(definition.label || definition.handle || ''),
			value: values.join('\n'),
		};
	});
}

export function blankContextFields(definitions = []) {
	return definitions.map((definition) => ({
		key: String(definition.key || definition.handle || ''),
		label: String(definition.label || definition.handle || ''),
		value: '',
	}));
}

export function responsePayload(response) {
	const payload = response?.data ?? response ?? {};
	if (payload.success === false) {
		throw new Error(payload.message || payload.error || 'The request failed.');
	}

	return payload;
}

export function standaloneFolderTarget(value) {
	const folderId = Number(value);
	if (!Number.isSafeInteger(folderId) || folderId < 1) {
		return null;
	}

	return {type: 'folder', folderId};
}

export function generationPollDelay(attempt) {
	const delays = [500, 750, 1000, 1500, 2000, 3000];
	const index = Math.max(0, Math.min(Number(attempt) || 0, delays.length - 1));

	return delays[index];
}

export function generationState(payload, expectedToken) {
	const generation = payload?.generation;
	const statuses = ['queued', 'running', 'complete', 'failed', 'cancelled'];
	if (
		!generation
		|| generation.token !== expectedToken
		|| !statuses.includes(generation.status)
	) {
		throw new Error('The server returned an invalid image generation status.');
	}

	return {
		status: String(generation.status),
		error: String(generation.error || ''),
		result: payload?.result || null,
	};
}

export function requestErrorMessage(error, fallback) {
	const data = error?.response?.data;
	const errors = data?.errors;
	if (errors && typeof errors === 'object') {
		const first = Object.values(errors).flat().find(Boolean);
		if (first) {
			return String(first);
		}
	}

	return String(data?.message || data?.error || error?.message || fallback);
}

export function targetFromContext(context) {
	return {
		fieldUid: context.dataset.fieldUid || '',
		elementId: Number(context.dataset.elementId || 0),
		siteId: Number(context.dataset.siteId || 0),
		elementType: context.dataset.elementType || '',
	};
}
