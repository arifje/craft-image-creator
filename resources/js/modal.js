import {
	readContextFields,
	requestErrorMessage,
	responsePayload,
	translate,
} from './helpers.js';

let modalCount = 0;

function element(tag, options = {}, children = []) {
	const node = document.createElement(tag);
	Object.entries(options).forEach(([key, value]) => {
		if (key === 'className') {
			node.className = value;
		} else if (key === 'text') {
			node.textContent = value;
		} else if (key === 'htmlFor') {
			node.htmlFor = value;
		} else if (value !== undefined && value !== null) {
			node.setAttribute(key, String(value));
		}
	});
	children.filter(Boolean).forEach((child) => node.appendChild(child));
	return node;
}

function actionRequest(url, data) {
	return Craft.sendActionRequest('POST', url, {
		data,
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
	}).then(responsePayload);
}

function configuredProvider(config) {
	const providers = Array.isArray(config.providers) ? config.providers : [];
	return providers.find((provider) => provider.value === config.defaultProvider)?.value
		|| providers[0]?.value
		|| '';
}

export class ImageCreatorModal {
	constructor(context, config, onAssetReady) {
		this.context = context;
		this.config = config;
		this.onAssetReady = onAssetReady;
		this.id = `craft-image-creator-${++modalCount}`;
		this.initialContext = readContextFields(
			context.context,
			Array.isArray(config.contextFields) ? config.contextFields : []
		);
		this.result = null;
		this.savedAsset = null;
		this.busy = '';
		this.destroyed = false;
		this.build();
		this.show();
	}

	build() {
		this.form = element('form', {
			className: 'modal craft-image-creator-modal',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': `${this.id}-title`,
		});
		this.form.addEventListener('submit', (event) => {
			event.preventDefault();
			this.generate();
		});

		const closeButton = element('button', {
			type: 'button',
			className: 'btn craft-image-creator-modal__close',
			'aria-label': translate('Close'),
			'data-icon': 'remove',
		});
		closeButton.addEventListener('click', () => this.close());
		this.closeButton = closeButton;

		const header = element('header', {className: 'craft-image-creator-modal__header'}, [
			element('div', {}, [
				element('h1', {id: `${this.id}-title`, text: translate('Create with AI')}),
				element('p', {
					text: translate('Create an image from the configured prompt and this element’s context.'),
				}),
			]),
			closeButton,
		]);

		this.body = element('div', {className: 'craft-image-creator-modal__body'});
		this.controls = element('section', {className: 'craft-image-creator-modal__controls'});
		this.preview = this.buildPreview();
		this.buildControls();
		this.body.append(this.controls, this.preview);

		this.error = element('div', {
			className: 'error craft-image-creator-modal__error',
			role: 'alert',
			'aria-live': 'assertive',
		});
		this.error.hidden = true;

		this.status = element('span', {
			className: 'craft-image-creator-modal__status',
			'aria-live': 'polite',
		});

		this.generateButton = element('button', {
			type: 'submit',
			className: 'btn submit',
			text: translate('Generate image'),
		});
		this.addButton = element('button', {
			type: 'button',
			className: 'btn submit',
			text: translate('Add to field'),
		});
		this.addButton.disabled = true;
		this.addButton.addEventListener('click', () => this.addToField());

		this.resetButton = element('button', {
			type: 'button',
			className: 'btn',
			text: translate('Reset'),
		});
		this.resetButton.addEventListener('click', () => this.reset());

		this.footerCloseButton = element('button', {
			type: 'button',
			className: 'btn',
			text: translate('Close'),
		});
		this.footerCloseButton.addEventListener('click', () => this.close());

		const footer = element('footer', {className: 'craft-image-creator-modal__footer'}, [
			element('div', {className: 'buttons'}, [
				this.generateButton,
				this.addButton,
				this.resetButton,
				this.footerCloseButton,
			]),
			this.status,
		]);

		this.form.append(header, this.body, this.error, footer);
		this.refreshControls();
	}

	buildControls() {
		const providers = Array.isArray(this.config.providers) ? this.config.providers : [];
		const providerId = `${this.id}-provider`;
		this.providerSelect = element('select', {id: providerId});
		providers.forEach((provider) => {
			const option = element('option', {value: provider.value, text: provider.label});
			this.providerSelect.appendChild(option);
		});
		this.providerSelect.value = configuredProvider(this.config);

		const providerField = this.field(
			translate('Provider'),
			providerId,
			element('div', {className: 'select fullwidth'}, [this.providerSelect])
		);

		this.ratioInputs = [];
		const ratioGroup = element('div', {
			className: 'craft-image-creator-modal__ratios',
			role: 'radiogroup',
			'aria-label': translate('Image ratio'),
		});
		(Array.isArray(this.config.ratios) ? this.config.ratios : []).forEach((ratio, index) => {
			const ratioId = `${this.id}-ratio-${index}`;
			const input = element('input', {
				id: ratioId,
				type: 'radio',
				name: `${this.id}-ratio`,
				value: ratio.value,
			});
			input.checked = index === 0;
			this.ratioInputs.push(input);
			ratioGroup.appendChild(element('label', {className: 'craft-image-creator-modal__ratio'}, [
				input,
				element('span', {text: ratio.label}),
			]));
		});

		const selectorGrid = element('div', {className: 'craft-image-creator-modal__selectors'}, [
			providerField,
			this.field(translate('Image ratio'), '', ratioGroup),
		]);
		this.controls.appendChild(selectorGrid);

		if (!providers.length) {
			const warning = element('p', {
				className: 'warning with-icon',
				text: translate('No AI image provider is configured.'),
			});
			this.controls.appendChild(warning);
		}

		this.contextInputs = new Map();
		const contextSection = element('div', {className: 'craft-image-creator-modal__context'});
		this.initialContext.forEach((item, index) => {
			const inputId = `${this.id}-context-${index}`;
			const input = element('textarea', {
				id: inputId,
				className: 'text fullwidth',
				rows: '2',
				maxlength: '10000',
			});
			input.value = item.value;
			this.contextInputs.set(item.key, {input, item});
			contextSection.appendChild(this.field(item.label, inputId, input));
		});

		const extraId = `${this.id}-extra`;
		this.extraInput = element('textarea', {
			id: extraId,
			className: 'text fullwidth',
			rows: '3',
			maxlength: '20000',
			placeholder: translate('Optional details, visual direction, or constraints for this image.'),
		});
		contextSection.appendChild(this.field(
			translate('Extra context'),
			extraId,
			this.extraInput,
			translate('Add details for this image only. Configured context fields may be left empty.')
		));
		this.controls.appendChild(contextSection);

		const filenameId = `${this.id}-filename`;
		this.filenameInput = element('input', {
			id: filenameId,
			type: 'text',
			className: 'text fullwidth',
			maxlength: '255',
			placeholder: translate('Generated automatically'),
		});
		this.filenameField = this.field(translate('Filename'), filenameId, this.filenameInput);
		this.filenameField.classList.add('craft-image-creator-modal__filename');
		this.filenameField.hidden = true;
		this.controls.appendChild(this.filenameField);
	}

	buildPreview() {
		this.previewImage = element('img', {
			alt: translate('Generated image'),
		});
		this.previewImage.hidden = true;
		this.previewImage.addEventListener('error', () => {
			if (this.result && !this.destroyed) {
				this.setError(translate('The generated preview could not be loaded. Generate the image again.'));
			}
		});
		this.previewPlaceholder = element('div', {
			className: 'craft-image-creator-modal__placeholder',
		}, [
			element('span', {className: 'spinner big'}),
			element('strong', {text: translate('Your generated image will appear here.')}),
		]);
		this.previewPlaceholder.querySelector('.spinner').hidden = true;

		return element('aside', {className: 'craft-image-creator-modal__preview'}, [
			this.previewImage,
			this.previewPlaceholder,
		]);
	}

	field(label, inputId, control, instructions = '') {
		const labelOptions = {text: label};
		if (inputId) {
			labelOptions.htmlFor = inputId;
		}
		const children = [
			element('div', {className: 'heading'}, [element('label', labelOptions)]),
		];
		if (instructions) {
			children.push(element('p', {className: 'instructions', text: instructions}));
		}
		children.push(element('div', {className: 'input'}, [control]));

		return element('div', {className: 'field'}, children);
	}

	show() {
		if (window.Garnish && window.jQuery && Garnish.$bod) {
			this.$form = window.jQuery(this.form).appendTo(Garnish.$bod);
			this.garnishModal = new Garnish.Modal(this.$form, {
				hideOnEsc: false,
				hideOnShadeClick: false,
				triggerElement: this.context.button,
				onFadeOut: () => this.destroy(),
			});
		} else {
			this.backdrop = element('div', {className: 'craft-image-creator-modal__backdrop'});
			document.body.append(this.backdrop, this.form);
			this.form.classList.add('showing', 'craft-image-creator-modal--fallback');
		}

		window.setTimeout(() => this.providerSelect?.focus(), 50);
	}

	selectedRatio() {
		return this.ratioInputs.find((input) => input.checked)?.value || '1:1';
	}

	contextValues() {
		return [...this.contextInputs.values()].map(({input, item}) => ({
			key: item.key,
			label: item.label,
			value: input.value,
		}));
	}

	async generate() {
		if (this.busy || !this.providerSelect.value) {
			return;
		}

		this.setError('');
		this.setBusy('generating');
		try {
			const payload = await actionRequest(this.config.routes.generate, {
				provider: this.providerSelect.value,
				ratio: this.selectedRatio(),
				context: this.contextValues(),
				extraContext: this.extraInput.value,
				target: this.context.target,
			});
			const result = payload.result ?? payload;
			if (!result.token || !result.previewUrl) {
				throw new Error(translate('The server did not return a generated image.'));
			}
			if (this.destroyed) {
				this.discardToken(result.token);
				return;
			}

			const oldToken = this.result?.token;
			this.result = result;
			this.savedAsset = null;
			this.previewImage.src = result.previewUrl;
			this.previewImage.hidden = false;
			this.previewPlaceholder.hidden = true;
			this.filenameField.hidden = false;
			if (oldToken && oldToken !== result.token) {
				this.discardToken(oldToken);
			}
		} catch (error) {
			if (!this.destroyed) {
				this.setError(requestErrorMessage(error, translate('The image could not be generated.')));
			}
		} finally {
			if (!this.destroyed) {
				this.setBusy('');
			}
		}
	}

	async addToField() {
		if (this.busy || (!this.result && !this.savedAsset)) {
			return;
		}

		this.setError('');
		this.setBusy('saving');
		try {
			if (!this.savedAsset) {
				const payload = await actionRequest(this.config.routes.save, {
					token: this.result.token,
					filename: this.filenameInput.value,
					target: this.context.target,
				});
				this.savedAsset = payload.asset;
				if (!this.savedAsset?.id) {
					throw new Error(translate('The server did not return the saved Asset.'));
				}
				// A successfully saved result token is consumed server-side.
				this.result = null;
			}

			await this.onAssetReady(this.context, this.savedAsset);
			Craft.cp.displayNotice(translate('Image created and added to the field.'));
			this.close(true);
		} catch (error) {
			const fallback = this.savedAsset
				? translate('The Asset was saved, but it could not be added to this field. Try Add to field again.')
				: translate('The image could not be saved.');
			this.setError(requestErrorMessage(error, fallback));
		} finally {
			if (!this.destroyed) {
				this.setBusy('');
			}
		}
	}

	reset() {
		if (this.busy) {
			return;
		}

		if (this.result?.token) {
			this.discardToken(this.result.token);
		}
		this.result = null;
		this.savedAsset = null;
		this.previewImage.removeAttribute('src');
		this.previewImage.hidden = true;
		this.previewPlaceholder.hidden = false;
		this.previewPlaceholder.querySelector('.spinner').hidden = true;
		this.previewPlaceholder.querySelector('strong').textContent = translate(
			'Your generated image will appear here.'
		);
		this.initialContext.forEach((item) => {
			const context = this.contextInputs.get(item.key);
			if (context) {
				context.input.value = item.value;
			}
		});
		this.extraInput.value = '';
		this.filenameInput.value = '';
		this.filenameField.hidden = true;
		this.providerSelect.value = configuredProvider(this.config);
		this.ratioInputs.forEach((input, index) => {
			input.checked = index === 0;
		});
		this.setError('');
		this.refreshControls();
	}

	setBusy(stage) {
		this.busy = stage;
		this.form.setAttribute('aria-busy', stage ? 'true' : 'false');
		this.form.classList.toggle('craft-image-creator-modal--busy', Boolean(stage));
		this.generateButton.classList.toggle('loading', stage === 'generating');
		this.addButton.classList.toggle('loading', stage === 'saving');
		const spinner = this.previewPlaceholder.querySelector('.spinner');
		if (stage === 'generating') {
			this.previewPlaceholder.hidden = false;
			spinner.hidden = false;
			this.previewPlaceholder.querySelector('strong').textContent = translate('Generating image…');
		} else {
			spinner.hidden = true;
			this.previewPlaceholder.querySelector('strong').textContent = translate(
				'Your generated image will appear here.'
			);
			this.previewPlaceholder.hidden = Boolean(this.result || this.savedAsset);
			this.previewImage.hidden = !(this.result || this.savedAsset);
		}
		this.status.textContent = stage === 'generating'
			? translate('Generating image…')
			: stage === 'saving'
				? translate('Saving Asset…')
				: '';
		this.refreshControls();
	}

	refreshControls() {
		const disabled = Boolean(this.busy);
		this.providerSelect.disabled = disabled;
		this.ratioInputs.forEach((input) => {
			input.disabled = disabled;
		});
		this.contextInputs.forEach(({input}) => {
			input.disabled = disabled;
		});
		this.extraInput.disabled = disabled;
		this.filenameInput.disabled = disabled;
		this.generateButton.disabled = disabled || !this.providerSelect.value;
		this.addButton.disabled = disabled || (!this.result && !this.savedAsset);
		this.resetButton.disabled = disabled;
		this.closeButton.disabled = disabled;
		this.footerCloseButton.disabled = disabled;
	}

	setError(message) {
		this.error.textContent = message;
		this.error.hidden = !message;
	}

	discardToken(token) {
		if (!token || !this.config.routes.discard) {
			return Promise.resolve();
		}

		return actionRequest(this.config.routes.discard, {token}).catch(() => undefined);
	}

	close(assetAdded = false) {
		if (this.busy && !assetAdded) {
			return;
		}
		// Garnish restores focus synchronously during hide() in Craft 4, so the
		// trigger must be enabled before the modal starts closing.
		this.context.button.disabled = false;

		if (this.garnishModal) {
			this.garnishModal.hide();
		} else {
			this.destroy();
		}
	}

	destroy() {
		if (this.destroyed) {
			return;
		}
		this.destroyed = true;
		if (this.result?.token) {
			this.discardToken(this.result.token);
		}
		this.result = null;
		this.backdrop?.remove();
		const garnishModal = this.garnishModal;
		this.garnishModal = null;
		if (garnishModal) {
			// Removes Garnish's shade, listeners, data reference, and (in Craft 5)
			// modal instance registration in addition to the form element.
			garnishModal.destroy();
		} else {
			this.form.remove();
		}
		this.context.button.disabled = false;
	}
}
