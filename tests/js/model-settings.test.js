import assert from 'node:assert/strict';
import test from 'node:test';
import {replaceModelOptions} from '../../resources/js/model-settings.js';

globalThis.window = {};
globalThis.Option = class {
	constructor(label, value, defaultSelected = false, selected = false) {
		this.textContent = label;
		this.value = value;
		this.defaultSelected = defaultSelected;
		this.selected = selected;
	}
};

function select(value, options) {
	return {
		value,
		options,
		replaceChildren(...replacement) {
			this.options = replacement;
			this.value = replacement.find((option) => option.selected)?.value || '';
		},
	};
}

test('provider refresh keeps an existing custom model selected', () => {
	const input = select('custom-image-model', [
		new Option('Current/custom — custom-image-model', 'custom-image-model', true, true),
	]);

	replaceModelOptions(input, ['gpt-image-2.5-sunburst', 'gpt-image-2.5-sunburst']);

	assert.deepEqual(input.options.map((option) => option.value), [
		'gpt-image-2.5-sunburst',
		'custom-image-model',
	]);
	assert.equal(input.value, 'custom-image-model');
	assert.equal(input.options[1].textContent, 'Current/custom — custom-image-model');
});

test('provider refresh keeps the current selection and label when discovered', () => {
	const input = select('gpt-image-2', [
		new Option('GPT Image 2 — gpt-image-2', 'gpt-image-2', true, true),
	]);

	replaceModelOptions(input, ['gpt-image-2.5-sunburst', 'gpt-image-2']);

	assert.equal(input.value, 'gpt-image-2');
	assert.equal(input.options[1].textContent, 'GPT Image 2 — gpt-image-2');
});

test('empty refresh result does not erase the existing dropdown', () => {
	const input = select('gpt-image-2', [new Option('GPT Image 2', 'gpt-image-2', true, true)]);

	assert.throws(() => replaceModelOptions(input, []), /no image models/i);
	assert.equal(input.value, 'gpt-image-2');
	assert.equal(input.options.length, 1);
});
