# Repository Guidelines

## Project Structure & Module Organization
- `src/`: TypeScript extension logic; entry `extension.ts` handles activation, `api.ts` drives backend calls, `userPanelProvider.ts` renders the sidebar webview, and `common/` stores shared utilities and logging.
- `webview/`: Static assets for the user panel UI; update `userPanel.js` and `userPanel.css` before rebuilding.
- `dist/`: Bundled output created by webpack; never edit manually—regenerate via build commands.
- `resources/`: VS Code marketplace assets such as `icon.png`.
- `hack/`: Low-level helpers for crypto, http2, and rotation logic; treat as internal modules and lint before use.
- Root configuration (`tsconfig.json`, `webpack.config.js`, `eslint.config.mjs`) governs TypeScript targets, bundling, and linting.

## Build, Test, and Development Commands
- `yarn compile`: Run webpack in default mode to refresh `dist/extension.js`.
- `yarn start` or `yarn watch`: Start webpack in watch mode for iterative development.
- `yarn package`: Produce an obfuscated production bundle with source maps stripped.
- `yarn lint`: Lint all TypeScript sources using the project ESLint rules.
- `yarn test`: Execute VS Code integration tests via `@vscode/test-cli` (runs `pretest` first to compile and lint).

## Coding Style & Naming Conventions
- Stick to TypeScript with tab indentation; keep double quotes for strings to match existing modules.
- Prefer camelCase for files and symbols; imports must follow the ESLint naming rule (camelCase or PascalCase).
- Export shared utilities through index files where possible; keep webview scripts ES module-friendly.
- Run `yarn lint` before committing to surface deviations early.

## Testing Guidelines
- Place new tests under `src/test` mirroring the module path (e.g., `src/test/userPanelProvider.test.ts`).
- Use Mocha-style suites provided by `@vscode/test-electron`; favor descriptive `describe`/`it` names tied to user workflows.
- Verify authentication and proxy flows by mocking network calls defined in `api.ts` and `proxy.ts`.
- Always run `yarn test` (or at minimum `yarn pretest`) prior to submitting a pull request.

## Commit & Pull Request Guidelines
- Follow the existing short, imperative commit style (e.g., `修复模型显示`, `去掉频率限制`); include the version tag only when releasing.
- Reference related issues in the commit body when applicable; avoid multiline subjects.
- For pull requests, summarize the change, list tested commands, attach UI screenshots for webview updates, and note any configuration prerequisites.
- Confirm that bundles are rebuilt and that no secrets or tokens are added to `src` or `hack`.
