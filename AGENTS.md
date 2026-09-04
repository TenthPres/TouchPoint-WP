# AGENTS.md

## Project conventions

- This is a WordPress plugin written primarily in PHP with some JavaScript and Python helpers.  Python runs in an environment separate from WordPress. 
- Follow **PSR-12** coding standards; the codebase explicitly prefers PSR-12 over WordPress formatting.
- The project is organized around OOP under the `tp\TouchPointWP` namespace.
- Keep changes consistent with the existing class- and module-oriented structure in `src/`.
- Never modify vendor assets. 
- Block files result in `blocks/`. Follow existing conventions for block registration and rendering.

## Documentation

- Public behavior and APIs are documented in `docs/` and generated API docs.
- All public methods and properties should have docblocks, including type hints and descriptions.
- Update the relevant docs when changing public behavior, interfaces, or user-facing features.

## Plugin-specific workflow

- Preserve compatibility with WordPress conventions and existing plugin behavior unless the task explicitly changes it.
- Be cautious with multisite behavior; the repository notes that multisite support is limited and often shared across a network.
- When changing API, authentication, or data-sync code, check for related docs and interfaces in `docs/` and `src/TouchPoint-WP/Interfaces/`.

## Verification

- Prefer the smallest existing test, lint, or build command that covers the change.
- If no targeted automation exists, verify the affected files directly and keep the change minimal and surgical.
