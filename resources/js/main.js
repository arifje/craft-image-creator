import '../css/image-creator.css';
import {installFieldActions, insertGeneratedAsset} from './field-actions.js';
import {ImageCreatorModal} from './modal.js';
import {installStandaloneCreator} from './standalone.js';

const config = window.CraftImageCreatorConfig || {
	craftMajorVersion: 4,
	providers: [],
	contextFields: [],
	ratios: [],
	routes: {},
};

function openModal(context) {
	try {
		new ImageCreatorModal(
			context,
			config,
			(modalContext, asset) => insertGeneratedAsset(modalContext, asset, config)
		);
	} catch (error) {
		context.button.disabled = false;
		Craft.cp.displayError(error?.message || 'Image Creator could not be opened.');
	}
}

installFieldActions(openModal);
installStandaloneCreator(config);
