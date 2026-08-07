# Domain Architecture

## Status

The module structure in this document is the **Proposed baseline** for implementation tasks. The
event-listener discovery convention is **Locked** and configured in `bootstrap/app.php`.

This is a pragmatic Laravel modular architecture, not a requirement to implement every tactical DDD
pattern. Create a folder only when a domain capability needs it.

## Objectives

- Keep ledger invariants independent from HTTP, Inertia, CLI, queues, and providers.
- Group code by financial capability instead of placing all models, actions, or events in global
  folders.
- Make domain ownership visible from namespaces and paths.
- Keep external-provider details out of the financial domain.
- Allow Laravel to discover listeners without maintaining a manual event-to-listener map.
- Preserve explicit service-provider registration and dependency bindings.

## Top-level structure

```text
app/
├── Domain/
│   ├── Ledger/
│   ├── Money/
│   └── Obligations/
├── Infrastructure/
│   ├── Binance/
│   ├── Bcv/
│   └── CashMarket/
├── Http/
├── Models/
├── Providers/
└── Support/
```

Initial ownership:

| Module           | Owns                                                                                    |
| ---------------- | --------------------------------------------------------------------------------------- |
| `Ledger`         | Books, containers, accounts, journal transactions, postings, posting and reversal rules |
| `Money`          | Instruments, quotes, valuation policies, rates, carrying-cost calculations              |
| `Obligations`    | Counterparties, payables, receivables, settlements, obligation lifecycle                |
| `Infrastructure` | BCV, Binance, cash-market, bank, and other provider-specific adapters                   |
| `Http`           | Requests, controllers, resources, middleware, and Inertia delivery                      |
| `Models`         | Framework/application models that are not owned by a financial domain, initially `User` |
| `Support`        | Small cross-cutting technical utilities with no financial business meaning              |

Reporting may become its own module when cross-domain read models appear. Do not create it merely to
wrap simple domain queries.

## Module shape

A mature module may contain:

```text
app/Domain/Ledger/
├── Actions/
├── Contracts/
├── Data/
├── Enums/
├── Events/
├── Exceptions/
├── Listeners/
├── Models/
├── Policies/
├── Queries/
├── ValueObjects/
└── LedgerServiceProvider.php
```

These directories are a vocabulary, not mandatory scaffolding. For example, do not create an empty
`Policies/` directory before the ledger has a policy object.

### Actions

One application use case per class, such as:

- `PostJournalTransactionAction`;
- `ReverseJournalTransactionAction`;
- `RecordQuoteAction`;
- `SettleObligationAction`.

Actions expose one consistent `handle(...)` entry point, use constructor injection, own transaction
boundaries when they coordinate persistence, and return explicit data/results instead of HTTP
responses.

### Contracts

Interfaces exist at real boundaries, especially when the domain consumes an external capability or
multiple implementations are expected. Examples include quote providers or provider trade readers.

Do not create an interface for every action, query, or service. Internal concrete collaborators are
acceptable when no substitutable boundary exists.

The consuming domain owns the contract. Provider-specific implementations live under
`app/Infrastructure/` and are bound explicitly in a service provider.

### Data and value objects

- `Data/` contains immutable command and result DTOs crossing use-case boundaries.
- `ValueObjects/` contains validated concepts with behavior, such as native quantity, functional
  amount, rate, pair, and effective-time ranges.
- Monetary contracts use decimal strings or decimal value objects, never binary floats.

### Enums

Enums represent closed domain vocabularies such as account type, transaction status, quote side, or
obligation direction. Enum cases follow project PHP conventions and use TitleCase names.

### Models

Eloquent models live in the module that owns their lifecycle, for example:

```text
App\Domain\Ledger\Models\Account
App\Domain\Ledger\Models\JournalTransaction
App\Domain\Money\Models\Instrument
App\Domain\Money\Models\Quote
App\Domain\Obligations\Models\Obligation
```

Model methods may express local relationships, casts, and invariants. Multi-record accounting
operations remain in actions/domain services so they share one transaction boundary.

Factories mirror module ownership under `database/factories/Domain/...`, and tests mirror it under
`tests/Feature/Domain/...` or `tests/Unit/Domain/...`.

### Queries

Queries provide named, testable read contracts. Keep cross-domain dashboard composition out of
models and controllers. Simple module-local Eloquent queries do not require a repository layer.

### Events and listeners

Events describe facts that already occurred and use past-tense names:

- `JournalTransactionPosted`;
- `JournalTransactionReversed`;
- `QuoteRecorded`;
- `ObligationOpened`;
- `ObligationSettled`.

The emitting domain owns the event class under its `Events/` directory. The domain reacting to the
fact owns the listener under its own `Listeners/` directory.

Example:

```text
app/Domain/Ledger/Events/JournalTransactionPosted.php
app/Domain/Obligations/Listeners/ApplyPostedSettlement.php
```

## Dependency direction

**ARC-001 — Vertical modules.** Financial capabilities live under `app/Domain/<Module>` and own their
models, actions, contracts, events, listeners, exceptions, and tests.

**ARC-002 — No empty architecture.** Add module subdirectories only when real code requires them.

**ARC-003 — Stable core direction.** The intended dependency direction is:

```text
Infrastructure ───────► Domain contracts
Http / CLI / Jobs ────► Domain actions and queries
Obligations ──────────► Ledger and Money capabilities
Ledger ───────────────► Money concepts required for posting and valuation
Money ────────────────► no Ledger or Obligations dependency
Reporting ────────────► read access across domains, when introduced
```

Avoid a Ledger-to-Obligations dependency and avoid circular module references.

**ARC-004 — External data stops at adapters.** Binance, BCV, or bank payloads are translated into
application DTOs before entering domain actions. Provider response shapes do not leak into ledger
events or models.

**ARC-005 — Contracts belong to consumers.** A contract is defined by the domain that needs the
capability, while its provider implementation remains in Infrastructure.

**ARC-006 — Explicit providers.** Each domain may expose a service provider for bindings and domain
configuration. Providers are registered explicitly in `bootstrap/providers.php`; provider discovery
is not inferred from directories.

## Laravel event discovery

Laravel discovers **listeners**, then infers the event types from the first parameter of public
`handle*` or `__invoke` methods. Event classes themselves do not need registration or filesystem
discovery; application code dispatches them explicitly.

Agentic Money configures these listener roots:

```php
->withEvents(discover: [
    __DIR__.'/../app/Listeners',
    __DIR__.'/../app/Domain/*/Listeners',
])
```

**ARC-007 — Listener location.** Domain listeners live directly under
`app/Domain/<Module>/Listeners`. The configured wildcard is deliberately one module level deep.

**ARC-008 — Discoverable signature.** A discovered listener exposes a public `handle` or `__invoke`
method whose first parameter type-hints one or more event classes. Constructor dependencies are
resolved by Laravel's container.

**ARC-009 — No manual duplicate registration.** A discovered listener is not also registered in a
service provider or event map. Duplicate registration would run it more than once.

**ARC-010 — Events are explicit.** Domain actions explicitly dispatch event objects. “Autodiscovery
of events” means Laravel derives event-to-listener mappings from listener type hints; it does not mean
the framework scans and dispatches event classes automatically.

**ARC-011 — Consumer owns reaction.** The listener belongs to the module that performs the reaction,
not necessarily the module that emitted the event.

**ARC-012 — Core invariants do not depend on listeners.** Posting balance, obligation settlement,
idempotency, and other atomic financial invariants execute inside the primary action/database
transaction. Listeners handle secondary reactions, projections, notifications, or integration work.

**ARC-013 — Dispatch after commit.** Events emitted from a database transaction implement Laravel's
after-commit event contract or are otherwise dispatched only after a successful commit. A listener
must never observe an event for rolled-back financial data.

**ARC-014 — Queued listeners are idempotent.** Slow or external reactions use queued listeners with
retry-safe behavior and stable uniqueness where duplicates would be harmful.

**ARC-015 — Discovery is verifiable.** Development and CI can inspect mappings with
`php artisan event:list`. Production deployment caches discovered mappings through Laravel's normal
optimization/event-cache process.

## Service providers

Providers have a narrower role than domain modules:

- bind contracts to implementations;
- register configuration and framework integration;
- configure provider clients;
- register explicit subscribers that cannot follow listener discovery conventions.

They do not contain transaction workflows or manually list ordinary discovered listeners.

Suggested future registration:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Domain\Money\MoneyServiceProvider::class,
    App\Domain\Ledger\LedgerServiceProvider::class,
    App\Domain\Obligations\ObligationsServiceProvider::class,
    App\Providers\InfrastructureServiceProvider::class,
];
```

Only add a provider when it has an actual binding or boot responsibility.

## Testing structure

```text
tests/
├── Feature/
│   └── Domain/
│       ├── Ledger/
│       ├── Money/
│       └── Obligations/
└── Unit/
    └── Domain/
        ├── Ledger/
        ├── Money/
        └── Obligations/
```

- Feature tests prove database constraints, action atomicity, discovery, and complete accounting
  scenarios.
- Unit tests prove value objects, calculations, policies, and isolated rules.
- Listener tests verify the reaction and use Laravel event/queue fakes at the interface boundary.
- At least one architecture test should enforce allowed module dependencies once the modules exist.

## Boundaries intentionally deferred

- Exact schema and model relationships.
- Whether cross-domain read models live in `app/Domain/Reporting` or a dedicated `app/Reporting`
  namespace.
- Exact provider contracts for Binance and BCV.
- Whether events carry identifiers only or small immutable snapshots. Prefer identifiers plus the
  minimum immutable facts required by idempotent consumers.
- Whether any event subscribers are needed; ordinary listeners are the default.
