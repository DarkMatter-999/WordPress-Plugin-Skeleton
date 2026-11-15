# WordPress Plugin Skeleton

This repository provides a skeleton for developing WordPress plugins. It's designed to streamline development workflow by providing a pre-configured setup for common tasks like linting, building, and initializing plugin.

## Features

*   **Vite Build:** Uses Vite for JavaScript, TypeScript, React, and SCSS development and production builds.
*   **Code Linting:**
    *   **PHP:** Integrated with PHP_CodeSniffer for enforcing consistent PHP coding standards.
    *   **JavaScript:** Uses ESLint via `@wordpress/scripts`.
    *   **CSS/SCSS:** Uses Stylelint via `@wordpress/scripts`.
*   **Git Hooks (Husky):** Configured with Husky to run lint before committing, ensuring that only linted and formatted code is committed.
*   **Autoloading:** Includes a basic autoloader setup (within the `include/helpers` directory) to manage PHP classes efficiently.
*   **Clear Structure:** Provides a logical directory structure to keep plugin organized.

## Getting Started

### 1. Clone the Repository

```bash
git clone https://github.com/DarkMatter-999/WordPress-Plugin-Skeleton.git your-new-plugin-name
cd your-new-plugin-name
```

### 2. Install Dependencies

Use Node.js `^20.19.0` or `>=22.12.0` and pnpm `12.20.0`. Enable Corepack, activate the pinned pnpm version, and install dependencies:

```bash
corepack enable
corepack prepare pnpm@12.20.0 --activate
pnpm install
```

### 3. Initialize Your Plugin

Run the initialization script to set up your plugin's basic information. This script will prompt you for details like plugin name, author, version, etc., and will update the main plugin file (`.php`) and internal namespaces/constants accordingly.

```bash
pnpm run init
```

Follow the prompts to enter your plugin's details.

### 4. Composer Dependencies (for PHPCS)

To use the PHP linting, you'll need Composer to install `PHP_CodeSniffer`.
Once Composer is installed, run:

```bash
composer install
```

This installs the PHP tools into `vendor/`, allowing `pnpm lint:php` to work.

## Development Workflow

### Starting WordPress and Vite

Start WordPress in one terminal:

```bash
pnpm wp-env:start
```

In a second terminal, start Vite:

```bash
pnpm dev
```

Open WordPress at <http://localhost:8888>. JavaScript and stylesheet changes use Vite HMR. Changes under `assets/src/blocks/` trigger a full editor/page reload so block registrations are not hot-swapped into a stale editor session. The dev command writes and refreshes the ignored `assets/build/hot` marker while Vite is running, and removes it on shutdown.

### Building for Production

Build production JavaScript and CSS:

```bash
pnpm build
```

Deploy the generated `assets/build/` directory with the plugin. Production resolves assets from its Vite manifest and does not require Node.js or a running Vite server at runtime.

### Code Linting and Formatting

This skeleton comes with pre-configured linting rules for PHP, JavaScript, and CSS.

*   **Lint All:**
    ```bash
    pnpm lint
    ```
*   **Lint CSS/SCSS:**
    ```bash
    pnpm lint:css
    ```
*   **Fix CSS/SCSS Linting Issues:**
    ```bash
    pnpm lint:css:fix
    ```
*   **Lint JavaScript:**
    ```bash
    pnpm lint:js
    ```
*   **Fix JavaScript Linting Issues:**
    ```bash
    pnpm lint:js:fix
    ```
*   **Lint PHP:**
    ```bash
    pnpm lint:php
    ```
*   **Fix PHP Linting Issues:**
    ```bash
    pnpm lint:php:fix
    ```

### Pre-Commit Linting

This project uses `husky` and `lint-staged` to automatically run linters on staged files before you commit. This helps maintain code quality and consistency.

You don't need to run `lint:staged` manually; it's triggered by Git when you commit.

## Project Structure

*   `bin/`: Contains utility scripts, including `init.js`.
*   `include/`: Main directory for your plugin's PHP classes and core logic.
    *   `include/helpers/autoloader.php`: Basic autoloader for your plugin classes.
*   `assets/src/`: Contains source JavaScript, TypeScript, and SCSS files.
*   `assets/build/`: (Generated and ignored) Production assets and Vite manifest from `pnpm build`; also contains the temporary dev marker while `pnpm dev` is running.
*   `vendor/`: (Generated) Composer dependencies (e.g., PHP_CodeSniffer).
*   `<your-plugin-name>.php`: The main plugin file, generated and updated by `pnpm run init`.
*   `stylelintrc.json`: Stylelint configuration.
*   `phpcs.xml`: PHP_CodeSniffer configuration.
*   `package.json`: Project metadata and pnpm scripts.
*   `composer.json`: Composer metadata and dependencies.

## Customization

*   **PHP Namespace:** The `init` script will prompt you for a namespace. This will be used for your main plugin classes.
*   **Constant Prefix:** The `init` script automatically generates a unique constant prefix based on your plugin name.
*   **Linting Rules:** Adjust `stylelintrc.json`, and `phpcs.xml` to match your preferred coding standards.
*   **Autoloading:** If your project grows complex, you might consider migrating to a more robust autoloader (eg- Composer's autoloader) for PHP classes.
