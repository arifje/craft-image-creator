import {ImageCreatorModal} from './modal.js';
import {standaloneTarget} from './helpers.js';

export function installStandaloneCreator(config) {
	const container = document.getElementById('craft-image-creator-standalone');
	const button = document.getElementById('craft-image-creator-open');
	if (!container || !button) {
		return;
	}

	button.addEventListener('click', () => {
		button.disabled = true;
		try {
			new ImageCreatorModal({
				button,
				context: container,
				target: standaloneTarget(),
				standalone: true,
			}, config, async (_context, asset) => {
				const saved = container.querySelector('.craft-image-creator-standalone__saved');
				const link = saved?.querySelector('a');
				if (!saved || !link) {
					return;
				}

				link.textContent = asset.title || asset.filename || Craft.t(
					'craft-image-creator',
					'Open Asset'
				);
				const url = asset.cpEditUrl || asset.url || '';
				if (url) {
					link.href = url;
				} else {
					link.removeAttribute('href');
				}
				saved.hidden = false;
			});
		} catch (error) {
			button.disabled = false;
			Craft.cp.displayError(error?.message || 'Image Creator could not be opened.');
		}
	});
}
