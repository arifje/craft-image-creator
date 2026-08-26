import assert from 'node:assert/strict';
import test from 'node:test';

import {
	blankContextFields,
	cacheBustUrl,
	extractRenderedElement,
	generationPollDelay,
	generationState,
	matchesFieldName,
	matchesNativeFieldName,
	renderElementRequest,
	responsePayload,
	standaloneFolderTarget,
} from '../../resources/js/helpers.js';

test('cacheBustUrl keeps local data URLs untouched', () => {
	assert.equal(cacheBustUrl('data:image/png;base64,abc', 42), 'data:image/png;base64,abc');
	assert.equal(cacheBustUrl('/asset.jpg', 42), '/asset.jpg?imageCreator=42');
	assert.equal(cacheBustUrl('/asset.jpg?w=100', 42), '/asset.jpg?w=100&imageCreator=42');
});

test('generationPollDelay backs off and remains capped', () => {
	assert.equal(generationPollDelay(0), 500);
	assert.equal(generationPollDelay(3), 1500);
	assert.equal(generationPollDelay(99), 3000);
});

test('generationState validates the user-bound operation token', () => {
	assert.deepEqual(generationState({
		generation: {token: 'abc', status: 'running'},
	}, 'abc'), {
		status: 'running',
		error: '',
		result: null,
	});
	assert.throws(
		() => generationState({generation: {token: 'other', status: 'complete'}}, 'abc'),
		/invalid image generation status/
	);
	assert.throws(
		() => generationState({generation: {token: 'abc', status: 'unknown'}}, 'abc'),
		/invalid image generation status/
	);
});

test('standaloneFolderTarget only creates canonical client folder requests', () => {
	assert.deepEqual(standaloneFolderTarget('42'), {type: 'folder', folderId: 42});
	assert.equal(standaloneFolderTarget(''), null);
	assert.equal(standaloneFolderTarget('-1'), null);
	assert.equal(standaloneFolderTarget('1.5'), null);
});

test('blankContextFields gives standalone creation empty editable context', () => {
	assert.deepEqual(blankContextFields([
		{key: 'title', label: 'Title'},
		{handle: 'summary'},
	]), [
		{key: 'title', label: 'Title', value: ''},
		{key: 'summary', label: 'summary', value: ''},
	]);
});

test('matchesFieldName handles top-level and nested Craft field namespaces', () => {
	assert.equal(matchesFieldName('fields[summary]', 'summary'), true);
	assert.equal(matchesFieldName('fields[summary][0]', 'summary'), true);
	assert.equal(
		matchesFieldName('fields[content][blocks][new1][fields][summary]', 'summary'),
		true
	);
	assert.equal(
		matchesFieldName(
			'fields[content][entries][uid:9e195692-e01f-4d61-8c51-28e66fbcf477][fields][summary]',
			'summary'
		),
		true
	);
	assert.equal(matchesFieldName('fields[other]', 'summary'), false);
});

test('matchesNativeFieldName handles Craft 5 namespaced Matrix titles', () => {
	assert.equal(matchesNativeFieldName('title', 'title'), true);
	assert.equal(
		matchesNativeFieldName(
			'fields[content][entries][uid:9e195692-e01f-4d61-8c51-28e66fbcf477][title]',
			'title'
		),
		true
	);
	assert.equal(matchesNativeFieldName('fields[content][titleText]', 'title'), false);
});

test('renderElementRequest supports Craft 4 and Craft 5', () => {
	const input = {settings: {viewMode: 'thumbs', showActionMenu: true}};
	assert.deepEqual(renderElementRequest(4, input, 12, 2), {
		action: 'elements/get-element-html',
		data: {elementId: 12, siteId: 2, thumbSize: 'thumbs'},
	});

	const craft5 = renderElementRequest(5, input, 12, 2);
	assert.equal(craft5.action, 'app/render-elements');
	assert.equal(craft5.data.elements[0].instances[0].ui, 'chip');
	assert.equal(craft5.data.elements[0].instances[0].size, 'large');
});

test('extractRenderedElement reads both response formats', () => {
	assert.equal(extractRenderedElement(4, {html: '<div>4</div>'}, 9), '<div>4</div>');
	assert.equal(
		extractRenderedElement(5, {elements: {9: ['<div>5</div>']}}, 9),
		'<div>5</div>'
	);
});

test('responsePayload unwraps Craft responses and rejects explicit failures', () => {
	assert.deepEqual(responsePayload({data: {success: true, result: {token: 'abc'}}}), {
		success: true,
		result: {token: 'abc'},
	});
	assert.throws(
		() => responsePayload({data: {success: false, message: 'Nope'}}),
		/Nope/
	);
});
