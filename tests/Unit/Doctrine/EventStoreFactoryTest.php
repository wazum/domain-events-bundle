<?php

declare(strict_types=1);

namespace Headsnet\DomainEventsBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Headsnet\DomainEventsBundle\Domain\Model\EventStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @group unit
 */
class EventStoreFactoryTest extends TestCase
{
    private EventStoreFactory $factory;
    private ManagerRegistry&MockObject $managerRegistry;
    private SerializerInterface $serializer;
    private EventDispatcherInterface $eventDispatcher;
    private string $tableName;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->tableName = 'test_events';

        $this->factory = new EventStoreFactory(
            $this->managerRegistry,
            $this->serializer,
            $this->eventDispatcher,
            $this->tableName
        );
    }

    public function testCreateReturnsEventStoreForDefaultEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        
        $this->managerRegistry
            ->expects($this->once())
            ->method('getManager')
            ->with('default')
            ->willReturn($entityManager);

        $eventStore = $this->factory->create();

        self::assertInstanceOf(EventStore::class, $eventStore);
        self::assertInstanceOf(DoctrineEventStore::class, $eventStore);
    }

    public function testCreateReturnsEventStoreForSpecificEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        
        $this->managerRegistry
            ->expects($this->once())
            ->method('getManager')
            ->with('tenant_a')
            ->willReturn($entityManager);

        $eventStore = $this->factory->create('tenant_a');

        self::assertInstanceOf(EventStore::class, $eventStore);
        self::assertInstanceOf(DoctrineEventStore::class, $eventStore);
    }

    public function testCreateThrowsExceptionForNonOrmEntityManager(): void
    {
        $nonOrmEntityManager = $this->createMock(\Doctrine\Persistence\ObjectManager::class);
        
        $this->managerRegistry
            ->expects($this->once())
            ->method('getManager')
            ->with('mongodb')
            ->willReturn($nonOrmEntityManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity manager "mongodb" is not an ORM entity manager.');

        $this->factory->create('mongodb');
    }
}
