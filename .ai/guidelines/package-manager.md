# Package Manager

- ALWAYS use `pnpm` for every JavaScript/Node.js operation in this project. Never use `npm`, `yarn`, or `bun`.
- Install dependencies with `pnpm install`, add packages with `pnpm add <pkg>` (or `pnpm add -D <pkg>` for dev dependencies), and run scripts with `pnpm run <script>` (e.g. `pnpm run build`, `pnpm run dev`).
- Node.js and pnpm versions are managed by Volta and pinned in `package.json` — do not install or switch Node versions through other tools.
