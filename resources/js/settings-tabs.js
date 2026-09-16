function activateTab(selectedTab, tabs, panels, focus = false) {
	const selectedPanelId = selectedTab.hash.slice(1);
	for (const tab of tabs) {
		const selected = tab === selectedTab;
		tab.setAttribute('aria-selected', String(selected));
		tab.tabIndex = selected ? 0 : -1;
		tab.classList.toggle('is-active', selected);
	}
	for (const panel of panels) {
		panel.hidden = panel.id !== selectedPanelId;
	}
	if (focus) {
		selectedTab.focus();
	}
}

function installTabs(root) {
	const tablist = root.querySelector('[data-settings-tablist]');
	const tabs = [...root.querySelectorAll('[data-settings-tab]')];
	const panels = [...root.querySelectorAll('[data-settings-panel]')];
	if (!tablist || !tabs.length || tabs.length !== panels.length ||
		tabs.some((tab) => !panels.some((panel) => panel.id === tab.hash.slice(1)))) {
		return;
	}

	tablist.setAttribute('role', 'tablist');
	for (const tab of tabs) {
		tab.setAttribute('role', 'tab');
		tab.setAttribute('aria-controls', tab.hash.slice(1));
		tab.addEventListener('click', (event) => {
			event.preventDefault();
			activateTab(tab, tabs, panels);
		});
	}
	for (const panel of panels) {
		panel.setAttribute('role', 'tabpanel');
		panel.tabIndex = 0;
	}

	tablist.addEventListener('keydown', (event) => {
		const currentIndex = tabs.indexOf(document.activeElement);
		if (currentIndex < 0) {
			return;
		}

		let nextIndex;
		const rightToLeft = getComputedStyle(tablist).direction === 'rtl';
		switch (event.key) {
			case 'ArrowRight':
				nextIndex = (currentIndex + (rightToLeft ? -1 : 1) + tabs.length) % tabs.length;
				break;
			case 'ArrowLeft':
				nextIndex = (currentIndex + (rightToLeft ? 1 : -1) + tabs.length) % tabs.length;
				break;
			case 'Home':
				nextIndex = 0;
				break;
			case 'End':
				nextIndex = tabs.length - 1;
				break;
			default:
				return;
		}

		event.preventDefault();
		activateTab(tabs[nextIndex], tabs, panels, true);
	});

	const initialTab = tabs.find((tab) => tab.dataset.hasErrors === 'true')
		|| tabs.find((tab) => tab.hash === window.location.hash)
		|| tabs[0];
	activateTab(initialTab, tabs, panels);
}

export function installSettingsTabs() {
	const start = () => {
		document.querySelectorAll('[data-image-creator-settings-tabs]').forEach(installTabs);
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, {once: true});
	} else {
		start();
	}
}
