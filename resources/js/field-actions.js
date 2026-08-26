import {
	CONTEXT_SELECTOR,
	cacheBustUrl,
	extractRenderedElement,
	renderElementRequest,
	targetFromContext,
	translate,
} from './helpers.js';

let observer;
let scanTimer;

function fieldInput(context) {
	const field = context?.closest?.('.field');
	if (!field) {
		return null;
	}

	return [...field.querySelectorAll('.elementselect')]
		.find((candidate) => candidate.closest('.field') === field) || null;
}

function assetInput(container) {
	return container && window.jQuery
		? window.jQuery(container).data('elementSelect')
		: null;
}

function relationId(card) {
	const direct = card?.dataset?.assetId || card?.dataset?.id || '';
	if (/^\d+$/.test(String(direct))) {
		return String(direct);
	}

	const hidden = card?.querySelector?.('input[type="hidden"][value]');
	return /^\d+$/.test(hidden?.value || '') ? hidden.value : '';
}

function selectedRelationIds(container) {
	if (!container) {
		return [];
	}

	return [...container.querySelectorAll(
		':scope > .elements .element[data-id], '
		+ ':scope > .elements .element-card[data-id], '
		+ ':scope > .elements [data-asset-id]'
	)]
		.map(relationId)
		.filter((id, index, all) => id && all.indexOf(id) === index);
}

function fieldCapacity(input) {
	const selectedCount = Number(input?.$elements?.length || 0);
	const limit = Number(input?.settings?.limit || 0);
	const full = typeof input?.canAddMoreElements === 'function'
		? !input.canAddMoreElements()
		: Boolean(limit && selectedCount >= limit);

	return {full, limit};
}

function createButton(context, container, openModal) {
	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'btn dashed add icon craft-image-creator-field-button';
	button.textContent = translate('Create with AI');
	button.addEventListener('click', (event) => {
		event.preventDefault();
		event.stopPropagation();

		const input = assetInput(container);
		if (!input || (window.Craft?.AssetSelectInput && !(input instanceof Craft.AssetSelectInput))) {
			Craft.cp.displayError(translate('The Assets field is not ready yet.'));
			return;
		}
		if (input.settings?.allowAdd === false) {
			Craft.cp.displayError(translate('You are not allowed to add Assets to this field.'));
			return;
		}

		const target = targetFromContext(context);
		if (!target.elementId) {
			Craft.cp.displayError(translate('Save the element before creating an image.'));
			return;
		}

		const capacity = fieldCapacity(input);
		if (capacity.full && capacity.limit !== 1) {
			Craft.cp.displayError(translate('This Assets field has reached its relation limit.'));
			return;
		}
		if (capacity.full && capacity.limit === 1 && input.settings?.allowRemove === false) {
			Craft.cp.displayError(translate('You are not allowed to replace this Asset relation.'));
			return;
		}

		button.disabled = true;
		openModal({
			context,
			container,
			input,
			button,
			target,
			replaceAssetId: selectedRelationIds(container)[0] || '',
		});
	});

	return button;
}

function attachButton(context, openModal) {
	const container = fieldInput(context);
	if (!container) {
		return;
	}

	const controls = container.querySelector(':scope > .flex');
	if (!controls) {
		return;
	}

	const existingButton = controls.querySelector(':scope > .craft-image-creator-field-button');
	const input = assetInput(container);
	if (input?.settings?.allowAdd === false) {
		existingButton?.remove();
		return;
	}
	if (existingButton) {
		return;
	}

	const button = createButton(context, container, openModal);
	const spinner = controls.querySelector(':scope > .spinner');
	controls.insertBefore(button, spinner);
}

function scan(openModal) {
	document.querySelectorAll(CONTEXT_SELECTOR)
		.forEach((context) => attachButton(context, openModal));
}

function scheduleScan(openModal, delay = 40) {
	window.clearTimeout(scanTimer);
	scanTimer = window.setTimeout(() => scan(openModal), delay);
}

function bustRenderedThumbnail(input, asset) {
	const container = input?.$container?.get?.(0) || document;
	const card = container.querySelector(
		`.element[data-id="${asset.id}"], .element-card[data-id="${asset.id}"], [data-asset-id="${asset.id}"]`
	);
	if (!card) {
		return;
	}

	const timestamp = Date.now();
	card.querySelectorAll('img[src]').forEach((image) => {
		image.src = cacheBustUrl(image.src, timestamp);
		if (image.srcset) {
			image.srcset = image.srcset
				.split(',')
				.map((entry) => {
					const parts = entry.trim().split(/\s+/);
					parts[0] = cacheBustUrl(parts[0], timestamp);
					return parts.join(' ');
				})
				.join(', ');
		}
	});
}

export async function insertGeneratedAsset(modalContext, asset, config) {
	const input = modalContext.input;
	const capacity = fieldCapacity(input);

	if (capacity.limit === 1 && capacity.full) {
		const replaceId = modalContext.replaceAssetId
			|| selectedRelationIds(modalContext.container)[0]
			|| String(input?.$elements?.first?.().data?.('id') || '');
		if (!replaceId) {
			throw new Error(translate('The current Asset relation could not be identified.'));
		}
		await Promise.resolve(input.replaceElement(replaceId, asset.id));
	} else {
		if (capacity.full) {
			throw new Error(translate('This Assets field has reached its relation limit.'));
		}

		const request = renderElementRequest(
			config.craftMajorVersion,
			input,
			asset.id,
			input?.settings?.criteria?.siteId || modalContext.target.siteId
		);
		input.showSpinner?.();
		try {
			const response = await Craft.sendActionRequest('POST', request.action, {
				data: request.data,
				headers: {Accept: 'application/json'},
			});
			const html = extractRenderedElement(
				config.craftMajorVersion,
				response.data,
				asset.id
			);
			if (!html) {
				throw new Error(translate('Craft could not render the generated Asset.'));
			}

			const info = Craft.getElementInfo(html);
			if (Number(config.craftMajorVersion) >= 5) {
				// Craft 5's own AssetSelectInput inserts the element before it
				// evaluates the response's registered head/body HTML.
				await Promise.resolve(input.selectElements([info]));
				await Craft.appendHeadHtml(response.data.headHtml || '');
				await Craft.appendBodyHtml(response.data.bodyHtml || '');
			} else {
				// Craft 4's upload flow registers head HTML first.
				if (response.data.headHtml) {
					await Promise.resolve(Craft.appendHeadHtml(response.data.headHtml));
				}
				await Promise.resolve(input.selectElements([info]));
			}
		} finally {
			input.hideSpinner?.();
		}
	}

	bustRenderedThumbnail(input, asset);
	// selectElements()/replaceElement() both emit the Assets input's bubbling
	// change event in Craft 4 and 5. Avoid emitting a duplicate event here,
	// which can result in redundant draft saves.
}

export function installFieldActions(openModal) {
	const init = () => {
		if (!window.Craft || !document.body) {
			return;
		}

		scan(openModal);
		// Some Craft field inputs initialize just after DOM ready. These bounded
		// rescans also cover tabs whose markup was already present but inactive.
		[100, 300, 800].forEach((delay) => {
			window.setTimeout(() => scan(openModal), delay);
		});
		observer = new MutationObserver(() => scheduleScan(openModal));
		observer.observe(document.body, {childList: true, subtree: true});
		document.addEventListener('click', () => scheduleScan(openModal), true);
		window.addEventListener('hashchange', () => scheduleScan(openModal));
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, {once: true});
	} else {
		init();
	}
}
