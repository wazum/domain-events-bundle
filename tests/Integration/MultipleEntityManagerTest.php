<?php

declare(strict_types=1);

namespace Headsnet\DomainEventsBundle\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Headsnet\DomainEventsBundle\Domain\Model\StoredEvent;
use Headsnet\DomainEventsBundle\EventSubscriber\PublishDomainEventSubscriber;
use Headsnet\DomainEventsBundle\HeadsnetDomainEventsBundle;
use Headsnet\DomainEventsBundle\Integration\Fixtures\TestEntity;
use Headsnet\DomainEventsBundle\Integration\Fixtures\TestEvent;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @group integration
 */
class MultipleEntityManagerTest extends KernelTestCase
{
    private EntityManagerInterface $tenantAEntityManager;
    private EntityManagerInterface $tenantBEntityManager;
    private EntityManagerInterface $defaultEntityManager;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel($options);
        $kernel->addTestConfig(__DIR__ . '/config-multi-em.yml');
        $kernel->addTestBundle(FrameworkBundle::class);
        $kernel->addTestBundle(DoctrineBundle::class);
        $kernel->addTestBundle(HeadsnetDomainEventsBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var Registry $doctrine */
        $doctrine = $container->get('doctrine');

        $defaultEntityManager = $doctrine->getManager('default');
        $tenantAEntityManager = $doctrine->getManager('tenant_a');
        $tenantBEntityManager = $doctrine->getManager('tenant_b');
        
        assert($defaultEntityManager instanceof EntityManagerInterface);
        assert($tenantAEntityManager instanceof EntityManagerInterface);
        assert($tenantBEntityManager instanceof EntityManagerInterface);
        
        $this->defaultEntityManager = $defaultEntityManager;
        $this->tenantAEntityManager = $tenantAEntityManager;
        $this->tenantBEntityManager = $tenantBEntityManager;

        $this->createSchema($this->defaultEntityManager);
        $this->createSchema($this->tenantAEntityManager);
        $this->createSchema($this->tenantBEntityManager);
    }

    private function createSchema(EntityManagerInterface $entityManager): void
    {
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * This test demonstrates the current limitation: events from different tenants
     * are mixed together instead of being isolated by tenant database.
     *
     * Expected behavior:
     * - Tenant A events should be stored in tenant_a database
     * - Tenant B events should be stored in tenant_b database
     * - No cross-tenant data leakage
     */
    public function testEventsAreIsolatedByTenantDatabase(): void
    {
        $tenantAEntity = new TestEntity();
        $tenantAEvent = new TestEvent($tenantAEntity->getId());
        $tenantAEntity->record($tenantAEvent);

        $this->tenantAEntityManager->persist($tenantAEntity);
        $this->tenantAEntityManager->flush();

        $tenantBEntity = new TestEntity();
        $tenantBEvent = new TestEvent($tenantBEntity->getId());
        $tenantBEntity->record($tenantBEvent);

        $this->tenantBEntityManager->persist($tenantBEntity);
        $this->tenantBEntityManager->flush();

        $tenantAEvents = $this->tenantAEntityManager->getRepository(StoredEvent::class)->findAll();
        $tenantBEvents = $this->tenantBEntityManager->getRepository(StoredEvent::class)->findAll();

        self::assertCount(1, $tenantAEvents, 'Tenant A should have exactly 1 event in their database');
        self::assertCount(1, $tenantBEvents, 'Tenant B should have exactly 1 event in their database');

        self::assertEquals($tenantAEntity->getId(), $tenantAEvents[0]->getAggregateRoot(), 'Tenant A event should be for Tenant A entity');
        self::assertEquals($tenantBEntity->getId(), $tenantBEvents[0]->getAggregateRoot(), 'Tenant B event should be for Tenant B entity');

        $tenantAEntityIds = array_map(fn ($event) => $event->getAggregateRoot(), $tenantAEvents);
        $tenantBEntityIds = array_map(fn ($event) => $event->getAggregateRoot(), $tenantBEvents);

        self::assertNotContains($tenantBEntity->getId(), $tenantAEntityIds, 'Tenant A database should not contain Tenant B events');
        self::assertNotContains($tenantAEntity->getId(), $tenantBEntityIds, 'Tenant B database should not contain Tenant A events');
    }

    /**
     * Verifies that events from all entity managers are published correctly.
     */
    public function testEventsFromAllEntityManagersArePublished(): void
    {
        $tenantAEntity = new TestEntity();
        $tenantAEvent = new TestEvent($tenantAEntity->getId());
        $tenantAEntity->record($tenantAEvent);

        $tenantBEntity = new TestEntity();
        $tenantBEvent = new TestEvent($tenantBEntity->getId());
        $tenantBEntity->record($tenantBEvent);

        $this->tenantAEntityManager->persist($tenantAEntity);
        $this->tenantAEntityManager->flush();

        $this->tenantBEntityManager->persist($tenantBEntity);
        $this->tenantBEntityManager->flush();

        $this->tenantAEntityManager->clear();
        $this->tenantBEntityManager->clear();

        $tenantAEvents = $this->tenantAEntityManager->getRepository(StoredEvent::class)->findAll();
        $tenantBEvents = $this->tenantBEntityManager->getRepository(StoredEvent::class)->findAll();
        $defaultEvents = $this->defaultEntityManager->getRepository(StoredEvent::class)->findAll();

        self::assertCount(1, $tenantAEvents, 'Tenant A should have 1 stored event');
        self::assertCount(1, $tenantBEvents, 'Tenant B should have 1 stored event');
        self::assertCount(0, $defaultEvents, 'Default database should have no events');
        self::assertNull($tenantAEvents[0]->getPublishedOn(), 'Tenant A event should be unpublished');
        self::assertNull($tenantBEvents[0]->getPublishedOn(), 'Tenant B event should be unpublished');

        $container = self::getContainer();
        /** @var PublishDomainEventSubscriber $publisher */
        $publisher = $container->get('test.headsnet_domain_events.event_subscriber.publisher');

        $kernel = self::getContainer()->get('kernel');
        assert($kernel instanceof \Symfony\Component\HttpKernel\HttpKernelInterface);
        
        $publisher->publishEventsFromHttp(
            new \Symfony\Component\HttpKernel\Event\TerminateEvent(
                $kernel,
                $this->createMock(\Symfony\Component\HttpFoundation\Request::class),
                $this->createMock(\Symfony\Component\HttpFoundation\Response::class)
            )
        );

        $this->tenantAEntityManager->clear();
        $this->tenantBEntityManager->clear();
        $this->defaultEntityManager->clear();

        $tenantAEventsAfterPublish = $this->tenantAEntityManager->getRepository(StoredEvent::class)->findAll();
        $tenantBEventsAfterPublish = $this->tenantBEntityManager->getRepository(StoredEvent::class)->findAll();
        $defaultEventsAfterPublish = $this->defaultEntityManager->getRepository(StoredEvent::class)->findAll();

        self::assertCount(0, $defaultEventsAfterPublish, 'Default database should still have no events');

        self::assertNotNull($tenantAEventsAfterPublish[0]->getPublishedOn(), 'Tenant A event should be published');
        self::assertNotNull($tenantBEventsAfterPublish[0]->getPublishedOn(), 'Tenant B event should be published');

        self::assertCount(0, $this->getUnpublishedEventsFromAllDatabases(), 'No unpublished events should remain');
    }

    /**
     * Verifies that the publisher handles empty entity managers gracefully.
     */
    public function testPublisherHandlesEmptyEntityManagersGracefully(): void
    {
        $tenantAEntity = new TestEntity();
        $tenantAEvent = new TestEvent($tenantAEntity->getId());
        $tenantAEntity->record($tenantAEvent);

        $this->tenantAEntityManager->persist($tenantAEntity);
        $this->tenantAEntityManager->flush();

        self::assertCount(1, $this->tenantAEntityManager->getRepository(StoredEvent::class)->findAll());
        self::assertCount(0, $this->tenantBEntityManager->getRepository(StoredEvent::class)->findAll());
        self::assertCount(0, $this->defaultEntityManager->getRepository(StoredEvent::class)->findAll());

        $container = self::getContainer();
        /** @var PublishDomainEventSubscriber $publisher */
        $publisher = $container->get('test.headsnet_domain_events.event_subscriber.publisher');

        $kernel = self::getContainer()->get('kernel');
        assert($kernel instanceof \Symfony\Component\HttpKernel\HttpKernelInterface);
        
        $publisher->publishEventsFromHttp(
            new \Symfony\Component\HttpKernel\Event\TerminateEvent(
                $kernel,
                $this->createMock(\Symfony\Component\HttpFoundation\Request::class),
                $this->createMock(\Symfony\Component\HttpFoundation\Response::class)
            )
        );

        $this->tenantAEntityManager->clear();
        $tenantAEvents = $this->tenantAEntityManager->getRepository(StoredEvent::class)->findAll();

        self::assertCount(1, $tenantAEvents);
        self::assertNotNull($tenantAEvents[0]->getPublishedOn(), 'Tenant A event should be published');
        self::assertCount(0, $this->tenantBEntityManager->getRepository(StoredEvent::class)->findAll());
        self::assertCount(0, $this->defaultEntityManager->getRepository(StoredEvent::class)->findAll());
    }

    /**
     * Verifies that metadata detection works correctly for entity managers.
     */
    public function testMetadataDetectionForStoredEventMappings(): void
    {
        $container = self::getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');

        self::assertTrue($this->defaultEntityManager->getMetadataFactory()->hasMetadataFor(StoredEvent::class));
        self::assertTrue($this->tenantAEntityManager->getMetadataFactory()->hasMetadataFor(StoredEvent::class));
        self::assertTrue($this->tenantBEntityManager->getMetadataFactory()->hasMetadataFor(StoredEvent::class));

        /** @var class-string $nonExistentClass */
        $nonExistentClass = 'NonExistent\\Class';
        self::assertFalse($this->defaultEntityManager->getMetadataFactory()->hasMetadataFor($nonExistentClass));

        $metadata = $this->defaultEntityManager->getClassMetadata(StoredEvent::class);
        self::assertEquals('event', $metadata->getTableName());
    }

    /**
     * Helper method to get unpublished events from all databases
     * This simulates what the publisher should actually do
     *
     * @return array<StoredEvent>
     */
    private function getUnpublishedEventsFromAllDatabases(): array
    {
        $unpublishedEvents = [];

        $defaultEvents = $this->defaultEntityManager->getRepository(StoredEvent::class)->findBy(['publishedOn' => null]);
        $unpublishedEvents = array_merge($unpublishedEvents, $defaultEvents);

        $tenantAEvents = $this->tenantAEntityManager->getRepository(StoredEvent::class)->findBy(['publishedOn' => null]);
        $unpublishedEvents = array_merge($unpublishedEvents, $tenantAEvents);

        $tenantBEvents = $this->tenantBEntityManager->getRepository(StoredEvent::class)->findBy(['publishedOn' => null]);
        $unpublishedEvents = array_merge($unpublishedEvents, $tenantBEvents);

        return $unpublishedEvents;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->defaultEntityManager->close();
        $this->tenantAEntityManager->close();
        $this->tenantBEntityManager->close();
    }
}
