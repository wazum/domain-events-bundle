# Domain Event Bundle

![Build Status](https://github.com/headsnet/domain-events-bundle/actions/workflows/ci.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/headsnet/domain-events-bundle/v)](//packagist.org/packages/headsnet/domain-events-bundle)
[![Total Downloads](https://poser.pugx.org/headsnet/domain-events-bundle/downloads)](//packagist.org/packages/headsnet/domain-events-bundle)
[![License](https://poser.pugx.org/headsnet/domain-events-bundle/license)](//packagist.org/packages/headsnet/domain-events-bundle)

DDD Domain Events for Symfony, with a Doctrine based event store.

This package allows you to dispatch domain events from within your domain
model, so that they are persisted in the same transaction as your aggregate.

These events are then published using a Symfony event listener in the
`kernel.TERMINATE` event.

This ensures transactional consistency and guaranteed delivery via the Outbox
pattern.

_Requires Symfony 4.4 or higher_

### Installation

```bash
composer require headsnet/domain-events-bundle
```

(see [Messenger Component](#messenger-component) below for prerequisites)

### The Domain Event Class

A domain event class must be instantiated with an aggregate root ID.

You can add other parameters to the constructor as required.

```php
use Headsnet\DomainEventsBundle\Domain\Model\DomainEvent;
use Headsnet\DomainEventsBundle\Domain\Model\Traits\DomainEventTrait;

final class DiscountWasApplied implements DomainEvent
{
    use DomainEventTrait;

    public function __construct(string $aggregateRootId)
    {
        $this->aggregateRootId = $aggregateRootId;
        $this->occurredOn = (new \DateTimeImmutable)->format(DateTime::ATOM);
    }
}
```

### Recording Events

Domain events should be dispatched from within your domain model - i.e. from
directly inside your entities.

Here we record a domain event for entity creation. It is then automatically
persisted to the Doctrine `event`
database table in the same database transaction as the main entity is persisted.

```php
use Headsnet\DomainEventsBundle\Domain\Model\ContainsEvents;
use Headsnet\DomainEventsBundle\Domain\Model\RecordsEvents;
use Headsnet\DomainEventsBundle\Domain\Model\Traits\EventRecorderTrait;

class MyEntity implements ContainsEvents, RecordsEvents
{
	use EventRecorderTrait;

	public function __construct(PropertyId $uuid)
    	{
    	    $this->uuid = $uuid;

    	    // Record a domain event
    	    $this->record(
    		    new DiscountWasApplied($uuid->asString())
    	    );
    	}
}
```

Then, in `kernel.TERMINATE` event, a listener automatically publishes the domain
event on to the `messenger.bus.event` event bus for consumption elsewhere.

### Amending domain events

Even though events should be treated as immutable, it might be convenient
to add or change meta data before adding them to the event store.

Before a domain event is appended to the event store,
the standard Doctrine event store emits a `PreAppendEvent` Symfony event,
which can be used e.g. to set the actor ID as in the following example:

```php
use App\Entity\User;
use Headsnet\DomainEventsBundle\Doctrine\Event\PreAppendEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

final class AssignDomainEventUser implements EventSubscriberInterface
{
    private Security $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreAppendEvent::class => 'onPreAppend'
        ];
    }

    public function onPreAppend(PreAppendEvent $event): void
    {
        $domainEvent = $event->getDomainEvent();
        if (null === $domainEvent->getActorId()) {
            $user = $this->security->getUser();
            if ($user instanceof User) {
                $domainEvent->setActorId($user->getId());
            }
        }
    }
}
```

### Deferring Events Into The Future

If you specify a future date for the `DomainEvent::occurredOn` the event will
not be published until this date.

This allows scheduling of tasks directly from within the domain model.

#### Replaceable Future Events

If an event implements `ReplaceableDomainEvent` instead of `DomainEvent`,
recording multiple instances of the same event for the same aggregate root will
overwrite previous recordings of the event, as long as it is not yet published.

For example, say you have an aggregate _Booking_, which has a future
_ReminderDue_ event. If the booking is then modified to have a different
date/time, the reminder must also be modified. By implementing
`ReplaceableDomainEvent`, you can simply record a new _ReminderDue_ event, and
providing that the previous _ReminderDue_ event had not been published, it will
be removed and superseded by the new _ReminderDue_ event.

### Event dispatching

By default only the DomainEvent is dispatched to the configured event bus.

You can overwrite the default event dispatcher with your own implementation to
annotate the message before dispatching it, e.g. to add an envelope with custom stamps.

Example:

```yaml
services:
    headsnet_domain_events.domain_event_dispatcher_service:
        class: App\Infrastructure\DomainEventDispatcher
```

```php
class PersonCreated implements DomainEvent, AuditableEvent
{
    …
}
```

```php
class DomainEventDispatcher implements \Headsnet\DomainEventsBundle\EventSubscriber\DomainEventDispatcher
{
    private MessageBusInterface  $eventBus;

    public function __construct(MessageBusInterface $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    public function dispatch(DomainEvent $event): void
    {
        if ($event instanceof AuditableEvent) {
            $this->eventBus->dispatch(
                new Envelope($event, [new AuditStamp()])
            );
        } else {
            $this->eventBus->dispatch($event);
        }
    }
}
```

### Messenger Component

By default, the bundle expects a message bus called `messenger.bus.event` to be
available.
This can be configured using the bundle configuration - see
[Default Configuration](#default-configuration).

```yaml
framework:
    messenger:
        …

        buses:
            messenger.bus.event:
                # Optional
                default_middleware: allow_no_handlers
```

[Symfony Messenger/Multiple Buses](https://symfony.com/doc/current/messenger/multiple_buses.html)

### Doctrine

The bundle will create a database table called `event` to persist the events
before dispatch. This allows a permanent record of all events raised.

The database table name can be configured - see *Default Configuration* below.

The `StoredEvent` entity also tracks whether each event has been published to
the bus or not.

Finally, a Doctrine DBAL custom type called `datetime_immutable_microseconds` is
automatically registered. This allows the StoredEvent entity to persist events
with microsecond accuracy. This ensures that events are published in the exact
same order they are recorded.

### Transaction Safety

Events are only published when no database transaction is active. If the
`kernel.TERMINATE` event fires while a database transaction is still open
(including nested transactions), event publishing will be deferred until all
transactions are committed.

This prevents events from being published for data that might be rolled back,
maintaining the integrity of the outbox pattern.

### Multiple Entity Manager Support

**Default Setup:** The bundle works out of the box with Doctrine's default entity manager configuration. No additional configuration is required for standard single-database applications.

**Multi-Entity Manager Setup:** For advanced use cases like multi-tenant applications where each tenant has its own database, the bundle automatically supports multiple entity managers with zero additional configuration required.

The bundle will automatically detect all entity managers that have `StoredEvent` mappings configured and process events from each database independently. Events are completely isolated between entity managers, ensuring no cross-tenant data leakage.

**Key Features:**
- Works out of the box with default Doctrine setup
- Automatic detection of entity managers with event mappings
- Complete event isolation between databases
- Zero configuration required for multi-entity manager support
- Works with any number of entity managers (see performance considerations below)

**Example multi-tenant Doctrine configuration** (only required if you want multiple databases):
```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            tenant_a:
                url: '%env(resolve:TENANT_A_DATABASE_URL)%'
            tenant_b:
                url: '%env(resolve:TENANT_B_DATABASE_URL)%'
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings:
                    HeadsnetDomainEventsBundle:
                        is_bundle: true
                        type: xml
                        prefix: 'Headsnet\DomainEventsBundle\Domain\Model'
            tenant_a:
                connection: tenant_a
                mappings:
                    HeadsnetDomainEventsBundle:
                        is_bundle: true
                        type: xml
                        prefix: 'Headsnet\DomainEventsBundle\Domain\Model'
            tenant_b:
                connection: tenant_b
                mappings:
                    HeadsnetDomainEventsBundle:
                        is_bundle: true
                        type: xml
                        prefix: 'Headsnet\DomainEventsBundle\Domain\Model'
```

**Performance Considerations:**
- The bundle only processes entity managers that have `StoredEvent` mappings
- Entity managers without event mappings are automatically skipped
- For applications with many entity managers, consider the performance impact of checking all databases during event publishing

**Deprecation Notice:**
- The static `EventStore` service (`headsnet_domain_events.repository.event_store_doctrine`) is **deprecated** and will be removed in the next major version
- This service only works with the default entity manager and does not support multi-entity manager setups
- If you are injecting this service directly, use the new `EventStoreFactory` instead:

```php
// DEPRECATED - Don't do this:
public function __construct(EventStore $eventStore) { ... }

// RECOMMENDED - Use the factory service:
use Headsnet\DomainEventsBundle\Doctrine\EventStoreFactory;

public function __construct(EventStoreFactory $eventStoreFactory)
{
    // Create event store for default entity manager
    $eventStore = $eventStoreFactory->create();
    
    // Or create for specific entity manager
    $tenantEventStore = $eventStoreFactory->create('tenant_a');
}
```

### Legacy Events Classes

During refactorings, you may well move or rename event classes. This will
result in legacy class names being stored in the database.

There is a console command, which will report on these legacy event classes
that do not match an existing, current class in the codebase (based on the
Composer autoloading).

```
bin/console headsnet:domain-events:name-check
```

**Multiple Entity Manager Support:** For applications using multiple entity managers, you can specify which entity manager to check using the `--entity-manager` option:

```bash
# Check default entity manager
bin/console headsnet:domain-events:name-check

# Check specific entity manager
bin/console headsnet:domain-events:name-check --entity-manager=tenant_a
bin/console headsnet:domain-events:name-check --entity-manager=tenant_b
```

You can then define the `legacy_map` configuration parameter, to map old,
legacy event class names to their new replacements.

```yaml
headsnet_domain_events:
    legacy_map:
        App\Namespace\Event\YourLegacyEvent1: App\Namespace\Event\YourNewEvent1
        App\Namespace\Event\YourLegacyEvent2: App\Namespace\Event\YourNewEvent2
```

Then you can re-run the console command with the `--fix` option. This will
then update the legacy class names in the database with their new references.

```bash
# Fix legacy events in specific entity manager
bin/console headsnet:domain-events:name-check --fix --entity-manager=tenant_a
```

There is also a `--delete` option which will remove all legacy events from
the database if they are not found in the legacy map. **THIS IS A DESTRUCTIVE
COMMAND PLEASE USE WITH CAUTION.**

```bash
# Delete unfixable events from specific entity manager
bin/console headsnet:domain-events:name-check --delete --entity-manager=tenant_a
```

### Default Configuration

```yaml
headsnet_domain_events:
    message_bus:
        name: messenger.bus.event
    persistence:
        table_name: event
    legacy_map: []
```

### Contributing

Contributions are welcome. Please submit pull requests with one fix/feature per
pull request.

Composer scripts are configured for your convenience:

```
> composer test       # Run test suite
> composer cs         # Run coding standards checks
> composer cs-fix     # Fix coding standards violations
> composer static     # Run static analysis with Phpstan
```

