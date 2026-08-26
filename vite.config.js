import {defineConfig} from 'vite';
import {resolve} from 'node:path';

export default defineConfig({
	build: {
		emptyOutDir: true,
		outDir: resolve(import.meta.dirname, 'src/web/assets/dist'),
		lib: {
			entry: resolve(import.meta.dirname, 'resources/js/main.js'),
			formats: ['iife'],
			name: 'CraftImageCreator',
			fileName: () => 'image-creator.js',
		},
		cssCodeSplit: false,
		rollupOptions: {
			output: {
				assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css')
					? 'image-creator.css'
					: '[name][extname]',
			},
		},
	},
});
