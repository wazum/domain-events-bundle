<?php
/*
 * This file is part of the Symfony HeadsnetDomainEventsBundle.
 *
 * (c) Headstrong Internet Services Ltd 2025
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Headsnet\DomainEventsBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Headsnet\DomainEventsBundle\Domain\Model\EventStore;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EventStoreFactory
{
    private ManagerRegistry $managerRegistry;
    private SerializerInterface $serializer;
    private EventDispatcherInterface $eventDispatcher;
    private string $tableName;

    public function __construct(
        ManagerRegistry $managerRegistry,
        SerializerInterface $serializer,
        EventDispatcherInterface $eventDispatcher,
        string $tableName
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->serializer = $serializer;
        $this->eventDispatcher = $eventDispatcher;
        $this->tableName = $tableName;
    }

    public function create(string $entityManagerName = 'default'): EventStore
    {
        $entityManager = $this->managerRegistry->getManager($entityManagerName);
        
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Entity manager "%s" is not an ORM entity manager.',
                $entityManagerName
            ));
        }

        return new DoctrineEventStore(
            $entityManager,
            $this->serializer,
            $this->eventDispatcher,
            $this->tableName
        );
    }
}
