import assert from 'node:assert/strict';
import test from 'node:test';
import {selectionPreferences, rememberSelection} from '../../resources/js/preferences.js';

const config = {
	preferenceCookie: 'creator_user1',
	defaultProvider: 'openai',
	providers: [{value: 'openai'}, {value: 'google'}],
	ratios: [{value: '16:9'}, {value: '4:5'}],
};

test('restores choices from the current user cookie', () => {
	const value = encodeURIComponent(JSON.stringify({provider: 'google', ratio: '4:5'}));
	assert.deepEqual(selectionPreferences(config, `other=x; creator_user1=${value}`), {
		provider: 'google', ratio: '4:5',
	});
	assert.equal(selectionPreferences(config, `creator_user2=${value}`).provider, 'openai');
});

test('invalid or unavailable preferences use defaults', () => {
	for (const value of ['%bad', 'null', encodeURIComponent(JSON.stringify({provider: 'xai', ratio: '3:2'}))]) {
		assert.deepEqual(selectionPreferences(config, `creator_user1=${value}`), {
			provider: 'openai', ratio: '16:9',
		});
	}
});

test('stores a persistent cookie and tolerates blocked cookies', () => {
	globalThis.document = {cookie: ''};
	globalThis.window = {location: {protocol: 'https:'}};
	rememberSelection(config, 'google', '4:5');
	assert.match(document.cookie, /Max-Age=31536000; SameSite=Lax; Secure$/);
	assert.deepEqual(selectionPreferences(config), {provider: 'google', ratio: '4:5'});
	Object.defineProperty(document, 'cookie', {
		get() { throw new Error('Blocked'); },
		set() { throw new Error('Blocked'); },
	});
	assert.doesNotThrow(() => rememberSelection(config, 'google', '4:5'));
	assert.equal(selectionPreferences(config).provider, 'openai');
});
