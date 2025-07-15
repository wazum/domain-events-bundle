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

namespace Headsnet\DomainEventsBundle\Doctrine\EventSubscriber;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Headsnet\DomainEventsBundle\Doctrine\DoctrineEventStore;
use Headsnet\DomainEventsBundle\Domain\Model\ContainsEvents;
use Headsnet\DomainEventsBundle\Domain\Model\EventStore;
use Headsnet\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PersistDomainEventSubscriber
{
    private SerializerInterface $serializer;
    private EventDispatcherInterface $eventDispatcher;
    private string $tableName;

    public function __construct(
        SerializerInterface $serializer,
        EventDispatcherInterface $eventDispatcher,
        string $tableName
    ) {
        $this->serializer = $serializer;
        $this->eventDispatcher = $eventDispatcher;
        $this->tableName = $tableName;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->persistEntityDomainEvents($args);
    }

    private function persistEntityDomainEvents(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $uow = $entityManager->getUnitOfWork();

        // Create a dynamic event store for this entity manager
        $eventStore = new DoctrineEventStore(
            $entityManager,
            $this->serializer,
            $this->eventDispatcher,
            $this->tableName
        );

        $sources = [
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
            $uow->getScheduledEntityDeletions(),
        ];

        foreach ($sources as $source) {
            foreach ($source as $entity) {
                if (false === $entity instanceof ContainsEvents) {
                    continue;
                }

                $this->storeRecordedEvents($entity, $eventStore);
            }
        }

        $collectionSources = [
            $uow->getScheduledCollectionDeletions(),
            $uow->getScheduledCollectionUpdates(),
        ];
        foreach ($collectionSources as $source) {
            /** @var PersistentCollection $collection */
            foreach ($source as $collection) {
                $entity = $collection->getOwner();
                if (false === $entity instanceof ContainsEvents) {
                    continue;
                }

                $this->storeRecordedEvents($entity, $eventStore);
            }
        }
    }

    private function storeRecordedEvents(ContainsEvents $entity, EventStore $eventStore): void
    {
        foreach ($entity->getRecordedEvents() as $domainEvent) {
            if ($domainEvent instanceof ReplaceableDomainEvent) {
                $eventStore->replace($domainEvent);
            } else {
                $eventStore->append($domainEvent);
            }
        }

        $entity->clearRecordedEvents();
    }
}
