<?php
/*
 * This file is part of the Symfony HeadsnetDomainEventsBundle.
 *
 * (c) Headstrong Internet Services Ltd 2020
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Headsnet\DomainEventsBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Headsnet\DomainEventsBundle\Doctrine\DoctrineEventStore;
use Headsnet\DomainEventsBundle\Domain\Model\DomainEvent;
use Headsnet\DomainEventsBundle\Domain\Model\EventStore;
use Headsnet\DomainEventsBundle\Domain\Model\StoredEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PublishDomainEventSubscriber implements EventSubscriberInterface
{
    private DomainEventDispatcher $domainEventDispatcher;

    private SerializerInterface $serializer;

    private LockFactory $lockFactory;

    private ManagerRegistry $managerRegistry;

    private EventDispatcherInterface $eventDispatcher;

    private string $tableName;

    public function __construct(
        DomainEventDispatcher $domainEventDispatcher,
        SerializerInterface $serializer,
        LockFactory $lockFactory,
        ManagerRegistry $managerRegistry,
        EventDispatcherInterface $eventDispatcher,
        string $tableName
    ) {
        $this->domainEventDispatcher = $domainEventDispatcher;
        $this->serializer = $serializer;
        $this->lockFactory = $lockFactory;
        $this->managerRegistry = $managerRegistry;
        $this->eventDispatcher = $eventDispatcher;
        $this->tableName = $tableName;
    }

    /**
     * Support publishing events on TERMINATE event of both HttpKernel and Console.
     *
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'publishEventsFromHttp',
            ConsoleEvents::TERMINATE => 'publishEventsFromConsole',
        ];
    }

    public function publishEventsFromHttp(TerminateEvent $event): void
    {
        $this->publishEvents();
    }

    public function publishEventsFromConsole(ConsoleTerminateEvent $event): void
    {
        $this->publishEvents();
    }

    private function publishEvents(): void
    {
        foreach ($this->managerRegistry->getManagers() as $entityManager) {
            // Skip non ORM entity managers
            if (!$entityManager instanceof EntityManagerInterface) {
                continue;
            }

            // Skip entity managers without StoredEvent mappings
            if (!$entityManager->getMetadataFactory()->hasMetadataFor(StoredEvent::class)) {
                continue;
            }

            // No publishing of events during an open transaction
            if ($entityManager->getConnection()->isTransactionActive()) {
                continue;
            }

            // Create a dynamic event store for this entity manager
            $eventStore = new DoctrineEventStore(
                $entityManager,
                $this->serializer,
                $this->eventDispatcher,
                $this->tableName
            );

            foreach ($eventStore->allUnpublished() as $event) {
                $this->publishEvent($event, $eventStore);
            }
        }
    }

    private function publishEvent(StoredEvent $storedEvent, EventStore $eventStore): void
    {
        $lock = $this->lockFactory->createLock(
            sprintf('domain-event-%s', $storedEvent->getEventId()->asString())
        );

        if ($lock->acquire()) {
            // Refresh the entity from the database, in case another process has
            // updated it between reading it above and then acquiring the lock here.
            // THIS IS CRITICAL TO AVOID DUPLICATE PUBLISHING OF EVENTS
            $eventStore->refresh($storedEvent);

            // If the event is definitely still unpublished, whilst we are here holding
            // the lock, then we can proceed to publish it.
            if ($storedEvent->getPublishedOn() === null) {
                $domainEvent = $this->serializer->deserialize(
                    $storedEvent->getEventBody(),
                    $storedEvent->getTypeName(),
                    'json'
                );
                assert($domainEvent instanceof DomainEvent);

                $this->domainEventDispatcher->dispatch($domainEvent);
                $eventStore->publish($storedEvent);
            }

            $lock->release();
        }
    }
}
