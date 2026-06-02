<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval as TimeIntervalResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record style model for a time interval. Backs the
 * etechflow_dd_time_interval table.
 */
class TimeInterval extends AbstractModel implements TimeIntervalInterface
{
    protected $_eventPrefix = 'etechflow_dd_time_interval';

    protected function _construct(): void
    {
        $this->_init(TimeIntervalResource::class);
    }

    public function getIntervalId(): ?int
    {
        $id = $this->getData(self::INTERVAL_ID);
        return $id === null ? null : (int) $id;
    }

    public function setIntervalId(?int $id): self
    {
        $this->setData(self::INTERVAL_ID, $id);
        return $this;
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        $this->setData(self::STORE_ID, $storeId);
        return $this;
    }

    public function getFromTime(): string
    {
        return (string) $this->getData(self::FROM_TIME);
    }

    public function setFromTime(string $hm): self
    {
        $this->setData(self::FROM_TIME, $hm);
        return $this;
    }

    public function getToTime(): string
    {
        return (string) $this->getData(self::TO_TIME);
    }

    public function setToTime(string $hm): self
    {
        $this->setData(self::TO_TIME, $hm);
        return $this;
    }

    public function getPosition(): int
    {
        return (int) $this->getData(self::POSITION);
    }

    public function setPosition(int $position): self
    {
        $this->setData(self::POSITION, $position);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $v = $this->getData(self::CREATED_AT);
        return $v === null ? null : (string) $v;
    }

    public function getUpdatedAt(): ?string
    {
        $v = $this->getData(self::UPDATED_AT);
        return $v === null ? null : (string) $v;
    }
}