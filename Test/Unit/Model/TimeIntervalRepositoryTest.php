<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval as TimeIntervalResource;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval\Collection;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval\CollectionFactory;
use ETechFlow\DeliveryDate\Model\TimeInterval;
use ETechFlow\DeliveryDate\Model\TimeIntervalFactory;
use ETechFlow\DeliveryDate\Model\TimeIntervalRepository;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-request caching + exception wrapping behaviour of the
 * Time Interval repository.
 */
class TimeIntervalRepositoryTest extends TestCase
{
    private TimeIntervalFactory|MockObject $factory;
    private TimeIntervalResource|MockObject $resource;
    private CollectionFactory|MockObject $collectionFactory;
    private TimeIntervalRepository $repository;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(TimeIntervalFactory::class);
        $this->resource = $this->createMock(TimeIntervalResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);

        $this->repository = new TimeIntervalRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory
        );
    }

    /**
     * Build a TimeInterval model mock with the supplied data shape.
     */
    private function makeModel(int $id, string $from = '09:00', string $to = '12:00'): TimeInterval|MockObject
    {
        $model = $this->getMockBuilder(TimeInterval::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIntervalId', 'getFromTime', 'getToTime'])
            ->getMock();
        $model->method('getIntervalId')->willReturn($id);
        $model->method('getFromTime')->willReturn($from);
        $model->method('getToTime')->willReturn($to);
        return $model;
    }

    // -----------------------------------------------------------------
    // getById
    // -----------------------------------------------------------------

    public function testGetByIdReturnsLoadedModel(): void
    {
        $model = $this->makeModel(7);
        $this->factory->method('create')->willReturn($model);
        $this->resource->method('load')->willReturnSelf();

        $result = $this->repository->getById(7);

        $this->assertSame(7, $result->getIntervalId());
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $model = $this->getMockBuilder(TimeInterval::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIntervalId'])
            ->getMock();
        $model->method('getIntervalId')->willReturn(null);  // simulate missing
        $this->factory->method('create')->willReturn($model);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(999);
    }

    public function testGetByIdCachesPerRequest(): void
    {
        $model = $this->makeModel(7);
        $this->factory->method('create')->willReturn($model);

        // Resource load called ONCE — second getById should hit cache
        $this->resource->expects($this->once())->method('load');

        $this->repository->getById(7);
        $this->repository->getById(7);
    }

    // -----------------------------------------------------------------
    // save
    // -----------------------------------------------------------------

    public function testSaveWrapsThrowableAsCouldNotSave(): void
    {
        $model = $this->makeModel(0);
        $this->resource->method('save')->willThrowException(new \RuntimeException('db error'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($model);
    }

    public function testSaveInvalidatesCache(): void
    {
        // Set up the count expectation BEFORE any getById call, so PHPUnit
        // counts both invocations against the same matcher.
        $model = $this->makeModel(7);
        $this->factory->method('create')->willReturn($model);
        $this->resource->expects($this->exactly(2))->method('load')->willReturnSelf();
        $this->resource->method('save')->willReturnSelf();

        $this->repository->getById(7);         // load count → 1, cache populated
        $this->repository->save($model);       // invalidates cache
        $this->repository->getById(7);         // load count → 2 (cache was dropped)
    }

    // -----------------------------------------------------------------
    // delete
    // -----------------------------------------------------------------

    public function testDeleteWrapsThrowable(): void
    {
        $model = $this->makeModel(5);
        $this->resource->method('delete')->willThrowException(new \RuntimeException('fk violation'));

        $this->expectException(CouldNotDeleteException::class);
        $this->repository->delete($model);
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $model = $this->makeModel(5);
        $this->factory->method('create')->willReturn($model);
        $this->resource->method('load')->willReturnSelf();
        $this->resource->expects($this->once())->method('delete')->willReturnSelf();

        $this->assertTrue($this->repository->deleteById(5));
    }

    // -----------------------------------------------------------------
    // getAll
    // -----------------------------------------------------------------

    public function testGetAllReturnsCollectionItems(): void
    {
        $items = [$this->makeModel(1), $this->makeModel(2)];
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setOrder', 'getItems'])
            ->getMock();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($items);
        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->repository->getAll();

        $this->assertCount(2, $result);
    }

    public function testGetAllCachesPerRequest(): void
    {
        $items = [$this->makeModel(1)];
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setOrder', 'getItems'])
            ->getMock();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($items);
        // Collection factory create() must run exactly once across two getAll calls
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);

        $this->repository->getAll();
        $this->repository->getAll();
    }
}