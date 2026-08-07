# Engineering principles

- Do not preserve backward compatibility unless the current requirements explicitly
  demand it. Replace obsolete internal paths instead of accumulating compatibility
  layers or fallbacks. If persisted data or external contracts may require a
  migration, alert the user and ask whether it is necessary before creating one.
- Choose the simplest implementation that fully meets the current requirements.
  Avoid speculative abstractions, configuration, and indirection.
- Grow the system in layers. Start with the smallest version that works end to end,
  then add each capability on top of a product that already works. Never trade a
  working product for unfinished complexity.
- Keep components modular and concerns clearly separated.
- Prefer established, well-maintained libraries when they reduce overall complexity
  or improve reliability. Do not reimplement common functionality without a clear
  reason.
- Lean on the dependencies already in the project before writing your own
  implementation or adding packages. Do not assume a library lacks a capability
  without checking its documentation and types.
- Choose designs that can remain in place as the product grows.
