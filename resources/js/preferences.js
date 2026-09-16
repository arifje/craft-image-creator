const MAX_AGE = 365 * 24 * 60 * 60;

export function selectionPreferences(config, cookies) {
	let saved = {};
	try {
		cookies ??= document.cookie;
		const prefix = `${config.preferenceCookie}=`;
		const cookie = cookies.split(';').map((value) => value.trim())
			.find((value) => value.startsWith(prefix));
		saved = cookie ? JSON.parse(decodeURIComponent(cookie.slice(prefix.length))) : {};
	} catch {
		// Invalid or expired browser preferences fall back to configured choices.
	}
	const providers = Array.isArray(config.providers) ? config.providers : [];
	const ratios = Array.isArray(config.ratios) ? config.ratios : [];
	return {
		provider: providers.find((item) => item.value === saved?.provider)?.value
			|| providers.find((item) => item.value === config.defaultProvider)?.value
			|| providers[0]?.value || '',
		ratio: ratios.find((item) => item.value === saved?.ratio)?.value
			|| ratios[0]?.value || '1:1',
	};
}

export function rememberSelection(config, provider, ratio) {
	if (!config.preferenceCookie) {
		return;
	}
	try {
		const value = encodeURIComponent(JSON.stringify({provider, ratio}));
		document.cookie = `${config.preferenceCookie}=${value}; Path=/; Max-Age=${MAX_AGE}; SameSite=Lax`
			+ (window.location.protocol === 'https:' ? '; Secure' : '');
	} catch {
		// The modal remains usable when the browser blocks cookies.
	}
}
