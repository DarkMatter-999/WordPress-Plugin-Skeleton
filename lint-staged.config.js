module.exports = {
	'**/*.php': [ 'pnpm run lint:php' ],
	'**/*.{js,jsx}': [ 'pnpm run lint:js' ],
	'**/*.{css,scss}': [ 'pnpm run lint:css' ],
};
